<?php // Included by review.php; all data comes from the read-only case endpoints. ?>
<div class="review-page guided-review">
  <div class="ui-section-header"><h3>Review</h3><span id="caseCount" class="ui-meta">Loading…</span></div>
  <p>Check an interesting discovery or a possible mistake. One clear recording can confirm a bird's presence; it does not verify every recording.</p>
  <div class="review-filters" role="group" aria-label="Review views">
    <button class="ui-button-link case-view" data-view="recommended" aria-pressed="true">Recommended <span>0</span></button>
    <button class="ui-button-link case-view" data-view="all" aria-pressed="false">All review items <span>0</span></button>
    <button class="ui-button-link case-view" data-view="history" aria-pressed="false">History <span>0</span></button>
    <button class="ui-button-link case-view" data-view="samples" aria-pressed="false">Optional quality checks <span>0</span></button>
  </div>
  <p id="caseHelp" class="ui-meta"></p>
  <div class="review-toolbar">
    <button class="ui-button-link" id="caseSession">Review a few recordings</button>
    <label><input type="checkbox" id="caseSamples"> Include optional quality checks in my session</label>
    <button class="ui-button-link" id="caseRefresh">Refresh</button>
    <button class="ui-button-link" id="caseStop" hidden>End session</button>
  </div>
  <p id="caseProgress" role="status"></p>
  <details><summary>Date range and advanced tools</summary>
    <div class="review-toolbar"><label>From <input type="date" id="caseStart"></label><label>Through <input type="date" id="caseEnd"></label><button class="ui-button-link" id="caseDates">Apply dates</button><button class="ui-button-link" id="caseRecent">Last 7 days</button></div>
    <p>Browse older questions by date (up to 31 days at a time). Acted-on questions remain in History beyond this window. <a href="?view=Review&amp;visit_tools=1">Advanced whole-visit tools</a> apply decisions to all recordings in a visit.</p>
  </details>
  <div id="caseStatus" role="status" aria-live="polite"></div>
  <div id="caseError" class="review-error" role="alert"></div>
  <div class="review-toolbar"><button class="ui-button-link" id="caseRetry" hidden>Retry the same decision</button><button class="ui-button-link" id="caseUndo" hidden>Undo last decision</button><span id="caseUndoLabel"></span></div>
  <div id="caseQueue" class="review-queue" aria-busy="true"></div>
  <div class="review-toolbar"><button class="ui-button-link" id="casePrevious" hidden>Previous items</button><button class="ui-button-link" id="caseNext" hidden>More items</button></div>
  <details id="confirmedPanel"><summary>Human-confirmed species by date</summary>
    <p>This list includes prior whole-visit confirmations, identified separately. Machine detection totals remain unchanged unless individual records are rejected or hidden.</p>
    <label>Date <input type="date" id="confirmedDate"></label> <button class="ui-button-link" id="confirmedLoad">Show species</button>
    <div id="confirmedList" role="status"></div>
  </details>
  <p class="ui-meta">Keys: ↑/↓ choose a question · Space plays its selected recording · Y yes · N not this bird · U can't tell · L later. Undo restores review decisions, not species-reassignment file renames.</p>
