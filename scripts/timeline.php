<?php
// Timeline / Day Replay (Phase 3): one day at a glance - hourly weather
// strip on top, then one lane per species with its visits drawn as blocks
// along the 24-hour track. Click a block to hear that visit's best clip.
// Data: /api/v1/detections/timeline (visit clusters + weather).
error_reporting(E_ERROR);
require_once 'scripts/common.php';

$tl_date = isset($_GET['date']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $_GET['date']) ? $_GET['date'] : date('Y-m-d');
?>
<div class="timeline-page" data-date="<?php echo h($tl_date); ?>">
  <div class="timeline-header">
    <h2><?php echo nav_icon('clock'); ?> Day replay</h2>
    <div class="timeline-datenav">
      <a class="ui-button-link" id="tlPrev" href="#" aria-label="Previous day">&larr;</a>
      <input type="date" id="tlDate" value="<?php echo h($tl_date); ?>" max="<?php echo date('Y-m-d'); ?>">
      <a class="ui-button-link" id="tlNext" href="#" aria-label="Next day">&rarr;</a>
      <a class="ui-button-link" href="?view=Recordings">All recordings &rarr;</a>
    </div>
  </div>

  <div class="timeline-stats" id="tlStats"></div>

  <div class="ui-card timeline-card">
    <div class="tl-scroll">
      <div class="tl-inner">
        <div class="tl-weather" id="tlWeather"></div>
        <div class="tl-hours" id="tlHours"></div>
        <div class="tl-lanes" id="tlLanes">
          <div class="ui-skeleton-block" aria-hidden="true">
            <span class="ui-skeleton-line" style="width:90%"></span>
            <span class="ui-skeleton-line" style="width:76%"></span>
            <span class="ui-skeleton-line" style="width:62%"></span>
          </div>
        </div>
      </div>
    </div>
    <div class="tl-player" id="tlPlayer" style="display:none;">
      <div class="tl-player-meta" id="tlPlayerMeta"></div>
      <audio controls id="tlAudio" style="width:100%"></audio>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var date = document.querySelector('.timeline-page').getAttribute('data-date');

  function weatherEmoji(code, isDay) {
    code = Number(code);
    isDay = Number(isDay) !== 0;
    if (code === 0) return isDay ? '☀️' : '🌙';
    if (code >= 1 && code <= 3) return isDay ? '⛅' : '☁️';
    if (code === 45 || code === 48) return '🌫️';
    if (code >= 51 && code <= 55) return isDay ? '🌦️' : '🌧️';
    if (code >= 61 && code <= 65) return '🌧️';
    if (code >= 71 && code <= 75) return '❄️';
    if (code >= 80 && code <= 82) return isDay ? '🌦️' : '🌧️';
    if (code >= 95) return '⛈️';
    return '☁️';
  }

  function hourLabel(h) {
    if (h === 0) return '12a';
    if (h < 12) return h + 'a';
    if (h === 12) return '12p';
    return (h - 12) + 'p';
  }

  function timeToPct(t) {
    var p = t.split(':');
    var secs = (+p[0]) * 3600 + (+p[1] || 0) * 60 + (+p[2] || 0);
    return (secs / 86400) * 100;
  }

  // Extracted clips live at By_Date/<date>/<species dir>/<file>; the species
  // dir strips apostrophes and turns spaces into underscores.
  function clipUrl(species, file) {
    var dir = species.replace(/'/g, '').replace(/ /g, '_');
    return '/By_Date/' + encodeURIComponent(date) + '/' + encodeURIComponent(dir) + '/' + encodeURIComponent(file);
  }

  function shiftDate(days) {
    var d = new Date(date + 'T12:00:00');
    d.setDate(d.getDate() + days);
    return d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
  }
  document.getElementById('tlPrev').href = '?view=Timeline&date=' + shiftDate(-1);
  document.getElementById('tlNext').href = '?view=Timeline&date=' + shiftDate(1);
  document.getElementById('tlDate').addEventListener('change', function () {
    if (this.value) window.location = '?view=Timeline&date=' + this.value;
  });
  document.addEventListener('keydown', function (e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA') return;
    if (e.key === 'ArrowLeft') window.location = '?view=Timeline&date=' + shiftDate(-1);
    if (e.key === 'ArrowRight') window.location = '?view=Timeline&date=' + shiftDate(1);
  });

  function render(data) {
    document.getElementById('tlStats').innerHTML = [
      [data.total_detections.toLocaleString(), 'detections'],
      [data.total_species, 'species'],
      [hourLabel(data.peak_hour), 'peak hour']
    ].map(function (it) {
      return '<div class="ui-card kpi-mini"><div class="kpi-mini-value">' + it[0] + '</div><div class="kpi-mini-label">' + it[1] + '</div></div>';
    }).join('');

    // Weather strip + hour ruler
    var weatherHtml = '';
    var hoursHtml = '';
    for (var h = 0; h < 24; h++) {
      var w = data.weather ? data.weather[h] : null;
      weatherHtml += '<div class="tl-wcell">' + (w
        ? '<span class="hm-weather" aria-hidden="true">' + weatherEmoji(w.code, w.is_day) + '</span><span class="hm-temp">' + w.temp + '&deg;' + (data.weather_unit || 'F') + '</span>'
        : '') + '</div>';
      hoursHtml += '<div class="tl-hcell">' + hourLabel(h) + '</div>';
    }
    document.getElementById('tlWeather').innerHTML = '<div class="tl-label"></div><div class="tl-track">' + weatherHtml + '</div>';
    document.getElementById('tlHours').innerHTML = '<div class="tl-label"></div><div class="tl-track">' + hoursHtml + '</div>';

    // Group clusters by species, keep species ordered by total detections
    var bySpecies = {};
    var totals = {};
    (data.hours || []).forEach(function (hr) {
      (hr.clusters || []).forEach(function (c) {
        if (!bySpecies[c.sci_name]) { bySpecies[c.sci_name] = { name: c.species, clusters: [] }; totals[c.sci_name] = 0; }
        bySpecies[c.sci_name].clusters.push(c);
        totals[c.sci_name] += c.count;
      });
    });
    var order = Object.keys(bySpecies).sort(function (a, b) { return totals[b] - totals[a]; });

    if (order.length === 0) {
      document.getElementById('tlLanes').innerHTML = '<div class="visit-empty" style="padding:20px;">No detections on ' + esc(date) + '.</div>';
      return;
    }

    document.getElementById('tlLanes').innerHTML = order.map(function (sciKey) {
      var sp = bySpecies[sciKey];
      var blocks = sp.clusters.map(function (c) {
        var left = timeToPct(c.first_time);
        var width = Math.max(0.45, timeToPct(c.last_time) - left);
        var pct = Math.round(c.best_confidence * 100);
        var best = c.detections && c.detections.length
          ? c.detections.reduce(function (a, b) { return b.confidence > a.confidence ? b : a; })
          : null;
        return '<button type="button" class="tl-block" style="left:' + left.toFixed(2) + '%;width:' + width.toFixed(2) + '%"' +
          ' data-species="' + esc(sp.name) + '" data-file="' + esc(best ? best.file : '') + '"' +
          ' data-meta="' + esc(c.first_time.slice(0, 5) + (c.first_time === c.last_time ? '' : '–' + c.last_time.slice(0, 5)) + ' · ' + c.count + '× · best ' + pct + '%') + '"' +
          ' title="' + esc(sp.name) + ' ' + esc(c.first_time.slice(0, 5)) + ' · ' + c.count + '× · ' + pct + '%"></button>';
      }).join('');
      return '<div class="tl-lane">' +
        '<a class="tl-label" href="?view=Bird&sci_name=' + encodeURIComponent(sciKey) + '" title="' + esc(sp.name) + '">' +
          '<span class="tl-thumb" data-sci="' + esc(sciKey) + '"></span>' +
          '<span class="tl-name">' + esc(sp.name) + '</span>' +
          '<span class="tl-lane-count">' + totals[sciKey] + '</span>' +
        '</a>' +
        '<div class="tl-track">' + gridLines() + blocks + '</div>' +
        '</div>';
    }).join('');

    loadThumbs();
  }

  // Fill species thumbnails progressively from the local image cache, one
  // lookup per species (shared via a promise so duplicates never refetch).
  function loadThumbs() {
    var pending = {};
    document.querySelectorAll('.tl-thumb[data-sci]').forEach(function (el) {
      var sciName = el.getAttribute('data-sci');
      if (!pending[sciName]) {
        pending[sciName] = fetch('api/v1/image/' + encodeURIComponent(sciName), { headers: { 'Accept': 'application/json' } })
          .then(function (r) { return r.ok ? r.json() : null; })
          .catch(function () { return null; });
      }
      pending[sciName].then(function (j) {
        if (j && j.data && j.data.image_url) {
          var img = document.createElement('img');
          img.loading = 'lazy';
          img.alt = '';
          img.src = j.data.image_url;
          img.onerror = function () { img.remove(); };
          el.appendChild(img);
        }
      });
    });
  }

  function gridLines() {
    var s = '';
    for (var h = 1; h < 24; h++) {
      s += '<span class="tl-grid" style="left:' + ((h / 24) * 100).toFixed(2) + '%"></span>';
    }
    return s;
  }

  document.getElementById('tlLanes').addEventListener('click', function (e) {
    var block = e.target.closest('.tl-block');
    if (!block || !block.getAttribute('data-file')) return;
    var player = document.getElementById('tlPlayer');
    var audio = document.getElementById('tlAudio');
    document.getElementById('tlPlayerMeta').textContent = block.getAttribute('data-species') + ' · ' + block.getAttribute('data-meta');
    player.style.display = '';
    audio.src = clipUrl(block.getAttribute('data-species'), block.getAttribute('data-file'));
    audio.play().catch(function () {});
    document.querySelectorAll('.tl-block.playing').forEach(function (b) { b.classList.remove('playing'); });
    block.classList.add('playing');
  });

  fetch('api/v1/detections/timeline?date=' + encodeURIComponent(date) + '&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
    .then(function (r) { if (!r.ok) throw new Error('timeline failed'); return r.json(); })
    .then(render)
    .catch(function () {
      document.getElementById('tlLanes').innerHTML =
        '<div class="ui-message ui-message-error" role="alert"><strong>Timeline unavailable</strong><span>Could not load this day.</span></div>';
    });
})();
</script>
