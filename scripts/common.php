<?php

if (!defined('__ROOT__')) {
  define('__ROOT__', dirname(dirname(__FILE__)));
}

// CLI runs (cleanup helpers, seeders) have no session to resume and would
// leave an orphan session file behind on every run; $_SESSION still works as
// a plain array for the per-request caches.
if (PHP_SAPI !== 'cli' && session_status() !== PHP_SESSION_ACTIVE)
  session_start();

function h($value) {
  return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
}

function js_arg($value) {
  return json_encode((string)$value, JSON_HEX_TAG | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_HEX_AMP);
}

function request_int($source, $key, $default, $min = null, $max = null) {
  $value = $default;
  if (isset($source[$key]) && is_numeric($source[$key])) {
    $value = intval($source[$key]);
  }
  if ($min !== null) {
    $value = max($min, $value);
  }
  if ($max !== null) {
    $value = min($max, $value);
  }
  return $value;
}

function ensure_db_ok($sql_stmt) {
  if ($sql_stmt == False) {
    echo "Database is busy";
    header("refresh:1;");
    exit;
  }
}

function db_log_error($db, $context, $sql = '') {
  $message = 'BirdNET-Pi database query failed';
  if ($context !== '') {
    $message .= ' [' . $context . ']';
  }
  if ($db instanceof SQLite3) {
    $message .= ': ' . $db->lastErrorMsg();
  }
  if ($sql !== '') {
    $message .= ' SQL: ' . $sql;
  }
  error_log($message);
}

function db_query_safe($db, $sql, $context = '') {
  $result = $db->query($sql);
  if ($result === false) {
    db_log_error($db, $context, $sql);
    return false;
  }
  return $result;
}

function db_execute_safe($db, $stmt, $context = '') {
  if ($stmt === false) {
    db_log_error($db, $context);
    return false;
  }
  $result = $stmt->execute();
  if ($result === false) {
    db_log_error($db, $context);
    return false;
  }
  return $result;
}

function db_fetch_assoc_safe($result) {
  if ($result === false || $result === null) {
    return null;
  }
  $row = $result->fetchArray(SQLITE3_ASSOC);
  return $row === false ? null : $row;
}

function db_query_all_safe($db, $sql, $context = '') {
  $rows = [];
  $result = db_query_safe($db, $sql, $context);
  if ($result === false) {
    return $rows;
  }
  while ($row = db_fetch_assoc_safe($result)) {
    $rows[] = $row;
  }
  return $rows;
}

function db_query_one_safe($db, $sql, $context = '') {
  $result = db_query_safe($db, $sql, $context);
  return db_fetch_assoc_safe($result);
}

function db_query_single_safe($db, $sql, $default = null, $context = '') {
  $value = $db->querySingle($sql);
  if ($value === false && $db->lastErrorCode() !== 0) {
    db_log_error($db, $context, $sql);
    return $default;
  }
  return $value === null ? $default : $value;
}

function set_timezone() {
  if (!isset($_SESSION['my_timezone'])) {
    $_SESSION['my_timezone'] = trim(shell_exec('timedatectl show --value --property=Timezone'));
  }
  date_default_timezone_set($_SESSION['my_timezone']);
}

function get_config($force_reload = false) {
  $mtime = stat('/etc/birdnet/birdnet.conf')["mtime"];
  if (isset($_SESSION['my_config_version']) && $_SESSION['my_config_version'] !== $mtime) {
    $force_reload = true;
  }
  if (!isset($_SESSION['my_config']) || $force_reload) {
    $source = preg_replace("~^#+.*$~m", "", file_get_contents('/etc/birdnet/birdnet.conf'));
    $my_config = parse_ini_string($source);
    if ($my_config) {
      $_SESSION['my_config'] = $my_config;
    } else {
      syslog(LOG_ERR, "Cannot parse config");
    }
    $_SESSION['my_config_version'] = $mtime;
  }
  return $_SESSION['my_config'];
}

function get_user() {
  $config = get_config();
  $user = $config['BIRDNET_USER'];
  return $user;
}

function get_home() {
  $home = '/home/' . get_user();
  return $home;
}

function get_sitename() {
  $config = get_config();

  if ($config["SITE_NAME"] == "") {
    $site_name = "BirdNET-Pi";
  } else {
    $site_name = $config['SITE_NAME'];
  }
  return $site_name;
}

function get_service_mount_name() {
  $home = get_home();
  $service_mount = trim(shell_exec("systemd-escape -p --suffix=mount " . $home . "/BirdSongs/StreamData"));
  return $service_mount;
}

function is_authenticated() {
  $ret = false;
  if (isset($_SERVER['PHP_AUTH_USER'])) {
    $config = get_config();
    $ret = ($_SERVER['PHP_AUTH_PW'] == $config['CADDY_PWD'] && $_SERVER['PHP_AUTH_USER'] == 'birdnet');
  }
  return $ret;
}

function is_protected_view($view) {
  $protected_views = [
    'Settings',
    'Advanced',
    'Included',
    'Excluded',
    'Whitelisted',
    'Species Management',
    'Services',
    'System Info',
    'Webterm',
    'Adminer',
    'File',
    'System Controls'
  ];
  return in_array($view, $protected_views);
}

function ensure_authenticated($error_message = 'You cannot edit the settings for this installation') {
  if (!is_authenticated()) {
    // Same realm as api.php: one protection space, so credentials cached by
    // signing in anywhere on the station work everywhere on it.
    header('WWW-Authenticate: Basic realm="BirdNET-Pi"');
    header('HTTP/1.0 401 Unauthorized');
    // If in an iframe and the browser blocks the popup, this body will be rendered.
    // We attempt to breakout and reload at the top level so the popup can show.
    echo '<script>if (window.top !== window.self) { window.top.location.reload(); }</script>';
    echo '<table><tr><td>' . $error_message . '</td></tr></table>';
    exit;
  }
}

function debug_log($message) {
  if (is_bool($message)) {
    $message = $message ? 'true' : 'false';
  }
  error_log($message . "\n", 3, $_SERVER['DOCUMENT_ROOT'] . "/debug_log.log");
}

function get_com_en_name($sci_name) {
  static $_labels_flickr = null;
  if ($_labels_flickr === null) {
    $_labels_flickr = json_decode(file_get_contents(__ROOT__ . "/model/l18n/labels_en.json"), true);
  }
  $engname = isset($_labels_flickr[$sci_name]) ? $_labels_flickr[$sci_name] : "";
  return $engname;
}

function get_label($record, $sort_by, $date=null) {
  $name = $record["Com_Name"];
  if ($sort_by == "confidence") {
    $ret = $name . ' (' . round($record['MaxConfidence'] * 100) . '%)';
  } elseif ($sort_by == "occurrences") {
    $valuescount = $record['Count'];
    if ($valuescount >= 1000) {
      $ret = $name . ' (' . round($valuescount / 1000, 1) . 'k)';
    } else {
      $ret = $name . ' (' . $valuescount . ')';
    }
  } elseif (($sort_by == "date") && !isset($date)) {
    $ret = $name . ' (' . $record['Date'] . ')';
  } elseif (($sort_by == "date") && isset($date)) {
    $ret = $name . ' (' . $record['Time'] . ')';
  } else {
    $ret = $name;
  }
  return $ret;
}

