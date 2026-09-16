<?php // Included by review.php; all data comes from the read-only case endpoints. ?>
<div class="review-page guided-review">
  <header class="case-page-heading">
    <div><h2>Review</h2><p>A closer listen to discoveries and uncertain matches.</p></div>
    <div class="case-session-start"><button class="ui-button-link case-primary" id="caseSession">Review up to 5</button><button class="ui-button-link" id="caseStop" hidden>End session</button><span>A few recordings is enough. No need to clear the list.</span></div>
  </header>
  <section class="case-navigation" aria-label="Review navigation">
  <div class="case-tabs" role="group" aria-label="Review views">
    <button class="ui-button-link case-view" data-view="recommended" aria-pressed="true">Recommended <span>0</span></button>
    <button class="ui-button-link case-view" data-view="all" aria-pressed="false">All items <span>0</span></button>
    <button class="ui-button-link case-view" data-view="history" aria-pressed="false">History <span>0</span></button>
    <button class="ui-button-link case-view" data-view="samples" aria-pressed="false">Quality checks <span>0</span></button>
  </div>
  <div class="case-view-description"><p id="caseHelp"></p><button class="case-text-button" id="caseRefresh">Refresh</button></div>
  <details class="case-options"><summary>Dates &amp; options <span id="caseRange">Last 7 days</span></summary>
    <div class="case-option-fields"><label>From <input type="date" id="caseStart"></label><label>Through <input type="date" id="caseEnd"></label><button class="ui-button-link" id="caseDates">Apply dates</button><button class="case-text-button" id="caseRecent">Last 7 days</button></div>
    <label class="case-sample-option"><input type="checkbox" id="caseSamples"> Include optional quality checks in short sessions</label>
    <p>Browse up to 31 days at a time. Questions you've acted on stay in History. <a href="?view=Review&amp;visit_tools=1">Advanced whole-visit tools</a> apply decisions to every recording in a visit.</p>
  </details>
  </section>
  <div class="case-queue-heading"><span id="caseCount">Loading…</span><p id="caseProgress" role="status"></p></div>
  <div class="case-feedback">
    <div id="caseStatus" role="status" aria-live="polite"></div>
    <div id="caseError" class="review-error" role="alert"></div>
    <div id="caseRecovery" hidden><button class="ui-button-link" id="caseRetry" hidden>Retry the same decision</button><button class="ui-button-link" id="caseUndo" hidden>Undo last decision</button><span id="caseUndoLabel" class="case-sr-only"></span></div>
  </div>
  <div id="caseQueue" class="review-queue" aria-busy="true"></div>
  <div class="case-pagination"><button class="ui-button-link" id="casePrevious" hidden>Previous items</button><button class="ui-button-link" id="caseNext" hidden>More items</button></div>
  <details id="confirmedPanel" class="case-footer-panel"><summary>Confirmed species by date</summary>
    <p>This list includes prior whole-visit confirmations, identified separately. Machine detection totals remain unchanged unless individual records are rejected or hidden.</p>
    <label>Date <input type="date" id="confirmedDate"></label> <button class="ui-button-link" id="confirmedLoad">Show species</button>
    <div id="confirmedList" role="status"></div>
  </details>
  <details class="case-footer-panel"><summary>Review tips &amp; keyboard shortcuts</summary>
    <p>One clear recording can confirm a bird's presence. It does not verify every detection. “I can't tell” leaves the question unresolved; Later postpones it for 24 hours.</p>
    <p>↑/↓ choose a question · Space plays the selected recording · Y yes · N not this bird · U can't tell · L later. Undo restores decisions, not species-reassignment file renames.</p>
  </details>
