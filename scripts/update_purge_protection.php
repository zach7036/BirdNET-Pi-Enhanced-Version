<?php
// Rewrites the managed section of scripts/disk_check_exclude.txt with the
// clips that must survive disk cleanup: each species' best recordings (the
// top N by confidence that still exist on disk, N = PROTECTED_RECORDINGS_PER_
// SPECIES, reviewed false positives excluded) plus every pinned clip.
//
// disk_check.sh and disk_species_clean.sh run this immediately before they
// delete anything, so the list is always current: a new higher-scoring clip
// is protected at the next cleanup and the one it displaced becomes
// purgeable - no bookkeeping. Lines outside ##start/##end (pins written by
// the Birds page) are preserved.
//
// Usage: php scripts/update_purge_protection.php   (any working directory)
if (PHP_SAPI !== 'cli') {
  http_response_code(403);
  exit('cli only');
}
// CLI runs have no writable web session dir; keep common.php's session_start quiet.
ini_set('session.save_path', sys_get_temp_dir());
require_once __DIR__ . '/common.php';

$db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

$keep = protected_recordings_per_species();
$lines = [];
$species_count = 0;
foreach (all_species_best_candidates($db) as $sci_name => $candidates) {
  $species_count++;
  foreach (surviving_best_recordings($candidates, $keep) as $clip) {
    $lines[] = $clip['clip_path'];
    $lines[] = $clip['clip_path'] . '.png';
  }
}

$pins = 0;
if (spine_table_exists($db, 'species_prefs')) {
  $pinned = db_query_all_safe($db, "SELECT crowned_clip FROM species_prefs WHERE crowned_clip IS NOT NULL AND crowned_clip != ''", 'purge protection pins');
  foreach ($pinned as $row) {
    $rel = clip_relative_for_file($db, $row['crowned_clip']);
    if ($rel !== null) {
      $pins++;
      $lines[] = $rel;
      $lines[] = $rel . '.png';
    }
  }
}
$lines = array_values(array_unique($lines));

$path = purge_exclude_path();
$existing = is_file($path) ? (string)file_get_contents($path) : '';
$end_pos = strpos($existing, "##end");
$tail = $end_pos === false ? "##end\n" : substr($existing, $end_pos);
$content = "##start\n" . ($lines ? implode("\n", $lines) . "\n" : '') . $tail;

// Written atomically: the cleanup scripts read this file moments later, and
// a half-written list would let them delete protected clips.
$tmp = $path . '.tmp';
if (file_put_contents($tmp, $content) === false || !rename($tmp, $path)) {
  @unlink($tmp);
  fwrite(STDERR, "update_purge_protection: could not write $path\n");
  exit(1);
}
echo "Protected " . (count($lines) / 2) . " clips across $species_count species (keep $keep per species, $pins pinned)\n";
