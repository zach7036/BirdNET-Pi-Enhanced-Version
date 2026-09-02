import sys
from datetime import datetime, timedelta, timezone
from pathlib import Path
from unittest.mock import MagicMock, patch


UTILS_DIR = Path(__file__).resolve().parents[1] / 'scripts' / 'utils'
sys.path.insert(0, str(UTILS_DIR))

import weather  # noqa: E402
from helpers import get_settings  # noqa: E402


def _ha_response(json_body, status_ok=True):
    response = MagicMock()
    response.json.return_value = json_body
    if not status_ok:
        response.raise_for_status.side_effect = Exception('boom')
    return response


def test_weather_switch_defaults_to_enabled():
    assert weather.weather_sync_enabled({}) is True
    assert weather.weather_sync_enabled({'WEATHER_ENABLED': '1'}) is True
    assert weather.weather_sync_enabled({'WEATHER_ENABLED': ''}) is True
    assert weather.weather_sync_enabled({'WEATHER_ENABLED': 'unexpected'}) is True


def test_weather_switch_only_explicit_zero_disables():
    assert weather.weather_sync_enabled({'WEATHER_ENABLED': '0'}) is False
    assert weather.weather_sync_enabled({'WEATHER_ENABLED': ' 0 '}) is False


def test_weather_switch_with_real_birdnet_config_parser(tmp_path):
    config_path = tmp_path / 'birdnet.conf'
    config_path.write_text('WEATHER_ENABLED=0\nLATITUDE=1\nLONGITUDE=2\n', encoding='utf-8')
    conf = get_settings(str(config_path), force_reload=True)
    assert weather.weather_sync_enabled(conf) is False

    config_path.write_text('LATITUDE=1\nLONGITUDE=2\n', encoding='utf-8')
    conf = get_settings(str(config_path), force_reload=True)
    assert weather.weather_sync_enabled(conf) is True


def test_disabled_weather_exits_before_database_or_network_work():
    with patch.object(weather, 'get_settings', return_value={'WEATHER_ENABLED': '0'}), \
            patch.object(weather, 'ensure_weather_schema') as ensure_schema, \
            patch.object(weather, 'fetch_hourly') as fetch_hourly, \
            patch.object(weather, 'fetch_ha_temperature') as fetch_ha_temp, \
            patch.object(weather, 'fetch_ha_weather') as fetch_ha_weather:
        weather.update_weather()

    ensure_schema.assert_not_called()
    fetch_hourly.assert_not_called()
    fetch_ha_temp.assert_not_called()
    fetch_ha_weather.assert_not_called()


def test_fetch_ha_weather_returns_empty_when_unconfigured():
    assert weather.fetch_ha_weather({}) == {}
    assert weather.fetch_ha_weather({'HA_URL': 'http://ha', 'HA_TOKEN': 't'}) == {}


def test_fetch_ha_weather_reads_wind_condition_and_day():
    conf = {
        'HA_URL': 'http://ha:8123',
        'HA_TOKEN': 'token',
        'HA_WEATHER_ENTITY': 'weather.backyard',
    }
    fresh = datetime.now(timezone.utc).isoformat()
    weather_body = _ha_response({
        'state': 'rainy',
        'last_updated': fresh,
        'attributes': {'wind_speed': 12.3, 'wind_bearing': 200},
    })
    sun_body = _ha_response({'state': 'above_horizon'})

    with patch.object(weather.requests, 'get', side_effect=[weather_body, sun_body]):
        result = weather.fetch_ha_weather(conf)

    assert result == {
        'WindSpeed': 12.3,
        'WindDirection': 200,
        'ConditionCode': 61,
        'IsDay': 1,
    }


def test_fetch_ha_weather_stale_last_updated_falls_back():
    conf = {
        'HA_URL': 'http://ha:8123',
        'HA_TOKEN': 'token',
        'HA_WEATHER_ENTITY': 'weather.backyard',
    }
    stale = (datetime.now(timezone.utc) - timedelta(hours=2)).isoformat()
    weather_body = _ha_response({
        'state': 'sunny',
        'last_updated': stale,
        'attributes': {'wind_speed': 5, 'wind_bearing': 90},
    })

    with patch.object(weather.requests, 'get', return_value=weather_body):
        result = weather.fetch_ha_weather(conf)

    assert result == {}


def test_fetch_ha_weather_unmapped_condition_keeps_wind_but_drops_code():
    conf = {
        'HA_URL': 'http://ha:8123',
        'HA_TOKEN': 'token',
        'HA_WEATHER_ENTITY': 'weather.backyard',
    }
    fresh = datetime.now(timezone.utc).isoformat()
    weather_body = _ha_response({
        'state': 'windy',
        'last_updated': fresh,
        'attributes': {'wind_speed': 8, 'wind_bearing': 10},
    })
    sun_body = _ha_response({'state': 'below_horizon'})

    with patch.object(weather.requests, 'get', side_effect=[weather_body, sun_body]):
        result = weather.fetch_ha_weather(conf)

    assert result == {'WindSpeed': 8, 'WindDirection': 10, 'IsDay': 0}
    assert 'ConditionCode' not in result


def test_fetch_ha_is_day_maps_sun_state():
    conf = {'HA_URL': 'http://ha:8123', 'HA_TOKEN': 'token'}
    with patch.object(weather.requests, 'get', return_value=_ha_response({'state': 'above_horizon'})):
        assert weather.fetch_ha_is_day(conf) == 1
    with patch.object(weather.requests, 'get', return_value=_ha_response({'state': 'below_horizon'})):
        assert weather.fetch_ha_is_day(conf) == 0
    with patch.object(weather.requests, 'get', side_effect=Exception('boom')):
        assert weather.fetch_ha_is_day(conf) is None


def test_missing_switch_keeps_existing_update_path_enabled():
    with patch.object(weather, 'get_settings', return_value={}), \
            patch.object(weather, 'ensure_weather_schema', return_value=False) as ensure_schema, \
            patch.object(weather, 'fetch_hourly') as fetch_hourly:
        weather.update_weather()

    ensure_schema.assert_called_once_with()
    fetch_hourly.assert_not_called()
