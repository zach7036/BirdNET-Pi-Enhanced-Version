// Real shipped Review/Now markup and JavaScript, with every request mocked.
// Run: BIRDNET_TEST_BROWSER=chrome node --test tests/test_review_ui.js
// Requires Playwright. No PHP server, station database, or live network used.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
let browser;
test.before(async () => {
  browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined});
});
test.after(async () => { if (browser) await browser.close(); });

function markup(file) {
  return '<!doctype html><html><head><meta charset="utf-8"></head><body>' +
    fs.readFileSync(path.join(root, file), 'utf8')
      .replace('<?php echo js_arg($visit_explainer); ?>', '"Five-minute visits"')
      .replace(/<\?php[\s\S]*?\?>/g, '')
      .replace(/<script src=[\s\S]*?<\/script>/g, '') + '</body></html>';
}
function visit(i) {
  return {sci_name: 'Testus birdus' + i, species: 'Test Bird ' + i, date: '2026-09-15',
    first_time: '12:00:00', last_time: '12:01:00', count: 2, best_confidence: .75,
    best_file: 'bird' + i + '.wav', clip_path: 'bird' + i + '.wav',
    member_clips: ['bird' + i + 'a.wav', 'bird' + i + 'b.wav'], reasons: ['uncertain'],
    reason_details: [{code: 'uncertain', text: 'Best match 75% is below the 85% review cutoff (band starts at 60%).'}],
    priority: 'routine', group: 'routine', active: false, audio_available: true, reassign_available: true,
    audio_fallback: false, playback_file: 'bird' + i + '.wav', playback_confidence: .75};
}
function counts(visits) {
  const result = {ready: 0, important: 0, routine: 0, active: 0, unavailable: 0, skipped: 0};
  for (const v of visits) {
    result[v.group]++;
    if (v.group === 'important' || v.group === 'routine') result.ready++;
  }
  return result;
}
async function fixture(t, count = 2) {
  const context = await browser.newContext({viewport: {width: 1200, height: 900}});
  t.after(() => context.close());
  const errors = [];
  context.on('page', page => page.on('pageerror', e => errors.push(e.message)));
  t.after(() => assert.deepEqual(errors, [], 'no uncaught script errors'));
  await context.addInitScript({path: path.join(root, 'homepage/static/ui-helpers.js')});
  const state = {visits: Array.from({length: count}, (_, i) => visit(i)), queues: [], posts: [],
    nowCalls: 0, renameCalls: 0, failQueue: false, postFailure: null, waitPost: null,
    afterPost: null, renameFailureAt: null, nowHook: null, nowUnknown: false, latestVisit: null, undo: new Map()};
  await context.route('**/*', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const json = (value, status = 200) => route.fulfill({status, contentType: 'application/json', body: JSON.stringify(value)});
    if (req.isNavigationRequest()) return route.fulfill({contentType: 'text/html', body: markup(url.searchParams.get('view') === 'Now' ? 'scripts/now.php' : 'scripts/review.php')});
    if (url.pathname.endsWith('/RobotoFlex-Regular.ttf')) return route.fulfill({contentType: 'font/ttf', body: fs.readFileSync(path.join(root, 'homepage/static/RobotoFlex-Regular.ttf'))});
    if (url.pathname.endsWith('/reviews/queue')) {
      state.queues.push(url);
      if (state.failQueue) return json({status: 'error'}, 503);
      const group = url.searchParams.get('group') || 'ready';
      const selected = state.visits.filter(v => group === v.group || (group === 'ready' && ['important', 'routine'].includes(v.group)));
      const totals = counts(state.visits);
      return json({queue: selected.slice(0, 25), total: selected.length, days: 7, suggestions: [], counts: totals,
        pending_total: totals.ready + totals.active + totals.unavailable + totals.skipped, gap_seconds: 300, group});
    }
    if (url.pathname.endsWith('/reviews/examples')) return json({examples: []});
    if (url.pathname.endsWith('/reviews') && req.method() === 'POST') {
      const body = req.postDataJSON();
      state.posts.push(body);
      assert.equal(req.headers()['x-requested-with'], 'XMLHttpRequest');
      if (state.waitPost) await state.waitPost;
      if (state.postFailure === 'network') return route.abort();
      if (typeof state.postFailure === 'number') return json({status: 'error'}, state.postFailure);
      if (state.postFailure === 'false-ok') return json({status: 'error', affected: 2});
      if (state.postFailure === 'zero') return json({status: 'ok', affected: 0});
      if (body.action === 'undo') {
        const before = state.undo.get(body.undo_token);
        assert.ok(before, 'server-issued undo token');
        state.visits = structuredClone(before);
        return json({status: 'ok', affected: 2, action: 'undo'});
      }
      const token = state.posts.length.toString(16).padStart(64, '0');
      state.undo.set(token, structuredClone(state.visits));
      if (body.action === 'skip') {
        const v = state.visits.find(v => v.sci_name === body.visit.sci_name);
        v.group = 'skipped'; v.deferred_until = Math.floor(Date.now() / 1000) + 86400;
      } else if (body.action === 'resume') {
        state.visits.find(v => v.sci_name === body.visit.sci_name).group = 'routine';
      } else state.visits = state.visits.filter(v => v.sci_name !== body.visit.sci_name);
      if (state.afterPost) state.afterPost();
      return json({status: 'ok', affected: 2, review_status: body.status || body.action, undo_token: token});
    }
    if (url.pathname.endsWith('/dashboard/now')) {
      state.nowCalls++;
      const oldCounts = counts(state.visits);
      const caseCounts = {recommended: oldCounts.ready, all: oldCounts.ready,
        history: oldCounts.active + oldCounts.unavailable + oldCounts.skipped,
        active: oldCounts.active, unavailable: oldCounts.unavailable, later: oldCounts.skipped, unresolved: 0};
      const data = {latest_visit: state.latestVisit, today: {detections: 10, species: 1, visits: 2, new_species: 0},
        review_worthy: state.nowUnknown ? null : oldCounts.ready, review_counts: state.nowUnknown ? null : caseCounts, story: []};
      if (state.nowHook) await state.nowHook(state.nowCalls);
      return json(data);
    }
    if (url.pathname.endsWith('/overview.php')) return json({species: [], hourly: {}, weather: {}});
    if (url.searchParams.has('getlabels')) return json(['Testus correctus_Correct Bird']);
    if (url.searchParams.has('changefile')) {
      state.renameCalls++;
      if (state.renameFailureAt === state.renameCalls) return route.fulfill({body: 'Error : injected failure'});
      if (state.renameCalls % 2 === 0) state.visits.shift();
      return route.fulfill({body: 'OK'});
    }
    return route.abort();
  });
  const page = await context.newPage();
  await page.goto('http://review.test/?view=Review');
  await ready(page, count);
  return {page, context, state};
}
async function ready(page, count) {
  await page.waitForFunction(n => document.getElementById('reviewQueueMeta').textContent.startsWith(n + ' visit') &&
    !document.getElementById('reviewRefresh').disabled, count);
}
async function review(page, action) {
  await page.locator('.review-btn.' + action).first().click();
}

