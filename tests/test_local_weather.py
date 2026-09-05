"""Weather tests use only temporary databases and mocked HTTP, never the station."""
import json
import os
from pathlib import Path
import shutil
import sqlite3
import subprocess
import sys
from datetime import datetime, timezone
from types import SimpleNamespace
from unittest.mock import Mock

import pytest
import requests

ROOT = Path(__file__).resolve().parents[1]
sys.path.insert(0, str(ROOT / 'scripts' / 'utils'))
import weather
import weather_sources as sources

NOW = datetime(2026, 9, 5, 12, 30, tzinfo=timezone.utc).timestamp()
CONF = {'HA_URL': 'http://ha.invalid:8123', 'HA_TOKEN': 'test-secret',
        'HA_WEATHER_ENTITY': 'weather.garden', 'HA_TEMP_ENTITY': 'sensor.garden',
        'LATITUDE': '42', 'LONGITUDE': '-71'}


@pytest.fixture
def station(tmp_path, monkeypatch):
    clock = [NOW]

    class UTCClock(datetime):
        @classmethod
        def now(cls, tz=None):
            value = datetime.fromtimestamp(clock[0], timezone.utc)
            return value.astimezone(tz) if tz else value.replace(tzinfo=None)

        @classmethod
        def fromtimestamp(cls, value, tz=None):
            value = datetime.fromtimestamp(value, timezone.utc)
            return value.astimezone(tz) if tz else value.replace(tzinfo=None)

    monkeypatch.setattr(weather, 'DB_PATH', str(tmp_path / 'birds.db'))
    monkeypatch.setattr(weather, 'datetime', UTCClock)
    fake_time = SimpleNamespace(time=lambda: clock[0], sleep=lambda _: pytest.fail('Unexpected retry sleep'))
    monkeypatch.setattr(weather, 'time', fake_time)
    monkeypatch.setattr(sources, 'time', fake_time)
    monkeypatch.setattr(requests.sessions.Session, 'request', lambda *a, **k: pytest.fail('Unmocked HTTP'))
    return clock


def entity(state='sunny', attributes=None, age=0, **extra):
    result = {'state': state, 'attributes': attributes or {},
              'last_reported': datetime.fromtimestamp(NOW - age, timezone.utc).isoformat()}
    result.update(extra)
    return result


def reply(body, code=200):
    return Mock(status_code=code, json=Mock(return_value=body))


def local(value=10, field='WindSpeed', stamp=NOW, name='weather.garden'):
    return {field: (value, name, stamp)}


def rows():
    return [('2026-09-05', hour, 70, 0, 1, 5, 90) for hour in (11, 12, 13)]


def query(sql, args=()):
    with sqlite3.connect(weather.DB_PATH) as con:
        return con.execute(sql, args).fetchall()


@pytest.mark.parametrize('value,unit,expected', [
    (32, '°F', 32), (0, '°C', 32), (273.15, 'K', 32), ('-10', 'C', 14),
    (12, None, None), (12, 'unknown', None), (True, 'F', None),
    ('nan', 'F', None), (float('inf'), 'F', None), (-1, 'K', None),
])
def test_temperature_units(value, unit, expected):
    assert sources.temperature_f(value, unit) == expected


@pytest.mark.parametrize('value,unit,expected', [
    (10, 'mi/h', 10), (10, 'mph', 10), (16.09344, 'km/h', 10),
    (4.4704, 'm/s', 10), (8.68976242, 'kn', 10), (14.666666667, 'ft/s', 10),
    (0, 'km/h', 0), (-1, 'mph', None), (12, '', None), (3, 'Beaufort', None),
    (True, 'mph', None), ('nan', 'mph', None),
])
def test_wind_units(value, unit, expected):
    actual = sources.wind_mph(value, unit)
    assert actual == (pytest.approx(expected) if expected is not None else None)


@pytest.mark.parametrize('raw,expected', [
    ('NW', 315), ('nne', 22.5), (' SW ', 225), ('0', 0), (360, 0), (180.5, 180.5),
    (-1, None), (361, None), ('variable', None), (True, None), (float('inf'), None),
])
def test_bearings(raw, expected):
    assert sources.bearing_degrees(raw) == expected


