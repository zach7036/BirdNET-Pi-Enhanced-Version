<?php
error_reporting(E_ERROR);
ini_set('display_errors',1);
ini_set('session.gc_maxlifetime', 7200);
session_set_cookie_params(7200);
session_start();
require_once 'scripts/common.php';
$home = get_home();
$config = get_config();

set_timezone();
$myDate = date('Y-m-d');
$chart = "Combo-$myDate.png";

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(5000);

/* JSON endpoints must fail as JSON: ensure_db_ok() echoes an HTML notice,
   which corrupts the response and surfaces as a parse error in the UI. */
function ensure_db_ok_json($sql_stmt) {
  if ($sql_stmt == false) {
    http_response_code(503);
    echo json_encode(['error' => 'Database is busy']);
    die();
  }
}

function get_overview_weather($db, $date) {
  $weather = [];
  $check_table = db_query_safe($db, "SELECT name FROM sqlite_master WHERE type='table' AND name='weather'", 'overview weather table check');
  if (!db_fetch_assoc_safe($check_table)) {
    return $weather;
  }

  $hasIsDay = false;
  $cols = db_query_safe($db, "PRAGMA table_info(weather)", 'overview weather table info');
  if ($cols) {
    while($c = db_fetch_assoc_safe($cols)) {
      if($c['name'] == 'IsDay') {
        $hasIsDay = true;
      }
    }
  }

  $sel = $hasIsDay ? "Hour, Temp, ConditionCode, IsDay" : "Hour, Temp, ConditionCode";
  $stmt = $db->prepare("SELECT $sel FROM weather WHERE Date = :date AND Temp IS NOT NULL ORDER BY Hour ASC");
  if (!$stmt) {
    return $weather;
  }

  $stmt->bindValue(':date', $date, SQLITE3_TEXT);
  $res = db_execute_safe($db, $stmt, 'overview weather rows');
  if (!$res) {
    return $weather;
  }

  while ($row = db_fetch_assoc_safe($res)) {
    $weather[(int)$row['Hour']] = [
      'temp' => display_temp($row['Temp']),
      'code' => (int)$row['ConditionCode'],
      'is_day' => $hasIsDay ? (int)$row['IsDay'] : 1
    ];
  }

  return $weather;
}

// When the station cannot reach the weather API the missing-data trigger
// fires on every chart load, each request stalling on a doomed 20s sync.
// The lock file's mtime records the last attempt; retry at most every 10
// minutes and leave the real syncing to the hourly cron. Callers can check
// this before releasing their DB handle - a suppressed sync changes nothing.
function weather_sync_cooldown_active() {
  $last_attempt = @filemtime(sys_get_temp_dir() . '/birdnet_weather_sync.lock');
  return $last_attempt !== false && time() - $last_attempt < 600;
}

function sync_overview_weather($home, $user) {
  $python = $home . "/BirdNET-Pi/birdnet/bin/python3";
  $script = $home . "/BirdNET-Pi/scripts/utils/weather.py";
  if (!is_readable($script)) {
    return;
  }

  if (weather_sync_cooldown_active()) {
    return;
  }

  $lock_path = sys_get_temp_dir() . '/birdnet_weather_sync.lock';
  $lock = @fopen($lock_path, 'c');
  if (!$lock) {
    return;
  }

  if (@flock($lock, LOCK_EX | LOCK_NB)) {
    @touch($lock_path);
    $timeout = is_executable('/usr/bin/timeout') ? "/usr/bin/timeout 20 " : "";
    $cmd = $timeout . "sudo -u " . escapeshellarg($user) . " " . escapeshellarg($python) . " " . escapeshellarg($script) . " >/dev/null 2>&1";
    @exec($cmd);
    @flock($lock, LOCK_UN);
  }
  @fclose($lock);
}

if(isset($_GET['custom_image'])){
  if(isset($config["CUSTOM_IMAGE"])) {
  ?>
    <br>
    <h3><?php echo $config["CUSTOM_IMAGE_TITLE"]; ?></h3>
    <?php
    $image_data = file_get_contents($config["CUSTOM_IMAGE"]);
    $image_base64 = base64_encode($image_data);
    $img_tag = "<img src='data:image/png;base64," . $image_base64 . "'>";
    echo $img_tag;
  }
  die();
}

if(isset($_GET['clearcache'])) {
  unset($_SESSION['images']);
  header("Location: overview.php");
  die();
}

if(isset($_GET['blacklistimage'])) {
  ensure_authenticated('You must be authenticated.');
  $imageid = $_GET['blacklistimage'];
  $file_handle = fopen($home."/BirdNET-Pi/scripts/blacklisted_images.txt", 'a+');
  fwrite($file_handle, $imageid . "\n");
  fclose($file_handle);
  unset($_SESSION['images']);
  die("OK");
}

if(isset($_GET['fetch_chart_string']) && $_GET['fetch_chart_string'] == "true") {
  $myDate = date('Y-m-d');
  $chart = "Combo-$myDate.png";
  echo $chart;
  die();
}