for (const action of ['false_positive', 'confirmed', 'hidden']) {
  test(action + ' refreshes authoritative count and remaining cards', async t => {
    const {page, state} = await fixture(t);
    await review(page, action);
    await ready(page, 1);
    assert.equal(state.queues.length, 2);
    assert.equal(state.posts[0].status, action);
    assert.equal(await page.locator('.review-card').count(), 1);
    assert.match(await page.locator('#reviewActionStatus').textContent(), /^Saved - 2 detections/);
    assert.equal(await page.locator('#reviewProgress').textContent(), '1 reviewed this session');
    await review(page, action);
    await ready(page, 0);
    assert.match(await page.locator('#reviewQueue').textContent(), /All caught up/);
    assert.equal(await page.locator('#reviewProgress').textContent(), '2 reviewed this session');
  });
}

test('important, routine and active views keep separate counts and explanations', async t => {
  const {page, state} = await fixture(t, 3);
  Object.assign(state.visits[0], {group: 'important', priority: 'important', best_confidence: .96,
    reasons: ['first_lifetime'], reason_details: [{code: 'first_lifetime', text: 'Recorded on this species’ first day; no occurrence has been confirmed yet.'}]});
  Object.assign(state.visits[2], {group: 'active', active: true});
  await page.locator('#reviewRefresh').click();
  await ready(page, 2);
  assert.equal(await page.locator('[data-group="important"] span').textContent(), '1');
  assert.equal(await page.locator('[data-group="routine"] span').textContent(), '1');
  assert.equal(await page.locator('[data-group="active"] span').textContent(), '1');
  await page.locator('[data-group="important"]').click();
  await ready(page, 1);
  assert.match(await page.locator('.review-explanations').textContent(), /no occurrence has been confirmed/);
  assert.match(await page.locator('.review-card-meta').textContent(), /96%/);
  await page.locator('[data-group="active"]').click();
  await ready(page, 1);
  assert.match(await page.locator('#reviewGroupHelp').textContent(), /5 minutes of quiet/);
  assert.match(await page.locator('.review-media-warning').textContent(), /Still active/);
});