function get_db() {
  static $_db = null;
  if (!isset($_db)) {
    $_db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
    $_db->busyTimeout(1000);
  }
  return $_db;
}

function fetch_species_array($sort_by, $date=null) {
  $db = get_db();
  $where = (isset($date)) ? "WHERE Date == :date" : "";
  if ($sort_by === "occurrences") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY COUNT(*) DESC");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MAX(Confidence) DESC");
  } elseif ($sort_by === "date") {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY MIN(Date) DESC, Time DESC");
  } else {
    $statement = $db->prepare("SELECT Date, Time, File_Name, Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConfidence FROM detections $where GROUP BY Sci_Name ORDER BY Com_Name ASC");
  }
  ensure_db_ok($statement);
  if (isset($date)) {
    $statement->bindValue(':date', $date, SQLITE3_TEXT);
  }
  $result = db_execute_safe($db, $statement, 'fetch_species_array');
  return $result;
}

function fetch_best_detection($com_name) {
  $db = get_db();
  $statement = $db->prepare("SELECT Com_Name, Sci_Name, COUNT(*), MAX(Confidence), File_Name, Date, Time from detections WHERE Com_Name = :com_name");
  ensure_db_ok($statement);
  $statement->bindValue(':com_name', $com_name, SQLITE3_TEXT);
  $result = db_execute_safe($db, $statement, 'fetch_best_detection');
  return $result;
}

function fetch_all_detections($sci_name, $sort_by, $date=null) {
  $db = get_db();
  $filter = (isset($date)) ? "AND Date == :date" : "";
  if ($sort_by === "occurrences") {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == :sci_name $filter ORDER BY $order");
  } elseif ($sort_by === "confidence") {
    $statement = $db->prepare("SELECT * FROM detections WHERE Sci_Name == :sci_name $filter ORDER BY Confidence DESC");
  } else {
    $order = (isset($date)) ? "Time DESC" : "Date DESC, Time DESC";
    $statement = $db->prepare("SELECT * FROM detections where Sci_Name == :sci_name $filter ORDER BY $order");
  }
  ensure_db_ok($statement);
  $statement->bindValue(':sci_name', $sci_name, SQLITE3_TEXT);
  if (isset($date)) {
    $statement->bindValue(':date', $date, SQLITE3_TEXT);
  }
  $result = db_execute_safe($db, $statement, 'fetch_all_detections');
  return $result;
}

function get_summary() {
  $db = get_db();
  $totalcount = db_query_one_safe($db, 'SELECT COUNT(*) FROM detections', 'summary total detections') ?: ['COUNT(*)' => 0];
  $todaycount = db_query_one_safe($db, 'SELECT COUNT(*) FROM detections WHERE Date == DATE(\'now\', \'localtime\')', 'summary today detections') ?: ['COUNT(*)' => 0];
  $hourcount = db_query_one_safe($db, 'SELECT COUNT(*) FROM detections WHERE Date == Date(\'now\', \'localtime\') AND TIME >= TIME(\'now\', \'localtime\', \'-1 hour\')', 'summary last hour detections') ?: ['COUNT(*)' => 0];
  $todayspeciestally = db_query_one_safe($db, 'SELECT COUNT(DISTINCT(Sci_Name)) FROM detections WHERE Date == Date(\'now\',\'localtime\')', 'summary species today') ?: ['COUNT(DISTINCT(Sci_Name))' => 0];
  $totalspeciestally = db_query_one_safe($db, 'SELECT COUNT(DISTINCT(Sci_Name)) FROM detections', 'summary total species') ?: ['COUNT(DISTINCT(Sci_Name))' => 0];
  $topspeciesrow = db_query_one_safe($db, 'SELECT Com_Name, COUNT(*) as cnt FROM detections WHERE Date == Date(\'now\',\'localtime\') GROUP BY Sci_Name ORDER BY cnt DESC LIMIT 1', 'summary top species');
  $newspeciestally = db_query_one_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date = DATE('now', 'localtime') AND Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < DATE('now', 'localtime'))", 'summary new species') ?: ['COUNT(DISTINCT Sci_Name)' => 0];

  $ret = [
    'totalcount' => $totalcount['COUNT(*)'],
    'todaycount' => $todaycount['COUNT(*)'],
    'hourcount' => $hourcount['COUNT(*)'],
    'speciestally' => $todayspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'totalspeciestally' => $totalspeciestally['COUNT(DISTINCT(Sci_Name))'],
    'newspeciestally' => $newspeciestally['COUNT(DISTINCT Sci_Name)'],
    'topspecies' => $topspeciesrow ? $topspeciesrow['Com_Name'] : '',
    'topspeciescount' => $topspeciesrow ? $topspeciesrow['cnt'] : 0
  ];
  return $ret;
}

class ImageProvider {

  protected $db = null;
  protected $db_path = null;
  protected $db_reset = false;
  protected $context = null;

  public function __construct() {
    $this->set_db();
    $opts = [
      'http' => [
        'method' => "GET",
        'header' => "User-Agent: BirdNET-Pi/1.0 (https://github.com/mcguirepr89/BirdNET-Pi) PHP_Script",
        'timeout' => 5
      ]
    ];
    $this->context = stream_context_create($opts);
  }

  public function get_image($sci_name, $fallback_provider = null) {
    $log_path = __ROOT__ . '/scripts/birdnet_img.log';
    @file_put_contents($log_path, "[" . date('Y-m-d H:i:s') . "] Fetching $sci_name\n", FILE_APPEND);
    $image = $this->get_image_from_db($sci_name);
    if ($image !== false) {
      @file_put_contents($log_path, "  Found in DB: " . $image['image_url'] . "\n", FILE_APPEND);
      $now = new DateTime();
      $datetime = DateTime::createFromFormat("Y-m-d", $image['date_created']);
      $interval = $now->diff($datetime);
      $expire_days = rand(15, 25);
      if ($interval->days > $expire_days) {
        $image = false;
        @file_put_contents($log_path, "  Expired. Re-fetching.\n", FILE_APPEND);
      }
    }
    if ($image === false) {
      @file_put_contents($log_path, "  Not in DB, calling get_from_source\n", FILE_APPEND);
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }
    
    // If we still don't have an image and a fallback provider was given, try it
    if (($image === false || empty($image['image_url'])) && $fallback_provider !== null) {
      @file_put_contents($log_path, "  Wikipedia failed, falling back to Flickr\n", FILE_APPEND);
      return $fallback_provider->get_image($sci_name);
    }

    $url_status = $image ? $image['image_url'] : "FAILED ALL";
    @file_put_contents($log_path, "  Final Result: $url_status\n", FILE_APPEND);
    return $image;
  }

  public function is_reset() {
    return $this->db_reset;
  }

  protected function get_json($url) {
    $resp = @file_get_contents($url, false, $this->context);
    if ($resp === false) return false;
    return json_decode($resp, true);
  }

