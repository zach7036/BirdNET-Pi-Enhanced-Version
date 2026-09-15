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

## Review queue regression tests

These checks use synthetic data and mocked browser requests. They do not use
the station database or recordings, and do not require the development server:

```sh
php -d extension=sqlite3 -d extension=mbstring tests/test_review_data.php
php -d extension=sqlite3 -d extension=mbstring tests/test_review_workflow.php
php -d extension=sqlite3 -d extension=mbstring tests/test_review_cases.php
python -m pytest tests/test_review_api.py
BIRDNET_TEST_BROWSER=chrome node --test tests/test_review_ui.js
BIRDNET_TEST_BROWSER=chrome node --test tests/test_review_guided_ui.js
```

The API test needs PHP with SQLite3 and mbstring (`BIRDNET_TEST_PHP` can select
the executable). The browser test needs Playwright; omit `BIRDNET_TEST_BROWSER`
to use its bundled Chromium, or select an installed Chrome/Edge browser.

The default Review page now uses guided cases in `scripts/review_cases.php`.
The dashboard's `review_worthy` is the Recommended **item** count, not a visit
count. Both use the same selection helper; failures remain `null`, not zero.
`review_counts` now contains `recommended`, `all`, `history`, `samples`,
`active`, `unavailable`, `unresolved`, and `later`. See
[Guided Review](GUIDED_REVIEW.md) for the new endpoints, scoped actions,
provenance, idempotency, Undo, performance benchmark, and Pi validation.

The following describes the **legacy/advanced visit API**, retained for
compatibility and linked from Advanced tools. It is not the default dashboard
selection. `GET /api/v1/reviews/queue?group=...` still accepts:

- `ready` (default): Important and Routine together, important cases first.
- `important`: first-ever, regionally unusual, rare-visitor or low-precision records.
- `routine`: ordinary uncertain matches, using the 60% to below-85% band.
- `active`: visits still inside `VISIT_GAP_MINUTES`, including future-dated ones.
- `unavailable`: completed visits with no surviving, readable unreviewed audio.
- `skipped`: visits whose pending member clips are deferred.

The queue returns `total` for the selected view, `counts` for every category,
and `pending_total` across all views (without double-counting Ready's two
subgroups). Scores are displayed precisely enough to distinguish 84.99% from
85%. Each card includes explanatory `reason_details`. Important records are
ranked first-ever, regional rarity, rejection history, then yard rarity;
ties and routine records are oldest first. The underlying confidence band
and rarity thresholds are unchanged. Confidence does not override a rarity
or first-ever reason.

Trust and exclusion suggestions count independent completed visits, not clips.
A visit contributes one vote only when every member has the same confirmed
or false-positive verdict. Mixed, incomplete, hidden and unsure visits do not
establish trust. Ten independent decisions are required before applying the
existing 95% auto-trust or 50% low-precision cutoffs. Confirming an occurrence
suppresses repeat first-ever prompts for that species, but does not suppress
other applicable reasons or confirm any other detection.

Recording existence is checked only for eligible visits. A missing best clip
falls back to the strongest surviving unreviewed clip in that same visit;
its playback score is separate from the best recorded score. No audio means
no listening-based verdict buttons. Whole-visit reassignment is disabled if
any member file is missing, to avoid a predictable partial rename.

`POST /api/v1/reviews` retains the verdict `status` API (including legacy
`unsure` and `clear`). It additionally accepts `action: skip` or `resume` with
the same file/visit target, or `action: undo` with an `undo_token` returned by
a prior write. Skip defers pending files for 24 hours without changing their
verdicts or statistics. Resume removes that deferral early. Expiry is evaluated
on reads without deleting or confirming anything. The UI's Skip button replaces
Unsure; existing unsure verdicts are not migrated or reinterpreted.

Writes are transactional; `clear` removes only review metadata. The new helper
`scripts/review_actions.php` lazily creates three additive metadata tables on
the first write: `review_deferrals`, `review_state_versions`, `review_actions`.
No existing schema or detection rows are migrated. Undo history is retained in
the database; the UI keeps the last 20 undo tokens in this tab's session storage
(including across reloads). Undo restores previous verdicts, notes, timestamps
and deferrals, never detection rows or recording files. Version checks reject
conflicting newer decisions or renamed targets with HTTP 409. A repeated Undo
is idempotent. All writes, including Undo, require the existing authentication
and CSRF header. A failure in any member or in the undo journal rolls back the
entire action.

The existing species-reassignment flow renames files separately and is not part
of decision Undo; use Reassign to change an identification back. Unanticipated
rename failures can still report a partial result. After a decision, the UI
reloads the first remaining batch so removing visits never causes pagination
to skip work. Cards are not automatically reshuffled while someone is listening;
an empty view refreshes periodically to discover completed or unskipped visits.

On a Pi, validate the UI on the development branch: check Important/Routine,
let an active visit finish, Skip/Resume one known visit, and Undo a deliberate
test verdict. These operations should change only review metadata. Confirm the
Legacy Ready count matches its own visit endpoint and that existing audio still plays. Local automated
checks do not replace measuring queue responsiveness against a large real
station database.

## Notes

- Always lint changed PHP with `php -l` before testing.
- The Insights fragment cache writes to `<system temp>/birdnet_cache/`; delete
  those files to force recomputation. Cache keys include the detections
  watermark (`MAX(rowid)`), date/hour, and the source file's mtime.
- Species/Overview image fetching needs the `openssl` PHP extension for the
  `https://` wrapper; without it the pages still render (images just fail).