</div>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var cases = [], view = 'recommended', offset = 0, total = 0, busy = false, active = 0, selected = {}, expanded = {}, session = null, pending = null, undo = [], customDates = false;
  var rejectionNotice = null;
  function read(key, fallback) { try { return JSON.parse(sessionStorage.getItem(key)) || fallback; } catch (e) { return fallback; } }
  function store(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) {} }
  var storedUndo = read('birdnet-guided-undo', []);
  undo = Array.isArray(storedUndo) ? storedUndo.filter(function (x) { return x && /^[a-f0-9]{64}$/.test(x.token) && typeof x.label === 'string'; }).slice(-20) : [];
  pending = read('birdnet-guided-pending', null);
  if (pending && (!/^[a-f0-9]{32,64}$/.test(pending.request_id) || typeof pending.action !== 'string')) pending = null;
  $('caseSamples').checked = read('birdnet-guided-samples', false) === true;
  function pct(n) { return (Number(n) * 100).toFixed(2).replace(/\.?0+$/, '') + '%'; }
  function dateLabel(value) { var d = new Date(value + 'T12:00:00'); return isNaN(d.getTime()) ? value : d.toLocaleDateString(undefined, {month:'short',day:'numeric',year:'numeric'}); }
  function url(path) { return '/By_Date/' + path.split('/').map(encodeURIComponent).join('/'); }
  function id() { var b = new Uint8Array(16); crypto.getRandomValues(b); return Array.from(b, function (n) { return n.toString(16).padStart(2, '0'); }).join(''); }
  function wake() { try { localStorage.setItem('birdnet-reviews-changed', Date.now() + ':' + Math.random()); } catch (e) {} }
  function controls() {
    document.querySelectorAll('.guided-review button').forEach(function (b) { b.disabled = busy || !!pending; });
    $('caseRetry').hidden = !pending; $('caseRetry').disabled = busy;
    $('caseUndo').hidden = !undo.length; $('caseUndoLabel').textContent = undo.length ? undo[undo.length - 1].label : '';
    $('caseUndo').setAttribute('aria-describedby', 'caseUndoLabel');
    $('caseRecovery').hidden = !pending && !undo.length;
    $('caseStop').hidden = !session;
    $('caseSession').hidden = !!session;
    $('caseQueue').querySelectorAll('input').forEach(function (input) { input.disabled = busy || !!pending; });
    $('caseQueue').setAttribute('aria-busy', busy ? 'true' : 'false');
    document.querySelectorAll('[data-needs-audio]').forEach(function (b) {
      var c = cases[Number(b.dataset.i)]; var clip = c && c.evidence[selected[c.key] || 0];
      if (!clip || clip.audio_available === false) b.disabled = true;
    });
    showRejectionNotice();
  }
  // Keep acknowledgement beside the evidence, not only at the page's top.
  // The next-recording cue requires BOTH a confirmed save and a fresh queue.
  function showRejectionNotice() {
    cases.forEach(function (c, i) {
      var card = $('case-' + i); if (!card) return;
      var box = card.querySelector('.case-rejection-notice'), badge = card.querySelector('.case-next-recording');
      var note = rejectionNotice && rejectionNotice.key === c.key ? rejectionNotice : null;
      box.hidden = !note;
      var clip = c.evidence[selected[c.key] || 0];
      var advanced = note && note.phase === 'saved' && note.refreshed && !c.evidence.some(function (e) { return note.files.indexOf(e.file_name) >= 0; });
      var next = advanced && c.state === 'open' && clip && clip.audio_available !== false;
      badge.hidden = !next || note.seen;
      card.classList.toggle('case-recording-changed', !!next && !note.seen);
      if (!note) return;
      var title, message, control = '';
      if (note.phase === 'saving') {
        title = 'Saving “Not this bird”…'; message = 'Please wait before reviewing another recording.';
      } else if (note.phase === 'failed') {
        title = 'Save not confirmed'; message = pending ? 'Retry the same decision to safely check whether it was saved.' : 'Refresh the recordings before trying again.';
        control = pending ? 'retry' : 'refresh';
      } else {
        title = note.files.length === 1 ? 'Marked “Not this bird”' : note.files.length + ' identifications rejected';
        message = (note.previous ? note.previous + '. ' : '') + 'Audio kept. ' + (next ? 'Now showing another recording for this bird.' : advanced ? 'No new recording is ready here.' : 'Refresh to load the remaining recordings.');
        control = advanced ? 'undo' : 'refresh';
      }
      var lastUndo = undo[undo.length - 1];
      if (control === 'undo' && (!lastUndo || lastUndo.token !== note.token)) control = '';
      var disabled = busy || !!pending && control !== 'retry';
      var html = '<div><strong>' + esc(title) + '</strong><p>' + esc(message) + '</p></div>' + (control ? '<button class="ui-button-link" data-rejection-control="' + control + '"' + (disabled ? ' disabled' : '') + '>' + ({undo:'Undo',retry:'Retry save',refresh:'Refresh recordings'}[control]) + '</button>' : '');
      if (box._noticeHtml !== html) { box.innerHTML = html; box._noticeHtml = html; }
      // Never let a failed refresh expose the already-rejected clip as new work.
      if (note.phase === 'saved' && !advanced) card.querySelectorAll('[data-action]').forEach(function (b) { b.disabled = true; });
      if (note.phase === 'saved' && note.refreshed && note.focus) {
        note.focus = false; active = i; box.focus({preventScroll:true});
        var rect = box.getBoundingClientRect();
        if (rect.top < 0 || rect.bottom > innerHeight) box.scrollIntoView({block:'nearest',behavior:'instant'});
      }
    });
  }
  function query(which, extra) {
    var p = new URLSearchParams({view: which, limit: '25', offset: String(offset)});
    if (customDates && $('caseStart').value) p.set('start', $('caseStart').value);
    if (customDates && $('caseEnd').value) p.set('end', $('caseEnd').value);
    Object.keys(extra || {}).forEach(function (k) { p.set(k, extra[k]); });
    return 'api/v1/reviews/cases?' + p.toString();
  }
  function get(path) { return fetch(path, {headers: {'Accept':'application/json'}}).then(function (r) { if (!r.ok) throw new Error('Could not load review data. Refresh to retry.'); return r.json(); }); }
  function button(i, action, label, audio) { return '<button class="review-btn case-action-' + action + '" data-i="' + i + '" data-action="' + action + '"' + (audio ? ' data-needs-audio="1"' : '') + '>' + label + '</button>'; }
  function pauseAudio(except) { document.querySelectorAll('.guided-review audio').forEach(function (audio) { if (audio !== except) audio.pause(); }); }
  function chooseClip(i, j) {
    var c = cases[i], e = c && c.evidence[j], card = $('case-' + i);
    if (!e || !card) return;
    if (rejectionNotice && rejectionNotice.key === c.key) rejectionNotice.seen = true;
    selected[c.key] = j; active = i;
    var audio = card.querySelector('.case-player');
    if (audio.dataset.clip !== String(j)) { audio.pause(); audio.dataset.clip = String(j); audio.src = url(e.clip_path); }
    audio.hidden = e.audio_available === false;
    audio.setAttribute('aria-label', c.species + ', ' + e.date + ' ' + e.time);
    card.querySelector('.case-audio-warning').hidden = e.audio_available !== false;
    card.querySelector('.case-recording-date').textContent = dateLabel(e.date);
    card.querySelector('.case-recording-time').textContent = e.time;
    card.querySelector('.case-score-value').textContent = pct(e.score);
    card.querySelectorAll('[data-recording-choice]').forEach(function (row) {
      var chosen = Number(row.dataset.recordingChoice) === j;
      row.classList.toggle('is-selected', chosen); row.querySelector('input').checked = chosen;
    });
    controls();
  }
  function render() {
    pauseAudio();
    $('caseQueue').innerHTML = cases.map(function (c, i) {
      var choice = Math.min(selected[c.key] || 0, Math.max(0, c.evidence.length - 1)); selected[c.key] = choice;
      var clip = c.evidence[choice], open = c.state === 'open', stateLabel = {resolved:'Completed',unresolved:'Unresolved',later:'Postponed',archived:'Older question'}[c.state];
      var kindLabel = c.kind === 'discovery' ? 'Confirm presence' : c.kind === 'occurrence' ? 'Unusual occurrence' : c.sample ? 'Quality check' : 'Identification check';
      var why = c.kind === 'discovery' ? 'Not yet confirmed at your station. One clear recording can establish presence.' : c.kind === 'occurrence' ? 'Unusual for this location and season. Listen to check the identification.' : c.sample ? 'An optional spot check of an established species.' : c.priority === 'important' ? 'Recent checks raised doubts about this identification.' : 'An optional check of an uncertain model match.';
      if (c.state === 'resolved') why = 'This review question is complete. Other recordings remain unverified.';
      var saved = expanded[c.key] || {};
      var clips = c.evidence.map(function (e, j) {
        return '<label class="case-recording-choice' + (choice === j ? ' is-selected' : '') + '" data-recording-choice="' + j + '"><input type="radio" name="clip-' + i + '" data-case="' + i + '" value="' + j + '"' + (choice === j ? ' checked' : '') + '><span>' + esc(dateLabel(e.date)) + '<small>' + esc(e.time) + '</small></span><span class="case-choice-score">' + pct(e.score) + '</span></label>';
      }).join('');
      return '<article class="ui-card review-card case-card" id="case-' + i + '" tabindex="0" data-card="' + i + '" aria-labelledby="case-title-' + i + '">' +
        '<header class="case-card-heading"><div><div class="case-kind">' + esc(kindLabel) + '</div><h3 id="case-title-' + i + '"><a href="?view=Bird&amp;sci_name=' + encodeURIComponent(c.sci_name) + '">' + esc(c.species) + '</a></h3><p class="case-why">' + esc(why) + '</p></div>' + (stateLabel ? '<span class="case-state">' + stateLabel + '</span>' : '') + '</header>' +
        '<div class="case-rejection-notice" role="status" aria-live="polite" aria-atomic="true" tabindex="-1" hidden></div>' +
        '<div class="case-workspace"><section class="case-listen" aria-label="Recording evidence"><div class="case-listen-heading"><h4 class="case-step">1 · Listen</h4><span class="case-next-recording" hidden>Next recording</span></div>' +
        (clip ? '<div class="case-recording-meta"><div><span class="case-recording-date">' + esc(dateLabel(clip.date)) + '</span><span class="case-recording-time">' + esc(clip.time) + '</span></div><span class="case-score" title="BirdNET model score, not a guarantee of a correct identification">Model score <strong class="case-score-value">' + pct(clip.score) + '</strong></span></div>' +
          '<audio class="case-player" controls preload="none" data-case="' + i + '" data-clip="' + choice + '" src="' + url(clip.clip_path) + '" aria-label="' + esc(c.species + ', ' + clip.date + ' ' + clip.time) + '"' + (clip.audio_available === false ? ' hidden' : '') + '></audio><p class="case-audio-warning review-media-warning"' + (clip.audio_available === false ? '' : ' hidden') + '>This recording could not be played. Choose another recording or refresh.</p>' +
          '<details class="case-recordings" data-expand="recordings"' + (saved.recordings ? ' open' : '') + '><summary>Choose a recording <span>' + c.evidence.length + ' available</span></summary><fieldset><legend class="case-sr-only">Recording to listen to and review</legend>' + clips + '</fieldset>' + button(i,'evidence','Load more recordings') + '</details>' : '<p class="case-no-audio">No completed, unreviewed audio is available here. No verdict has been inferred.</p>') +
        (c.active ? '<p class="case-context-note">A visit is still active; only completed recordings are offered.</p>' : '') + '</section>' +
        '<section class="case-decision" aria-label="Review decision"><h4 class="case-step">2 · Decide</h4>' +
        (open ? '<p class="case-question">Do you hear this bird?</p><div class="case-verdicts">' + button(i,'confirm','Yes, I hear this bird',true) + button(i,'reject','Not this bird',true) + '</div><div class="case-secondary-decisions">' + button(i,'uncertain',"I can’t tell") + button(i,'later','Later') + '</div><p class="case-scope">Only the selected recording is reviewed.</p>' : '<p class="case-closed-note">' + (c.state === 'resolved' ? 'Use Undo for a recent decision, or advanced tools to change an older verdict.' : c.until_at && c.state === 'later' ? 'Postponed until ' + esc(new Date(c.until_at * 1000).toLocaleString()) + '.' : 'No new identification verdict is needed until you reopen this question.') + '</p>' + (c.state === 'resolved' ? '' : button(i,'resume','Reopen question'))) + '</section></div>' +
        '<details class="case-tools" data-expand="tools"' + (saved.tools ? ' open' : '') + '><summary>Details &amp; advanced tools <span>' + c.visits + ' visits · ' + c.detections + ' detections</span></summary><div class="case-tools-content"><h4>Why this is here</h4><ul class="review-explanations">' + c.reasons.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>' +
        '<p class="case-context-note">' + esc(dateLabel(c.date) + (c.date !== c.end_date ? ' – ' + dateLabel(c.end_date) : '')) + '. One confirmed clip can establish presence, but other recordings remain unverified. “I can’t tell” leaves statistics unchanged.</p>' +
        (c.reopened_reason ? '<p>' + esc(c.reopened_reason) + '</p>' : '') +
        '<div class="review-card-actions">' + button(i,'compare','Compare reference clips',true) + button(i,'hide','Hide selected recording',true) + button(i,'reassign','Reassign selected recording',true) + '</div>' +
        '<details class="case-bulk" data-expand="bulk"' + (saved.bulk ? ' open' : '') + '><summary>Review multiple recordings</summary><p>Choose only recordings you have checked. You will preview the selection before saving.</p><div class="case-bulk-list">' + c.evidence.map(function (e,j) { return '<label><input type="checkbox" data-bulk="' + i + '" value="' + j + '"><span>' + esc(dateLabel(e.date) + ' · ' + e.time) + '</span><span>' + pct(e.score) + '</span></label>'; }).join('') + '</div>' + button(i,'bulk','Preview selected recordings') + '</details><div class="case-detail" role="status"></div></div></details></article>';
    }).join('');
    if (!cases.length) $('caseQueue').innerHTML = '<div class="case-empty"><h3>' + (session ? 'Session complete' : 'No questions in this view') + '</h3><p>' + (session ? 'You can stop here or end the session to see any remaining recommended questions.' : 'Other views or older dates may contain additional evidence. This does not mean every detection is verified.') + '</p></div>';
    $('caseQueue').querySelectorAll('audio').forEach(function (audio) {
      audio.addEventListener('play', function () {
        var i = Number(audio.dataset.case), j = Number(audio.dataset.clip), c = cases[i];
        if (!c) return; active = i; selected[c.key] = j;
        if (rejectionNotice && rejectionNotice.key === c.key) rejectionNotice.seen = true;
        pauseAudio(audio);
        controls();
      });
      audio.addEventListener('error', function () {
        var c = cases[Number(audio.dataset.case)], e = c && c.evidence[Number(audio.dataset.clip)];
        if (!e) return; e.audio_available = false; chooseClip(Number(audio.dataset.case), Number(audio.dataset.clip));
      });
    });
    $('caseQueue').querySelectorAll('[data-expand]').forEach(function (details) {
      details.addEventListener('toggle', function () { var c = cases[Number(details.closest('[data-card]').dataset.card)]; if (c) { expanded[c.key] = expanded[c.key] || {}; expanded[c.key][details.dataset.expand] = details.open; } });
    });
    controls();
  }
  function load() {
    if (busy) return Promise.resolve(); busy = true; controls(); $('caseError').textContent = '';
    return get(query(view)).then(function (data) {
      if (!Array.isArray(data.cases) || !data.counts || !Number.isInteger(data.total)) throw new Error('Invalid review response.');
      total = data.total; $('caseStart').value = data.start; $('caseEnd').value = data.end;
      $('caseRange').textContent = customDates ? dateLabel(data.start) + ' – ' + dateLabel(data.end) : 'Last 7 days';
      if (!$('confirmedDate').value) $('confirmedDate').value = data.end;
      document.querySelectorAll('.case-view').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false'); b.querySelector('span').textContent = data.counts[b.dataset.view]; });
      $('caseCount').textContent = total + ' item' + (total === 1 ? '' : 's') + ' in this view';
      $('caseHelp').textContent = {recommended:'Discoveries and unusual matches worth a closer listen.',all:'Recommended questions plus optional routine checks.',history:'Completed, unresolved, postponed, or waiting for audio.',samples:'Up to two optional spot checks from yesterday—not an accuracy estimate.'}[view];
      cases = data.cases;
      if (session && session.samples) return get(query('samples', {offset:0})).then(function (sampleData) { cases = cases.concat(sampleData.cases.filter(function (c) { return !cases.some(function (x) { return x.key === c.key; }); })); });
    }).then(function () {
      if (session) {
        cases = cases.filter(function (c) { return session.keys.indexOf(c.key) >= 0 && c.ready; });
        $('caseProgress').textContent = 'Session: ' + (session.keys.length - cases.length) + ' of ' + session.keys.length + ' questions finished. ' + total + ' items remain in the selected view.';
      } else $('caseProgress').textContent = '';
      if (rejectionNotice && rejectionNotice.phase === 'saved') rejectionNotice.refreshed = true;
      active = 0; render();
      $('casePrevious').hidden = !offset || !!session; $('caseNext').hidden = offset + 25 >= total || !!session;
    }).catch(function (e) { $('caseError').textContent = e.message; }).finally(function () { busy = false; controls(); });
  }
  function spec(c) { return {key:c.key,version:c.version,date:c.date,end_date:c.end_date,sci_name:c.sci_name}; }
  function send(body) {
    if (busy) return;
    if (body.action === 'reject' && body.case && body.files) {
      if (!rejectionNotice || rejectionNotice.requestId !== body.request_id) {
        var c = cases.find(function (item) { return item.key === body.case.key; });
        var prior = c && c.evidence.find(function (e) { return body.files.length === 1 && e.file_name === body.files[0].file_name; });
        rejectionNotice = {requestId:body.request_id,key:body.case.key,files:body.files.map(function (f) { return f.file_name; }),previous:prior ? 'Previous recording: ' + dateLabel(prior.date) + ' · ' + prior.time : '',refreshed:false,seen:false,focus:true};
      }
      rejectionNotice.phase = 'saving'; pauseAudio();
    } else rejectionNotice = null;
    pending = body; store('birdnet-guided-pending', pending); busy = true; controls(); $('caseError').textContent = ''; $('caseStatus').textContent = 'Saving…';
    fetch('api/v1/reviews/case-actions', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(body)})
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || j.status !== 'ok') { var e = new Error(j.message || 'Could not save.'); e.definitive = r.status >= 400 && r.status < 500; throw e; } return j; }); })
      .then(function (j) {
        if (body.action === 'reject' && rejectionNotice) { rejectionNotice.phase = 'saved'; rejectionNotice.token = j.undo_token; }
        if (body.action === 'undo') undo.pop();
        else if (j.undo_token) undo.push({token:j.undo_token,label:j.message});
        undo = undo.slice(-20); store('birdnet-guided-undo', undo); pending = null; store('birdnet-guided-pending', null);
        if (body.action === 'resume' && body.case) {
          view = 'all';
          if (body.case.date < $('caseStart').value || body.case.end_date > $('caseEnd').value) { customDates = true; $('caseStart').value = body.case.date; $('caseEnd').value = body.case.end_date; }
        }
        $('caseStatus').textContent = j.message; wake(); offset = 0; busy = false; return load();
      }).catch(function (e) {
        if (body.action === 'reject' && rejectionNotice) rejectionNotice.phase = 'failed';
        if (e.definitive) { pending = null; store('birdnet-guided-pending', null); }
        $('caseStatus').textContent = ''; $('caseError').textContent = e.message + (pending ? ' Retry the same decision; its request ID prevents duplicate saves.' : ' Refresh before deciding again.');
        busy = false; controls();
      });
  }
  function act(i, action, files, bulk) {
    if (busy || pending) return;
    var c = cases[i]; if (!c) return;
    if (rejectionNotice && rejectionNotice.key === c.key && rejectionNotice.phase === 'saved' && (!rejectionNotice.refreshed || c.evidence.some(function (e) { return rejectionNotice.files.indexOf(e.file_name) >= 0; }))) return;
    var clip = c.evidence[selected[c.key] || 0];
    if (!files && ['confirm','reject','hide'].indexOf(action) >= 0 && (!clip || clip.audio_available === false)) return;
    var body = {request_id:id(),action:action,case:spec(c),source:view === 'samples' || session && session.samples && c.sample ? 'sample' : 'targeted'};
    if (files) { body.files = files; body.bulk_confirmed = bulk === true; }
    else if (clip) body.files = [{file_name:clip.file_name,file_revision:clip.file_revision}];
    send(body);
  }
  $('caseQueue').addEventListener('change', function (e) { if (e.target.matches('input[type="radio"]')) chooseClip(Number(e.target.dataset.case), Number(e.target.value)); });
  $('caseQueue').addEventListener('click', function (event) {
    var card = event.target.closest('[data-card]'); if (card) active = Number(card.dataset.card);
    var feedbackButton = event.target.closest('[data-rejection-control]');
    if (feedbackButton) {
      if (feedbackButton.disabled || busy) return;
      if (feedbackButton.dataset.rejectionControl === 'retry' && pending) send(pending);
      else if (feedbackButton.dataset.rejectionControl === 'refresh' && !pending) load();
      else if (feedbackButton.dataset.rejectionControl === 'undo' && !pending && rejectionNotice && undo.length && undo[undo.length - 1].token === rejectionNotice.token) $('caseUndo').click();
      return;
    }
    var b = event.target.closest('[data-action]'); if (!b || b.disabled || busy || pending) return;
    var i = Number(b.dataset.i), c = cases[i], action = b.dataset.action, clip = c.evidence[selected[c.key] || 0], detail = card.querySelector('.case-detail');
    if (action === 'evidence') {
      busy = true; controls(); get(query('all', {key:c.key,start:c.date,end:c.end_date,details:1})).then(function (d) {
        if (!d.cases.length) throw new Error('Question changed. Refresh.');
        cases[i] = d.cases[0]; selected[c.key] = Math.max(0, cases[i].evidence.findIndex(function (e) { return clip && e.file_name === clip.file_name; }));
        expanded[c.key] = expanded[c.key] || {}; expanded[c.key].recordings = true; render();
        var picker = $('case-' + i).querySelector('.case-recordings summary'); if (picker) picker.focus();
      }).catch(function (e) { $('caseError').textContent = e.message; }).finally(function () { busy = false; controls(); }); return;
    }
    if (action === 'compare') {
      get('api/v1/reviews/examples?sci_name=' + encodeURIComponent(c.sci_name) + '&exclude=' + encodeURIComponent(clip.file_name) + '&exclude_date=' + encodeURIComponent(clip.date) + '&exclude_from=' + encodeURIComponent(clip.from_time || clip.time) + '&exclude_to=' + encodeURIComponent(clip.to_time || clip.time)).then(function (d) {
        detail.innerHTML = '<p>References: human-confirmed where labeled; other clips are model matches, not verified truth.</p>' + (d.examples || []).map(function (e) { return '<p>' + (e.source === 'confirmed' ? (e.verification === 'individual' ? 'Individually checked' : 'Prior or bulk confirmation') : 'Unverified model match') + ' · ' + pct(e.confidence) + '</p><audio controls preload="none" src="' + url(e.clip_path) + '"></audio>'; }).join('');
        detail.querySelectorAll('audio').forEach(function (audio) { audio.addEventListener('play', function () { pauseAudio(audio); }); });
      }).catch(function (e) { detail.textContent = e.message; }); return;
    }
    if (action === 'bulk') {
      var chosen = Array.from(card.querySelectorAll('[data-bulk]:checked')).map(function (box) { return c.evidence[Number(box.value)]; }).filter(function (e) { return e.audio_available !== false; });
      if (!chosen.length) { detail.textContent = 'Check the individual recordings you intend to review first.'; return; }
      detail.innerHTML = '<p>This changes ONLY these ' + chosen.length + ' selected recordings. New or unselected detections are excluded:</p><ul>' + chosen.map(function (e) { return '<li>' + esc(e.date + ' ' + e.time + ' — ' + e.file_name) + '</li>'; }).join('') + '</ul><button class="ui-button-link" data-bulk-confirm="confirm">Confirm selected</button> <button class="ui-button-link" data-bulk-confirm="reject">Reject selected</button>';
      detail.querySelectorAll('[data-bulk-confirm]').forEach(function (button) { button.addEventListener('click', function () { act(i,button.dataset.bulkConfirm,chosen.map(function (e) { return {file_name:e.file_name,file_revision:e.file_revision}; }),true); }); }); return;
    }
    if (action === 'reassign') {
      busy = true; controls(); get('play.php?getlabels=true').then(function (labels) {
        detail.innerHTML = '<p>Reassignment renames this selected recording using the existing workflow. Decision Undo does not reverse it. Its old identification review will be archived.</p><label>Filter species <input class="case-label-filter"></label><select class="case-labels" size="6"></select><button class="ui-button-link case-rename">Reassign this recording</button>';
        var list = detail.querySelector('select'); function fill(q) { list.innerHTML = ''; labels.filter(function (l) { return l.toLowerCase().indexOf(q.toLowerCase()) >= 0; }).slice(0,400).forEach(function (l) { var o = document.createElement('option'); o.value=l; o.textContent=l.replace('_',' — '); list.appendChild(o); }); } fill('');
        detail.querySelector('input').oninput = function () { fill(this.value); };
        detail.querySelector('button').onclick = function () {
          if (busy || !list.value) return; busy = true; controls();
          fetch('play.php?changefile=' + encodeURIComponent(clip.clip_path) + '&newname=' + encodeURIComponent(list.value), {headers:{'X-Requested-With':'XMLHttpRequest'}}).then(function (r) { var notice = r.headers.get('X-BirdNET-Notice') || ''; return r.text().then(function (t) { if (!r.ok || t.indexOf('OK') !== 0) throw new Error('Reassignment was not confirmed. Refresh before retrying.'); if(notice.indexOf('metadata-not-updated')>=0)throw new Error('Recording renamed, but review metadata could not be updated. Check the station logs before further review.'); }); }).then(function () { $('caseStatus').textContent = 'Recording reassigned. Its prior review is archived; confirm the new identification separately.'; wake(); }).catch(function (e) { $('caseStatus').textContent = e.message; }).finally(function () { busy=false; load(); });
        };
      }).catch(function (e) { detail.textContent=e.message; }).finally(function () { busy=false; controls(); }); return;
    }
    act(i,action);
  });
  document.querySelectorAll('.case-view').forEach(function (b) { b.onclick=function () { if(busy || pending)return; view=b.dataset.view; offset=0;session=null;load(); }; });
  $('caseRefresh').onclick=load;
  $('caseDates').onclick=function () { customDates=true;offset=0;session=null;load(); };
  $('caseRecent').onclick=function () { customDates=false;offset=0;session=null;load(); };
  $('casePrevious').onclick=function () { offset=Math.max(0,offset-25);load(); };
  $('caseNext').onclick=function () { offset+=25;load(); };
  $('caseRetry').onclick=function () { if(pending)send(pending); };
  $('caseUndo').onclick=function () { if(undo.length)send({request_id:id(),action:'undo',undo_token:undo[undo.length-1].token}); };
  $('caseStop').onclick=function () { session=null;offset=0;load(); };
  $('caseSamples').onchange=function () { store('birdnet-guided-samples',this.checked); };
  $('caseSession').onclick=function () {
    if(busy || pending)return; view='recommended';offset=0;
    load().then(function () { if ($('caseError').textContent) return; var base=cases.slice(0,5), withSamples=$('caseSamples').checked;
      var supplement=withSamples && base.length<5 ? get(query('samples',{offset:0})).then(function (d) { return d.cases; }) : Promise.resolve([]);
      busy=true;controls(); supplement.then(function (extra) { extra.forEach(function (c) { if(base.length<5 && !base.some(function(x){return x.key===c.key;}))base.push(c); }); session={keys:base.map(function(c){return c.key;}),samples:withSamples}; cases=base; $('caseProgress').textContent='Session: up to '+base.length+' questions. Stop whenever you like.';render(); }).catch(function(e){$('caseError').textContent=e.message;}).finally(function(){busy=false;controls();});
    });
  };
  $('confirmedLoad').onclick=function () {
    get('api/v1/reviews/confirmed?date='+encodeURIComponent($('confirmedDate').value)).then(function(d){
      $('confirmedList').innerHTML=d.species.length?'<ul>'+d.species.map(function(p){return '<li><a href="?view=Bird&amp;sci_name='+encodeURIComponent(p.sci_name)+'">'+esc(p.species)+'</a> — '+p.individually_checked+' individually checked; '+(p.supporting_recordings-p.individually_checked)+' supporting records from bulk/prior reviews. '+(p.audio_available?'<a href="'+url(p.clip_path)+'" target="_blank" rel="noopener">Supporting recording</a>':'Supporting audio unavailable; confirmation history retained.')+'</li>';}).join('')+'</ul>':'No human-confirmed species on this date. This is not evidence of absence.';
    }).catch(function(e){$('confirmedList').textContent=e.message;});
  };
  document.addEventListener('keydown',function(e){
    if(e.repeat || e.ctrlKey || e.metaKey || e.altKey || /^(INPUT|TEXTAREA|SELECT|BUTTON|A|AUDIO|SUMMARY)$/.test(e.target.tagName) || busy || pending || !cases.length)return;
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){active=Math.max(0,Math.min(cases.length-1,active+(e.key==='ArrowDown'?1:-1)));$('case-'+active).focus();e.preventDefault();}
    if(e.key===' '){var aud=$('case-'+active).querySelector('audio[data-clip="'+(selected[cases[active].key]||0)+'"]');if(aud){aud.paused?aud.play().catch(function(){}):aud.pause();}e.preventDefault();}
    var action={y:'confirm',n:'reject',u:'uncertain',l:'later'}[e.key.toLowerCase()];if(action && cases[active].state==='open')act(active,action);
  });
  setInterval(function(){if(!document.hidden && !busy && !pending && !session && !cases.length)load();},30000);
  if(pending)$('caseError').textContent='A previous save was not acknowledged. Retry the same decision to safely recover its result.';
  load();
})();
</script>
