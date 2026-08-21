<?php

if (!defined('__ROOT__')) {
  define('__ROOT__', dirname(dirname(__FILE__)));
}
require_once(__ROOT__ . '/scripts/common.php');

$config = get_config();
set_timezone();
$requestUri = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
$requestMethod = $_SERVER['REQUEST_METHOD'];

$post_routes = ['#^/api/v1/reviews$#', '#^/api/v1/species/prefs$#', '#^/api/v1/notes$#'];
$is_post_route = false;
foreach ($post_routes as $post_pattern) {
  if (preg_match($post_pattern, $requestUri)) {
    $is_post_route = true;
    break;
  }
}
if ($requestMethod !== 'GET' && !($requestMethod === 'POST' && $is_post_route)) {
  sendResponse405();
}

$db = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// Reviewed false positives / hidden detections are excluded from curated
// analytics; raw streams (recent feed, timeline) stay unfiltered.
$fp_pred = review_exclusion_sql($db);
$fp_and = $fp_pred === '' ? '' : ' AND ' . $fp_pred;
$fp_where = $fp_pred === '' ? '' : ' WHERE ' . $fp_pred;

function api_json($data, $status = 200) {
  http_response_code($status);
  header('Content-Type: application/json');
  echo json_encode($data);
}

function api_error($message, $status = 400) {
  api_json(['status' => 'error', 'message' => $message], $status);
  exit;
}

function api_require_auth() {
  if (!is_authenticated()) {
    header('WWW-Authenticate: Basic realm="BirdNET-Pi"');
    api_error('Authentication required', 401);
  }
  // Lightweight CSRF defense: requiring a custom header forces a CORS
  // preflight, which a cross-origin page cannot complete. Basic auth alone
  // would not stop a forged same-network form post.
  $requested_with = isset($_SERVER['HTTP_X_REQUESTED_WITH']) ? $_SERVER['HTTP_X_REQUESTED_WITH'] : '';
  if (strcasecmp($requested_with, 'XMLHttpRequest') !== 0) {
    api_error('Missing X-Requested-With: XMLHttpRequest header', 403);
  }
}

function api_request_body() {
  $content_type = isset($_SERVER['CONTENT_TYPE']) ? $_SERVER['CONTENT_TYPE'] : '';
  if (stripos($content_type, 'application/json') !== false) {
    $data = json_decode(file_get_contents('php://input'), true);
    return is_array($data) ? $data : [];
  }
  return $_POST ?: [];
}

function api_send_csv($filename, $header_row, $rows) {
  header('Content-Type: text/csv; charset=utf-8');
  header('Content-Disposition: attachment; filename="' . $filename . '"');
  $out = fopen('php://output', 'w');
  fputcsv($out, $header_row);
  foreach ($rows as $row) {
    fputcsv($out, $row);
  }
  fclose($out);
  exit;
}

function api_open_rw_db() {
  $db_rw = new SQLite3(__ROOT__ . '/scripts/birds.db', SQLITE3_OPEN_READWRITE);
  $db_rw->busyTimeout(2000);
  ensure_spine_tables($db_rw);
  return $db_rw;
}