test('precise score, cutoff, and fallback recording are all explained', async t => {
  const {page, state} = await fixture(t, 1);
  Object.assign(state.visits[0], {best_confidence: .846, audio_fallback: true, playback_confidence: .80,
    reason_details: [{code: 'uncertain', text: 'Best match 84.6% is below the 85% review cutoff (band starts at 60%).'}]});
  await page.locator('#reviewRefresh').click();
  await ready(page, 1);
  assert.match(await page.locator('.review-card-meta').textContent(), /best 84\.6%/);
  assert.match(await page.locator('.review-explanations').textContent(), /84\.6%.*85%/);
  assert.match(await page.locator('.review-playback-note').textContent(), /80%.*84\.6%/);
});

test('Skip defers without marking reviewed, Resume and Undo both work', async t => {
  const {page, state} = await fixture(t, 1);
  await review(page, 'skip');
  await ready(page, 0);
  assert.equal(state.posts[0].action, 'skip');
  assert.equal(state.posts[0].status, undefined);
  assert.match(await page.locator('#reviewActionStatus').textContent(), /skipped for 24 hours.*Statistics unchanged/);
  assert.equal(await page.locator('#reviewProgress').textContent(), '0 reviewed this session');
  assert.doesNotMatch(await page.locator('#reviewQueue').textContent(), /All caught up/);
  await page.locator('#reviewUndo').click();
  await ready(page, 1);
  await review(page, 'skip');
  await ready(page, 0);
  await page.locator('[data-group="skipped"]').click();
  await ready(page, 1);
  await review(page, 'resume');
  await ready(page, 0);
  await page.locator('#reviewUndo').click();
  await ready(page, 1);
  assert.equal(state.visits[0].group, 'skipped');
  assert.match(await page.locator('#reviewActionStatus').textContent(), /previous review state restored/);
});

test('Undo survives a page reload and restores a verdict', async t => {
  const {page, state} = await fixture(t, 1);
  await review(page, 'false_positive');
  await ready(page, 0);
  await page.reload();
  await ready(page, 0);
  assert.ok(await page.locator('#reviewUndoPanel').isVisible());
  assert.match(await page.locator('#reviewUndoLabel').textContent(), /Test Bird 0.*marked not this bird/);
  await page.locator('#reviewUndo').click();
  await ready(page, 1);
  assert.equal(state.posts.at(-1).action, 'undo');
  assert.ok(await page.locator('#reviewUndoPanel').isHidden());
});

test('Undo conflict reports failure without losing its history or changing counts', async t => {
  const {page, state} = await fixture(t, 1);
  await review(page, 'confirmed');
  await ready(page, 0);
  state.postFailure = 409;
  await page.locator('#reviewUndo').click();
  await page.waitForFunction(() => document.getElementById('reviewLoadStatus').textContent.includes('409'));
  assert.equal(await page.locator('#reviewActionStatus').textContent(), '');
  assert.ok(await page.locator('#reviewUndoPanel').isVisible());
  assert.equal(state.visits.length, 0);
  state.postFailure = null;
  await page.locator('#reviewUndo').click();
  await ready(page, 1);
});