  protected function set_db() {
    try {
      if ($this->db === null) {
        $db = new SQLite3($this->db_path, SQLITE3_OPEN_READWRITE);
        $this->db = $db;
      }
    } catch (Exception $ex) {
      $this->create_tables();
    }
    $this->db->busyTimeout(1000);
  }

  protected function create_tables() {
    $tbl_def = "CREATE TABLE images (sci_name VARCHAR(63) NOT NULL PRIMARY KEY, com_en_name VARCHAR(63) NOT NULL, image_url TEXT NOT NULL, title TEXT NOT NULL, id TEXT NOT NULL UNIQUE, author_url TEXT NOT NULL, license_url TEXT NOT NULL, date_created DATE)";
    $db = new SQLite3($this->db_path);
    $db->exec($tbl_def);
    $db->exec('CREATE TABLE source (ID INTEGER PRIMARY KEY, email VARCHAR(63), uid VARCHAR(63), date_created DATE)');
    $this->db_reset = true;
    $this->db = $db;
  }

  protected function delete_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('DELETE FROM images WHERE sci_name == :sci_name');
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->execute();
  }

  protected function get_image_from_db($sci_name) {
    $statement0 = $this->db->prepare('SELECT sci_name, com_en_name, image_url, title, id, author_url, license_url, date_created FROM images WHERE sci_name == :sci_name');
    if ($statement0 === false) {
      db_log_error($this->db, 'image cache lookup prepare');
      return false;
    }
    $statement0->bindValue(':sci_name', $sci_name);
    $result = db_execute_safe($this->db, $statement0, 'image cache lookup');
    return db_fetch_assoc_safe($result) ?: false;
  }

  protected function set_image_in_db($sci_name, $com_en_name, $image_url, $title, $id, $author_url, $license_url) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO images VALUES (:sci_name, :com_en_name, :image_url, :title, :id, :author_url, :license_url, DATE(\"now\"))");
    if ($statement0 === false) {
      db_log_error($this->db, 'image cache insert prepare');
      return;
    }
    $statement0->bindValue(':sci_name', $sci_name);
    $statement0->bindValue(':com_en_name', $com_en_name);
    $statement0->bindValue(':image_url', $image_url);
    $statement0->bindValue(':title', $title);
    $statement0->bindValue(':id', $id);
    $statement0->bindValue(':author_url', $author_url);
    $statement0->bindValue(':license_url', $license_url);
    // A silently failed INSERT means the species is never cached and every
    // render repeats the provider's API calls - log it instead.
    if (@$statement0->execute() === false) {
      db_log_error($this->db, 'image cache insert');
    }
  }
}

// The single place that decides which image provider is primary and which is
// the fallback. Returns [null, null] when image lookups are disabled
// (IMAGE_PROVIDER unset or "None"): callers must skip lookups entirely - the
// Settings privacy text promises no outbound image requests in that state.
function make_image_provider($config) {
  if (empty($config['IMAGE_PROVIDER'])) {
    return [null, null];
  }
  $flickr = new Flickr();
  $wikipedia = new Wikipedia();
  return strtoupper($config['IMAGE_PROVIDER']) === 'FLICKR'
      ? [$flickr, $wikipedia]
      : [$wikipedia, $flickr];
}

// Per-session image cache shared by the species surfaces. Entries are
// [com_name, image_url, title, photos_url, author_url, license_url]; a species
// whose lookup failed is stored with an empty image_url so it is not
// re-queried on every poll - but a failed lookup is only trusted for
// SESSION_IMAGE_MISS_TTL seconds. Without the expiry one transient provider
// hiccup blanked a species' picture for the life of the browser session.
const SESSION_IMAGE_MISS_TTL = 600;

function session_image_lookup($cache_key, $com_name) {
  if (!isset($_SESSION[$cache_key])) {
    $_SESSION[$cache_key] = [];
  }
  $key = array_search($com_name, array_column($_SESSION[$cache_key], 0));
  if ($key === false) {
    return null;
  }
  $entry = $_SESSION[$cache_key][$key];
  if ($entry[1] === '' && time() - ($entry[6] ?? 0) > SESSION_IMAGE_MISS_TTL) {
    unset($_SESSION[$cache_key][$key]);
    // array_column() returns a packed list, so the cache must stay packed for
    // the lookup index to line up with the stored keys.
    $_SESSION[$cache_key] = array_values($_SESSION[$cache_key]);
    return null;
  }
  return $entry;
}

function session_image_store($cache_key, $com_name, $cached_image) {
  if ($cached_image && !empty($cached_image['image_url'])) {
    $entry = [$com_name, $cached_image['image_url'], $cached_image['title'], $cached_image['photos_url'], $cached_image['author_url'], $cached_image['license_url']];
  } else {
    $entry = [$com_name, '', 'Not Found', '', '', '', time()];
  }
  $_SESSION[$cache_key][] = $entry;
  return $entry;
}

// Cached entry for a species, querying the provider on a miss. The one place
// that owns the lookup -> fetch -> store order for every species surface.
function session_image_get($cache_key, $com_name, $sci_name, $image_provider, $fallback_provider) {
  $entry = session_image_lookup($cache_key, $com_name);
  if ($entry === null) {
    $entry = session_image_store($cache_key, $com_name, $image_provider->get_image($sci_name, $fallback_provider));
  }
  return $entry;
}

