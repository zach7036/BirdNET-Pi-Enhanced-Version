<?php
// Review-only metadata. These additive tables are created lazily inside the
// first write transaction, not by GET requests or a destructive migration.
class ReviewTargetNotFound extends RuntimeException {}
class ReviewConflict extends RuntimeException {}

function review_workflow_schema($db) {
  require_once __DIR__ . '/spine_schema.php';
  foreach (spine_schema_statements_standalone() as $sql) review_exec($db, $sql);
  review_exec($db, 'CREATE TABLE IF NOT EXISTS review_deferrals (file_name TEXT PRIMARY KEY, deferred_until INTEGER NOT NULL)');
  review_exec($db, 'CREATE TABLE IF NOT EXISTS review_state_versions (file_name TEXT PRIMARY KEY, token TEXT NOT NULL)');
  review_exec($db, 'CREATE TABLE IF NOT EXISTS review_actions (token TEXT PRIMARY KEY, snapshot_json TEXT NOT NULL, created_at INTEGER NOT NULL, undone INTEGER NOT NULL DEFAULT 0)');
}

function review_transaction($db, $operation) {
  $begun = false;
  try {
    review_exec($db, 'BEGIN IMMEDIATE');
    $begun = true;
    $answer = $operation();
    review_exec($db, 'COMMIT');
    return $answer;
  } catch (Throwable $e) {
    if ($begun) {
      try { review_exec($db, 'ROLLBACK'); }
      catch (Throwable $rollback_error) { error_log('Review rollback failed: ' . $rollback_error->getMessage()); }
    }
    throw $e;
  }
}

function review_target_query($body) {
  if (!empty($body['file_name']) && is_string($body['file_name'])) {
    return ['single', 'File_Name=:file', [':file' => $body['file_name']]];
  }
  if (!isset($body['visit']) || !is_array($body['visit'])) throw new InvalidArgumentException('Provide file_name or a visit window.');
  $v = $body['visit'];
  foreach (['sci_name', 'date', 'from_time', 'to_time'] as $key) {
    if (!isset($v[$key]) || !is_string($v[$key]) || $v[$key] === '') throw new InvalidArgumentException('visit.' . $key . ' is required.');
  }
  $date = DateTime::createFromFormat('!Y-m-d', $v['date']);
  if (!$date || $date->format('Y-m-d') !== $v['date']) throw new InvalidArgumentException('Invalid visit date.');
  foreach (['from_time', 'to_time'] as $key) {
    if (!preg_match('/^(?:[01]\d|2[0-3]):[0-5]\d:[0-5]\d(?:\.\d+)?$/D', $v[$key])) throw new InvalidArgumentException('Invalid visit time.');
  }
  if ($v['from_time'] > $v['to_time']) throw new InvalidArgumentException('Visit start must not follow its end.');
  return ['visit', 'Sci_Name=:sci AND Date=:date AND Time>=:from AND Time<=:to',
    [':sci' => $v['sci_name'], ':date' => $v['date'], ':from' => $v['from_time'], ':to' => $v['to_time']]];
}

function review_metadata_state($db, $file) {
  $p = [':file' => $file];
  // The surrogate id is not business data and can be reused by SQLite after
  // a clear; never restore it over another row or use it as an undo version.
  return [
    'review' => review_rows($db, 'SELECT file_name,sci_name,com_name,date,time,status,reviewed_via,note,created_at FROM detection_reviews WHERE file_name=:file', $p)[0] ?? null,
    'deferral' => review_rows($db, 'SELECT file_name,deferred_until FROM review_deferrals WHERE file_name=:file', $p)[0] ?? null,
    'version' => review_rows($db, 'SELECT file_name,token FROM review_state_versions WHERE file_name=:file', $p)[0] ?? null
  ];
}

function review_delete_metadata($db, $table, $file) {
  if (!in_array($table, ['detection_reviews', 'review_deferrals', 'review_state_versions'], true)) throw new LogicException('Invalid review metadata table.');
  review_query($db, "DELETE FROM $table WHERE file_name=:file", [':file' => $file])->finalize();
  $changes = $db->changes();
  if (review_rows($db, "SELECT file_name FROM $table WHERE file_name=:file", [':file' => $file])) throw new RuntimeException('Review metadata removal failed.');
  return $changes;
}

function review_restore_metadata($db, $table, $file, $row) {
  review_delete_metadata($db, $table, $file);
  if ($row === null) return;
  $columns = array_keys($row);
  $params = [];
  foreach ($row as $key => $value) $params[':' . $key] = $value;
  review_query($db, "INSERT INTO $table (" . implode(',', $columns) . ') VALUES (' . implode(',', array_keys($params)) . ')', $params)->finalize();
  if ($db->changes() !== 1) throw new RuntimeException('Review metadata restoration failed.');
}

