<?php
// Shared review selection and transactional verdict writes. No recording-file
// operations or detection mutations belong here. Loaded by common.php.

function review_query($db, $sql, $params = []) {
  $stmt = $db->prepare($sql);
  if ($stmt === false) throw new RuntimeException('Could not prepare review query.');
  foreach ($params as $name => $value) {
    if (!$stmt->bindValue($name, $value, $value === null ? SQLITE3_NULL : SQLITE3_TEXT)) {
      throw new RuntimeException('Could not bind review query.');
    }
  }
  $result = $stmt->execute();
  if ($result === false) throw new RuntimeException('Could not execute review query.');
  return $result;
}

function review_rows($db, $sql, $params = []) {
  $result = review_query($db, $sql, $params);
  $rows = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
  if (!in_array($db->lastErrorCode(), [0, 100, 101], true)) {
    throw new RuntimeException('Could not read review query.');
  }
  $result->finalize();
  return $rows;
}

function review_exec($db, $sql) {
  if (!$db->exec($sql)) throw new RuntimeException('Could not complete review transaction.');
}

// limit=0 is the count-only path used by Now. Eligibility and the date window
// are identical to the queue, but clip paths are built only for returned cards.
function review_queue_data($db, $options = []) {
  $days = request_int($options, 'days', 7, 1, 30);
  $limit = request_int($options, 'limit', 50, 0, 200);
  $offset = request_int($options, 'offset', 0, 0, 100000);
  $band_min = isset($options['band_min']) && is_numeric($options['band_min']) ? max(0, min(1, (float)$options['band_min'])) : 0.60;
  $band_max = isset($options['band_max']) && is_numeric($options['band_max']) ? max(0, min(1, (float)$options['band_max'])) : 0.85;
  $gap = isset($options['gap_seconds']) ? (int)$options['gap_seconds'] : get_visit_gap_seconds();
  // Do not use the per-request table-name cache: the writer can create this
  // table on its first use, and tests may use multiple independent databases.
  $has_reviews = !empty(review_rows($db, "SELECT name FROM sqlite_master WHERE type='table' AND name='detection_reviews'"));

  $first_seen_map = [];
  $lifetime_map = [];
  foreach (review_rows($db, 'SELECT Sci_Name, MIN(Date) AS first_seen, COUNT(*) AS lifetime FROM detections GROUP BY Sci_Name') as $row) {
    $first_seen_map[$row['Sci_Name']] = $row['first_seen'];
    $lifetime_map[$row['Sci_Name']] = (int)$row['lifetime'];
  }
  $precision_map = [];
  $review_stats = [];
  if ($has_reviews) {
    foreach (review_rows($db, "SELECT sci_name, MIN(com_name) AS com_name,
        SUM(CASE WHEN status='confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status='false_positive' THEN 1 ELSE 0 END) AS rejected
      FROM detection_reviews GROUP BY sci_name") as $row) {
      $n = (int)$row['confirmed'] + (int)$row['rejected'];
      $review_stats[$row['sci_name']] = $row;
      if ($n >= 10) $precision_map[$row['sci_name']] = (int)$row['confirmed'] / $n;
    }
  }

  // Stream the week's detections into visit summaries instead of retaining
  // every member clip in PHP memory for the dashboard's periodic count.
  $status_sql = $has_reviews ? 'r.status' : 'NULL';
  $join = $has_reviews ? ' LEFT JOIN detection_reviews r ON r.file_name=d.File_Name' : '';
  $result = review_query($db, "SELECT d.Date, d.Time, d.Sci_Name, d.Com_Name, d.Confidence, d.File_Name, $status_sql AS review_status
    FROM detections d $join WHERE d.Date >= DATE('now','localtime','-$days days') ORDER BY d.Date ASC, d.Time ASC, d.rowid ASC");
  $visits = [];
  $open = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $sci = $row['Sci_Name'];
    $secs = time_to_seconds($row['Time']);
    $conf = round((float)$row['Confidence'], 4);
    $idx = $open[$sci] ?? -1;
    if ($idx >= 0 && ($visits[$idx]['date'] !== $row['Date'] || $secs - $visits[$idx]['last_secs'] > $gap)) $idx = -1;
    if ($idx < 0) {
      $visits[] = ['species' => $row['Com_Name'], 'sci_name' => $sci, 'date' => $row['Date'],
        'first_time' => $row['Time'], 'last_time' => $row['Time'], 'last_secs' => $secs,
        'count' => 0, 'best_confidence' => 0.0, 'best_file' => $row['File_Name'],
        'best_reviewed' => $row['review_status'] !== null, 'unreviewed_count' => 0];
      $idx = count($visits) - 1;
      $open[$sci] = $idx;
    }
    $visits[$idx]['count']++;
    $visits[$idx]['last_time'] = $row['Time'];
    $visits[$idx]['last_secs'] = $secs;
    if ($row['review_status'] === null) $visits[$idx]['unreviewed_count']++;
    if ($conf > $visits[$idx]['best_confidence']) {
      $visits[$idx]['best_confidence'] = $conf;
      $visits[$idx]['best_file'] = $row['File_Name'];
      $visits[$idx]['best_reviewed'] = $row['review_status'] !== null;
    }
  }
  if (!in_array($db->lastErrorCode(), [0, 100, 101], true)) throw new RuntimeException('Could not read review visits.');
  $result->finalize();

  $queue = [];
  foreach ($visits as $v) {
    if ($v['unreviewed_count'] === 0) continue;
    $precision = $precision_map[$v['sci_name']] ?? null;
    $reasons = [];
    // Preserve the existing uncertainty, trust, rarity and precision rules.
    if ($v['best_confidence'] >= $band_min && $v['best_confidence'] < $band_max && !$v['best_reviewed']
        && ($precision === null || $precision < 0.95)) $reasons[] = 'uncertain';
    if (($first_seen_map[$v['sci_name']] ?? null) === $v['date']) $reasons[] = 'first_lifetime';
    if (is_region_rare($v['sci_name'], $v['date'])) $reasons[] = 'region_rare';
    if (!in_array('first_lifetime', $reasons, true) && ($lifetime_map[$v['sci_name']] ?? PHP_INT_MAX) <= YARD_RARE_LIFETIME_MAX) $reasons[] = 'yard_rare';
    if ($precision !== null && $precision <= 0.5) $reasons[] = 'low_precision';
    if (!$reasons) continue;
    unset($v['last_secs'], $v['best_reviewed']);
    $v['reasons'] = $reasons;
    $queue[] = $v;
  }
  $total = count($queue);
  if ($limit > 0) {
    usort($queue, function ($a, $b) {
      return strcmp($b['date'], $a['date']) ?: strcmp($b['last_time'], $a['last_time']) ?: strcmp($a['sci_name'], $b['sci_name']);
    });
    $queue = array_slice($queue, $offset, $limit);
    foreach ($queue as &$v) {
      $members = review_rows($db, 'SELECT File_Name FROM detections WHERE Sci_Name=:sci AND Date=:date AND Time>=:from AND Time<=:to ORDER BY Time ASC, rowid ASC',
        [':sci' => $v['sci_name'], ':date' => $v['date'], ':from' => $v['first_time'], ':to' => $v['last_time']]);
      $v['member_clips'] = array_map(function ($d) use ($v) { return detection_clip_relative_path($v['date'], $v['species'], $d['File_Name']); }, $members);
      $v['clip_path'] = detection_clip_relative_path($v['date'], $v['species'], $v['best_file']);
    }
    unset($v);
  } else {
    $queue = [];
  }
  $suggestions = [];
  foreach ($review_stats as $sci => $stats) {
    $confirmed = (int)$stats['confirmed'];
    $rejected = (int)$stats['rejected'];
    $n = $confirmed + $rejected;
    if ($n >= 10 && $rejected / $n >= 0.8) $suggestions[] = ['sci_name' => $sci, 'com_name' => $stats['com_name'],
      'confirmed' => $confirmed, 'rejected' => $rejected, 'rejected_pct' => round($rejected / $n * 100)];
  }
  return ['queue' => $queue, 'count' => count($queue), 'total' => $total, 'offset' => $offset,
    'band' => ['min' => $band_min, 'max' => $band_max], 'days' => $days, 'suggestions' => $suggestions, 'generated_at' => date('c')];
}

class ReviewTargetNotFound extends RuntimeException {}

function save_detection_review($db, $body) {
  $status = $body['status'] ?? null;
  if (!in_array($status, ['confirmed', 'false_positive', 'hidden', 'unsure', 'clear'], true)) {
    throw new InvalidArgumentException('Invalid review status.');
  }
  $note = $body['note'] ?? null;
  if ($note !== null && (!is_string($note) || mb_strlen($note) > 2000)) throw new InvalidArgumentException('Note must be text of at most 2000 characters.');
  $note = $note === null || trim($note) === '' ? null : trim($note);
  if (!empty($body['file_name']) && is_string($body['file_name'])) {
    $via = 'single';
    $where = 'File_Name=:file';
    $params = [':file' => $body['file_name']];
  } elseif (isset($body['visit']) && is_array($body['visit'])) {
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
    $via = 'visit';
    $where = 'Sci_Name=:sci AND Date=:date AND Time>=:from AND Time<=:to';
    $params = [':sci' => $v['sci_name'], ':date' => $v['date'], ':from' => $v['from_time'], ':to' => $v['to_time']];
  } else {
    throw new InvalidArgumentException('Provide file_name or a visit window.');
  }

  $in_transaction = false;
  try {
    // Reserve the writer before selecting targets. A decision covers only its
    // requested snapshot window, never clips that arrive later in the visit.
    review_exec($db, 'BEGIN IMMEDIATE');
    $in_transaction = true;
    $targets = review_rows($db, "SELECT DISTINCT File_Name, Sci_Name, Com_Name, Date, Time FROM detections WHERE $where ORDER BY Time ASC", $params);
    if (!$targets) throw new ReviewTargetNotFound('No detections found for this review.');
    require_once __DIR__ . '/spine_schema.php';
    foreach (spine_schema_statements_standalone() as $sql) review_exec($db, $sql);
    $affected = 0;
    $seen = [];
    foreach ($targets as $t) {
      if (isset($seen[$t['File_Name']])) continue;
      $seen[$t['File_Name']] = true;
      if ($status === 'clear') {
        $result = review_query($db, 'DELETE FROM detection_reviews WHERE file_name=:file', [':file' => $t['File_Name']]);
      } else {
        $result = review_query($db, "INSERT INTO detection_reviews (file_name, sci_name, com_name, date, time, status, reviewed_via, note)
          VALUES (:file,:sci,:com,:date,:time,:status,:via,:note)
          ON CONFLICT(file_name) DO UPDATE SET status=excluded.status, reviewed_via=excluded.reviewed_via,
            note=excluded.note, created_at=datetime('now','localtime')",
          [':file' => $t['File_Name'], ':sci' => $t['Sci_Name'], ':com' => $t['Com_Name'], ':date' => $t['Date'],
           ':time' => $t['Time'], ':status' => $status, ':via' => $via, ':note' => $note]);
      }
      $result->finalize();
      $changes = $db->changes();
      if ($status !== 'clear' && $changes !== 1) throw new RuntimeException('Review write did not save the verdict.');
      if ($status === 'clear' && review_rows($db, 'SELECT file_name FROM detection_reviews WHERE file_name=:file', [':file' => $t['File_Name']])) {
        throw new RuntimeException('Review clear did not remove the verdict.');
      }
      $affected += $changes;
    }
    review_exec($db, 'COMMIT');
    $in_transaction = false;
    return ['status' => 'ok', 'affected' => $affected, 'review_status' => $status, 'via' => $via];
  } catch (Throwable $e) {
    if ($in_transaction) {
      try { review_exec($db, 'ROLLBACK'); }
      catch (Throwable $rollback_error) { error_log('Review rollback failed: ' . $rollback_error->getMessage()); }
    }
    throw $e;
  }
}
