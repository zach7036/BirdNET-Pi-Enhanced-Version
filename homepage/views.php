<?php

/* Prevent XSS input */
$_GET   = filter_input_array(INPUT_GET, FILTER_SANITIZE_FULL_SPECIAL_CHARS);
$_POST  = filter_input_array(INPUT_POST, FILTER_SANITIZE_FULL_SPECIAL_CHARS);

session_start();

require_once 'scripts/common.php';
$user = get_user();
$home = get_home();
$config = get_config();
$color_scheme = get_color_scheme();
set_timezone();

if(isset($_GET['view']) && $_GET['view'] == "Species" && (isset($_GET['ajax_species_batch']) || isset($_GET['export']))) {
  include('scripts/species.php');
  exit;
}

$restore = "cat $home/BirdSongs/restore.log";

if(is_authenticated()
    && (!isset($_GET['view']) || $_GET['view'] !== 'System Controls')
    && (!isset($_SESSION['behind']) || !isset($_SESSION['behind_time']) || time() > $_SESSION['behind_time'] + 86400)) {
  $num_commits_behind = '0';
  // Keep the daily status check from lingering indefinitely on offline stations.
  shell_exec("sudo -n -u".$user." /usr/bin/timeout --kill-after=2s 15s git -C ".$home."/BirdNET-Pi fetch > /dev/null 2>/dev/null &");
  $str = trim(shell_exec("sudo -u".$user." git -C ".$home."/BirdNET-Pi status"));
  if (preg_match("/behind '.*?' by (\d+) commit(s?)\b/", $str, $matches)) {
    $num_commits_behind = $matches[1];
  }
  if (preg_match('/\b(\d+)\b and \b(\d+)\b different commits each/', $str, $matches)) {
    $num1 = (int) $matches[1];
    $num2 = (int) $matches[2];
    $num_commits_behind = $num1 + $num2;
  }
  if (stripos($str, "Your branch is up to date") !== false) {
    $num_commits_behind = '0';
  }
  $_SESSION['behind'] = $num_commits_behind;
  $_SESSION['behind_time'] = time();
}
$site_name = get_sitename();
$current_view = isset($_GET['view']) ? $_GET['view'] : 'Now';
$current_subview = isset($_GET['subview']) ? $_GET['subview'] : '';
if (is_protected_view($current_view)) {
  ensure_authenticated();
}
$page_title = $current_view === 'Now' ? $site_name : $current_view . ' · ' . $site_name;

$updatediv = "";
if (isset($_SESSION['behind']) && intval($_SESSION['behind']) >= 50 && (($config['SILENCE_UPDATE_INDICATOR'] ?? '') != 1)) {
  $updatediv = ' <div class="updatenumber">'.$_SESSION["behind"].'</div>';
}

function nav_icon($name) {
  return '<svg class="nav-icon" aria-hidden="true" focusable="false"><use href="static/icons.svg#' . $name . '"></use></svg>';
}
?>
<!DOCTYPE html>
<html lang="en">
<head>
  <script>
    (function () {
      var theme = localStorage.getItem('birdnet-theme');
      // A first-time visitor always starts in light mode. Dark mode is only
      // applied after the visitor explicitly selects and saves it.
      if (theme === 'dark') {
        document.documentElement.setAttribute('data-theme', 'dark');
      }
    })();
  </script>
  <meta charset="UTF-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <meta name="description" content="BirdNET-Pi - Bird sound identification and monitoring dashboard">
  <title><?php echo h($page_title); ?></title>
  <link id="iconLink" rel="shortcut icon" sizes="85x85" href="images/bird.png">
  <link rel="manifest" href="manifest.webmanifest">
  <link rel="apple-touch-icon" href="images/pwa-192.png">
  <meta name="theme-color" content="#4f46e5">
  <script>
    if ('serviceWorker' in navigator) {
      window.addEventListener('load', function () {
        navigator.serviceWorker.register('/sw.js').catch(function () {});
      });
    }
    // Display units, resolved server-side once; JS renders values the APIs
    // have already converted and only needs the labels/format.
    window.BIRDNET_UNITS = {
      temp: '<?php echo get_temp_unit(); ?>',
      tempSuffix: '<?php echo get_temp_unit() === 'C' ? '°C' : '°F'; ?>',
      wind: '<?php echo h(wind_unit_label()); ?>',
      time: '<?php echo get_time_format(); ?>',
      numLocale: '<?php echo number_format_js_locale(); ?>'
    };
    // Number display style (Settings > Display & Units) for client-rendered
    // values; toLocaleString with the matching locale mirrors format_number().
    window.birdnetFormatNumber = function (n, decimals) {
      if (n === null || n === undefined || n === '' || isNaN(Number(n))) return '';
      var opts = decimals === undefined ? {} :
        { minimumFractionDigits: decimals, maximumFractionDigits: decimals };
      return Number(n).toLocaleString(window.BIRDNET_UNITS.numLocale, opts);
    };
  </script>
  <link rel="stylesheet" href="<?php echo $color_scheme . '?v=' . date('n.d.y', filemtime($color_scheme)); ?>">
  <link rel="stylesheet" href="static/css/tokens.css?v=<?php echo filemtime('static/css/tokens.css'); ?>">
  <link rel="stylesheet" href="static/css/shell.css?v=<?php echo filemtime('static/css/shell.css'); ?>">
  <link rel="stylesheet" href="static/css/pages.css?v=<?php echo filemtime('static/css/pages.css'); ?>">
  <link rel="stylesheet" type="text/css" href="static/dialog-polyfill.css">
  <script src="static/ui-helpers.js?v=<?php echo (int)@filemtime('static/ui-helpers.js'); ?>" defer></script>
  <script src="static/palette.js?v=<?php echo filemtime('static/palette.js'); ?>" defer></script>
  <?php if (isset($_SESSION['behind']) && intval($_SESSION['behind']) >= 99) { ?>
  <style>
    .updatenumber {
      width: 30px !important;
    }
  </style>
  <?php } ?>