test('unavailable audio stays separate and cannot be blindly confirmed or rejected', async t => {
  const {page, state, context} = await fixture(t, 1);
  Object.assign(state.visits[0], {group: 'unavailable', audio_available: false, clip_path: null, reassign_available: false});
  await page.locator('#reviewRefresh').click();
  await ready(page, 0);
  assert.doesNotMatch(await page.locator('#reviewQueue').textContent(), /All caught up/);
  await page.locator('[data-group="unavailable"]').click();
  await ready(page, 1);
  for (const action of ['confirmed', 'false_positive', 'reassign']) assert.ok(await page.locator('.review-btn.' + action).isDisabled());
  await page.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown', {key: 'n'})));
  assert.equal(state.posts.length, 0);
  assert.ok(await page.locator('.review-btn.skip').isEnabled());
  const now = await context.newPage();
  await now.goto('http://review.test/?view=Now');
  await now.waitForFunction(() => document.getElementById('kpiDetections').textContent === '10');
  assert.equal(await now.locator('#heroReviewLink').textContent(), 'Review station detections →');
  assert.ok(await now.locator('#heroReviewLink').isVisible(), 'Other review views remain accessible with zero ready visits');
});

test('audio disappearing after loading disables listening-based decisions', async t => {
  const {page, state} = await fixture(t, 1);
  await page.locator('.review-card-media audio').evaluate(audio => audio.dispatchEvent(new Event('error')));
  assert.match(await page.locator('.review-media-warning').textContent(), /Refresh the queue/);
  for (const action of ['confirmed', 'false_positive', 'reassign']) assert.ok(await page.locator('.review-btn.' + action).isDisabled());
  await page.evaluate(() => document.dispatchEvent(new KeyboardEvent('keydown', {key: 'y'})));
  assert.equal(state.posts.length, 0);
});

