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

// Compact decimal percentages preserve the actual cutoff (84.99 is not 85).
function review_percent($confidence) {
  return rtrim(rtrim(number_format($confidence * 100, 2, '.', ''), '0'), '.') . '%';
}

function review_table_exists($db, $name) {
  return (bool)review_rows($db, "SELECT name FROM sqlite_master WHERE type='table' AND name=:name", [':name' => $name]);
}

// Only fully, consistently reviewed, completed visits teach station trust.
// Read all detections on reviewed species/dates, including unreviewed bridges,
// so missing decisions cannot split one chatty visit into many independent votes.
function review_visit_history($db, $gap, $now) {
  $stats = [];
  $confirmed_species = [];
  if (!review_table_exists($db, 'detection_reviews')) return [$stats, $confirmed_species];
  $result = review_query($db, "SELECT d.Sci_Name, d.Com_Name, d.Date, d.Time, r.status
    FROM (SELECT DISTINCT sci_name, date FROM detection_reviews WHERE status IN ('confirmed','false_positive')) h
    JOIN detections d ON d.Sci_Name=h.sci_name AND d.Date=h.date
    LEFT JOIN detection_reviews r ON r.file_name=d.File_Name
    ORDER BY d.Sci_Name, d.Date, d.Time, d.rowid");
  $visit = null;
  $flush = function () use (&$visit, &$stats, $gap, $now) {
    if (!$visit || $now - strtotime($visit['date'] . ' ' . $visit['last_time']) <= $gap) return;
    $sci = $visit['sci'];
    if (!isset($stats[$sci])) $stats[$sci] = ['com_name' => $visit['com'], 'confirmed' => 0, 'rejected' => 0];
    if ($visit['confirmed'] === $visit['count']) $stats[$sci]['confirmed']++;
    elseif ($visit['rejected'] === $visit['count']) $stats[$sci]['rejected']++;
  };
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $secs = time_to_seconds($row['Time']);
    if (!$visit || $visit['sci'] !== $row['Sci_Name'] || $visit['date'] !== $row['Date'] || $secs - $visit['secs'] > $gap) {
      $flush();
      $visit = ['sci' => $row['Sci_Name'], 'com' => $row['Com_Name'], 'date' => $row['Date'],
        'count' => 0, 'confirmed' => 0, 'rejected' => 0];
    }
    $visit['count']++;
    $visit['secs'] = $secs;
    $visit['last_time'] = $row['Time'];
    if ($row['status'] === 'confirmed') {
      $visit['confirmed']++;
      $confirmed_species[$row['Sci_Name']] = true;
    } elseif ($row['status'] === 'false_positive') $visit['rejected']++;
  }
  if (!in_array($db->lastErrorCode(), [0, 100, 101], true)) throw new RuntimeException('Could not read review history.');
  $result->finalize();
  $flush();
  return [$stats, $confirmed_species];
}

// Check files only after a visit qualifies for review. A station can have
// hundreds of thousands of ordinary detections whose files need no stat().
function review_visit_audio($db, $visit, $has_reviews, $available) {
  $join = $has_reviews ? ' LEFT JOIN detection_reviews r ON r.file_name=d.File_Name' : '';
  $unreviewed = $has_reviews ? ' AND r.file_name IS NULL' : '';
  $result = review_query($db, "SELECT d.File_Name,d.Com_Name,d.Confidence FROM detections d $join
    WHERE d.Sci_Name=:sci AND d.Date=:date AND d.Time>=:from AND d.Time<=:to $unreviewed
    ORDER BY CASE WHEN d.File_Name=:best THEN 0 ELSE 1 END, d.Confidence DESC, d.Time, d.rowid",
    [':sci' => $visit['sci_name'], ':date' => $visit['date'], ':from' => $visit['first_time'], ':to' => $visit['last_time'], ':best' => $visit['best_file']]);
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $path = detection_clip_relative_path($visit['date'], $row['Com_Name'], $row['File_Name']);
    if ($available($path)) {
      $visit['clip_path'] = $path;
      $visit['playback_confidence'] = round((float)$row['Confidence'], 4);
      $visit['playback_file'] = $row['File_Name'];
      break;
    }
  }
  if (!in_array($db->lastErrorCode(), [0, 100, 101], true)) throw new RuntimeException('Could not read review audio candidates.');
  $result->finalize();
  return $visit;
}

