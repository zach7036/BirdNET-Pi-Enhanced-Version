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

// In-place rewrite under an exclusive lock: the inode, owner and mode stay as
// they are, and the cleanup scripts never observe a half-written list.
$path = purge_exclude_path();
$created = !file_exists($path);
$fh = @fopen($path, 'c+');
if ($fh === false) {
  fail("cannot open $path for writing");
}
if (!flock($fh, LOCK_EX)) {
  fclose($fh);
  fail("cannot lock $path");
}
$existing = stream_get_contents($fh);
$before = '';
$after = '';
$start = strpos($existing, '##start');
$end = strpos($existing, '##end');
if ($start !== false && $end !== false && $end > $start) {
  $before = substr($existing, 0, $start);
  $after = substr($existing, $end + strlen('##end'));
} else {
  // No usable markers: keep whatever is there (manual protections) intact
  // above a fresh managed section.
  $before = $existing;
  if ($before !== '' && substr($before, -1) !== PHP_EOL) {
    $before .= PHP_EOL;
  }
  $after = PHP_EOL;
}
$managed = '##start' . PHP_EOL . ($lines ? implode(PHP_EOL, $lines) . PHP_EOL : '') . '##end';
$content = $before . $managed . $after;
if (substr($content, -1) !== PHP_EOL) {
  $content .= PHP_EOL;
}
if (rewind($fh) === false || ftruncate($fh, 0) === false || fwrite($fh, $content) === false || fflush($fh) === false) {
  flock($fh, LOCK_UN);
  fclose($fh);
  fail("write to $path failed");
}
flock($fh, LOCK_UN);
fclose($fh);
if ($created) {
  // Match the updater's "chmod 666 scripts/*.txt": the web user must be able
  // to add pins and manual protections to this file too.
  @chmod($path, 0666);
}

echo 'Protected ' . (count($lines) / 2) . " clips across $species_count species (keep $keep per species, $pins pinned)" . PHP_EOL;
