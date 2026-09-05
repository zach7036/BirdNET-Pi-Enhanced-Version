<?php
// Test fixture: explicitly supplied temporary DB, read-only. Never opens birds.db.
if (PHP_SAPI !== 'cli' || $argc !== 5) exit(2);
require_once __DIR__ . '/../scripts/common.php';
date_default_timezone_set('UTC');
$db = new SQLite3($argv[1], SQLITE3_OPEN_READONLY);
$now = (int)$argv[2];
if ($argv[3] === 'config') {
  $input = json_decode($argv[4], true);
  try {
    $entity = weather_entity_setting($input['entity'] ?? null, $input['previous'] ?? '');
    echo json_encode(['contents' => weather_store_entity_setting($input['contents'], $entity)], JSON_THROW_ON_ERROR);
  } catch (InvalidArgumentException $e) {
    echo json_encode(['invalid' => true]);
  }
} elseif ($argv[3] === 'health') {
  echo json_encode(weather_health_checks($db, json_decode($argv[4], true), $now), JSON_THROW_ON_ERROR);
} else {
  $table = weather_data_table($db, $now);
  $sql = $argv[3] === 'join'
    ? "SELECT COUNT(*) AS total, SUM(w.WindSpeed) AS wind_total FROM detections d JOIN $table w ON d.Date=w.Date AND CAST(substr(d.Time,1,2) AS INTEGER)=w.Hour"
    : "SELECT * FROM $table ORDER BY Date, Hour";
  $result = $db->query($sql);
  $rows = [];
  while ($row = $result->fetchArray(SQLITE3_ASSOC)) $rows[] = $row;
  echo json_encode($rows, JSON_THROW_ON_ERROR);
}
$db->close();