def test_identical_but_reported_values_remain_fresh(station):
    state = entity(last_updated='2026-09-01T00:00:00Z', last_changed='2026-08-01T00:00:00Z')
    assert sources.report_time(state, NOW) == NOW


@pytest.mark.parametrize('raw', [None, '', 'invalid', '2026-09-05T12:30:00',
                                  '2026-09-05T11:29:59Z', '2026-09-05T12:35:01Z', 123])
def test_reject_bad_or_stale_timestamps(raw):
    assert sources.report_time({'last_reported': raw}, NOW) is None


def test_older_ha_timestamp_fallback():
    assert sources.report_time({'last_updated': '2026-09-05T12:00:00Z'}, NOW) == NOW - 1800


def test_only_unambiguous_conditions():
    assert sources.CONDITIONS['sunny'] == 0
    assert sources.CONDITIONS['fog'] == 45
    assert all(c not in sources.CONDITIONS for c in ('hail', 'snowy-rainy', 'rainy', 'snowy', 'windy', 'exceptional'))


def test_temperature_priority_and_partial_fields(station, monkeypatch):
    weather_body = entity(attributes={'temperature': 10, 'temperature_unit': '°C',
                                     'wind_speed': 16.09344, 'wind_speed_unit': 'km/h',
                                     'wind_bearing': 'NW'})
    sensor_body = entity('68', {'unit_of_measurement': '°F'})
    get = Mock(side_effect=[reply(weather_body), reply(sensor_body)])
    monkeypatch.setattr(sources.requests, 'get', get)
    readings, status = sources.collect_local_weather(CONF)
    assert readings['Temp'] == (68, 'sensor.garden', NOW)
    assert readings['WindSpeed'][0] == pytest.approx(10)
    assert readings['WindDirection'][0] == 315
    assert readings['ConditionCode'][0] == 0
    assert get.call_count == 2
    assert all('/sun.sun' not in call.args[0] for call in get.call_args_list)
    assert all(call.kwargs['allow_redirects'] is False for call in get.call_args_list)
    assert 'test-secret' not in json.dumps(status)


@pytest.mark.parametrize('sensor_body', [entity('unavailable'), entity('oops'), entity('50', {'unit_of_measurement': 'C'}, age=3601)])
def test_bad_sensor_uses_weather_entity_temperature(station, monkeypatch, sensor_body):
    get = Mock(side_effect=[reply(entity(attributes={'temperature': 20, 'temperature_unit': 'C'})),
                           reply(sensor_body)])
    monkeypatch.setattr(sources.requests, 'get', get)
    readings, _ = sources.collect_local_weather(CONF)
    assert readings['Temp'] == (68, 'weather.garden', NOW)


@pytest.mark.parametrize('body', [None, [], {}, {'state': 'sunny', 'attributes': []},
                                  entity('unknown'), entity('unavailable')])
def test_bad_ha_response_falls_back(station, monkeypatch, body):
    monkeypatch.setattr(sources.requests, 'get', Mock(return_value=reply(body)))
    readings, _ = sources.collect_local_weather(CONF)
    assert readings == {}


def test_timeout_does_not_leak_credentials(station, monkeypatch):
    monkeypatch.setattr(sources.requests, 'get', Mock(side_effect=requests.Timeout('test-secret')))
    readings, status = sources.collect_local_weather(CONF)
    assert not readings and 'test-secret' not in json.dumps(status)


def test_blank_entities_make_no_requests(station, monkeypatch):
    get = Mock()
    monkeypatch.setattr(sources.requests, 'get', get)
    assert sources.collect_local_weather({})[0] == {}
    get.assert_not_called()


def test_online_failure_still_saves_local(station, monkeypatch):
    monkeypatch.setattr(weather, 'get_settings', lambda: CONF)
    monkeypatch.setattr(weather, 'collect_local_weather', lambda _: (local(), {}))
    def fail_online(*_):
        assert query('SELECT Value FROM weather_local_observations') == [(10,)]
        return None
    monkeypatch.setattr(weather, 'fetch_hourly', fail_online)
    weather.update_weather()
    assert query('SELECT COUNT(*) FROM weather') == [(0,)]
    assert query('SELECT OnlineStatus FROM weather_sync_runs') == [('unavailable',)]