function api_current_weather($db) {
  $has_weather = db_query_single_safe($db, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'", 0, 'api current weather table') > 0;
  if (!$has_weather) {
    return ['status' => 'missing', 'message' => 'Weather table has not been created yet.'];
  }

  $has_is_day = false;
  $cols = db_query_safe($db, "PRAGMA table_info(weather)", 'api weather table info');
  while ($col = db_fetch_assoc_safe($cols)) {
    if ($col['name'] === 'IsDay') {
      $has_is_day = true;
      break;
    }
  }

  $sel = $has_is_day ? 'Date, Hour, Temp, ConditionCode, IsDay' : 'Date, Hour, Temp, ConditionCode';
  $stmt = $db->prepare("SELECT $sel FROM weather WHERE Date = DATE('now','localtime') AND Hour = :hour AND Temp IS NOT NULL LIMIT 1");
  $stmt->bindValue(':hour', (int)date('G'), SQLITE3_INTEGER);
  $current = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api current weather row'));
  $latest = db_query_one_safe($db, "SELECT Date, Hour FROM weather WHERE Temp IS NOT NULL ORDER BY Date DESC, Hour DESC LIMIT 1", 'api latest weather row');
  $today_rows = db_query_single_safe($db, "SELECT COUNT(*) FROM weather WHERE Date = DATE('now','localtime') AND Temp IS NOT NULL", 0, 'api current weather today rows') ?: 0;

  if (!$current) {
    return [
      'status' => 'missing',
      'today_rows' => (int)$today_rows,
      'last_synced_at' => $latest ? $latest['Date'] . ' ' . sprintf('%02d:00', (int)$latest['Hour']) : null,
      'message' => 'Current-hour weather is missing.'
    ];
  }

  $code = (int)$current['ConditionCode'];
  return [
    'status' => 'current',
    'date' => $current['Date'],
    'hour' => (int)$current['Hour'],
    'temp' => display_temp($current['Temp']),
    'temp_unit' => temp_unit_suffix(),
    'condition_code' => $code,
    'condition' => api_weather_label($code),
    'is_day' => $has_is_day ? (int)$current['IsDay'] : 1,
    'today_rows' => (int)$today_rows,
    'last_synced_at' => $current['Date'] . ' ' . sprintf('%02d:00', (int)$current['Hour']),
    'generated_at' => date('c')
  ];
}

function api_format_service($service) {
  $status = trim((string) shell_exec('systemctl is-active ' . escapeshellarg($service) . ' 2>/dev/null'));
  if ($status === '') {
    $status = 'unknown';
  }
  return [
    'name' => $service,
    'status' => $status,
    'ok' => $status === 'active'
  ];
}

function api_weather_label($code) {
  $codes = [
    0 => 'Clear', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
    45 => 'Fog', 48 => 'Rime fog', 51 => 'Light drizzle', 53 => 'Moderate drizzle',
    55 => 'Dense drizzle', 61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
    71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 80 => 'Slight showers',
    81 => 'Moderate showers', 82 => 'Violent showers', 95 => 'Thunderstorm'
  ];
  return $codes[$code] ?? 'Cloudy';
}

function api_ebird_export_count($db, $date, $min_confidence = 0.75) {
  // Must mirror get_ebird_export_rows() in history.php: reviewed false
  // positives and hidden detections are excluded from eBird exports.
  $review_exclusion = spine_table_exists($db, 'detection_reviews')
    ? "AND File_Name NOT IN (SELECT file_name FROM detection_reviews WHERE status IN ('false_positive', 'hidden'))"
    : "";
  $stmt = $db->prepare("
    SELECT COUNT(*) AS row_count, COALESCE(SUM(DetectionCount), 0) AS detection_count
    FROM (
      SELECT Com_Name, CAST(substr(Time, 1, 2) AS INTEGER) AS Hour, COUNT(*) AS DetectionCount
      FROM detections
      WHERE Date = :date
        AND Confidence > :min_confidence
        AND Time IS NOT NULL
        AND length(Time) >= 2
        $review_exclusion
      GROUP BY Com_Name, CAST(substr(Time, 1, 2) AS INTEGER)
    )
  ");
  $stmt->bindValue(':date', $date, SQLITE3_TEXT);
  $stmt->bindValue(':min_confidence', $min_confidence, SQLITE3_FLOAT);
  $row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api ebird export count'));
  return [
    'row_count' => (int)($row['row_count'] ?? 0),
    'detection_count' => (int)($row['detection_count'] ?? 0)
  ];
}

if (preg_match('#^/api/v1/system/health$#', $requestUri)) {
  $home = get_home();
  $db_path = __ROOT__ . '/scripts/birds.db';
  $last_detection = db_query_single_safe($db, 'SELECT Date || " " || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1', null, 'api system health last detection');
  $weather_count = db_query_single_safe($db, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'", 0, 'api system health weather table') ? db_query_single_safe($db, "SELECT COUNT(*) FROM weather WHERE Date = DATE('now','localtime')", 0, 'api system health weather rows') : 0;
  $disk_total = @disk_total_space($home);
  $disk_free = @disk_free_space($home);

  api_json([
    'services' => [
      'recording' => api_format_service('birdnet_recording.service'),
      'analysis' => api_format_service('birdnet_analysis.service')
    ],
    'disk' => [
      'total_bytes' => $disk_total ?: 0,
      'free_bytes' => $disk_free ?: 0,
      'used_percent' => ($disk_total && $disk_free) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : null
    ],
    'database' => [
      'path' => $db_path,
      'size_bytes' => file_exists($db_path) ? filesize($db_path) : 0
    ],
    'last_detection_at' => $last_detection ?: null,
    'weather_rows_today' => (int)$weather_count,
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/weather/current$#', $requestUri)) {
  api_json(api_current_weather($db));

} elseif (preg_match('#^/api/v1/species/list$#', $requestUri)) {
  $limit = request_int($_GET, 'limit', 50, 1, 100);
  $offset = request_int($_GET, 'offset', 0, 0, 1000000);
  $time_period = isset($_GET['time_period']) ? $_GET['time_period'] : 'all';
  $sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'detections';
  $search = isset($_GET['search']) ? trim($_GET['search']) : '';

  $where_clauses = [];
  if ($fp_pred !== '') {
    $where_clauses[] = $fp_pred;
  }
  if ($time_period !== 'all') {
    $periods = ['24h' => '-1 day', '7d' => '-7 days', '30d' => '-30 days', '90d' => '-90 days', '1y' => '-1 year'];
    if (isset($periods[$time_period])) {
      $where_clauses[] = "Date >= date('now', '" . $periods[$time_period] . "')";
    }
  }
  if ($search !== '') {
    $where_clauses[] = "(Com_Name LIKE :search OR Sci_Name LIKE :search)";
  }
  $where_sql = !empty($where_clauses) ? 'WHERE ' . implode(' AND ', $where_clauses) : '';

  $order_by = 'COUNT(*) DESC';
  if ($sort_by === 'sci_name') $order_by = 'Sci_Name ASC';
  elseif ($sort_by === 'com_name') $order_by = 'Com_Name ASC';
  elseif ($sort_by === 'confidence') $order_by = 'MAX(Confidence) DESC';

  $count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM (SELECT Sci_Name FROM detections $where_sql GROUP BY Sci_Name)");
  $list_stmt = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConf, MIN(Date) as FirstDate FROM detections $where_sql GROUP BY Sci_Name ORDER BY $order_by LIMIT :limit OFFSET :offset");
  if ($search !== '') {
    $count_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
    $list_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
  }
  $list_stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
  $list_stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
  $count_row = db_fetch_assoc_safe(db_execute_safe($db, $count_stmt, 'api species list count'));
  $total = (int)($count_row['total'] ?? 0);
  $result = db_execute_safe($db, $list_stmt, 'api species list');
  $items = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $info = get_info_url($row['Sci_Name']);
    $items[] = [
      'common_name' => $row['Com_Name'],
      'scientific_name' => $row['Sci_Name'],
      'detections' => (int)$row['Count'],
      'max_confidence' => round((float)$row['MaxConf'], 4),
      'first_detected' => $row['FirstDate'],
      'info_url' => $info['URL'],
      'info_title' => $info['TITLE'],
      'wikipedia_url' => get_wikipedia_url($row['Sci_Name'])
    ];
  }
  if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    $csv_rows = [];
    foreach ($items as $item) {
      $csv_rows[] = [$item['common_name'], $item['scientific_name'], $item['detections'], $item['max_confidence'], $item['first_detected']];
    }
    api_send_csv('species.csv', ['common_name', 'scientific_name', 'detections', 'max_confidence', 'first_detected'], $csv_rows);
  }

  api_json([
    'items' => $items,
    'count' => count($items),
    'limit' => $limit,
    'offset' => $offset,
    'next_offset' => $offset + count($items),
    'total' => $total,
    'has_more' => ($offset + count($items)) < $total
  ]);

} elseif (preg_match('#^/api/v1/exports/ebird/preview$#', $requestUri)) {
  $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
  $counts = api_ebird_export_count($db, $date);
  $warnings = [];
  $lat = $config['LATITUDE'] ?? '';
  $lon = $config['LONGITUDE'] ?? '';
  if ($counts['row_count'] === 0) {
    $warnings[] = 'No detections above 75% confidence were found for this date.';
  }
  if ($lat === '' || $lon === '' || $lat === '0.000' || $lon === '0.000') {
    $warnings[] = 'Latitude or longitude is missing from Settings.';
  }
  api_json([
    'date' => $date,
    'row_count' => $counts['row_count'],
    'detection_count' => $counts['detection_count'],
    'latitude' => $lat,
    'longitude' => $lon,
    'warnings' => $warnings,
    'ok' => empty($warnings)
  ]);

} elseif (preg_match('#^/api/v1/image/(\S+)$#', $requestUri, $matches)) {
  // make_image_provider returns [null, null] for provider "None": the station
  // promised no outbound image lookups, so the route 404s without contacting
  // Wikipedia the way an unguarded !== 'FLICKR' branch would.
  $result = false;
  list($image_provider, $fallback_provider) = make_image_provider($config);
  if ($image_provider) {
    $sci_name = urldecode($matches[1]);
    $result = $image_provider->get_image($sci_name, $fallback_provider);
  }

  if ($result == false) {
    http_response_code(404);
    echo "Error 404! No image found!";
  } else {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([
      "status" => "success",
      "message" => "successfully image data from database",
      "data" => $result
    ]);
  }
} elseif (preg_match('#^/api/v1/analytics/activity$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  $stmt = $db->prepare('SELECT strftime("%H", Time) as Hour, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Hour ORDER BY Hour ASC');
  $result = db_execute_safe($db, $stmt, 'api analytics activity');
  $data = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $data[$row['Hour']] = $row['Count'];
  }
  
  // Fill empty hours with 0
  $final_data = [];
  for ($i = 0; $i < 24; $i++) {
    $hourStr = str_pad($i, 2, '0', STR_PAD_LEFT);
    $final_data[] = ["hour" => $hourStr, "count" => isset($data[$hourStr]) ? $data[$hourStr] : 0];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($final_data);

} elseif (preg_match('#^/api/v1/analytics/stats$#', $requestUri)) {
  $days = request_int($_GET, 'days', 7, 1, 3650);
  
  // Total detections
  $stmt = $db->prepare('SELECT COUNT(*) as total FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and);
  $total_row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api analytics stats total'));
  $total = $total_row['total'] ?? 0;
  
  // Unique species
  $stmt = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) as unique_species FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and);
  $unique_row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api analytics stats unique'));
  $unique = $unique_row['unique_species'] ?? 0;
  
  // Avg confidence
  $stmt = $db->prepare('SELECT AVG(Confidence) as avg_conf FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and);
  $avg_conf_row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api analytics stats confidence'));
  $avg_conf = $avg_conf_row['avg_conf'] ?? 0;
  
  // Most common
  $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Sci_Name ORDER BY count DESC LIMIT 1');
  $most_common = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'api analytics stats most common'));

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode([
    "total_detections" => $total,
    "unique_species" => $unique,
    "avg_confidence" => round($avg_conf * 100, 1) . '%',
    "most_common" => $most_common ? $most_common['Com_Name'] : 'None',
    "most_common_count" => $most_common ? $most_common['count'] : 0,
    "days" => $days
  ]);

} elseif (preg_match('#^/api/v1/analytics/new_species$#', $requestUri)) {
  $days = request_int($_GET, 'days', 7, 1, 3650);
  
  // Find species whose FIRST detection was within the last N days
  $stmt = $db->prepare('SELECT Com_Name, Sci_Name, MIN(Date) as first_date, MIN(Time) as first_time FROM detections'.$fp_where.' GROUP BY Sci_Name HAVING first_date >= DATE("now", "-'.$days.' days") ORDER BY first_date DESC, first_time DESC');
  $result = db_execute_safe($db, $stmt, 'api analytics new species');
  $data = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $data[] = $row;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/analytics/diversity$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  $stmt = $db->prepare('SELECT Date, COUNT(DISTINCT(Sci_Name)) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Date ORDER BY Date ASC');
  $result = db_execute_safe($db, $stmt, 'api analytics diversity');
  
  $dates = [];
  $counts = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $dates[] = $row['Date'];
    $counts[] = $row['count'];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates, "counts" => $counts]);

} elseif (preg_match('#^/api/v1/analytics/detections$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  $stmt = $db->prepare('SELECT Date, COUNT(*) as count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Date ORDER BY Date ASC');
  $result = db_execute_safe($db, $stmt, 'api analytics detections');
  
  $dates = [];
  $counts = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $dates[] = $row['Date'];
    $counts[] = $row['count'];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates, "counts" => $counts]);

} elseif (preg_match('#^/api/v1/analytics/top_species$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  $limit = request_int($_GET, 'limit', 10, 1, 100);
  
  $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Com_Name ORDER BY Count DESC LIMIT '.$limit);
  $result = db_execute_safe($db, $stmt, 'api analytics top species');
  $data = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $data[] = ["species" => $row['Com_Name'], "count" => $row['Count']];
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/analytics/trends$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  // Get target species: either from GET param or default to top 5
  $target_species = [];
  if (isset($_GET['species']) && !empty($_GET['species'])) {
    $target_species = explode(',', $_GET['species']);
    // Limit to 5 to prevent performance issues and chart clutter
    if (count($target_species) > 5) {
      $target_species = array_slice($target_species, 0, 5);
    }
  } else {
    $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Com_Name ORDER BY Count DESC LIMIT 5');
    $result = db_execute_safe($db, $stmt, 'api analytics trends default species');
    while ($row = db_fetch_assoc_safe($result)) {
      $target_species[] = $row['Com_Name'];
    }
  }
  
  $data = [];
  $dates_array = [];
  
  for ($i = $days; $i >= 0; $i--) {
    $dates_array[] = date('Y-m-d', strtotime("-$i days"));
  }

  // Get daily counts for each target species
  foreach ($target_species as $species) {
    $stmt = $db->prepare('SELECT Date, COUNT(*) as Count FROM detections WHERE Com_Name = :com_name AND Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Date');
    $stmt->bindValue(':com_name', $species, SQLITE3_TEXT);
    $result = db_execute_safe($db, $stmt, 'api analytics trends species');
    
    $species_data = [];
    while ($row = db_fetch_assoc_safe($result)) {
      $species_data[$row['Date']] = $row['Count'];
    }
    
    // Fill empty dates with 0
    $final_species_data = [];
    foreach ($dates_array as $dateStr) {
      $final_species_data[] = isset($species_data[$dateStr]) ? $species_data[$dateStr] : 0;
    }
    
    $data[$species] = $final_species_data;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode(["dates" => $dates_array, "series" => $data]);

} elseif (preg_match('#^/api/v1/analytics/patterns$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  
  // Get target species: either from GET param or default to top 5
  $target_species = [];
  if (isset($_GET['species']) && !empty($_GET['species'])) {
    $target_species = explode(',', $_GET['species']);
    if (count($target_species) > 5) {
      $target_species = array_slice($target_species, 0, 5);
    }
  } else {
    $stmt = $db->prepare('SELECT Com_Name, COUNT(*) as Count FROM detections WHERE Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Com_Name ORDER BY Count DESC LIMIT 5');
    $result = db_execute_safe($db, $stmt, 'api analytics patterns default species');
    while ($row = db_fetch_assoc_safe($result)) {
      $target_species[] = $row['Com_Name'];
    }
  }
  
  $data = [];
  foreach ($target_species as $species) {
    $stmt = $db->prepare('SELECT strftime("%H", Time) as Hour, COUNT(*) as count FROM detections WHERE Com_Name = :com_name AND Date >= DATE("now", "-'.$days.' days")'.$fp_and.' GROUP BY Hour ORDER BY Hour ASC');
    $stmt->bindValue(':com_name', $species, SQLITE3_TEXT);
    $result = db_execute_safe($db, $stmt, 'api analytics patterns species');
    
    $hourly_counts = array_fill(0, 24, 0);
    while ($row = db_fetch_assoc_safe($result)) {
      $hourly_counts[intval($row['Hour'])] = (float)$row['count'] / $days; // Average detections per hour
    }
    $data[$species] = $hourly_counts;
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/detections/recent$#', $requestUri)) {
  $limit = request_int($_GET, 'limit', 20, 1, 100);
  $days = request_int($_GET, 'days', 0, 0, 3650);
  
  $date_filter = $days > 0 ? 'Date >= DATE("now", "-'.$days.' days")' : 'Date = DATE("now", "localtime")';
  $stmt = $db->prepare('SELECT Com_Name, Sci_Name, Confidence, Date, Time FROM detections WHERE '.$date_filter.' ORDER BY Date DESC, Time DESC LIMIT '.$limit);
  $result = db_execute_safe($db, $stmt, 'api recent detections');
  $data = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $data[] = [
      "species" => $row['Com_Name'],
      "sci_name" => $row['Sci_Name'],
      "confidence" => round((float)$row['Confidence'], 4),
      "date" => $row['Date'],
      "time" => $row['Time']
    ];
  }

  if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    $csv_rows = [];
    foreach ($data as $d) {
      $csv_rows[] = [$d['date'], $d['time'], $d['species'], $d['sci_name'], $d['confidence']];
    }
    api_send_csv('detections.csv', ['date', 'time', 'species', 'sci_name', 'confidence'], $csv_rows);
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/detections/timeline$#', $requestUri)) {
  $date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');

  // Visits are the shared clustering layer (common.php). Each visit is
  // listed under the hour it started in and may span hour boundaries.
  $visits = get_visits($db, ['date' => $date, 'include_detections' => true]);

  $total_detections = 0;
  $species_set = [];
  $hour_counts = array_fill(0, 24, 0);
  $hours_clusters = [];

  foreach ($visits as $v) {
    $total_detections += $v['count'];
    $species_set[$v['sci_name']] = true;
    foreach ($v['detections'] as $d) {
      $hour_counts[intval(substr($d['time'], 0, 2))]++;
    }
    $start_hour = intval(substr($v['first_time'], 0, 2));
    $hours_clusters[$start_hour][] = [
      'species' => $v['species'],
      'sci_name' => $v['sci_name'],
      'count' => $v['count'],
      'best_confidence' => $v['best_confidence'],
      'first_time' => $v['first_time'],
      'last_time' => $v['last_time'],
      'detections' => $v['detections']
    ];
  }

  // Find peak hour
  $peak_hour = 0;
  $peak_count = 0;
  foreach ($hour_counts as $h => $c) {
    if ($c > $peak_count) { $peak_count = $c; $peak_hour = $h; }
  }

  $hours_result = [];
  for ($h = 0; $h < 24; $h++) {
    if ($hour_counts[$h] === 0 && empty($hours_clusters[$h])) continue;
    $hours_result[] = [
      'hour' => $h,
      'detection_count' => $hour_counts[$h],
      'clusters' => isset($hours_clusters[$h]) ? $hours_clusters[$h] : []
    ];
  }

  // Hourly weather for the requested date (drawn as the Timeline weather strip)
  $weather_map = [];
  $has_weather_tbl = db_query_single_safe($db, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'", 0, 'timeline weather table') > 0;
  if ($has_weather_tbl) {
    $w_stmt = $db->prepare('SELECT * FROM weather WHERE Date = :date AND Temp IS NOT NULL ORDER BY Hour ASC');
    if ($w_stmt) {
      $w_stmt->bindValue(':date', $date, SQLITE3_TEXT);
      $w_res = db_execute_safe($db, $w_stmt, 'timeline weather rows');
      while ($w_row = db_fetch_assoc_safe($w_res)) {
        $weather_map[(int)$w_row['Hour']] = [
          'temp' => display_temp($w_row['Temp']),
          'code' => (int)$w_row['ConditionCode'],
          'is_day' => isset($w_row['IsDay']) ? (int)$w_row['IsDay'] : 1
        ];
      }
    }
  }

  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode([
    'date' => $date,
    'total_detections' => $total_detections,
    'total_species' => count($species_set),
    'peak_hour' => $peak_hour,
    'hours' => $hours_result,
    'weather' => $weather_map
  ]);

} elseif (preg_match('#^/api/v1/species/search$#', $requestUri)) {
  $query = isset($_GET['q']) ? trim($_GET['q']) : '';
  if (strlen($query) < 2) {
    http_response_code(200);
    header('Content-Type: application/json');
    echo json_encode([]);
    exit;
  }
  
  $stmt = $db->prepare('SELECT DISTINCT Com_Name as name, Sci_Name as sciName FROM detections WHERE Com_Name LIKE :query OR Sci_Name LIKE :query ORDER BY Com_Name ASC LIMIT 20');
  $stmt->bindValue(':query', '%' . $query . '%', SQLITE3_TEXT);
  $result = db_execute_safe($db, $stmt, 'api species search');
  
  $data = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $data[] = $row;
  }
  
  http_response_code(200);
  header('Content-Type: application/json');
  echo json_encode($data);

} elseif (preg_match('#^/api/v1/detections/visits$#', $requestUri)) {
  $options = [];
  if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $options['date'] = $_GET['date'];
  } elseif (isset($_GET['days'])) {
    $options['days'] = request_int($_GET, 'days', 1, 1, 90);
  }
  if (!empty($_GET['species'])) {
    $options['sci_name'] = $_GET['species'];
  }
  if (!empty($_GET['include_detections'])) {
    $options['include_detections'] = true;
  }
  $visits = get_visits($db, $options);

  $files = [];
  foreach ($visits as $v) {
    $files[] = $v['best_file'];
  }
  $review_map = get_review_map($db, $files);
  foreach ($visits as $i => $v) {
    $visits[$i]['review_status'] = isset($review_map[$v['best_file']]) ? $review_map[$v['best_file']] : null;
    // Server-computed so browser clock or timezone differences can't skew it
    $visits[$i]['seconds_ago'] = max(0, time() - strtotime($v['date'] . ' ' . $v['last_time']));
  }

  if (isset($_GET['format']) && $_GET['format'] === 'csv') {
    $csv_rows = [];
    foreach ($visits as $v) {
      $csv_rows[] = [$v['date'], $v['first_time'], $v['last_time'], $v['species'], $v['sci_name'], $v['count'], $v['best_confidence'], $v['best_file'], $v['review_status']];
    }
    api_send_csv('visits.csv', ['date', 'first_time', 'last_time', 'species', 'sci_name', 'detections', 'best_confidence', 'best_file', 'review_status'], $csv_rows);
  }

  api_json([
    'visits' => $visits,
    'count' => count($visits),
    'gap_seconds' => get_visit_gap_seconds(),
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/dashboard/now$#', $requestUri)) {
  $summary = get_summary();
  $visits_today = get_visits($db, []);

  // The hero never shows a visit whose best detection was reviewed away
  $hero_reviews = [];
  if (!empty($visits_today)) {
    $hero_files = array_map(function ($v) { return $v['best_file']; }, $visits_today);
    $hero_reviews = get_review_map($db, $hero_files);
  }
  $latest = null;
  foreach ($visits_today as $v) {
    $hero_status = isset($hero_reviews[$v['best_file']]) ? $hero_reviews[$v['best_file']] : null;
    if ($hero_status === 'false_positive' || $hero_status === 'hidden') {
      continue;
    }
    if ($latest === null || time_to_seconds($v['last_time']) >= time_to_seconds($latest['last_time'])) {
      $latest = $v;
    }
  }
  if ($latest !== null) {
    $first_stmt = $db->prepare('SELECT MIN(Date) AS first_seen FROM detections WHERE Sci_Name = :sci');
    $first_stmt->bindValue(':sci', $latest['sci_name'], SQLITE3_TEXT);
    $first_row = db_fetch_assoc_safe(db_execute_safe($db, $first_stmt, 'now first seen'));
    $latest['first_seen'] = $first_row ? $first_row['first_seen'] : null;
    $latest['is_new_lifetime'] = ($latest['first_seen'] === $latest['date']);
    $latest['visits_last_7_days'] = count(get_visits($db, ['days' => 7, 'sci_name' => $latest['sci_name']]));
    $latest['clip_path'] = detection_clip_relative_path($latest['date'], $latest['species'], $latest['best_file']);
    // Server-computed so browser clock or timezone differences can't skew it
    $latest['seconds_ago'] = max(0, time() - strtotime($latest['date'] . ' ' . $latest['last_time']));
    $latest['region_rare'] = is_region_rare($latest['sci_name'], $latest['date']);
  }

  $new_today = [];
  $new_res = db_query_safe($db, "SELECT Com_Name, Sci_Name, MIN(Time) AS first_time FROM detections WHERE Date = DATE('now','localtime')" . and_review_exclusion($db) . " AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now','localtime')) GROUP BY Sci_Name ORDER BY first_time DESC LIMIT 5", 'now new today');
  while ($row = db_fetch_assoc_safe($new_res)) {
    $new_today[] = ['species' => $row['Com_Name'], 'sci_name' => $row['Sci_Name'], 'first_time' => $row['first_time']];
  }

  // Visit-level review count: visits whose BEST detection is in the uncertain
  // band and not yet reviewed (matches what the Review queue actually shows).
  $uncertain_best_files = [];
  foreach ($visits_today as $v) {
    if ($v['best_confidence'] >= 0.60 && $v['best_confidence'] < 0.85) {
      $uncertain_best_files[$v['best_file']] = true;
    }
  }
  $review_worthy = 0;
  if (!empty($uncertain_best_files)) {
    $reviewed = get_review_map($db, array_keys($uncertain_best_files));
    foreach ($uncertain_best_files as $file => $unused) {
      if (!isset($reviewed[$file])) {
        $review_worthy++;
      }
    }
  }

  api_json([
    'latest_visit' => $latest,
    'today' => [
      'detections' => (int)$summary['todaycount'],
      'species' => (int)$summary['speciestally'],
      'new_species' => (int)$summary['newspeciestally'],
      'top_species' => $summary['topspecies'],
      'top_species_count' => (int)$summary['topspeciescount'],
      'last_hour' => (int)$summary['hourcount'],
      'visits' => count($visits_today)
    ],
    'lifetime' => [
      'detections' => (int)$summary['totalcount'],
      'species' => (int)$summary['totalspeciestally']
    ],
    'weather' => api_current_weather($db),
    'services' => [
      'recording' => api_format_service('birdnet_recording.service'),
      'analysis' => api_format_service('birdnet_analysis.service')
    ],
    'new_today' => $new_today,
    'review_worthy' => $review_worthy,
    'gap_seconds' => get_visit_gap_seconds(),
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/species/detail$#', $requestUri)) {
  $sci = isset($_GET['sci_name']) ? trim($_GET['sci_name']) : '';
  if ($sci === '') {
    api_error('sci_name is required');
  }

  $info_stmt = $db->prepare('SELECT Com_Name, COUNT(*) AS total, MIN(Date) AS first_seen, MAX(Date) AS last_seen, MAX(Confidence) AS best_confidence FROM detections WHERE Sci_Name = :sci');
  $info_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
  $info = db_fetch_assoc_safe(db_execute_safe($db, $info_stmt, 'species detail info'));
  if (!$info || !(int)$info['total']) {
    api_error('Species not found', 404);
  }


  $daily = [];
  $daily_stmt = $db->prepare("SELECT Date, COUNT(*) AS count FROM detections WHERE Sci_Name = :sci AND Date >= DATE('now', 'localtime', '-30 days') GROUP BY Date ORDER BY Date ASC");
  $daily_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
  $daily_res = db_execute_safe($db, $daily_stmt, 'species detail daily');
  while ($row = db_fetch_assoc_safe($daily_res)) {
    $daily[] = ['date' => $row['Date'], 'count' => (int)$row['count']];
  }

  $hourly = array_fill(0, 24, 0);
  $hourly_stmt = $db->prepare('SELECT CAST(strftime("%H", Time) AS INTEGER) AS hour, COUNT(*) AS count FROM detections WHERE Sci_Name = :sci GROUP BY hour');
  $hourly_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
  $hourly_res = db_execute_safe($db, $hourly_stmt, 'species detail hourly');
  while ($row = db_fetch_assoc_safe($hourly_res)) {
    $hourly[(int)$row['hour']] = (int)$row['count'];
  }

  // Daily counts for the past year (calendar heatmap on the Birds detail page)
  $calendar = [];
  $cal_stmt = $db->prepare("SELECT Date, COUNT(*) AS count FROM detections WHERE Sci_Name = :sci AND Date >= DATE('now','localtime','-365 days') GROUP BY Date ORDER BY Date ASC");
  $cal_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
  $cal_res = db_execute_safe($db, $cal_stmt, 'species detail calendar');
  while ($row = db_fetch_assoc_safe($cal_res)) {
    $calendar[$row['Date']] = (int)$row['count'];
  }

  $prefs = get_species_prefs_row($db, $sci);
  // Best recording, purged-best note and the best-confidence tile all come
  // from the one shared definition (pinned clip first, then best surviving).
  $best_info = species_best_recording($db, $sci, $prefs);

  $precision = null;
  $review_counts = [];
  if (spine_table_exists($db, 'detection_reviews')) {
    $rev_stmt = $db->prepare('SELECT status, COUNT(*) AS count FROM detection_reviews WHERE sci_name = :sci GROUP BY status');
    $rev_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
    $rev_res = db_execute_safe($db, $rev_stmt, 'species detail reviews');
    while ($row = db_fetch_assoc_safe($rev_res)) {
      $review_counts[$row['status']] = (int)$row['count'];
    }
    $confirmed = isset($review_counts['confirmed']) ? $review_counts['confirmed'] : 0;
    $rejected = isset($review_counts['false_positive']) ? $review_counts['false_positive'] : 0;
    if ($confirmed + $rejected >= 10) {
      $precision = round($confirmed / ($confirmed + $rejected), 3);
    }
  }

  $recent_visits = array_slice(get_visits($db, ['days' => 7, 'sci_name' => $sci]), -10);
  foreach ($recent_visits as $i => $v) {
    $recent_visits[$i]['clip_path'] = detection_clip_relative_path($v['date'], $v['species'], $v['best_file']);
  }

  $note_count = 0;
  if (spine_table_exists($db, 'notes')) {
    $note_count = (int) db_query_single_safe($db, "SELECT COUNT(*) FROM notes WHERE sci_name = '" . SQLite3::escapeString($sci) . "'", 0, 'species detail notes');
  }

  $info_url = get_info_url($sci);
  api_json([
    'common_name' => $info['Com_Name'],
    'scientific_name' => $sci,
    'total_detections' => (int)$info['total'],
    'first_seen' => $info['first_seen'],
    'last_seen' => $info['last_seen'],
    'best_confidence' => $best_info['best_confidence'] ?? round((float)$info['best_confidence'], 4),
    'best_recording' => $best_info['best_recording'],
    'purged_best' => $best_info['purged_best'],
    'daily_30d' => $daily,
    'hourly_pattern' => $hourly,
    'calendar' => $calendar,
    'prefs' => $prefs,
    'review_counts' => $review_counts,
    'precision' => $precision,
    'recent_visits' => $recent_visits,
    'note_count' => $note_count,
    'info_url' => $info_url['URL'],
    'info_title' => $info_url['TITLE'],
    'wikipedia_url' => get_wikipedia_url($sci),
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/analytics/bundle$#', $requestUri)) {
  $days = request_int($_GET, 'days', 30, 1, 3650);
  $bundle_key = birdnet_cache_key('analytics_bundle', $days, date('Y-m-d'), detections_watermark(), filemtime(__FILE__));
  $cached = birdnet_cache_get($bundle_key);
  if ($cached !== false) {
    http_response_code(200);
    header('Content-Type: application/json');
    header('X-BirdNET-Cache: hit');
    echo $cached;
    exit;
  }

  $stats_total = (int) db_query_single_safe($db, 'SELECT COUNT(*) FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and, 0, 'bundle total');
  $stats_unique = (int) db_query_single_safe($db, 'SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and, 0, 'bundle unique');
  $stats_avg = (float) db_query_single_safe($db, 'SELECT AVG(Confidence) FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and, 0, 'bundle avg conf');
  $most_common = db_query_one_safe($db, 'SELECT Com_Name, COUNT(*) as count FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and . ' GROUP BY Sci_Name ORDER BY count DESC LIMIT 1', 'bundle most common');

  $activity = array_fill(0, 24, 0);
  $act_res = db_query_safe($db, 'SELECT CAST(strftime("%H", Time) AS INTEGER) AS hour, COUNT(*) AS count FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and . ' GROUP BY hour', 'bundle activity');
  while ($row = db_fetch_assoc_safe($act_res)) {
    $activity[(int)$row['hour']] = (int)$row['count'];
  }

  $daily_dates = [];
  $daily_counts = [];
  $daily_res = db_query_safe($db, 'SELECT Date, COUNT(*) AS count FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and . ' GROUP BY Date ORDER BY Date ASC', 'bundle daily');
  while ($row = db_fetch_assoc_safe($daily_res)) {
    $daily_dates[] = $row['Date'];
    $daily_counts[] = (int)$row['count'];
  }

  $div_dates = [];
  $div_counts = [];
  $div_res = db_query_safe($db, 'SELECT Date, COUNT(DISTINCT(Sci_Name)) AS count FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and . ' GROUP BY Date ORDER BY Date ASC', 'bundle diversity');
  while ($row = db_fetch_assoc_safe($div_res)) {
    $div_dates[] = $row['Date'];
    $div_counts[] = (int)$row['count'];
  }

  $top = [];
  $top_res = db_query_safe($db, 'SELECT Com_Name, COUNT(*) AS count FROM detections WHERE Date >= DATE("now", "-' . $days . ' days")' . $fp_and . ' GROUP BY Com_Name ORDER BY count DESC LIMIT 10', 'bundle top');
  while ($row = db_fetch_assoc_safe($top_res)) {
    $top[] = ['species' => $row['Com_Name'], 'count' => (int)$row['count']];
  }

  $payload = json_encode([
    'days' => $days,
    'stats' => [
      'total_detections' => $stats_total,
      'unique_species' => $stats_unique,
      'avg_confidence' => round($stats_avg * 100, 1),
      'most_common' => $most_common ? $most_common['Com_Name'] : null,
      'most_common_count' => $most_common ? (int)$most_common['count'] : 0
    ],
    'activity_by_hour' => $activity,
    'daily' => ['dates' => $daily_dates, 'counts' => $daily_counts],
    'diversity' => ['dates' => $div_dates, 'counts' => $div_counts],
    'top_species' => $top,
    'generated_at' => date('c')
  ]);
  birdnet_cache_put($bundle_key, $payload);
  http_response_code(200);
  header('Content-Type: application/json');
  header('X-BirdNET-Cache: miss');
  echo $payload;

} elseif (preg_match('#^/api/v1/reviews/queue$#', $requestUri)) {
  $days = request_int($_GET, 'days', 7, 1, 30);
  $band_min = isset($_GET['band_min']) && is_numeric($_GET['band_min']) ? max(0, min(1, (float)$_GET['band_min'])) : 0.60;
  $band_max = isset($_GET['band_max']) && is_numeric($_GET['band_max']) ? max(0, min(1, (float)$_GET['band_max'])) : 0.85;
  $limit = request_int($_GET, 'limit', 50, 1, 200);
  $offset = request_int($_GET, 'offset', 0, 0, 100000);

  $visits = get_visits($db, ['days' => $days, 'include_detections' => true]);

  $all_files = [];
  foreach ($visits as $v) {
    foreach ($v['detections'] as $d) {
      $all_files[] = $d['file'];
    }
  }
  $review_map = get_review_map($db, $all_files);

  $first_seen_map = [];
  $lifetime_map = [];
  $fs_res = db_query_safe($db, 'SELECT Sci_Name, MIN(Date) AS first_seen, COUNT(*) AS lifetime FROM detections GROUP BY Sci_Name', 'queue first seen');
  while ($row = db_fetch_assoc_safe($fs_res)) {
    $first_seen_map[$row['Sci_Name']] = $row['first_seen'];
    $lifetime_map[$row['Sci_Name']] = (int)$row['lifetime'];
  }

  // Per-species precision from review history: the station learns which IDs
  // it can trust. n >= 10 decisions required before precision means anything.
  $precision_map = [];
  $review_stats = [];
  if (spine_table_exists($db, 'detection_reviews')) {
    $pr_res = db_query_safe($db, "SELECT sci_name, com_name,
        SUM(CASE WHEN status = 'confirmed' THEN 1 ELSE 0 END) AS confirmed,
        SUM(CASE WHEN status = 'false_positive' THEN 1 ELSE 0 END) AS rejected
      FROM detection_reviews GROUP BY sci_name", 'queue precision');
    while ($row = db_fetch_assoc_safe($pr_res)) {
      $n = (int)$row['confirmed'] + (int)$row['rejected'];
      $review_stats[$row['sci_name']] = [
        'com_name' => $row['com_name'],
        'confirmed' => (int)$row['confirmed'],
        'rejected' => (int)$row['rejected']
      ];
      if ($n >= 10) {
        $precision_map[$row['sci_name']] = (int)$row['confirmed'] / $n;
      }
    }
  }

  $queue = [];
  foreach ($visits as $v) {
    $unreviewed = 0;
    foreach ($v['detections'] as $d) {
      if (!isset($review_map[$d['file']])) {
        $unreviewed++;
      }
    }
    if ($unreviewed === 0) {
      continue;
    }
    $precision = isset($precision_map[$v['sci_name']]) ? $precision_map[$v['sci_name']] : null;
    $reasons = [];
    // Uncertainty is judged at the visit level: a visit whose BEST detection
    // is confident is not uncertain, even if weaker member detections exist.
    // Auto-trust: species this station has consistently confirmed (>= 95%
    // precision) skip the uncertainty routing - their track record speaks.
    if ($v['best_confidence'] >= $band_min && $v['best_confidence'] < $band_max && !isset($review_map[$v['best_file']])) {
      if ($precision === null || $precision < 0.95) {
        $reasons[] = 'uncertain';
      }
    }
    if (isset($first_seen_map[$v['sci_name']]) && $first_seen_map[$v['sci_name']] === $v['date']) {
      $reasons[] = 'first_lifetime';
    }
    if (is_region_rare($v['sci_name'], $v['date'])) {
      $reasons[] = 'region_rare';
    }
    if (!in_array('first_lifetime', $reasons, true)
        && isset($lifetime_map[$v['sci_name']]) && $lifetime_map[$v['sci_name']] <= YARD_RARE_LIFETIME_MAX) {
      $reasons[] = 'yard_rare';
    }
    // Auto-route: species this station usually rejects get reviewed even at
    // high confidence.
    if ($precision !== null && $precision <= 0.5) {
      $reasons[] = 'low_precision';
    }
    if (empty($reasons)) {
      continue;
    }
    // Member clip paths let the Review page reassign the whole visit
    // (one rename call per file via play.php's change-identification flow).
    $member_clips = [];
    foreach ($v['detections'] as $d) {
      $member_clips[] = detection_clip_relative_path($v['date'], $v['species'], $d['file']);
    }
    unset($v['detections']);
    $v['member_clips'] = $member_clips;
    $v['unreviewed_count'] = $unreviewed;
    $v['reasons'] = $reasons;
    $v['clip_path'] = detection_clip_relative_path($v['date'], $v['species'], $v['best_file']);
    $queue[] = $v;
  }

  usort($queue, function ($a, $b) {
    if ($a['date'] !== $b['date']) {
      return strcmp($b['date'], $a['date']);
    }
    return strcmp($b['last_time'], $a['last_time']);
  });

  $total = count($queue);
  $queue = array_slice($queue, $offset, $limit);

  // Exclude-list suggestions: species the reviewer rejects 80%+ of the time
  // (with enough decisions to mean it) probably should not be detected here.
  $suggestions = [];
  foreach ($review_stats as $sci => $stats) {
    $n = $stats['confirmed'] + $stats['rejected'];
    if ($n >= 10 && ($stats['rejected'] / $n) >= 0.8) {
      $suggestions[] = [
        'sci_name' => $sci,
        'com_name' => $stats['com_name'],
        'confirmed' => $stats['confirmed'],
        'rejected' => $stats['rejected'],
        'rejected_pct' => round(($stats['rejected'] / $n) * 100)
      ];
    }
  }

  api_json([
    'queue' => $queue,
    'count' => count($queue),
    'total' => $total,
    'offset' => $offset,
    'band' => ['min' => $band_min, 'max' => $band_max],
    'days' => $days,
    'suggestions' => $suggestions,
    'generated_at' => date('c')
  ]);

} elseif (preg_match('#^/api/v1/reviews/examples$#', $requestUri)) {
  // "Verify by comparison": known-good clips of the same species from this
  // station. Hybrid slot filling - 3 slots, in priority order:
  //   1. clips the user confirmed at >=80% confidence (verified AND clean),
  //   2. the station's strongest unverified matches (>=90%),
  //   3. weaker confirmed clips.
  // A new species starts as all strongest-matches; once three strong
  // confirmed clips exist the strip is entirely the user's own verified
  // audio and the model's opinion drops out.
  $sci = isset($_GET['q']) ? trim($_GET['q']) : (isset($_GET['sci_name']) ? trim($_GET['sci_name']) : '');
  if ($sci === '') {
    api_error('sci_name is required');
  }
  $exclude = isset($_GET['exclude']) ? trim($_GET['exclude']) : '';
  /* Exclude the whole visit under review, not just its best clip: every
     detection in a visit shares one acoustic event (the same individual,
     the same background, the same distant mimic), so its errors are
     correlated - a mockingbird doing a ten-minute Nighthawk impression
     must not be "verified" against its own visit-mates. Clips from other
     visits are independent samples of the model's judgment. Living in the
     SQL WHERE (not a post-filter) so the LIMITed candidate scans reach
     past even a marathon 100-detection visit instead of starving. */
  $x_date = isset($_GET['exclude_date']) ? trim($_GET['exclude_date']) : '';
  $x_from = isset($_GET['exclude_from']) ? trim($_GET['exclude_from']) : '';
  $x_to = isset($_GET['exclude_to']) ? trim($_GET['exclude_to']) : '';
  $visit_excl = ($x_date !== '' && $x_from !== '' && $x_to !== '');
  $visit_excl_for = function ($prefix) use ($visit_excl) {
    return $visit_excl
      ? " AND NOT ({$prefix}Date = :xdate AND {$prefix}Time >= :xfrom AND {$prefix}Time <= :xto)"
      : '';
  };
  $bind_visit = function ($stmt) use ($visit_excl, $x_date, $x_from, $x_to) {
    if ($visit_excl) {
      $stmt->bindValue(':xdate', $x_date, SQLITE3_TEXT);
      $stmt->bindValue(':xfrom', $x_from, SQLITE3_TEXT);
      $stmt->bindValue(':xto', $x_to, SQLITE3_TEXT);
    }
  };
  $examples = [];
  $seen = [];

  /* Old clips get purged as the disk fills, but the DB rows remain - so the
     strongest matches on record often no longer exist on disk. Offering those
     renders an empty comparison strip. Only return examples whose audio file
     is still present. When the By_Date dir itself is absent (dev harness),
     existence can't be checked, so skip the filter. */
  $clip_check = is_dir(clip_base_dir());
  $clip_exists = function ($rel) use ($clip_check) {
    return !$clip_check || clip_exists($rel);
  };

  $confirmed_strong = [];
  $confirmed_weak = [];
  if (spine_table_exists($db, 'detection_reviews')) {
    $ex_stmt = $db->prepare("SELECT r.file_name, r.date, r.com_name, d.Confidence
      FROM detection_reviews r JOIN detections d ON d.File_Name = r.file_name
      WHERE r.sci_name = :sci AND r.status = 'confirmed' AND r.file_name != :exclude" . $visit_excl_for('d.') . "
      ORDER BY d.Confidence DESC LIMIT 24");
    if ($ex_stmt) {
      $ex_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
      $ex_stmt->bindValue(':exclude', $exclude, SQLITE3_TEXT);
      $bind_visit($ex_stmt);
      $ex_res = db_execute_safe($db, $ex_stmt, 'review examples confirmed');
      while ((count($confirmed_strong) + count($confirmed_weak)) < 6 && ($row = db_fetch_assoc_safe($ex_res))) {
        $rel = detection_clip_relative_path($row['date'], $row['com_name'], $row['file_name']);
        if (!$clip_exists($rel)) {
          continue;
        }
        $seen[$row['file_name']] = true;
        $entry = [
          'file' => $row['file_name'],
          'clip_path' => $rel,
          'confidence' => round((float)$row['Confidence'], 4),
          'source' => 'confirmed'
        ];
        if ((float)$row['Confidence'] >= 0.8) {
          $confirmed_strong[] = $entry;
        } else {
          $confirmed_weak[] = $entry;
        }
      }
    }
  }

  // Slot 1: strong confirmed clips
  $examples = array_slice($confirmed_strong, 0, 3);

  // Slot 2: strongest unverified matches fill what remains. Scan the most
  // RECENT high-confidence detections, not the all-time strongest: on a
  // long-running station the all-time top clips are months old and purged
  // from disk, so an all-time scan finds nothing that still exists. Recent
  // candidates survive; rank the survivors by confidence.
  if (count($examples) < 3) {
    $fb_stmt = $db->prepare('SELECT File_Name, Date, Com_Name, Confidence FROM detections
      WHERE Sci_Name = :sci AND File_Name != :exclude AND Confidence >= 0.9' . $visit_excl_for('') . '
      ORDER BY Date DESC, Time DESC LIMIT 60');
    if ($fb_stmt) {
      $fb_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
      $fb_stmt->bindValue(':exclude', $exclude, SQLITE3_TEXT);
      $bind_visit($fb_stmt);
      $fb_res = db_execute_safe($db, $fb_stmt, 'review examples fallback');
      $candidates = [];
      while ($row = db_fetch_assoc_safe($fb_res)) {
        if (isset($seen[$row['File_Name']])) {
          continue;
        }
        $rel = detection_clip_relative_path($row['Date'], $row['Com_Name'], $row['File_Name']);
        if (!$clip_exists($rel)) {
          continue;
        }
        $candidates[] = [
          'file' => $row['File_Name'],
          'clip_path' => $rel,
          'confidence' => round((float)$row['Confidence'], 4),
          'source' => 'high_confidence'
        ];
      }
      usort($candidates, function ($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
      });
      foreach ($candidates as $entry) {
        if (count($examples) >= 3) {
          break;
        }
        $examples[] = $entry;
      }
    }
  }

  // Slot 3: weaker confirmed clips - still ground truth, just fainter audio
  foreach ($confirmed_weak as $entry) {
    if (count($examples) >= 3) {
      break;
    }
    $examples[] = $entry;
  }

  /* Recent detections are the most likely to still be on disk; if nothing
     high-confidence survived the purge, fall back to the best recent clips
     so the strip is never needlessly empty. */
  if (count($examples) === 0 && $clip_check) {
    $rc_stmt = $db->prepare('SELECT File_Name, Date, Com_Name, Confidence FROM detections
      WHERE Sci_Name = :sci AND File_Name != :exclude' . $visit_excl_for('') . '
      ORDER BY Date DESC, Time DESC LIMIT 40');
    if ($rc_stmt) {
      $rc_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
      $rc_stmt->bindValue(':exclude', $exclude, SQLITE3_TEXT);
      $bind_visit($rc_stmt);
      $rc_res = db_execute_safe($db, $rc_stmt, 'review examples recent');
      $recent = [];
      while ($row = db_fetch_assoc_safe($rc_res)) {
        $rel = detection_clip_relative_path($row['Date'], $row['Com_Name'], $row['File_Name']);
        if (!$clip_exists($rel)) {
          continue;
        }
        $recent[] = [
          'file' => $row['File_Name'],
          'clip_path' => $rel,
          'confidence' => round((float)$row['Confidence'], 4),
          'source' => 'high_confidence'
        ];
      }
      usort($recent, function ($a, $b) {
        return $b['confidence'] <=> $a['confidence'];
      });
      $examples = array_slice($recent, 0, 3);
    }
  }

  api_json(['examples' => $examples, 'count' => count($examples)]);

} elseif (preg_match('#^/api/v1/station/doctor$#', $requestUri)) {
  $home = get_home();
  $checks = [];

  foreach ([['recording', 'birdnet_recording.service', 'Recording'], ['analysis', 'birdnet_analysis.service', 'Analysis']] as $svc) {
    $service = api_format_service($svc[1]);
    $svc_status = $service['ok'] ? 'ok' : ($service['status'] === 'unknown' ? 'warn' : 'error');
    $checks[] = [
      'id' => $svc[0] . '_service',
      'label' => $svc[2] . ' service',
      'status' => $svc_status,
      'message' => $service['ok'] ? $svc[2] . ' is running.' : $svc[2] . ' service is ' . $service['status'] . '.',
      'action' => $service['ok'] ? null : 'Restart it from Tools > Services.'
    ];
  }

  // Live stream is a feature rather than core capture, so problems are warn-level
  $livestream = api_format_service('livestream.service');
  $icecast = api_format_service('icecast2.service');
  $stream_ok = $livestream['ok'] && $icecast['ok'];
  $checks[] = [
    'id' => 'livestream',
    'label' => 'Live stream',
    'status' => $stream_ok ? 'ok' : 'warn',
    'message' => $stream_ok
      ? 'Live audio stream is running.'
      : 'Live stream: livestream.service is ' . $livestream['status'] . ', icecast2 is ' . $icecast['status'] . '.',
    'action' => $stream_ok ? null : 'Use "Restart livestream" in the Station Doctor quick fixes.'
  ];

  $disk_total = @disk_total_space($home);
  $disk_free = @disk_free_space($home);
  $used_percent = ($disk_total && $disk_free) ? round((($disk_total - $disk_free) / $disk_total) * 100, 1) : null;
  $purge_threshold = isset($config['PURGE_THRESHOLD']) && is_numeric($config['PURGE_THRESHOLD']) ? (float)$config['PURGE_THRESHOLD'] : 95;
  $disk_status = 'ok';
  if ($used_percent === null) {
    $disk_status = 'warn';
  } elseif ($used_percent >= $purge_threshold) {
    $disk_status = 'error';
  } elseif ($used_percent >= 80) {
    $disk_status = 'warn';
  }
  $checks[] = [
    'id' => 'disk',
    'label' => 'Disk space',
    'status' => $disk_status,
    'message' => $used_percent === null ? 'Could not determine disk usage.' : 'Disk is ' . $used_percent . '% full (purge threshold ' . $purge_threshold . '%).',
    'action' => $disk_status === 'ok' ? null : 'Review old recordings or adjust retention settings.'
  ];

  $last_detection = db_query_single_safe($db, 'SELECT Date || " " || Time FROM detections ORDER BY Date DESC, Time DESC LIMIT 1', null, 'doctor last detection');
  $age_hours = $last_detection ? round((time() - strtotime($last_detection)) / 3600, 1) : null;
  $det_status = 'ok';
  if ($last_detection === null || ($age_hours !== null && $age_hours > 6)) {
    $det_status = 'warn';
  }
  $checks[] = [
    'id' => 'last_detection',
    'label' => 'Last detection',
    'status' => $det_status,
    'message' => $last_detection ? 'Last detection ' . $age_hours . 'h ago (' . $last_detection . ').' : 'No detections recorded yet.',
    'action' => $det_status === 'ok' ? null : 'Quiet periods are normal at night; if this persists in daytime, check the microphone and services.'
  ];

  $has_weather_table = db_query_single_safe($db, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'", 0, 'doctor weather table') > 0;
  $weather_status = 'warn';
  $weather_message = 'Weather table has not been created yet.';
  if ($has_weather_table) {
    $latest_weather = db_query_one_safe($db, 'SELECT Date, Hour FROM weather WHERE Temp IS NOT NULL ORDER BY Date DESC, Hour DESC LIMIT 1', 'doctor weather');
    if ($latest_weather) {
      $weather_age = round((time() - strtotime($latest_weather['Date'] . ' ' . sprintf('%02d:00', (int)$latest_weather['Hour']))) / 3600, 1);
      $weather_status = $weather_age <= 3 ? 'ok' : 'warn';
      $weather_message = 'Latest weather data is ' . $weather_age . 'h old.';
    } else {
      $weather_message = 'No weather rows synced yet.';
    }
  }
  $checks[] = [
    'id' => 'weather',
    'label' => 'Weather sync',
    'status' => $weather_status,
    'message' => $weather_message,
    'action' => $weather_status === 'ok' ? null : 'Weather syncs hourly; check internet connectivity if it stays stale.'
  ];

  // Local temperature sensor (only checked when configured): a live probe
  // showing whether the current hour is using the sensor or the online
  // fallback. The fallback working is a warn, never an error.
  $ha_url = rtrim(trim((string)($config['HA_URL'] ?? '')), '/');
  $ha_token = trim((string)($config['HA_TOKEN'] ?? ''));
  $ha_entity = trim((string)($config['HA_TEMP_ENTITY'] ?? ''));
  if ($ha_url !== '' && $ha_token !== '' && $ha_entity !== '') {
    $ha_status = 'warn';
    $ha_message = 'Sensor could not be reached; the current hour uses online weather.';
    $ctx = stream_context_create(['http' => [
      'method' => 'GET',
      'header' => "Authorization: Bearer $ha_token\r\nAccept: application/json\r\n",
      'timeout' => 5,
      'ignore_errors' => true
    ]]);
    $ha_raw = @file_get_contents($ha_url . '/api/states/' . rawurlencode($ha_entity), false, $ctx);
    if ($ha_raw !== false) {
      $ha_state = json_decode($ha_raw, true);
      $raw_val = isset($ha_state['state']) ? $ha_state['state'] : null;
      if ($raw_val !== null && $raw_val !== 'unavailable' && $raw_val !== 'unknown' && is_numeric($raw_val)) {
        $changed = isset($ha_state['last_changed']) ? strtotime($ha_state['last_changed']) : false;
        $age = $changed !== false ? time() - $changed : PHP_INT_MAX;
        $unit = isset($ha_state['attributes']['unit_of_measurement']) ? $ha_state['attributes']['unit_of_measurement'] : '';
        if ($age <= 3600) {
          $ha_status = 'ok';
          $ha_message = "$ha_entity reads $raw_val$unit (updated " . round($age / 60) . " min ago); the current hour uses your sensor.";
        } else {
          $ha_message = "$ha_entity has not changed in " . round($age / 60) . " min; the current hour uses online weather.";
        }
      } else {
        $ha_message = "$ha_entity is " . ($raw_val === null ? 'missing' : $raw_val) . '; the current hour uses online weather.';
      }
    }
    $checks[] = [
      'id' => 'local_sensor',
      'label' => 'Local temperature sensor',
      'status' => $ha_status,
      'message' => $ha_message,
      'action' => $ha_status === 'ok' ? null : 'Check the Home Assistant URL, token, and entity in Tools > Settings. Online weather covers the gap meanwhile.'
    ];
  }

  $lat = isset($config['LATITUDE']) ? $config['LATITUDE'] : '';
  $lon = isset($config['LONGITUDE']) ? $config['LONGITUDE'] : '';
  $loc_ok = $lat !== '' && $lon !== '' && $lat !== '0.000' && $lon !== '0.000';
  $checks[] = [
    'id' => 'location',
    'label' => 'Location',
    'status' => $loc_ok ? 'ok' : 'error',
    'message' => $loc_ok ? 'Latitude and longitude are set.' : 'Latitude/longitude are not set; species range filtering cannot work.',
    'action' => $loc_ok ? null : 'Set them in Tools > Settings.'
  ];

  $pwd_ok = !empty($config['CADDY_PWD']);
  $checks[] = [
    'id' => 'password',
    'label' => 'Admin password',
    'status' => $pwd_ok ? 'ok' : 'warn',
    'message' => $pwd_ok ? 'An admin password is set.' : 'No admin password is set; anyone on your network can change settings.',
    'action' => $pwd_ok ? null : 'Set one in Tools > Settings > Advanced Settings.'
  ];

  $overall = 'ok';
  foreach ($checks as $c) {
    if ($c['status'] === 'error') {
      $overall = 'error';
      break;
    }
    if ($c['status'] === 'warn') {
      $overall = 'warn';
    }
  }

  api_json(['status' => $overall, 'checks' => $checks, 'generated_at' => date('c')]);

} elseif (preg_match('#^/api/v1/reviews$#', $requestUri) && $requestMethod === 'POST') {
  api_require_auth();
  $body = api_request_body();
  $review_status = isset($body['status']) ? $body['status'] : '';
  $valid_statuses = ['confirmed', 'false_positive', 'hidden', 'unsure', 'clear'];
  if (!in_array($review_status, $valid_statuses, true)) {
    api_error('status must be one of: ' . implode(', ', $valid_statuses));
  }
  $note = isset($body['note']) ? trim((string)$body['note']) : null;
  if ($note !== null && mb_strlen($note) > 2000) {
    api_error('note too long (max 2000 characters)');
  }

  $targets = [];
  $via = 'single';
  if (!empty($body['file_name'])) {
    $stmt = $db->prepare('SELECT File_Name, Sci_Name, Com_Name, Date, Time FROM detections WHERE File_Name = :f LIMIT 1');
    $stmt->bindValue(':f', $body['file_name'], SQLITE3_TEXT);
    $row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'review target file'));
    if (!$row) {
      api_error('Detection not found', 404);
    }
    $targets[] = $row;
  } elseif (!empty($body['visit']) && is_array($body['visit'])) {
    $vw = $body['visit'];
    foreach (['sci_name', 'date', 'from_time', 'to_time'] as $k) {
      if (empty($vw[$k])) {
        api_error('visit.' . $k . ' is required');
      }
    }
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $vw['date'])) {
      api_error('visit.date must be YYYY-MM-DD');
    }
    $stmt = $db->prepare('SELECT File_Name, Sci_Name, Com_Name, Date, Time FROM detections WHERE Sci_Name = :sci AND Date = :d AND Time >= :from AND Time <= :to ORDER BY Time ASC');
    $stmt->bindValue(':sci', $vw['sci_name'], SQLITE3_TEXT);
    $stmt->bindValue(':d', $vw['date'], SQLITE3_TEXT);
    $stmt->bindValue(':from', $vw['from_time'], SQLITE3_TEXT);
    $stmt->bindValue(':to', $vw['to_time'], SQLITE3_TEXT);
    $result = db_execute_safe($db, $stmt, 'review target visit');
    while ($row = db_fetch_assoc_safe($result)) {
      $targets[] = $row;
    }
    if (empty($targets)) {
      api_error('No detections found in that visit window', 404);
    }
    $via = 'visit';
  } else {
    api_error('Provide file_name or visit {sci_name, date, from_time, to_time}');
  }

  $db_rw = api_open_rw_db();
  $affected = 0;
  if ($review_status === 'clear') {
    foreach ($targets as $t) {
      $del = $db_rw->prepare('DELETE FROM detection_reviews WHERE file_name = :f');
      $del->bindValue(':f', $t['File_Name'], SQLITE3_TEXT);
      db_execute_safe($db_rw, $del, 'review clear');
      $affected += $db_rw->changes();
    }
  } else {
    $db_rw->exec('BEGIN');
    foreach ($targets as $t) {
      $ins = $db_rw->prepare("INSERT INTO detection_reviews (file_name, sci_name, com_name, date, time, status, reviewed_via, note)
        VALUES (:f, :sci, :com, :d, :t, :s, :via, :n)
        ON CONFLICT(file_name) DO UPDATE SET status = :s, reviewed_via = :via, note = :n, created_at = datetime('now','localtime')");
      $ins->bindValue(':f', $t['File_Name'], SQLITE3_TEXT);
      $ins->bindValue(':sci', $t['Sci_Name'], SQLITE3_TEXT);
      $ins->bindValue(':com', $t['Com_Name'], SQLITE3_TEXT);
      $ins->bindValue(':d', $t['Date'], SQLITE3_TEXT);
      $ins->bindValue(':t', $t['Time'], SQLITE3_TEXT);
      $ins->bindValue(':s', $review_status, SQLITE3_TEXT);
      $ins->bindValue(':via', $via, SQLITE3_TEXT);
      if ($note === null || $note === '') {
        $ins->bindValue(':n', null, SQLITE3_NULL);
      } else {
        $ins->bindValue(':n', $note, SQLITE3_TEXT);
      }
      db_execute_safe($db_rw, $ins, 'review upsert');
      $affected++;
    }
    $db_rw->exec('COMMIT');
  }
  $db_rw->close();
  api_json(['status' => 'ok', 'affected' => $affected, 'review_status' => $review_status, 'via' => $via]);

} elseif (preg_match('#^/api/v1/species/prefs$#', $requestUri) && $requestMethod === 'POST') {
  api_require_auth();
  $body = api_request_body();
  $sci = isset($body['sci_name']) ? trim($body['sci_name']) : '';
  if ($sci === '') {
    api_error('sci_name is required');
  }
  $stmt = $db->prepare('SELECT Com_Name FROM detections WHERE Sci_Name = :sci LIMIT 1');
  $stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
  $species_row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'prefs species lookup'));
  if (!$species_row) {
    api_error('Species not found in detections', 404);
  }
  $com = $species_row['Com_Name'];

  $db_rw = api_open_rw_db();
  $existing = get_species_prefs_row($db_rw, $sci) ?: [
    'favorite' => 0, 'muted' => 0, 'notify_mode' => 'default', 'custom_threshold' => null, 'crowned_clip' => null
  ];

  $favorite = isset($body['favorite']) ? (int)(bool)$body['favorite'] : (int)$existing['favorite'];
  $muted = isset($body['muted']) ? (int)(bool)$body['muted'] : (int)$existing['muted'];

  $notify_mode = $existing['notify_mode'];
  if (isset($body['notify_mode'])) {
    $valid_modes = ['default', 'every_visit', 'first_daily', 'first_lifetime', 'rare_only', 'never'];
    if (!in_array($body['notify_mode'], $valid_modes, true)) {
      api_error('notify_mode must be one of: ' . implode(', ', $valid_modes));
    }
    $notify_mode = $body['notify_mode'];
  }

  $threshold = array_key_exists('custom_threshold', $body)
    ? (($body['custom_threshold'] === null || $body['custom_threshold'] === '') ? null : (float)$body['custom_threshold'])
    : ($existing['custom_threshold'] !== null ? (float)$existing['custom_threshold'] : null);
  if ($threshold !== null && ($threshold < 0 || $threshold > 1)) {
    api_error('custom_threshold must be between 0 and 1');
  }

  // Pins live in the database only. update_purge_protection.php writes them
  // into the managed section of disk_check_exclude.txt before every cleanup,
  // so nothing is written to that file here - and nothing is reported as
  // saved until the row actually landed (see the upsert check below).
  $crowned = $existing['crowned_clip'];
  $pin_lock = null;
  if (array_key_exists('crowned_clip', $body)) {
    // Cleanup holds this same lock from snapshot generation through deletion.
    // Take it before changing the database so the next cleanup snapshot must
    // see the new Pin (or this request asks the user to retry).
    $pin_lock = purge_lock_acquire();
    if ($pin_lock === false) {
      $db_rw->close();
      api_error('Disk cleanup is running - try the Pin change again in a moment.', 503);
    }
    $new_crown = trim((string)($body['crowned_clip'] === null ? '' : $body['crowned_clip']));
    if ($new_crown === '') {
      $crowned = null;
    } else {
      $clip_stmt = $db->prepare('SELECT Date, Com_Name FROM detections WHERE File_Name = :f AND Sci_Name = :sci LIMIT 1');
      if ($clip_stmt === false) {
        purge_lock_release($pin_lock);
        $db_rw->close();
        api_error('Could not validate crowned_clip - the station database is busy, try again in a moment.', 503);
      }
      $clip_stmt->bindValue(':f', $new_crown, SQLITE3_TEXT);
      $clip_stmt->bindValue(':sci', $sci, SQLITE3_TEXT);
      $clip_result = db_execute_safe($db, $clip_stmt, 'crown clip lookup');
      if ($clip_result === false) {
        purge_lock_release($pin_lock);
        $db_rw->close();
        api_error('Could not validate crowned_clip - the station database is busy, try again in a moment.', 503);
      }
      $clip = db_fetch_assoc_safe($clip_result);
      // Release the read statement's SHARED lock now: kept open, it would
      // block the upsert on the separate read-write connection below
      // (rollback-journal mode) until busyTimeout expires - "database is locked".
      $clip_result->finalize();
      $clip_stmt->close();
      if (!$clip) {
        purge_lock_release($pin_lock);
        $db_rw->close();
        api_error('crowned_clip not found for this species', 404);
      }
      $crowned = $new_crown;
    }
  }

  $up = $db_rw->prepare("INSERT INTO species_prefs (sci_name, com_name, favorite, muted, notify_mode, custom_threshold, crowned_clip, updated_at)
    VALUES (:sci, :com, :fav, :mut, :nm, :th, :crown, datetime('now','localtime'))
    ON CONFLICT(sci_name) DO UPDATE SET com_name = :com, favorite = :fav, muted = :mut, notify_mode = :nm, custom_threshold = :th, crowned_clip = :crown, updated_at = datetime('now','localtime')");
  if ($up === false) {
    purge_lock_release($pin_lock);
    $db_rw->close();
    api_error('Could not save preferences - the station database is busy, try again in a moment.', 503);
  }
  $up->bindValue(':sci', $sci, SQLITE3_TEXT);
  $up->bindValue(':com', $com, SQLITE3_TEXT);
  $up->bindValue(':fav', $favorite, SQLITE3_INTEGER);
  $up->bindValue(':mut', $muted, SQLITE3_INTEGER);
  $up->bindValue(':nm', $notify_mode, SQLITE3_TEXT);
  if ($threshold === null) {
    $up->bindValue(':th', null, SQLITE3_NULL);
  } else {
    $up->bindValue(':th', $threshold, SQLITE3_FLOAT);
  }
  if ($crowned === null) {
    $up->bindValue(':crown', null, SQLITE3_NULL);
  } else {
    $up->bindValue(':crown', $crowned, SQLITE3_TEXT);
  }
  if (db_execute_safe($db_rw, $up, 'prefs upsert') === false) {
    purge_lock_release($pin_lock);
    $db_rw->close();
    api_error('Could not save preferences - the station database is busy, try again in a moment.', 503);
  }
  $db_rw->close();
  purge_lock_release($pin_lock);

  api_json([
    'status' => 'ok',
    'prefs' => [
      'sci_name' => $sci,
      'com_name' => $com,
      'favorite' => $favorite,
      'muted' => $muted,
      'notify_mode' => $notify_mode,
      'custom_threshold' => $threshold,
      'crowned_clip' => $crowned
    ],
    // Kept for API compatibility: a pin recorded here is protected from the
    // next cleanup run onward.
    'crown_protected' => $crowned !== null,
    // The recomputed best-recording block, so a pin/unpin can re-render
    // without re-running the whole species/detail handler.
    'best' => species_best_recording($db, $sci, ['crowned_clip' => $crowned])
  ]);

} elseif (preg_match('#^/api/v1/notes$#', $requestUri) && $requestMethod === 'POST') {
  api_require_auth();
  $body = api_request_body();
  $action = isset($body['action']) ? $body['action'] : 'create';

  $db_rw = api_open_rw_db();
  if ($action === 'delete') {
    $id = isset($body['id']) ? (int)$body['id'] : 0;
    if ($id <= 0) {
      api_error('id is required for delete');
    }
    $del = $db_rw->prepare('DELETE FROM notes WHERE id = :id');
    $del->bindValue(':id', $id, SQLITE3_INTEGER);
    db_execute_safe($db_rw, $del, 'note delete');
    $deleted = $db_rw->changes();
    $db_rw->close();
    api_json(['status' => 'ok', 'deleted' => $deleted]);
    exit;
  }

  $text = isset($body['body']) ? trim((string)$body['body']) : '';
  if ($text === '') {
    api_error('body is required');
  }
  if (mb_strlen($text) > 2000) {
    api_error('note too long (max 2000 characters)');
  }
  $note_date = null;
  if (!empty($body['date'])) {
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $body['date'])) {
      api_error('date must be YYYY-MM-DD');
    }
    $note_date = $body['date'];
  }
  $note_sci = !empty($body['sci_name']) ? trim($body['sci_name']) : null;
  $note_file = !empty($body['file_name']) ? trim($body['file_name']) : null;

  $ins = $db_rw->prepare('INSERT INTO notes (date, sci_name, file_name, body) VALUES (:d, :sci, :f, :b)');
  if ($note_date === null) { $ins->bindValue(':d', null, SQLITE3_NULL); } else { $ins->bindValue(':d', $note_date, SQLITE3_TEXT); }
  if ($note_sci === null) { $ins->bindValue(':sci', null, SQLITE3_NULL); } else { $ins->bindValue(':sci', $note_sci, SQLITE3_TEXT); }
  if ($note_file === null) { $ins->bindValue(':f', null, SQLITE3_NULL); } else { $ins->bindValue(':f', $note_file, SQLITE3_TEXT); }
  $ins->bindValue(':b', $text, SQLITE3_TEXT);
  db_execute_safe($db_rw, $ins, 'note insert');
  $new_id = $db_rw->lastInsertRowID();
  $db_rw->close();
  api_json(['status' => 'ok', 'id' => $new_id]);

} elseif (preg_match('#^/api/v1/notes$#', $requestUri)) {
  if (!spine_table_exists($db, 'notes')) {
    api_json(['notes' => [], 'count' => 0]);
    exit;
  }
  $limit = request_int($_GET, 'limit', 50, 1, 200);
  $where = [];
  $params = [];
  if (isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date'])) {
    $where[] = 'date = :date';
    $params[':date'] = $_GET['date'];
  }
  if (!empty($_GET['sci_name'])) {
    $where[] = 'sci_name = :sci';
    $params[':sci'] = $_GET['sci_name'];
  }
  $sql = 'SELECT id, date, sci_name, file_name, body, created_at FROM notes'
       . (!empty($where) ? ' WHERE ' . implode(' AND ', $where) : '')
       . ' ORDER BY created_at DESC LIMIT ' . $limit;
  $stmt = $db->prepare($sql);
  foreach ($params as $name => $value) {
    $stmt->bindValue($name, $value, SQLITE3_TEXT);
  }
  $result = db_execute_safe($db, $stmt, 'notes list');
  $notes = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $notes[] = $row;
  }
  api_json(['notes' => $notes, 'count' => count($notes)]);

} else {
  http_response_code(404);
  echo json_encode(["status" => "error", "message" => "Error 404! No route found!"]);
}

function sendResponse405() {
  http_response_code(405);
  echo json_encode(["status" => "error", "message" => "Method Not Allowed"]);
  exit;
}
