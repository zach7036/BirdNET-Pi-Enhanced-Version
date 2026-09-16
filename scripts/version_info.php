<?php
// Local, read-only metadata. No fetch, checkout, database, or configuration reads.
function system_version_git_runner($repo, $user = null) {
    return function ($args) use ($repo, $user) {
      if (!function_exists('exec')) return ['code' => 127, 'output' => ''];
      $windows = PHP_OS_FAMILY === 'Windows';
      $prefix = !$windows && is_executable('/usr/bin/timeout') ? '/usr/bin/timeout --kill-after=1s 3s ' : '';
      if ($user !== null && !$windows) $prefix .= 'sudo -n -u ' . escapeshellarg($user) . ' ';
      $command = $prefix . ($windows ? 'git' : '/usr/bin/git') . ' --no-optional-locks -C ' . escapeshellarg($repo);
      foreach ($args as $arg) $command .= ' ' . escapeshellarg($arg);
      $lines = [];
      $code = 127;
      exec($command . ($windows ? ' 2>NUL' : ' 2>/dev/null'), $lines, $code);
      return ['code' => $code, 'output' => implode("\n", $lines)];
    };
}

function system_version_info($repo, $user = null, $run = null) {
  if ($run === null) $run = system_version_git_runner($repo, $user);
  $git = function ($args) use ($run) {
    try {
      $result = $run($args);
      return ['code' => $result['code'] ?? 127, 'output' => trim($result['output'] ?? '')];
    } catch (Throwable $e) {
      return ['code' => 127, 'output' => ''];
    }
  };
  $info = ['version' => 'Release unavailable', 'branch' => 'Unavailable', 'build' => '', 'details' => []];
  $head = $git(['rev-parse', '--verify', 'HEAD']);
  if ($head['code'] !== 0 || !preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $head['output'])) {
    $info['details'][] = 'Local Git information could not be read.';
    return $info;
  }
  $info['build'] = $head['output'];
  $branch = $git(['symbolic-ref', '--quiet', '--short', 'HEAD']);
  if ($branch['code'] === 0 && $branch['output'] !== '') {
    $info['branch'] = preg_replace('/[\x00-\x1f\x7f]/', '', $branch['output']);
  } elseif ($branch['code'] === 1) {
    $info['branch'] = 'Detached HEAD';
  }
  // Pin reads to the HEAD we observed, and never infer the installed release
  // from the branch name or from GitHub's latest release.
  $description = $git(['describe', '--tags', '--long', '--match', 'v[0-9]*', '--abbrev=12', $info['build']]);
  $release_pattern = '/^(v[0-9]+\.[0-9]+\.[0-9]+(?:-[0-9A-Za-z.-]+)?(?:\+[0-9A-Za-z.-]+)?)-([0-9]+)-g([0-9a-f]+)$/D';
  if ($description['code'] === 0 && preg_match($release_pattern, $description['output'], $match)
      && strpos($info['build'], $match[3]) === 0) {
    $distance = (int)$match[2];
    if ($distance === 0) {
      $info['version'] = $match[1];
      if (preg_match('/^v[0-9]+\.[0-9]+\.[0-9]+-/', $match[1])) $info['details'][] = 'Prerelease build';
    } else {
      $info['version'] = $info['branch'] === 'development' ? 'Development build' : 'Unreleased build';
      $info['details'][] = $distance . ($distance === 1 ? ' commit after ' : ' commits after ') . $match[1];
    }
  } else {
    $info['details'][] = 'No matching release tag is available locally.';
  }
  $changes = $git(['diff', '--no-ext-diff', '--no-textconv', '--quiet', $info['build'], '--']);
  if ($changes['code'] === 1) $info['details'][] = 'Local tracked-file changes';
  elseif ($changes['code'] !== 0) $info['details'][] = 'Local-change status unavailable';
  return $info;
}

function system_version_text($info) {
  $text = "BirdNET-Pi\nVersion: " . $info['version'] . "\nBranch: " . $info['branch']
    . "\nBuild: " . ($info['build'] !== '' ? $info['build'] : 'Unavailable');
  if ($info['details']) $text .= "\nDetails: " . implode('; ', $info['details']);
  return $text;
}

function render_system_version($info) {
  $esc = function ($value) { return htmlspecialchars((string)$value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); };
  ob_start();
  ?>
  <section class="system-version" aria-label="Installed version">
    <dl class="system-version-fields">
      <div><dt>Version</dt><dd><?php echo $esc($info['version']); ?></dd></div>
      <div><dt>Branch</dt><dd><?php echo $esc($info['branch']); ?></dd></div>
      <div><dt>Build</dt><dd><?php if (preg_match('/^(?:[0-9a-f]{40}|[0-9a-f]{64})$/D', $info['build'])) { ?>
        <a href="https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/commit/<?php echo $esc($info['build']); ?>" target="_blank" rel="noopener noreferrer" title="View this build on GitHub"><?php echo $esc(substr($info['build'], 0, 7)); ?></a>
      <?php } else { ?>Unavailable<?php } ?></dd></div>
    </dl>
    <?php if ($info['details']) { ?><p class="system-version-details"><?php echo $esc(implode(' · ', $info['details'])); ?></p><?php } ?>
    <button type="button" id="copyVersionInfo">Copy version info</button>
    <span id="versionCopyStatus" class="system-version-status" role="status" aria-live="polite"></span>
    <details id="versionCopyManual">
      <summary>Show version info</summary>
      <label class="system-version-copy-label" for="versionCopyText">Version info to copy</label>
      <textarea id="versionCopyText" rows="6" readonly spellcheck="false"><?php echo $esc(system_version_text($info)); ?></textarea>
    </details>
  </section>
  <?php
  return ob_get_clean();
}
