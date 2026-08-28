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
                        
        # The local sensor wins the current hour when healthy; every other
        # hour (and every fallback case) keeps the online value.
        if local_temp is not None:
            now = datetime.now()
            cur.execute("UPDATE weather SET Temp = ? WHERE Date = ? AND Hour = ?",
                        (local_temp, now.strftime('%Y-%m-%d'), now.hour))
            log.info(f"Current hour temperature set from local sensor: {local_temp}°F.")

        con.commit()
        con.close()
        log.info("Hourly weather data synced successfully to birds.db.")
    except Exception as e:
        log.error(f"Database error writing weather: {e}")

if __name__ == '__main__':
    update_weather()
