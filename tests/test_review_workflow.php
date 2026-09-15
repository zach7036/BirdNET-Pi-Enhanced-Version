<?php
// Extends the core review regression fixtures; all databases are disposable.
require __DIR__ . '/test_review_data.php';
$workflow_start = $checks;
function undo_saved($db, $saved) { return save_detection_review($db, ['action' => 'undo', 'undo_token' => $saved['undo_token']]); }
function workflow_visit($db, $action) {
  return save_detection_review($db, ['action' => $action, 'visit' => ['sci_name' => 'Testus birdus',
    'date' => day($db), 'from_time' => '12:00:00', 'to_time' => '12:01:00']]);
}

// Explain the precise cutoff and prioritize important records over routine
// ones, with old visits first within the same priority.
$db = fixture(); history($db);
detection($db, 'routine-new', '12:00:00', .846);
detection($db, 'routine-old', '12:00:00', .75, 2);
detection($db, 'important', '14:00:00', .97, 0, 'Testus novus');
$q = queue_data($db);
same($q['total'], 3, 'Ready combines important and routine');
same($q['counts']['important'], 1, 'One important visit');
same($q['counts']['routine'], 2, 'Two routine visits');
same(array_column($q['queue'], 'best_file'), ['important', 'routine-old', 'routine-new'], 'Priority then oldest first');
same(queue_data($db, ['group' => 'important'])['total'], 1, 'Important filter');
same(queue_data($db, ['group' => 'routine'])['total'], 2, 'Routine filter');
check(strpos($q['queue'][2]['reason_details'][0]['text'], '84.6%') !== false, 'Exact score in explanation');
check(strpos($q['queue'][2]['reason_details'][0]['text'], '85% review cutoff') !== false, 'Cutoff explained');
same(queue_data($db, ['limit' => 0])['counts'], $q['counts'], 'Home and queue groups agree');

// Live and future-dated visits are not ready; boundary respects station gap.
$db = ordinary_visit();
$last = strtotime(day($db) . ' 12:01:00');
same(queue_data($db, ['now' => $last + 300])['counts']['active'], 1, 'At gap boundary still active');
same(queue_data($db, ['now' => $last + 300])['total'], 0, 'No active visit in ready');
same(queue_data($db, ['now' => $last + 301])['total'], 1, 'Ready after completed gap');
same(queue_data($db, ['now' => $last + 301, 'gap_seconds' => 600])['total'], 0, 'Custom gap honored');
same(queue_data($db, ['now' => $last - 60, 'group' => 'active'])['total'], 1, 'Clock ahead in recordings is not prematurely complete');

// First-ever prompts stop after confirmation, without marking other visits.
$db = fixture(); detection($db, 'first', '10:00:00', .99); detection($db, 'second', '12:00:00', .99);
same(pending($db), 2, 'Initially both first-day occurrences need confirmation');
$saved = save_detection_review($db, ['file_name' => 'first', 'status' => 'confirmed']);
same(pending($db), 0, 'Confirmed occurrence suppresses repeat first-ever prompt');
same(count_reviews($db), 1, 'Second occurrence was not auto-confirmed');
undo_saved($db, $saved);
same(pending($db), 2, 'Undo restores first-ever routing');

// Twelve clips from one visit give one vote, not twelve. Incomplete/mixed
// visits (including unreviewed bridges) do not establish station trust.
$db = fixture();
for ($i = 0; $i < 12; $i++) detection($db, 'chatty-' . $i, sprintf('10:00:%02d', $i), .99, 30);
$target = ['sci_name' => 'Testus birdus', 'date' => day($db, 30), 'from_time' => '10:00:00', 'to_time' => '10:00:11'];
save_detection_review($db, ['status' => 'confirmed', 'visit' => $target]);
[$history] = review_visit_history($db, 300, time());
same($history['Testus birdus']['confirmed'], 1, 'One long visit is one trust vote');
detection($db, 'current');
same(pending($db), 1, 'One confirmed burst cannot auto-trust the species');
save_detection_review($db, ['status' => 'false_positive', 'file_name' => 'chatty-11']);
[$history] = review_visit_history($db, 300, time());
same($history['Testus birdus']['confirmed'] + $history['Testus birdus']['rejected'], 0, 'Mixed verdict visit excluded from trust');
$db = fixture();
detection($db, 'bridge-a', '10:00:00', .99, 30); detection($db, 'bridge-b', '10:05:00', .99, 30); detection($db, 'bridge-c', '10:10:00', .99, 30);
foreach (['bridge-a', 'bridge-c'] as $file) save_detection_review($db, ['status' => 'confirmed', 'file_name' => $file]);
[$history] = review_visit_history($db, 300, time());
same($history['Testus birdus']['confirmed'], 0, 'Unreviewed bridge prevents counting two incomplete votes');