class Flickr extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/flickr_v4.db';

  private $flickr_api_key = null;
  private $args = "&license=2%2C3%2C4%2C5%2C6%2C9";
  private $blacklisted_ids = [];
  private $licenses_urls = [];
  private $flickr_email = null;
  private $comnameprefix = "%20bird";

  public function __construct() {
    parent::__construct();

    $blacklisted = get_home() . "/BirdNET-Pi/scripts/blacklisted_images.txt";
    if (file_exists($blacklisted)) {
      $blacklisted_file = file($blacklisted);
      if ($blacklisted_file) {
        $this->blacklisted_ids = array_map('trim', $blacklisted_file);
      }
    }
    $this->flickr_api_key = get_config()["FLICKR_API_KEY"];
    $this->flickr_email = get_config()["FLICKR_FILTER_EMAIL"];
    $source = $this->get_uid_from_db();
    if ($source['email'] !== $this->flickr_email) {
      // reset the DB
      $this->db->exec("DROP TABLE images;");
      $this->create_tables();
      if (!empty($this->flickr_email)) {
        $source = $this->get_uid_from_db();
        if ($source['email'] !== $this->flickr_email) {
          $this->get_uid_from_flickr();
          $source = $this->get_uid_from_db();
        }
      } else {
        $this->set_uid_in_db("");
      }
    }
    if (!empty($this->flickr_email)) {
      $this->args = "&user_id=" . $source['uid'];
      $this->comnameprefix = "";
    }
  }

  public function get_image($sci_name, $fallback_provider = null) {
    $image = parent::get_image_from_db($sci_name);
    if ($image !== false && in_array($image['id'], $this->blacklisted_ids)) {
      $image = false;
      $this->delete_image_from_db($sci_name);
    }
    if ($image === false) {
      $this->get_from_source($sci_name);
      $image = $this->get_image_from_db($sci_name);
    }

    // Fallback logic
    if (($image === false || empty($image['image_url'])) && $fallback_provider !== null) {
      return $fallback_provider->get_image($sci_name);
    }

    if ($image === false)
      return false;
    // external link to photo
    $photos_url = str_replace('/people/', '/photos/', $image['author_url'] . '/' . $image['id']);
    $image['photos_url'] = $photos_url;
    return $image;
  }

  private function get_from_source($sci_name) {
    $engname = get_com_en_name($sci_name);
    if (empty($engname)) {
        // Fallback to sci name if no english name found
        $engname = $sci_name;
    }

    $url = "https://www.flickr.com/services/rest/?method=flickr.photos.search&api_key=" . $this->flickr_api_key . "&text=" . urlencode($engname) . $this->comnameprefix . "&sort=relevance" . $this->args . "&per_page=5&media=photos&format=json&nojsoncallback=1";
    $response = file_get_contents($url, false, $this->context);
    if ($response === false) return;
    
    $data = json_decode($response, true);
    if (!isset($data["photos"]["photo"])) return;
    
    $flickrjson = $data["photos"]["photo"];
    
    // Find the first photo that is not blacklisted or is not the specific blacklisted id
    $photo = null;
    foreach ($flickrjson as $flickrphoto) {
      if ($flickrphoto["id"] !== "4892923285" && !in_array($flickrphoto["id"], $this->blacklisted_ids)) {
        $photo = $flickrphoto;
        break;
      }
    }

    if ($photo === null)
      return;

    $info_url = "https://api.flickr.com/services/rest/?method=flickr.photos.getInfo&api_key=" . $this->flickr_api_key . "&photo_id=" . $photo["id"] . "&format=json&nojsoncallback=1";
    $license_response = $this->get_json($info_url);
    if (!isset($license_response["photo"])) return;
    
    $license_id = $license_response["photo"]["license"];
    $license_url = $this->get_license_url($license_id);

    $authorlink = "https://flickr.com/people/" . $photo["owner"];
    // Using _b suffix for 1024px resolution (was using default which is often ~500px)
    $imageurl = 'https://farm' . $photo["farm"] . '.static.flickr.com/' . $photo["server"] . '/' . $photo["id"] . '_' . $photo["secret"] . '_b.jpg';

    $this->set_image_in_db($sci_name, $engname, $imageurl, $photo["title"], $photo["id"], $authorlink, $license_url);
  }

  private function get_license_url($id) {
    if (empty($this->licenses_urls)) {
      $licenses_url = "https://api.flickr.com/services/rest/?method=flickr.photos.licenses.getInfo&api_key=" . $this->flickr_api_key . "&format=json&nojsoncallback=1";
      $licenses_response = $this->get_json($licenses_url);
      $licenses_data = is_array($licenses_response) ? ($licenses_response["licenses"]["license"] ?? null) : null;
      if (!is_array($licenses_data)) {
        // A failed licenses call must not return null: license_url is NOT NULL
        // in the cache schema, so a null silently voids the INSERT and the
        // species re-runs all three Flickr calls on every subsequent render.
        return '';
      }
      foreach ($licenses_data as $license) {
        $license_id = $license["id"];
        $license_url = $license["url"];
        $this->licenses_urls[$license_id] = $license_url;
      }
    }
    return $this->licenses_urls[$id] ?? '';
  }

  public function get_uid_from_db() {
    $statement0 = $this->db->prepare('SELECT email, uid, date_created FROM source');
    $result = db_execute_safe($this->db, $statement0, 'flickr uid lookup');
    return db_fetch_assoc_safe($result) ?: ['email' => '', 'uid' => '', 'date_created' => ''];
  }

  private function set_uid_in_db($uid) {
    $statement0 = $this->db->prepare("INSERT OR REPLACE INTO source VALUES (1, :email, :uid, DATE(\"now\"))");
    $statement0->bindValue(':email', $this->flickr_email);
    $statement0->bindValue(':uid', $uid);
    db_execute_safe($this->db, $statement0, 'flickr uid save');
    return true;
  }

  private function get_uid_from_flickr() {
    $url = "https://www.flickr.com/services/rest/?method=flickr.people.findByEmail&api_key=" . $this->flickr_api_key . "&find_email=" . $this->flickr_email . "&format=json&nojsoncallback=1";
    $resp = @file_get_contents($url, false, $this->context);
    if ($resp === false) return;
    $data = json_decode($resp, true);
    if (isset($data["user"]["nsid"])) {
      $uid = $data["user"]["nsid"];
      $this->set_uid_in_db($uid);
    }
  }
}

class Wikipedia extends ImageProvider {

  protected $db_path = __ROOT__ . '/scripts/wikipedia_v4.db';

  protected function get_from_source($sci_name) {
    $titles_to_try = [str_replace(' ', '_', $sci_name)];
    $engname = get_com_en_name($sci_name);
    if (!empty($engname)) {
      $titles_to_try[] = str_replace(' ', '_', $engname);
    }

    foreach ($titles_to_try as $page_title) {
      $data = $this->get_json("https://en.wikipedia.org/api/rest_v1/page/summary/" . urlencode($page_title));
        if ($data != false && isset($data['originalimage'])) {
          $image_url = trim($data['originalimage']['source'], " \t\n\r\0\x0B\"");
          $title = $data['title'];
          $image_name = urldecode(substr($image_url, strrpos($image_url, '/') + 1));
          
          $author_url = $this->get_external_link($image_url);
          $license_url = $this->get_external_link($image_url);
          $author = 'Wikipedia';

          $metadata = $this->get_json("https://commons.wikimedia.org/w/api.php?action=query&titles=File:" . urlencode($image_name) . "&prop=imageinfo&iiprop=url|extmetadata|size&iiurlwidth=1024&format=json");
          
          if ($metadata != false && isset($metadata['query']['pages'])) {
            foreach ($metadata['query']['pages'] as $page) {
              if (isset($page['imageinfo']['0'])) {
                $info = $page['imageinfo']['0'];
                
                // Use the official thumbnail URL if provided
                if (isset($info['thumburl'])) {
                  $image_url = $info['thumburl'];
                }

                $details = $info['extmetadata'];
                $author = isset($details['Artist']) ? strip_tags($details['Artist']['value']) : 'Unknown';
                if (preg_match('/href="(http\S*)"/', (isset($details['Artist']) ? $details['Artist']['value'] : ''), $matches)) {
                  $author_url = $matches[1];
                }
                if (isset($details['LicenseUrl'])) {
                  $license_url = $details['LicenseUrl']['value'];
                }
              }
            }
          }

          $this->set_image_in_db($sci_name, $engname ?: $sci_name, $image_url, $title, $sci_name, $author_url, $license_url);
          return; // Success
        }
      }
  }

  public function get_image($sci_name, $fallback_provider = null) {
    $image = parent::get_image($sci_name, $fallback_provider);
    if ($image === false)
      return false;

    // Only use get_external_link if the image is actually from Wikipedia
    if (strpos($image['image_url'], 'wikimedia.org') !== false) {
      $image['photos_url'] = $this->get_external_link($image['image_url']);
    } else {
      // If it's a fallback (e.g. from Flickr), it should already have a photo_url,
      // but we ensure it's set. ImageProvider doesn't set it by default.
      // Flickr::get_image sets it, so if we got here from Flickr, it might already be there.
    }
    return $image;
  }

