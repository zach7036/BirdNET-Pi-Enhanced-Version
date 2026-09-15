<?php
// Guided review is a read-only projection of detections plus reversible metadata.
// Legacy visit endpoints remain available; this module never changes detections.
function review_case_schema($db) {
  review_workflow_schema($db);
  foreach ([
    'CREATE TABLE IF NOT EXISTS review_cases (case_key TEXT PRIMARY KEY, snapshot_json TEXT NOT NULL, state TEXT NOT NULL, until_at INTEGER NOT NULL DEFAULT 0, baseline_json TEXT NOT NULL, token TEXT NOT NULL, updated_at INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS review_evidence (file_name TEXT PRIMARY KEY, target_json TEXT NOT NULL, review_token TEXT NOT NULL, case_key TEXT NOT NULL, source TEXT NOT NULL, visit_key TEXT NOT NULL, score REAL NOT NULL, checked_at INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS review_case_actions (request_id TEXT PRIMARY KEY, request_hash TEXT NOT NULL, token TEXT NOT NULL UNIQUE, snapshot_json TEXT NOT NULL, response_json TEXT NOT NULL, undone INTEGER NOT NULL DEFAULT 0, created_at INTEGER NOT NULL)',
    'CREATE TABLE IF NOT EXISTS review_rename_archive (id INTEGER PRIMARY KEY, old_file TEXT NOT NULL, new_file TEXT NOT NULL, snapshot_json TEXT NOT NULL, created_at INTEGER NOT NULL)',
    'CREATE INDEX IF NOT EXISTS idx_review_evidence_checked ON review_evidence(checked_at)',
    'CREATE INDEX IF NOT EXISTS idx_review_cases_updated ON review_cases(updated_at)'
  ] as $sql) review_exec($db, $sql);
}

function review_case_key($kind, $sci, $date = '', $anchor = '') {
  return hash('sha256', json_encode([$kind, $sci, $date, $anchor], JSON_UNESCAPED_UNICODE));
}