// Multiple independent visits still establish trust, and fully rejected
// visits drive suggestions regardless of how many clips each visit contains.
$db = fixture(); history($db, 'Testus birdus', 10); detection($db, 'current');
for ($i = 0; $i < 9; $i++) save_detection_review($db, ['status' => 'confirmed', 'file_name' => 'Testus birdus-old-' . $i]);
same(pending($db), 1, 'Nine independent confirmations are below trust minimum');
save_detection_review($db, ['status' => 'confirmed', 'file_name' => 'Testus birdus-old-9']);
same(pending($db), 0, 'Ten independent confirmations establish uncertainty trust');

// Missing best recording: choose a surviving unreviewed clip. No recording:
// separate unavailable view, no verdict and no inflation of the ready count.
$db = ordinary_visit(); $original = rows($db);
$q = queue_data($db, ['clip_available' => function ($path) { return substr($path, -5) === 'a.wav'; }]);
same($q['queue'][0]['playback_file'], 'a.wav', 'Surviving clip selected');
same($q['queue'][0]['playback_confidence'], .70, 'Playback score distinguished from best score');
same($q['queue'][0]['best_confidence'], .75, 'Best recorded score retained');
same($q['queue'][0]['audio_fallback'], true, 'Fallback explained');
same($q['queue'][0]['reassign_available'], false, 'Cannot reassign entire visit with missing member files');
$missing = ['clip_available' => function () { return false; }];
same(queue_data($db, $missing)['counts']['unavailable'], 1, 'Missing audio counted separately');
same(queue_data($db, $missing)['total'], 0, 'No missing audio in ready count');
same(queue_data($db, $missing + ['group' => 'unavailable'])['queue'][0]['clip_path'], null, 'No invented audio path');
same(rows($db), $original, 'Audio checks preserve detections');
same(review_table_exists($db, 'detection_reviews'), false, 'Audio checks never write a verdict');

