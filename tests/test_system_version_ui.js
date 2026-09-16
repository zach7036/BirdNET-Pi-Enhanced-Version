// Actual PHP-rendered version panel, shipped JS/CSS, mocked clipboard/network.
// Requires Playwright and PHP CLI (BIRDNET_TEST_PHP can select the executable).
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const os = require('node:os');
const {execFileSync} = require('node:child_process');
const test = require('node:test');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const php = process.env.BIRDNET_TEST_PHP || 'php';
const baseInfo = {version: 'v2.8.1', branch: 'main', build: '12345678'.repeat(5), details: []};
let browser;
test.before(async () => { browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined}); });
test.after(async () => { if (browser) await browser.close(); });

async function fixture(t, {info = baseInfo, clipboard = 'success', legacy = false, scheme = 'https', width = 1100, dark = false} = {}) {
  const panel = execFileSync(php, ['-r', 'require $argv[1]; echo render_system_version(json_decode(stream_get_contents(STDIN), true));',
    path.join(root, 'scripts/version_info.php')], {encoding: 'utf8', input: JSON.stringify(info)});
  const context = await browser.newContext({viewport: {width, height: 800}, serviceWorkers: 'block'});
  const unexpected = [], errors = [];
  t.after(async () => {
    await context.close();
    assert.deepEqual(unexpected, [], 'no maintenance or unexpected requests');
    assert.deepEqual(errors, [], 'no browser errors');
  });
  await context.addInitScript(({clipboard, legacy}) => {
    window.copyAttempts = [];
    window.legacyCopies = [];
    Object.defineProperty(navigator, 'clipboard', {configurable: true, value: clipboard === 'missing' ? undefined : {
      writeText: async text => {
        window.copyAttempts.push(text);
        if (clipboard === 'denied') throw new Error('Clipboard permission denied');
        if (clipboard === 'pending') await new Promise(resolve => { window.finishVersionCopy = resolve; });
      }
    }});
    document.execCommand = command => {
      if (command === 'copy' && legacy) {
        window.legacyCopies.push(document.getElementById('versionCopyText').value);
        return true;
      }
      return false;
    };
  }, {clipboard, legacy});
  const origin = scheme + '://version.test';
  await context.route('**/*', route => {
    const url = new URL(route.request().url());
    if (url.origin === origin) {
      if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: '<!doctype html><html' + (dark ? ' data-theme="dark"' : '') +
        '><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1"><link rel="stylesheet" href="/style.css">' +
        '<link rel="stylesheet" href="/static/css/tokens.css"><link rel="stylesheet" href="/static/css/pages.css">' +
        '<script src="/static/system-version.js" defer></script></head><body><div class="systemcontrols">' + panel + '</div></body></html>'});
      if (url.pathname === '/style.css') return route.fulfill({contentType: 'text/css', body: fs.readFileSync(path.join(root, 'homepage/style.css'))});
      if (['/static/css/tokens.css', '/static/css/pages.css'].includes(url.pathname)) return route.fulfill({contentType: 'text/css', body: fs.readFileSync(path.join(root, 'homepage', url.pathname))});
      if (url.pathname === '/static/system-version.js') return route.fulfill({contentType: 'application/javascript', body: fs.readFileSync(path.join(root, 'homepage/static/system-version.js'))});
      if (url.pathname === '/static/RobotoFlex-Regular.ttf') return route.fulfill({contentType: 'font/ttf', body: fs.readFileSync(path.join(root, 'homepage/static/RobotoFlex-Regular.ttf'))});
    }
    unexpected.push(url.href);
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => errors.push(error.message));
  await page.goto(origin);
  return page;
}

async function copied(page) {
  await page.waitForFunction(() => document.getElementById('versionCopyStatus').textContent === 'Version info copied.');
  assert.equal(await page.locator('#copyVersionInfo').isDisabled(), false);
}

test('readable release, branch, short linked build; copy contains full hash only', async t => {
  const page = await fixture(t);
  assert.deepEqual(await page.locator('dd').allTextContents().then(values => values.map(v => v.trim())), ['v2.8.1', 'main', '1234567']);
  assert.equal(await page.locator('.system-version-fields a').getAttribute('href'), 'https://github.com/zach7036/BirdNET-Pi-Enhanced-Version/commit/' + baseInfo.build);
  assert.equal(await page.locator('#copyVersionInfo').getAttribute('type'), 'button');
  assert.equal(await page.locator('#versionCopyText').isVisible(), false);
  assert.equal(await page.locator('dd').first().evaluate(el => getComputedStyle(el, '::before').content), 'none');
  if (process.env.BIRDNET_VERSION_SCREENSHOTS) await page.screenshot({path: path.join(os.tmpdir(), 'birdnet-version-release.png')});
  await page.locator('#copyVersionInfo').click();
  await copied(page);
  assert.deepEqual(await page.evaluate(() => copyAttempts), ['BirdNET-Pi\nVersion: v2.8.1\nBranch: main\nBuild: ' + baseInfo.build]);
  assert.equal(await page.evaluate(() => document.activeElement.id), 'copyVersionInfo');
});

