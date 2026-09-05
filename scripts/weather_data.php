<?php
/* Shared read-only weather overlay. Persistent weather and detections are never
 * changed here. TEMP views work on a read-only SQLite connection and disappear
 * when that connection closes. Previous releases simply read the original table.
 */
function weather_table_exists($db, $name) {
  return (bool)$db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($name) . "'");
}

function weather_data_table($db, $now = null) {
  $now = $now ?? time();
  $fields = ['Temp', 'ConditionCode', 'IsDay', 'WindSpeed', 'WindDirection'];
  $columns = [];
  if (weather_table_exists($db, 'weather')) {
    $result = $db->query('PRAGMA table_info(weather)');
    while ($row = $result->fetchArray(SQLITE3_ASSOC)) $columns[] = $row['name'];
  }
  $select = [];
  foreach ($fields as $field) $select[] = in_array($field, $columns, true) ? $field : "NULL AS $field";
  $online = $columns ? 'SELECT Date, Hour, ' . implode(', ', $select) . ' FROM weather'
    : 'SELECT NULL AS Date, NULL AS Hour, ' . implode(', ', $select) . ' WHERE 0';
  $has_local = weather_table_exists($db, 'weather_local_observations') && weather_table_exists($db, 'weather_sync_runs');
  if (!$has_local) {
    $sources = [];
    foreach ($fields as $field) $sources[] = "CASE WHEN $field IS NOT NULL THEN 'stored_weather' END AS {$field}Source";
    $sql = 'SELECT w.*, ' . implode(', ', $sources) . " FROM ($online) w";
  } else {
    $date = date('Y-m-d', $now);
    $hour = (int)date('G', $now);
    $earliest = (int)$now - 3600;
    $latest = (int)$now + 300;
    $pivot = [];
    $merged = [];
    foreach ($fields as $field) {
      if ($field === 'IsDay') {
        $merged[] = "w.IsDay, CASE WHEN w.IsDay IS NOT NULL THEN 'stored_weather' END AS IsDaySource";
        continue;
      }
      $pivot[] = "MAX(CASE WHEN Field='$field' THEN Value END) AS $field";
      $pivot[] = "MAX(CASE WHEN Field='$field' THEN Entity END) AS {$field}Source";
      $merged[] = "COALESCE(l.$field, w.$field) AS $field";
      $merged[] = "CASE WHEN l.$field IS NOT NULL THEN l.{$field}Source WHEN w.$field IS NOT NULL THEN 'stored_weather' END AS {$field}Source";
    }
    // Completed hours keep their last good local measurement. For the current
    // hour, use only the latest attempt and reports still within the age limit:
    // a failed fetch must not silently reuse an earlier successful attempt.
    $sql = "WITH online AS ($online), ranked AS (
      SELECT o.*, ROW_NUMBER() OVER (PARTITION BY Date, Hour, Field ORDER BY RunId DESC, Id DESC) AS rank
      FROM weather_local_observations o
      WHERE Date < '$date' OR (Date = '$date' AND Hour < $hour)
         OR (Date = '$date' AND Hour = $hour AND ReportedAt BETWEEN $earliest AND $latest
             AND RunId = (SELECT MAX(r.Id) FROM weather_sync_runs r WHERE r.Date=o.Date AND r.Hour=o.Hour))
    ), local AS (
      SELECT Date, Hour, " . implode(', ', $pivot) . " FROM ranked WHERE rank=1 GROUP BY Date, Hour
    ), hours AS (SELECT Date, Hour FROM online UNION SELECT Date, Hour FROM local)
    SELECT h.Date, h.Hour, " . implode(', ', $merged) . " FROM hours h
      LEFT JOIN online w ON w.Date=h.Date AND w.Hour=h.Hour
      LEFT JOIN local l ON l.Date=h.Date AND l.Hour=h.Hour";
  }
  if (!$db->exec('CREATE TEMP VIEW IF NOT EXISTS birdnet_weather_effective AS ' . $sql)) {
    throw new RuntimeException('Unable to read weather data.');
  }
  return 'birdnet_weather_effective';
}

function weather_latest_sync($db) {
  if (!weather_table_exists($db, 'weather_sync_runs')) return null;
  $result = $db->query('SELECT * FROM weather_sync_runs ORDER BY Id DESC LIMIT 1');
  return $result ? ($result->fetchArray(SQLITE3_ASSOC) ?: null) : null;
}

function weather_optional_int($value) {
  return $value === null ? null : (int)$value;
}