function undo_detection_review($db, $token) {
  if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Invalid undo token.');
  return review_transaction($db, function () use ($db, $token) {
    if (!review_table_exists($db, 'review_actions')) throw new ReviewTargetNotFound('That review action is unavailable.');
    $action = review_rows($db, 'SELECT snapshot_json,undone FROM review_actions WHERE token=:token', [':token' => $token])[0] ?? null;
    if (!$action) throw new ReviewTargetNotFound('That review action is unavailable.');
    if ((int)$action['undone'] === 1) return ['status' => 'ok', 'action' => 'undo', 'affected' => 0, 'already_undone' => true];
    $snapshots = json_decode($action['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
    // Check every member before changing any of them. Versions also detect a
    // second identical verdict saved in the same second by a different tab.
    foreach ($snapshots as $s) {
      $target = review_rows($db, 'SELECT File_Name,Sci_Name,Com_Name,Date,Time FROM detections WHERE File_Name=:file ORDER BY rowid LIMIT 1', [':file' => $s['target']['File_Name']])[0] ?? null;
      if ($target !== $s['target'] || review_metadata_state($db, $s['target']['File_Name']) !== $s['after']) {
        throw new ReviewConflict('This visit changed after that decision. Undo was not applied; refresh the queue before reviewing it again.');
      }
    }
    foreach ($snapshots as $s) {
      $file = $s['target']['File_Name'];
      review_restore_metadata($db, 'detection_reviews', $file, $s['before']['review']);
      review_restore_metadata($db, 'review_deferrals', $file, $s['before']['deferral']);
      review_restore_metadata($db, 'review_state_versions', $file, $s['before']['version']);
      if (review_metadata_state($db, $file) !== $s['before']) throw new RuntimeException('Undo did not restore the prior review state.');
    }
    review_query($db, 'UPDATE review_actions SET undone=1 WHERE token=:token', [':token' => $token])->finalize();
    if ($db->changes() !== 1) throw new RuntimeException('Could not record undo.');
    return ['status' => 'ok', 'action' => 'undo', 'affected' => count($snapshots), 'already_undone' => false];
  });
}

function save_detection_review($db, $body) {
  $action = $body['action'] ?? $body['status'] ?? null;
  if (isset($body['action'], $body['status'])) throw new InvalidArgumentException('Provide action or status, not both.');
  if ($action === 'undo') return undo_detection_review($db, $body['undo_token'] ?? null);
  if (!in_array($action, ['confirmed', 'false_positive', 'hidden', 'unsure', 'clear', 'skip', 'resume'], true)) throw new InvalidArgumentException('Invalid review action.');
  $note = $body['note'] ?? null;
  if ($note !== null && (!is_string($note) || mb_strlen($note) > 2000)) throw new InvalidArgumentException('Note must be text of at most 2000 characters.');
  $note = $note === null || trim($note) === '' ? null : trim($note);
  [$via, $where, $params] = review_target_query($body);
  return review_transaction($db, function () use ($db, $action, $note, $via, $where, $params) {
    $targets = review_rows($db, "SELECT File_Name,Sci_Name,Com_Name,Date,Time FROM detections WHERE $where ORDER BY Time,rowid", $params);
    if (!$targets) throw new ReviewTargetNotFound('No detections found for this review.');
    review_workflow_schema($db);
    $token = bin2hex(random_bytes(32));
    $snapshots = [];
    $seen = [];
    $affected = 0;
    $until = time() + 86400;
    foreach ($targets as $t) {
      $file = $t['File_Name'];
      if (isset($seen[$file])) continue;
      $seen[$file] = true;
      $before = review_metadata_state($db, $file);
      if ($action === 'skip') {
        if ($before['review'] !== null) continue;
        review_query($db, 'INSERT INTO review_deferrals (file_name,deferred_until) VALUES (:file,:until) ON CONFLICT(file_name) DO UPDATE SET deferred_until=excluded.deferred_until', [':file' => $file, ':until' => $until])->finalize();
        if ($db->changes() !== 1) throw new RuntimeException('Could not defer review.');
        $affected++;
      } elseif ($action === 'resume') {
        $affected += review_delete_metadata($db, 'review_deferrals', $file);
      } else {
        if ($action === 'clear') $affected += review_delete_metadata($db, 'detection_reviews', $file);
        else {
          review_query($db, "INSERT INTO detection_reviews (file_name,sci_name,com_name,date,time,status,reviewed_via,note)
            VALUES (:file,:sci,:com,:date,:time,:status,:via,:note)
            ON CONFLICT(file_name) DO UPDATE SET status=excluded.status, reviewed_via=excluded.reviewed_via,
              note=excluded.note, created_at=datetime('now','localtime')",
            [':file' => $file, ':sci' => $t['Sci_Name'], ':com' => $t['Com_Name'], ':date' => $t['Date'], ':time' => $t['Time'], ':status' => $action, ':via' => $via, ':note' => $note])->finalize();
          if ($db->changes() !== 1) throw new RuntimeException('Review write did not save the verdict.');
          $affected++;
        }
        review_delete_metadata($db, 'review_deferrals', $file);
      }
      $after = review_metadata_state($db, $file);
      // Skip no-op clear/resume records; every verdict still gets a version,
      // including an identical repeat, to protect against concurrent undo.
      if ($before === $after && in_array($action, ['clear', 'resume'], true)) continue;
      review_query($db, 'INSERT INTO review_state_versions (file_name,token) VALUES (:file,:token) ON CONFLICT(file_name) DO UPDATE SET token=excluded.token', [':file' => $file, ':token' => $token])->finalize();
      if ($db->changes() !== 1) throw new RuntimeException('Could not version review.');
      $after['version'] = ['file_name' => $file, 'token' => $token];
      $snapshots[] = ['target' => $t, 'before' => $before, 'after' => $after];
    }
    if (!$snapshots && in_array($action, ['skip', 'resume'], true)) throw new ReviewConflict('There is nothing left to ' . $action . ' in this visit. Refresh the queue.');
    if ($snapshots) {
      review_query($db, 'INSERT INTO review_actions (token,snapshot_json,created_at) VALUES (:token,:snapshot,:created)',
        [':token' => $token, ':snapshot' => json_encode($snapshots, JSON_THROW_ON_ERROR), ':created' => time()])->finalize();
      if ($db->changes() !== 1) throw new RuntimeException('Could not record review undo history.');
    }
    return ['status' => 'ok', 'affected' => $affected, 'review_status' => $action, 'via' => $via,
      'undo_token' => $snapshots ? $token : null, 'deferred_until' => $action === 'skip' ? $until : null];
  });
}