</div>
<script>
(function () {
  'use strict';
  var $ = function (id) { return document.getElementById(id); };
  var esc = window.BirdNETUI ? BirdNETUI.escapeHtml : function (s) { return String(s == null ? '' : s).replace(/[&<>"']/g, function (c) { return {'&':'&amp;','<':'&lt;','>':'&gt;','"':'&quot;',"'":'&#39;'}[c]; }); };
  var cases = [], view = 'recommended', offset = 0, total = 0, busy = false, active = 0, selected = {}, session = null, pending = null, undo = [], customDates = false;
  function read(key, fallback) { try { return JSON.parse(sessionStorage.getItem(key)) || fallback; } catch (e) { return fallback; } }
  function store(key, value) { try { sessionStorage.setItem(key, JSON.stringify(value)); } catch (e) {} }
  var storedUndo = read('birdnet-guided-undo', []);
  undo = Array.isArray(storedUndo) ? storedUndo.filter(function (x) { return x && /^[a-f0-9]{64}$/.test(x.token) && typeof x.label === 'string'; }).slice(-20) : [];
  pending = read('birdnet-guided-pending', null);
  if (pending && (!/^[a-f0-9]{32,64}$/.test(pending.request_id) || typeof pending.action !== 'string')) pending = null;
  $('caseSamples').checked = read('birdnet-guided-samples', false) === true;
  function pct(n) { return (Number(n) * 100).toFixed(2).replace(/\.?0+$/, '') + '%'; }
  function url(path) { return '/By_Date/' + path.split('/').map(encodeURIComponent).join('/'); }
  function id() { var b = new Uint8Array(16); crypto.getRandomValues(b); return Array.from(b, function (n) { return n.toString(16).padStart(2, '0'); }).join(''); }
  function wake() { try { localStorage.setItem('birdnet-reviews-changed', Date.now() + ':' + Math.random()); } catch (e) {} }
  function controls() {
    document.querySelectorAll('.guided-review button').forEach(function (b) { b.disabled = busy || !!pending; });
    $('caseRetry').hidden = !pending; $('caseRetry').disabled = busy;
    $('caseUndo').hidden = !undo.length; $('caseUndoLabel').textContent = undo.length ? undo[undo.length - 1].label : '';
    $('caseStop').hidden = !session;
    $('caseQueue').setAttribute('aria-busy', busy ? 'true' : 'false');
    document.querySelectorAll('[data-needs-audio]').forEach(function (b) {
      var c = cases[Number(b.dataset.i)]; var clip = c && c.evidence[selected[c.key] || 0];
      if (!clip || clip.audio_available === false) b.disabled = true;
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
  function button(i, action, label, audio) { return '<button class="review-btn" data-i="' + i + '" data-action="' + action + '"' + (audio ? ' data-needs-audio="1"' : '') + '>' + label + '</button>'; }
  function render() {
    $('caseQueue').innerHTML = cases.map(function (c, i) {
      var choice = Math.min(selected[c.key] || 0, Math.max(0, c.evidence.length - 1)); selected[c.key] = choice;
      var clips = c.evidence.map(function (e, j) {
        return '<div class="case-evidence"><label><input type="radio" name="clip-' + i + '" data-case="' + i + '" value="' + j + '"' + (choice === j ? ' checked' : '') + '> ' + esc(e.date + ' ' + e.time) + ' · model score ' + pct(e.score) + '</label>' +
          '<audio controls preload="none" data-case="' + i + '" data-clip="' + j + '" src="' + url(e.clip_path) + '"></audio>' +
          '<label class="case-bulk-choice"><input type="checkbox" data-bulk="' + i + '" value="' + j + '"> Include in an explicit bulk decision</label></div>';
      }).join('');
      var open = c.state === 'open';
      return '<article class="ui-card review-card case-card" id="case-' + i + '" tabindex="0" data-card="' + i + '"><div class="review-card-body">' +
        '<h4><a href="?view=Bird&amp;sci_name=' + encodeURIComponent(c.sci_name) + '">' + esc(c.species) + '</a> — ' + esc(c.title) + '</h4>' +
        '<p class="ui-meta">' + esc(c.date + (c.date !== c.end_date ? ' – ' + c.end_date : '')) + ' · ' + c.visits + ' visits · ' + c.detections + ' detections · ' + esc(c.state) + '</p>' +
        '<ul class="review-explanations">' + c.reasons.map(function (r) { return '<li>' + esc(r) + '</li>'; }).join('') + '</ul>' +
        (c.reopened_reason ? '<p>' + esc(c.reopened_reason) + '</p>' : '') +
        (c.active ? '<p class="ui-meta">A visit is still active. Only completed evidence is offered here.</p>' : '') +
        (c.until_at && c.state === 'later' ? '<p>Postponed until ' + esc(new Date(c.until_at * 1000).toLocaleString()) + '.</p>' : '') +
        (clips || '<p>No completed, unreviewed audio is available in this view. No verdict has been inferred.</p>') +
        '<div class="review-card-actions">' + (open ? button(i,'confirm','Yes, I hear this bird',true) + button(i,'reject','Not this bird',true) + button(i,'uncertain',"I can’t tell") + button(i,'later','Later') : (c.state === 'resolved' ? '<span>Completed. Use Undo for a recent decision or the advanced tools to change an older verdict.</span>' : button(i,'resume','Reopen question'))) + '</div>' +
        '<p class="review-action-help">Yes / Not this bird apply only to the selected recording. Other recordings remain unverified. “Can’t tell” does not affect statistics.</p>' +
        '<details><summary>More evidence and actions</summary><div class="review-card-actions">' + button(i,'evidence','Load more recordings') + button(i,'compare','Compare with reference clips',true) + button(i,'hide','Hide selected recording',true) + button(i,'reassign','Reassign selected recording',true) + button(i,'bulk','Review checked recordings') + '</div><div class="case-detail"></div></details>' +
        '<div class="case-result" role="status"></div></div></article>';
    }).join('');
    if (!cases.length) $('caseQueue').innerHTML = '<div class="ui-message"><strong>' + (session ? 'Session complete' : 'No questions in this view') + '</strong><span>' + (session ? 'You can stop here or end the session to see any remaining recommended questions.' : 'Other views or older dates may contain additional evidence. This does not mean every detection is verified.') + '</span></div>';
    $('caseQueue').querySelectorAll('audio').forEach(function (audio) {
      audio.addEventListener('play', function () {
        var i = Number(audio.dataset.case), j = Number(audio.dataset.clip), c = cases[i];
        if (!c) return; active = i; selected[c.key] = j;
        var radio = $('case-' + i).querySelector('input[type="radio"][value="' + j + '"]'); if (radio) radio.checked = true;
        $('caseQueue').querySelectorAll('audio').forEach(function (other) { if (other !== audio) other.pause(); });
        controls();
      });
      audio.addEventListener('error', function () {
        var c = cases[Number(audio.dataset.case)], e = c && c.evidence[Number(audio.dataset.clip)];
        if (!e) return; e.audio_available = false;
        var note = document.createElement('p'); note.className = 'review-media-warning'; note.textContent = 'This recording could not be played. Refresh to look for other evidence.'; audio.replaceWith(note); controls();
      });
    });
    $('caseQueue').querySelectorAll('.case-card details').forEach(function (details) {
      details.addEventListener('toggle', function () { details.closest('.case-card').classList.toggle('case-advanced', details.open); });
    });
    controls();
  }
  function load() {
    if (busy) return Promise.resolve(); busy = true; controls(); $('caseError').textContent = '';
    return get(query(view)).then(function (data) {
      if (!Array.isArray(data.cases) || !data.counts || !Number.isInteger(data.total)) throw new Error('Invalid review response.');
      total = data.total; $('caseStart').value = data.start; $('caseEnd').value = data.end;
      if (!$('confirmedDate').value) $('confirmedDate').value = data.end;
      document.querySelectorAll('.case-view').forEach(function (b) { b.setAttribute('aria-pressed', b.dataset.view === view ? 'true' : 'false'); b.querySelector('span').textContent = data.counts[b.dataset.view]; });
      $('caseCount').textContent = total + ' item' + (total === 1 ? '' : 's') + ' in this view';
      $('caseHelp').textContent = {recommended:'Important questions with completed audio. This count matches Today for the default last-seven-days window.',all:'Includes optional routine checks. You do not need to clear this list.',history:'Completed, unresolved, postponed, active and unavailable questions. Choose older dates to browse untouched older questions.',samples:'Up to two optional checks from yesterday, across different species when possible. These are not a calibrated accuracy estimate.'}[view];
      cases = data.cases;
      if (session && session.samples) return get(query('samples', {offset:0})).then(function (sampleData) { cases = cases.concat(sampleData.cases.filter(function (c) { return !cases.some(function (x) { return x.key === c.key; }); })); });
    }).then(function () {
      if (session) {
        cases = cases.filter(function (c) { return session.keys.indexOf(c.key) >= 0 && c.ready; });
        $('caseProgress').textContent = 'Session: ' + (session.keys.length - cases.length) + ' of ' + session.keys.length + ' questions finished. ' + total + ' items remain in the selected view.';
      } else $('caseProgress').textContent = '';
      active = 0; render();
      $('casePrevious').hidden = !offset || !!session; $('caseNext').hidden = offset + 25 >= total || !!session;
    }).catch(function (e) { $('caseError').textContent = e.message; }).finally(function () { busy = false; controls(); });
  }
  function spec(c) { return {key:c.key,version:c.version,date:c.date,end_date:c.end_date,sci_name:c.sci_name}; }
  function send(body) {
    if (busy) return; pending = body; store('birdnet-guided-pending', pending); busy = true; controls(); $('caseError').textContent = ''; $('caseStatus').textContent = 'Saving…';
    fetch('api/v1/reviews/case-actions', {method:'POST', headers:{'Content-Type':'application/json','X-Requested-With':'XMLHttpRequest'}, body:JSON.stringify(body)})
      .then(function (r) { return r.json().then(function (j) { if (!r.ok || j.status !== 'ok') { var e = new Error(j.message || 'Could not save.'); e.definitive = r.status >= 400 && r.status < 500; throw e; } return j; }); })
      .then(function (j) {
        if (body.action === 'undo') undo.pop();
        else if (j.undo_token) undo.push({token:j.undo_token,label:j.message});
        undo = undo.slice(-20); store('birdnet-guided-undo', undo); pending = null; store('birdnet-guided-pending', null);
        if (body.action === 'resume' && body.case) {
          view = 'all';
          if (body.case.date < $('caseStart').value || body.case.end_date > $('caseEnd').value) { customDates = true; $('caseStart').value = body.case.date; $('caseEnd').value = body.case.end_date; }
        }
        $('caseStatus').textContent = j.message; wake(); offset = 0; busy = false; return load();
      }).catch(function (e) {
        if (e.definitive) { pending = null; store('birdnet-guided-pending', null); }
        $('caseStatus').textContent = ''; $('caseError').textContent = e.message + (pending ? ' Retry the same decision; its request ID prevents duplicate saves.' : ' Refresh before deciding again.');
        busy = false; controls();
      });
  }
  function act(i, action, files, bulk) {
    if (busy || pending) return;
    var c = cases[i]; if (!c) return;
    var clip = c.evidence[selected[c.key] || 0];
    if (!files && ['confirm','reject','hide'].indexOf(action) >= 0 && (!clip || clip.audio_available === false)) return;
    var body = {request_id:id(),action:action,case:spec(c),source:view === 'samples' || session && session.samples && c.sample ? 'sample' : 'targeted'};
    if (files) { body.files = files; body.bulk_confirmed = bulk === true; }
    else if (clip) body.files = [{file_name:clip.file_name,file_revision:clip.file_revision}];
    send(body);
  }
  $('caseQueue').addEventListener('change', function (e) { if (e.target.matches('input[type="radio"]')) { var c = cases[Number(e.target.dataset.case)]; selected[c.key] = Number(e.target.value); active = Number(e.target.dataset.case); controls(); } });
  $('caseQueue').addEventListener('click', function (event) {
    var card = event.target.closest('[data-card]'); if (card) active = Number(card.dataset.card);
    var b = event.target.closest('[data-action]'); if (!b || b.disabled || busy || pending) return;
    var i = Number(b.dataset.i), c = cases[i], action = b.dataset.action, clip = c.evidence[selected[c.key] || 0], detail = card.querySelector('.case-detail');
    if (action === 'evidence') {
      busy = true; controls(); get(query('all', {key:c.key,start:c.date,end:c.end_date,details:1})).then(function (d) { if (!d.cases.length) throw new Error('Question changed. Refresh.'); cases[i] = d.cases[0]; render(); }).catch(function (e) { $('caseError').textContent = e.message; }).finally(function () { busy = false; controls(); }); return;
    }
    if (action === 'compare') {
      get('api/v1/reviews/examples?sci_name=' + encodeURIComponent(c.sci_name) + '&exclude=' + encodeURIComponent(clip.file_name) + '&exclude_date=' + encodeURIComponent(clip.date) + '&exclude_from=' + encodeURIComponent(clip.from_time || clip.time) + '&exclude_to=' + encodeURIComponent(clip.to_time || clip.time)).then(function (d) {
        detail.innerHTML = '<p>References: human-confirmed where labeled; other clips are model matches, not verified truth.</p>' + (d.examples || []).map(function (e) { return '<p>' + (e.source === 'confirmed' ? (e.verification === 'individual' ? 'Individually checked' : 'Prior or bulk confirmation') : 'Unverified model match') + ' · ' + pct(e.confidence) + '</p><audio controls preload="none" src="' + url(e.clip_path) + '"></audio>'; }).join('');
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
    if(e.repeat || e.ctrlKey || e.metaKey || e.altKey || /INPUT|TEXTAREA|SELECT|BUTTON|A|AUDIO/.test(e.target.tagName) || busy || pending || !cases.length)return;
    if(e.key==='ArrowDown'||e.key==='ArrowUp'){active=Math.max(0,Math.min(cases.length-1,active+(e.key==='ArrowDown'?1:-1)));$('case-'+active).focus();e.preventDefault();}
    if(e.key===' '){var aud=$('case-'+active).querySelector('audio[data-clip="'+(selected[cases[active].key]||0)+'"]');if(aud){aud.paused?aud.play().catch(function(){}):aud.pause();}e.preventDefault();}
    var action={y:'confirm',n:'reject',u:'uncertain',l:'later'}[e.key.toLowerCase()];if(action && cases[active].state==='open')act(active,action);
  });
  setInterval(function(){if(!document.hidden && !busy && !pending && !session && !cases.length)load();},30000);
  if(pending)$('caseError').textContent='A previous save was not acknowledged. Retry the same decision to safely recover its result.';
  load();
})();
</script>