</head>
<body>
<div id="live-audio-panel">
  <div id="live-audio-content" role="region" aria-labelledby="live-audio-title" aria-hidden="true">
    <div class="live-audio-heading">
      <div class="live-audio-title-wrap">
        <span class="live-audio-status-dot" aria-hidden="true"></span>
        <strong id="live-audio-title">Live Audio</strong>
      </div>
      <button type="button" id="live-audio-close" onclick="closeAudioPanel()" aria-label="Close live audio player">
        <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24"><path d="M18 6 6 18M6 6l12 12"></path></svg>
      </button>
    </div>
    <div class="live-audio-player-wrap">
      <audio id="live-audio-player" controls preload="none">
        <source src="/stream" type="audio/mpeg">
      </audio>
    </div>
    <div class="live-audio-listening">
      <svg aria-hidden="true" focusable="false" viewBox="0 0 24 24">
        <path d="M4 10v4M8 6v12M12 3v18M16 7v10M20 10v4"></path>
      </svg>
      <span>Listening for bird sounds in real time</span>
    </div>
  </div>
</div>
<script>
  let activeAudioTrigger = null;
  const liveAudioPlayer = document.getElementById('live-audio-player');

  function setLiveAudioPlaying(isPlaying) {
    document.querySelectorAll('.live-audio-trigger').forEach(function (button) {
      button.classList.toggle('is-playing', isPlaying);
    });
  }

  liveAudioPlayer.addEventListener('playing', function () {
    setLiveAudioPlaying(true);
  });
  ['pause', 'ended', 'waiting', 'stalled', 'error', 'emptied', 'abort'].forEach(function (eventName) {
    liveAudioPlayer.addEventListener(eventName, function () {
      setLiveAudioPlaying(false);
    });
  });

  function setAudioPanelOpen(isOpen, trigger) {
    const panel = document.getElementById('live-audio-panel');
    const content = document.getElementById('live-audio-content');
    if (trigger) activeAudioTrigger = trigger;
    const anchor = activeAudioTrigger;
    const isMobileAnchor = !!(anchor && anchor.classList.contains('live-audio-trigger-mobile'));
    const sidebar = document.getElementById('mySidebar');
    panel.classList.toggle('mobile-anchor', isMobileAnchor);
    panel.classList.toggle('collapsed-anchor', !isMobileAnchor && !!(sidebar && sidebar.classList.contains('collapsed')));
    panel.classList.toggle('open', isOpen);
    document.querySelectorAll('.live-audio-trigger').forEach(function (button) {
      const isActive = isOpen && button === anchor;
      button.setAttribute('aria-expanded', isActive ? 'true' : 'false');
      button.setAttribute('aria-label', isActive ? 'Close live audio player' : 'Open live audio player');
    });
    content.setAttribute('aria-hidden', isOpen ? 'false' : 'true');
  }
  function toggleAudioPanel(trigger, activationEvent) {
    const panel = document.getElementById('live-audio-panel');
    const shouldOpen = !panel.classList.contains('open') || activeAudioTrigger !== trigger;
    setAudioPanelOpen(shouldOpen, trigger);
    // Pointer and touch activation should not leave a latent focus ring that
    // appears when the user next presses a hardware key (such as volume).
    // Keyboard activation has detail === 0 and intentionally keeps focus.
    if (activationEvent && activationEvent.detail > 0) trigger.blur();
  }
  function closeAudioPanel(restoreFocus) {
    setAudioPanelOpen(false);
    if (restoreFocus !== false && activeAudioTrigger) activeAudioTrigger.focus();
  }
  document.addEventListener('keydown', function (event) {
    if (event.key === 'Escape' && document.getElementById('live-audio-panel').classList.contains('open')) {
      closeAudioPanel();
    }
  });
  window.addEventListener('resize', function () {
    if (document.getElementById('live-audio-panel').classList.contains('open')) closeAudioPanel(false);
  });
</script>
<div class="mobile-header">
  <div class="sidebar-logo">
    <img src="images/bnp.png" alt="BirdNET-Pi logo">
  </div>
  <button type="button" class="icon palette-launch-mobile" onclick="window.BirdNETPalette && BirdNETPalette.show()" aria-label="Search pages and species"><?php echo nav_icon('search'); ?></button>
  <button type="button" class="live-audio-trigger live-audio-trigger-mobile" onclick="toggleAudioPanel(this, event)" aria-label="Open live audio player" aria-expanded="false" aria-controls="live-audio-content">
    <svg class="live-audio-mic" aria-hidden="true" focusable="false"><use href="static/icons.svg#mic"></use></svg>
  </button>
  <button type="button" class="icon" onclick="myFunction()" aria-label="Toggle navigation menu"><img src="images/menu.png" alt=""></button>