  private function get_external_link($image_url) {
    if (strpos($image_url, '/commons/thumb/') !== false) {
      $parts = explode('/', $image_url);
      $image_name = $parts[count($parts) - 2];
    } else {
      $image_name = substr($image_url, strrpos($image_url, '/') + 1);
    }
    $photo_url = "https://en.wikipedia.org/wiki/File:$image_name";
    return $photo_url;
  }
}

/* Wikipedia link in the user's DATABASE_LANG edition (the setting that
   already localizes species common names; eBird links follow it too).
   Scientific-name URLs redirect to the local article in every sizable
   edition, so no per-language title lookup is needed. Only outbound links
   localize - the image/summary fetchers stay on en.wikipedia, where photo
   coverage is best. */
function get_wikipedia_url($sciname) {
  $config = get_config();
  $lang = isset($config['DATABASE_LANG']) ? strtolower(trim($config['DATABASE_LANG'])) : '';
  // Subdomains are the tag's language part: pt-BR -> pt. 'not-selected'
  // (and anything else non-language-shaped) falls back to English.
  $lang = substr($lang, 0, 2);
  if (!preg_match('/^[a-z]{2}$/', $lang) || (isset($config['DATABASE_LANG']) && $config['DATABASE_LANG'] === 'not-selected')) {
    $lang = 'en';
  }
  return 'https://' . $lang . '.wikipedia.org/wiki/' . str_replace('%20', '_', rawurlencode($sciname));
}

function get_info_url($sciname){
  $engname = get_com_en_name($sciname);
  $config = get_config();
  if ($config['INFO_SITE'] === 'EBIRD'){
    require 'scripts/ebird.php';
    $ebird = $ebirds[$sciname];
    $language = $config['DATABASE_LANG'];
    $url = "https://ebird.org/species/$ebird?siteLanguage=$language";
    $url_title = "eBirds";
  } else {
    $engname_url = str_replace("'", '', str_replace(' ', '_', $engname));
    $url = "https://allaboutbirds.org/guide/$engname_url";
    $url_title = "All About Birds";
  }
  $ret = array(
      'URL' => $url,
      'TITLE' => $url_title
          );
  return $ret;
}

function get_color_scheme(){
  $config = get_config();
  if (strtolower($config['COLOR_SCHEME']) === 'dark'){
    return 'static/dark-style.css';
  } else {
    return 'style.css';
  }
}

/* ===== Lightweight file cache (Phase 0) =====
   Rendered fragments and computed aggregates are keyed on the detections
   watermark (MAX rowid), so any new detection invalidates them automatically. */

function birdnet_cache_dir() {
  $dir = sys_get_temp_dir() . '/birdnet_cache';
  if (!is_dir($dir)) {
    @mkdir($dir, 0777, true);
  }
  return $dir;
}

function detections_watermark() {
  static $watermark = null;
  if ($watermark === null) {
    $db = get_db();
    $max_id = (string) db_query_single_safe($db, 'SELECT MAX(rowid) FROM detections', 0, 'cache watermark');
    // Review verdicts change what cached fragments should show, so they are
    // part of the watermark (created_at refreshes on re-review upserts).
    $review_mark = spine_table_exists($db, 'detection_reviews')
      ? (string) db_query_single_safe($db, "SELECT COUNT(*) || '-' || COALESCE(MAX(created_at), '') FROM detection_reviews", '', 'cache watermark reviews')
      : '';
    $watermark = $max_id . '|' . $review_mark;
  }
  return $watermark;
}

/* Predicate excluding detections the user reviewed as false positives or
   hid. Returns '' when the reviews table doesn't exist yet; callers prepend
   WHERE/AND as appropriate. $column lets joined queries qualify File_Name. */
function review_exclusion_sql($db, $column = 'File_Name') {
  if (!spine_table_exists($db, 'detection_reviews')) {
    return '';
  }
  return "$column NOT IN (SELECT file_name FROM detection_reviews WHERE status IN ('false_positive', 'hidden'))";
}

function and_review_exclusion($db, $column = 'File_Name') {
  $predicate = review_exclusion_sql($db, $column);
  return $predicate === '' ? '' : ' AND ' . $predicate;
}

function birdnet_cache_key(...$parts) {
  return 'bnp_' . md5(implode('|', array_map('strval', $parts)));
}

function birdnet_cache_get($key, $max_age = 21600) {
  $file = birdnet_cache_dir() . '/' . $key . '.cache';
  if (!is_file($file) || (time() - @filemtime($file)) > $max_age) {
    return false;
  }
  $data = @file_get_contents($file);
  return $data === false ? false : $data;
}

function birdnet_cache_put($key, $content) {
  @file_put_contents(birdnet_cache_dir() . '/' . $key . '.cache', $content, LOCK_EX);
}

/* ===== Data spine (Phase 1): visits, reviews, species prefs, notes =====
   A "visit" groups successive detections of the same species on the same day
   when the gap between them stays under VISIT_GAP_MINUTES (default 5).
   Visits are derived at query time; the detections table is never modified. */

function time_to_seconds($time_str) {
  $parts = explode(':', (string)$time_str);
  return intval($parts[0]) * 3600 + intval($parts[1] ?? 0) * 60 + intval($parts[2] ?? 0);
}

function get_visit_gap_seconds() {
  $config = get_config();
  $minutes = 5;
  if (isset($config['VISIT_GAP_MINUTES']) && is_numeric($config['VISIT_GAP_MINUTES']) && $config['VISIT_GAP_MINUTES'] > 0) {
    $minutes = (float)$config['VISIT_GAP_MINUTES'];
  }
  return (int)round($minutes * 60);
}

/* ===== Display units =====
   Weather is always STORED in Fahrenheit / mph (weather.py fetches it that
   way); only the display path converts. Storing raw values in one fixed unit
   keeps a unit switch fully reversible and historical stats consistent. */

function get_temp_unit() {
  $config = get_config();
  return (isset($config['TEMPERATURE_UNIT']) && strtolower($config['TEMPERATURE_UNIT']) === 'celsius') ? 'C' : 'F';
}

function temp_unit_suffix() {
  return get_temp_unit() === 'C' ? '°C' : '°F';
}

function display_temp($fahrenheit, $decimals = 0) {
  if ($fahrenheit === null || $fahrenheit === '') {
    return null;
  }
  $value = (float)$fahrenheit;
  if (get_temp_unit() === 'C') {
    $value = ($value - 32) * 5 / 9;
  }
  return round($value, $decimals);
}

function get_wind_unit() {
  $config = get_config();
  $unit = isset($config['WIND_SPEED_UNIT']) ? strtolower($config['WIND_SPEED_UNIT']) : 'mph';
  return in_array($unit, ['kmh', 'ms'], true) ? $unit : 'mph';
}

