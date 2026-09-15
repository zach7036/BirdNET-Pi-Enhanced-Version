<?php
// Run: php -d extension=sqlite3 -d extension=mbstring tests/test_review_data.php
// Synthetic in-memory databases only, except two connections to an isolated
// temporary file for the lock test. Never opens scripts/birds.db or audio.
require_once __DIR__ . '/../scripts/common.php';
require_once __DIR__ . '/../scripts/spine_schema.php';

$checks = 0;
function check($ok, $message) {
  global $checks;
  $checks++;
  if (!$ok) throw new RuntimeException($message);
}
function same($actual, $expected, $message) {
  check($actual === $expected, $message . ': ' . json_encode($actual) . ' !== ' . json_encode($expected));
}
function fails($fn, $type = Throwable::class) {
  try { $fn(); } catch (Throwable $e) { check($e instanceof $type, 'Expected ' . $type . ', got ' . get_class($e)); return; }
  throw new RuntimeException('Expected an exception');
}
function fixture($db = null) {
  $db = $db ?? new SQLite3(':memory:');
  $db->enableExceptions(true);
  $db->exec('CREATE TABLE detections (Date TEXT, Time TEXT, Sci_Name TEXT, Com_Name TEXT, Confidence FLOAT, File_Name TEXT)');
  return $db;
}
function day($db, $ago = 0) {
  return $db->querySingle("SELECT DATE('now','localtime','-$ago days')");
}
function detection($db, $file, $time = '12:00:00', $conf = 0.70, $ago = 0, $sci = 'Testus birdus') {
  review_query($db, 'INSERT INTO detections VALUES (:date,:time,:sci,:com,:conf,:file)',
    [':date' => day($db, $ago), ':time' => $time, ':sci' => $sci, ':com' => 'Test Bird', ':conf' => $conf, ':file' => $file])->finalize();
}
function history($db, $sci = 'Testus birdus', $n = 6) {
  for ($i = 0; $i < $n; $i++) detection($db, $sci . '-old-' . $i, '10:00:00', 0.99, 30, $sci);
}
function queue_data($db, $options = []) {
  return review_queue_data($db, $options + ['gap_seconds' => 300]);
}
function pending($db) {
  $full = queue_data($db);
  $count = queue_data($db, ['limit' => 0]);
  same($count['queue'], [], 'Count-only response has no cards');
  same($count['total'], $full['total'], 'Dashboard and queue agree');
  return $full['total'];
}
function verdict($db, $status, $from = '12:00:00', $to = '12:01:00') {
  return save_detection_review($db, ['status' => $status, 'visit' => ['sci_name' => 'Testus birdus',
    'date' => day($db), 'from_time' => $from, 'to_time' => $to]]);
}
function rows($db, $table = 'detections') { return review_rows($db, 'SELECT * FROM ' . $table . ' ORDER BY rowid'); }
function schema($db) { foreach (spine_schema_statements_standalone() as $sql) $db->exec($sql); }
function count_reviews($db) { return (int)$db->querySingle('SELECT COUNT(*) FROM detection_reviews'); }
function ordinary_visit() {
  $db = fixture(); history($db);
  detection($db, 'a.wav'); detection($db, 'b.wav', '12:01:00', 0.75);
  return $db;
}