</div>
<div class="sidebar" id="mySidebar">
  <div class="sidebar-header">
    <div class="sidebar-logo">
      <img src="images/bnp.png" alt="BirdNET-Pi logo">
      <?php
      // Optional station label (Settings > Display & Units): tells stations
      // apart at a glance when you run more than one.
      $sidebar_config = get_config();
      if (!empty($sidebar_config['SIDEBAR_SITE_NAME']) && $sidebar_config['SIDEBAR_SITE_NAME'] == 1) {
        $sidebar_site_label = trim((string)($sidebar_config['SITE_NAME'] ?? ''));
        if ($sidebar_site_label !== '') {
          echo '<span class="sidebar-site-name">' . h($sidebar_site_label) . '</span>';
        }
      }
      ?>
    </div>
    <div class="sidebar-header-actions">
      <button type="button" class="live-audio-trigger live-audio-trigger-desktop" onclick="toggleAudioPanel(this, event)" aria-label="Open live audio player" aria-expanded="false" aria-controls="live-audio-content">
        <svg class="live-audio-mic" aria-hidden="true" focusable="false"><use href="static/icons.svg#mic"></use></svg>
      </button>
      <button type="button" class="sidebar-toggle" onclick="myFunction()" aria-label="Toggle sidebar">«</button>
    </div>
  </div>
  <button type="button" class="palette-launch" onclick="window.BirdNETPalette && BirdNETPalette.show()" aria-label="Search pages and species">
    <?php echo nav_icon('search'); ?> <span>Search&hellip;</span> <kbd>Ctrl K</kbd>
  </button>
  <nav class="sidebar-nav" aria-label="Main navigation">
<?php
/* Question-first navigation (Phase 2). Insights groups the analysis pages,
   including the Chart.js dashboard ("Charts" = the Analytics view). Legacy
   ?view= values (Overview, Analytics, Spectrogram, ...) all stay routed for
   bookmarks; they just aren't all listed here. */