function wind_unit_label() {
  $unit = get_wind_unit();
  return $unit === 'kmh' ? 'km/h' : ($unit === 'ms' ? 'm/s' : 'mph');
}

function display_wind($mph, $decimals = 0) {
  if ($mph === null || $mph === '') {
    return null;
  }
  $value = (float)$mph;
  $unit = get_wind_unit();
  if ($unit === 'kmh') {
    $value *= 1.609344;
  } elseif ($unit === 'ms') {
    $value *= 0.44704;
  }
  return round($value, $decimals);
}

function get_time_format() {
  $config = get_config();
  return (isset($config['TIME_FORMAT']) && trim($config['TIME_FORMAT']) === '24') ? '24' : '12';
}

/* Number display style: 'point' 1,234.5 (default) / 'comma' 1.234,5 /
   'space' 1 234,5. Display-only, like the units above - data paths (CSV
   exports, JSON APIs) must keep machine format and never call these. */
function get_number_format() {
  $config = get_config();
  $style = isset($config['NUMBER_FORMAT']) ? strtolower(trim($config['NUMBER_FORMAT'])) : 'point';
  return in_array($style, ['comma', 'space'], true) ? $style : 'point';
}

function number_format_separators() {
  switch (get_number_format()) {
    case 'comma': return [',', '.'];
    case 'space': return [',', "\u{00A0}"]; // no-break space so counts don't wrap
    default:      return ['.', ','];
  }
}

function format_number($n, $decimals = 0) {
  if ($n === null || $n === '') {
    return '';
  }
  list($dec, $thou) = number_format_separators();
  return number_format((float)$n, $decimals, $dec, $thou);
}

/* BCP-47 locale whose number formatting matches the setting, for JS
   toLocaleString on the client. */
function number_format_js_locale() {
  switch (get_number_format()) {
    case 'comma': return 'de-DE';
    case 'space': return 'fr-FR';
    default:      return 'en-US';
  }
}

/* Whole hour (0-23) as an axis/section label: "5 AM" or "05:00". */
function format_hour_label($hour) {
  $hour = ((int)$hour % 24 + 24) % 24;
  if (get_time_format() === '24') {
    return sprintf('%02d:00', $hour);
  }
  if ($hour === 0) return '12 AM';
  if ($hour < 12) return $hour . ' AM';
  if ($hour === 12) return '12 PM';
  return ($hour - 12) . ' PM';
}

/* Minutes after midnight as a clock time: "4:37 AM" or "04:37". */
function format_minutes_label($minutes) {
  $minutes = max(0, (int)round($minutes));
  $h = intdiv($minutes, 60) % 24;
  $m = $minutes % 60;
  if (get_time_format() === '24') {
    return sprintf('%02d:%02d', $h, $m);
  }
  $ampm = $h < 12 ? 'AM' : 'PM';
  $h12 = $h % 12 ?: 12;
  return sprintf('%d:%02d %s', $h12, $m, $ampm);
}

/* "HH:MM[:SS]" (DB Time column) as a clock time in the configured format. */
function format_time_label($time) {
  $h = (int)substr($time, 0, 2);
  $m = (int)substr($time, 3, 2);
  return format_minutes_label($h * 60 + $m);
}

/* Owner-authored front-page info box (Settings > Display & Units). Lives in
   its own file because multi-line HTML can't be a birdnet.conf value. */
function custom_info_path() {
  return __ROOT__ . '/custom_info.html';
}

function get_custom_info_html() {
  $path = custom_info_path();
  if (!is_file($path)) {
    return '';
  }
  return trim((string)@file_get_contents($path));
}

/* $rows must be sorted by Date ASC, Time ASC and contain
   Date, Time, Sci_Name, Com_Name, Confidence, File_Name. */
function visits_from_detections($rows, $gap_seconds = null) {
  if ($gap_seconds === null) {
    $gap_seconds = get_visit_gap_seconds();
  }
  $visits = [];
  $open = []; // sci_name => index of the currently open visit in $visits
  foreach ($rows as $row) {
    $sci = $row['Sci_Name'];
    $secs = time_to_seconds($row['Time']);
    $conf = round((float)$row['Confidence'], 4);

    $idx = isset($open[$sci]) ? $open[$sci] : -1;
    if ($idx >= 0 && ($visits[$idx]['date'] !== $row['Date'] || $secs - $visits[$idx]['last_secs'] > $gap_seconds)) {
      $idx = -1;
    }
    if ($idx < 0) {
      $visits[] = [
        'species' => $row['Com_Name'],
        'sci_name' => $sci,
        'date' => $row['Date'],
        'first_time' => $row['Time'],
        'last_time' => $row['Time'],
        'count' => 0,
        'best_confidence' => 0.0,
        'best_file' => $row['File_Name'],
        'detections' => [],
        'last_secs' => $secs
      ];
      $idx = count($visits) - 1;
      $open[$sci] = $idx;
    }
    $visits[$idx]['count']++;
    $visits[$idx]['last_time'] = $row['Time'];
    $visits[$idx]['last_secs'] = $secs;
    if ($conf > $visits[$idx]['best_confidence']) {
      $visits[$idx]['best_confidence'] = $conf;
      $visits[$idx]['best_file'] = $row['File_Name'];
    }
    $visits[$idx]['detections'][] = [
      'time' => $row['Time'],
      'confidence' => $conf,
      'file' => $row['File_Name']
    ];
  }
  foreach ($visits as $i => $v) {
    unset($visits[$i]['last_secs']);
  }
  return $visits;
}

/* Options: date (Y-m-d) | days (int, capped at 90; default today only),
   sci_name, include_detections (bool), gap_seconds (int). */
