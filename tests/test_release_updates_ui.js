// Real PHP-rendered release panel + maintenance markup, with every request intercepted.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const test = require('node:test');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const available = {status: 'available', release: {version: 'v2.8.3', url: 'javascript:bad'}, checked_at: 1789570000, error: null};
const current = {...available, status: 'current'};
let browser;
test.before(async () => { browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined}); });
test.after(async () => { if (browser) await browser.close(); });

async function fixture(t, {replies = [available], silenced = false, width = 1100, dark = false, timeout = false} = {}) {
  const context = await browser.newContext({viewport: {width, height: 1000}, serviceWorkers: 'block'});
  const state = {requests: [], unexpected: [], errors: [], deliver: null};
  t.after(async () => {
    await context.close();
    assert.deepEqual(state.unexpected, [], 'no updater, maintenance, or external requests');
    assert.deepEqual(state.errors, [], 'no browser errors');
  });
  await context.addInitScript(({timeout}) => {
    window.plupload = {Uploader: function () { this.init = function () {}; }};
    const original = window.setTimeout;
    window.setTimeout = (fn, delay, ...args) => {
      if (delay === 86400000) window.runDailyReleaseCheck = fn;
      return original(fn, timeout && delay === 20000 ? 100 : delay, ...args);
    };
  }, {timeout});
  const panel = execFileSync(process.env.BIRDNET_TEST_PHP || 'php', ['-r',
    'require $argv[1]; echo render_release_update_panel(); echo render_system_version(["version"=>"v2.8.2", "branch"=>"main", "build"=>str_repeat("a",40), "details"=>[]]);',
    path.join(root, 'scripts/release_updates.php')], {encoding: 'utf8'});
  const badge = read('homepage/views.php').match(/<span class="updatenumber release-update-badge"[^>]*>1<\/span>/)[0];
  const markup = read('scripts/system_controls.php').replace(/<\?php[\s\S]*?\?>/g,
    php => php.includes('render_release_update_panel()') ? panel : '');
  await context.route('**/*', async route => {
    const url = new URL(route.request().url());
    if (url.origin === 'https://updates.test') {
      if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: '<!doctype html><html' + (dark ? ' data-theme="dark"' : '') + '><head>' +
        '<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1"><link rel="stylesheet" href="/style.css">' +
        '<link rel="stylesheet" href="/static/css/tokens.css"><link rel="stylesheet" href="/static/css/pages.css">' +
        '<script id="releaseUpdatesScript" src="/static/release-updates.js" data-silenced="' + (silenced ? '1' : '0') + '" defer></script></head><body>' +
        '<nav class="sidebar-nav"><a href="/?view=Tools"><span>Settings</span> ' + badge + '</a><a href="/?view=System%20Controls"><span>System Controls</span> ' + badge + '</a></nav>' + markup + '</body></html>'});
      if (url.pathname === '/api/v1/system/updates') {
        state.requests.push({method: route.request().method(), headers: route.request().headers(), body: route.request().postData()});
        const next = replies[Math.min(state.requests.length - 1, replies.length - 1)];
        if (next === 'hold') { state.deliver = () => route.fulfill({json: current}); return; }
        if (next === 'abort') return route.abort();
        if (typeof next === 'number') return route.fulfill({status: next, json: {message: 'test failure'}});
        return route.fulfill({json: next});
      }
      const assets = {'/style.css': 'text/css', '/static/css/tokens.css': 'text/css', '/static/css/pages.css': 'text/css',
        '/static/release-updates.js': 'application/javascript', '/static/system-version.js': 'application/javascript',
        '/static/RobotoFlex-Regular.ttf': 'font/ttf'};
      if (assets[url.pathname]) return route.fulfill({contentType: assets[url.pathname], body: fs.readFileSync(path.join(root, 'homepage', url.pathname))});
    }
    state.unexpected.push(url.href);
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => state.errors.push(error.message));
  await page.goto('https://updates.test/');
  return {page, state};
}

async function settled(page) {
  await page.waitForFunction(() => !document.getElementById('checkReleaseUpdates').disabled);
}

test('homepage and Update badges consistently mean one available release', async t => {
  const {page, state} = await fixture(t);
  await settled(page);
  assert.equal(await page.locator('.release-update-badge:visible').count(), 3);
  assert.deepEqual(await page.locator('.release-update-badge').allTextContents(), ['1', '1', '1']);
  assert.equal(await page.locator('#releaseUpdateStatus').innerText(), 'v2.8.3 is available.');
  assert.equal(await page.locator('#releaseUpdateNotes').getAttribute('href'), 'https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/releases/tag/v2.8.3');
  assert.equal(state.requests[0].method, 'GET');
  assert.equal(state.requests[0].headers['x-requested-with'], 'XMLHttpRequest');
});

