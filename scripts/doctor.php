<?php
// Station Doctor (Phase 2): plain-English health checks with one-click fixes.
// Checks come from /api/v1/station/doctor; the fix buttons reuse the existing
// whitelisted command mechanism in views.php (?submit=...), which is
// authenticated. Weather sync and the support bundle run here, auth-gated.
error_reporting(E_ERROR);
// This file is both included from views.php and requested directly for its
// AJAX actions, so the require must not depend on the working directory.
if (!defined('__ROOT__')) {
  define('__ROOT__', dirname(dirname(__FILE__)));
}
require_once(__ROOT__ . '/scripts/common.php');
$config = get_config();
$home = get_home();
$user = get_user();

if (isset($_GET['sync_weather']) && $_GET['sync_weather'] == 'true') {
  ensure_authenticated('You must be authenticated to sync weather.');
  if (!weather_sync_enabled($config)) {
    http_response_code(409);
    echo "DISABLED\nWeather syncing is disabled in Settings. No sync was started.";
    die();
  }
  $python = $home . '/BirdNET-Pi/birdnet/bin/python3';
  $script = $home . '/BirdNET-Pi/scripts/utils/weather.py';
  if (!is_readable($script)) {
    echo 'Weather script not found.';
    die();
  }
  $lock = @fopen(sys_get_temp_dir() . '/birdnet_weather_sync.lock', 'c');
  if ($lock && @flock($lock, LOCK_EX | LOCK_NB)) {
    $timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 30 ' : '';
    $output = shell_exec($timeout . 'sudo -u ' . escapeshellarg($user) . ' ' . escapeshellarg($python) . ' ' . escapeshellarg($script) . ' 2>&1');
    @flock($lock, LOCK_UN);
    echo 'Weather sync attempted. Refresh Station Doctor for saved source results.' . ($output ? "\n" . $output : '');
  } else {
    echo 'A weather sync is already running.';
  }
  if ($lock) {
    @fclose($lock);
  }
  die();
}

if (isset($_GET['support_bundle']) && $_GET['support_bundle'] == 'true') {
  ensure_authenticated('You must be authenticated to generate a support bundle.');
  $timeout = is_executable('/usr/bin/timeout') ? '/usr/bin/timeout 60 ' : '';
  $output = shell_exec($timeout . 'sudo -u ' . escapeshellarg($user) . ' ' . escapeshellarg($home . '/BirdNET-Pi/scripts/print_diagnostic_info.sh') . ' 2>&1');
  header('Content-Type: text/plain; charset=utf-8');
  echo $output ?: 'No diagnostic output produced.';
  die();
}
?>
<div class="doctor-page">
  <div class="ui-section-header">
    <h3><?php echo nav_icon('search'); ?> Station Doctor</h3>
    <span class="ui-meta" id="doctorUpdated">Checking&hellip;</span>
  </div>
  <p class="doctor-intro">A plain-English check-up of your station. Checks refresh automatically every 30 seconds.</p>

  <div id="doctorChecks" class="doctor-checks">
    <div class="ui-skeleton-block" aria-hidden="true">
      <span class="ui-skeleton-line" style="width:90%"></span>
      <span class="ui-skeleton-line" style="width:76%"></span>
      <span class="ui-skeleton-line" style="width:62%"></span>
    </div>
  </div>

  <div class="ui-card doctor-fixes">
    <h3>Quick fixes</h3>
    <p class="doctor-intro">These run the same maintenance commands as Tools &gt; Services and require sign-in.</p>
    <div class="doctor-fix-buttons">
      <a class="ui-button-link" href="index.php?submit=<?php echo rawurlencode('sudo systemctl restart birdnet_recording.service'); ?>">Restart recording</a>
      <a class="ui-button-link" href="index.php?submit=<?php echo rawurlencode('sudo systemctl restart birdnet_analysis.service'); ?>">Restart analysis</a>
      <a class="ui-button-link" href="index.php?submit=<?php echo rawurlencode('sudo systemctl restart icecast2.service && sudo systemctl restart livestream.service'); ?>">Restart livestream</a>
      <a class="ui-button-link" href="index.php?submit=<?php echo rawurlencode('restart_services.sh'); ?>">Restart core services</a>
      <button type="button" class="ui-button-link" id="syncWeatherBtn" <?php if (!weather_sync_enabled($config)) echo 'disabled title="Weather syncing is disabled in Settings."'; ?>><?php echo weather_sync_enabled($config) ? 'Sync weather now' : 'Weather sync disabled'; ?></button>
      <button type="button" class="ui-button-link" id="supportBundleBtn">Support bundle</button>
    </div>
    <div id="doctorActionOutput"></div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var statusIcons = { ok: '✓', warn: '⚠', error: '✕' };

  function refreshDoctor() {
    fetch('api/v1/station/doctor?_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('doctor failed'); return r.json(); })
      .then(function (data) {
        document.getElementById('doctorUpdated').textContent =
          'Checked ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
        document.getElementById('doctorChecks').innerHTML = (data.checks || []).map(function (c) {
          return '<div class="doctor-check ' + esc(c.status) + '">' +
            '<span class="doctor-check-icon" aria-hidden="true">' + (statusIcons[c.status] || '?') + '</span>' +
            '<div class="doctor-check-body">' +
              '<div class="doctor-check-label">' + esc(c.label) + '</div>' +
              '<div class="doctor-check-message">' + esc(c.message) + '</div>' +
              (c.action ? '<div class="doctor-check-action">' + esc(c.action) + '</div>' : '') +
            '</div>' +
            '</div>';
        }).join('');
      })
      .catch(function () {
        document.getElementById('doctorUpdated').textContent = 'Check failed - retrying';
      });
  }

  function showActionOutput(text, isError) {
    var box = document.getElementById('doctorActionOutput');
    box.innerHTML = '<pre class="doctor-output' + (isError ? ' error' : '') + '">' + esc(text) + '</pre>';
  }

  document.getElementById('syncWeatherBtn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    showActionOutput('Syncing weather...', false);
    fetch('scripts/doctor.php?sync_weather=true')
      .then(function (r) { return r.text(); })
      .then(function (t) {
        showActionOutput(t, t.indexOf('Weather sync attempted.') !== 0);
        btn.disabled = false;
        refreshDoctor();
      })
      .catch(function () {
        showActionOutput('Weather sync request failed.', true);
        btn.disabled = false;
      });
  });

  document.getElementById('supportBundleBtn').addEventListener('click', function () {
    var btn = this;
    btn.disabled = true;
    showActionOutput('Collecting diagnostic info (can take up to a minute)...', false);
    fetch('scripts/doctor.php?support_bundle=true')
      .then(function (r) { return r.text(); })
      .then(function (t) {
        showActionOutput(t, false);
        btn.disabled = false;
      })
      .catch(function () {
        showActionOutput('Could not collect diagnostics.', true);
        btn.disabled = false;
      });
  });

  refreshDoctor();
  setInterval(refreshDoctor, 30000);
})();
</script>