$insights_items = [
  // [view, subview-or-null, icon, label]
  ['Analytics', null, 'chart', 'Charts'],
  ['Insights', 'dashboard', 'grid', 'Dashboard'],
  ['Insights', 'behavior', 'clock', 'Behavior'],
  ['Insights', 'migration', 'send', 'Migration'],
  ['Insights', 'environmental', 'cloud', 'Weather'],
  ['Insights', 'health', 'search', 'Health'],
  ['Insights', 'forecasting', 'trending-up', 'Trends & Forecasting'],
  ['Insights', 'report', 'file-text', 'Reports'],
  ['Year', null, 'award', 'Year in Birds'],
];
$main_nav = [
  ['Now', 'home', 'Today'],
  ['Live', 'activity', 'Live'],
  ['Timeline', 'clock', 'Timeline'],
  ['Species', 'bird', 'Birds'],
  'INSIGHTS',
  ['Review', 'search', 'Review'],
  ['Tools', 'sliders', 'Settings'],
];
/* Views reached from within a section highlight that section's nav item */
$nav_aliases = [
  'Timeline' => ['Recordings'],
  'Species' => ['Bird', 'Species Stats', 'Todays Detections'],
];
foreach ($main_nav as $nav_item) {
  if ($nav_item === 'INSIGHTS') {
    $insights_open = ($current_view === 'Insights' || $current_view === 'Analytics' || $current_view === 'Year');
    $effective_subview = $current_subview === '' ? 'dashboard' : $current_subview;
    echo '<div class="sidebar-dropdown' . ($insights_open ? ' open' : '') . '">';
    echo '<button type="button" class="sidebar-dropdown-toggle" aria-expanded="' . ($insights_open ? 'true' : 'false') . '">' . nav_icon('zap') . ' <span>Insights</span> <span class="dropdown-arrow" aria-hidden="true">&#9660;</span></button>';
    echo '<div class="sidebar-dropdown-content">';
    foreach ($insights_items as $sv) {
      if ($sv[1] === null) {
        $sv_active = ($current_view === $sv[0]);
        $href = '?view=' . rawurlencode($sv[0]);
      } else {
        $sv_active = ($current_view === 'Insights' && $effective_subview === $sv[1]);
        $href = '?view=Insights&amp;subview=' . $sv[1];
      }
      echo '<a href="' . $href . '"' . ($sv_active ? ' class="active" aria-current="page"' : '') . '>' . nav_icon($sv[2]) . ' <span>' . h($sv[3]) . '</span></a>';
    }
    echo '</div></div>';
    continue;
  }
  $is_active = ($current_view === $nav_item[0])
    || (isset($nav_aliases[$nav_item[0]]) && in_array($current_view, $nav_aliases[$nav_item[0]], true));
  $extra = ($nav_item[0] === 'Tools') ? $updatediv : '';
  echo '<a href="?view=' . rawurlencode($nav_item[0]) . '"' . ($is_active ? ' class="active" aria-current="page"' : '') . '>' . nav_icon($nav_item[1]) . ' <span>' . h($nav_item[2]) . '</span>' . $extra . '</a>';
}
?>
    <button type="button" id="themeToggleBtn" onclick="toggleTheme()">
      <span class="theme-toggle-option theme-when-light">🌙 <span>Dark Mode</span></span>
      <span class="theme-toggle-option theme-when-dark">☀️ <span>Light Mode</span></span>
    </button>
    <script>
      // Dropdown wiring; nav active states are rendered server-side and the
      // theme button's two states are toggled purely by CSS via data-theme,
      // so nothing flashes while the page loads.
      document.addEventListener('DOMContentLoaded', function() {
        const dropdown = document.querySelector('.sidebar-dropdown');
        const toggle = dropdown.querySelector('.sidebar-dropdown-toggle');
        toggle.addEventListener('click', function(e) {
          e.preventDefault();
          const isOpen = dropdown.classList.toggle('open');
          toggle.setAttribute('aria-expanded', isOpen ? 'true' : 'false');
        });
      });

      function toggleTheme() {
        const isDark = document.documentElement.getAttribute('data-theme') === 'dark';
        const newTheme = isDark ? 'light' : 'dark';
        localStorage.setItem('birdnet-theme', newTheme);
        document.documentElement.setAttribute('data-theme', newTheme);
      }
    </script>
  </nav>


  <div class="sidebar-feed">
  <?php
    $current_weather_str = "";
    try {
      $feed_db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
      $check_weather = db_query_safe($feed_db, "SELECT name FROM sqlite_master WHERE type='table' AND name='weather'", 'sidebar weather table');
      if (db_fetch_assoc_safe($check_weather)) {
          $hasIsDay = false;
          $cols = db_query_safe($feed_db, "PRAGMA table_info(weather)", 'sidebar weather columns');
          while($c = db_fetch_assoc_safe($cols)) { if($c['name'] == 'IsDay') { $hasIsDay = true; break; } }
          
          $sel = $hasIsDay ? "Temp, ConditionCode, IsDay" : "Temp, ConditionCode";
          $w_stmt = $feed_db->prepare("SELECT $sel FROM weather WHERE Date = DATE('now','localtime') AND Hour = ?");
          if ($w_stmt) {
              $w_stmt->bindValue(1, (int)date('G'), SQLITE3_INTEGER);
              $w_res = db_execute_safe($feed_db, $w_stmt, 'sidebar current weather');
              if ($w_row = db_fetch_assoc_safe($w_res)) {
                  $temp = display_temp($w_row['Temp']) . str_replace('°', '&deg;', temp_unit_suffix());
                  $code = (int)$w_row['ConditionCode'];
                  $is_day = $hasIsDay ? (int)$w_row['IsDay'] : 1;
                  
                  $emoji = '☁️';
                  if ($code === 0) $emoji = $is_day === 0 ? '🌙' : '☀️';
                  elseif ($code >= 1 && $code <= 3) $emoji = $is_day === 0 ? '☁️' : '⛅';
                  elseif ($code === 45 || $code === 48) $emoji = '🌫️';
                  elseif ($code >= 51 && $code <= 55) $emoji = $is_day === 0 ? '🌧️' : '🌦️';
                  elseif ($code >= 61 && $code <= 65) $emoji = '🌧️';
                  elseif ($code >= 71 && $code <= 75) $emoji = '❄️';
                  elseif ($code >= 80 && $code <= 82) $emoji = $is_day === 0 ? '🌧️' : '🌦️';
                  elseif ($code >= 95) $emoji = '⛈️';
                  
                  $current_weather_str = "<span id='liveFeedWeather' style='margin-left:auto; font-size:0.9em; font-weight:normal; color:var(--text-secondary, #6b7280);'>{$temp} {$emoji}</span>";
              }
          }
      }
      $feed_db->close();
    } catch(Exception $e) {}
  ?>
    <h3 style="display:flex; align-items:center; width:100%;"><span class="live-dot"></span> Live Activity <?php echo $current_weather_str ?: "<span id='liveFeedWeather' style='margin-left:auto; font-size:0.9em; font-weight:normal; color:var(--text-secondary, #6b7280);'></span>"; ?></h3>
    <ul class="feed-list" id="liveFeedList">
      <li style="padding:12px 0; text-align:center; color: var(--text-secondary, #6b7280);">Loading...</li>
    </ul>
  </div>

  <script>
  function liveFeedWeatherEmoji(code, isDay) {
    code = Number(code);
    isDay = Number(isDay) !== 0;
    if (code === 0) return isDay ? '\u2600\ufe0f' : '\ud83c\udf19';
    if (code >= 1 && code <= 3) return isDay ? '\u26c5' : '\u2601\ufe0f';
    if (code === 45 || code === 48) return '\ud83c\udf2b\ufe0f';
    if (code >= 51 && code <= 55) return isDay ? '\ud83c\udf26\ufe0f' : '\ud83c\udf27\ufe0f';
    if (code >= 61 && code <= 65) return '\ud83c\udf27\ufe0f';
    if (code >= 71 && code <= 75) return '\u2744\ufe0f';
    if (code >= 80 && code <= 82) return isDay ? '\ud83c\udf26\ufe0f' : '\ud83c\udf27\ufe0f';
    if (code >= 95) return '\u26c8\ufe0f';
    return '\u2601\ufe0f';
  }

  function refreshLiveWeather() {
    fetch('/api/v1/weather/current?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(r => {
        if (!r.ok) throw new Error('Weather request failed');
        return r.json();
      })
      .then(data => {
        if (!data || data.status !== 'current') return;
        const weather = document.getElementById('liveFeedWeather');
        if (!weather) return;
        weather.innerHTML = Math.round(Number(data.temp)) + (window.BIRDNET_UNITS ? window.BIRDNET_UNITS.tempSuffix.replace('°', '&deg;') : '&deg;F') + ' ' + liveFeedWeatherEmoji(data.condition_code, data.is_day);
      })
      .catch(() => {
        // Keep the last known weather visible during transient API or database failures.
      });
  }

  function refreshLiveFeed() {
    // Visit-grouped feed: one row per visit instead of one per detection, so
    // a chatty bird occupies a single line. Ongoing visits pulse as "now".
    fetch('api/v1/detections/visits?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(r => {
        if (!r.ok) throw new Error('Visits request failed');
        return r.json();
      })
      .then(data => {
        const list = document.getElementById('liveFeedList');
        if (!list) return;
        const visits = (data.visits || []).slice();
        if (visits.length === 0) {
          list.innerHTML = '<li style="padding:12px 0; text-align:center; color: var(--text-secondary, #6b7280);">No detections today yet.</li>';
          return;
        }
        visits.sort((a, b) => a.seconds_ago - b.seconds_ago);
        const gap = data.gap_seconds || 300;
        list.innerHTML = visits.slice(0, 7).map(v => {
          const pct = Math.round(v.best_confidence * 100);
          let cls = 'low';
          if (pct >= 90) cls = 'high';
          else if (pct >= 75) cls = 'med';
          const active = v.seconds_ago <= gap;
          const when = active
            ? '<span class="feed-now"><span class="live-dot"></span> now</span>'
            : v.last_time.slice(0, 5);
          return `<li class="feed-item${active ? ' feed-active' : ''}">
            <a class="feed-species" href="?view=Bird&sci_name=${encodeURIComponent(v.sci_name)}">${v.species}</a>
            <span class="feed-count">&times;${v.count}</span>
            <span class="feed-badge ${cls}">${pct}%</span>
            <span class="feed-time">${when}</span>
          </li>`;
        }).join('');
      })
      .catch(() => {
        const list = document.getElementById('liveFeedList');
        const hasExistingFeed = list && list.querySelector('.feed-item');
        if (list && !hasExistingFeed) {
          list.innerHTML = '<li style="padding:8px 0;">' + (window.BirdNETUI ? BirdNETUI.message('warning', 'Live feed retrying', 'Visits could not be loaded yet.') : 'Live feed retrying.') + '</li>';
        }
      });
  }
  document.addEventListener("DOMContentLoaded", function() {
    refreshLiveFeed();
    refreshLiveWeather();
    // 10s keeps the sidebar in step with the Live page's "Now hearing" ticker
    setInterval(refreshLiveFeed, 10000);
    setInterval(refreshLiveWeather, 60000);
  });
  </script>