// Reads never create tables. limit=0 gives the home page the same actionable
// counts without building member lists. Internal clock/filesystem overrides
// make regression tests deterministic; the public API never accepts them.
function review_queue_data($db, $options = []) {
  $days = request_int($options, 'days', 7, 1, 30);
  $limit = request_int($options, 'limit', 50, 0, 200);
  $offset = request_int($options, 'offset', 0, 0, 100000);
  $band_min = isset($options['band_min']) && is_numeric($options['band_min']) ? max(0, min(1, (float)$options['band_min'])) : 0.60;
  $band_max = isset($options['band_max']) && is_numeric($options['band_max']) ? max(0, min(1, (float)$options['band_max'])) : 0.85;
  $gap = isset($options['gap_seconds']) ? max(1, (int)$options['gap_seconds']) : get_visit_gap_seconds();
  $now = $options['now'] ?? time();
  $group = $options['group'] ?? 'ready';
  if (!in_array($group, ['ready', 'important', 'routine', 'active', 'unavailable', 'skipped'], true)) {
    throw new InvalidArgumentException('Unknown review group.');
  }
  $available = $options['clip_available'] ?? function ($path) { return clip_exists($path) && is_readable(clip_absolute_path($path)); };
  $has_reviews = review_table_exists($db, 'detection_reviews');
  $has_deferrals = review_table_exists($db, 'review_deferrals');
  $first_seen = [];
  $lifetime = [];
  foreach (review_rows($db, 'SELECT Sci_Name, MIN(Date) AS first_seen, COUNT(*) AS lifetime FROM detections GROUP BY Sci_Name') as $row) {
    $first_seen[$row['Sci_Name']] = $row['first_seen'];
    $lifetime[$row['Sci_Name']] = (int)$row['lifetime'];
  }
  [$stats, $confirmed_species] = review_visit_history($db, $gap, $now);
  $precision = [];
  foreach ($stats as $sci => $s) {
    $n = $s['confirmed'] + $s['rejected'];
    if ($n >= 10) $precision[$sci] = $s['confirmed'] / $n;
  }
  $status_sql = $has_reviews ? 'r.status' : 'NULL';
  $defer_sql = $has_deferrals ? 'f.deferred_until' : 'NULL';
  $joins = $has_reviews ? ' LEFT JOIN detection_reviews r ON r.file_name=d.File_Name' : '';
  if ($has_deferrals) $joins .= ' LEFT JOIN review_deferrals f ON f.file_name=d.File_Name';
  $result = review_query($db, "SELECT d.Date, d.Time, d.Sci_Name, d.Com_Name, d.Confidence, d.File_Name,
    $status_sql AS review_status, $defer_sql AS deferred_until FROM detections d $joins
    WHERE d.Date >= DATE('now','localtime','-$days days') ORDER BY d.Date, d.Time, d.rowid");
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
        'best_reviewed' => $row['review_status'] !== null, 'unreviewed_count' => 0,
        'deferred_count' => 0, 'deferred_until' => null, 'clip_path' => null, 'playback_confidence' => null, 'playback_file' => null];
      $idx = count($visits) - 1;
      $open[$sci] = $idx;
    }
    $v =& $visits[$idx];
    $v['count']++;
    $v['last_time'] = $row['Time'];
    $v['last_secs'] = $secs;
    if ($row['review_status'] === null) {
      $v['unreviewed_count']++;
      if ((int)$row['deferred_until'] > $now) {
        $v['deferred_count']++;
        $v['deferred_until'] = $v['deferred_until'] === null ? (int)$row['deferred_until'] : min($v['deferred_until'], (int)$row['deferred_until']);
      }
    }
    if ($conf > $v['best_confidence']) {
      $v['best_confidence'] = $conf;
      $v['best_file'] = $row['File_Name'];
      $v['best_reviewed'] = $row['review_status'] !== null;
    }
    unset($v);
  }
  if (!in_array($db->lastErrorCode(), [0, 100, 101], true)) throw new RuntimeException('Could not read review visits.');
  $result->finalize();
  $counts = ['ready' => 0, 'important' => 0, 'routine' => 0, 'active' => 0, 'unavailable' => 0, 'skipped' => 0];
  $queue = [];
  foreach ($visits as $v) {
    if ($v['unreviewed_count'] === 0) continue;
    $sci = $v['sci_name'];
    $p = $precision[$sci] ?? null;
    $reasons = [];
    $details = [];
    $add = function ($code, $message) use (&$reasons, &$details) { $reasons[] = $code; $details[] = ['code' => $code, 'text' => $message]; };
    if ($v['best_confidence'] >= $band_min && $v['best_confidence'] < $band_max && !$v['best_reviewed'] && ($p === null || $p < 0.95)) {
      $add('uncertain', 'Best match ' . review_percent($v['best_confidence']) . ' is below the ' . review_percent($band_max) . ' review cutoff (band starts at ' . review_percent($band_min) . ').');
    }
    $first_day = ($first_seen[$sci] ?? null) === $v['date'];
    if ($first_day && !isset($confirmed_species[$sci])) $add('first_lifetime', 'Recorded on this species’ first day at your station; no occurrence has been confirmed yet.');
    if (is_region_rare($sci, $v['date'])) $add('region_rare', 'The location model considers this species unusual here at this time of year, even with a high confidence score.');
    if (!$first_day && ($lifetime[$sci] ?? PHP_INT_MAX) <= YARD_RARE_LIFETIME_MAX) $add('yard_rare', 'Only ' . $lifetime[$sci] . ' detections of this species are on record at your station.');
    if ($p !== null && $p <= 0.5) {
      $n = $stats[$sci]['confirmed'] + $stats[$sci]['rejected'];
      $add('low_precision', 'You rejected ' . $stats[$sci]['rejected'] . ' of ' . $n . ' independently reviewed visits for this species.');
    }
    if (!$reasons) continue;
    $v = review_visit_audio($db, $v, $has_reviews, $available);
    $v['reasons'] = $reasons;
    $v['reason_details'] = $details;
    $v['priority'] = count(array_diff($reasons, ['uncertain'])) ? 'important' : 'routine';
    $v['priority_rank'] = in_array('first_lifetime', $reasons, true) ? 0 : (in_array('region_rare', $reasons, true) ? 1 : (in_array('low_precision', $reasons, true) ? 2 : ($v['priority'] === 'important' ? 3 : 4)));
    $last_ts = strtotime($v['date'] . ' ' . $v['last_time']);
    $v['active'] = $last_ts === false || $now - $last_ts <= $gap;
    $v['audio_available'] = $v['clip_path'] !== null;
    $v['audio_fallback'] = $v['audio_available'] && $v['playback_file'] !== $v['best_file'];
    $v['group'] = $v['deferred_count'] === $v['unreviewed_count'] ? 'skipped'
      : ($v['active'] ? 'active' : (!$v['audio_available'] ? 'unavailable' : $v['priority']));
    $counts[$v['group']]++;
    if (in_array($v['group'], ['important', 'routine'], true)) $counts['ready']++;
    if ($group === $v['group'] || ($group === 'ready' && in_array($v['group'], ['important', 'routine'], true))) {
      unset($v['last_secs'], $v['best_reviewed'], $v['deferred_count']);
      $queue[] = $v;
    }
  }
  $total = count($queue);
  if ($limit > 0) {
    // Important cases first, then oldest first so older work is not starved.
    usort($queue, function ($a, $b) {
      return $a['priority_rank'] <=> $b['priority_rank'] ?: strcmp($a['date'], $b['date'])
        ?: strcmp($a['first_time'], $b['first_time']) ?: strcmp($a['sci_name'], $b['sci_name']);
    });
    $queue = array_slice($queue, $offset, $limit);
    foreach ($queue as &$v) {
      $members = review_rows($db, 'SELECT File_Name, Com_Name FROM detections WHERE Sci_Name=:sci AND Date=:date AND Time>=:from AND Time<=:to ORDER BY Time, rowid',
        [':sci' => $v['sci_name'], ':date' => $v['date'], ':from' => $v['first_time'], ':to' => $v['last_time']]);
      $v['member_clips'] = array_map(function ($d) use ($v) { return detection_clip_relative_path($v['date'], $d['Com_Name'], $d['File_Name']); }, $members);
      $v['reassign_available'] = true;
      foreach ($v['member_clips'] as $path) {
        if (!$available($path)) { $v['reassign_available'] = false; break; }
      }
    }
    unset($v);
  } else $queue = [];
  $suggestions = [];
  foreach ($stats as $sci => $s) {
    $n = $s['confirmed'] + $s['rejected'];
    if ($n >= 10 && $s['rejected'] / $n >= 0.8) $suggestions[] = ['sci_name' => $sci, 'com_name' => $s['com_name'],
      'confirmed' => $s['confirmed'], 'rejected' => $s['rejected'], 'rejected_pct' => round($s['rejected'] / $n * 100)];
  }
  return ['queue' => $queue, 'count' => count($queue), 'total' => $total, 'counts' => $counts, 'group' => $group,
    'pending_total' => $counts['ready'] + $counts['active'] + $counts['unavailable'] + $counts['skipped'],
    'offset' => $offset, 'band' => ['min' => $band_min, 'max' => $band_max], 'days' => $days,
    'gap_seconds' => $gap, 'suggestions' => $suggestions, 'generated_at' => date('c', $now)];
}

require_once __DIR__ . '/review_actions.php';