if(isset($_GET['ajax_chart_data']) && $_GET['ajax_chart_data'] == "true") {
  header('Content-Type: application/json');
  header('Cache-Control: no-store');
  // Anything echoed before the JSON (stray whitespace from an include, a
  // displayed PHP message) corrupts the payload; buffer and discard it.
  ob_start();

  // Species aggregate: name, count, max confidence.
  // Detections reviewed as false positives (or hidden) are excluded.
  $stmt1 = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*) as cnt, MAX(Confidence) as maxConf FROM detections WHERE Date = DATE('now','localtime')" . and_review_exclusion($db) . " GROUP BY Sci_Name ORDER BY cnt DESC");
  ensure_db_ok_json($stmt1);
  $res1 = db_execute_safe($db, $stmt1, 'overview chart species');
    // For image fetching
    list($image_provider, $fallback_provider) = make_image_provider($config);

    $species = [];
    while ($row = db_fetch_assoc_safe($res1)) {
      $img_url = "";
      if ($image_provider) {
        if (!isset($_SESSION['species_portal_v12_cache'])) {
          $_SESSION['species_portal_v12_cache'] = [];
        }
        $search_name = trim($row['Com_Name']);
        $key = array_search($search_name, array_column($_SESSION['species_portal_v12_cache'], 0));
        
        if ($key !== false) {
          $img_url = $_SESSION['species_portal_v12_cache'][$key][1];
        } else {
          $cached_image = $image_provider->get_image($row['Sci_Name'], $fallback_provider);
          if ($cached_image && !empty($cached_image["image_url"])) {
            $image_data = array($search_name, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]);
            array_push($_SESSION["species_portal_v12_cache"], $image_data);
            $img_url = $cached_image["image_url"];
          } else {
            $image_data = array($search_name, "", "Not Found", "", "", "");
            array_push($_SESSION["species_portal_v12_cache"], $image_data);
          }
        }
      }

      $species[] = [
        'name' => $row['Com_Name'],
        'sciName' => $row['Sci_Name'],
        'count' => (int)$row['cnt'],
        'maxConf' => round((float)$row['maxConf'], 3),
        'image' => $img_url
      ];
    }

  // Hourly breakdown per species (same review exclusion as the aggregate)
  $stmt2 = $db->prepare("SELECT Com_Name, CAST(strftime('%H', Time) AS INTEGER) as hour, COUNT(*) as cnt FROM detections WHERE Date = DATE('now','localtime')" . and_review_exclusion($db) . " GROUP BY Com_Name, hour");
  ensure_db_ok_json($stmt2);
  $res2 = db_execute_safe($db, $stmt2, 'overview chart hourly');
  $hourly = [];
  while ($row = db_fetch_assoc_safe($res2)) {
    $name = $row['Com_Name'];
    if (!isset($hourly[$name])) $hourly[$name] = [];
    $hourly[$name][(int)$row['hour']] = (int)$row['cnt'];
  }
  // Weather breakdown per hour
  $today = date('Y-m-d');
  $currentHour = (int)date('G');
  $weather = get_overview_weather($db, $today);
  if ((empty($weather) || !isset($weather[$currentHour])) && !weather_sync_cooldown_active()) {
      $db->close();
      sync_overview_weather($home, get_user());
      $db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
      $db->busyTimeout(5000);
      $weather = get_overview_weather($db, $today);
  }

  $chart_json = json_encode(['species' => $species, 'hourly' => $hourly, 'weather' => $weather, 'currentHour' => $currentHour], JSON_INVALID_UTF8_SUBSTITUTE);
  if (ob_get_length()) {
    error_log('overview ajax_chart_data: discarded stray output: ' . substr(ob_get_contents(), 0, 200));
    ob_clean();
  }
  if ($chart_json === false) {
    http_response_code(500);
    echo json_encode(['error' => 'Encoding failed: ' . json_last_error_msg()]);
    die();
  }
  echo $chart_json;
  die();
}

if(isset($_GET['ajax_new_species_details']) && $_GET['ajax_new_species_details'] == "true") {
  header('Content-Type: application/json');
  
  // Specific query for New Species Today
  $stmt = $db->prepare("SELECT Com_Name, Sci_Name FROM detections WHERE Date = DATE('now', 'localtime') AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime')) GROUP BY Sci_Name ORDER BY Com_Name ASC");
  ensure_db_ok_json($stmt);
  $res = db_execute_safe($db, $stmt, 'overview new species details');

  list($image_provider, $fallback_provider) = make_image_provider($config);

  $details = [];
  while ($row = db_fetch_assoc_safe($res)) {
    $img_url = "";
    if ($image_provider) {
      if (!isset($_SESSION['species_portal_v12_cache'])) {
        $_SESSION['species_portal_v12_cache'] = [];
      }
      $search_name = trim($row['Com_Name']);
      $key = array_search($search_name, array_column($_SESSION['species_portal_v12_cache'], 0));
      
      if ($key !== false) {
        $img_url = $_SESSION['species_portal_v12_cache'][$key][1];
      } else {
        $cached_image = $image_provider->get_image($row['Sci_Name'], $fallback_provider);
        if ($cached_image && !empty($cached_image["image_url"])) {
          $image_data = array($search_name, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]);
          array_push($_SESSION["species_portal_v12_cache"], $image_data);
          $img_url = $cached_image["image_url"];
        } else {
          $image_data = array($search_name, "", "Not Found", "", "", "");
          array_push($_SESSION["species_portal_v12_cache"], $image_data);
        }
      }
    }
    $details[] = [
      'name' => $row['Com_Name'],
      'sciName' => $row['Sci_Name'],
      'image' => $img_url
    ];
  }
  echo json_encode($details, JSON_INVALID_UTF8_SUBSTITUTE);
  die();
}