</div>
<script type="text/javascript" src="static/plupload.full.min.js"></script>
<!--<script type="text/javascript" src="static/moxie.js"></script>
<script type="text/javascript" src="static/plupload.dev.js"></script>-->
<script>
window.addEventListener('load', function() {
  var elements = document.querySelectorAll(".sidebar-nav a");

  var setViewsOpacity = function() {
      document.getElementsByClassName("views")[0].style.opacity = "0.5";
  };

  for (var i = 0; i < elements.length; i++) {
      elements[i].addEventListener('click', setViewsOpacity, false);
  }
});
function copyOutput(elem) {
  elem.innerHTML = 'Copied!';
  const copyText = document.getElementsByTagName("pre")[0].textContent;
  const textArea = document.createElement('textarea');
  textArea.style.position = 'absolute';
  textArea.style.left = '-100%';
  textArea.textContent = copyText;
  document.body.append(textArea);
  textArea.select();
  document.execCommand("copy");
}
</script>

<div class="views">
<?php
function update_species_list($filename, $species, $add) {
    if($add){
        $str = file_get_contents($filename);
        $str = preg_replace("/(^[\r\n]*|[\r\n]+)[\s\t]*[\r\n]+/", "\n", $str);
        file_put_contents("$filename", "$str");
        foreach ($species as $selectedOption) {
            // The file stores decoded names; comparing the still-encoded form
            // ("Bewick&#039;s Wren") re-appended every apostrophe species.
            $decoded = htmlspecialchars_decode($selectedOption, ENT_QUOTES);
            if (strpos($str, $decoded) === false) {
                file_put_contents($filename, $decoded."\n", FILE_APPEND);
            }
        }
    } else {
        $str = file_get_contents($filename);
        $str = preg_replace('/^\h*\v+/m', '', $str);
        file_put_contents($filename, "$str");
        foreach($species as $selectedOption) {
              $content = file_get_contents($filename);
              $newcontent = str_replace($selectedOption, "", "$content");
              $newcontent = str_replace(htmlspecialchars_decode($selectedOption, ENT_QUOTES), "", "$newcontent");
              file_put_contents($filename, "$newcontent");
        }
        $str = file_get_contents($filename);
        $str = preg_replace('/^\h*\v+/m', '', $str);
        file_put_contents($filename, "$str");
    }
}

