import sys
from pathlib import Path
from unittest.mock import patch


UTILS_DIR = Path(__file__).resolve().parents[1] / 'scripts' / 'utils'
sys.path.insert(0, str(UTILS_DIR))

import weather  # noqa: E402
from helpers import get_settings  # noqa: E402


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
            patch.object(weather, 'fetch_ha_temperature') as fetch_ha:
        weather.update_weather()

    ensure_schema.assert_not_called()
    fetch_hourly.assert_not_called()
    fetch_ha.assert_not_called()


def test_missing_switch_keeps_existing_update_path_enabled():
    with patch.object(weather, 'get_settings', return_value={}), \
            patch.object(weather, 'ensure_weather_schema', return_value=False) as ensure_schema, \
            patch.object(weather, 'fetch_hourly') as fetch_hourly:
        weather.update_weather()

    ensure_schema.assert_called_once_with()
    fetch_hourly.assert_not_called()
