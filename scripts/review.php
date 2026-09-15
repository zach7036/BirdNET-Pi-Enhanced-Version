<?php
// Review queue (Phase 3): visit-level triage with keyboard shortcuts,
// verify-by-comparison strips (your own confirmed clips of the same species),
// and species reassignment via the existing change-identification flow.
// One decision per visit fans out to every member detection.
error_reporting(E_ERROR);
require_once 'scripts/common.php';
?>
<div class="review-page">
  <div class="ui-section-header">
    <h3><?php echo nav_icon('search'); ?> Review queue</h3>
    <span class="ui-meta" id="reviewQueueMeta">Loading&hellip;</span>
  </div>
  <p class="doctor-intro">
    Visits worth a second listen: uncertain IDs, first-ever or rare species, and frequently rejected matches.
    One decision covers the displayed visit window. New detections may need another review. Actions require sign-in.
  </p>
  <div class="review-toolbar">
    <button type="button" class="ui-button-link" id="reviewRefresh">Refresh queue</button>
    <span class="review-progress" id="reviewProgress"></span>
    <span class="review-keys">Keys: <kbd>&darr;</kbd>/<kbd>&uarr;</kbd> move &middot; <kbd>Space</kbd> play &middot; <kbd>Y</kbd> confirm &middot; <kbd>N</kbd> not this bird &middot; <kbd>R</kbd> reassign &middot; <kbd>U</kbd> unsure &middot; <kbd>H</kbd> hide</span>
  </div>
  <div id="reviewActionStatus" class="review-done" role="status" aria-live="polite"></div>
  <div id="reviewLoadStatus" class="review-error" role="alert"></div>
  <div id="reviewSuggestions"></div>
  <div id="reviewQueue" class="review-queue">
    <div class="ui-skeleton-block" aria-hidden="true">
      <span class="ui-skeleton-line" style="width:90%"></span>
      <span class="ui-skeleton-line" style="width:76%"></span>
      <span class="ui-skeleton-line" style="width:62%"></span>
    </div>
  </div>
</div>

<div id="reassignModal" class="reassign-modal" style="display:none;" role="dialog" aria-modal="true" aria-label="Reassign species">
  <div class="reassign-box ui-card">
    <h3>Reassign to which species?</h3>
    <input type="text" id="reassignFilter" placeholder="Type to filter&hellip;" autocomplete="off">
    <select id="reassignList" size="9"></select>
    <div class="reassign-actions">
      <button type="button" class="ui-button-link" id="reassignCancel">Cancel</button>
      <button type="button" class="ui-button-link reassign-go" id="reassignGo">Reassign visit</button>
    </div>
    <div id="reassignStatus" class="bird-action-result"></div>
  </div>
</div>