// Legacy databases can be counted without creating any schema on a read.
$db = ordinary_visit();
same(pending($db), 1, 'Uncertain visit queued');
same($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='detection_reviews'"), 0, 'Queue is read-only');
$original = rows($db);
foreach (['false_positive', 'confirmed', 'hidden', 'unsure'] as $status) {
  $saved = verdict($db, $status);
  same($saved, ['status' => 'ok', 'affected' => 2, 'review_status' => $status, 'via' => 'visit'], 'Whole visit acknowledged');
  same(count_reviews($db), 2, 'Both verdicts stored');
  same(pending($db), 0, $status . ' leaves queue');
  same(rows($db), $original, $status . ' preserves detection rows');
  same(verdict($db, 'clear')['affected'], 2, 'Clear removes review metadata only');
  same(verdict($db, 'clear')['affected'], 0, 'Repeated clear reports no changes');
  same(pending($db), 1, 'Clear restores eligibility');
  same(rows($db), $original, 'Clear preserves detection rows');
}
same(save_detection_review($db, ['file_name' => 'a.wav', 'status' => 'confirmed', 'note' => '  Field note  '])['via'], 'single', 'Single detection supported');
same($db->querySingle("SELECT note FROM detection_reviews WHERE file_name='a.wav'"), 'Field note', 'Note retained');
same(pending($db), 1, 'Unreviewed best clip still pending');
verdict($db, 'false_positive');
detection($db, 'new.wav', '12:02:00', 0.80);
same(pending($db), 1, 'Later best detection creates new review work');
same(count_reviews($db), 2, 'Earlier verdict does not mark a future clip');
same(queue_data($db)['queue'][0]['unreviewed_count'], 1, 'New member visible');
same(verdict($db, 'hidden', '12:00:00', '12:02:00')['affected'], 3, 'Expanded visit saved explicitly');
same(pending($db), 0, 'Expanded visit leaves queue');

// The badge previously missed these visits or counted trusted ones incorrectly.
$db = fixture(); history($db);
detection($db, 'yesterday.wav', '12:00:00', 0.75, 1);
detection($db, 'boundary.wav', '12:00:00', 0.75, 7);
detection($db, 'outside.wav', '12:00:00', 0.75, 8);
same(pending($db), 2, 'Existing seven-day boundary retained');
same(queue_data($db, ['days' => 1])['total'], 1, 'Custom API date window retained');
$db = fixture(); detection($db, 'first.wav', '12:00:00', 0.99);
same(pending($db), 1, 'First-ever confident visit counted');
same(queue_data($db)['queue'][0]['reasons'], ['first_lifetime'], 'First-ever reason retained');
$db = fixture(); history($db, 'Testus birdus', 1); detection($db, 'rare.wav', '12:00:00', 0.99);
same(queue_data($db)['queue'][0]['reasons'], ['yard_rare'], 'Yard rarity retained');
foreach (['confirmed' => 0, 'false_positive' => 1] as $status => $expected) {
  $db = fixture(); history($db, 'Testus birdus', 10);
  for ($i = 0; $i < 10; $i++) save_detection_review($db, ['status' => $status, 'file_name' => 'Testus birdus-old-' . $i]);
  detection($db, 'today.wav');
  same(pending($db), $expected, 'Historical ' . $status . ' precision respected');
  if ($expected) {
    check(in_array('low_precision', queue_data($db)['queue'][0]['reasons'], true), 'Low precision reason');
    same(queue_data($db)['suggestions'][0]['rejected_pct'], 100.0, 'Exclusion suggestion retained');
  }
}
$db = fixture(); history($db);
foreach ([0.59994, 0.59996, 0.84994, 0.84996, 0.95] as $i => $confidence) detection($db, 'band-' . $i, sprintf('%02d:00:00', $i), $confidence);
same(pending($db), 2, 'Rounded confidence band boundaries preserved');

// Compare streamed summaries with the existing grouping implementation,
// including interleaved species, exact gap, ties, a split, and midnight.
$db = fixture();
foreach ([['a', '00:00:00', .70, 0, 'Testus birdus'], ['b', '00:02:00', .72, 0, 'Testus otherus'],
  ['c', '00:05:00', .70, 0, 'Testus birdus'], ['d', '00:10:01', .71, 0, 'Testus birdus'],
  ['e', '23:59:00', .72, 1, 'Testus birdus'], ['f', '00:10:01', .71, 0, 'Testus birdus']] as $args) detection($db, ...$args);
$legacy = visits_from_detections(review_rows($db, 'SELECT * FROM detections ORDER BY Date, Time, rowid'), 300);
$actual = queue_data($db)['queue'];
// Yesterday's visit makes today's high-level reason uncertain rather than first.
same(count($actual), count($legacy), 'Same number of grouped visits');
foreach ($legacy as $v) {
  $matches = array_values(array_filter($actual, function ($q) use ($v) { return $q['sci_name'] === $v['sci_name'] && $q['date'] === $v['date'] && $q['first_time'] === $v['first_time']; }));
  same(count($matches), 1, 'Unique visit found');
  foreach (['species', 'sci_name', 'date', 'first_time', 'last_time', 'count', 'best_confidence', 'best_file'] as $key) same($matches[0][$key], $v[$key], 'Grouping field ' . $key);
  same(count($matches[0]['member_clips']), $v['count'], 'All member paths hydrated');
}

// A shrinking queue must always start from offset zero after each decision.
$db = fixture(); history($db);
for ($i = 0; $i < 30; $i++) detection($db, 'batch-' . $i, sprintf('%02d:%02d:00', intdiv($i * 10, 60), $i * 10 % 60));
same(queue_data($db, ['limit' => 25])['count'], 25, 'Initial batch capped at 25');
same(queue_data($db, ['limit' => 25, 'offset' => 25])['count'], 5, 'External API pagination retained');
for ($i = 30; $i > 0; $i--) {
  same(pending($db), $i, 'Remaining batch count');
  $v = queue_data($db, ['limit' => 25])['queue'][0];
  verdict($db, 'false_positive', $v['first_time'], $v['last_time']);
}
same(pending($db), 0, 'All batches finish');
same((int)$db->querySingle('SELECT COUNT(*) FROM detections'), 36, 'No detections deleted by batch review');

// A failed or ignored second write must roll back the first as well.
foreach (['ABORT, \'injected failure\'', 'IGNORE'] as $raise) {
  foreach (['false_positive', 'confirmed', 'hidden', 'unsure'] as $status) {
    $db = ordinary_visit(); schema($db); $original = rows($db);
    $db->exec("CREATE TRIGGER fail_review BEFORE INSERT ON detection_reviews WHEN NEW.file_name='b.wav' BEGIN SELECT RAISE($raise); END");
    fails(function () use ($db, $status) { verdict($db, $status); });
    same(count_reviews($db), 0, 'No partial ' . $status . ' after ' . $raise);
    same(pending($db), 1, 'Failed save remains pending');
    same(rows($db), $original, 'Failed save preserves detections');
  }
  $db = ordinary_visit(); verdict($db, 'confirmed'); $original = rows($db, 'detection_reviews');
  $db->exec("CREATE TRIGGER fail_clear BEFORE DELETE ON detection_reviews WHEN OLD.file_name='b.wav' BEGIN SELECT RAISE($raise); END");
  fails(function () use ($db) { verdict($db, 'clear'); });
  same(rows($db, 'detection_reviews'), $original, 'Clear failure rolls back earlier clears');
}
$db = ordinary_visit(); verdict($db, 'confirmed'); $original = rows($db, 'detection_reviews');
$db->exec("CREATE TRIGGER fail_update BEFORE UPDATE ON detection_reviews WHEN NEW.file_name='b.wav' BEGIN SELECT RAISE(ABORT, 'injected update failure'); END");
fails(function () use ($db) { verdict($db, 'false_positive'); });
same(rows($db, 'detection_reviews'), $original, 'Existing verdicts restored on failure');

// Preparation/DDL failures and failed COMMIT are not reported as successful.
$db = ordinary_visit(); $db->exec('CREATE TABLE notes (wrong TEXT)');
fails(function () use ($db) { verdict($db, 'confirmed'); });
same($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='detection_reviews'"), 0, 'DDL rolled back');
$db = fixture(); $db->exec('DROP TABLE detections');
fails(function () use ($db) { queue_data($db); });
// Check the non-throwing SQLite API mode too (warnings are expected here).
set_error_handler(function () { return true; });
$db->enableExceptions(false);
fails(function () use ($db) { queue_data($db); }, RuntimeException::class);
restore_error_handler();
class FailCommitSQLite extends SQLite3 {
  public function exec(string $query): bool {
    if ($query === 'COMMIT') return false;
    return parent::exec($query);
  }
}
$db = fixture(new FailCommitSQLite(':memory:')); schema($db); detection($db, 'a.wav');
fails(function () use ($db) { verdict($db, 'confirmed'); });
same(count_reviews($db), 0, 'COMMIT failure rolls back verdicts');

// A busy writer leaves all data alone. Only our own temp file is removed.
$temp = tempnam(sys_get_temp_dir(), 'birdnet-review-test-');
$writer = null; $locked = null;
try {
  $writer = fixture(new SQLite3($temp)); schema($writer); detection($writer, 'a.wav');
  $locked = new SQLite3($temp); $locked->enableExceptions(true); $locked->busyTimeout(10);
  $writer->exec('BEGIN IMMEDIATE');
  fails(function () use ($locked) { verdict($locked, 'confirmed'); });
  $writer->exec('ROLLBACK');
  same(count_reviews($locked), 0, 'Locked database unchanged');
  same(verdict($locked, 'confirmed')['affected'], 1, 'Retry succeeds after lock released');
} finally {
  if ($locked) $locked->close();
  if ($writer) $writer->close();
  unlink($temp);
}

// Invalid requests never begin writing, and unknown targets stay a 404 case.
$db = ordinary_visit();
foreach ([['status' => 'delete'], ['status' => 'confirmed'], ['status' => 'confirmed', 'file_name' => 'a.wav', 'note' => str_repeat('x', 2001)],
  ['status' => 'confirmed', 'visit' => ['sci_name' => 'Testus birdus', 'date' => '2026-02-30', 'from_time' => '12:00:00', 'to_time' => '12:01:00']],
  ['status' => 'confirmed', 'visit' => ['sci_name' => 'Testus birdus', 'date' => day($db), 'from_time' => '25:00:00', 'to_time' => '12:01:00']]] as $body) {
  fails(function () use ($db, $body) { save_detection_review($db, $body); }, InvalidArgumentException::class);
}
fails(function () use ($db) { save_detection_review($db, ['status' => 'confirmed', 'file_name' => 'missing.wav']); }, ReviewTargetNotFound::class);
same($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE name='detection_reviews'"), 0, 'Invalid requests do not create tables');

echo "PASS: $checks review data checks\n";