def test_missing_coordinates_do_not_block_local(station, monkeypatch):
    monkeypatch.setattr(weather, 'get_settings', lambda: {})
    monkeypatch.setattr(weather, 'collect_local_weather', lambda _: (local(), {}))
    weather.update_weather()
    assert query('SELECT Value FROM weather_local_observations') == [(10,)]


def test_schema_is_additive_and_old_queries_still_work(station):
    with sqlite3.connect(weather.DB_PATH) as con:
        con.executescript("""CREATE TABLE detections (Date TEXT, File_Name TEXT);
            INSERT INTO detections VALUES ('2020-01-01', 'keep.wav');
            CREATE TABLE weather (Date TEXT, Hour INT, Temp REAL, ConditionCode INT, PRIMARY KEY(Date,Hour));
            INSERT INTO weather VALUES ('2020-01-01', 1, 44, 3);""")
    assert weather.ensure_weather_schema()
    assert weather.ensure_weather_schema()
    assert query('SELECT rowid, Date, File_Name FROM detections') == [(1, '2020-01-01', 'keep.wav')]
    assert query('SELECT rowid, Date, Hour, Temp, ConditionCode FROM weather') == [(1, '2020-01-01', 1, 44, 3)]
    assert query('SELECT IsDay FROM weather') == [(None,)]


def test_past_rows_and_row_identity_survive_backfill(station):
    assert weather.ensure_weather_schema()
    with sqlite3.connect(weather.DB_PATH) as con:
        con.execute("INSERT INTO weather VALUES ('2026-09-05', 11, 33, 45, 0, 2, 30)")
        con.execute("INSERT INTO weather VALUES ('2026-09-05', 13, 88, 3, 1, 2, 30)")
    weather.save_online(rows(), datetime(2026, 9, 5, 12, 30))
    assert query('SELECT rowid, Temp, WindSpeed FROM weather WHERE Hour=11') == [(1, 33, 2)]
    assert query('SELECT rowid, Temp, WindSpeed FROM weather WHERE Hour=13') == [(2, 70, 5)]
    assert query('SELECT COUNT(*) FROM weather') == [(3,)]
    weather.save_online(rows(), datetime(2026, 9, 5, 13, 30))
    assert query('SELECT rowid, Temp, WindSpeed FROM weather WHERE Hour=11') == [(1, 33, 2)]


def test_partial_online_values_do_not_clear_existing_data(station):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    weather.save_online([('2026-09-05', 12, None, None, None, 12, None)], datetime(2026, 9, 5, 12))
    assert query('SELECT Temp, ConditionCode, WindSpeed, WindDirection FROM weather WHERE Hour=12') == [(70, 0, 12, 90)]


def test_failed_local_transaction_rolls_back_only_its_new_rows(station):
    weather.ensure_weather_schema()
    weather.save_local(local(), {}, NOW)
    with pytest.raises(ValueError):
        weather.save_local({'BadField': (3, 'weather.garden', NOW)}, {}, NOW + 1)
    assert query('SELECT COUNT(*) FROM weather_sync_runs') == [(1,)]
    assert query('SELECT Value FROM weather_local_observations') == [(10,)]


def test_collector_never_deletes_or_updates_detections(station, monkeypatch):
    weather.ensure_weather_schema()
    with sqlite3.connect(weather.DB_PATH) as con:
        con.execute('CREATE TABLE detections (File_Name TEXT)')
        con.execute("INSERT INTO detections VALUES ('keep.wav')")
    connect = sqlite3.connect
    def guarded_connect(*args, **kwargs):
        con = connect(*args, **kwargs)
        def authorize(action, table, column, *_):
            if action in (sqlite3.SQLITE_DELETE, sqlite3.SQLITE_DROP_TABLE):
                return sqlite3.SQLITE_DENY
            if table == 'detections' and action in (sqlite3.SQLITE_INSERT, sqlite3.SQLITE_UPDATE):
                return sqlite3.SQLITE_DENY
            return sqlite3.SQLITE_OK
        con.set_authorizer(authorize)
        return con
    monkeypatch.setattr(weather.sqlite3, 'connect', guarded_connect)
    weather.save_local(local(), {}, NOW)
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    assert query('SELECT File_Name FROM detections') == [('keep.wav',)]