<script>
(function () {
  'use strict';
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s); };
  var reasonLabels = {
    uncertain: 'Uncertain ID',
    first_lifetime: 'First ever',
    region_rare: 'Rare here this week',
    yard_rare: 'Rare visitor',
    low_precision: 'Often misidentified'
  };
  var queue = [];
  var activeIdx = -1;
  var reviewedCount = 0;
  var exampleCache = {};
  var labelsCache = null;
  var loading = false;
  var saving = false;
  var reassignNeedsRefresh = false;

  function updateBusy() {
    var modalOpen = document.getElementById('reassignModal').style.display !== 'none';
    document.getElementById('reviewRefresh').disabled = loading || saving || modalOpen;
    document.querySelectorAll('.review-btn').forEach(function (button) {
      button.disabled = loading || saving || modalOpen || button.closest('.review-card').classList.contains('reviewed');
    });
    document.getElementById('reviewQueue').setAttribute('aria-busy', loading || saving ? 'true' : 'false');
  }

  function notifyReviewChange() {
    // Wake a Now page open in another tab. Storage may be blocked in private
    // browsers; its regular refresh remains the fallback.
    try { localStorage.setItem('birdnet-reviews-changed', Date.now() + ':' + Math.random()); } catch (e) {}
  }

  function clipUrl(path, ext) {
    return '/By_Date/' + path.split('/').map(encodeURIComponent).join('/') + (ext || '');
  }

  function updateProgress() {
    document.getElementById('reviewProgress').textContent =
      reviewedCount + ' reviewed this session';
  }

  function loadQueue() {
    if (loading || saving) return Promise.resolve();
    loading = true;
    updateBusy();
    document.getElementById('reviewLoadStatus').textContent = '';
    // Reload the first remaining batch. Advancing an offset after removing
    // visits would skip work, and local subtraction misses eligibility changes.
    return fetch('api/v1/reviews/queue?days=7&limit=25&_=' + Date.now(), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { if (!r.ok) throw new Error('queue failed'); return r.json(); })
      .then(function (data) {
        if (!Array.isArray(data.queue) || !Number.isInteger(data.total) || data.total < 0 ||
            (data.total > 0 && data.queue.length === 0)) throw new Error('Invalid queue response');
        queue = data.queue;
        activeIdx = -1;
        exampleCache = {};
        document.getElementById('reviewQueueMeta').textContent =
          data.total + ' visit' + (data.total === 1 ? '' : 's') + ' to review (last ' + data.days + ' days)' +
          (data.total > queue.length ? ' · showing ' + queue.length : '');
        updateProgress();
        renderSuggestions(data.suggestions || []);
        var box = document.getElementById('reviewQueue');
        if (queue.length === 0) {
          box.innerHTML = '<div class="ui-message ui-message-success" role="status"><strong>All caught up</strong><span>Nothing needs review right now.</span></div>';
          return;
        }
        box.innerHTML = queue.map(function (v, i) {
          var pct = Math.round(v.best_confidence * 100);
          var range = v.first_time.slice(0, 5) + (v.first_time === v.last_time ? '' : '–' + v.last_time.slice(0, 5));
          var reasons = (v.reasons || []).map(function (r) {
            return '<span class="review-reason ' + esc(r) + '">' + esc(reasonLabels[r] || r) + '</span>';
          }).join(' ');
          return '<div class="ui-card review-card" id="reviewCard' + i + '" data-i="' + i + '" tabindex="0">' +
            '<div class="review-card-media">' +
              '<img loading="lazy" src="' + clipUrl(v.clip_path, '.png') + '" alt="Spectrogram" onerror="this.style.display=\'none\'">' +
              '<audio controls preload="none" src="' + clipUrl(v.clip_path) + '" onerror="this.style.display=\'none\'"></audio>' +
            '</div>' +
            '<div class="review-card-body">' +
              '<div class="review-card-title"><a href="?view=Bird&sci_name=' + encodeURIComponent(v.sci_name) + '">' + esc(v.species) + '</a> ' + reasons + '</div>' +
              '<div class="review-card-meta">' + esc(v.date) + ' &middot; ' + esc(range) + ' &middot; ' +
                v.count + ' detection' + (v.count === 1 ? '' : 's') + ' &middot; best ' + pct + '%</div>' +
              '<div class="review-card-actions">' +
                actBtn(i, 'confirmed', 'Confirm', 'Y') +
                actBtn(i, 'false_positive', 'Not this bird', 'N') +
                actBtn(i, 'reassign', 'Reassign&hellip;', 'R') +
                actBtn(i, 'unsure', 'Unsure', 'U') +
                actBtn(i, 'hidden', 'Hide', 'H') +
              '</div>' +
              '<div class="review-examples" id="reviewExamples' + i + '"></div>' +
              '<div class="review-card-result" id="reviewResult' + i + '"></div>' +
            '</div>' +
            '</div>';
        }).join('');
        setActive(0);
      })
      .catch(function () {
        document.getElementById('reviewQueueMeta').textContent = 'Review count unavailable';
        document.getElementById('reviewLoadStatus').textContent = 'Could not refresh the queue. Use Refresh queue to retry.';
        if (!queue.length) document.getElementById('reviewQueue').innerHTML = '';
      })
      .then(function () {
        loading = false;
        updateBusy();
      });
  }

  function actBtn(i, action, label, key) {
    return '<button type="button" class="review-btn ' + action + '" data-i="' + i + '" data-action="' + action + '">' +
      label + ' <kbd>' + key + '</kbd></button>';
  }

  function renderSuggestions(suggestions) {
    var box = document.getElementById('reviewSuggestions');
    if (!suggestions.length) {
      box.innerHTML = '';
      return;
    }
    box.innerHTML = suggestions.map(function (s) {
      return '<div class="ui-message ui-message-warning" role="status"><strong>Consider excluding ' + esc(s.com_name) + '</strong>' +
        '<span>You rejected ' + s.rejected_pct + '% of its reviewed detections (' + s.rejected + ' of ' + (s.confirmed + s.rejected) + '). ' +
        'Adding it to the <a href="?view=Excluded">excluded species list</a> stops these detections at the source.</span></div>';
    }).join('');
  }

  function setActive(i) {
    if (i < 0 || i >= queue.length) return;
    if (activeIdx >= 0) {
      var prev = document.getElementById('reviewCard' + activeIdx);
      if (prev) prev.classList.remove('active');
    }
    activeIdx = i;
    var card = document.getElementById('reviewCard' + i);
    if (!card) return;
    card.classList.add('active');
    card.scrollIntoView({ block: 'nearest', behavior: 'smooth' });
    loadExamples(i);
  }

  function loadExamples(i) {
    var v = queue[i];
    var box = document.getElementById('reviewExamples' + i);
    if (!v || !box || box.dataset.loaded) return;
    box.dataset.loaded = '1';
    var renderExamples = function (examples) {
      if (!examples || examples.length === 0) {
        box.innerHTML = '';
        return;
      }
      var confirmedCount = examples.filter(function (ex) { return ex.source === 'confirmed'; }).length;
      var label = confirmedCount === examples.length ? 'Compare with clips you confirmed:'
        : (confirmedCount === 0 ? 'Compare with this station’s strongest matches:'
          : 'Compare with known-good clips (&#10003; = you confirmed):');
      box.innerHTML = '<div class="review-examples-label">' + label + '</div>' +
        '<div class="review-examples-row">' + examples.map(function (ex) {
          return '<figure class="review-example">' +
            '<img loading="lazy" src="' + clipUrl(ex.clip_path, '.png') + '" alt="Reference spectrogram">' +
            '<figcaption>' + Math.round(ex.confidence * 100) + '%' + (ex.source === 'confirmed' ? ' &#10003;' : '') + '</figcaption>' +
            '<audio controls preload="none" src="' + clipUrl(ex.clip_path) + '"></audio>' +
            '</figure>';
        }).join('') + '</div>';
      // If a clip vanished from disk anyway (purged since the API answered),
      // drop its figure - and if none survive, drop the label rather than
      // leaving an orphaned "Compare with..." heading over an empty row.
      box.querySelectorAll('.review-example img').forEach(function (img) {
        img.addEventListener('error', function () {
          var fig = img.closest('figure');
          if (fig) fig.parentNode.removeChild(fig);
          if (!box.querySelector('.review-example')) box.innerHTML = '';
        });
      });
    };
    // Keyed per visit, not per species: the exclusion window differs for
    // each queued visit of the same species, so their strips differ too.
    var cacheKey = v.sci_name + '|' + v.date + '|' + v.first_time + '|' + v.last_time;
    if (exampleCache[cacheKey]) {
      renderExamples(exampleCache[cacheKey]);
      return;
    }
    fetch('api/v1/reviews/examples?sci_name=' + encodeURIComponent(v.sci_name) +
        '&exclude=' + encodeURIComponent(v.best_file) +
        '&exclude_date=' + encodeURIComponent(v.date) +
        '&exclude_from=' + encodeURIComponent(v.first_time) +
        '&exclude_to=' + encodeURIComponent(v.last_time), { headers: { 'Accept': 'application/json' } })
      .then(function (r) { return r.ok ? r.json() : { examples: [] }; })
      .then(function (j) {
        exampleCache[cacheKey] = j.examples || [];
        renderExamples(exampleCache[cacheKey]);
      })
      .catch(function () {});
  }

  function markCardDone(i, message) {
    var card = document.getElementById('reviewCard' + i);
    if (!card || card.classList.contains('reviewed')) return;
    card.classList.add('reviewed');
    card.querySelectorAll('.review-btn').forEach(function (b) { b.disabled = true; });
    document.getElementById('reviewResult' + i).textContent = message;
    document.getElementById('reviewActionStatus').textContent = message;
    reviewedCount++;
    updateProgress();
  }

  function submitReview(i, status) {
    var card = document.getElementById('reviewCard' + i);
    if (loading || saving || !card || card.classList.contains('reviewed')) return;
    var v = queue[i];
    var resultBox = document.getElementById('reviewResult' + i);
    saving = true;
    updateBusy();
    document.getElementById('reviewActionStatus').textContent = '';
    resultBox.textContent = 'Saving…';
    fetch('api/v1/reviews', {
      method: 'POST',
      headers: { 'Content-Type': 'application/json', 'X-Requested-With': 'XMLHttpRequest' },
      body: JSON.stringify({
        status: status,
        visit: { sci_name: v.sci_name, date: v.date, from_time: v.first_time, to_time: v.last_time }
      })
    })
      .then(function (r) {
        if (r.status === 401) throw new Error('Sign in required - open any Settings page first, then retry.');
        if (!r.ok) throw new Error('Review failed (' + r.status + ')');
        return r.json();
      })
      .then(function (j) {
        if (j.status !== 'ok' || !Number.isInteger(j.affected) || j.affected < 1) throw new Error('The station did not confirm the save. Refresh the queue before retrying.');
        var labels = { confirmed: 'confirmed', false_positive: 'marked not this bird', unsure: 'marked unsure', hidden: 'hidden' };
        markCardDone(i, 'Saved - ' + j.affected + ' detection' + (j.affected === 1 ? '' : 's') + ' ' + (labels[status] || status) + '.');
        notifyReviewChange();
        saving = false;
        return loadQueue();
      })
      .catch(function (err) {
        resultBox.innerHTML = '<span class="review-error">' + esc(err.message) + '</span>';
        saving = false;
        updateBusy();
      });
  }

  // ===== Reassignment (reuses play.php's change-identification flow) =====
  var reassignTarget = -1;

  function openReassign(i) {
    var card = document.getElementById('reviewCard' + i);
    if (loading || saving || !card || card.classList.contains('reviewed')) return;
    reassignTarget = i;
    reassignNeedsRefresh = false;
    var modal = document.getElementById('reassignModal');
    modal.style.display = '';
    updateBusy();
    document.getElementById('reassignGo').disabled = false;
    document.getElementById('reassignStatus').innerHTML = '';
    document.getElementById('reassignFilter').value = '';
    var fill = function () {
      fillReassignList('');
      document.getElementById('reassignFilter').focus();
    };
    if (labelsCache) { fill(); return; }
    fetch('play.php?getlabels=true')
      .then(function (r) { return r.json(); })
      .then(function (labels) { labelsCache = labels; fill(); })
      .catch(function () {
        document.getElementById('reassignStatus').innerHTML = '<span class="review-error">Could not load the species list.</span>';
      });
  }

  function fillReassignList(filter) {
    var list = document.getElementById('reassignList');
    list.innerHTML = '';
    var f = filter.toUpperCase();
    var shown = 0;
    for (var i = 0; i < (labelsCache || []).length && shown < 400; i++) {
      if (f && labelsCache[i].toUpperCase().indexOf(f) === -1) continue;
      var opt = document.createElement('option');
      opt.value = labelsCache[i];
      opt.text = labelsCache[i].split('_')[1] || labelsCache[i];
      list.appendChild(opt);
      shown++;
    }
  }

  document.getElementById('reassignFilter').addEventListener('input', function () { fillReassignList(this.value); });
  function closeReassign() {
    if (saving) return;
    document.getElementById('reassignModal').style.display = 'none';
    updateBusy();
    if (reassignNeedsRefresh) loadQueue();
  }
  document.getElementById('reassignCancel').addEventListener('click', closeReassign);
  document.getElementById('reassignModal').addEventListener('click', function (e) {
    if (e.target === this) closeReassign();
  });

  document.getElementById('reassignGo').addEventListener('click', function () {
    var list = document.getElementById('reassignList');
    var newLabel = list.value;
    if (!newLabel || reassignTarget < 0 || saving || loading || reassignNeedsRefresh) return;
    var v = queue[reassignTarget];
    var clips = v.member_clips || [];
    var status = document.getElementById('reassignStatus');
    var go = this;
    if (!clips.length) return;
    saving = true;
    updateBusy();
    document.getElementById('reviewActionStatus').textContent = '';
    document.getElementById('reassignCancel').disabled = true;
    go.disabled = true;
    var done = 0;
    var failed = 0;
    var lastError = '';

    var finish = function () {
      saving = false;
      document.getElementById('reassignCancel').disabled = false;
      // Even a lost response can mean a file was renamed. Refresh before
      // another attempt, rather than reusing possibly stale member paths.
      reassignNeedsRefresh = true;
      notifyReviewChange();
      if (failed === 0) {
        markCardDone(reassignTarget, 'Reassigned ' + done + ' detection' + (done === 1 ? '' : 's') + ' to ' + (newLabel.split('_')[1] || newLabel) + '.');
        closeReassign();
      } else {
        status.innerHTML = '<span class="review-error">' + done + ' renamed, ' + failed + ' failed. ' + lastError + ' Close this dialog to refresh the remaining visits before retrying.</span>';
        updateBusy();
      }
    };

    var next = function () {
      if (done + failed >= clips.length) {
        finish();
        return;
      }
      status.innerHTML = '<span class="review-done">Reassigning ' + (done + failed + 1) + ' of ' + clips.length + '&hellip;</span>';
      fetch('play.php?changefile=' + encodeURIComponent(clips[done + failed]) + '&newname=' + encodeURIComponent(newLabel), { credentials: 'same-origin', headers: { 'X-Requested-With': 'XMLHttpRequest' } })
        .then(function (r) {
          if (r.status === 401) {
            // fetch() cannot show the browser's sign-in prompt; a page
            // navigation can. Point at Settings, whose prompt covers the
            // whole station, then the user can retry from here.
            failed += clips.length - done - failed;
            lastError = 'Your browser is not signed in to this station. ' +
              '<a href="?view=Settings" target="_blank">Open Settings</a> to sign in, then retry.';
            finish();
            return null;
          }
          return r.text();
        })
        .then(function (t) {
          if (t === null) return;
          if (t.indexOf('OK') === 0) {
            done++;
          } else {
            failed++;
            // play.php answers "Error : <script output>" - show it instead
            // of guessing; auth and rename-script failures look identical
            // otherwise.
            var detail = t.replace(/<[^>]*>/g, ' ').replace(/\s+/g, ' ').trim().slice(0, 200);
            lastError = detail !== '' ? esc(detail) : 'The rename script failed with no output.';
          }
          next();
        })
        .catch(function () {
          failed++;
          lastError = 'The station could not be reached.';
          next();
        });
    };
    next();
  });

  // ===== Card actions + keyboard =====
  document.getElementById('reviewQueue').addEventListener('click', function (e) {
    var card = e.target.closest('.review-card');
    if (card) setActive(parseInt(card.getAttribute('data-i'), 10));
    var btn = e.target.closest('.review-btn');
    if (!btn || btn.disabled) return;
    var i = parseInt(btn.getAttribute('data-i'), 10);
    var action = btn.getAttribute('data-action');
    if (action === 'reassign') {
      openReassign(i);
    } else {
      submitReview(i, action);
    }
  });

  document.addEventListener('keydown', function (e) {
    if (e.target.tagName === 'INPUT' || e.target.tagName === 'TEXTAREA' || e.target.tagName === 'SELECT') return;
    if (document.getElementById('reassignModal').style.display !== 'none') {
      if (e.key === 'Escape') closeReassign();
      return;
    }
    if (loading || saving || activeIdx < 0 || queue.length === 0) return;
    var card = document.getElementById('reviewCard' + activeIdx);
    var isDone = card && card.classList.contains('reviewed');
    switch (e.key) {
      case 'ArrowDown': case 'j': setActive(Math.min(queue.length - 1, activeIdx + 1)); e.preventDefault(); break;
      case 'ArrowUp': case 'k': setActive(Math.max(0, activeIdx - 1)); e.preventDefault(); break;
      case ' ': {
        var audio = card && card.querySelector('.review-card-media audio');
        if (audio) { audio.paused ? audio.play().catch(function () {}) : audio.pause(); }
        e.preventDefault();
        break;
      }
      case 'y': case 'Y': if (!isDone) submitReview(activeIdx, 'confirmed'); break;
      case 'n': case 'N': if (!isDone) submitReview(activeIdx, 'false_positive'); break;
      case 'u': case 'U': if (!isDone) submitReview(activeIdx, 'unsure'); break;
      case 'h': case 'H': if (!isDone) submitReview(activeIdx, 'hidden'); break;
      case 'r': case 'R': if (!isDone) openReassign(activeIdx); break;
    }
  });

  document.getElementById('reviewRefresh').addEventListener('click', loadQueue);
  loadQueue();
})();
</script>