function weather_entity_setting($input, $previous = '') {
  $value = $input === null ? $previous : $input;
  if (!is_string($value)) throw new InvalidArgumentException('Invalid weather entity.');
  $value = trim($value);
  if ($value !== '' && !preg_match('/^weather\.[a-z0-9_]+$/D', $value)) {
    throw new InvalidArgumentException('Invalid weather entity.');
  }
  return $value;
}

function weather_store_entity_setting($contents, $entity) {
  $line = 'HA_WEATHER_ENTITY="' . weather_entity_setting($entity) . '"';
  if (preg_match('/^HA_WEATHER_ENTITY=.*$/m', $contents)) {
    return preg_replace_callback('/^HA_WEATHER_ENTITY=.*$/m', function () use ($line) { return $line; }, $contents);
  }
  return $contents . ($contents !== '' && substr($contents, -1) !== "\n" ? "\n" : '') . $line . "\n";
}

function weather_health_checks($db, $config, $now = null) {
  $now = $now ?? time();
  $enabled = weather_sync_enabled($config);
  $sync = weather_latest_sync($db);
  $status = $enabled ? 'warn' : 'ok';
  $message = $enabled ? 'No sync result recorded yet. Run Sync weather now.'
    : 'Weather syncing is disabled. Existing weather history is kept.';
  $local = $sync ? json_decode($sync['LocalStatus'], true) : [];
  $local = is_array($local) ? $local : [];
  $recent = $sync && $now - (float)$sync['CollectedAt'] <= 10800 && $now >= (float)$sync['CollectedAt'] - 300;
  if ($enabled && $sync) {
    $status = $recent && $sync['OnlineStatus'] === 'ok' ? 'ok' : 'warn';
    $message = 'Last attempt: ' . date('Y-m-d H:i', (int)$sync['CollectedAt']) . '. ';
    $message .= $sync['OnlineStatus'] === 'pending' ? 'Online sync is running or did not finish.' : $sync['OnlineMessage'];
    if (!$recent) $message .= ' No recent sync result; check the weather service/cron and station clock.';
  }
  $checks = [[
    'id' => 'weather', 'label' => 'Weather sync', 'status' => $status, 'message' => $message,
    'action' => !$enabled || $status === 'ok' ? null : 'Run Sync weather now; local readings can work while Open-Meteo is unavailable.',
  ]];
  if ($enabled && (!empty($config['HA_TEMP_ENTITY']) || !empty($config['HA_WEATHER_ENTITY']))) {
    $messages = [];
    $accepted = [];
    foreach (['HA_TEMP_ENTITY' => 'Temperature sensor', 'HA_WEATHER_ENTITY' => 'Weather entity'] as $key => $label) {
      if (empty($config[$key])) continue;
      $entry = $local[$key] ?? [];
      if (isset($entry['entity']) && $entry['entity'] !== trim($config[$key])) {
        $messages[] = $label . ': Settings changed; run Sync weather now.';
        continue;
      }
      $messages[] = $label . ': ' . ($entry['message'] ?? 'Not checked yet; run Sync weather now.');
      $reported = $entry['reported_at'] ?? ($sync['CollectedAt'] ?? 0);
      if ($reported >= $now - 3600 && $reported <= $now + 300) {
        $accepted = array_merge($accepted, $entry['accepted'] ?? []);
      }
    }
    $local_recent = $sync && $now - (float)$sync['CollectedAt'] <= 3600 && $now >= (float)$sync['CollectedAt'] - 300;
    $ok = $local_recent && count($accepted) > 0;
    $checks[] = [
      'id' => 'local_sensor', 'label' => 'Local weather', 'status' => $ok ? 'ok' : 'warn',
      'message' => 'At the last sync: ' . implode(' ', $messages) . ($ok ? '' : ' No current accepted report for these settings.'),
      'action' => $ok ? null : 'Check the Home Assistant settings, then run Sync weather now. Available online weather covers missing fields.',
    ];
  }
  return $checks;
}

function weather_hour_display($row) {
  return [
    'temp' => display_temp($row['Temp']),
    'code' => weather_optional_int($row['ConditionCode']),
    'is_day' => weather_optional_int($row['IsDay']),
    'wind_speed' => display_wind($row['WindSpeed']),
    'wind_unit' => wind_unit_label(),
    'wind_direction' => $row['WindDirection'] === null ? null : (float)$row['WindDirection'],
    'sources' => [
      'temp' => $row['TempSource'], 'condition' => $row['ConditionCodeSource'],
      'wind_speed' => $row['WindSpeedSource'], 'wind_direction' => $row['WindDirectionSource'],
    ],
  ];
}