for (const width of [1200, 390, 320]) {
  test('review workflow controls fit at ' + width + 'px', async t => {
    const {page} = await fixture(t, 1);
    await page.setViewportSize({width, height: 1000});
    for (const file of ['homepage/style.css', 'homepage/static/css/tokens.css', 'homepage/static/css/pages.css']) {
      await page.addStyleTag({path: path.join(root, file)});
    }
    assert.ok(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), 'No horizontal page overflow');
    for (const group of ['ready', 'important', 'routine', 'active', 'unavailable', 'skipped']) {
      const bounds = await page.locator('[data-group="' + group + '"]').boundingBox();
      assert.ok(bounds.x >= 0 && bounds.x + bounds.width <= width);
    }
    if (process.env.BIRDNET_WORKFLOW_SCREENSHOT && width === 1200) await page.screenshot({path: process.env.BIRDNET_WORKFLOW_SCREENSHOT, fullPage: true});
  });
}
test('more than 25 visits are replenished without a skipping offset', async t => {
  const {page, state} = await fixture(t, 26);
  assert.match(await page.locator('#reviewQueueMeta').textContent(), /showing 25/);
  await review(page, 'false_positive');
  await ready(page, 25);
  assert.equal(await page.locator('.review-card').count(), 25);
  assert.match(await page.locator('.review-card').last().textContent(), /Test Bird 25/);
  for (let n = 24; n >= 0; n--) { await review(page, 'false_positive'); await ready(page, n); }
  assert.equal(state.posts.length, 26);
  assert.equal(new Set(state.posts.map(p => p.visit.sci_name)).size, 26);
  assert.ok(state.queues.every(url => !url.searchParams.has('offset')));
});
for (const failure of [401, 503, 'network', 'false-ok', 'zero']) {
  test('save failure ' + failure + ' never marks a card done', async t => {
    const {page, state} = await fixture(t, 1);
    state.postFailure = failure;
    await review(page, 'false_positive');
    await page.locator('#reviewResult0 .review-error').waitFor();
    await ready(page, 1);
    assert.equal(await page.locator('.review-card.reviewed').count(), 0);
    assert.equal(await page.locator('#reviewActionStatus').textContent(), '');
    assert.equal(state.queues.length, 1);
    state.postFailure = null;
    await review(page, 'false_positive');
    await ready(page, 0);
  });
}
test('a failed refresh preserves saved feedback and offers a retry', async t => {
  const {page, state} = await fixture(t, 1);
  state.afterPost = () => { state.failQueue = true; };
  await review(page, 'confirmed');
  await page.waitForFunction(() => document.getElementById('reviewQueueMeta').textContent === 'Review count unavailable' && !document.getElementById('reviewRefresh').disabled);
  assert.match(await page.locator('#reviewActionStatus').textContent(), /^Saved/);
  assert.equal(await page.locator('.review-card.reviewed').count(), 1);
  assert.equal(await page.locator('.review-btn:not(:disabled)').count(), 0);
  assert.doesNotMatch(await page.locator('#reviewQueue').textContent(), /All caught up/);
  state.failQueue = false;
  await page.locator('#reviewRefresh').click();
  await ready(page, 0);
});
test('duplicate click and keyboard input cannot submit during a save', async t => {
  const {page, state} = await fixture(t, 1);
  let release;
  state.waitPost = new Promise(resolve => { release = resolve; });
  await review(page, 'confirmed');
  await page.waitForFunction(() => document.getElementById('reviewResult0').textContent === 'Saving…');
  await page.evaluate(() => {
    for (let i = 0; i < 5; i++) {
      document.querySelector('.review-btn.false_positive').click();
      document.dispatchEvent(new KeyboardEvent('keydown', {key: 'n'}));
      document.getElementById('reviewRefresh').click();
    }
  });
  assert.equal(await page.locator('.review-btn:not(:disabled)').count(), 0);
  release();
  await ready(page, 0);
  assert.equal(state.posts.length, 1);
});
test('fresh detections remain pending and manual refresh discovers new visits', async t => {
  const {page, state} = await fixture(t, 1);
  state.afterPost = () => { const v = visit(0); v.last_time = '12:02:00'; state.visits.push(v); };
  await review(page, 'false_positive');
  await page.waitForFunction(() => document.getElementById('reviewProgress').textContent === '1 reviewed this session' && !document.getElementById('reviewRefresh').disabled);
  await ready(page, 1);
  assert.equal(state.posts[0].visit.to_time, '12:01:00');
  assert.equal(await page.locator('.review-card.reviewed').count(), 0);
  state.visits.push(visit(1));
  await page.locator('#reviewRefresh').click();
  await ready(page, 2);
});
test('reassign success refreshes queue using the existing rename endpoint', async t => {
  const {page, state} = await fixture(t, 1);
  await review(page, 'reassign');
  await page.locator('#reassignList option').waitFor({state: 'attached'});
  await page.locator('#reassignList').selectOption({index: 0});
  await page.locator('#reassignGo').click();
  await ready(page, 0);
  assert.equal(state.renameCalls, 2);
  assert.equal(state.posts.length, 0);
  assert.match(await page.locator('#reviewActionStatus').textContent(), /Reassigned 2 detections to Correct Bird/);
});
test('partial reassign is not reported as a fully saved visit', async t => {
  const {page, state} = await fixture(t, 1);
  state.renameFailureAt = 2;
  await review(page, 'reassign');
  await page.locator('#reassignList option').waitFor({state: 'attached'});
  await page.locator('#reassignList').selectOption({index: 0});
  await page.locator('#reassignGo').click();
  await page.locator('#reassignStatus .review-error').waitFor();
  assert.match(await page.locator('#reassignStatus').textContent(), /1 renamed, 1 failed/);
  assert.equal(await page.locator('#reviewActionStatus').textContent(), '');
  assert.ok(await page.locator('#reassignGo').isDisabled());
  await page.locator('#reassignCancel').click();
  await ready(page, 1);
  assert.equal(state.queues.length, 2);
});
test('Now review link updates across tabs, survives back navigation, and distinguishes unavailable', async t => {
  const {page, context, state} = await fixture(t, 1);
  const now = await context.newPage();
  await now.goto('http://review.test/?view=Now');
  await now.waitForFunction(() => document.getElementById('kpiDetections').textContent === '10');
  assert.equal(await now.locator('#heroReviewLink').textContent(), 'Review station detections →');
  assert.ok(await now.locator('#heroReviewLink').isVisible());
  await review(page, 'false_positive');
  await ready(page, 0);
  await now.waitForFunction(() => document.getElementById('heroReviewLink').style.display === 'none');
  assert.equal(await now.locator('#stationReviewActions').count(), 0);
  assert.ok(state.nowCalls >= 2);
  state.nowUnknown = true;
  await now.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
  await now.waitForFunction(() => document.getElementById('heroReviewLink').title.startsWith('Review count unavailable'));
  assert.ok(await now.locator('#heroReviewLink').isVisible());
  assert.equal(await now.locator('#heroReviewLink').textContent(), 'Review station detections →');
  assert.equal(await now.locator('#reviewWorthyCount, #reviewVisitUnit, #reviewOtherCounts').count(), 0);
});
test('an older dashboard response cannot overwrite freshly updated review-link visibility', async t => {
  const {context, state} = await fixture(t, 2);
  let release;
  const held = new Promise(resolve => { release = resolve; });
  state.nowHook = n => n === 1 ? held : Promise.resolve();
  const now = await context.newPage();
  const initialRequest = now.waitForRequest('**/dashboard/now?*');
  await now.goto('http://review.test/?view=Now');
  await initialRequest;
  state.visits = [];
  await now.evaluate(() => window.dispatchEvent(new StorageEvent('storage', {key: 'birdnet-reviews-changed'})));
  await now.waitForFunction(() => document.getElementById('heroReviewLink').style.display === 'none' && document.getElementById('kpiDetections').textContent === '10');
  const oldResponse = now.waitForResponse('**/dashboard/now?*');
  release();
  await oldResponse;
  await now.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  assert.ok(await now.locator('#heroReviewLink').isHidden());
});