if(isset($_GET['ajax_detections']) && $_GET['ajax_detections'] == "true" && isset($_GET['previous_detection_identifier'])) {

  $statement4 = $db->prepare('SELECT Com_Name, Sci_Name, Date, Time, Confidence, File_Name FROM detections ORDER BY Date DESC, Time DESC LIMIT 15');
  ensure_db_ok($statement4);
  $result4 = db_execute_safe($db, $statement4, 'overview recent detections');
  if(!isset($_SESSION['images'])) {
    $_SESSION['images'] = [];
  }
  $iterations = 0;
  $rows_examined = 0;
  $image_provider = null;
  $backlog_notice = "<h3>Your system is currently processing a backlog of audio. This can take several hours before normal functionality of your BirdNET-Pi resumes.</h3>";
  // Normalized so the client may send the identifier with or without a leading
  // slash; anything but a plain string (e.g. a crafted array parameter) is
  // treated as no identifier rather than fataling in ltrim().
  $previous_identifier = $_GET['previous_detection_identifier'] ?? '';
  $previous_identifier = is_string($previous_identifier) ? ltrim($previous_identifier, '/') : '';

  // hopefully one of the 5 most recent detections has an image that is valid, we'll use that one as the most recent detection until the newer ones get their images created
  while($mostrecent = db_fetch_assoc_safe($result4)) {
    $rows_examined++;
    $comname = preg_replace('/ /', '_', $mostrecent['Com_Name']);
    $sciname = preg_replace('/ /', '_', $mostrecent['Sci_Name']);
    $comnamegraph = str_replace("'", "\'", $mostrecent['Com_Name']);
    $comname = preg_replace('/\'/', '', $comname);
    $filename = "By_Date/".$mostrecent['Date']."/".$comname."/".$mostrecent['File_Name'];

    // check to make sure the image actually exists, sometimes it takes a minute to be created\
    if(file_exists($home."/BirdSongs/Extracted/".$filename.".png")){
      if($previous_identifier !== '' && $previous_identifier === $filename) {
        // Rows skipped above this one are newer detections whose spectrograms
        // are not rendered yet: report the backlog so the client keeps
        // refreshing instead of concluding nothing changed.
        if ($rows_examined > 1) { echo $backlog_notice; }
        die();
      }
      if(isset($_GET['only_name']) && $_GET['only_name'] == "true") { echo $comname.",".$filename;die(); }

      $iterations++;

      if (!empty($config["IMAGE_PROVIDER"])) {
        if ($image_provider === null) {
          list($image_provider, $fallback_provider) = make_image_provider($config);
          if ($image_provider->is_reset()) {
            $_SESSION['images'] = [];
          }
        }

        if (!isset($_SESSION['species_portal_v12_cache'])) {
          $_SESSION['species_portal_v12_cache'] = [];
        }
        
        $search_name = trim($mostrecent['Com_Name']);
        $key = array_search($search_name, array_column($_SESSION['species_portal_v12_cache'], 0));
        
        if ($key !== false) {
          $image = $_SESSION['species_portal_v12_cache'][$key];
        } else {
          $cached_image = $image_provider->get_image($mostrecent['Sci_Name'], $fallback_provider);
          if ($cached_image && !empty($cached_image["image_url"])) {
            $image_data = array($search_name, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]);
            array_push($_SESSION["species_portal_v12_cache"], $image_data);
            $image = $image_data;
          } else {
            $image_data = array($search_name, "", "Not Found", "", "", "");
            array_push($_SESSION["species_portal_v12_cache"], $image_data);
            $image = $image_data;
          }
        }
      }
    ?>
        <style>
        .fade-in {
          opacity: 1;
          animation-name: fadeInOpacity;
          animation-iteration-count: 1;
          animation-timing-function: ease-in;
          animation-duration: 1s;
        }
        @keyframes fadeInOpacity {
          0% { opacity: 0; }
          100% { opacity: 1; }
        }
        .mrd-card {
          background: var(--bg-card);
          border: 1px solid var(--border);
          border-radius: 16px;
          padding: 20px;
          box-shadow: var(--shadow-sm);
          max-width: 600px;
          margin: 0 auto;
        }
        .mrd-header {
          display: flex;
          justify-content: space-between;
          align-items: center;
          margin-bottom: 14px;
          flex-wrap: wrap;
          gap: 8px;
        }
        .mrd-datetime { font-size: 0.85em; color: var(--text-secondary); font-weight: 500; }
        .mrd-open-link {
          display: inline-flex; align-items: center; gap: 4px;
          padding: 4px 10px; border-radius: 8px;
          background: var(--accent-subtle, rgba(99,102,241,0.1));
          color: var(--accent, #6366f1);
          text-decoration: none; font-size: 0.75em; font-weight: 600;
          transition: all 0.2s ease;
        }
        .mrd-open-link:hover { background: var(--accent); color: white; }
        .mrd-open-link img { width: 14px; height: 14px; }
        .mrd-bird-img {
          width: 100%; max-height: 200px; object-fit: contain;
          background: var(--bg-primary, #f8fafc); border-radius: 10px;
          margin-bottom: 14px; cursor: pointer;
        }
        .mrd-species { text-align: center; margin-bottom: 12px; }
        .mrd-species-name { font-size: 1.3em; font-weight: 700; color: var(--text-heading); display: block; margin-bottom: 2px; }
        .mrd-species-name button {
          background: none; border: none; color: var(--text-heading);
          font: inherit; cursor: pointer; padding: 0;
        }
        .mrd-species-name button:hover { color: var(--accent); }
        .mrd-sci { font-style: italic; color: var(--text-secondary); font-size: 0.9em; display: block; margin-bottom: 8px; }
        .mrd-links { display: flex; justify-content: center; gap: 6px; flex-wrap: wrap; margin-bottom: 10px; }
        .mrd-link-pill {
          display: inline-flex; align-items: center; gap: 4px;
          padding: 3px 10px; border-radius: 8px;
          background: var(--accent-subtle, rgba(99,102,241,0.1));
          color: var(--accent, #6366f1);
          text-decoration: none; font-size: 0.75em; font-weight: 600;
          transition: all 0.2s ease; border: none; cursor: pointer; font-family: inherit;
        }
        .mrd-link-pill:hover { background: var(--accent); color: white; }
        .mrd-link-pill img { width: 12px; height: 12px; }
        .mrd-conf {
          display: inline-block; padding: 3px 12px; border-radius: 12px;
          font-size: 0.8em; font-weight: 700; margin-bottom: 12px;
        }
        .mrd-conf-high { background: #dcfce7; color: #166534; }
        .mrd-conf-med { background: #fef9c3; color: #854d0e; }
        .mrd-conf-low { background: #fee2e2; color: #991b1b; }
        </style>
        <?php
          $conf_raw = (float)round($mostrecent['Confidence'], 2);
          $conf_pct = round($conf_raw * 100) . '%';
          $conf_class = $conf_raw >= 0.8 ? 'mrd-conf-high' : ($conf_raw >= 0.5 ? 'mrd-conf-med' : 'mrd-conf-low');
          $display_date = date('M. j, Y', strtotime($mostrecent['Date']));
          $info_url = get_info_url($mostrecent['Sci_Name']);
          $url = $info_url['URL'];
        ?>
        <div class="mrd-card <?php echo ($previous_identifier === '') ? '' : 'fade-in'; ?>">
          <div class="mrd-header">
            <span class="mrd-datetime"><?php echo $display_date . ' ' . $mostrecent['Time']; ?></span>
            <a class="mrd-open-link" target="_blank" href="index.php?filename=<?php echo $mostrecent['File_Name']; ?>">
              <img src="images/copy.png"> Open
            </a>
          </div>
          <?php if(!empty($config["IMAGE_PROVIDER"]) && !empty($image[1])) { ?>
            <img class="mrd-bird-img" onerror="this.style.display='none'" onclick='setModalText(<?php echo (int)$iterations; ?>, <?php echo js_arg($image[2]); ?>, <?php echo js_arg($image[3]); ?>, <?php echo js_arg($image[4]); ?>, <?php echo js_arg($image[1]); ?>, <?php echo js_arg($image[5]); ?>)' src="<?php echo h($image[1]); ?>">
          <?php } ?>
          <div class="mrd-species">
            <span class="mrd-species-name">
              <form action="" method="GET" style="display:inline">
                <input type="hidden" name="view" value="Species Stats">
                <button type="submit" name="species" value="<?php echo $mostrecent['Com_Name'];?>"><?php echo $mostrecent['Com_Name'];?></button>
              </form>
            </span>
            <span class="mrd-sci"><?php echo $mostrecent['Sci_Name'];?></span>
            <div class="mrd-links">
              <a href="<?php echo $url; ?>" target="_blank" class="mrd-link-pill"><img src="images/info.png"> Info</a>
              <a href="<?php echo get_wikipedia_url($sciname); ?>" target="_blank" class="mrd-link-pill"><img src="images/wiki.png"> Wikipedia</a>
              <button class="mrd-link-pill" onclick="generateMiniGraph(this, '<?php echo $comnamegraph; ?>')"><img src="images/chart.svg"> Stats</button>
            </div>
            <span class="mrd-conf <?php echo $conf_class; ?>"><?php echo $conf_pct; ?></span>
          </div>
          <div class='custom-audio-player' data-audio-src="<?php echo $filename; ?>" data-image-src="<?php echo $filename.".png";?>"></div>
        </div>
        <?php break;
      }
  }
  if($iterations == 0) {
    $statement2 = $db->prepare('SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')');
    ensure_db_ok($statement2);
    $result2 = db_execute_safe($db, $statement2, 'overview today count');
    $todaycount = db_fetch_assoc_safe($result2) ?: ['COUNT(*)' => 0];
    if($todaycount['COUNT(*)'] > 0) {
      echo $backlog_notice;
    } else {
      echo "<h3>No Detections For Today.</h3>";
    }
  }
  die();
}

if(isset($_GET['ajax_left_chart']) && $_GET['ajax_left_chart'] == "true") {

  // Retrieve the cached data from session without regenerating
  $chart_data = get_summary();
  $_SESSION['chart_data'] = $chart_data;
?>
<div class="kpi-cards" style="justify-content: center; margin: 0; max-width: 100%;">
  <div class="kpi-card">
    <div class="kpi-icon">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><path d="M22 12h-4l-3 9L9 3l-3 9H2"/></svg>
    </div>
    <div class="kpi-value"><?php echo format_number($chart_data['totalcount']);?></div>
    <div class="kpi-label">Total Detections</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon kpi-icon-today">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><rect x="3" y="4" width="18" height="18" rx="2" ry="2"/><line x1="16" y1="2" x2="16" y2="6"/><line x1="8" y1="2" x2="8" y2="6"/><line x1="3" y1="10" x2="21" y2="10"/></svg>
    </div>
    <div class="kpi-value"><form action="" method="GET" style="display:inline"><button type="submit" name="view" value="Todays Detections" class="kpi-link"><?php echo format_number($chart_data['todaycount']);?></button></form></div>
    <div class="kpi-label">Today</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon kpi-icon-hour">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><circle cx="12" cy="12" r="10"/><polyline points="12 6 12 12 16 14"/></svg>
    </div>
    <div class="kpi-value"><?php echo format_number($chart_data['hourcount']);?></div>
    <div class="kpi-label">Last Hour</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon kpi-icon-species">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><path d="M18 8a6 6 0 0 0-12 0c0 7-3 9-3 9h18s-3-2-3-9"/><path d="M13.73 21a2 2 0 0 1-3.46 0"/></svg>
    </div>
    <div class="kpi-value"><form action="" method="GET" style="display:inline"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>" class="kpi-link"><?php echo $chart_data['speciestally'];?></button></form></div>
    <div class="kpi-label">Species Today</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon kpi-icon-total-species">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><path d="M17 21v-2a4 4 0 0 0-4-4H5a4 4 0 0 0-4 4v2"/><circle cx="9" cy="7" r="4"/><path d="M23 21v-2a4 4 0 0 0-3-3.87"/><path d="M16 3.13a4 4 0 0 1 0 7.75"/></svg>
    </div>
    <div class="kpi-value"><form action="" method="GET" style="display:inline"><button type="submit" name="view" value="Species Stats" class="kpi-link"><?php echo $chart_data['totalspeciestally'];?></button></form></div>
    <div class="kpi-label">Total Species</div>
  </div>
  <div class="kpi-card">
    <div class="kpi-icon kpi-icon-new-species">
      ✨
    </div>
    <div class="kpi-value">
      <button class="kpi-link" onclick="showNewSpeciesPopup(); return false;"><?php echo $chart_data['newspeciestally'];?></button>
    </div>
    <div class="kpi-label">New Species Today</div>
  </div>
  <?php if(!empty($chart_data['topspecies'])) { ?>
  <div class="kpi-card kpi-card-highlight">
    <div class="kpi-icon kpi-icon-top">
      <svg viewBox="0 0 24 24" width="28" height="28" fill="none" stroke="currentColor" stroke-width="2"><polygon points="12 2 15.09 8.26 22 9.27 17 14.14 18.18 21.02 12 17.77 5.82 21.02 7 14.14 2 9.27 8.91 8.26 12 2"/></svg>
    </div>
    <div class="kpi-value"><?php echo htmlspecialchars($chart_data['topspecies']);?></div>
    <div class="kpi-label">Top Species (<?php echo $chart_data['topspeciescount'];?>x)</div>
  </div>
  <?php } ?>
</div>
<?php
  die();
}

if(isset($_GET['ajax_center_chart']) && $_GET['ajax_center_chart'] == "true") {

  // Cached by a prior ajax_left_chart request; a fresh or expired session
  // must not render a KPI strip of blanks, so fall back to computing it.
  $chart_data = $_SESSION['chart_data'] ?? get_summary();
?>
<div class="kpi-cards kpi-cards-compact" style="justify-content: center; margin: 0; max-width: 100%;">
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><?php echo format_number($chart_data['totalcount']);?></div><div class="kpi-label">Total</div></div>
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><form action="" method="GET" style="display:inline"><button type="submit" name="view" value="Todays Detections" class="kpi-link"><?php echo format_number($chart_data['todaycount']);?></button></form></div><div class="kpi-label">Today</div></div>
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><?php echo format_number($chart_data['hourcount']);?></div><div class="kpi-label">Last Hour</div></div>
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><form action="" method="GET" style="display:inline"><button type="submit" name="view" value="Species Stats" class="kpi-link"><?php echo $chart_data['totalspeciestally'];?></button></form></div><div class="kpi-label">Species Total</div></div>
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><form action="" method="GET" style="display:inline"><input type="hidden" name="view" value="Recordings"><button type="submit" name="date" value="<?php echo date('Y-m-d');?>" class="kpi-link"><?php echo $chart_data['speciestally'];?></button></form></div><div class="kpi-label">Species Today</div></div>
  <div class="kpi-card kpi-card-sm"><div class="kpi-value"><button class="kpi-link" onclick="showNewSpeciesPopup(); return false;"><?php echo $chart_data['newspeciestally'];?></button></div><div class="kpi-label">New Today</div></div>
  <?php if(!empty($chart_data['topspecies'])) { ?>
  <div class="kpi-card kpi-card-sm kpi-card-highlight"><div class="kpi-value" style="font-size:0.95em"><?php echo htmlspecialchars($chart_data['topspecies']);?></div><div class="kpi-label">Top (<?php echo $chart_data['topspeciescount'];?>x)</div></div>
  <?php } ?>
</div>

<?php
  die();
}

if (get_included_files()[0] === __FILE__) {
  echo '<!DOCTYPE html>
<html lang="en">
<head>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Overview</title>
</head>';
}
?>
<div class="overview">
  <dialog style="margin-top: 5px;max-height: 95vh;
  overflow-y: auto;overscroll-behavior:contain" id="attribution-dialog">
    <h1 id="modalHeading"></h1>
    <p id="modalText"></p>
    <button onclick="hideDialog()">Close</button>
    <button style="font-weight:bold;color:blue" onclick="confirmBlacklistImage()" <?php if($config["IMAGE_PROVIDER"] === 'WIKIPEDIA'){ echo 'hidden';} ?> >Blacklist this image</button>
  </dialog>
  <script src="static/dialog-polyfill.js"></script>
  <script src="static/Chart.bundle.js"></script>
  <script src="static/chartjs-plugin-trendline.min.js"></script>
  <script>
  var last_photo_link;
  var dialog = document.querySelector('dialog');
  dialogPolyfill.registerDialog(dialog);

  function showDialog() {
    document.getElementById('attribution-dialog').showModal();
  }

  function hideDialog() {
    document.getElementById('attribution-dialog').close();
  }

  function confirmBlacklistImage() {
    if (window.BirdNETUI) {
      BirdNETUI.confirmAction({
        title: 'Blacklist image',
        message: 'This prevents the current image from being used again for this species.',
        confirmText: 'Blacklist image',
        danger: true
      }).then(function(confirmed) {
        if (confirmed) {
          blacklistImage();
        }
      });
      return;
    }
    if (confirm('Are you sure you want to blacklist this image?')) {
      blacklistImage();
    }
  }

  function blacklistImage() {
    const match = last_photo_link.match(/\d+$/); // match one or more digits
    const result = match ? match[0] : null; // extract the first match or return null if no match is found
    console.log(last_photo_link)
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.responseText.length > 0) {
       location.reload();
      }
    }
    xhttp.open("GET", "overview.php?blacklistimage="+result, true);
    xhttp.send();

  }

  function shorten(u) {
    if (u.length < 48) {
      return u;
    }
    uend = u.slice(u.length - 16);
    ustart = u.substr(0, 32);
    var shorter = ustart + '...' + uend;
    return shorter;
  }

  // Canonical escaping lives in BirdNETUI (static/ui-helpers.js); delegating
  // keeps a single copy of security-sensitive code. A missing helper fails
  // closed: the modal throws instead of rendering unescaped metadata.
  function escapeHtml(s) { return BirdNETUI.escapeHtml(s); }
  function safeHttpUrl(url) { return BirdNETUI.safeHttpUrl(url); }

  function setModalText(iter, title, text, authorlink, photolink, licenseurl) {
    const safeText = safeHttpUrl(text);
    const safeAuthor = safeHttpUrl(authorlink);
    const safePhoto = safeHttpUrl(photolink);
    const safeLicense = safeHttpUrl(licenseurl);
    let text_display = shorten(safeText);
    let authorlink_display = shorten(safeAuthor);
    let licenseurl_display = shorten(safeLicense);
    document.getElementById('modalHeading').textContent = "Photo: \""+String(title)+"\" Attribution";
    document.getElementById('modalText').innerHTML = "<div><img style='border-radius:5px;max-height: calc(100vh - 15rem);display: block;margin: 0 auto;' src='"+escapeHtml(safePhoto)+"'></div><br><div style='white-space:nowrap'>Image link: <a target='_blank' href='"+escapeHtml(safeText)+"'>"+escapeHtml(text_display)+"</a><br>Author link: <a target='_blank' href='"+escapeHtml(safeAuthor)+"'>"+escapeHtml(authorlink_display)+"</a><br>License URL: <a href='"+escapeHtml(safeLicense)+"' target='_blank'>"+escapeHtml(licenseurl_display)+"</a></div>";
    last_photo_link = safeText;
    showDialog();
  }

  function showNewSpeciesPopup() {
    const xhttp = new XMLHttpRequest();
    xhttp.onload = function() {
      if(this.status == 200) {
        const species = JSON.parse(this.responseText);
        let html = '<div class="new-species-grid">';
        if (species.length === 0) {
          html = '<p style="text-align:center;padding:20px;color:#888;">No new species detected today.</p>';
        } else {
          species.forEach(s => {
            html += `
              <div class="new-species-item">
                <div class="new-species-img">
                  ${s.image ? `<img src="${s.image}" alt="${s.name}" style="image-rendering: high-quality;">` : '<div class="no-img">No Image</div>'}
                </div>
                <div class="new-species-info">
                  <div class="new-species-name">${s.name}</div>
                  <div class="new-species-sci"><i>${s.sciName}</i></div>
                </div>
              </div>
            `;
          });
          html += '</div>';
        }
        document.getElementById('modalHeading').innerText = "New Species Today (" + species.length + ")";
        document.getElementById('modalText').innerHTML = html;
        showDialog();
      }
    }
    xhttp.open("GET", "overview.php?ajax_new_species_details=true", true);
    xhttp.send();
  }
  </script>
  <style>
    .new-species-grid {
      display: grid;
      grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
      gap: 16px;
      padding: 8px 0;
    }
    .new-species-item {
      display: flex;
      align-items: center;
      gap: 12px;
      padding: 10px;
      background: var(--bg-card);
      border: 1px solid var(--border);
      border-radius: 12px;
      transition: transform 0.2s ease;
    }
    .new-species-item:hover {
      transform: translateY(-2px);
      box-shadow: var(--shadow-md);
    }
    .new-species-img {
      width: 64px;
      height: 64px;
      border-radius: 8px;
      overflow: hidden;
      flex-shrink: 0;
      background: #eee;
      display: flex;
      align-items: center;
      justify-content: center;
    }
    .new-species-img img {
      width: 100%;
      height: 100%;
      object-fit: cover;
    }
    .new-species-info {
      flex-grow: 1;
    }
    .new-species-name {
      font-weight: 700;
      font-size: 1.1em;
      color: var(--text-primary);
      margin-bottom: 2px;
    }
    .new-species-sci {
      font-size: 0.9em;
      color: var(--text-secondary);
      opacity: 0.8;
    }
    .no-img {
      font-size: 10px;
      color: #aaa;
    }
    #attribution-dialog {
      border: none;
      border-radius: 16px;
      padding: 24px;
      box-shadow: var(--shadow-xl);
      max-width: 800px;
      width: 90%;
    }
    #modalHeading {
      margin-top: 0;
      margin-bottom: 20px;
      font-size: 1.5em;
      border-bottom: 2px solid var(--accent);
      padding-bottom: 10px;
    }
  </style>  
<div class="overview-stats">
<div class="right-column">
<div class="left-column" style="flex: unset; padding-left: 0; margin-bottom: 8px;"></div>
<div class="center-column">
</div>
<?php
// New/Rare species lists removed for KPI redesign
?>
<div class="chart-container" style="max-width: 100%;">
  <div class="ui-section-header">
    <h3>Today's Activity Heatmap</h3>
    <span id="heatmapUpdated" class="ui-meta">Loading...</span>
  </div>
  <div id="heatmapError" style="margin-bottom:8px;"></div>
  <div class="chart-canvas-wrapper ui-chart-scroll" style="max-width: 100%; margin:8px auto 0;">
    <canvas id="hourlyHeatmap"></canvas>
  </div>
  <div class="ui-legend" aria-label="Heatmap legend">
    <span class="ui-legend-item"><span class="ui-swatch ui-swatch-empty"></span>No detections</span>
    <span class="ui-legend-item"><span class="ui-swatch ui-swatch-low"></span>Lower activity</span>
    <span class="ui-legend-item"><span class="ui-swatch ui-swatch-high"></span>Higher activity</span>
    <span class="ui-legend-item">Weather row shows hourly temperature and conditions</span>
    <span class="ui-legend-item">Highlighted hour is the current hour</span>
  </div>
</div>
<?php
$refresh = $config['RECORDING_LENGTH'];
$dividedrefresh = $refresh/4;
if($dividedrefresh < 1) { 
  $dividedrefresh = 1;
}
?>

<div class="ui-section-header">
  <h3>5 Most Recent Detections</h3>
  <span id="recentDetectionsUpdated" class="ui-meta">Loading...</span>
</div>
<div id="recentDetectionsError" style="margin-bottom:8px;"></div>
<div style="padding-bottom:8px;" id="detections_table"><script>document.write(window.BirdNETUI ? BirdNETUI.skeleton(4) : '<h3>Loading...</h3>');</script></div>

<div id="customimage"></div>
<br>

</div>



</div>
</div>
<script>
// we're passing a unique ID of the currently displayed detection to our script, which checks the database to see if the newest detection entry is that ID, or not. If the IDs don't match, it must mean we have a new detection and it's loaded onto the page
// The probe asks for only_name=true: a new detection answers "comname,By_Date/..."
// without rendering a card or contacting the image providers, and the server
// returns nothing when the newest detection still matches the identifier we sent.
var latest_detection_identifier = undefined;
var initial_detection_load_done = false;
// A forced refresh survives "Database is busy" responses: the flag stays set
// until a cascade actually runs, so the next poll retries without an identifier.
var force_full_refresh = false;
// Backlog responses repeat for hours; cascade at most once per 30s for them.
var last_backlog_refresh = 0;
var last_cascade_date = new Date().toDateString();
function loadDetectionIfNewExists(previous_detection_identifier=undefined) {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    // An empty body means the newest detection is the one we already have.
    // A backlog is different from the other notices: detections keep landing
    // in the database without spectrograms, so it must keep refreshing.
    const is_busy = this.responseText.includes("Database is busy");
    const is_backlog = this.responseText.includes("processing a backlog");
    const has_new_detection = this.responseText.length > 0
      && !is_busy
      && !this.responseText.includes("No Detections")
      && !is_backlog;

    // remember what the server just described so the next poll can short-circuit
    if (has_new_detection) {
      const match = this.responseText.match(/By_Date\/.*/);
      if (match) latest_detection_identifier = match[0];
    }

    const backlog_refresh_due = is_backlog && Date.now() - last_backlog_refresh > 30000;

    // if there's a new detection that needs to be updated to the page
    // (the first load always populates, even on a station with no detections)
    if(!is_busy && (has_new_detection || backlog_refresh_due || force_full_refresh || !initial_detection_load_done)) {
      initial_detection_load_done = true;
      force_full_refresh = false;
      if (is_backlog) last_backlog_refresh = Date.now();
      last_cascade_date = new Date().toDateString();
      // Refresh KPIs and Recent Detections List

      // only going to load left chart & 5 most recents if there's a new detection
      loadLeftChart();
      loadFiveMostRecentDetections();
      refreshTopTen();

      // Now that new HTML is inserted, re-run player init:
      initCustomAudioPlayers();
    }
  }
  xhttp.open("GET", "overview.php?ajax_detections=true&only_name=true&previous_detection_identifier="+encodeURIComponent(previous_detection_identifier === undefined ? '' : previous_detection_identifier), true);
  xhttp.send();
}
function loadLeftChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("left-column")[0].innerHTML = this.responseText;
      loadCenterChart();
    }
  }
  xhttp.open("GET", "overview.php?ajax_left_chart=true", true);
  xhttp.send();
}
function loadCenterChart() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementsByClassName("center-column")[0].innerHTML = this.responseText;
    }
  }
  xhttp.open("GET", "overview.php?ajax_center_chart=true", true);
  xhttp.send();
}
function refreshTopTen() {
  if (window.DashboardCharts) {
    DashboardCharts.refresh();
  }
}
function refreshDetection() {
  if (!document.hidden) {
    // Check if ANY audio is currently playing on the page before refreshing
    let isPlaying = false;

    // Check custom audio players
    const audioPlayers = document.querySelectorAll(".custom-audio-player");
    audioPlayers.forEach((player) => {
      const audioEl = player.querySelector("audio");
      if (audioEl && audioEl.currentTime > 0 && !audioEl.paused && !audioEl.ended && audioEl.readyState > 2) {
        isPlaying = true;
      }
    });

    // Check ALL native audio elements on the page (e.g. in #detections_table)
    if (!isPlaying) {
      document.querySelectorAll("audio").forEach((audioEl) => {
        if (audioEl.currentTime > 0 && !audioEl.paused && !audioEl.ended && audioEl.readyState > 2) {
          isPlaying = true;
        }
      });
    }

    // If something is playing, skip the refresh
    if (isPlaying) return;

    // While the tab stays visible across midnight nothing new may arrive for
    // hours, yet the "Today" KPIs are already stale - force one full refresh.
    if (new Date().toDateString() !== last_cascade_date) {
      force_full_refresh = true;
    }

    // Nothing playing, proceed with refresh. The player's own identifier wins
    // when one is on the page; otherwise use the last one the server described.
    const currentIdentifier = force_full_refresh ? undefined : (audioPlayers[0]?.dataset.audioSrc || latest_detection_identifier);
    loadDetectionIfNewExists(currentIdentifier);
  }
}
function loadFiveMostRecentDetections() {
  const xhttp = new XMLHttpRequest();
  xhttp.onload = function() {
    if(this.responseText.length > 0 && !this.responseText.includes("Database is busy")) {
      document.getElementById("detections_table").innerHTML= this.responseText;
      document.getElementById("recentDetectionsError").innerHTML = "";
      const updated = document.getElementById("recentDetectionsUpdated");
      if (updated) updated.textContent = "Updated " + new Date().toLocaleTimeString([], {hour: "numeric", minute: "2-digit"});
    } else if (this.responseText.includes("Database is busy")) {
      if (window.BirdNETUI) BirdNETUI.setMessage("#recentDetectionsError", "warning", "Database busy", "Recent detections will retry automatically.");
    }
  }
  xhttp.onerror = function() {
    if (window.BirdNETUI) BirdNETUI.setMessage("#recentDetectionsError", "error", "Recent detections unavailable", "The latest detections could not be loaded.");
  }
  if (window.innerWidth > 500) {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=5", true);
  } else {
    xhttp.open("GET", "todays_detections.php?ajax_detections=true&display_limit=undefined&hard_limit=5&mobile=true", true);
  }
  xhttp.send();
}
function refreshCustomImage(){
  // Find the customimage element
  var customimage = document.getElementById("customimage");

  function updateCustomImage() {
    var xhr = new XMLHttpRequest();
    xhr.open("GET", "overview.php?custom_image=true", true);
    xhr.onload = function() {
      customimage.innerHTML = xhr.responseText;
    }
    xhr.send();
  }
  updateCustomImage();
}
function startAutoRefresh() {
    i_fn2 = window.setInterval(refreshDetection, <?php echo intval($dividedrefresh); ?>*1000);
    if (customImage) i_fn3 = window.setInterval(refreshCustomImage, 1000);
}
<?php if(isset($config["CUSTOM_IMAGE"]) && strlen($config["CUSTOM_IMAGE"]) > 2){?>
customImage = true;
<?php } else { ?>
customImage = false;
<?php } ?>
window.addEventListener("load", function(){
  loadDetectionIfNewExists();
});
document.addEventListener("visibilitychange", function() {
  console.log(document.visibilityState);
  console.log(document.hidden);
  if (document.hidden) {
    if (typeof i_fn1 !== 'undefined') clearInterval(i_fn1);
    clearInterval(i_fn2);
    if (customImage) clearInterval(i_fn3);
  } else {
    // Force a full refresh: while the tab was hidden the KPIs and heatmap may
    // have gone stale (a date rollover) without any new detection to signal it.
    force_full_refresh = true;
    loadDetectionIfNewExists();
    startAutoRefresh();
  }
});
startAutoRefresh();
</script>

<style>
  .tooltip {
  background-color: rgba(15, 23, 42, 0.9);
  color: white;
  border-radius: 8px;
  box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
  padding: 8px 12px;
  font-size: 0.85em;
  backdrop-filter: blur(4px);
  -webkit-backdrop-filter: blur(4px);
  transition: opacity 0.2s ease-in-out;
}
</style>
<script src="static/custom-audio-player.js"></script>
<script src="static/generateMiniGraph.js"></script>
<script src="static/dashboard-charts.js?v=<?php echo (int)@filemtime('static/dashboard-charts.js'); ?>"></script>
<script>if(window.DashboardCharts){DashboardCharts.refresh();}</script>
<script>
// Listen for the scroll event on the window object
window.addEventListener('scroll', function() {
  // Get all chart elements
  var charts = document.querySelectorAll('.chartdiv');
  
  // Loop through all chart elements and remove them
  charts.forEach(function(chart) {
    chart.parentNode.removeChild(chart);
    window.chartWindow = undefined;
  });
});

</script>