function get_visits($db, $options = []) {
  $where = [];
  $params = [];
  if (!empty($options['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $options['date'])) {
    $where[] = 'Date = :date';
    $params[':date'] = $options['date'];
  } elseif (!empty($options['days'])) {
    $days = min(90, max(1, intval($options['days'])));
    $where[] = "Date >= DATE('now', 'localtime', '-$days days')";
  } else {
    $where[] = "Date = DATE('now', 'localtime')";
  }
  if (!empty($options['sci_name'])) {
    $where[] = 'Sci_Name = :sci';
    $params[':sci'] = $options['sci_name'];
  }
  $sql = 'SELECT Date, Time, Sci_Name, Com_Name, Confidence, File_Name FROM detections WHERE '
       . implode(' AND ', $where) . ' ORDER BY Date ASC, Time ASC';
  $stmt = $db->prepare($sql);
  if ($stmt === false) {
    db_log_error($db, 'get_visits prepare', $sql);
    return [];
  }
  foreach ($params as $name => $value) {
    $stmt->bindValue($name, $value, SQLITE3_TEXT);
  }
  $result = db_execute_safe($db, $stmt, 'get_visits');
  $rows = [];
  while ($row = db_fetch_assoc_safe($result)) {
    $rows[] = $row;
  }
  $gap = isset($options['gap_seconds']) ? intval($options['gap_seconds']) : null;
  $visits = visits_from_detections($rows, $gap);
  if (empty($options['include_detections'])) {
    foreach ($visits as $i => $v) {
      unset($visits[$i]['detections']);
    }
  }
  return $visits;
}

function ensure_spine_tables($db_rw) {
  require_once __ROOT__ . '/scripts/spine_schema.php';
  foreach (spine_schema_statements_standalone() as $sql) {
    if ($db_rw->exec($sql) === false) {
      db_log_error($db_rw, 'ensure spine tables', $sql);
    }
  }
}

function spine_table_exists($db, $table) {
  static $cache = [];
  if (!isset($cache[$table])) {
    $cache[$table] = db_query_single_safe($db,
      "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='" . SQLite3::escapeString($table) . "'",
      0, 'spine table check') > 0;
  }
  return $cache[$table];
}

/* Returns [file_name => status] for the given detection file names. */
function get_review_map($db, $file_names) {
  $map = [];
  if (empty($file_names) || !spine_table_exists($db, 'detection_reviews')) {
    return $map;
  }
  foreach (array_chunk(array_values(array_unique($file_names)), 200) as $chunk) {
    $placeholders = implode(',', array_fill(0, count($chunk), '?'));
    $stmt = $db->prepare("SELECT file_name, status FROM detection_reviews WHERE file_name IN ($placeholders)");
    if ($stmt === false) {
      db_log_error($db, 'review map prepare');
      return $map;
    }
    foreach ($chunk as $i => $name) {
      $stmt->bindValue($i + 1, $name, SQLITE3_TEXT);
    }
    $result = db_execute_safe($db, $stmt, 'review map');
    while ($row = db_fetch_assoc_safe($result)) {
      $map[$row['file_name']] = $row['status'];
    }
  }
  return $map;
}

function get_species_prefs_row($db, $sci_name) {
  if (!spine_table_exists($db, 'species_prefs')) {
    return null;
  }
  $stmt = $db->prepare('SELECT * FROM species_prefs WHERE sci_name = :sci');
  if ($stmt === false) {
    return null;
  }
  $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
  return db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'species prefs row'));
}

/* ===== Two-axis rarity (Phase 4) =====
   Region-rare: the cached location model (scripts/seasonal_cache.json,
   written by get_seasonal_expected.py) expects nearly zero occurrence for
   this species at this location in the current model week.
   Yard-rare: very few lifetime detections at this particular station. */

define('REGION_RARE_THRESHOLD', 0.05);
define('YARD_RARE_LIFETIME_MAX', 5);

function seasonal_expected_scores() {
  static $scores = null;
  if ($scores === null) {
    $scores = [];
    $path = __ROOT__ . '/scripts/seasonal_cache.json';
    if (is_readable($path)) {
      $json = json_decode(@file_get_contents($path), true);
      if (is_array($json) && isset($json['data']) && is_array($json['data'])) {
        $scores = $json['data'];
      }
    }
  }
  return $scores;
}

/* The location model uses 48 "weeks": four segments per month. */
function birdnet_week($date = null) {
  $ts = $date ? strtotime($date) : time();
  $month = (int)date('n', $ts);
  $day = (int)date('j', $ts);
  return ($month - 1) * 4 + min(3, intdiv($day - 1, 7)) + 1;
}

function region_rarity_score_for($sci_name, $week) {
  $data = seasonal_expected_scores();
  if (!isset($data[$sci_name]) || !is_array($data[$sci_name])) {
    return null;
  }
  $idx = max(0, min(count($data[$sci_name]) - 1, $week - 1));
  return isset($data[$sci_name][$idx]) ? (float)$data[$sci_name][$idx] : null;
}

/* Two ways to be region-rare, both judged against the species' own profile
   so an uncommon-but-regular resident hovering just under the absolute
   threshold all year doesn't get flagged every single week:
   - vagrant: the model expects (almost) none here in ANY week, or
   - out of season: expected almost none now AND well below the species'
     own seasonal peak at this location. */
function is_region_rare($sci_name, $date = null) {
  $data = seasonal_expected_scores();
  if (!isset($data[$sci_name]) || !is_array($data[$sci_name]) || empty($data[$sci_name])) {
    return false;
  }
  $freqs = array_map('floatval', $data[$sci_name]);
  $annual_max = max($freqs);
  if ($annual_max < 0.02) {
    return true; // vagrant: never really expected at this location
  }
  $idx = max(0, min(count($freqs) - 1, birdnet_week($date) - 1));
  $score = $freqs[$idx];
  return $score < REGION_RARE_THRESHOLD && $score < 0.25 * $annual_max;
}

/* ===== Crowned clips: purge protection =====
   Extracted clips live at By_Date/<date>/<species dir>/<file>; the species
   dir strips apostrophes and replaces spaces with underscores (classes.py).
   disk_check.sh / disk_species_clean.sh skip exact lines found in
   disk_check_exclude.txt, including the clip's .png spectrogram twin. */

function detection_clip_relative_path($date, $com_name, $file_name) {
  $dir = str_replace("'", '', $com_name);
  $dir = str_replace(' ', '_', $dir);
  return $date . '/' . $dir . '/' . $file_name;
}

function purge_exclude_path() {
  return __ROOT__ . '/scripts/disk_check_exclude.txt';
}

function purge_protect_add($relative) {
  $path = purge_exclude_path();
  if (!file_exists($path)) {
    if (@file_put_contents($path, "##start\n##end\n") === false) {
      return false;
    }
  }
  $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
  foreach ([$relative, $relative . '.png'] as $entry) {
    if (!in_array($entry, $lines, true)) {
      @file_put_contents($path, $entry . "\n", FILE_APPEND);
    }
  }
  return true;
}

function purge_protected($relative) {
  $path = purge_exclude_path();
  if (!file_exists($path)) {
    return false;
  }
  $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
  return in_array($relative, $lines, true);
}

/* ===== Best recordings: one definition shared by the Birds page, the
   Species Stats page, and purge protection ===== */

// Highest confidence first; ties go to the most recent clip (more likely to
// still exist, reflects the current mic and placement, and ages out of the
// per-species cleanup last); File_Name makes the order fully deterministic so
// what the page shows is exactly what cleanup protects.
const BEST_RECORDING_ORDER = 'Confidence DESC, Date DESC, Time DESC, File_Name DESC';

// How many of each species' best recordings survive disk cleanup. The
// allowed values live here only; config.php's save and render use them too.
const PROTECTED_RECORDINGS_CHOICES = ['1', '2', '3'];
const PROTECTED_RECORDINGS_DEFAULT = '2';

function protected_recordings_per_species() {
  $config = get_config();
  $n = (string)($config['PROTECTED_RECORDINGS_PER_SPECIES'] ?? PROTECTED_RECORDINGS_DEFAULT);
  return (int)(in_array($n, PROTECTED_RECORDINGS_CHOICES, true) ? $n : PROTECTED_RECORDINGS_DEFAULT);
}

// Where extracted clips live. Resolved once per request: the existence
// filter calls this for every candidate of every species.
function clip_base_dir() {
  static $base = null;
  if ($base === null) {
    $config = get_config();
    $extracted = $config['EXTRACTED'] ?? '';
    // Only trust an absolute, already-expanded path; anything else (empty, a
    // relative path, an unexpanded "$HOME/...") falls back to the default.
    if ($extracted === '' || $extracted[0] !== '/' || strpos($extracted, '$') !== false) {
      $extracted = get_home() . '/BirdSongs/Extracted';
    }
    $base = rtrim($extracted, '/') . '/By_Date';
  }
  return $base;
}

