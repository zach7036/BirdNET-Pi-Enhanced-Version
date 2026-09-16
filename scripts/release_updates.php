<?php
// Release notifications only. Never fetch into, reset, or switch the checkout.
require_once __DIR__ . '/version_info.php';

const BIRDNET_RELEASE_REPO = 'zach7036/BirdNET-Pi-Enhanced-Version';
const BIRDNET_RELEASE_INTERVAL = 86400;

function release_update_http($path, $deadline) {
  $remaining = min(4, $deadline - microtime(true));
  if ($remaining <= 0 || !function_exists('exec')) throw new RuntimeException('Check unavailable');
  // Fixed host, no redirects, no credentials, bounded transfer/time, normal TLS verification.
  $url = 'https://api.github.com/repos/' . BIRDNET_RELEASE_REPO . $path;
  $curl = PHP_OS_FAMILY === 'Windows' ? 'curl.exe' : '/usr/bin/curl';
  $command = escapeshellarg($curl) . ' --disable --fail --silent --show-error --proto =https --connect-timeout 2 --max-time '
    . escapeshellarg((string)$remaining) . ' --max-filesize 2097152 --header '
    . escapeshellarg('Accept: application/vnd.github+json') . ' --user-agent BirdNET-Pi-Release-Check ' . escapeshellarg($url);
  $lines = [];
  $code = 1;
  exec($command . (PHP_OS_FAMILY === 'Windows' ? ' 2>NUL' : ' 2>/dev/null'), $lines, $code);
  if ($code !== 0) throw new RuntimeException('Check unavailable');
  $data = json_decode(implode("\n", $lines), true);
  if (!is_array($data)) throw new RuntimeException('Invalid release response');
  return $data;
}

function release_update_sha($sha) {
  return is_string($sha) && preg_match('/^[0-9a-f]{40}$/D', $sha);
}

function release_update_latest($http) {
  $data = $http('/releases/latest');
  $tag = $data['tag_name'] ?? '';
  if (($data['draft'] ?? true) !== false || ($data['prerelease'] ?? true) !== false
      || !is_string($tag) || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $tag)
      || empty($data['published_at'])) throw new RuntimeException('No stable release');
  // target_commitish can be a moving branch. Resolve the actual published tag.
  $ref = $http('/git/ref/tags/' . rawurlencode($tag));
  $object = $ref['object'] ?? [];
  for ($i = 0; $i < 3 && ($object['type'] ?? '') === 'tag'; $i++) {
    if (!release_update_sha($object['sha'] ?? null)) throw new RuntimeException('Invalid release tag');
    $annotation = $http('/git/tags/' . $object['sha']);
    $object = $annotation['object'] ?? [];
  }
  if (($object['type'] ?? '') !== 'commit' || !release_update_sha($object['sha'] ?? null)) {
    throw new RuntimeException('Invalid release commit');
  }
  return ['version' => $tag, 'sha' => $object['sha'],
    'url' => 'https://github.com/' . BIRDNET_RELEASE_REPO . '/releases/tag/' . rawurlencode($tag)];
}

function release_update_relation($head, $release, $git, $http = null) {
  if ($head === $release['sha']) return 'current';
  $contains = $git(['merge-base', '--is-ancestor', $release['sha'], $head]);
  if ($contains['code'] === 0) return 'current';
  $behind = $git(['merge-base', '--is-ancestor', $head, $release['sha']]);
  if ($behind['code'] === 0) return 'available';
  // Shallow histories/missing release objects need remote ancestry, not version-string guesses.
  if ($http !== null) {
    $comparison = $http('/compare/' . $head . '...' . $release['sha'] . '?per_page=1');
    if (($comparison['base_commit']['sha'] ?? '') !== $head) throw new RuntimeException('Invalid comparison');
    $status = $comparison['status'] ?? '';
    if ($status === 'ahead') return 'available';
    if ($status === 'identical' || $status === 'behind') return 'current';
    if ($status === 'diverged') return 'diverged';
    throw new RuntimeException('Invalid comparison');
  }
  return ($contains['code'] === 1 && $behind['code'] === 1) ? 'diverged' : 'unknown';
}

function release_update_public($state) {
  return ['status' => $state['status'] ?? 'unknown', 'release' => $state['release'] ?? null,
    'checked_at' => $state['checked_at'] ?? null, 'last_attempt' => $state['last_attempt'] ?? null,
    'error' => $state['error'] ?? null];
}

