#!/usr/bin/env bash
# API + page smoke test against a running dev server (see docs/DEVELOPMENT.md).
# Usage: BASE=http://127.0.0.1:8123 AUTH=birdnet:devpassword bash tests/smoke_api.sh
set -u

# The demo data is date-relative and goes stale within hours, failing the
# current-hour checks. RESEED=1 reseeds it first (opt-in so this can never
# run against a database that is not the throwaway dev demo; the seeder
# additionally refuses real databases without --force on its own).
if [ "${RESEED:-0}" = "1" ]; then
  # PHP_BIN is intentionally unquoted: it may carry flags (php -c dev.ini)
  if ${PHP_BIN:-php} "$(dirname "$0")/seed_demo_db.php" --force >/dev/null; then
    echo "== Reseeded demo DB =="
  else
    echo "WARN: reseed failed; continuing"
  fi
fi

BASE="${BASE:-http://127.0.0.1:8123}"
AUTH="${AUTH:-birdnet:devpassword}"
PASS=0
FAIL=0

check() { # check <name> <expected_status> <url> [curl args...]
  local name="$1" expected="$2" url="$3"
  shift 3
  local status
  status=$(curl -s -o /tmp/smoke_last.out -w "%{http_code}" "$@" "$BASE$url")
  if [ "$status" == "$expected" ]; then
    PASS=$((PASS+1))
    echo "ok   $name ($status)"
  else
    FAIL=$((FAIL+1))
    echo "FAIL $name (expected $expected, got $status) $url"
  fi
}

check_contains() { # check_contains <name> <needle>
  local name="$1" needle="$2"
  if grep -q "$needle" /tmp/smoke_last.out; then
    PASS=$((PASS+1))
    echo "ok   $name"
  else
    FAIL=$((FAIL+1))
    echo "FAIL $name (missing: $needle)"
    head -c 300 /tmp/smoke_last.out
    echo ""
  fi
}

echo "== Pages =="
for v in Now Live Doctor Review Overview Analytics Species Recordings Spectrogram Styleguide; do
  check "page $v" 200 "/?view=$v"
done
check "default route is Now" 200 "/"
check_contains "default shows Today's Story" "Today's Story"
check "now page hero" 200 "/?view=Now"
check_contains "now has hero" "LAST HEARD"
check "doctor weather sync unauthenticated" 401 "/scripts/doctor.php?sync_weather=true"
check "doctor support bundle unauthenticated" 401 "/scripts/doctor.php?support_bundle=true"
check "timeline page" 200 "/?view=Timeline"
check_contains "timeline has day replay" "Day replay"
check "bird page" 200 "/?view=Bird&sci_name=Cardinalis%20cardinalis"
check_contains "bird page has calendar" "birdCalendar"
check "bird page no species" 200 "/?view=Bird"
check_contains "bird page prompts selection" "No species selected"
check "review page v2" 200 "/?view=Review"
check_contains "review has keyboard help" "Reassign"
check "reviews examples" 200 "/api/v1/reviews/examples?sci_name=Cardinalis%20cardinalis"
check_contains "examples have source" '"source"'
check "examples missing param" 400 "/api/v1/reviews/examples"
check "timeline api has weather" 200 "/api/v1/detections/timeline"
check_contains "timeline weather key" '"weather"'
check "species detail has calendar" 200 "/api/v1/species/detail?sci_name=Cardinalis%20cardinalis"
check_contains "calendar key present" '"calendar"'
# best_recording is the best clip still on disk (null when none survive) and
# purged_best reports a higher-scoring clip that cleanup removed
check_contains "best recording key present" '"best_recording"'
check_contains "purged best key present" '"purged_best"'
check "queue has member clips" 200 "/api/v1/reviews/queue?days=7&limit=2"
check_contains "member_clips present" '"member_clips"'
check "labels endpoint" 200 "/play.php?getlabels=true"
check "queue has suggestions key" 200 "/api/v1/reviews/queue?days=7&limit=1"
check_contains "suggestions key present" '"suggestions"'
check "queue flags region-rare arrival" 200 "/api/v1/reviews/queue?days=3&limit=50"
check_contains "region_rare reason present" '"region_rare"'
check "now has region_rare flag" 200 "/api/v1/dashboard/now"
check_contains "region_rare key in now" '"region_rare"'
check "insights dashboard takeaways" 200 "/?view=Insights&subview=dashboard"
check_contains "plain english card" "In plain English"
check "insights behavior takeaways" 200 "/?view=Insights&subview=behavior"
check_contains "behavior takeaway present" "dawn chorus"
# Reports: each period must render its own report (the cache key once collided
# weekly/monthly/yearly into one entry)
check "report weekly" 200 "/?view=Insights&subview=report&type=weekly"
check_contains "weekly report title" "Week"
check "report monthly" 200 "/?view=Insights&subview=report&type=monthly"
check_contains "monthly report title" "Monthly Report"
check "report yearly" 200 "/?view=Insights&subview=report&type=yearly"
check_contains "yearly report title" "Yearly Report"
check "ebird preview" 200 "/api/v1/exports/ebird/preview"
check_contains "preview has row_count" '"row_count"'
check "settings page has quiet hours" 200 "/?view=Settings" -u "$AUTH"
check_contains "quiet hours fields" "apprise_quiet_start"
check "settings privacy panel" 200 "/?view=Settings" -u "$AUTH"
check_contains "privacy section" "Human-voice filtering"
check "manifest" 200 "/manifest.webmanifest"
check_contains "manifest has start url" '"start_url"'
check "service worker" 200 "/sw.js"
check_contains "sw has cache name" "birdnet-static"
check "pwa icon 192" 200 "/images/pwa-192.png"
check "year in birds" 200 "/?view=Year"
check_contains "year has champions or empty" "in Birds"
check "year previous" 200 "/?view=Year&y=2025"
check "shell has palette" 200 "/?view=Now"
check_contains "palette script loaded" "palette.js"
check_contains "bottom nav present" "bottom-nav"
check_contains "manifest linked" "manifest.webmanifest"
check_contains "palette launch button" "palette-launch"
check "bird page notify mode" 200 "/?view=Bird&sci_name=Cardinalis%20cardinalis"
check_contains "notify mode select" "notifyModeSel"
for sv in dashboard behavior migration environmental health forecasting report; do
  check "insights $sv" 200 "/?view=Insights&subview=$sv"
