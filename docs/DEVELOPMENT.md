# Local development harness (no Raspberry Pi needed)

The web UI can be developed and smoke-tested on any machine with a PHP CLI
(8.x with the `sqlite3` extension). The pages only need three things a real
install provides: the config file, the docroot link layout, and a database.

## One-time setup

1. **Config stub** — the code reads `/etc/birdnet/birdnet.conf` (on Windows:
   `C:\etc\birdnet\birdnet.conf`). Create it with at least:

   ```ini
   BIRDNET_USER=pi
   SITE_NAME="BirdNET-Pi Dev"
   COLOR_SCHEME=light
   LATITUDE=40.030
   LONGITUDE=-75.020
   SILENCE_UPDATE_INDICATOR=0
   IMAGE_PROVIDER=WIKIPEDIA
   INFO_SITE=ALLABOUTBIRDS
   DATABASE_LANG=en
   FLICKR_API_KEY=
   FLICKR_FILTER_EMAIL=
   CADDY_PWD=devpassword
   RECS_DIR=/tmp/recs
   RTSP_STREAM=
   RTSP_STREAM_TO_LIVESTREAM=0
   CUSTOM_IMAGE=
   CUSTOM_IMAGE_TITLE=
   FREQSHIFT_RECONNECT_DELAY=4000
   ```

2. **Docroot links** — on a Pi, the installer symlinks `homepage/*`, `scripts/`,
   and several view PHPs into the web root (`install_services.sh`). Mirror that
   inside `homepage/` itself:
   - link `homepage/scripts` → `../scripts` (Windows: junction via
     `New-Item -ItemType Junction`)
   - link these files from `scripts/` into `homepage/`: `overview.php`,
     `play.php`, `spectrogram.php`, `stats.php`, `todays_detections.php`,
     `history.php` (Windows: hardlinks; symlinks if Developer Mode is on).
     **Hardlink gotcha:** editors that save via write-temp-then-rename sever
     hardlinks, leaving `homepage/` serving a stale copy. If a change to one
     of these six files doesn't show up, delete the `homepage/` copies and
     recreate the links.
   - add all of those to `.git/info/exclude` so `git status` stays clean

3. **Timezone shim** — `set_timezone()` shells out to `timedatectl`. On
   non-systemd machines put a stub named `timedatectl` (or `timedatectl.bat`)
   on `PATH` that prints a timezone like `America/New_York`.

4. **Demo database** — seed `scripts/birds.db` (gitignored) with realistic data:

   ```
   php tests/seed_demo_db.php
   ```

   The seed includes dawn-biased songbirds, a nocturnal owl, a rare new
   arrival, and a "gone quiet" species so the Insights pages have something
   to say. It is deterministic (fixed RNG seed).

## Run

From `homepage/`, with a router that emulates Caddy's behavior (static files
served directly, everything else → `index.php`, which also routes `/api/v1/*`):

```php
<?php // router.php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path !== '/' && is_file($_SERVER['DOCUMENT_ROOT'] . $path)) {
  return false;
}
require $_SERVER['DOCUMENT_ROOT'] . '/index.php';
```

```
php -S 127.0.0.1:8123 -t . router.php
```

Then browse `http://127.0.0.1:8123`. Authenticated views use HTTP basic auth:
user `birdnet`, password = `CADDY_PWD` from the config stub.

Useful routes for smoke testing:
- `/?view=Overview`, `/?view=Analytics`, `/?view=Species`, `/?view=Recordings`
- `/?view=Insights&subview=dashboard` (and the other six subviews)
- `/?view=Styleguide` — hidden component reference page (light/dark)
- `/api/v1/detections/recent?limit=3`, `/api/v1/analytics/stats?days=7`

Or run the whole suite (pages, read API, write API guards and round-trips):

```
BASE=http://127.0.0.1:8123 AUTH=birdnet:devpassword bash tests/smoke_api.sh
```

Add `RESEED=1` (and `PHP_BIN="<php> -c <ini>"` if PHP isn't on PATH) to reseed
the demo DB first — the seeded data is date-relative and the current-hour
checks go stale within hours, so always reseed when running the suite on a
dev box that has been up a while.

## Data-spine API (Phase 1)

Shared layers live in `scripts/common.php` (`get_visits`, `visits_from_detections`,
review/prefs helpers, purge-protection) with the spine schema in
`scripts/spine_schema.php` (mirrored in `createdb.sh` and
`update_birdnet_snippets.sh`).

Read endpoints: `/api/v1/detections/visits` (`?date=|days=|species=|format=csv`),
`/api/v1/dashboard/now`, `/api/v1/species/detail?sci_name=`,
`/api/v1/analytics/bundle?days=`, `/api/v1/reviews/queue`, `/api/v1/station/doctor`,
`/api/v1/notes`.

Write endpoints (HTTP basic auth + `X-Requested-With: XMLHttpRequest` header
required; the custom header forces a CORS preflight as CSRF protection):
- `POST /api/v1/reviews` — `{status, note?, file_name}` or
  `{status, note?, visit:{sci_name,date,from_time,to_time}}`; a visit review
  fans out to every member detection. `status: clear` removes reviews.
- `POST /api/v1/species/prefs` — partial update of
  `{favorite, muted, notify_mode, custom_threshold, crowned_clip}`. A pin
  (`crowned_clip`) is stored in `species_prefs` only; `update_purge_protection.php`
  writes pins and each species' best recordings into the managed
  `##start/##end` section of `disk_check_exclude.txt` before every cleanup.
  Lines outside the markers are manual Locks from the Recordings page.
- `POST /api/v1/notes` — `{body, date?, sci_name?, file_name?}` or
  `{action:"delete", id}`.

## Notes

- Always lint changed PHP with `php -l` before testing.
- The Insights fragment cache writes to `<system temp>/birdnet_cache/`; delete
  those files to force recomputation. Cache keys include the detections
  watermark (`MAX(rowid)`), date/hour, and the source file's mtime.
- Species/Overview image fetching needs the `openssl` PHP extension for the
  `https://` wrapper; without it the pages still render (images just fail).
