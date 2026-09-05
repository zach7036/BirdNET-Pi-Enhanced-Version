"""Hourly weather collection with additive local history and bounded DB writes."""
import json
import logging
import os
import sqlite3
import sys
import time
from contextlib import closing, contextmanager
from datetime import datetime
from zoneinfo import ZoneInfo, ZoneInfoNotFoundError

import requests

sys.path.append(os.path.dirname(os.path.abspath(__file__)))
from helpers import DB_PATH, get_settings
from weather_sources import collect_local_weather, number

logging.basicConfig(level=logging.INFO)
log = logging.getLogger('weather')
FETCH_ATTEMPTS = 3
RETRY_DELAY_SECONDS = [30, 60]
PAST_DAYS = 7
FIELDS = ('Temp', 'ConditionCode', 'IsDay', 'WindSpeed', 'WindDirection')
WMO_CODES = {0, 1, 2, 3, 45, 48, 51, 53, 55, 56, 57, 61, 63, 65, 66, 67,
             71, 73, 75, 77, 80, 81, 82, 85, 86, 95, 96, 99}


def weather_sync_enabled(conf):
    return str(conf.get('WEATHER_ENABLED', '1')).strip() != '0'


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


def ensure_weather_schema():
    """Add only: never rebuild a table or remove a row, including old installs."""
    try:
        with closing(sqlite3.connect(DB_PATH, timeout=2)) as con, con:
            con.execute("""CREATE TABLE IF NOT EXISTS weather (
                Date DATE, Hour INT, Temp FLOAT, ConditionCode INT, IsDay INT,
                WindSpeed FLOAT, WindDirection INT, PRIMARY KEY(Date, Hour))""")
            columns = {row[1] for row in con.execute('PRAGMA table_info(weather)')}
            for column, declaration in (('IsDay', 'INT'), ('WindSpeed', 'FLOAT'),
                                        ('WindDirection', 'INT')):
                if column not in columns:
                    con.execute(f'ALTER TABLE weather ADD COLUMN {column} {declaration}')
            con.execute("""CREATE TABLE IF NOT EXISTS weather_sync_runs (
                Id INTEGER PRIMARY KEY, Date TEXT NOT NULL, Hour INTEGER NOT NULL,
                CollectedAt REAL NOT NULL, FinishedAt REAL, LocalStatus TEXT NOT NULL,
                OnlineStatus TEXT NOT NULL DEFAULT 'pending', OnlineMessage TEXT NOT NULL DEFAULT '')""")
            con.execute("""CREATE INDEX IF NOT EXISTS weather_runs_hour
                ON weather_sync_runs (Date, Hour, Id DESC)""")
            con.execute("""CREATE TABLE IF NOT EXISTS weather_local_observations (
                Id INTEGER PRIMARY KEY, RunId INTEGER NOT NULL, Date TEXT NOT NULL,
                Hour INTEGER NOT NULL, Field TEXT NOT NULL, Value REAL NOT NULL,
                Entity TEXT NOT NULL, ReportedAt REAL NOT NULL,
                UNIQUE(RunId, Field))""")
            con.execute("""CREATE INDEX IF NOT EXISTS weather_local_hour
                ON weather_local_observations (Date, Hour, Field, RunId DESC)""")
        return True
    except sqlite3.Error as exc:
        log.error("Cannot prepare weather storage: %s", exc)
        return False


@contextmanager
def sync_lock():
    """Cron and manual syncs must not race; no DB lock is held during HTTP."""
    with open(DB_PATH + '.weather-sync.lock', 'a+b') as handle:
        if os.name == 'nt':
            import msvcrt
            handle.seek(0)
            try:
                msvcrt.locking(handle.fileno(), msvcrt.LK_NBLCK, 1)
            except OSError:
                yield False
                return
            try:
                yield True
            finally:
                handle.seek(0)
                msvcrt.locking(handle.fileno(), msvcrt.LK_UNLCK, 1)
        else:
            import fcntl
            try:
                fcntl.flock(handle, fcntl.LOCK_EX | fcntl.LOCK_NB)
            except BlockingIOError:
                yield False
                return
            try:
                yield True
            finally:
                fcntl.flock(handle, fcntl.LOCK_UN)


def save_local(readings, status, collected_at):
    """Append a complete local attempt before waiting for the online service.

    Empty attempts matter: the current display falls back immediately after an
    unsuccessful local fetch. Past hours still retain their last good observation.
    """
    local = datetime.fromtimestamp(collected_at)
    date, hour = local.strftime('%Y-%m-%d'), local.hour
    with closing(sqlite3.connect(DB_PATH, timeout=2)) as con, con:
        run = con.execute("""INSERT INTO weather_sync_runs
            (Date, Hour, CollectedAt, LocalStatus) VALUES (?, ?, ?, ?)""",
                          (date, hour, collected_at, json.dumps(status))).lastrowid
        for field, (value, entity, reported_at) in readings.items():
            if field not in FIELDS or field == 'IsDay':
                raise ValueError('Unsupported local weather field')
            con.execute("""INSERT INTO weather_local_observations
                (RunId, Date, Hour, Field, Value, Entity, ReportedAt) VALUES (?, ?, ?, ?, ?, ?, ?)""",
                        (run, date, hour, field, value, entity, reported_at))
    return run