function release_update_valid_cache($state) {
  if (!is_array($state) || ($state['schema'] ?? null) !== 1 || !release_update_sha($state['head'] ?? null)) return false;
  foreach (['last_attempt', 'network_attempt', 'checked_at'] as $key) {
    if (isset($state[$key]) && (!is_int($state[$key]) || $state[$key] < 0)) return false;
  }
  if (isset($state['status']) && !in_array($state['status'], ['available', 'current', 'unknown', 'diverged'], true)) return false;
  if (isset($state['error']) && !is_string($state['error'])) return false;
  if (isset($state['release'])) {
    $release = $state['release'];
    if (!is_array($release) || !is_string($release['version'] ?? null)
        || !preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+$/D', $release['version'])
        || !release_update_sha($release['sha'] ?? null)) return false;
  } elseif (in_array($state['status'] ?? '', ['available', 'current', 'diverged'], true)) return false;
  return true;
}

function release_update_status($repo, $user, $cache_dir, $force = false, $now = null, $git = null, $http = null) {
  $now = $now ?? time();
  $git = $git ?? system_version_git_runner($repo, $user);
  $deadline = microtime(true) + 12;
  $http = $http ?? function ($path) use ($deadline) { return release_update_http($path, $deadline); };
  $unknown = ['status' => 'unknown', 'error' => 'Could not check for updates. Try again later.'];
  // Private, station-wide cache, independent of PHP sessions, browsers, and detection data.
  if (!is_dir($cache_dir) && !@mkdir($cache_dir, 0700, true) && !is_dir($cache_dir)) return release_update_public($unknown);
  if (is_link($cache_dir)) return release_update_public($unknown);
  $file = $cache_dir . '/status.json';
  $lockfile = $cache_dir . '/check.lock';
  if (is_link($file) || is_link($lockfile)) return release_update_public($unknown);
  $lock = @fopen($lockfile, 'c');
  if (!$lock) return release_update_public($unknown);
  if (!flock($lock, LOCK_EX | LOCK_NB)) {
    fclose($lock);
    return release_update_public(['status' => 'checking']);
  }
  try {
    $state = is_file($file) ? json_decode((string)@file_get_contents($file, false, null, 0, 16384), true) : [];
    if (!release_update_valid_cache($state)) $state = [];
    $head_result = $git(['rev-parse', '--verify', 'HEAD']);
    $head = trim($head_result['output'] ?? '');
    if ($head_result['code'] !== 0 || !release_update_sha($head)) return release_update_public($unknown);
    $changed = isset($state['head']) && $state['head'] !== $head;
    if ($changed) {
      // Re-evaluate against cached release immediately, but give the new build a quiet day.
      $state['status'] = isset($state['release']) ? release_update_relation($head, $state['release'], $git) : 'unknown';
      $state['last_attempt'] = $now;
      $state['error'] = null;
    }
    $state['head'] = $head;
    $last = $state['last_attempt'] ?? 0;
    $elapsed = $now - $last;
    $network_elapsed = $now - ($state['network_attempt'] ?? 0);
    // Explicit checks bypass the daily cache, but share a 60-second abuse/double-click cooldown.
    $due = $force ? (!isset($state['network_attempt']) || $network_elapsed < 0 || $network_elapsed >= 60)
      : (!$last || $elapsed < 0 || $elapsed >= BIRDNET_RELEASE_INTERVAL);
    if ($due) {
      $state['last_attempt'] = $now;
      $state['network_attempt'] = $now;
      try {
        $release = release_update_latest($http);
        $status = release_update_relation($head, $release, $git, $http);
        $state['release'] = $release;
        $state['status'] = $status;
        $state['checked_at'] = $now;
        $state['error'] = null;
      } catch (Throwable $e) {
        $state['error'] = 'Could not check for updates. Last successful result is shown, if available.';
      }
    }
    if ($due || $changed) {
      $state['schema'] = 1;
      $temp = tempnam($cache_dir, 'status-');
      if ($temp === false) return release_update_public($unknown);
      $written = @file_put_contents($temp, json_encode($state), LOCK_EX);
      if ($written === false || !@rename($temp, $file)) {
        @unlink($temp);
        return release_update_public($unknown);
      }
    }
    return release_update_public($state);
  } catch (Throwable $e) {
    return release_update_public($unknown);
  } finally {
    flock($lock, LOCK_UN);
    fclose($lock);
  }
}

function render_release_update_panel() {
  return '<section class="system-release-update" aria-label="Release updates">'
    . '<p id="releaseUpdateStatus" role="status" aria-live="polite">Checking for updates…</p>'
    . '<p id="releaseUpdateChecked" class="system-release-detail"></p>'
    . '<div class="system-release-actions"><a id="releaseUpdateNotes" href="https://github.com/' . BIRDNET_RELEASE_REPO
    . '/releases" target="_blank" rel="noopener noreferrer">Release notes</a>'
    . '<button id="checkReleaseUpdates" type="button">Check for updates</button></div>'
    . '<p class="system-release-detail">Automatic checks once a day. Checking does not install anything.</p>'
    . '<noscript>Enable JavaScript to check here, or open Release notes above.</noscript></section>';
}