def test_online_response_shape_and_nulls():
    data = {'hourly': {'time': ['2026-09-05T12:00'], 'temperature_2m': [None],
                      'weather_code': [None], 'is_day': [None],
                      'wind_speed_10m': [10], 'wind_direction_10m': [360]}}
    assert weather.hourly_rows(data) == [('2026-09-05', 12, None, None, None, 10, 0)]
    data['hourly']['time'].append('2026-09-05T13:00')
    with pytest.raises(ValueError):
        weather.hourly_rows(data)


def php_read(now, mode='rows', config=None):
    php = os.environ.get('BIRDNET_TEST_PHP') or shutil.which('php')
    if not php:
        pytest.skip('Set BIRDNET_TEST_PHP to run the cross-language SQLite tests')
    args = [php]
    ext = Path(php).parent / 'ext'
    if os.name == 'nt' and (ext / 'php_sqlite3.dll').exists():
        args += ['-d', 'extension_dir=' + str(ext), '-d', 'extension=sqlite3']
    result = subprocess.run(args + [str(ROOT / 'tests' / 'weather_reader.php'),
                                   weather.DB_PATH, str(int(now)), mode, json.dumps(config or {})],
                            capture_output=True, text=True, check=True)
    return json.loads(result.stdout)


def test_php_overlay_preserves_history_and_online_rows(station):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    weather.save_local(local(), {}, NOW)
    before = query('SELECT rowid,* FROM weather')
    current = {r['Hour']: r for r in php_read(NOW)}
    assert current[12]['WindSpeed'] == 10
    assert current[12]['WindSpeedSource'] == 'weather.garden'
    weather.save_online(rows(), datetime(2026, 9, 5, 13))
    weather.save_local(local(20, stamp=NOW + 3600), {}, NOW + 3600)
    history = {r['Hour']: r for r in php_read(NOW + 3600)}
    assert history[12]['WindSpeed'] == 10
    assert history[13]['WindSpeed'] == 20
    assert query('SELECT rowid,* FROM weather') == before


def test_php_current_failure_falls_back_but_past_keeps_good_observation(station):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    weather.save_local(local(), {}, NOW)
    weather.save_local({}, {}, NOW + 60)
    assert {r['Hour']: r for r in php_read(NOW + 60)}[12]['WindSpeed'] == 5
    assert {r['Hour']: r for r in php_read(NOW + 3600)}[12]['WindSpeed'] == 10


def test_php_expired_report_does_not_override_current(station):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    weather.save_local(local(stamp=NOW - 3500), {}, NOW)
    assert {r['Hour']: r for r in php_read(NOW + 200)}[12]['WindSpeed'] == 5


def test_php_local_only_partial_hour_has_no_fake_temperature_or_sky(station):
    weather.ensure_weather_schema()
    weather.save_local(local(), {}, NOW)
    row = php_read(NOW)[0]
    assert row['Temp'] is None and row['ConditionCode'] is None and row['IsDay'] is None
    assert row['WindSpeed'] == 10


def test_php_empty_and_legacy_databases_are_readable(station):
    with sqlite3.connect(weather.DB_PATH):
        pass
    assert php_read(NOW) == []
    with sqlite3.connect(weather.DB_PATH) as con:
        con.execute('CREATE TABLE weather (Date TEXT, Hour INTEGER, Temp REAL, ConditionCode INTEGER)')
        con.execute("INSERT INTO weather VALUES ('2026-09-05', 12, 0, 0)")
    row = php_read(NOW)[0]
    assert row['Temp'] == 0 and row['ConditionCode'] == 0 and row['IsDay'] is None


def test_doctor_uses_saved_status_without_network(station):
    weather.ensure_weather_schema()
    status = {'HA_WEATHER_ENTITY': {'accepted': ['WindSpeed'], 'message': 'Accepted wind.'}}
    run = weather.save_local(local(), status, NOW)
    weather.finish_run(run, 'unavailable', 'Open-Meteo offline.')
    checks = php_read(NOW, 'health', CONF)
    assert checks[0]['status'] == 'warn'
    assert checks[1]['status'] == 'ok'
    assert 'Accepted wind.' in checks[1]['message']
    disabled = php_read(NOW, 'health', {**CONF, 'WEATHER_ENABLED': '0'})
    assert len(disabled) == 1 and disabled[0]['status'] == 'ok'


