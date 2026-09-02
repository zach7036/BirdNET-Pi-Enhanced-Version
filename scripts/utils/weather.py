import sqlite3
import requests
import os
import logging
import time
from datetime import datetime
import sys
sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from helpers import DB_PATH, get_settings

logging.basicConfig(level=logging.INFO)
log = logging.getLogger('weather')

# Open-Meteo occasionally times out or returns 5xx; without retries a failed
# hourly cron run left a permanent gap in the weather table. Retry a few
# times, and fetch a week of history (the API serves it freely) so any
# successful run backfills gaps from Pi downtime or earlier failed runs.
FETCH_ATTEMPTS = 3
RETRY_DELAY_SECONDS = [30, 60]
PAST_DAYS = 7

# A local Home Assistant temperature sensor (HA_URL/HA_TOKEN/HA_TEMP_ENTITY)
# can override the CURRENT hour's temperature. Readings older than this are
# treated as a frozen or disconnected sensor and ignored - Open-Meteo's value
# stands, which is the automatic fallback.
HA_MAX_AGE_SECONDS = 3600
HA_TIMEOUT_SECONDS = 10

# HA's weather.* domain entities (WeatherFlow/Tempest, Ecowitt, AccuWeather,
# etc.) report `state` as one of a small fixed set of condition strings
# rather than a WMO code. Map the ones with an unambiguous WMO equivalent;
# anything else (e.g. "windy", "exceptional") is left unmapped on purpose so
# fetch_ha_weather() falls back to Open-Meteo's condition for that hour
# instead of guessing.
HA_CONDITION_TO_WMO_CODE = {
    'clear-night': 0,
    'sunny': 0,
    'partlycloudy': 2,
    'cloudy': 3,
    'fog': 45,
    'rainy': 61,
    'pouring': 65,
    'snowy': 71,
    'snowy-rainy': 71,
    'lightning': 95,
    'lightning-rainy': 95,
    'hail': 99,
}


def weather_sync_enabled(conf):
    """Return False only for an explicit WEATHER_ENABLED=0 setting."""
    if 'WEATHER_ENABLED' not in conf:
        return True
    return str(conf.get('WEATHER_ENABLED', '')).strip() != '0'


def fetch_hourly(lat, lon):
    url = (f"https://api.open-meteo.com/v1/forecast?latitude={lat}&longitude={lon}"
           "&hourly=temperature_2m,weather_code,is_day,wind_speed_10m,wind_direction_10m"
           f"&temperature_unit=fahrenheit&wind_speed_unit=mph&past_days={PAST_DAYS}"
           "&forecast_days=1&timezone=auto")
    for attempt in range(1, FETCH_ATTEMPTS + 1):
        try:
            response = requests.get(url, timeout=15)
            response.raise_for_status()
            return response.json()
        except Exception as e:
            if attempt < FETCH_ATTEMPTS:
                delay = RETRY_DELAY_SECONDS[min(attempt, len(RETRY_DELAY_SECONDS)) - 1]
                log.warning(f"Weather fetch attempt {attempt}/{FETCH_ATTEMPTS} failed ({e}); retrying in {delay}s.")
                time.sleep(delay)
            else:
                log.error(f"Weather fetch failed after {FETCH_ATTEMPTS} attempts: {e}")
    return None

def to_fahrenheit(value, unit):
    unit = (unit or '').strip()
    if unit in ('°F', 'F'):
        return value
    if unit == 'K':
        return (value - 273.15) * 9 / 5 + 32
    # HA temperature sensors default to °C outside the US; treat an unknown
    # unit as Celsius rather than silently storing a wrong-scale value.
    return value * 9 / 5 + 32


