"""Read optional Home Assistant observations; no database or filesystem writes.

HA reports values in the entity's presentation units, not necessarily the
station's units. All accepted values here use BirdNET-Pi's Fahrenheit/mph/degrees.
"""
import math
import re
import time
from datetime import datetime
from urllib.parse import urlsplit

import requests

MAX_REPORT_AGE = 3600
CLOCK_SKEW = 300
REQUEST_TIMEOUT = 10

# Only descriptions that do not invent precipitation type or intensity.
CONDITIONS = {'sunny': 0, 'clear-night': 0, 'partlycloudy': 2, 'cloudy': 3, 'fog': 45}
WIND_FACTORS = {'mph': 1, 'mi/h': 1, 'km/h': 1 / 1.609344,
                'm/s': 1 / 0.44704, 'kn': 1.150779448, 'ft/s': 3600 / 5280}
COMPASS = {name: index * 22.5 for index, name in enumerate(
    'N NNE NE ENE E ESE SE SSE S SSW SW WSW W WNW NW NNW'.split())}


def number(value):
    if isinstance(value, bool) or value is None:
        return None
    try:
        result = float(value)
        return result if math.isfinite(result) else None
    except (TypeError, ValueError, OverflowError):
        return None


def temperature_f(value, unit):
    value = number(value)
    unit = str(unit or '').strip().upper()
    if value is None:
        return None
    if unit in ('C', '°C'):
        value = value * 1.8 + 32
    elif unit == 'K':
        value = (value - 273.15) * 1.8 + 32
    elif unit not in ('F', '°F'):
        return None
    return round(value, 1) if math.isfinite(value) and value >= -459.67 else None


def wind_mph(value, unit):
    value = number(value)
    factor = WIND_FACTORS.get(str(unit or '').strip().lower())
    if value is None or value < 0 or factor is None:
        return None
    result = value * factor
    return result if math.isfinite(result) else None


def bearing_degrees(value):
    if isinstance(value, str) and value.strip().upper() in COMPASS:
        return COMPASS[value.strip().upper()]
    value = number(value)
    return value % 360 if value is not None and 0 <= value <= 360 else None


def report_time(state, now):
    # last_reported advances when identical values are written to HA. This
    # measures HA reporting freshness, not the physical sensor's sample age.
    raw = next((state.get(key) for key in ('last_reported', 'last_updated', 'last_changed')
                if state.get(key)), None)
    if not isinstance(raw, str):
        return None
    try:
        stamp = datetime.fromisoformat(raw.replace('Z', '+00:00'))
        if stamp.tzinfo is None:
            return None
        stamp = stamp.timestamp()
        return stamp if -CLOCK_SKEW <= now - stamp <= MAX_REPORT_AGE else None
    except (ValueError, OverflowError, OSError):
        return None


def read_entity(conf, key, domain):
    entity = str(conf.get(key) or '').strip()
    if not entity:
        return None, None, 'Not configured.'
    if not re.fullmatch(re.escape(domain) + r'\.[a-z0-9_]+', entity):
        return None, None, 'Invalid entity ID.'
    url = str(conf.get('HA_URL') or '').strip().rstrip('/')
    token = str(conf.get('HA_TOKEN') or '').strip()
    try:
        parsed = urlsplit(url)
        valid_url = parsed.scheme in ('http', 'https') and parsed.hostname and not parsed.username
    except ValueError:
        valid_url = False
    if not valid_url or not token:
        return None, None, 'Home Assistant URL or token is missing or invalid.'
    try:
        response = requests.get(url + '/api/states/' + entity,
                                headers={'Authorization': 'Bearer ' + token},
                                timeout=REQUEST_TIMEOUT, allow_redirects=False)
        if response.status_code != 200:
            return None, None, 'Home Assistant returned HTTP ' + str(response.status_code) + '.'
        state = response.json()
    except (requests.RequestException, ValueError):
        # Exception text can contain credentials or the configured URL.
        return None, None, 'Home Assistant request failed.'
    if not isinstance(state, dict) or not isinstance(state.get('attributes'), dict):
        return None, None, 'Invalid entity response.'
    if not isinstance(state.get('state'), str) or state['state'] in ('unknown', 'unavailable'):
        return None, None, 'Entity is unavailable.'
    stamp = report_time(state, time.time())
    if stamp is None:
        return None, None, 'Report is older than one hour or its timestamp is invalid.'
    return state, stamp, 'Reporting.'


def collect_local_weather(conf):
    """Return accepted fields (value, entity, report time) and safe diagnostics."""
    readings = {}
    status = {}
    for key, domain in (('HA_WEATHER_ENTITY', 'weather'), ('HA_TEMP_ENTITY', 'sensor')):
        state, stamp, message = read_entity(conf, key, domain)
        entity = str(conf.get(key) or '').strip()
        accepted = []
        if state is not None:
            attrs = state['attributes']
            if domain == 'sensor':
                values = {'Temp': temperature_f(state['state'], attrs.get('unit_of_measurement'))}
            else:
                values = {
                    'Temp': temperature_f(attrs.get('temperature'), attrs.get('temperature_unit')),
                    'WindSpeed': wind_mph(attrs.get('wind_speed'), attrs.get('wind_speed_unit')),
                    'WindDirection': bearing_degrees(attrs.get('wind_bearing')),
                    'ConditionCode': CONDITIONS.get(state['state']),
                }
            for field, value in values.items():
                if value is not None:
                    readings[field] = (value, entity, stamp)
                    accepted.append(field)
            omitted = [field for field, value in values.items() if value is None]
            message = ('Accepted: ' + ', '.join(accepted) + '. ') if accepted else ''
            if omitted:
                message += 'Missing/unsupported: ' + ', '.join(omitted) + '; using available fallback.'
        status[key] = {'configured': bool(entity), 'entity': entity, 'reported_at': stamp,
                       'accepted': accepted, 'message': message}
    return readings, status
