<?php
// Birds detail page (Phase 3): one page per species - photo, stats, year
// calendar heatmap, hourly pattern, best (pinnable) recording, recent
// visits, notes, and notification preferences. Data comes from
// /api/v1/species/detail; preference writes go to POST /api/v1/species/prefs.
error_reporting(E_ERROR);
require_once 'scripts/common.php';

$bird_sci = isset($_GET['sci_name']) ? trim(html_entity_decode($_GET['sci_name'], ENT_QUOTES)) : '';
if ($bird_sci === '') {
  echo '<div class="ui-message ui-message-warning" role="alert"><strong>No species selected</strong><span>Open a bird from the Species gallery, the Now page, or the Review queue.</span></div>';
  return;
}
?>
<div class="bird-page" data-sci="<?php echo h($bird_sci); ?>">
  <div class="bird-header ui-card">
    <div class="bird-photo" id="birdPhoto"><div class="hero-photo-placeholder"><?php echo nav_icon('bird'); ?></div></div>
    <div class="bird-head-body">
      <h2 id="birdName">Loading&hellip;</h2>
      <div class="hero-sci" id="birdSci"><?php echo h($bird_sci); ?></div>
      <div class="bird-badges" id="birdBadges"></div>
      <div class="bird-actions">
        <button type="button" class="ui-button-link" id="favBtn" aria-pressed="false">&#9734; Favorite</button>
        <button type="button" class="ui-button-link" id="muteBtn" aria-pressed="false">&#128277; Mute notifications</button>
        <label class="bird-notify-mode">
          <span>Notify me:</span>
          <select id="notifyModeSel" class="ui-button-link" aria-label="Notification mode for this species">
            <option value="default">Station default</option>
            <option value="every_visit">Every visit</option>
            <option value="first_daily">First time each day</option>
            <option value="first_lifetime">First ever only</option>
            <option value="rare_only">Only when rare</option>
            <option value="never">Never</option>
          </select>
        </label>
        <a class="ui-button-link" id="infoLink" href="#" target="_blank" rel="noopener">Field guide &rarr;</a>
        <a class="ui-button-link" id="wikiLink" href="#" target="_blank" rel="noopener">Wikipedia &rarr;</a>
      </div>
      <div id="birdActionResult" class="bird-action-result"></div>
    </div>
    <div class="bird-stats" id="birdStats"></div>
  </div>

  <div class="ui-card">
    <h3><?php echo nav_icon('grid'); ?> Past year <span class="now-species-hint">click a day to open it in the Timeline</span></h3>
    <div class="bird-calendar-wrap"><div id="birdCalendar" class="bird-calendar"></div></div>
  </div>

  <div class="bird-two-col">
    <div class="ui-card">
      <h3><?php echo nav_icon('clock'); ?> Activity by hour <span class="now-species-hint">all time</span></h3>
      <div id="birdHourly"></div>
    </div>
    <div class="ui-card">
      <h3><?php echo nav_icon('music'); ?> Best recording</h3>
      <div id="birdBest"><div class="visit-empty">Loading&hellip;</div></div>
    </div>
  </div>

  <div class="bird-two-col">
    <div class="ui-card">
      <h3><?php echo nav_icon('activity'); ?> Recent visits <span class="now-species-hint">last 7 days</span></h3>
      <ul class="bird-visit-list" id="birdVisits"><li class="visit-empty">Loading&hellip;</li></ul>
    </div>
    <div class="ui-card">
      <h3><?php echo nav_icon('file-text'); ?> Notes</h3>
      <div class="bird-note-add">
        <textarea id="noteInput" rows="2" maxlength="2000" placeholder="e.g. Pair nesting in the maple - confirmed visually"></textarea>
        <button type="button" class="ui-button-link" id="noteAddBtn">Add note</button>
      </div>
      <ul class="bird-notes" id="birdNotes"><li class="visit-empty">Loading&hellip;</li></ul>
    </div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var sci = document.querySelector('.bird-page').getAttribute('data-sci');
  var prefs = { favorite: 0, muted: 0 };
  var detail = null;

  function clipUrl(path, ext) {
    return '/By_Date/' + path.split('/').map(encodeURIComponent).join('/') + (ext || '');
  }

  function postJson(url, body) {
    return fetch(url, {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify(body)
    }).then(function (r) {
      if (r.status === 401) throw new Error('Sign in required - open Settings once, then retry.');
      if (!r.ok) throw new Error('Request failed (' + r.status + ')');
      return r.json();
    });
  }

  function showActionResult(msg, isError) {
    var box = document.getElementById('birdActionResult');
    box.innerHTML = '<span class="' + (isError ? 'review-error' : 'review-done') + '">' + esc(msg) + '</span>';
    setTimeout(function () { box.innerHTML = ''; }, 4000);
  }

  function renderPrefButtons() {
    var fav = document.getElementById('favBtn');
    fav.innerHTML = (prefs.favorite ? '&#9733; Favorited' : '&#9734; Favorite');
    fav.classList.toggle('active-pref', !!prefs.favorite);
    fav.setAttribute('aria-pressed', prefs.favorite ? 'true' : 'false');
    var mute = document.getElementById('muteBtn');
    mute.innerHTML = (prefs.muted ? '&#128276; Unmute notifications' : '&#128277; Mute notifications');
    mute.classList.toggle('active-pref', !!prefs.muted);
    mute.setAttribute('aria-pressed', prefs.muted ? 'true' : 'false');
  }

  document.getElementById('favBtn').addEventListener('click', function () {
    postJson('api/v1/species/prefs', { sci_name: sci, favorite: !prefs.favorite })
      .then(function (j) { prefs = j.prefs; renderPrefButtons(); showActionResult(prefs.favorite ? 'Added to favorites.' : 'Removed from favorites.'); })
      .catch(function (e) { showActionResult(e.message, true); });
  });
  document.getElementById('muteBtn').addEventListener('click', function () {
    postJson('api/v1/species/prefs', { sci_name: sci, muted: !prefs.muted })
      .then(function (j) { prefs = j.prefs; renderPrefButtons(); showActionResult(prefs.muted ? 'Notifications muted for this species.' : 'Notifications unmuted.'); })
      .catch(function (e) { showActionResult(e.message, true); });
  });

  document.getElementById('notifyModeSel').addEventListener('change', function () {
    var sel = this;
    var labels = {
      'default': 'Following the station-wide rules.',
      every_visit: 'You will be notified at the start of every visit.',
      first_daily: 'You will be notified the first time each day.',
      first_lifetime: 'You will only be notified if this species is brand new.',
      rare_only: 'You will only be notified when a detection is rare.',
      never: 'This species will never notify you.'
    };
    postJson('api/v1/species/prefs', { sci_name: sci, notify_mode: sel.value })
      .then(function (j) { showActionResult(labels[j.prefs.notify_mode] || 'Saved.'); })
      .catch(function (e) { showActionResult(e.message, true); });
  });

  function renderStats(d) {
    var items = [
      [d.total_detections.toLocaleString(window.BIRDNET_UNITS.numLocale), 'detections'],
      [d.first_seen || '—', 'first heard'],
      [d.last_seen || '—', 'last heard'],
      [Math.round(d.best_confidence * 100) + '%', 'best confidence']
    ];
    if (d.precision !== null && d.precision !== undefined) {
      items.push([Math.round(d.precision * 100) + '%', 'confirmed by you']);
    }
    document.getElementById('birdStats').innerHTML = items.map(function (it) {
      return '<div class="bird-stat"><div class="bird-stat-value">' + esc(String(it[0])) + '</div><div class="bird-stat-label">' + esc(it[1]) + '</div></div>';
    }).join('');
  }

  function renderCalendar(calendar) {
    var box = document.getElementById('birdCalendar');
    var today = new Date();
    var start = new Date(today);
    start.setDate(start.getDate() - 364);
    start.setDate(start.getDate() - start.getDay()); // back to Sunday
    var max = 1;
    Object.keys(calendar).forEach(function (k) { if (calendar[k] > max) max = calendar[k]; });

    var html = '';
    var monthLabels = [];
    var d = new Date(start);
    var week = 0;
    var cells = '';
    while (d <= today) {
      var col = '';
      for (var dow = 0; dow < 7; dow++) {
        if (d > today) break;
        var key = d.getFullYear() + '-' + String(d.getMonth() + 1).padStart(2, '0') + '-' + String(d.getDate()).padStart(2, '0');
        var c = calendar[key] || 0;
        var level = c === 0 ? 0 : Math.min(4, 1 + Math.floor((c / max) * 3.99));
        if (d.getDate() === 1) {
          monthLabels.push({ week: week, label: d.toLocaleString([], { month: 'short' }) });
        }
        col += '<a class="cal-cell l' + level + '" href="?view=Timeline&date=' + key + '" title="' + key + ' — ' + c + ' detection' + (c === 1 ? '' : 's') + '"></a>';
        d.setDate(d.getDate() + 1);
      }
      cells += '<div class="cal-week">' + col + '</div>';
      week++;
    }
    var months = '<div class="cal-months">';
    var lastWeek = -2;
    monthLabels.forEach(function (m) {
      if (m.week - lastWeek >= 2) {
        months += '<span style="left:' + (m.week * 13) + 'px">' + m.label + '</span>';
        lastWeek = m.week;
      }
    });
    months += '</div>';
    box.innerHTML = months + '<div class="cal-grid">' + cells + '</div>';
  }

  function renderHourly(hourly) {
    var max = Math.max.apply(null, hourly.concat([1]));
    var bars = hourly.map(function (c, h) {
      var label = (h === 0 ? '12a' : (h < 12 ? h + 'a' : (h === 12 ? '12p' : (h - 12) + 'p')));
      return '<i' + (c > 0 ? ' class="on"' : '') + ' style="height:' + Math.max(4, Math.round((c / max) * 100)) + '%" title="' + label + ' — ' + c + '"></i>';
    }).join('');
    var axis = '';
    for (var h = 0; h < 24; h += 3) {
      axis += '<div class="axis-col"><span class="axis-time">' + (h === 0 ? '12a' : (h < 12 ? h + 'a' : (h === 12 ? '12p' : (h - 12) + 'p'))) + '</span></div>';
    }
    document.getElementById('birdHourly').innerHTML =
      '<div class="spark bird-spark">' + bars + '</div><div class="spark-axis">' + axis + '</div>';
  }

  // The server already picked the best recording that still exists on disk
  // (the pinned clip first, then the shared best-recording definition), so a
  // purged clip is replaced quietly. It only gets a mention when it scored
  // higher than what is shown - or when nothing survived at all.
  function purgedNote(d) {
    if (!d.purged_best) return '';
    return '<div class="bird-best-purged visit-empty">A ' + Math.round(d.purged_best.confidence * 100) + '% recording from ' +
      esc(d.purged_best.date) + ' was removed by disk cleanup.</div>';
  }

  function renderBest(d) {
    var box = document.getElementById('birdBest');
    if (!d.best_recording) {
      box.innerHTML = '<div class="visit-empty">No recordings on disk for this species' +
        (d.purged_best ? ' - its clips were removed by disk cleanup.' : '.') + '</div>';
      return;
    }
    var b = d.best_recording;
    var pinned = !!b.pinned;
    box.innerHTML =
      '<img class="bird-best-spec" loading="lazy" src="' + clipUrl(b.clip_path, '.png') + '" alt="Spectrogram" onerror="this.style.display=\'none\'">' +
      '<audio controls preload="none" src="' + clipUrl(b.clip_path) + '" style="width:100%" onerror="this.parentNode.querySelector(\'.bird-best-meta\').innerHTML += \' <span class=&quot;visit-empty&quot;>(clip could not be loaded)</span>\'; this.remove();"></audio>' +
      '<div class="bird-best-meta">' + esc(b.date) + ' ' + esc(b.time) + ' &middot; ' + Math.round(b.confidence * 100) + '%' +
      (pinned
        ? ' <span class="crowned-tag" title="Your pick: shown as the best recording and always kept by disk cleanup">&#128204; Pinned</span> <button type="button" class="ui-button-link" id="crownBtn" data-mode="uncrown">Unpin</button>'
        : ' <button type="button" class="ui-button-link" id="crownBtn" data-mode="crown" title="Feature this clip as the species\' best recording and always keep it. The highest-scoring clips are protected automatically; pinning overrides that pick.">&#128204; Pin as best recording</button>') +
      '</div>' + purgedNote(d);
    document.getElementById('crownBtn').addEventListener('click', function () {
      var mode = this.getAttribute('data-mode');
      postJson('api/v1/species/prefs', { sci_name: sci, crowned_clip: mode === 'crown' ? b.file : '' })
        .then(function () {
          // Re-fetch: after an unpin the automatic best may be a different clip.
          return fetch('api/v1/species/detail?sci_name=' + encodeURIComponent(sci) + '&_=' + Date.now(), { headers: { 'Accept': 'application/json' } });
        })
        .then(function (r) { return r.json(); })
        .then(function (d2) {
          detail = d2;
          renderBest(detail);
          showActionResult(mode === 'crown' ? 'Pinned - this clip is now the featured best recording and will always be kept.' : 'Unpinned - back to the automatic best recording.');
        })
        .catch(function (e) { showActionResult(e.message, true); });
    });
  }

  function renderVisits(visits) {
    var list = document.getElementById('birdVisits');
    if (!visits || visits.length === 0) {
      list.innerHTML = '<li class="visit-empty">No visits in the last 7 days.</li>';
      return;
    }
    list.innerHTML = visits.slice().reverse().map(function (v) {
      var range = v.first_time.slice(0, 5) + (v.first_time === v.last_time ? '' : '–' + v.last_time.slice(0, 5));
      return '<li class="bird-visit-item">' +
        '<div class="bird-visit-meta">' + esc(v.date) + ' &middot; ' + esc(range) + ' &middot; ' + v.count + '&times; &middot; ' + Math.round(v.best_confidence * 100) + '%</div>' +
        '<audio controls preload="none" src="' + clipUrl(v.clip_path) + '" onerror="this.parentNode.querySelector(\'.bird-visit-meta\').innerHTML += \' <span class=&quot;visit-empty&quot;>(clip purged)</span>\'; this.remove();"></audio>' +
        '</li>';
    }).join('');
  }

  function loadNotes() {
    fetch('api/v1/notes?sci_name=' + encodeURIComponent(sci) + '&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.json(); })
      .then(function (j) {
        var list = document.getElementById('birdNotes');
        if (!j.notes || j.notes.length === 0) {
          list.innerHTML = '<li class="visit-empty">No notes yet.</li>';
          return;
        }
        list.innerHTML = j.notes.map(function (n) {
          return '<li class="bird-note"><span class="bird-note-body">' + esc(n.body) + '</span>' +
            '<span class="bird-note-meta">' + esc(n.created_at || '') +
            ' <button type="button" class="note-del" data-id="' + n.id + '" aria-label="Delete note">&times;</button></span></li>';
        }).join('');
      })
      .catch(function () {});
  }

  document.getElementById('birdNotes').addEventListener('click', function (e) {
    var btn = e.target.closest('.note-del');
    if (!btn) return;
    postJson('api/v1/notes', { action: 'delete', id: parseInt(btn.getAttribute('data-id'), 10) })
      .then(function () { loadNotes(); })
      .catch(function (err) { showActionResult(err.message, true); });
  });

  document.getElementById('noteAddBtn').addEventListener('click', function () {
    var input = document.getElementById('noteInput');
    var text = input.value.trim();
    if (!text) return;
    postJson('api/v1/notes', { body: text, sci_name: sci, date: new Date().toISOString().slice(0, 10) })
      .then(function () { input.value = ''; loadNotes(); })
      .catch(function (err) { showActionResult(err.message, true); });
  });

  fetch('api/v1/image/' + encodeURIComponent(sci), { headers: { 'Accept': 'application/json' } })
    .then(function (r) { return r.ok ? r.json() : Promise.reject(); })
    .then(function (j) {
      if (j && j.data && j.data.image_url) {
        document.getElementById('birdPhoto').innerHTML = '<img src="' + esc(j.data.image_url) + '" alt="' + esc(sci) + '">';
      }
    }).catch(function () {});

  fetch('api/v1/species/detail?sci_name=' + encodeURIComponent(sci) + '&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
    .then(function (r) {
      if (!r.ok) throw new Error(r.status === 404 ? 'This species has no detections at your station.' : 'Could not load species details.');
      return r.json();
    })
    .then(function (d) {
      detail = d;
      document.getElementById('birdName').textContent = d.common_name;
      document.title = d.common_name + ' · BirdNET-Pi';
      if (d.prefs) { prefs = { favorite: parseInt(d.prefs.favorite, 10) || 0, muted: parseInt(d.prefs.muted, 10) || 0 }; }
      renderPrefButtons();
      document.getElementById('notifyModeSel').value = (d.prefs && d.prefs.notify_mode) || 'default';
      var badges = [];
      // New = first heard within the last week (not merely "heard on one day ever")
      var firstSeenDays = d.first_seen ? (Date.now() - new Date(d.first_seen + 'T12:00:00').getTime()) / 86400000 : 999;
      if (firstSeenDays <= 7) badges.push('<span class="hero-badge new">NEW SPECIES</span>');
      if (d.note_count > 0) badges.push('<span class="hero-badge regular">' + d.note_count + ' note' + (d.note_count === 1 ? '' : 's') + '</span>');
      document.getElementById('birdBadges').innerHTML = badges.join(' ');
      document.getElementById('infoLink').href = d.info_url;
      document.getElementById('infoLink').textContent = d.info_title + ' →';
      document.getElementById('wikiLink').href = d.wikipedia_url;
      renderStats(d);
      renderCalendar(d.calendar || {});
      renderHourly(d.hourly_pattern || []);
      renderBest(d);
      renderVisits(d.recent_visits || []);
      loadNotes();
    })
    .catch(function (e) {
      document.querySelector('.bird-page').innerHTML =
        '<div class="ui-message ui-message-error" role="alert"><strong>Unavailable</strong><span>' + esc(e.message) + '</span></div>';
    });
})();
</script>
