import apprise
import json
import os
import socket
import requests
import html
import time

from .db import get_todays_count_for, get_this_weeks_count_for, get_lifetime_count_for, get_species_prefs
from .helpers import get_settings

userDir = os.path.expanduser('~')
APPRISE_CONFIG = userDir + '/BirdNET-Pi/apprise.txt'
APPRISE_BODY = userDir + '/BirdNET-Pi/body.txt'
SEASONAL_CACHE = userDir + '/BirdNET-Pi/scripts/seasonal_cache.json'

# Region-rare: expected weekly occurrence (0..1) from the cached location
# model below which a species is unusual for this area at this time of year.
REGION_RARE_THRESHOLD = 0.05
# Yard-rare: at most this many lifetime detections at this station.
YARD_RARE_LIFETIME_MAX = 5

apobj = None
images = {}
species_last_notified = {}
# Last detection time per species, used to group detections into visits:
# a detection within the visit gap of the previous one is the same visit.
species_last_detected = {}


def notify(body, title, attached=""):
    global apobj
    if apobj is None:
        asset = apprise.AppriseAsset(
            plugin_paths=[
                userDir + "/.apprise/plugins",
                userDir + "/.config/apprise/plugins",
            ]
        )
        apobj = apprise.Apprise(asset=asset)
        config = apprise.AppriseConfig()
        config.add(APPRISE_CONFIG)
        apobj.add(config)

    if attached != "":
        apobj.notify(
            body=body,
            title=title,
            attach=attached,
        )
    else:
        apobj.notify(
            body=body,
            title=title,
        )


def get_visit_gap_seconds(settings_dict):
    try:
        minutes = float(settings_dict.get('VISIT_GAP_MINUTES') or 5)
    except (TypeError, ValueError):
        minutes = 5
    return int(minutes * 60) if minutes > 0 else 300


def in_quiet_hours(settings_dict, hour=None):
    """True between APPRISE_QUIET_HOURS_START and APPRISE_QUIET_HOURS_END
    (local hours 0-23; supports ranges that wrap past midnight, e.g. 22-6)."""
    start = settings_dict.get('APPRISE_QUIET_HOURS_START')
    end = settings_dict.get('APPRISE_QUIET_HOURS_END')
    if start is None or end is None or str(start).strip() == "" or str(end).strip() == "":
        return False
    try:
        start = int(start)
        end = int(end)
    except (TypeError, ValueError):
        return False
    if start == end:
        return False
    if hour is None:
        hour = time.localtime().tm_hour
    if start < end:
        return start <= hour < end
    return hour >= start or hour < end


def is_region_rare(sci_name, week):
    """Two ways to be region-rare, judged against the species' own profile
    (mirrors is_region_rare() in scripts/common.php):
    - vagrant: the location model expects (almost) none here in ANY week, or
    - out of season: expected almost none now AND well below the species'
      own seasonal peak at this location.
    """
    try:
        with open(SEASONAL_CACHE) as f:
            cache = json.load(f)
        freqs = cache.get('data', {}).get(sci_name)
        if not freqs:
            return False
        freqs = [float(v) for v in freqs]
        annual_max = max(freqs)
        if annual_max < 0.02:
            return True
        week = int(week)
        idx = max(0, min(len(freqs) - 1, week - 1))
        return freqs[idx] < REGION_RARE_THRESHOLD and freqs[idx] < 0.25 * annual_max
    except (OSError, TypeError, ValueError, json.JSONDecodeError):
        return False


def rarity_reason(sci_name, week):
    """Why this detection is rare, or None if it isn't."""
    if is_region_rare(sci_name, week):
        return "rare for your area this week"
    lifetime = get_lifetime_count_for(sci_name)
    if 0 < lifetime <= YARD_RARE_LIFETIME_MAX:
        return f"rare visitor: {lifetime} lifetime detection{'s' if lifetime != 1 else ''}"
    return None