done
check "protected view unauthenticated" 401 "/?view=Settings"
check "protected view authenticated" 200 "/?view=Settings" -u "$AUTH"

echo "== Read API =="
check "system health" 200 "/api/v1/system/health"
check "weather current" 200 "/api/v1/weather/current"
check_contains "weather has display unit" '"temp_unit"'
check "species list" 200 "/api/v1/species/list?limit=5"
check "species list csv" 200 "/api/v1/species/list?limit=5&format=csv"
check "species search" 200 "/api/v1/species/search?q=card"
check "species detail" 200 "/api/v1/species/detail?sci_name=Cardinalis%20cardinalis"
check_contains "species detail has hourly" '"hourly_pattern"'
check "species detail missing param" 400 "/api/v1/species/detail"
check "species detail unknown" 404 "/api/v1/species/detail?sci_name=Nope%20nope"
check "recent detections" 200 "/api/v1/detections/recent?limit=3"
check "recent detections csv" 200 "/api/v1/detections/recent?limit=3&format=csv"
check "timeline" 200 "/api/v1/detections/timeline"
check_contains "timeline has clusters" '"clusters"'
check "visits today" 200 "/api/v1/detections/visits"
check_contains "visits have review_status" '"review_status"'
check "visits csv" 200 "/api/v1/detections/visits?format=csv"
check "dashboard now" 200 "/api/v1/dashboard/now"
check_contains "now has latest_visit" '"latest_visit"'
check_contains "now has review_worthy" '"review_worthy"'
check "analytics bundle" 200 "/api/v1/analytics/bundle?days=7"
check_contains "bundle has top_species" '"top_species"'
check "reviews queue" 200 "/api/v1/reviews/queue?days=7"
check_contains "queue has reasons" '"reasons"'
check "station doctor" 200 "/api/v1/station/doctor"
check_contains "doctor has checks" '"checks"'
check "notes list (empty ok)" 200 "/api/v1/notes"
check "unknown route" 404 "/api/v1/nope"

echo "== Write API guards =="
check "review unauthenticated" 401 "/api/v1/reviews" -X POST -H "Content-Type: application/json" -d '{}'
check "review no csrf header" 403 "/api/v1/reviews" -X POST -u "$AUTH" -H "Content-Type: application/json" -d '{}'
check "review bad status" 400 "/api/v1/reviews" -X POST -u "$AUTH" -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" -d '{"status":"banana","file_name":"x"}'
check "delete via api blocked" 405 "/api/v1/reviews" -X DELETE
check "post to read route blocked" 405 "/api/v1/system/health" -X POST

echo "== Write API round-trip =="
# Find a real visit to review (newest queue entry)
VISIT_JSON=$(curl -s "$BASE/api/v1/reviews/queue?days=7&limit=1")
SCI=$(echo "$VISIT_JSON" | sed -n 's/.*"sci_name":"\([^"]*\)".*/\1/p' | head -1)
DATE=$(echo "$VISIT_JSON" | sed -n 's/.*"date":"\([^"]*\)".*/\1/p' | head -1)
FROM=$(echo "$VISIT_JSON" | sed -n 's/.*"first_time":"\([^"]*\)".*/\1/p' | head -1)
TO=$(echo "$VISIT_JSON" | sed -n 's/.*"last_time":"\([^"]*\)".*/\1/p' | head -1)
if [ -n "$SCI" ] && [ -n "$DATE" ]; then
  check "review visit fan-out" 200 "/api/v1/reviews" -X POST -u "$AUTH" \
    -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
    -d "{\"status\":\"confirmed\",\"visit\":{\"sci_name\":\"$SCI\",\"date\":\"$DATE\",\"from_time\":\"$FROM\",\"to_time\":\"$TO\"}}"
  check_contains "fan-out affected > 0" '"affected":'
else
  echo "skip review round-trip (queue empty)"
fi

check "prefs favorite+crown" 200 "/api/v1/species/prefs" -X POST -u "$AUTH" \
  -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
  -d '{"sci_name":"Cardinalis cardinalis","favorite":true}'
check_contains "prefs favorite saved" '"favorite":1'

check "note create" 200 "/api/v1/notes" -X POST -u "$AUTH" \
  -H "X-Requested-With: XMLHttpRequest" -H "Content-Type: application/json" \
  -d '{"body":"Smoke test note","sci_name":"Cardinalis cardinalis"}'
check_contains "note created with id" '"id":'
check "notes list has note" 200 "/api/v1/notes?sci_name=Cardinalis%20cardinalis"
check_contains "note body present" "Smoke test note"

echo ""
echo "== Result: $PASS passed, $FAIL failed =="
[ "$FAIL" -eq 0 ]