@pytest.mark.parametrize('entity_id', ['weather.garden', '', None])
def test_weather_setting_saves_and_preserves_unrelated_config(station, entity_id):
    weather.ensure_weather_schema()
    source = 'HA_URL="http://ha.invalid"\nHA_TOKEN="leave-as-is"\n# Keep this comment\n'
    result = php_read(NOW, 'config', {'contents': source, 'entity': entity_id, 'previous': 'weather.old'})
    expected = 'weather.old' if entity_id is None else entity_id
    assert result['contents'] == source + 'HA_WEATHER_ENTITY="' + expected + '"\n'
    again = php_read(NOW, 'config', {'contents': result['contents'], 'entity': expected})
    assert again == result


@pytest.mark.parametrize('bad', ['sensor.garden', 'weather.bad-name', ['weather.garden']])
def test_invalid_weather_entity_does_not_produce_config(station, bad):
    weather.ensure_weather_schema()
    assert php_read(NOW, 'config', {'contents': 'HA_TOKEN="unchanged"\n', 'entity': bad}) == {'invalid': True}


def test_doctor_detects_source_change_and_report_expiry(station):
    weather.ensure_weather_schema()
    status = {'HA_WEATHER_ENTITY': {'entity': 'weather.garden', 'reported_at': NOW - 3500,
                                   'accepted': ['WindSpeed'], 'message': 'Accepted wind.'}}
    weather.save_local(local(), status, NOW)
    config = {**CONF, 'HA_TEMP_ENTITY': ''}
    assert php_read(NOW, 'health', config)[1]['status'] == 'ok'
    assert php_read(NOW + 200, 'health', config)[1]['status'] == 'warn'
    changed = php_read(NOW, 'health', {**config, 'HA_WEATHER_ENTITY': 'weather.other'})
    assert changed[1]['status'] == 'warn' and 'Settings changed' in changed[1]['message']


def test_overlay_does_not_multiply_historical_detection_counts(station):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    weather.save_local(local(10), {}, NOW)
    weather.save_local(local(15), {}, NOW + 60)
    with sqlite3.connect(weather.DB_PATH) as con:
        con.execute('CREATE TABLE detections (Date TEXT, Time TEXT)')
        con.executemany('INSERT INTO detections VALUES (?, ?)', [('2026-09-05', '12:00:00')] * 12000)
    before = Path(weather.DB_PATH).read_bytes()
    assert php_read(NOW + 3600, 'join') == [{'total': 12000, 'wind_total': 180000}]
    assert Path(weather.DB_PATH).read_bytes() == before


def test_lock_prevents_overlapping_syncs(station):
    with weather.sync_lock() as first:
        assert first
        with weather.sync_lock() as second:
            assert not second
    with weather.sync_lock() as after:
        assert after


def test_successful_online_sync_with_local_sources_disabled(station, monkeypatch):
    conf = {'LATITUDE': '42', 'LONGITUDE': '-71'}
    body = {'hourly': {'time': ['2026-09-05T12:00'], 'temperature_2m': [70],
                      'weather_code': [0], 'is_day': [1], 'wind_speed_10m': [5],
                      'wind_direction_10m': [90]}}
    monkeypatch.setattr(weather, 'get_settings', lambda: conf)
    monkeypatch.setattr(weather, 'fetch_hourly', lambda *_: body)
    weather.update_weather()
    assert query('SELECT Temp, WindSpeed FROM weather') == [(70, 5)]
    assert query('SELECT COUNT(*) FROM weather_local_observations') == [(0,)]
    assert query('SELECT OnlineStatus FROM weather_sync_runs') == [('ok',)]


def test_bad_online_payload_cannot_erase_local_or_existing_weather(station, monkeypatch):
    weather.ensure_weather_schema()
    weather.save_online(rows(), datetime(2026, 9, 5, 12))
    before = query('SELECT rowid,* FROM weather')
    monkeypatch.setattr(weather, 'get_settings', lambda: CONF)
    monkeypatch.setattr(weather, 'collect_local_weather', lambda _: (local(), {}))
    monkeypatch.setattr(weather, 'fetch_hourly', lambda *_: {'hourly': {'time': []}})
    weather.update_weather()
    assert query('SELECT rowid,* FROM weather') == before
    assert query('SELECT Value FROM weather_local_observations') == [(10,)]
    assert query('SELECT OnlineStatus FROM weather_sync_runs') == [('unavailable',)]