function clip_absolute_path($relative) {
  return clip_base_dir() . '/' . $relative;
}

function clip_exists($relative) {
  return is_file(clip_absolute_path($relative));
}

function clip_relative_for_file($db, $file_name) {
  $stmt = $db->prepare('SELECT Date, Com_Name FROM detections WHERE File_Name = :f LIMIT 1');
  if ($stmt === false) {
    return null;
  }
  $stmt->bindValue(':f', $file_name, SQLITE3_TEXT);
  $row = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'clip relative lookup'));
  return $row ? detection_clip_relative_path($row['Date'], $row['Com_Name'], $file_name) : null;
}

// Best-first candidates for one species. Detections reviewed as false
// positives or hidden are excluded: a confidently wrong detection must never
// become a featured, permanently protected "best recording".
// Returns false on a query failure - callers that delete things must be able
// to tell "nothing found" from "could not look".
function species_best_candidates($db, $sci_name, $limit = 50, $offset = 0) {
  $stmt = $db->prepare('SELECT Date, Time, Confidence, Com_Name, File_Name FROM detections WHERE Sci_Name = :sci' . and_review_exclusion($db) . ' ORDER BY ' . BEST_RECORDING_ORDER . ' LIMIT ' . (int)$limit . ' OFFSET ' . (int)$offset);
  if ($stmt === false) {
    db_log_error($db, 'species best candidates prepare');
    return false;
  }
  $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
  $res = db_execute_safe($db, $stmt, 'species best candidates');
  if ($res === false) {
    return false;
  }
  $rows = [];
  while ($row = db_fetch_assoc_safe($res)) {
    $row['clip_path'] = detection_clip_relative_path($row['Date'], $row['Com_Name'], $row['File_Name']);
    $rows[] = $row;
  }
  return $rows;
}

// Best-first candidates for every species in one pass (purge protection).
// Returns Sci_Name => rows, each capped at $per_species candidates, or false
// on a query failure.
function all_species_best_candidates($db, $per_species = 50) {
  $sql = 'SELECT Sci_Name, Date, Time, Confidence, Com_Name, File_Name FROM ('
       . 'SELECT Sci_Name, Date, Time, Confidence, Com_Name, File_Name, '
       . 'ROW_NUMBER() OVER (PARTITION BY Sci_Name ORDER BY ' . BEST_RECORDING_ORDER . ') AS rn '
       . 'FROM detections WHERE 1=1' . and_review_exclusion($db) . ') WHERE rn <= ' . (int)$per_species
       . ' ORDER BY Sci_Name, rn';
  $res = db_query_safe($db, $sql, 'all species best candidates');
  if ($res === false) {
    return false;
  }
  $by_species = [];
  while ($row = db_fetch_assoc_safe($res)) {
    $row['clip_path'] = detection_clip_relative_path($row['Date'], $row['Com_Name'], $row['File_Name']);
    $by_species[$row['Sci_Name']][] = $row;
  }
  return $by_species;
}

// The first $keep of $candidates whose audio still exists on disk.
function filter_surviving_clips($candidates, $keep) {
  $found = [];
  foreach ($candidates as $row) {
    if (clip_exists($row['clip_path'])) {
      $found[] = $row;
      if (count($found) >= $keep) {
        break;
      }
    }
  }
  return $found;
}

// A species' best $keep recordings that still exist on disk, paging through
// candidates from $offset until enough survive or $max_scan rows have been
// examined. A fixed window was not enough: on a long-running station a common
// species' top-50 clips can all be purged while hundreds of others remain.
// Returns false on a query failure.
function species_best_surviving($db, $sci_name, $keep, $offset = 0, $max_scan = 2000) {
  $found = [];
  $page = 50;
  while (count($found) < $keep && $offset < $max_scan) {
    $batch = species_best_candidates($db, $sci_name, $page, $offset);
    if ($batch === false) {
      return false;
    }
    foreach (filter_surviving_clips($batch, $keep - count($found)) as $row) {
      $found[] = $row;
    }
    if (count($batch) < $page) {
      break;
    }
    $offset += $page;
  }
  return $found;
}

// The single answer to "what is this species' best recording": the pinned
// clip if it still exists (and was not reviewed away), else the best
// surviving candidate. purged_best names a higher-scoring clip that cleanup
// removed - the page stays quiet about purged clips otherwise.
function species_best_recording($db, $sci_name, $prefs) {
  $top = species_best_candidates($db, $sci_name, 1);
  $all_time_best = ($top !== false && $top) ? $top[0] : null;

  $best = null;
  $pinned = false;
  if (!empty($prefs['crowned_clip'])) {
    $stmt = $db->prepare('SELECT Date, Time, Confidence, Com_Name, File_Name FROM detections WHERE File_Name = :f AND Sci_Name = :sci' . and_review_exclusion($db) . ' LIMIT 1');
    if ($stmt !== false) {
      $stmt->bindValue(':f', $prefs['crowned_clip'], SQLITE3_TEXT);
      $stmt->bindValue(':sci', $sci_name, SQLITE3_TEXT);
      $pin = db_fetch_assoc_safe(db_execute_safe($db, $stmt, 'species pinned clip'));
      if ($pin) {
        $pin['clip_path'] = detection_clip_relative_path($pin['Date'], $pin['Com_Name'], $pin['File_Name']);
        if (clip_exists($pin['clip_path'])) {
          $best = $pin;
          $pinned = true;
        }
      }
    }
  }
  if ($best === null) {
    $surviving = species_best_surviving($db, $sci_name, 1);
    $best = ($surviving !== false && $surviving) ? $surviving[0] : null;
  }

  $purged_best = null;
  if ($all_time_best && !clip_exists($all_time_best['clip_path'])
      && (!$best || (float)$all_time_best['Confidence'] > (float)$best['Confidence'])) {
    $purged_best = ['date' => $all_time_best['Date'], 'confidence' => round((float)$all_time_best['Confidence'], 4)];
  }

  return [
    'best_confidence' => $all_time_best ? round((float)$all_time_best['Confidence'], 4) : null,
    'best_recording' => $best ? [
      'date' => $best['Date'],
      'time' => $best['Time'],
      'confidence' => round((float)$best['Confidence'], 4),
      'file' => $best['File_Name'],
      'clip_path' => $best['clip_path'],
      'pinned' => $pinned
    ] : null,
    'purged_best' => $purged_best
  ];
}

function purge_protect_remove($relative) {
  $path = purge_exclude_path();
  if (!file_exists($path)) {
    return;
  }
  $lines = @file($path, FILE_IGNORE_NEW_LINES) ?: [];
  $keep = [];
  foreach ($lines as $line) {
    if ($line === $relative || $line === $relative . '.png') {
      continue;
    }
    $keep[] = $line;
  }
  @file_put_contents($path, implode("\n", $keep) . "\n");
}