// Do not walk the filesystem for ordinary confident detections that would
// never enter Review, especially if a recording drive is unavailable.
history($db, 'Testus confidentus');
$today = day($db);
$db->exec("WITH RECURSIVE n(x) AS (SELECT 1 UNION ALL SELECT x+1 FROM n WHERE x<1000)
  INSERT INTO detections SELECT '$today','14:00:00','Testus confidentus','Confident Bird',.99,'confident-'||x FROM n");
$file_checks = 0;
queue_data($db, ['limit' => 0, 'clip_available' => function () use (&$file_checks) { $file_checks++; return false; }]);
same($file_checks, 2, 'Only eligible visit members incur missing-file checks');

// Skip/resume/expiry and chained undo never alter statistics or recordings.
$db = ordinary_visit(); $original = rows($db);
$skip = workflow_visit($db, 'skip');
same(count_reviews($db), 0, 'Skip creates no verdict');
same(queue_data($db)['counts']['skipped'], 1, 'Skipped view populated');
same(pending($db), 0, 'Skipped visit leaves ready');
same(queue_data($db, ['now' => $skip['deferred_until'] + 1])['total'], 1, 'Expired deferral returns automatically');
same((int)$db->querySingle('SELECT COUNT(*) FROM review_deferrals'), 2, 'GET does not mutate expired deferrals');
$resume = workflow_visit($db, 'resume');
same(pending($db), 1, 'Resume brings visit back early');
undo_saved($db, $resume);
same(queue_data($db)['counts']['skipped'], 1, 'Undo resume restores exact deferral');
undo_saved($db, $skip);
same(pending($db), 1, 'Undo skip restores ready');
same(rows($db), $original, 'Skip/resume/undo preserve detections');
same(count_reviews($db), 0, 'Skip/resume/undo do not change analytics verdicts');

// Restore mixed preexisting notes/status/timestamps, not just clear a verdict.
$db = ordinary_visit();
$first = save_detection_review($db, ['file_name' => 'a.wav', 'status' => 'confirmed', 'note' => 'Original field note']);
$before = review_metadata_state($db, 'a.wav');
$second = verdict($db, 'false_positive');
undo_saved($db, $second);
same(review_metadata_state($db, 'a.wav'), $before, 'Undo restores note, status, timestamp and version');
same(review_metadata_state($db, 'b.wav')['review'], null, 'Undo removes only new verdict for other member');
undo_saved($db, $first);
same(count_reviews($db), 0, 'Earlier undo still works after restoring its version');
same(undo_saved($db, $first)['already_undone'], true, 'Undo retry is idempotent');

// A newly arrived clip is never touched by undo of an earlier displayed window.
$db = ordinary_visit(); $saved = verdict($db, 'hidden');
detection($db, 'future.wav', '12:02:00', .8);
save_detection_review($db, ['file_name' => 'future.wav', 'status' => 'confirmed']);
undo_saved($db, $saved);
same(count_reviews($db), 1, 'Undo leaves later independent decision intact');
same($db->querySingle("SELECT file_name FROM detection_reviews"), 'future.wav', 'Only later clip remains reviewed');

// Optimistic concurrency: even an identical newer decision prevents old undo.
$db = ordinary_visit(); $one = verdict($db, 'confirmed'); $two = verdict($db, 'confirmed');
$before = rows($db, 'detection_reviews');
fails(function () use ($db, $one) { undo_saved($db, $one); }, ReviewConflict::class);
same(rows($db, 'detection_reviews'), $before, 'Conflicting undo leaves newer decisions intact');
undo_saved($db, $two); undo_saved($db, $one);
same(count_reviews($db), 0, 'LIFO undo remains possible');
$db = ordinary_visit(); $saved = verdict($db, 'confirmed');
$db->exec("UPDATE detections SET File_Name='renamed.wav' WHERE File_Name='b.wav'");
fails(function () use ($db, $saved) { undo_saved($db, $saved); }, ReviewConflict::class);
same(count_reviews($db), 2, 'Rename conflict does not partially undo first member');

// Failure injection for new operations: whole-action atomicity includes the
// undo journal, deferrals and per-file versions, not just verdict rows.
$db = ordinary_visit(); review_workflow_schema($db);
$db->exec("CREATE TRIGGER fail_skip BEFORE INSERT ON review_deferrals WHEN NEW.file_name='b.wav' BEGIN SELECT RAISE(IGNORE); END");
fails(function () use ($db) { workflow_visit($db, 'skip'); });
same((int)$db->querySingle('SELECT COUNT(*) FROM review_deferrals'), 0, 'Partial skip rolled back');
same((int)$db->querySingle('SELECT COUNT(*) FROM review_state_versions'), 0, 'Partial skip versions rolled back');
$db = ordinary_visit(); review_workflow_schema($db);
$db->exec("CREATE TRIGGER fail_journal BEFORE INSERT ON review_actions BEGIN SELECT RAISE(ABORT, 'journal failure'); END");
fails(function () use ($db) { verdict($db, 'confirmed'); });
same(count_reviews($db), 0, 'Failed journal rolls back all verdicts');
$db = ordinary_visit(); $saved = verdict($db, 'hidden'); $before = rows($db, 'detection_reviews');
$db->exec("CREATE TRIGGER fail_undo BEFORE DELETE ON detection_reviews WHEN OLD.file_name='b.wav' BEGIN SELECT RAISE(ABORT, 'undo failure'); END");
fails(function () use ($db, $saved) { undo_saved($db, $saved); });
same(rows($db, 'detection_reviews'), $before, 'Failed undo rolls back every restored member');
same((int)$db->querySingle('SELECT undone FROM review_actions'), 0, 'Failed undo remains retryable');
$db->exec('DROP TRIGGER fail_undo');
undo_saved($db, $saved);
same(count_reviews($db), 0, 'Undo retry succeeds after failure removed');

echo 'PASS: ' . ($checks - $workflow_start) . " additional workflow checks\n";