for (const mode of ['missing', 'denied']) {
  test(mode + ' Clipboard API falls back to copying on plain HTTP', async t => {
    const page = await fixture(t, {clipboard: mode, legacy: true, scheme: 'http'});
    await page.locator('#copyVersionInfo').click();
    await copied(page);
    assert.equal((await page.evaluate(() => legacyCopies)).length, 1);
    assert.equal(await page.locator('#versionCopyText').isVisible(), false);
  });

  test(mode + ' Clipboard API and blocked legacy copy provide selected manual text', async t => {
    const page = await fixture(t, {clipboard: mode, scheme: 'http'});
    await page.locator('#copyVersionInfo').click();
    await page.locator('#versionCopyText').waitFor({state: 'visible'});
    assert.match(await page.locator('#versionCopyStatus').innerText(), /Copy the selected text below/);
    assert.equal(await page.evaluate(() => document.activeElement.id), 'versionCopyText');
    assert.equal(await page.locator('#versionCopyText').evaluate(el => el.selectionEnd - el.selectionStart), (await page.locator('#versionCopyText').inputValue()).length);
    assert.equal(await page.locator('#copyVersionInfo').isDisabled(), false);
  });
}

test('pending clipboard operation prevents repeated copies and restores button', async t => {
  const page = await fixture(t, {clipboard: 'pending'});
  await page.locator('#copyVersionInfo').click();
  assert.equal(await page.locator('#copyVersionInfo').isDisabled(), true);
  await page.locator('#copyVersionInfo').evaluate(button => button.click());
  assert.equal((await page.evaluate(() => copyAttempts)).length, 1);
  await page.evaluate(() => finishVersionCopy());
  await copied(page);
});

test('manual disclosure is keyboard accessible and unknown metadata has no broken link', async t => {
  const page = await fixture(t, {info: {version: 'Release unavailable', branch: 'Unavailable', build: '', details: ['Local Git information could not be read.']}});
  assert.equal(await page.locator('.system-version a').count(), 0);
  await page.locator('#versionCopyManual summary').focus();
  await page.keyboard.press('Enter');
  assert.equal(await page.locator('#versionCopyText').isVisible(), true);
  assert.match(await page.locator('#versionCopyText').inputValue(), /Build: Unavailable/);
});

test('unreleased, modified and unusual branch text stays readable and escaped', async t => {
  const page = await fixture(t, {info: {...baseInfo, version: 'Development build', branch: 'feature/<script>alert(1)</script>',
    details: ['1 commit after v2.8.1', 'Local tracked-file changes']}});
  assert.equal(await page.locator('.system-version script').count(), 0);
  assert.match(await page.locator('.system-version-details').innerText(), /Local tracked-file changes/);
  await page.locator('#copyVersionInfo').click();
  await copied(page);
  assert.match((await page.evaluate(() => copyAttempts))[0], /Version: Development build/);
});

for (const width of [1100, 390, 320]) {
  for (const dark of [false, true]) {
    test((dark ? 'dark' : 'light') + ' panel and manual copy fit at ' + width + 'px', async t => {
      const page = await fixture(t, {width, dark, clipboard: 'missing', info: {...baseInfo, version: 'Development build',
        branch: 'development/very-long-branch-name-for-testing-layout', details: ['1 commit after v2.8.1', 'Local tracked-file changes']}});
      await page.locator('#copyVersionInfo').click();
      const fits = await page.evaluate(() => document.documentElement.scrollWidth <= innerWidth);
      assert.equal(fits, true);
      const box = await page.locator('#versionCopyText').boundingBox();
      assert.ok(box.width <= width && box.x >= 0);
      if (process.env.BIRDNET_VERSION_SCREENSHOTS) {
        const file = path.join(os.tmpdir(), 'birdnet-version-' + width + '-' + (dark ? 'dark' : 'light') + '.png');
        await page.screenshot({path: file});
        console.log('Preview: ' + file);
      }
    });
  }
}
