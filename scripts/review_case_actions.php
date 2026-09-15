<?php
function review_case_state_row($db, $key) {
  return review_rows($db, 'SELECT * FROM review_cases WHERE case_key=:key', [':key' => $key])[0] ?? null;
}

function review_case_evidence_row($db, $file) {
  return review_rows($db, 'SELECT * FROM review_evidence WHERE file_name=:file', [':file' => $file])[0] ?? null;
}

function review_case_restore_row($db, $table, $column, $key, $row) {
  if (!in_array($table, ['review_cases','review_evidence'], true) || !in_array($column, ['case_key','file_name'], true)) throw new LogicException('Invalid case table.');
  review_query($db, "DELETE FROM $table WHERE $column=:key", [':key' => $key])->finalize();
  if (review_rows($db, "SELECT $column FROM $table WHERE $column=:key", [':key' => $key])) throw new RuntimeException('Could not clear case metadata.');
  if ($row === null) return;
  $params = []; foreach ($row as $k => $v) $params[':' . $k] = $v;
  review_query($db, "INSERT INTO $table (" . implode(',', array_keys($row)) . ') VALUES (' . implode(',', array_keys($params)) . ')', $params)->finalize();
  if ($db->changes() !== 1) throw new RuntimeException('Could not restore case metadata.');
}

function review_case_undo_in_transaction($db, $token) {
  if (!is_string($token) || !preg_match('/^[a-f0-9]{64}$/D', $token)) throw new InvalidArgumentException('Invalid Undo token.');
  $action = review_rows($db, 'SELECT * FROM review_case_actions WHERE token=:token', [':token' => $token])[0] ?? null;
  if (!$action) throw new ReviewTargetNotFound('That decision is unavailable.');
  if ((int)$action['undone']) return ['status' => 'ok', 'affected' => 0, 'already_undone' => true, 'message' => 'This decision was already undone.'];
  $snap = json_decode($action['snapshot_json'], true, 512, JSON_THROW_ON_ERROR);
  if (!$snap || !isset($snap['case_key'])) throw new InvalidArgumentException('This action cannot be undone.');
  if (review_case_state_row($db, $snap['case_key']) !== $snap['case_after']) throw new ReviewConflict('This question changed after that decision. Refresh before trying Undo again.');
  foreach ($snap['files'] as $f) {
    $target = review_rows($db, 'SELECT File_Name,Sci_Name,Com_Name,Date,Time FROM detections WHERE File_Name=:file ORDER BY rowid LIMIT 1', [':file' => $f['target']['File_Name']])[0] ?? null;
    if ($target !== $f['target'] || review_metadata_state($db, $target['File_Name']) !== $f['after'] || review_case_evidence_row($db, $target['File_Name']) !== $f['evidence_after']) throw new ReviewConflict('A recording was changed or reassigned after this decision. Undo was not applied.');
  }
  foreach ($snap['files'] as $f) {
    $file = $f['target']['File_Name'];
    foreach (['detection_reviews' => 'review', 'review_deferrals' => 'deferral', 'review_state_versions' => 'version'] as $table => $part) review_restore_metadata($db, $table, $file, $f['before'][$part]);
    review_case_restore_row($db, 'review_evidence', 'file_name', $file, $f['evidence_before']);
    if (review_metadata_state($db, $file) !== $f['before'] || review_case_evidence_row($db, $file) !== $f['evidence_before']) throw new RuntimeException('Could not restore recording review state.');
  }
  review_case_restore_row($db, 'review_cases', 'case_key', $snap['case_key'], $snap['case_before']);
  review_query($db, 'UPDATE review_case_actions SET undone=1 WHERE token=:token', [':token' => $token])->finalize();
  if ($db->changes() !== 1) throw new RuntimeException('Could not record Undo.');
  return ['status' => 'ok', 'affected' => count($snap['files']), 'already_undone' => false, 'message' => 'Undone. Previous recording decisions and question state restored.'];
}

