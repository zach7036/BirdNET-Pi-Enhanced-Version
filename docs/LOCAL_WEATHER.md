# Local weather through Home Assistant

Under **Settings → Location & Weather → Local weather**, enter your Home Assistant URL and
long-lived access token. Add a **Weather entity** such as `weather.garden` and/or a
**Temperature entity** such as `sensor.garden_temperature`. Leave both blank to use
Open-Meteo as before. No additional Python packages or Home Assistant add-ons are required.

Temperature uses the first valid value from the dedicated sensor, the weather entity, then stored
Open-Meteo weather. Wind speed, wind direction and supported conditions use the weather entity,
with independent online fallback for each missing field. A weather entity may itself use a cloud
provider: select an integration backed by your own equipment if you want local measurements.

The collector runs hourly. After changing settings, use **Station Doctor → Sync weather now**.
Doctor displays the saved result of the last attempt, including unavailable sources and fields;
opening Doctor does not itself request Home Assistant data.

## Units, timestamps and conditions

- Temperature accepts °C/C, °F/F and Kelvin. Storage remains Fahrenheit.
- Wind accepts mph/mi/h, km/h, m/s, knots (`kn`) and ft/s. Storage remains mph.
  Beaufort and missing/unknown units are not guessed.
- Wind direction accepts degrees and the 16 compass points, including N, NNE and NW.
- Invalid, non-finite and physically invalid values are ignored per field.
- Home Assistant reports must be within one hour, with up to five minutes of clock skew.
  `last_reported` is preferred; older responses fall back to `last_updated` or
  `last_changed`. An unchanged reading can therefore remain valid. These timestamps indicate
  reporting to Home Assistant, not independent proof that the physical sensor is still sampling.
- Clear, partly cloudy, cloudy and fog conditions have direct mappings. Generic precipitation,
  hail, thunderstorm descriptions and unusual conditions retain available online information;
  the application does not invent precipitation intensity. No online condition means unavailable.
- Day/night still uses the station's location-based online information. No `sun.sun` request
  is made. Without stored day/night information, a clear condition uses a neutral icon.

Use the correct station timezone and coordinates. Hour keys use the Pi's local timezone so weather
joins the detections recorded by the station. Local measurements are snapshots at sync time, not
hourly averages; their Home Assistant report timestamps are stored as well.

## Internet outages and missing data

Local observations are saved **before** requesting Open-Meteo. Working Home Assistant sources can
therefore provide partial weather during an internet outage. Missing fields stay unavailable;
they are not converted into 0° or clear sky. Existing online data for an hour can still provide
fallback, even if the current online request fails.

Open-Meteo requests continue while weather syncing is enabled, including retries by the hourly
collector. This feature is not a no-internet-request mode. Turning **Enable weather syncing** off
stops both sources and retains all stored history.

Manual syncs have a shorter outer timeout than the hourly collector. If a manual attempt is
interrupted while waiting for Open-Meteo, already-saved local readings remain available. Doctor
will show that the online part did not finish; the normal hourly sync will try again.

## Data preservation and compatibility

The existing `weather` table remains the online/legacy source. A successful sync fills
missing hours and can refresh existing current/future hours in place. Existing completed historical
hours are not rewritten, and partial responses do not erase known values.

Two additive tables hold new information:

- `weather_sync_runs`: collection times, safe source diagnostics and online sync outcomes.
- `weather_local_observations`: accepted values, source entity and report timestamp for each run.

Local observations are never written over legacy weather rows. The PHP pages combine the two sources
through a connection-local, read-only view. A failed local attempt makes the current hour fall back;
completed hours keep the last good local observation for each field. Observations and sync records
are retained; there is no automatic deletion policy in this change. Hourly runs normally add at most
four local observation rows and one sync row. Repeated manual syncs add additional records.

Existing old temperature overrides have no reliable provenance in the legacy table. They are kept
as stored; the update cannot reconstruct local readings previously overwritten by earlier releases.

There is no detection migration, table rebuild, recording cleanup or database reset in this feature.
Older installations may receive the existing additive weather-column upgrade, with unknown new
fields left NULL. The previous code can still read the original weather table and ignore the two
new tables. Reverting hides the new local overlay; it does not delete its saved observations.

## Testing on your existing Pi and rolling back

A second Pi is not required. The automated tests use temporary databases and mocked HTTP. A live
integration test needs access to a Home Assistant weather entity and can use your existing station.

Before deploying the reviewed development code, record the current commit, save your configuration,
and take a consistent SQLite backup. From your Pi's BirdNET-Pi checkout:

```bash
backup_dir="$(mktemp -d "$HOME/birdnet-weather-backup.XXXXXX")"
git rev-parse HEAD > "$backup_dir/code-before.txt"
cp /etc/birdnet/birdnet.conf "$backup_dir/birdnet.conf"
sqlite3 -readonly scripts/birds.db ".timeout 5000" ".backup '$backup_dir/birds.db'"
sqlite3 -readonly "$backup_dir/birds.db" "PRAGMA quick_check;"
printf 'Backup directory: %s\n' "$backup_dir"
```

Require the backup commands to succeed and `quick_check` to report `ok` before proceeding.
The SQLite backup API produces a consistent snapshot while the station continues recording.
Copy the backup somewhere other than the Pi too. The configuration contains credentials; keep
the backup directory private. This is a data/configuration backup, not a complete OS image.

For this weather-only change, a source-code update is sufficient: no installer, database creation
script, package upgrade or recording-service restart is needed. The next weather sync creates its
additive tables, and Settings can save the new key even if it was absent from the old config.
Reload browser assets after switching code.

Check the following before promoting development to main:

1. With both local entities blank, verify the normal online-weather display and bird recording.
2. Configure the intended entities, sync, and compare units and source results with Home Assistant.
3. Check Overview, Timeline and the weather Insights page, including zero and missing readings.
4. Run another sync in the following hour and confirm local history is retained.
5. For fallback testing, use an invalid test entity or the isolated automated tests. You do not
   need to disconnect the Pi, unplug its microphone, stop recording, or shut down Home Assistant.

For routine rollback, switch back to the recorded commit while preserving any unrelated local
edits, reload the browser assets, and restore only the weather settings you changed. Leave the new
tables in place: old code ignores them. **Do not restore the entire database for routine code
rollback**, because that would discard detections collected after the backup. The previous
collector resumes its previous online-history behavior, while the separate local observations
remain stored for a later return to the new version.

## Automated checks

```bash
python -m pytest -q tests/test_weather_switch.py tests/test_local_weather.py
php tests/test_weather_switch.php
node tests/test_weather_ui.js
```

The Python suite also invokes PHP with SQLite support to test the actual read-only overlay.
If PHP is not on PATH, set `BIRDNET_TEST_PHP` to its executable; without PHP those cross-language
checks are explicitly skipped. No test should point at the station's database.