function review_confirmed_presence($db, $date = null, $sci = null) {
  if (!review_table_exists($db, 'detection_reviews')) return [];
  $where = ''; $params = [];
  if ($date !== null) { $where .= ' AND d.Date=:date'; $params[':date'] = $date; }
  if ($sci !== null) { $where .= ' AND d.Sci_Name=:sci'; $params[':sci'] = $sci; }
  return review_rows($db, "SELECT d.Sci_Name AS sci_name, d.Com_Name AS species, d.Date AS date,
      COUNT(*) AS supporting_recordings,
      SUM(CASE WHEN r.reviewed_via IN ('guided_clip','guided_sample') THEN 1 ELSE 0 END) AS individually_checked,
      MIN(d.File_Name) AS evidence_file
    FROM detection_reviews r CROSS JOIN detections d ON d.File_Name=r.file_name AND d.Sci_Name=r.sci_name
      AND d.Date=r.date AND d.Time=r.time
    WHERE r.status='confirmed' $where GROUP BY d.Sci_Name,d.Date ORDER BY d.Date DESC,d.Com_Name", $params);
}

// A sampled clip is not a verdict on its whole visit. Count at most one recent
// sample per visit, separately from targeted and legacy decisions.
function review_evidence_history($db, $sci = null, $now = null) {
  $now = $now ?? time(); $out = [];
  if (!review_table_exists($db, 'review_evidence')) return $out;
  $params = [':since' => $now - 90 * 86400];
  $where = '';
  if ($sci !== null) { $where = ' AND d.Sci_Name=:sci'; $params[':sci'] = $sci; }
  $rows = review_rows($db, "SELECT e.*,d.Sci_Name,d.Com_Name,d.Date,d.Time,r.status
    FROM review_evidence e JOIN detections d ON d.File_Name=e.file_name
    JOIN detection_reviews r ON r.file_name=e.file_name AND r.sci_name=d.Sci_Name AND r.date=d.Date AND r.time=d.Time
    JOIN review_state_versions v ON v.file_name=e.file_name AND v.token=e.review_token
    WHERE e.checked_at>=:since AND r.status IN ('confirmed','false_positive') $where
    ORDER BY e.checked_at DESC,e.file_name", $params);
  $seen = [];
  foreach ($rows as $r) {
    $target = json_decode($r['target_json'], true);
    if (!$target || $target['Sci_Name'] !== $r['Sci_Name'] || $target['Date'] !== $r['Date'] || $target['Time'] !== $r['Time']) continue;
    $name = $r['Sci_Name']; $source = $r['source'] === 'sample' ? 'sample' : 'targeted';
    $key = $name . '|' . $source . '|' . $r['visit_key'];
    if (isset($seen[$key])) continue;
    $seen[$key] = true;
    if (!isset($out[$name])) $out[$name] = ['sample' => ['confirmed' => 0, 'rejected' => 0], 'targeted' => ['confirmed' => 0, 'rejected' => 0], 'bands' => []];
    $verdict = $r['status'] === 'confirmed' ? 'confirmed' : 'rejected';
    $out[$name][$source][$verdict]++;
    if ($source === 'sample') {
      $band = min(9, (int)floor((float)$r['score'] * 10));
      if (!isset($out[$name]['bands'][$band])) $out[$name]['bands'][$band] = ['confirmed' => 0, 'rejected' => 0, 'days' => []];
      $out[$name]['bands'][$band][$verdict]++;
      $out[$name]['bands'][$band]['days'][$r['Date']] = true;
    }
  }
  return $out;
}

function review_case_file_revision($target, $status, $version) {
  return hash('sha256', json_encode([$target, $status, $version], JSON_UNESCAPED_UNICODE));
}

function review_case_date($value) {
  if (!is_string($value)) throw new InvalidArgumentException('Invalid review date.');
  $d = DateTime::createFromFormat('!Y-m-d', $value);
  if (!$d || $d->format('Y-m-d') !== $value) throw new InvalidArgumentException('Invalid review date.');
  return $value;
}

function review_cases_data($db, $options = []) {
  $now = $options['now'] ?? time();
  $today = date('Y-m-d', $now);
  $end = review_case_date($options['end'] ?? $today);
  $start = review_case_date($options['start'] ?? date('Y-m-d', strtotime($end . ' -6 days')));
  $window_days = (new DateTimeImmutable($start))->diff(new DateTimeImmutable($end))->days;
  if ($start > $end || $window_days >= 31) throw new InvalidArgumentException('Choose a review window of at most 31 days.');
  $view = $options['view'] ?? 'recommended';
  if (!in_array($view, ['recommended','all','history','samples'], true)) throw new InvalidArgumentException('Unknown review view.');
  $limit = request_int($options, 'limit', 25, 0, 100);
  $offset = request_int($options, 'offset', 0, 0, 100000);
  $gap = $options['gap_seconds'] ?? get_visit_gap_seconds();
  $available = $options['clip_available'] ?? function ($path) { return clip_exists($path) && is_readable(clip_absolute_path($path)); };
  $has_reviews = review_table_exists($db, 'detection_reviews');
  $has_versions = review_table_exists($db, 'review_state_versions');
  $has_deferrals = review_table_exists($db, 'review_deferrals');
  $join = $has_reviews ? ' LEFT JOIN detection_reviews r ON r.file_name=d.File_Name' : '';
  if ($has_versions) $join .= ' LEFT JOIN review_state_versions v ON v.file_name=d.File_Name';
  if ($has_deferrals) $join .= ' LEFT JOIN review_deferrals f ON f.file_name=d.File_Name';
  $status = $has_reviews ? 'r.status' : 'NULL';
  $version = $has_versions ? 'v.token' : 'NULL';
  $defer = $has_deferrals ? 'f.deferred_until' : 'NULL';
  $params = [':start' => $start, ':end' => $end]; $species_where = '';
  if (isset($options['sci_name'])) { $species_where = ' AND d.Sci_Name=:sci'; $params[':sci'] = $options['sci_name']; }
  $result = review_query($db, "SELECT d.File_Name,d.Sci_Name,d.Com_Name,d.Date,d.Time,d.Confidence,
    $status AS status,$version AS version,$defer AS deferred_until FROM detections d $join
    WHERE d.Date>=:start AND d.Date<=:end $species_where ORDER BY d.Sci_Name,d.Date,d.Time,d.rowid", $params);
  $visits = []; $last = null;
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) {
    $seconds = time_to_seconds($row['Time']);
    if ($last === null || $row['Sci_Name'] !== $visits[$last]['sci'] || $row['Date'] !== $visits[$last]['date'] || $seconds - $visits[$last]['seconds'] > $gap) {
      $last = count($visits);
      $visits[] = ['sci' => $row['Sci_Name'], 'species' => $row['Com_Name'], 'date' => $row['Date'], 'anchor' => $row['File_Name'], 'rows' => [], 'seconds' => $seconds];
    }
    $visits[$last]['seconds'] = $seconds;
    $visits[$last]['last_time'] = $row['Time'];
    $visits[$last]['rows'][] = $row;
  }
  if (!in_array($db->lastErrorCode(), [0,100,101], true)) throw new RuntimeException('Could not read review cases.');
  $result->finalize();
  $presence = review_confirmed_presence($db);
  $known = []; $known_days = [];
  foreach ($presence as $p) { $known[$p['sci_name']] = true; $known_days[$p['sci_name']][$p['date']] = true; }
  $history = review_evidence_history($db, null, $now);
  $states = [];
  if (review_table_exists($db, 'review_cases')) {
    foreach (review_rows($db, 'SELECT * FROM review_cases ORDER BY updated_at DESC') as $state) $states[$state['case_key']] = $state;
  }
  // Daily optional samples: yesterday only, chosen before removing reviewed
  // cases, so completing one never creates an endless replacement sample.
  $yesterday = date('Y-m-d', strtotime($today . ' -1 day'));
  $sample_pool = [];
  foreach ($visits as $i => &$visit) {
    $visit['visit_key'] = review_case_key('visit', $visit['sci'], $visit['date'], $visit['anchor']);
    $last_ts = strtotime($visit['date'] . ' ' . $visit['last_time']);
    $visit['active'] = $last_ts === false || $now - $last_ts <= $gap;
    $established = !empty($known_days[$visit['sci']]) && min(array_keys($known_days[$visit['sci']])) < $yesterday;
    if ($visit['date'] === $yesterday && !$visit['active'] && $established && !is_region_rare($visit['sci'], $visit['date'])) {
      $sample_pool[$i] = hash('sha256', $today . '|sample-v1|' . $visit['visit_key']);
    }
  }
  unset($visit);
  asort($sample_pool); $samples = []; $sample_species = []; $sample_bands = [];
  // Prefer a second species in a different score band when the pool allows it.
  $fallback_samples = [];
  foreach ($sample_pool as $i => $rank) {
    if (isset($sample_species[$visits[$i]['sci']])) continue;
    $sample_band = min(9, (int)floor(max(array_column($visits[$i]['rows'], 'Confidence')) * 10));
    if (isset($sample_bands[$sample_band])) { $fallback_samples[] = $i; continue; }
    $samples[$i] = true; $sample_species[$visits[$i]['sci']] = true;
    $sample_bands[$sample_band] = true;
    if (count($samples) === 2) break;
  }
  foreach ($fallback_samples as $i) {
    if (count($samples) === 2) break;
    if (isset($sample_species[$visits[$i]['sci']])) continue;
    $samples[$i] = true; $sample_species[$visits[$i]['sci']] = true;
  }
  $cases = [];
  foreach ($visits as $i => $visit) {
    $sci = $visit['sci']; $date = $visit['date'];
    $unknown = !isset($known[$sci]);
    $rare = is_region_rare($sci, $date);
    $kind = $unknown ? 'discovery' : ($rare && !isset($known_days[$sci][$date]) ? 'occurrence' : 'check');
    $pending = array_values(array_filter($visit['rows'], function ($r) { return $r['status'] === null; }));
    $best = $pending ? max(array_column($pending, 'Confidence')) : 0;
    $targeted = $history[$sci]['targeted'] ?? ['confirmed' => 0, 'rejected' => 0];
    $contradiction = $targeted['rejected'] >= 3 && $targeted['rejected'] >= $targeted['confirmed'];
    // Recurrence is one daily question, not an urgent task for every visit.
    $anchor = $kind === 'check' ? ($contradiction ? 'recurring' : $visit['anchor']) : '';
    $key = review_case_key($kind, $sci, $kind === 'discovery' ? '' : $date, $anchor);
    $sample = isset($samples[$i]) && $kind === 'check';
    $routine = $best >= .60 && $best < .85;
    if ($kind === 'check' && !$routine && !$contradiction && !$sample && !isset($states[$key])) continue;
    if (!$pending && !isset($states[$key])) continue;
    if (!isset($cases[$key])) {
      $cases[$key] = ['key' => $key, 'kind' => $kind, 'sci_name' => $sci, 'species' => $visit['species'],
        'date' => $date, 'end_date' => $date, 'anchor' => $anchor,
        'title' => $kind === 'discovery' ? 'Confirm this species at your station' : ($kind === 'occurrence' ? 'Check an unusual occurrence' : 'Check an identification'),
        'reasons' => [], 'priority' => $kind !== 'check' || $contradiction ? 'important' : 'routine',
        'sample' => $sample, 'visits' => 0, 'detections' => 0, 'candidates' => [], 'active' => false,
        'version' => $states[$key]['token'] ?? '', 'revision' => '', 'state' => 'open', 'until_at' => 0,
        'visit_keys' => [], 'baseline_score' => 0, 'deferred' => true];
    }
    $c =& $cases[$key]; $c['visits']++; $c['detections'] += count($visit['rows']);
    $c['end_date'] = max($c['end_date'], $date); $c['active'] = $c['active'] || $visit['active'];
    if (!$visit['active']) $c['visit_keys'][] = $visit['visit_key'];
    if ($unknown) $c['reasons']['discovery'] = 'No human confirmation is on record. One clear recording can establish presence; other recordings remain unverified.';
    if ($rare) $c['reasons']['rare'] = 'Unusual for this location and season. A high model score does not settle this question.';
    if ($contradiction) $c['reasons']['history'] = 'Several separate recent targeted checks were rejected. This is a reason to inspect evidence, not a species-wide accuracy estimate.';
    if ($routine && $kind === 'check') $c['reasons']['routine'] = 'Optional check: an unreviewed match is in the 60% to below-85% band.';
    if ($sample) $c['reasons']['sample'] = 'Optional daily quality check, selected independently of whether its score looks suspicious.';
    $band = min(9, (int)floor($best * 10));
    $band_history = $history[$sci]['bands'][$band] ?? null;
    $c['lower_priority'] = $kind === 'check' && !$contradiction && $band_history && $band_history['confirmed'] >= 10 && !$band_history['rejected'] && count($band_history['days']) >= 3;
    foreach ($pending as $r) {
      if ((int)$r['deferred_until'] <= $now) $c['deferred'] = false;
      if ($visit['active']) continue;
      $c['baseline_score'] = max($c['baseline_score'], (float)$r['Confidence']);
      $target = array_intersect_key($r, array_flip(['File_Name','Sci_Name','Com_Name','Date','Time']));
      $c['candidates'][] = ['file_name' => $r['File_Name'], 'date' => $date, 'time' => $r['Time'], 'score' => (float)$r['Confidence'],
        'visit_key' => $visit['visit_key'], 'from_time' => $visit['rows'][0]['Time'], 'to_time' => $visit['last_time'], 'clip_path' => detection_clip_relative_path($date, $r['Com_Name'], $r['File_Name']),
        'file_revision' => review_case_file_revision($target, $r['status'], $r['version']), 'target' => $target];
    }
    unset($c);
  }
  // Persistent state preserves acted-on questions beyond the recent window.
  // Untouched older questions can be browsed by selecting their date window.
  foreach ($states as $key => $state) {
    if (isset($cases[$key])) continue;
    $snapshot = json_decode($state['snapshot_json'], true);
    if (!is_array($snapshot)) continue;
    if (isset($options['sci_name']) && $snapshot['sci_name'] !== $options['sci_name']) continue;
    $snapshot['candidates'] = []; $snapshot['version'] = $state['token'];
    $snapshot['archived'] = true; $cases[$key] = $snapshot;
  }
  $counts = ['recommended' => 0, 'all' => 0, 'history' => 0, 'samples' => 0, 'active' => 0, 'unavailable' => 0, 'unresolved' => 0, 'later' => 0];
  $output = [];
  foreach ($cases as $key => $c) {
    // Detail/action requests must not stat other cases while holding a writer
    // transaction. Counts in a key-scoped response describe that question only.
    if (isset($options['key']) && $options['key'] !== $key) continue;
    $c += ['visit_keys' => [], 'baseline_score' => 0, 'deferred' => false, 'active' => false];
    $state = $states[$key] ?? null;
    $c['state'] = $state['state'] ?? 'open'; $c['until_at'] = (int)($state['until_at'] ?? 0);
    if ($c['state'] === 'later' && $c['until_at'] <= $now) $c['state'] = 'open';
    // Presence can be established by another case or by the legacy endpoint.
    $has_presence = ($c['kind'] === 'discovery' && isset($known[$c['sci_name']])) || ($c['kind'] === 'occurrence' && isset($known_days[$c['sci_name']][$c['date']]));
    if ($has_presence) $c['state'] = 'resolved';
    elseif ($c['kind'] !== 'check' && $c['state'] === 'resolved') $c['state'] = 'open';
    $baseline = $state ? json_decode($state['baseline_json'], true) : null;
    $candidates = $c['candidates'];
    if (isset($options['evidence_files'])) $candidates = array_values(array_filter($candidates, function ($candidate) use ($options) { return in_array($candidate['file_name'], $options['evidence_files'], true); }));
    usort($candidates, function ($a, $b) { return $b['score'] <=> $a['score'] ?: strcmp($a['file_name'], $b['file_name']); });
    $evidence = []; $visit_seen = []; $extras = [];
    $desired_visits = min(3, count(array_unique(array_column($candidates, 'visit_key'))));
    foreach ($candidates as $candidate) {
      if (!$available($candidate['clip_path'])) continue;
      $candidate['audio_available'] = true;
      if ($c['state'] === 'unresolved' && $baseline &&
          (!in_array($candidate['visit_key'], $baseline['visits'] ?? [], true) && $candidate['score'] >= ($baseline['score'] ?? 1) + .10 || empty($baseline['had_audio']))) {
        $c['state'] = 'open'; $c['reopened_reason'] = 'New evidence is available. A higher model score is not proof of clearer or correct audio.';
      }
      if (!isset($visit_seen[$candidate['visit_key']])) {
        $evidence[] = $candidate; $visit_seen[$candidate['visit_key']] = true;
      } else $extras[] = $candidate;
      if (count($evidence) >= $desired_visits && count($evidence) + count($extras) >= 3 && empty($options['details'])) break;
    }
    $c['evidence'] = array_merge($evidence, $extras);
    if (empty($options['details'])) $c['evidence'] = array_slice($c['evidence'], 0, 3);
    else $c['evidence'] = array_slice($c['evidence'], 0, 100);
    $c['audio_available'] = !empty($c['evidence']);
    $c['baseline'] = !empty($c['archived']) && $baseline ? $baseline : ['score' => $c['baseline_score'], 'visits' => $c['visit_keys'], 'had_audio' => $c['audio_available']];
    $c['candidate_count'] = count($candidates);
    if (!$state && $c['state'] === 'open' && !empty($c['deferred']) && $candidates) $c['state'] = 'later';
    if ($c['state'] === 'open' && !$candidates && empty($c['active'])) $c['state'] = !empty($c['archived']) ? 'archived' : 'unresolved';
    $c['ready'] = $c['state'] === 'open' && $c['audio_available'];
    // A discovery may offer completed evidence while another visit is active.
    $c['recommended'] = $c['ready'] && $c['priority'] === 'important';
    if ($c['recommended']) $counts['recommended']++;
    if ($c['ready']) $counts['all']++;
    if ($c['ready'] && $c['sample']) $counts['samples']++;
    $in_history = !$c['ready'];
    if ($in_history) $counts['history']++;
    if ($c['state'] === 'open' && !$c['audio_available']) $counts[$c['active'] ? 'active' : 'unavailable']++;
    if (in_array($c['state'], ['unresolved','later'], true)) $counts[$c['state']]++;
    $c['reasons'] = array_values($c['reasons']);
    unset($c['candidates'], $c['visit_keys'], $c['baseline_score'], $c['deferred']);
    if (($view === 'recommended' && $c['recommended']) || ($view === 'all' && $c['ready']) || ($view === 'history' && $in_history) || ($view === 'samples' && $c['ready'] && $c['sample']) || isset($options['key']) && $options['key'] === $key) $output[] = $c;
  }
  usort($output, function ($a, $b) {
    return ($b['priority'] === 'important') <=> ($a['priority'] === 'important') ?: ($a['lower_priority'] ?? false) <=> ($b['lower_priority'] ?? false)
      ?: strcmp($a['date'], $b['date']) ?: strcmp($a['key'], $b['key']);
  });
  if (isset($options['key'])) $output = array_values(array_filter($output, function ($c) use ($options) { return $c['key'] === $options['key']; }));
  $total = count($output);
  return ['cases' => $limit ? array_slice($output, $offset, $limit) : [], 'total' => $total, 'counts' => $counts,
    'view' => $view, 'start' => $start, 'end' => $end, 'offset' => $offset, 'gap_seconds' => $gap, 'generated_at' => date('c', $now)];
}

require_once __DIR__ . '/review_case_actions.php';