if(isset($_GET['view'])){
  if($_GET['view'] == "System Info"){
    ensure_authenticated();
    echo "<iframe src='phpsysinfo/index.php'></iframe>";
  }
  if($_GET['view'] == "System Controls"){
    ensure_authenticated();
    include('scripts/system_controls.php');
  }
  if($_GET['view'] == "Services"){
    ensure_authenticated();
    include('scripts/service_controls.php');
  }
  if($_GET['view'] == "Spectrogram"){include('spectrogram.php');}
  if($_GET['view'] == "View Log"){echo "<body style=\"scroll:no;overflow-x:hidden;\"><iframe style=\"width:calc( 100% + 1em);\" src=\"log\"></iframe></body>";}
  if($_GET['view'] == "Now"){include('scripts/now.php');}
  if($_GET['view'] == "Live"){include('scripts/live.php');}
  if($_GET['view'] == "Doctor"){include('scripts/doctor.php');}
  if($_GET['view'] == "Review"){include('scripts/review.php');}
  if($_GET['view'] == "Timeline"){include('scripts/timeline.php');}
  if($_GET['view'] == "Bird"){include('scripts/bird.php');}
  if($_GET['view'] == "Year"){include('scripts/year.php');}
  if($_GET['view'] == "Overview"){include('overview.php');}
  if($_GET['view'] == "Todays Detections"){include('todays_detections.php');}
  if($_GET['view'] == "Kiosk"){$kiosk = true;include('todays_detections.php');}
  if($_GET['view'] == "Species Stats"){include('stats.php');}
  if($_GET['view'] == "Weekly Report" || $_GET['view'] == "Report" || $_GET['view'] == "Reports"){include('scripts/reports.php');}
  if($_GET['view'] == "Insights"){include('scripts/insights.php');}
  if($_GET['view'] == "Analytics"){include('scripts/analytics.php');}
  if($_GET['view'] == "Species"){include('scripts/species.php');}
  if($_GET['view'] == "Daily Charts"){include('history.php');}
  if($_GET['view'] == "Tools"){
    $url = $_SERVER['SERVER_NAME']."/scripts/adminer.php";
    echo "<style>
            .tools-grid { display: flex; flex-wrap: wrap; gap: 24px; justify-content: center; max-width: 900px; margin: 20px auto; }
            .tools-group { background: var(--bg-card); padding: 20px; border-radius: var(--radius-lg); box-shadow: var(--shadow-md); flex: 1 1 250px; text-align: left; }
            .tools-group h3 { margin-top: 0; color: var(--text-primary); border-bottom: 2px solid var(--accent); padding-bottom: 8px; margin-bottom: 15px; font-size: 1.2em; }
            .tools-group button { width: 100%; margin: 6px 0 !important; text-align: left; padding: 10px 15px; font-size: 1.1em; display: flex; justify-content: space-between; align-items: center; }
          </style>
          <div class=\"centered\">
            <div class=\"tools-system-health\" style=\"max-width:1120px;margin:0 auto 24px;text-align:left;\">
              <div class=\"ui-section-header\">
                <h3>System Health</h3>
                <span id=\"systemHealthUpdated\" class=\"ui-meta\">Loading...</span>
              </div>
              <div id=\"systemHealthStrip\" class=\"ui-health-strip\" data-system-health data-refresh-ms=\"30000\" data-updated-target=\"#systemHealthUpdated\" data-error-target=\"#systemHealthError\">
                <div class=\"ui-health-item\"><span class=\"ui-health-label\">Recording</span><span class=\"ui-health-value\">Loading...</span></div>
                <div class=\"ui-health-item\"><span class=\"ui-health-label\">Analysis</span><span class=\"ui-health-value\">Loading...</span></div>
                <div class=\"ui-health-item\"><span class=\"ui-health-label\">Weather</span><span class=\"ui-health-value\">Loading...</span></div>
              </div>
              <div id=\"systemHealthError\" style=\"margin-bottom:10px;\"></div>
            </div>
          <form action=\"index.php\" method=\"GET\" id=\"views\" target=\"_top\">
            <div class=\"tools-grid\">
              <div class=\"tools-group\">
                <h3>⚙️ System & Settings</h3>
                <button type=\"submit\" name=\"view\" value=\"Settings\" form=\"views\">Settings</button>
                <button type=\"submit\" name=\"view\" value=\"Doctor\" form=\"views\">Station Doctor</button>
                <button type=\"submit\" name=\"view\" value=\"System Info\" form=\"views\">System Info</button>
                <button type=\"submit\" name=\"view\" value=\"System Controls\" form=\"views\">System Controls".$updatediv."</button>
                <button type=\"submit\" name=\"view\" value=\"Services\" form=\"views\">Services</button>
                <button type=\"submit\" name=\"view\" value=\"View Log\" form=\"views\">Log</button>
              </div>
              <div class=\"tools-group\">
                <h3>📂 Data & Files</h3>
                <button type=\"submit\" name=\"view\" value=\"File\" form=\"views\">File Manager</button>
                <button type=\"submit\" name=\"view\" value=\"Adminer\" form=\"views\">Database Maintenance</button>
                <button type=\"submit\" name=\"view\" value=\"Webterm\" form=\"views\">Web Terminal</button>
                <button type=\"submit\" name=\"view\" value=\"eBird Export\" form=\"views\">📥 eBird Export</button>
              </div>
              <div class=\"tools-group\">
                <h3>🦜 Species Control</h3>
                <button type=\"submit\" name=\"view\" value=\"Included\" form=\"views\">Custom Species List</button>
                <button type=\"submit\" name=\"view\" value=\"Excluded\" form=\"views\">Excluded Species List</button>
                <button type=\"submit\" name=\"view\" value=\"Whitelisted\" form=\"views\">Whitelist Species List</button>
                <button type=\"submit\" name=\"view\" value=\"Species Management\" form=\"views\">Species Management</button>
              </div>
            </div>
          </form>
          </div>";
  }
  if($_GET['view'] == "eBird Export"){include('scripts/history.php');}
  if($_GET['view'] == "Recordings"){include('play.php');}
  if($_GET['view'] == "Settings"){include('scripts/config.php');} 
  if($_GET['view'] == "Advanced"){include('scripts/advanced.php');}
  if($_GET['view'] == "Included"){
    ensure_authenticated();
    if(isset($_GET['species']) && (isset($_GET['add']) or isset($_GET['del']))){
        update_species_list("./scripts/include_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list="include";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Excluded"){
    ensure_authenticated();
    if(isset($_GET['species']) && (isset($_GET['add']) or isset($_GET['del']))){
        update_species_list("./scripts/exclude_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list="exclude";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Whitelisted"){
    ensure_authenticated();
    if(isset($_GET['species']) && (isset($_GET['add']) or isset($_GET['del']))){
        update_species_list("./scripts/whitelist_species_list.txt", $_GET['species'], isset($_GET['add']));
    }
    $species_list="whitelist";
    include('./scripts/species_list.php');
  }
  if($_GET['view'] == "Species Management"){
    ensure_authenticated();
    include('scripts/species_tools.php');
  }
  if($_GET['view'] == "File"){
    ensure_authenticated();
    echo "<iframe src='scripts/filemanager/filemanager.php'></iframe>";
  }
  if($_GET['view'] == "Adminer"){
    ensure_authenticated();
    echo "<iframe src='scripts/adminer.php'></iframe>";
  }
  if($_GET['view'] == "Webterm"){
    ensure_authenticated('You cannot access the web terminal');
    echo "<iframe src='terminal'></iframe>";
  }
  if($_GET['view'] == "Styleguide"){include('styleguide.php');}
} elseif(isset($_GET['submit'])) {
  ensure_authenticated();
  $allowedCommands = array('sudo systemctl stop livestream.service && sudo systemctl stop icecast2.service',
                     'sudo systemctl restart livestream.service && sudo systemctl restart icecast2.service',
                     'sudo systemctl restart icecast2.service && sudo systemctl restart livestream.service',
                     'sudo systemctl disable --now livestream.service && sudo systemctl disable icecast2 && sudo systemctl stop icecast2.service',
                     'sudo systemctl enable icecast2 && sudo systemctl start icecast2.service && sudo systemctl enable --now livestream.service',
                     'sudo systemctl stop web_terminal.service',
                     'sudo systemctl restart web_terminal.service',
                     'sudo systemctl disable --now web_terminal.service',
                     'sudo systemctl enable --now web_terminal.service',
                     'sudo systemctl stop birdnet_log.service',
                     'sudo systemctl restart birdnet_log.service',
                     'sudo systemctl disable --now birdnet_log.service',
                     'sudo systemctl enable --now birdnet_log.service',
                     'sudo systemctl stop birdnet_analysis.service',
                     'sudo systemctl restart birdnet_analysis.service',
                     'sudo systemctl disable --now birdnet_analysis.service',
                     'sudo systemctl enable --now birdnet_analysis.service',
                     'sudo systemctl stop birdnet_stats.service',
                     'sudo systemctl restart birdnet_stats.service',
                     'sudo systemctl disable --now birdnet_stats.service',
                     'sudo systemctl enable --now birdnet_stats.service',
                     'sudo systemctl stop birdnet_recording.service',
                     'sudo systemctl restart birdnet_recording.service',
                     'sudo systemctl disable --now birdnet_recording.service',
                     'sudo systemctl enable --now birdnet_recording.service',
                     'sudo systemctl stop chart_viewer.service',
                     'sudo systemctl restart chart_viewer.service',
                     'sudo systemctl disable --now chart_viewer.service',
                     'sudo systemctl enable --now chart_viewer.service',
                     'sudo systemctl stop spectrogram_viewer.service',
                     'sudo systemctl restart spectrogram_viewer.service',
                     'sudo systemctl disable --now spectrogram_viewer.service',
                     'sudo systemctl enable --now spectrogram_viewer.service',
                     'sudo systemctl enable '.get_service_mount_name().' && sudo reboot',
                     'sudo systemctl disable '.get_service_mount_name().' && sudo reboot',
                     'stop_core_services.sh',
                     'restart_services.sh',
                     'sudo reboot',
                     'update_birdnet.sh',
                     'sudo shutdown now',
                     'sudo clear_all_data.sh',
                     "$restore");
    /* The sanitize filter at the top of this file HTML-encodes '&' to '&amp;',
       so compound '&&' commands can never match the allowlist unless decoded
       first. Safe: the command still has to exactly match an allowlist entry. */
    $command = html_entity_decode($_GET['submit'], ENT_QUOTES);
    if(in_array($command,$allowedCommands)){
      if(isset($command)){
        $initcommand = $command;
		  if (strpos($command, "systemctl") !== false) {
			  //If there more than one command to execute, processes then separately
			  //currently only livestream service uses multiple commands to interact with the required services
			  if (strpos($command, " && ") !== false) {
				  $separate_commands = explode("&&", trim($command));
				  $new_multiservice_status_command = "";
				  foreach ($separate_commands as $indiv_service_command) {
					  //explode the string by " " space so we can get each individual component of the command
					  //and eventually the service name at the end
					  $separate_command_tmp = explode(" ", trim($indiv_service_command));
					  //get the service names
					  $new_multiservice_status_command .= " " . trim(end($separate_command_tmp));
				  }

				  $service_names = $new_multiservice_status_command;
			  } else {
                  //only one service needs restarting so we only need to query the status of one service
				  $tmp = explode(" ", trim($command));
				  $service_names = end($tmp);
			  }

          $command .= " & sleep 3;sudo systemctl status " . $service_names;
        }
        if($initcommand == "update_birdnet.sh") {
          session_unset();
        }
        $results = shell_exec("$command 2>&1");
        $results = h($results);
        $results = str_replace("FAILURE", "<span style='color:red'>FAILURE</span>", $results);
        $results = str_replace("failed", "<span style='color:red'>failed</span>",$results);
        $results = str_replace("active (running)", "<span style='color:green'><b>active (running)</b></span>",$results);
        $results = str_replace("Your branch is up to date", "<span style='color:limegreen'><b>Your branch is up to date</b></span>",$results);

        $results = str_replace("(+)", "(<span style='color:lime;font-weight:bold'>+</span>)",$results);
        $results = str_replace("(-)", "(<span style='color:red;font-weight:bold'>-</span>)",$results);

        // split the input string into lines
        $lines = explode("\n", $results);

        // iterate over each line
        foreach ($lines as &$line) {
            // check if the line matches the pattern
            if (preg_match('/^(.+?)\s*\|\s*(\d+)\s*([\+\- ]+)(\d+)?$/', $line, $matches)) {
                // extract the filename, count, and indicator letters
                $filename = $matches[1];
                $count = $matches[2];
                $diff = $matches[3];
                $delta = $matches[4] ?? '';
                // determine the indicator letters
                $diff_array = str_split($diff);
                $indicators = array_map(function ($d) use ($delta) {
                    if ($d === '+') {
                        return "<span style='color:lime;'><b>+</b></span>";
                    } elseif ($d === '-') {
                        return "<span style='color:red;'><b>-</b></span>";
                    } elseif ($d === ' ') {
                        if ($delta !== '') {
                            return 'A';
                        } else {
                            return ' ';
                        }
                    }
                }, $diff_array);
                // modify the line with the new indicator letters
                $line = sprintf('%-35s|%3d %s%s', $filename, $count, implode('', $indicators), $delta);
            }
        }

        // rejoin the modified lines into a string
        $output = implode("\n", $lines);
        $results = $output;

        // remove script tags (xss)
        $results = preg_replace('#<script(.*?)>(.*?)</script>#is', '', $results);
        if(strlen($results) == 0) {
          $results = "This command has no output.";
        }
        echo "<table style='min-width:70%;'><tr class='relative'><th>Output of command:`".h($initcommand)."`<button class='copyimage' style='right:40px' onclick='copyOutput(this);'>Copy</button></th></tr><tr><td style='padding-left: 0px;padding-right: 0px;padding-bottom: 0px;padding-top: 0px;'><pre class='bash' style='text-align:left;margin:0px'>$results</pre></td></tr></table>";
      }
    }
  ob_end_flush();
} elseif (isset($_GET['filename'])) {
  // "Open in new tab" detection links and notification deep links are emitted
  // as index.php?filename=<File_Name> with no view parameter; play.php owns
  // the single-recording page.
  include('play.php');
} else {include('scripts/now.php');}
?>
<script>
function myFunction() {
  var sidebar = document.getElementById("mySidebar");
  var content = document.querySelector(".views");
  if (document.getElementById('live-audio-panel').classList.contains('open')) closeAudioPanel(false);
  
  if (window.innerWidth <= 1000) {
    // Mobile: Toggle drawer
    sidebar.classList.toggle("responsive");
  } else {
    // Desktop: Toggle collapse
    sidebar.classList.toggle("collapsed");
    if (content) {
      content.classList.toggle("expanded");
    }
  }
}
function setLiveStreamVolume(vol) {
  var parentAudioElements = document.getElementsByTagName("audio");
  if (parentAudioElements.length > 0) {
    parentAudioElements[0].volume = vol;
  }
}
window.onbeforeunload = function(event) {
  // if the user is playing a video and then navigates away mid-play, the live stream audio should be unmuted again
  var parentAudioElements = document.getElementsByTagName("audio");
  if (parentAudioElements.length > 0) {
    parentAudioElements[0].volume = 1;
  }
}

function getTheDate(increment) {
  var theDate = "<?php if (isset($theDate)) echo $theDate;?>";

  d = new Date(theDate);
  d.setDate(d.getDate(theDate) + increment);
  yyyy = d.getFullYear();
  mm = d.getMonth() + 1; if (mm < 10) mm = "0" + mm;
  dd = d.getDate(); if (dd < 10) dd = "0" + dd;

  document.getElementById("SwipeSpinner").hidden = false;
  
  window.location = "/views.php?date="+yyyy+"-"+mm+"-"+dd+"&view=Daily+Charts";
}

function installKeyAndSwipeEventHandler() {
  var urlParams = new URLSearchParams(window.location.search);
  if (urlParams.get('view') !== 'Daily Charts') {
    return;
  }

  document.onkeydown = function(event) {
    switch (event.keyCode) {
      case 37: //Left key
        getTheDate(-1);
        break;
      case 39: //Right key
        getTheDate(+1);
        break;
    }
  };

  // https://stackoverflow.com/questions/2264072/detect-a-finger-swipe-through-javascript-on-the-iphone-and-android
  let touchstartX = 0;
  let diffX = 0;
  let touchstartY = 0;
  let diffY = 0;
  let startTime = 0;
  let diffTime = 0;

  function checkDirection() {
    if (Math.abs(diffX) > Math.abs(diffY) && diffTime < 350) {
      if (diffX > 20) getTheDate(+1);
      if (diffX < -20) getTheDate(-1);
    }
  }

  document.addEventListener('touchstart', e => {
    touchstartX = e.changedTouches[0].screenX;
    touchstartY = e.changedTouches[0].screenY;
    startTime = Date.now();
  });

  document.addEventListener('touchend', e => {
    diffX = touchstartX - e.changedTouches[0].screenX;
    diffY = touchstartY - e.changedTouches[0].screenY;
    diffTime = Date.now() - startTime;
    checkDirection();
  });
}

installKeyAndSwipeEventHandler();
</script>
</div>
<nav class="bottom-nav" aria-label="Quick navigation">
<?php
$bottom_nav = [
  ['Now', 'home', 'Today'],
  ['Timeline', 'clock', 'Timeline'],
  ['Species', 'bird', 'Birds'],
  ['Review', 'search', 'Review'],
];
foreach ($bottom_nav as $bn) {
  $bn_active = ($current_view === $bn[0])
    || (isset($nav_aliases[$bn[0]]) && in_array($current_view, $nav_aliases[$bn[0]], true));
  echo '<a href="?view=' . rawurlencode($bn[0]) . '"' . ($bn_active ? ' class="active" aria-current="page"' : '') . '>'
     . nav_icon($bn[1]) . '<span>' . h($bn[2]) . '</span></a>';
}
?>
  <button type="button" onclick="myFunction()" aria-label="Open full menu"><?php echo nav_icon('menu'); ?><span>More</span></button>
</nav>
</body>
</html>