function save_review_case($db, $body, $options = []) {
  $request = $body['request_id'] ?? null;
  if (!is_string($request) || !preg_match('/^[a-f0-9]{32,64}$/D', $request)) throw new InvalidArgumentException('A request ID is required.');
  $action = $body['action'] ?? '';
  if (!in_array($action, ['confirm','reject','uncertain','later','resume','hide','undo'], true)) throw new InvalidArgumentException('Invalid case action.');
  $hash = hash('sha256', json_encode($body, JSON_THROW_ON_ERROR));
  return review_transaction($db, function () use ($db, $body, $options, $request, $action, $hash) {
    review_case_schema($db);
    $prior = review_rows($db, 'SELECT request_hash,response_json FROM review_case_actions WHERE request_id=:request', [':request' => $request])[0] ?? null;
    if ($prior) {
      if ($prior['request_hash'] !== $hash) throw new ReviewConflict('That request ID belongs to a different decision.');
      return json_decode($prior['response_json'], true, 512, JSON_THROW_ON_ERROR);
    }
    $now = $options['now'] ?? time(); $token = bin2hex(random_bytes(32)); $snapshot = [];
    if ($action === 'undo') {
      $response = review_case_undo_in_transaction($db, $body['undo_token'] ?? null);
    } else {
      $spec = $body['case'] ?? null;
      if (!is_array($spec) || !isset($spec['key'], $spec['version'], $spec['date'], $spec['end_date'], $spec['sci_name'])) throw new InvalidArgumentException('A question and its version are required.');
      if (!is_string($spec['key']) || !preg_match('/^[a-f0-9]{64}$/D', $spec['key']) || !is_string($spec['sci_name']) || !is_string($spec['version'])) throw new InvalidArgumentException('Invalid question.');
      $start = review_case_date($spec['date']); $end = review_case_date($spec['end_date']);
      $case_options = ['key' => $spec['key'], 'start' => $start, 'end' => $end, 'details' => true, 'limit' => 100];
      if (in_array($action, ['confirm','reject','hide'], true)) {
        $selected = $body['files'] ?? [];
        if (!is_array($selected) || !$selected || count($selected) > 100) throw new InvalidArgumentException('Select between 1 and 100 recordings.');
        $case_options['evidence_files'] = [];
        foreach ($selected as $selection) {
          if (!is_array($selection) || !isset($selection['file_name'], $selection['file_revision']) || !is_string($selection['file_name']) || !is_string($selection['file_revision'])) throw new InvalidArgumentException('Invalid recording selection.');
          $case_options['evidence_files'][] = $selection['file_name'];
        }
      }
      $data = review_cases_data($db, $case_options + $options);
      $case = $data['cases'][0] ?? null;
      if (!$case) throw new ReviewConflict('This question changed. Refresh to see its current evidence.');
      if ($case['sci_name'] !== $spec['sci_name']) throw new ReviewConflict('The question identity changed. Refresh the evidence.');
      if ($case['version'] !== $spec['version']) throw new ReviewConflict('This question was changed in another tab. Refresh before deciding.');
      $before = review_case_state_row($db, $case['key']);
      $files = []; $message = ''; $state = 'open'; $until = 0;
      if (in_array($action, ['confirm','reject','hide'], true)) {
        $selected = $body['files'] ?? [];
        if (!is_array($selected) || !$selected || count($selected) > 100) throw new InvalidArgumentException('Select between 1 and 100 recordings.');
        if (count($selected) > 1 && ($body['bulk_confirmed'] ?? false) !== true) throw new InvalidArgumentException('Bulk decisions require an explicit preview confirmation.');
        $candidates = array_column($case['evidence'], null, 'file_name'); $seen = [];
        foreach ($selected as $selection) {
          if (!is_array($selection) || !isset($selection['file_name'], $selection['file_revision']) || !is_string($selection['file_name'])) throw new InvalidArgumentException('Invalid recording selection.');
          $file = $selection['file_name'];
          if (isset($seen[$file])) throw new InvalidArgumentException('A recording was selected twice.');
          $seen[$file] = true;
          $candidate = $candidates[$file] ?? null;
          if (!$candidate || $candidate['file_revision'] !== $selection['file_revision']) throw new ReviewConflict('A selected recording is no longer available or has changed. Refresh the evidence.');
          $target = $candidate['target'];
          $metadata = review_metadata_state($db, $file); $evidence_before = review_case_evidence_row($db, $file);
          $status = ['confirm' => 'confirmed', 'reject' => 'false_positive', 'hide' => 'hidden'][$action];
          $is_sample = !empty($case['sample']) && ($body['source'] ?? '') === 'sample' && count($selected) === 1;
          $via = count($selected) > 1 ? 'guided_bulk' : ($is_sample ? 'guided_sample' : 'guided_clip');
          review_query($db, 'INSERT INTO detection_reviews (file_name,sci_name,com_name,date,time,status,reviewed_via) VALUES (:file,:sci,:com,:date,:time,:status,:via)',
            [':file' => $file, ':sci' => $target['Sci_Name'], ':com' => $target['Com_Name'], ':date' => $target['Date'], ':time' => $target['Time'], ':status' => $status, ':via' => $via])->finalize();
          if ($db->changes() !== 1) throw new RuntimeException('Could not save the selected recording.');
          review_delete_metadata($db, 'review_deferrals', $file);
          review_query($db, 'INSERT INTO review_state_versions (file_name,token) VALUES (:file,:token) ON CONFLICT(file_name) DO UPDATE SET token=excluded.token', [':file' => $file, ':token' => $token])->finalize();
          if ($db->changes() !== 1) throw new RuntimeException('Could not version the recording decision.');
          $evidence = ['file_name' => $file, 'target_json' => json_encode($target), 'review_token' => $token, 'case_key' => $case['key'], 'source' => $is_sample ? 'sample' : (count($selected) > 1 ? 'bulk' : 'targeted'), 'visit_key' => $candidate['visit_key'], 'score' => $candidate['score'], 'checked_at' => $now];
          review_case_restore_row($db, 'review_evidence', 'file_name', $file, $evidence);
          $files[] = ['target' => $target, 'before' => $metadata, 'after' => review_metadata_state($db, $file), 'evidence_before' => $evidence_before, 'evidence_after' => review_case_evidence_row($db, $file)];
        }
        $state = $action === 'confirm' || $case['kind'] === 'check' ? 'resolved' : 'open';
        $message = $action === 'confirm' ? 'Selected recording confirmed. Presence established for its date; other recordings remain unverified.'
          : ($action === 'reject' ? 'Selected identification rejected and excluded from review-aware statistics. Other recordings are unchanged; this does not establish absence.' : 'Selected recording hidden from review-aware statistics. Audio retained.');
        if (count($files) > 1) $message = count($files) . ' explicitly selected recordings updated. Unselected recordings are unchanged.';
      } elseif ($action === 'uncertain') {
        $state = 'unresolved'; $message = 'Saved as unresolved. Statistics unchanged; this will not return just because a day passes.';
      } elseif ($action === 'later') {
        $state = 'later'; $until = $now + 86400; $message = 'Postponed for 24 hours. No identification or statistic changed.';
      } else {
        $state = 'open'; $message = 'Question reopened. No recording verdict changed.';
      }
      $saved_case = $case; unset($saved_case['evidence']);
      $after = ['case_key' => $case['key'], 'snapshot_json' => json_encode($saved_case, JSON_THROW_ON_ERROR), 'state' => $state,
        'until_at' => $until, 'baseline_json' => json_encode($case['baseline'], JSON_THROW_ON_ERROR), 'token' => $token, 'updated_at' => $now];
      review_case_restore_row($db, 'review_cases', 'case_key', $case['key'], $after);
      $snapshot = ['case_key' => $case['key'], 'case_before' => $before, 'case_after' => review_case_state_row($db, $case['key']), 'files' => $files];
      $response = ['status' => 'ok', 'affected' => count($files), 'message' => $message, 'undo_token' => $token, 'case_state' => $state];
    }
    review_query($db, 'INSERT INTO review_case_actions (request_id,request_hash,token,snapshot_json,response_json,created_at) VALUES (:id,:hash,:token,:snapshot,:response,:created)',
      [':id' => $request, ':hash' => $hash, ':token' => $token, ':snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), ':response' => json_encode($response, JSON_THROW_ON_ERROR), ':created' => $now])->finalize();
    if ($db->changes() !== 1) throw new RuntimeException('Could not journal the decision.');
    return $response;
  });
}

// A decision about the OLD identification must never become a confirmation or
// rejection of the NEW identification. Archive metadata; leave audio/rows alone.
function review_rename_state($db, $file) {
  $parts = ['review' => null, 'deferral' => null, 'version' => null];
  foreach (['detection_reviews' => ['review','file_name,sci_name,com_name,date,time,status,reviewed_via,note,created_at'], 'review_deferrals' => ['deferral','file_name,deferred_until'], 'review_state_versions' => ['version','file_name,token']] as $table => $part) {
    if (review_table_exists($db,$table)) $parts[$part[0]] = review_rows($db,'SELECT '.$part[1].' FROM '.$table.' WHERE file_name=:file',[':file'=>$file])[0] ?? null;
  }
  return ['metadata' => $parts, 'evidence' => review_table_exists($db,'review_evidence') ? review_case_evidence_row($db,$file) : null];
}

function review_archive_renamed_metadata($db, $old_file, $new_file, $expected = null) {
  return review_transaction($db, function () use ($db, $old_file, $new_file, $expected) {
    review_case_schema($db);
    $snapshot = ['metadata' => review_metadata_state($db, $old_file), 'evidence' => review_case_evidence_row($db, $old_file)];
    if ($expected !== null && $snapshot !== $expected) throw new ReviewConflict('Review metadata changed during reassignment; newer decisions were not archived or cleared.');
    review_query($db, 'INSERT INTO review_rename_archive (old_file,new_file,snapshot_json,created_at) VALUES (:old,:new,:snapshot,:now)',
      [':old' => $old_file, ':new' => $new_file, ':snapshot' => json_encode($snapshot, JSON_THROW_ON_ERROR), ':now' => time()])->finalize();
    if ($db->changes() !== 1) throw new RuntimeException('Could not archive the prior identification review.');
    foreach (['detection_reviews','review_deferrals','review_state_versions'] as $table) review_delete_metadata($db, $table, $old_file);
    review_case_restore_row($db, 'review_evidence', 'file_name', $old_file, null);
  });
}