for (const width of [1440, 1024, 768, 390, 320]) {
  test('Review station detections sits beside About without counts or captions at ' + width + 'px', async t => {
    const {context, state} = await fixture(t, 165);
    state.latestVisit = {...visit(0), species: 'Common Grackle', sci_name: 'Quiscalus quiscula', seconds_ago: 2040, visits_last_7_days: 35};
    const now = await context.newPage();
    await now.setViewportSize({width, height: 1100});
    await now.goto('http://review.test/?view=Now');
    await now.waitForFunction(() => document.getElementById('kpiDetections').textContent === '10');
    for (const file of ['homepage/style.css', 'homepage/static/css/tokens.css', 'homepage/static/css/pages.css']) {
      await now.addStyleTag({path: path.join(root, file)});
    }
    await now.evaluate(() => document.fonts.ready);
    const layout = await now.evaluate(() => {
      const button = document.getElementById('heroReviewLink');
      const about = document.getElementById('heroDetailLink');
      const actions = document.querySelector('.hero-actions');
      const totals = document.querySelector('.now-kpis');
      const rect = button.getBoundingClientRect();
      const aboutRect = about.getBoundingClientRect();
      const bounds = actions.getBoundingClientRect();
      const hero = document.getElementById('nowHero');
      const heroHeight = hero.getBoundingClientRect().height;
      button.style.display = 'none';
      const heightWithoutButton = hero.getBoundingClientRect().height;
      button.style.display = '';
      return {
        inTotals: totals.contains(button), inHero: document.getElementById('nowHero').contains(button),
        followsAbout: button.previousElementSibling === about && button.parentElement === actions,
        onlyButtons: actions.children.length === 2 && Array.from(actions.children).every(el => el.matches('a.ui-button-link')),
        sameRow: Math.abs(rect.top - aboutRect.top) < 1,
        height: rect.height, aboutHeight: aboutRect.height, heroHeight, heightWithoutButton,
        fits: rect.left >= bounds.left && rect.right <= bounds.right,
        pageFits: document.documentElement.scrollWidth <= innerWidth + 1,
        text: button.textContent,
        href: button.getAttribute('href'),
      };
    });
    assert.ok(!layout.inTotals && layout.inHero && layout.followsAbout && layout.onlyButtons);
    assert.ok(layout.fits && layout.pageFits, JSON.stringify(layout));
    assert.ok(Math.abs(layout.height - layout.aboutHeight) < 1, 'Existing button height retained');
    if (width >= 1440) {
      assert.ok(layout.sameRow, 'Desktop actions stay on one row');
      assert.ok(Math.abs(layout.heroHeight - layout.heightWithoutButton) < 1, 'No extra desktop card height');
    }
    assert.equal(layout.text, 'Review station detections →');
    assert.equal(await now.locator('#stationReviewActions, .kpi-review-note, #reviewOtherCounts, #reviewWorthyCount, #reviewVisitUnit').count(), 0);
    assert.equal(layout.href, '?view=Review');
    if (process.env.BIRDNET_REVIEW_SCREENSHOT && width === 1440) {
      await now.locator('.now-main').screenshot({path: process.env.BIRDNET_REVIEW_SCREENSHOT});
    }
    if (process.env.BIRDNET_REVIEW_PREVIEW_DIR && [1440, 390].includes(width)) {
      await now.locator('.now-main').screenshot({path: path.join(process.env.BIRDNET_REVIEW_PREVIEW_DIR, `review-all-species-${width}.png`)});
    }
  });
}