def sendAppriseNotifications(sci_name, com_name, confidence, confidencepct, path, date, time_of_day, week, latitude, longitude, cutoff, sens, overlap):
    def render_template(template, reason=""):
        ret = template.replace("$sciname", sci_name) \
            .replace("$comname", com_name) \
            .replace("$confidencepct", str(confidencepct)) \
            .replace("$confidence", str(confidence)) \
            .replace("$listenurl", listenurl) \
            .replace("$friendlyurl", friendlyurl) \
            .replace("$date", str(date)) \
            .replace("$time", str(time_of_day)) \
            .replace("$week", str(week)) \
            .replace("$latitude", str(latitude)) \
            .replace("$longitude", str(longitude)) \
            .replace("$cutoff", str(cutoff)) \
            .replace("$sens", str(sens)) \
            .replace("$flickrimage", image_url if "{" in body else "") \
            .replace("$image", image_url if "{" in body else "") \
            .replace("$overlap", str(overlap)) \
            .replace("$reason", reason)
        return ret

    settings_dict = get_settings()

    # Per-species preferences from the web UI override everything else
    prefs = get_species_prefs(sci_name) or {}
    notify_mode = prefs.get('notify_mode') or 'default'
    try:
        if int(prefs.get('muted') or 0):
            return
    except (TypeError, ValueError):
        pass
    if notify_mode == 'never':
        return

    if not should_notify(com_name):
        return

    # Visit grouping: only the first detection after a quiet gap opens a visit
    now_ts = int(time.time())
    prev_ts = species_last_detected.get(com_name)
    species_last_detected[com_name] = now_ts
    is_new_visit = prev_ts is None or (now_ts - prev_ts) > get_visit_gap_seconds(settings_dict)

    title = html.unescape(settings_dict.get('APPRISE_NOTIFICATION_TITLE') or 'New BirdNET-Pi Detection')
    f = open(APPRISE_BODY, 'r')
    body = f.read()

    websiteurl = settings_dict.get('BIRDNETPI_URL')
    if websiteurl is None or len(websiteurl) == 0:
        websiteurl = f"http://{socket.gethostname()}.local"

    listenurl = f"{websiteurl}?filename={path}"
    friendlyurl = f"[Listen here]({listenurl})"

    image_url = ""
    if "$flickrimage" in body or "$image" in body:
        if com_name not in images:
            try:
                url = f"http://localhost/api/v1/image/{sci_name}"
                resp = requests.get(url=url, timeout=10).json()
                images[com_name] = resp['data']['image_url']
            except Exception as e:
                print("IMAGE API ERROR:", e)
        image_url = images.get(com_name, "")

    def send(reason):
        notify(render_template(body, reason), render_template(title, reason), image_url)
        species_last_notified[com_name] = int(time.time())

    # A per-species notify mode replaces the station-wide rules entirely
    if notify_mode != 'default':
        if notify_mode == 'every_visit':
            if is_new_visit:
                send("new visit")
        elif notify_mode == 'first_daily':
            if get_todays_count_for(sci_name) <= 1:
                send("first time today")
        elif notify_mode == 'first_lifetime':
            if get_lifetime_count_for(sci_name) <= 1:
                send("first ever at this station")
        elif notify_mode == 'rare_only':
            if is_new_visit:
                reason = rarity_reason(sci_name, week)
                if reason:
                    send(reason)
        return

    # Station-wide rules (default mode)
    if settings_dict.get('APPRISE_NOTIFY_EACH_DETECTION') == "1":
        # Visit grouping is on by default: one notification per visit rather
        # than one per chirp. Set APPRISE_VISIT_GROUPING=0 to notify on every
        # single detection like older releases did.
        visit_grouping = settings_dict.get('APPRISE_VISIT_GROUPING') != "0"
        if not visit_grouping:
            send("detection")
        elif is_new_visit:
            send("new visit")

    APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT = 1  # Notifies the first N per day.
    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES_EACH_DAY') == "1":
        numberDetections = get_todays_count_for(sci_name)
        if 0 < numberDetections <= APPRISE_NOTIFICATION_NEW_SPECIES_DAILY_COUNT_LIMIT:
            send("first time today")

    if settings_dict.get('APPRISE_NOTIFY_NEW_SPECIES') == "1":
        numberDetections = get_this_weeks_count_for(sci_name)
        if 0 < numberDetections <= 5:
            send(f"only seen {numberDetections} times in last 7d")

    # Rare-bird alerts: region-rare (location model expects ~none this week)
    # or yard-rare (very few lifetime detections at this station)
    if settings_dict.get('APPRISE_NOTIFY_RARE') == "1" and is_new_visit:
        reason = rarity_reason(sci_name, week)
        if reason:
            send(reason)


def should_notify(com_name):
    settings_dict = get_settings()
    if not (os.path.exists(APPRISE_CONFIG) and os.path.getsize(APPRISE_CONFIG) > 0):
        return False

    # check if this is an excluded species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES.strip() != "":
        excluded_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES.split(",")]
        if com_name.lower().replace(" ", "") in excluded_species:
            return False

    # check if this is an included species
    APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 = settings_dict.get('APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2')
    if APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2 is not None and APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.strip() != "":
        included_species = [bird.lower().replace(" ", "") for bird in APPRISE_ONLY_NOTIFY_SPECIES_NAMES_2.split(",")]
        if com_name.lower().replace(" ", "") not in included_species:
            return False

    # overnight do-not-disturb window
    if in_quiet_hours(settings_dict):
        return False

    # is it still too soon?
    APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES = settings_dict.get('APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES')
    # An absent or blank key means no rate limit; without this the int() below
    # raises and the except branch drops every repeat notification.
    if APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES not in (None, "", "0"):
        if species_last_notified.get(com_name) is not None:
            try:
                if int(time.time()) - species_last_notified[com_name] < int(APPRISE_MINIMUM_SECONDS_BETWEEN_NOTIFICATIONS_PER_SPECIES):
                    return False
            except Exception as e:
                print("APPRISE NOTIFICATION EXCEPTION: " + str(e))
                return False

    return True


if __name__ == "__main__":
    print("notfications")