def hourly_rows(data):
    """Validate the online response before any existing weather is updated."""
    hourly = data.get('hourly') if isinstance(data, dict) else None
    names = ('time', 'temperature_2m', 'weather_code', 'is_day',
             'wind_speed_10m', 'wind_direction_10m')
    if not isinstance(hourly, dict) or any(not isinstance(hourly.get(k), list) for k in names):
        raise ValueError('Incomplete hourly response')
    if not hourly['time'] or len({len(hourly[k]) for k in names}) != 1:
        raise ValueError('Inconsistent hourly response')
    zone = None
    if data.get('timezone'):
        try:
            zone = ZoneInfo(data['timezone'])
        except (ZoneInfoNotFoundError, TypeError, ValueError):
            raise ValueError('Unknown online timezone') from None
    rows = []
    for raw_time, raw_temp, raw_code, raw_day, raw_wind, raw_direction in zip(*(hourly[k] for k in names)):
        dt = datetime.fromisoformat(raw_time)
        # Open-Meteo's timezone=auto may differ from the Pi's timezone. Database
        # hour keys must use the same local clock as detections and the PHP UI.
        if dt.tzinfo is None and zone is not None:
            dt = dt.replace(tzinfo=zone)
        if dt.tzinfo is not None:
            dt = dt.astimezone()
        temp, code, day, wind, direction = map(number, (raw_temp, raw_code, raw_day, raw_wind, raw_direction))
        temp = temp if temp is not None and temp >= -459.67 else None
        code = int(code) if code in WMO_CODES else None
        day = int(day) if day in (0, 1) else None
        wind = wind if wind is not None and wind >= 0 else None
        direction = direction % 360 if direction is not None and 0 <= direction <= 360 else None
        if any(v is not None for v in (temp, code, day, wind, direction)):
            rows.append((dt.strftime('%Y-%m-%d'), dt.hour, temp, code, day, wind, direction))
    if not rows:
        raise ValueError('No usable online readings')
    return rows


def save_online(rows, now):
    """Fill missing history; only update an existing current/future hour.

    ON CONFLICT UPDATE preserves row identity (unlike INSERT OR REPLACE).
    A partial response never clears a previously stored value.
    """
    date, hour = now.strftime('%Y-%m-%d'), now.hour
    assignments = ', '.join(f'{field}=COALESCE(excluded.{field}, weather.{field})' for field in FIELDS)
    sql = f"""INSERT INTO weather (Date, Hour, {', '.join(FIELDS)})
        VALUES (?, ?, ?, ?, ?, ?, ?) ON CONFLICT(Date, Hour) DO UPDATE SET {assignments}
        WHERE weather.Date > ? OR (weather.Date = ? AND weather.Hour >= ?)"""
    with closing(sqlite3.connect(DB_PATH, timeout=2)) as con, con:
        con.executemany(sql, [tuple(row) + (date, date, hour) for row in rows])


def finish_run(run, status, message):
    with closing(sqlite3.connect(DB_PATH, timeout=2)) as con, con:
        con.execute("""UPDATE weather_sync_runs SET OnlineStatus=?, OnlineMessage=?, FinishedAt=?
            WHERE Id=?""", (status, message, time.time(), run))


def update_weather():
    conf = get_settings()
    if not weather_sync_enabled(conf):
        log.info('Weather syncing is disabled in Settings.')
        return
    if not ensure_weather_schema():
        return
    try:
        with sync_lock() as acquired:
            if not acquired:
                log.info('A weather sync is already running.')
                return
            readings, status = collect_local_weather(conf)
            run = save_local(readings, status, time.time())
            lat, lon = number(conf.get('LATITUDE')), number(conf.get('LONGITUDE'))
            if lat is None or lon is None or not (-90 <= lat <= 90 and -180 <= lon <= 180):
                finish_run(run, 'unavailable', 'Station coordinates are missing or invalid.')
                return
            data = fetch_hourly(lat, lon)
            if data is None:
                finish_run(run, 'unavailable', 'Open-Meteo could not be reached; saved local observations remain available.')
                return
            try:
                rows = hourly_rows(data)
            except (ValueError, TypeError, OverflowError):
                finish_run(run, 'unavailable', 'Open-Meteo returned incomplete or invalid weather.')
                return
            save_online(rows, datetime.now())
            finish_run(run, 'ok', 'Online weather synced; completed historical hours preserved.')
            log.info('Weather synced: %d local fields, %d online hours.', len(readings), len(rows))
    except (sqlite3.Error, OSError) as exc:
        log.error('Weather sync could not be saved: %s', exc)


if __name__ == '__main__':
    update_weather()