test('manual check cannot submit a maintenance command and removes stale badges', async t => {
  const {page, state} = await fixture(t, {replies: [available, current]});
  await settled(page);
  await page.locator('#checkReleaseUpdates').click();
  await page.waitForFunction(() => document.getElementById('releaseUpdateStatus').textContent.includes('already includes'));
  assert.equal(await page.locator('.release-update-badge:visible').count(), 0);
  assert.equal(state.requests[1].method, 'POST');
  assert.equal(state.requests[1].body, null);
  assert.equal(await page.locator('#checkReleaseUpdates').evaluate(button => button.form), null);
  assert.equal(await page.evaluate(() => systemUpdateTimer), null);
});

test('mute hides every badge without hiding release information', async t => {
  const {page} = await fixture(t, {silenced: true});
  await settled(page);
  assert.equal(await page.locator('.release-update-badge:visible').count(), 0);
  assert.match(await page.locator('#releaseUpdateStatus').innerText(), /v2.8.3 is available/);
});

test('a collapsed sidebar still shows the quiet update badge', async t => {
  const {page} = await fixture(t);
  await settled(page);
  await page.evaluate(() => {
    const sidebar = document.createElement('aside'); sidebar.className = 'sidebar collapsed';
    const nav = document.querySelector('.sidebar-nav'); nav.before(sidebar); sidebar.append(nav);
  });
  assert.equal(await page.locator('.sidebar .release-update-badge:visible').count(), 2);
  assert.equal(await page.locator('.sidebar-nav a > span:not(.release-update-badge):visible').count(), 0);
});

test('an open homepage checks again on its daily timer', async t => {
  const {page, state} = await fixture(t, {replies: [current, available]});
  await settled(page);
  await page.evaluate(() => window.runDailyReleaseCheck());
  await page.waitForFunction(() => document.getElementById('releaseUpdateStatus').textContent.includes('is available'));
  assert.deepEqual(state.requests.map(r => r.method), ['GET', 'GET']);
});

for (const status of ['current', 'unknown', 'diverged']) {
  test(status + ' is not an available-update notification', async t => {
    const {page} = await fixture(t, {replies: [{...available, status}]});
    await settled(page);
    assert.equal(await page.locator('.release-update-badge:visible').count(), 0);
  });
}

test('network failure preserves previously confirmed badge and restores check button', async t => {
  const {page} = await fixture(t, {replies: [available, 'abort']});
  await settled(page);
  await page.locator('#checkReleaseUpdates').click();
  await page.waitForFunction(() => document.getElementById('releaseUpdateStatus').textContent.includes('Could not check'));
  await settled(page);
  assert.equal(await page.locator('.release-update-badge:visible').count(), 3);
});

test('stale backend result is explicitly labeled', async t => {
  const {page} = await fixture(t, {replies: [{...available, error: 'offline'}]});
  await settled(page);
  assert.match(await page.locator('#releaseUpdateStatus').innerText(), /last successful result/);
});

for (const code of [401, 403]) {
  test('manual check handles ' + code + ' without a misleading success', async t => {
    const {page} = await fixture(t, {replies: [current, code]});
    await settled(page);
    await page.locator('#checkReleaseUpdates').click();
    await page.waitForFunction(() => document.getElementById('releaseUpdateStatus').textContent.includes('Sign in'));
    await settled(page);
  });
}

test('pending check is nonblocking and guards duplicate requests', async t => {
  const {page, state} = await fixture(t, {replies: [current, 'hold']});
  await settled(page);
  await page.locator('#checkReleaseUpdates').click();
  await page.waitForFunction(() => document.getElementById('checkReleaseUpdates').disabled);
  await page.locator('#checkReleaseUpdates').dispatchEvent('click');
  assert.equal(await page.locator('#updatebtn').isEnabled(), true);
  while (!state.deliver) await new Promise(resolve => setTimeout(resolve, 5));
  await state.deliver();
  await settled(page);
  assert.equal(state.requests.length, 2);
});

test('browser timeout ends checking without starting an update', async t => {
  const {page} = await fixture(t, {replies: ['hold'], timeout: true});
  await page.waitForFunction(() => document.getElementById('releaseUpdateStatus').textContent.includes('Could not check'));
  await settled(page);
  assert.equal(await page.evaluate(() => systemUpdateTimer), null);
});

test('remote tag text cannot inject markup or a link', async t => {
  const {page} = await fixture(t, {replies: [{...available, release: {version: '<img src=x onerror=alert(1)>'}}]});
  await settled(page);
  assert.equal(await page.locator('.release-update-badge:visible').count(), 0);
  assert.equal(await page.locator('#releaseUpdateNotes').getAttribute('href'), 'https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/releases');
  assert.equal(await page.locator('img').count(), 0);
});

for (const width of [1100, 390, 320]) for (const dark of [false, true]) {
  test('release information fits ' + width + 'px in ' + (dark ? 'dark' : 'light') + ' mode', async t => {
    const {page} = await fixture(t, {width, dark});
    await settled(page);
    assert.equal(await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth), true);
    if (process.env.BIRDNET_RELEASE_SCREENSHOTS) {
      await page.screenshot({path: path.join(os.tmpdir(), 'birdnet-release-' + width + '-' + (dark ? 'dark' : 'light') + '.png'), fullPage: true});
    }
  });
}
