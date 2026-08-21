<?php
// Rewrites the managed section of scripts/disk_check_exclude.txt with the
// clips that must survive disk cleanup: each species' best recordings (the
// top N by confidence that still exist on disk, N = PROTECTED_RECORDINGS_PER_
// SPECIES, reviewed false positives excluded) plus every pinned clip.
//
// disk_check.sh and disk_species_clean.sh run this immediately before they
// delete anything, so the list is always current: a new higher-scoring clip
// is protected at the next cleanup and the one it displaced becomes
// purgeable - no bookkeeping.
//
// Fails closed. Anything that prevents a trustworthy list (database error,
// clip directory missing, nothing found although clips exist) exits non-zero
// WITHOUT touching the file, and the callers skip their destructive pass.
// The file is rewritten in place under a lock so its owner and mode survive
// (php-fpm writes pins and manual protections to the same file), and every
// line outside ##start/##end is preserved.
//
// Usage: php scripts/update_purge_protection.php   (any working directory)
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('cli only');
}
require_once __DIR__ . '/common.php';

function fail($message) {
  fwrite(STDERR, "update_purge_protection: $message - leaving the protection list untouched\n");
  exit(1);
}

// The cleanup scripts call this while already holding the shared cleanup lock
// (PURGE_LOCK_HELD=1); a standalone run (the updater, a manual invocation)
// takes it here so it cannot race a cleanup pass or a web Lock/Pin write.
$lock = null;
if (getenv('PURGE_LOCK_HELD') !== '1') {
  $lock = purge_lock_acquire(120);
  if ($lock === false) {
    fail('could not acquire the cleanup lock (a cleanup pass is running)');
  }
}

try {
  $db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
} catch (Throwable $e) {
  fail('cannot open birds.db (' . $e->getMessage() . ')');
}
$db->busyTimeout(5000);

$base = clip_base_dir();
if (!is_dir($base)) {
  fail("clip directory $base does not exist");
}

$keep = protected_recordings_per_species();
$by_species = all_species_best_candidates($db);
if ($by_species === false) {
  fail('candidate query failed (database busy?)');
}

$lines = [];
$species_count = 0;
foreach ($by_species as $sci_name => $candidates) {
  $species_count++;
  $survivors = filter_surviving_clips($candidates, $keep);
  // The one-pass window holds 50 candidates; a species whose whole window
  // was purged may still have clips further down.
  if (count($survivors) < $keep && count($candidates) >= 50) {
    $more = species_best_surviving($db, $sci_name, $keep - count($survivors), 50);
    if ($more === false) {
      fail("candidate paging failed for $sci_name");
    }
    $survivors = array_merge($survivors, $more);
  }
  foreach ($survivors as $clip) {
    $lines[] = $clip['clip_path'];
    $lines[] = $clip['clip_path'] . '.png';
  }
}

// A database full of detections but zero surviving clips while day folders
// exist means this script is looking in the wrong place, not that everything
// was purged. Refuse rather than publish an empty list.
if ($species_count > 0 && !$lines && glob($base . '/*', GLOB_ONLYDIR)) {
  fail("no surviving clips found for $species_count species although $base has day folders");
}

$pins = 0;
if (spine_table_exists($db, 'species_prefs')) {
  $pin_sql = 'SELECT d.Date, d.Com_Name, d.File_Name FROM species_prefs p JOIN detections d'
           . ' ON d.File_Name = p.crowned_clip AND d.Sci_Name = p.sci_name'
           . " WHERE p.crowned_clip IS NOT NULL AND p.crowned_clip != ''" . and_review_exclusion($db, 'd.File_Name');
  $pin_res = db_query_safe($db, $pin_sql, 'purge protection pins');
  if ($pin_res === false) {
    fail('pinned clip query failed');
  }
  while ($row = db_fetch_assoc_safe($pin_res)) {
    $pins++;
    $rel = detection_clip_relative_path($row['Date'], $row['Com_Name'], $row['File_Name']);
    $lines[] = $rel;
    $lines[] = $rel . '.png';
  }
}
$lines = array_values(array_unique($lines));

// Manual locks (the lines outside the markers) are preserved verbatim; only
// the managed section is replaced. Written to a temp file and renamed over
// the target, so a reader that does not hold the lock (the web page's
// padlock rendering) sees the old list or the new one, never a partial one.
// The file is mode 666 (installer/updater), so ownership moving to whoever
// ran this - the station user from cron - costs the web user nothing.
$sections = purge_exclude_sections();
$out = array_merge($sections['before'], ['##start'], $lines, ['##end'], $sections['after']);
$out = array_values(array_filter($out, function ($l) { return $l !== ''; }));
$content = implode(PHP_EOL, $out) . PHP_EOL;

$path = purge_exclude_path();
$tmp = $path . '.tmp.' . getmypid();
if (@file_put_contents($tmp, $content) === false) {
  fail("cannot write $tmp");
}
@chmod($tmp, 0666);
if (!@rename($tmp, $path)) {
  // PHP on Windows (the dev harness) cannot rename over an existing file.
  if (PHP_OS_FAMILY !== 'Windows' || !@unlink($path) || !@rename($tmp, $path)) {
    @unlink($tmp);
    fail("cannot replace $path");
  }
}
@chmod($path, 0666);
purge_lock_release($lock);

echo 'Protected ' . (count($lines) / 2) . " clips across $species_count species (keep $keep per species, $pins pinned)" . PHP_EOL;
