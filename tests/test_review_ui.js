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
    member_clips: ['bird' + i + 'a.wav', 'bird' + i + 'b.wav'], reasons: ['uncertain']};
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
    afterPost: null, renameFailureAt: null, nowHook: null, nowUnknown: false};
  await context.route('**/*', async route => {
    const req = route.request();
    const url = new URL(req.url());
    const json = (value, status = 200) => route.fulfill({status, contentType: 'application/json', body: JSON.stringify(value)});
    if (req.isNavigationRequest()) return route.fulfill({contentType: 'text/html', body: markup(url.searchParams.get('view') === 'Now' ? 'scripts/now.php' : 'scripts/review.php')});
    if (url.pathname.endsWith('/reviews/queue')) {
      state.queues.push(url);
      if (state.failQueue) return json({status: 'error'}, 503);
      return json({queue: state.visits.slice(0, 25), total: state.visits.length, days: 7, suggestions: []});
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
      state.visits = state.visits.filter(v => v.sci_name !== body.visit.sci_name);
      if (state.afterPost) state.afterPost();
      return json({status: 'ok', affected: 2, review_status: body.status});
    }
    if (url.pathname.endsWith('/dashboard/now')) {
      state.nowCalls++;
      const data = {latest_visit: null, today: {detections: 10, species: 1, visits: 2, new_species: 0},
        review_worthy: state.nowUnknown ? null : state.visits.length, story: []};
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

for (const action of ['false_positive', 'confirmed', 'unsure', 'hidden']) {
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
test('Now badge updates across tabs, survives back navigation, and distinguishes unavailable', async t => {
  const {page, context, state} = await fixture(t, 1);
  const now = await context.newPage();
  await now.goto('http://review.test/?view=Now');
  await now.waitForFunction(() => document.getElementById('reviewWorthyCount').textContent === '1');
  assert.match(await now.locator('#heroReviewLink').textContent(), /Review 1 visit /);
  await review(page, 'false_positive');
  await ready(page, 0);
  await now.waitForFunction(() => document.getElementById('heroReviewLink').style.display === 'none');
  assert.ok(state.nowCalls >= 2);
  state.nowUnknown = true;
  await now.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
  await now.waitForFunction(() => document.getElementById('heroReviewLink').title.startsWith('Review count unavailable'));
  assert.ok(await now.locator('#heroReviewLink').isVisible());
  assert.equal(await now.locator('#reviewWorthyCount').textContent(), '');
});
test('an older dashboard response cannot overwrite a freshly updated count', async t => {
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
  await now.waitForFunction(() => document.getElementById('reviewWorthyCount').textContent === '0' && document.getElementById('kpiDetections').textContent === '10');
  const oldResponse = now.waitForResponse('**/dashboard/now?*');
  release();
  await oldResponse;
  await now.evaluate(() => new Promise(resolve => requestAnimationFrame(() => requestAnimationFrame(resolve))));
  assert.equal(await now.locator('#reviewWorthyCount').textContent(), '0');
});
