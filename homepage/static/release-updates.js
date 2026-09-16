// Passive release notifications. Never submits the maintenance form.
(function () {
  'use strict';
  const script = document.getElementById('releaseUpdatesScript');
  const silenced = script && script.dataset.silenced === '1';
  const button = document.getElementById('checkReleaseUpdates');
  const status = document.getElementById('releaseUpdateStatus');
  const checked = document.getElementById('releaseUpdateChecked');
  const notes = document.getElementById('releaseUpdateNotes');
  const releases = 'https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/releases';
  let pending = false;
  let lastRequest = 0;
  let retry;
  let dailyTimer;
  let retries = 0;

  function render(data) {
    const version = data.release && data.release.version;
    const validVersion = typeof version === 'string' && /^v\d+\.\d+\.\d+$/.test(version);
    const available = data.status === 'available' && validVersion;
    document.querySelectorAll('.release-update-badge').forEach(badge => { badge.hidden = silenced || !available; });
    if (status) {
      if (available) status.textContent = version + ' is available.';
      else if (data.status === 'current' && validVersion) status.textContent = 'Your station already includes the latest release (' + version + ').';
      else if (data.status === 'diverged') status.textContent = 'This build differs from the latest release. Review your branch and local changes before updating.';
      else if (data.status === 'checking') status.textContent = 'Another update check is in progress…';
      else status.textContent = 'Update status is unavailable.';
      if (data.error) status.textContent += ' Could not check GitHub; showing the last successful result, if available.';
    }
    if (notes) {
      // Construct the link from a validated tag, never insert remote HTML/URLs.
      notes.href = validVersion ? releases + '/tag/' + encodeURIComponent(version) : releases;
      notes.textContent = validVersion ? 'Read ' + version + ' release notes' : 'Release notes';
    }
    if (checked) {
      const date = Number.isFinite(data.checked_at) ? new Date(data.checked_at * 1000) : null;
      checked.textContent = date && !isNaN(date.getTime()) ? 'Last successful check: ' + date.toLocaleString() : '';
    }
  }

  async function check(force) {
    if (pending) return;
    pending = true;
    lastRequest = Date.now();
    clearTimeout(dailyTimer);
    dailyTimer = setTimeout(() => { if (!document.hidden) check(false); }, 86400000);
    if (button) button.disabled = true;
    if (status && force) status.textContent = 'Checking for updates…';
    const controller = new AbortController();
    const timeout = setTimeout(() => controller.abort(), 20000);
    try {
      const response = await fetch('/api/v1/system/updates', {
        method: force ? 'POST' : 'GET', credentials: 'same-origin', cache: 'no-store',
        headers: {'X-Requested-With': 'XMLHttpRequest'}, signal: controller.signal
      });
      if (!response.ok) throw new Error(response.status === 401 || response.status === 403 ? 'auth' : 'network');
      const data = await response.json();
      if (!data || !['available', 'current', 'unknown', 'diverged', 'checking'].includes(data.status)) throw new Error('network');
      render(data);
      if (data.status === 'checking' && retries++ < 3) retry = setTimeout(() => check(false), 5000);
      else retries = 0;
    } catch (error) {
      if (status) status.textContent = error.message === 'auth'
        ? 'Sign in to System Controls and try checking again.'
        : 'Could not check for updates. Try again later.';
      // A failed browser request must not clear an already-confirmed notification.
    } finally {
      clearTimeout(timeout);
      pending = false;
      if (button) button.disabled = false;
    }
  }
  if (button) button.addEventListener('click', () => { clearTimeout(retry); retries = 0; check(true); });
  document.addEventListener('visibilitychange', () => {
    if (!document.hidden && Date.now() - lastRequest >= 86400000) check(false);
  });
  check(false);
}());