def fetch_ha_temperature(conf):
    """Current temperature (°F) from a Home Assistant sensor, or None.

    None means "use the online value" - the caller falls back silently. Any
    failure (unconfigured, unreachable, bad token, dead entity, non-numeric
    state, or a reading that has not changed in HA_MAX_AGE_SECONDS) lands
    there; a genuinely rock-steady temperature would too, but outdoors at
    sensor resolution that does not happen inside an hour.
    """
    url = (conf.get('HA_URL') or '').strip().rstrip('/')
    token = (conf.get('HA_TOKEN') or '').strip()
    entity = (conf.get('HA_TEMP_ENTITY') or '').strip()
    if not url or not token or not entity:
        return None

    try:
        response = requests.get(
            f"{url}/api/states/{entity}",
            headers={'Authorization': f'Bearer {token}'},
            timeout=HA_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        state = response.json()
    except Exception as e:
        log.warning(f"Local sensor {entity} unreachable ({e}); using online temperature.")
        return None

    raw = state.get('state')
    if raw in (None, 'unavailable', 'unknown'):
        log.warning(f"Local sensor {entity} is {raw}; using online temperature.")
        return None
    try:
        value = float(raw)
    except (TypeError, ValueError):
        log.warning(f"Local sensor {entity} state {raw!r} is not numeric; using online temperature.")
        return None

    last_changed = state.get('last_changed') or ''
    try:
        changed_at = datetime.fromisoformat(last_changed.replace('Z', '+00:00'))
        age = (datetime.now(changed_at.tzinfo) - changed_at).total_seconds()
    except ValueError:
        log.warning(f"Local sensor {entity} has unparseable last_changed {last_changed!r}; using online temperature.")
        return None
    if age > HA_MAX_AGE_SECONDS:
        log.warning(f"Local sensor {entity} value unchanged for {int(age // 60)} min; using online temperature.")
        return None

    unit = (state.get('attributes') or {}).get('unit_of_measurement')
    temp_f = round(to_fahrenheit(value, unit), 1)
    log.info(f"Local sensor {entity}: {raw}{unit or ''} -> {temp_f}°F (changed {int(age)}s ago).")
    return temp_f


def fetch_ha_is_day(conf):
    """1/0 from Home Assistant's sun.sun entity, or None to use the online value.

    Only called when HA_WEATHER_ENTITY is configured - sun.sun is a stock
    entity present on every HA install, so no separate config knob for it.
    """
    url = (conf.get('HA_URL') or '').strip().rstrip('/')
    token = (conf.get('HA_TOKEN') or '').strip()
    if not url or not token:
        return None

    try:
        response = requests.get(
            f"{url}/api/states/sun.sun",
            headers={'Authorization': f'Bearer {token}'},
            timeout=HA_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        state = response.json().get('state')
    except Exception as e:
        log.warning(f"sun.sun unreachable ({e}); using online day/night.")
        return None

    if state == 'above_horizon':
        return 1
    if state == 'below_horizon':
        return 0
    return None


def fetch_ha_weather(conf):
    """Wind speed/direction, sky condition, and day/night from a Home
    Assistant weather.* entity (HA_WEATHER_ENTITY), as a dict containing only
    the keys read successfully - an empty dict means "use Open-Meteo for
    everything this function covers".

    These fields are independent of each other and of HA_TEMP_ENTITY, so a
    partial result (e.g. wind but no condition_code, because the condition
    string had no WMO mapping) is normal, not an error.
    """
    url = (conf.get('HA_URL') or '').strip().rstrip('/')
    token = (conf.get('HA_TOKEN') or '').strip()
    entity = (conf.get('HA_WEATHER_ENTITY') or '').strip()
    if not url or not token or not entity:
        return {}

    try:
        response = requests.get(
            f"{url}/api/states/{entity}",
            headers={'Authorization': f'Bearer {token}'},
            timeout=HA_TIMEOUT_SECONDS,
        )
        response.raise_for_status()
        state = response.json()
    except Exception as e:
        log.warning(f"Local weather entity {entity} unreachable ({e}); using online weather.")
        return {}

    # weather.* entities update their numeric attributes far more often than
    # their condition (`state`) string changes, so staleness has to be judged
    # against last_updated - last_changed only moves when the condition
    # itself flips and can otherwise sit unchanged for hours on a clear day.
    last_updated = state.get('last_updated') or ''
    try:
        updated_at = datetime.fromisoformat(last_updated.replace('Z', '+00:00'))
        age = (datetime.now(updated_at.tzinfo) - updated_at).total_seconds()
    except ValueError:
        log.warning(f"Local weather entity {entity} has unparseable last_updated {last_updated!r}; using online weather.")
        return {}
    if age > HA_MAX_AGE_SECONDS:
        log.warning(f"Local weather entity {entity} unchanged for {int(age // 60)} min; using online weather.")
        return {}

    attrs = state.get('attributes') or {}
    result = {}

    wind_speed = attrs.get('wind_speed')
    if isinstance(wind_speed, (int, float)):
        result['WindSpeed'] = wind_speed

    wind_bearing = attrs.get('wind_bearing')
    if isinstance(wind_bearing, (int, float)):
        result['WindDirection'] = wind_bearing

    condition = state.get('state')
    code = HA_CONDITION_TO_WMO_CODE.get(condition)
    if code is not None:
        result['ConditionCode'] = code
    elif condition not in (None, 'unavailable', 'unknown'):
        log.info(f"Local weather entity {entity} condition {condition!r} has no WMO mapping; keeping online condition.")

    is_day = fetch_ha_is_day(conf)
    if is_day is not None:
        result['IsDay'] = is_day

    if result:
        log.info(f"Local weather entity {entity}: {result} (updated {int(age)}s ago).")
    return result


def ensure_weather_schema():
    """Create/upgrade the weather table.

    Runs before any network call so a fresh install has the table even when the
    first fetch fails and the PHP pages read it as empty instead of erroring.
    Retry pacing on an offline station is handled in overview.php, which
    rate-limits its on-demand sync.
    """
    try:
        con = sqlite3.connect(DB_PATH)
        cur = con.cursor()

        # Ensure the weather table exists isolated from the detections table
        cur.execute('''
            CREATE TABLE IF NOT EXISTS weather (
                Date DATE,
                Hour INT,
                Temp FLOAT,
                ConditionCode INT,
                IsDay INT,
                WindSpeed FLOAT,
                WindDirection INT,
                PRIMARY KEY(Date, Hour)
            )
        ''')

        # Check for new columns (for existing tables)
        cur.execute("PRAGMA table_info(weather)")
        columns = [column[1] for column in cur.fetchall()]
        if 'IsDay' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN IsDay INT DEFAULT 1")
        if 'WindSpeed' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN WindSpeed FLOAT")
        if 'WindDirection' not in columns:
            cur.execute("ALTER TABLE weather ADD COLUMN WindDirection INT")

        con.commit()
        con.close()
        return True
    except Exception as e:
        log.error(f"Database error creating weather table: {e}")
        return False


def update_weather():
    conf = get_settings()
    if not weather_sync_enabled(conf):
        log.info("Weather syncing is disabled in Settings.")
        return

    lat = conf.get('LATITUDE', None)
    lon = conf.get('LONGITUDE', None)

    if not ensure_weather_schema():
        return

    if lat is None or lon is None or lat == '' or lon == '':
        log.error("Latitude or Longitude not set. Cannot fetch weather.")
        return

    data = fetch_hourly(lat, lon)
    if data is None:
        return

    local_temp = fetch_ha_temperature(conf)
    local_weather = fetch_ha_weather(conf)

    # Parse data
    times = data['hourly']['time']
    temps = data['hourly']['temperature_2m']
    codes = data['hourly']['weather_code']
    is_days = data['hourly']['is_day']
    winds = data['hourly']['wind_speed_10m']
    dirs = data['hourly']['wind_direction_10m']

    # Connect to the SQLite DB
    try:
        con = sqlite3.connect(DB_PATH)
        cur = con.cursor()

        # Insert or replace hourly metrics
        for t, temp, code, is_day, wind, direction in zip(times, temps, codes, is_days, winds, dirs):
            if temp is None:
                continue
            dt = datetime.fromisoformat(t)
            date_str = dt.strftime('%Y-%m-%d')
            hour = dt.hour
            
            cur.execute("INSERT OR REPLACE INTO weather (Date, Hour, Temp, ConditionCode, IsDay, WindSpeed, WindDirection) VALUES (?, ?, ?, ?, ?, ?, ?)",
                        (date_str, hour, temp, code, is_day, wind, direction))
                        
        # The local sensor/entity wins the current hour when healthy; every
        # other hour (and every fallback case) keeps the online value.
        now = datetime.now()
        if local_temp is not None:
            cur.execute("UPDATE weather SET Temp = ? WHERE Date = ? AND Hour = ?",
                        (local_temp, now.strftime('%Y-%m-%d'), now.hour))
            log.info(f"Current hour temperature set from local sensor: {local_temp}°F.")

        if local_weather:
            set_clause = ', '.join(f"{column} = ?" for column in local_weather)
            params = list(local_weather.values()) + [now.strftime('%Y-%m-%d'), now.hour]
            cur.execute(f"UPDATE weather SET {set_clause} WHERE Date = ? AND Hour = ?", params)
            log.info(f"Current hour {list(local_weather.keys())} set from local weather entity.")

        con.commit()
        con.close()
        log.info("Hourly weather data synced successfully to birds.db.")
    except Exception as e:
        log.error(f"Database error writing weather: {e}")

if __name__ == '__main__':
    update_weather()
