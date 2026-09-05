(function () {
  'use strict';

  function escapeHtml(value) {
    return String(value == null ? '' : value).replace(/[&<>"']/g, function (ch) {
      return ({ '&': '&amp;', '<': '&lt;', '>': '&gt;', '"': '&quot;', "'": '&#039;' })[ch];
    });
  }

  // Third-party URLs (Flickr/Wikipedia image metadata) must resolve to plain
  // http(s) before they reach an href/src; anything else collapses to '#'.
  // This is the single canonical copy - pages delegate here so a hardening
  // change propagates everywhere at once.
  function safeHttpUrl(url) {
    try {
      var parsed = new URL(String(url), window.location.origin);
      return (parsed.protocol === 'http:' || parsed.protocol === 'https:') ? parsed.href : '#';
    } catch (e) {
      return '#';
    }
  }

  function weatherTemperature(value, suffix) {
    if (value === null || value === undefined || value === '' || !Number.isFinite(Number(value))) return '\u2014';
    return Math.round(Number(value)) + (suffix === undefined ? '\u00b0' : suffix);
  }

  function weatherSummary(weather) {
    var parts = [];
    if (weather && weather.temp !== null && weather.temp !== undefined) {
      parts.push(weatherTemperature(weather.temp, weather.temp_unit || '\u00b0F'));
    }
    if (weather && weather.condition_code !== null && weather.condition_code !== undefined && weather.condition) {
      parts.push(weather.condition);
    }
    if (!parts.length && weather && weather.wind_speed !== null && weather.wind_speed !== undefined) {
      parts.push('Wind ' + weather.wind_speed + ' ' + (weather.wind_unit || 'mph'));
    }
    return parts.join(' ') || 'Weather unavailable';
  }

  function weatherEmoji(code, isDay) {
    if (code === null || code === undefined || code === '') return '';
    code = Number(code);
    var dayKnown = isDay === 0 || isDay === 1;
    var night = isDay === 0;
    if (code === 0) return dayKnown ? (night ? '🌙' : '☀️') : '○';
    if (code >= 1 && code <= 3) return night || !dayKnown ? '☁️' : '⛅';
    if (code === 45 || code === 48) return '🌫️';
    if (code >= 51 && code <= 67) return '🌧️';
    if ((code >= 71 && code <= 77) || code === 85 || code === 86) return '❄️';
    if (code >= 80 && code <= 82) return '🌧️';
    if (code >= 95 && code <= 99) return '⛈️';
    return '';
  }

  function formatBytes(bytes) {
    var value = Number(bytes || 0);
    var units = ['B', 'KB', 'MB', 'GB', 'TB'];
    var unit = 0;
    while (value >= 1024 && unit < units.length - 1) {
      value = value / 1024;
      unit++;
    }
    return (unit === 0 ? value.toFixed(0) : value.toFixed(value >= 10 ? 1 : 2)) + ' ' + units[unit];
  }

  function formatDateTime(value) {
    if (!value) return 'Never';
    var date = value instanceof Date ? value : new Date(value);
    if (isNaN(date.getTime())) return String(value);
    return date.toLocaleString([], {
      month: 'short',
      day: 'numeric',
      hour: 'numeric',
      minute: '2-digit'
    });
  }

  function skeleton(lines) {
    var count = lines || 3;
    var html = '<div class="ui-skeleton-block" aria-hidden="true">';
    for (var i = 0; i < count; i++) {
      html += '<span class="ui-skeleton-line" style="width:' + (90 - (i % 3) * 14) + '%"></span>';
    }
    html += '</div>';
    return html;
  }

  function message(type, title, detail) {
    return '<div class="ui-message ui-message-' + escapeHtml(type || 'info') + '" role="status">' +
      '<strong>' + escapeHtml(title || '') + '</strong>' +
      (detail ? '<span>' + escapeHtml(detail) + '</span>' : '') +
      '</div>';
  }

  function setMessage(target, type, title, detail) {
    var el = typeof target === 'string' ? document.querySelector(target) : target;
    if (!el) return;
    el.innerHTML = message(type, title, detail);
  }

  function statusPill(status, label) {
    var safeStatus = String(status || 'unknown').toLowerCase();
    return '<span class="ui-status-pill ui-status-' + escapeHtml(safeStatus) + '">' + escapeHtml(label || status || 'Unknown') + '</span>';
  }

  function fetchJson(url) {
    var requestUrl = url + (url.indexOf('?') === -1 ? '?' : '&') + '_=' + Date.now();
    return fetch(requestUrl, { headers: { 'Accept': 'application/json' } }).then(function (response) {
      if (!response.ok) throw new Error('Request failed: ' + response.status);
      return response.json();
    });
  }

  function renderHealthItem(label, value, status, tooltip) {
    var text = String(value == null ? 'Unknown' : value);
    var tooltipAttr = tooltip ? ' data-tooltip="' + escapeHtml(tooltip) + '"' : '';
    return '<div class="ui-health-item" tabindex="0" role="group" aria-label="' + escapeHtml(label + ': ' + text + (tooltip ? '. ' + tooltip : '')) + '"' + tooltipAttr + '>' +
      '<span class="ui-health-label">' + escapeHtml(label) + '</span>' +
      '<span class="ui-health-value">' + statusPill(status, text) + '</span>' +
      '</div>';
  }

  function loadSystemHealth(container) {
    var strip = typeof container === 'string' ? document.querySelector(container) : container;
    if (!strip) return Promise.resolve();

    var errorTarget = strip.dataset.errorTarget ? document.querySelector(strip.dataset.errorTarget) : null;
    var updatedTarget = strip.dataset.updatedTarget ? document.querySelector(strip.dataset.updatedTarget) : null;

    return Promise.all([
      fetchJson('/api/v1/system/health'),
      fetchJson('/api/v1/weather/current')
    ]).then(function (results) {
      var health = results[0];
      var weather = results[1];
      var dbSize = formatBytes(health.database && health.database.size_bytes);
      var diskUsed = health.disk && health.disk.used_percent !== null ? health.disk.used_percent + '% used' : 'Unknown';
      var diskTooltip = health.disk && health.disk.total_bytes ?
        formatBytes(health.disk.free_bytes) + ' free of ' + formatBytes(health.disk.total_bytes) + ' on the BirdNET-Pi home disk.' :
        'Disk usage could not be read.';
      var lastDetection = health.last_detection_at || 'No detections';
      var weatherSyncEnabled = weather.sync_enabled !== false;
      var weatherIsCurrent = weather.status === 'current';
      var weatherLabel = !weatherSyncEnabled ? 'Disabled' : (weatherIsCurrent ? weatherSummary(weather) : 'Missing current hour');
      var weatherTooltip = !weatherSyncEnabled ?
        'Weather syncing is disabled in Settings. Existing weather history is kept.' :
        (weatherIsCurrent ?
          'Current-hour local observations with stored online fallback. Some fields may be unavailable. Last attempt: ' + (weather.last_attempt_at || 'unknown') + '.' :
          'Current-hour weather is missing. Last synced row: ' + (weather.last_synced_at || 'none') + '.');

      strip.innerHTML =
        renderHealthItem('Recording', health.services.recording.status, health.services.recording.ok ? 'active' : 'inactive', 'Current systemd status for birdnet_recording.service.') +
        renderHealthItem('Analysis', health.services.analysis.status, health.services.analysis.ok ? 'active' : 'inactive', 'Current systemd status for birdnet_analysis.service.') +
        renderHealthItem('Disk', diskUsed, health.disk && health.disk.used_percent !== null && health.disk.used_percent > 90 ? 'warning' : 'active', diskTooltip) +
        renderHealthItem('Database', dbSize, 'complete', 'Current size of scripts/birds.db.') +
        renderHealthItem('Last Detection', lastDetection, health.last_detection_at ? 'active' : 'warning', 'Newest detection timestamp in the database. This refreshes automatically while this page is open.') +
        renderHealthItem('Weather', weatherLabel, !weatherSyncEnabled ? 'complete' : (weatherIsCurrent ? 'current' : 'warning'), weatherTooltip);

      if (errorTarget) errorTarget.innerHTML = '';
      if (updatedTarget) updatedTarget.textContent = 'Updated ' + new Date().toLocaleTimeString([], { hour: 'numeric', minute: '2-digit' });
    }).catch(function () {
      if (errorTarget) setMessage(errorTarget, 'error', 'Health status unavailable', 'System health details could not be loaded.');
    });
  }

  function bindSystemHealth(root) {
    var scope = root || document;
    var containers = scope.querySelectorAll('[data-system-health]');
    containers.forEach(function (container) {
      if (container.dataset.systemHealthBound === 'true') return;
      container.dataset.systemHealthBound = 'true';
      loadSystemHealth(container);
      var refreshMs = parseInt(container.dataset.refreshMs || '30000', 10);
      if (refreshMs > 0) {
        window.setInterval(function () {
          if (!document.hidden) loadSystemHealth(container);
        }, refreshMs);
      }
    });
  }

  function confirmAction(options) {
    options = options || {};
    if (!document.body || typeof HTMLDialogElement === 'undefined') {
      return Promise.resolve(window.confirm(options.message || options.title || 'Continue?'));
    }

    return new Promise(function (resolve) {
      var dialog = document.createElement('dialog');
      dialog.className = 'ui-confirm-dialog';
      dialog.innerHTML =
        '<form method="dialog">' +
        '<h3>' + escapeHtml(options.title || 'Confirm action') + '</h3>' +
        '<p>' + escapeHtml(options.message || 'Are you sure you want to continue?') + '</p>' +
        '<div class="ui-confirm-actions">' +
        '<button value="cancel" class="ui-btn-secondary">' + escapeHtml(options.cancelText || 'Cancel') + '</button>' +
        '<button value="confirm" class="ui-btn-primary ' + (options.danger ? 'ui-btn-danger' : '') + '">' + escapeHtml(options.confirmText || 'Continue') + '</button>' +
        '</div>' +
        '</form>';

      document.body.appendChild(dialog);
      dialog.addEventListener('close', function () {
        var ok = dialog.returnValue === 'confirm';
        dialog.remove();
        resolve(ok);
      });
      dialog.showModal();
      var cancel = dialog.querySelector('[value="cancel"]');
      if (cancel) cancel.focus();
    });
  }

  function submitButton(form, button) {
    if (button && button.name) {
      var hidden = document.createElement('input');
      hidden.type = 'hidden';
      hidden.name = button.name;
      hidden.value = button.value;
      form.appendChild(hidden);
    }
    form.submit();
  }

  function confirmSubmit(event, options) {
    event.preventDefault();
    var button = event.currentTarget || event.target;
    var form = button.form || button.closest('form');
    confirmAction(options).then(function (ok) {
      if (ok && form) submitButton(form, button);
    });
    return false;
  }

  function confirmLink(event, options) {
    event.preventDefault();
    var link = event.currentTarget || event.target;
    confirmAction(options).then(function (ok) {
      if (ok && link.href) window.location.href = link.href;
    });
    return false;
  }

  function bindPersistedControls(root) {
    var scope = root || document;
    var controls = scope.querySelectorAll('[data-ui-persist]');
    var params = new URLSearchParams(window.location.search);
    controls.forEach(function (control) {
      var key = 'birdnet-ui:' + control.getAttribute('data-ui-persist');
      var saved = localStorage.getItem(key);
      if (saved !== null && !(control.name && params.has(control.name))) {
        if (control.type === 'checkbox') control.checked = saved === '1';
        else control.value = saved;
        control.dataset.uiRestored = 'true';
        control.dispatchEvent(new CustomEvent('birdnet:restored', { bubbles: true }));
      }
      control.addEventListener('change', function () {
        localStorage.setItem(key, control.type === 'checkbox' ? (control.checked ? '1' : '0') : control.value);
      });
    });
  }

  document.addEventListener('DOMContentLoaded', function () {
    bindPersistedControls(document);
    bindSystemHealth(document);
  });

  window.BirdNETUI = {
    weatherTemperature: weatherTemperature,
    weatherSummary: weatherSummary,
    weatherEmoji: weatherEmoji,
    escapeHtml: escapeHtml,
    safeHttpUrl: safeHttpUrl,
    formatBytes: formatBytes,
    formatDateTime: formatDateTime,
    skeleton: skeleton,
    message: message,
    setMessage: setMessage,
    statusPill: statusPill,
    loadSystemHealth: loadSystemHealth,
    bindSystemHealth: bindSystemHealth,
    confirmAction: confirmAction,
    confirmSubmit: confirmSubmit,
    confirmLink: confirmLink,
    bindPersistedControls: bindPersistedControls
  };
})();
