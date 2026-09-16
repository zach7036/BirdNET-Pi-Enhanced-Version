// Real maintenance markup and JS; EVERY browser request is intercepted.
// No PHP is executed. No updater, reboot, shutdown, restore, or clear runs.
// Run: BIRDNET_TEST_BROWSER=chrome node --test tests/test_system_controls_ui.js
const assert = require('node:assert/strict');
const fs = require('node:fs');
const path = require('node:path');
const test = require('node:test');
const {chromium} = require('playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const helpers = read('homepage/static/ui-helpers.js');
let browser;

test.before(async () => {
  browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined});
});
test.after(async () => { if (browser) await browser.close(); });

async function fixture(t, {shared = true, services = false} = {}) {
  const context = await browser.newContext({serviceWorkers: 'block'});
  const state = {requests: [], errors: [], blocked: []};
  t.after(async () => {
    await context.close();
    assert.deepEqual(state.errors, [], 'no uncaught browser errors');
    assert.deepEqual(state.blocked, [], 'no unexpected requests escaped the fixture');
  });
  await context.addInitScript(() => {
    // Restore uploads are not part of this test. Never instantiate an uploader.
    window.plupload = {Uploader: function () { this.init = function () {}; }};
  });
  if (shared) await context.addInitScript({content: helpers});
  let markup = read(services ? 'scripts/service_controls.php' : 'scripts/system_controls.php');
  for (const action of ['enable', 'disable']) {
    markup = markup.replace('<?php do_service_mount("' + action + '");?>',
      'value="sudo systemctl ' + action + ' test-recordings.mount &amp;&amp; sudo reboot"');
  }
  markup = '<!doctype html><html><head><meta charset="utf-8"><link rel="stylesheet" href="/style.css"></head><body>' +
    markup.replace(/<\?php[\s\S]*?\?>/g, '') + '</body></html>';
  await context.route('**/*', async route => {
    const req = route.request();
    const url = new URL(req.url());
    if (url.origin === 'https://maintenance.test') {
      if (url.pathname === '/') return route.fulfill({contentType: 'text/html', body: markup});
      if (url.pathname === '/style.css') return route.fulfill({contentType: 'text/css', body: read('homepage/style.css')});
      if (url.pathname === '/static/system-version.js') return route.fulfill({contentType: 'application/javascript', body: read('homepage/static/system-version.js')});
      if (url.pathname === '/static/RobotoFlex-Regular.ttf') return route.fulfill({contentType: 'font/ttf',
        body: fs.readFileSync(path.join(root, 'homepage/static/RobotoFlex-Regular.ttf'))});
      if (url.pathname === '/views.php' || url.pathname === '/scripts/backup.php') {
        state.requests.push({url, method: req.method()});
        if (url.pathname === '/scripts/backup.php') return route.fulfill({
          contentType: 'application/octet-stream',
          headers: {'Content-Disposition': 'attachment; filename="mock-backup.txt"'},
          body: 'Mock download only; no station data.'
        });
        // A 204 keeps the page alive so busy/error/retry states can be checked.
        // This is only a recorded request: there is NO backend or shell here.
        return route.fulfill({status: 204});
      }
    }
    state.blocked.push(req.url());
    return route.abort();
  });
  const page = await context.newPage();
  page.on('pageerror', error => state.errors.push(error.message));
  await page.goto('https://maintenance.test/');
  // Chromium's native download attribute can bypass Playwright routing. Keep
  // the shipped onclick/href but use a routed navigation to the mock download.
  await page.locator('a[download]').evaluateAll(links => links.forEach(link => link.removeAttribute('download')));
  return {page, state, shared};
}

const actions = [
  {name: 'Update', selector: '#updatebtn', command: 'update_birdnet.sh'},
  {name: 'Reboot', selector: 'button[value="sudo reboot"]', command: 'sudo reboot'},
  {name: 'Shutdown', selector: 'button[value="sudo shutdown now"]', command: 'sudo shutdown now'},
  {name: 'Clear', selector: 'button[value="sudo clear_all_data.sh"]', command: 'sudo clear_all_data.sh'}
];

async function decide(f, selector, accept) {
  if (!f.shared) f.page.once('dialog', dialog => accept ? dialog.accept() : dialog.dismiss());
  await f.page.locator(selector).click();
  if (f.shared) await f.page.locator('dialog button[value="' + (accept ? 'confirm' : 'cancel') + '"]').click();
}

async function submit(f, selector) {
  const response = f.page.waitForResponse(r => new URL(r.url()).pathname === '/views.php');
  await decide(f, selector, true);
  await response;
}

function assertCommand(f, command, count = 1) {
  assert.equal(f.state.requests.length, count);
  const request = f.state.requests.at(-1);
  assert.equal(request.method, 'GET');
  assert.equal(request.url.pathname, '/views.php');
  assert.deepEqual([...request.url.searchParams], [['submit', command]], 'exactly the confirmed command, with no stale values');
}

async function assertReset(page) {
  await page.waitForFunction(() => !systemCommandPending);
  assert.equal(await page.evaluate(() => systemUpdateTimer), null);
  assert.equal(await page.evaluate(() => systemUpdateMarkup), null);
  assert.equal(await page.locator('#updatebtn #timer').count(), 0);
  assert.match(await page.locator('#updatebtn').innerText(), /^Update\s*$/);
  assert.equal(await page.locator('input[type="hidden"][name="submit"]').count(), 0);
}

for (const shared of [true, false]) {
  const mode = shared ? 'shared dialog' : 'native fallback';
  for (const action of actions) {
    test(mode + ': ' + action.name + ' submits only the confirmed command', async t => {
      const f = await fixture(t, {shared});
      assert.equal(await f.page.locator('#systemCommandError').isVisible(), false);
      assert.equal(await f.page.locator('form[action="views.php"]').evaluate(form => typeof form.submit), 'object',
        'the shipped controls really do mask form.submit');
      await submit(f, action.selector);
      assertCommand(f, action.command);
      assert.equal(await f.page.locator('form[action="views.php"] button:enabled').count(), 0);
      assert.equal(await f.page.locator('input[name="submit"]').count(), 0);
      if (action.name === 'Update') assert.match(await f.page.locator('#updatebtn').innerText(), /^Update requested:/);
    });

    test(mode + ': cancelling ' + action.name + ' sends nothing and allows another action', async t => {
      const f = await fixture(t, {shared});
      await decide(f, action.selector, false);
      await assertReset(f.page);
      assert.equal(f.state.requests.length, 0);
      assert.equal(await f.page.locator('form[action="views.php"] button:disabled').count(), 0);
      await submit(f, actions[1].selector);
      assertCommand(f, 'sudo reboot');
    });
  }

  test(mode + ': submission failure stops the timer, reports an error, and permits a clean retry', async t => {
    const f = await fixture(t, {shared});
    await f.page.evaluate(() => {
      window.originalSubmit = HTMLFormElement.prototype.submit;
      HTMLFormElement.prototype.submit = function () { throw new Error('Injected submission failure'); };
    });
    await decide(f, '#updatebtn', true);
    await assertReset(f.page);
    assert.equal(f.state.requests.length, 0);
    assert.equal(await f.page.locator('#systemCommandError').isVisible(), true);
    assert.match(await f.page.locator('#systemCommandError').textContent(), /Could not submit this action/);
    assert.equal(await f.page.locator('form[action="views.php"] button:disabled').count(), 0);
    await f.page.evaluate(() => { HTMLFormElement.prototype.submit = window.originalSubmit; });
    await submit(f, actions[1].selector);
    assertCommand(f, 'sudo reboot');
    assert.equal(await f.page.locator('#systemCommandError').isVisible(), false);
  });

  test(mode + ': Backup keeps its separate link flow', async t => {
    const f = await fixture(t, {shared});
    const selector = 'a[href="scripts/backup.php"]';
    await decide(f, selector, false);
    assert.equal(f.state.requests.length, 0);
    const download = f.page.waitForEvent('download');
    await decide(f, selector, true);
    assert.equal((await download).suggestedFilename(), 'mock-backup.txt');
    assert.equal(f.state.requests.length, 1);
    assert.equal(f.state.requests[0].url.pathname, '/scripts/backup.php');
    assert.equal(f.state.requests[0].url.search, '');
    await assertReset(f.page);
  });

  for (const action of ['enable', 'disable']) {
    test(mode + ': RAM-drive ' + action + ' cancels or submits exactly one command', async t => {
      const f = await fixture(t, {shared, services: true});
      const selector = 'button[value^="sudo systemctl ' + action + ' test-recordings.mount"]';
      await decide(f, selector, false);
      if (shared) await f.page.waitForFunction(() => !document.querySelector('form[action="views.php"]').dataset.uiConfirmPending);
      assert.equal(f.state.requests.length, 0);
      await submit(f, selector);
      assertCommand(f, 'sudo systemctl ' + action + ' test-recordings.mount && sudo reboot');
      assert.equal(await f.page.locator('input[name="submit"]').count(), 0);
    });
  }
}

test('repeated clicks cannot open multiple dialogs or send duplicate commands; Back restores controls', async t => {
  const f = await fixture(t);
  await f.page.evaluate(() => {
    document.querySelector('button[value="sudo shutdown now"]').disabled = true;
    for (let i = 0; i < 3; i++) document.getElementById('updatebtn').click();
  });
  assert.equal(await f.page.locator('dialog').count(), 1);
  const response = f.page.waitForResponse(r => new URL(r.url()).pathname === '/views.php');
  await f.page.locator('dialog button[value="confirm"]').click();
  await response;
  await f.page.locator('#updatebtn').evaluate(button => button.click());
  assertCommand(f, 'update_birdnet.sh');
  assert.equal(await f.page.locator('dialog').count(), 0);
  await f.page.waitForFunction(() => document.querySelector('#updatebtn #timer').textContent !== '00:00');
  await f.page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
  await assertReset(f.page);
  assert.equal(await f.page.locator(actions[2].selector).isDisabled(), true, 'previously disabled stays disabled');
  assert.equal(await f.page.locator('#updatebtn').isDisabled(), false);
  await submit(f, actions[1].selector);
  assertCommand(f, 'sudo reboot', 2);
});

test('an older cached helper without submitButton still submits safely', async t => {
  const f = await fixture(t);
  await f.page.evaluate(() => { delete BirdNETUI.submitButton; });
  await submit(f, '#updatebtn');
  assertCommand(f, 'update_birdnet.sh');
});

for (const failure of ['throw', 'reject']) {
  test('confirmation ' + failure + ' reports failure without starting a command or timer', async t => {
    const f = await fixture(t);
    await f.page.evaluate(failure => {
      BirdNETUI.confirmAction = function () {
        if (failure === 'throw') throw new Error('Injected dialog failure');
        return Promise.reject(new Error('Injected dialog failure'));
      };
    }, failure);
    await f.page.locator('#updatebtn').click();
    await assertReset(f.page);
    assert.equal(await f.page.locator('#systemCommandError').isVisible(), true);
    assert.equal(f.state.requests.length, 0);
  });
}

test('shared Services helper reports submission failure, removes stale values and guards repeat clicks', async t => {
  const f = await fixture(t, {services: true});
  const selector = 'button[value^="sudo systemctl enable test-recordings.mount"]';
  await f.page.evaluate(() => {
    window.originalSubmit = HTMLFormElement.prototype.submit;
    HTMLFormElement.prototype.submit = function () { throw new Error('Injected submission failure'); };
  });
  await decide(f, selector, true);
  await f.page.locator('[data-ui-submit-error]').waitFor({state: 'visible'});
  assert.equal(f.state.requests.length, 0);
  assert.equal(await f.page.locator('input[name="submit"]').count(), 0);
  await f.page.evaluate(() => { HTMLFormElement.prototype.submit = window.originalSubmit; });
  await f.page.locator(selector).evaluate(button => { button.click(); button.click(); });
  assert.equal(await f.page.locator('[data-ui-submit-error]').count(), 0);
  assert.equal(await f.page.locator('dialog').count(), 1);
  const response = f.page.waitForResponse(r => new URL(r.url()).pathname === '/views.php');
  await f.page.locator('dialog button[value="confirm"]').click();
  await response;
  await f.page.locator(selector).evaluate(button => button.click());
  assertCommand(f, 'sudo systemctl enable test-recordings.mount && sudo reboot');
  assert.equal(await f.page.locator('dialog').count(), 0);
  await f.page.evaluate(() => window.dispatchEvent(new PageTransitionEvent('pageshow', {persisted: true})));
  await submit(f, 'button[value^="sudo systemctl disable test-recordings.mount"]');
  assertCommand(f, 'sudo systemctl disable test-recordings.mount && sudo reboot', 2);
});

test('ordinary service buttons still use their existing native submission', async t => {
  const f = await fixture(t, {services: true});
  const response = f.page.waitForResponse(r => new URL(r.url()).pathname === '/views.php');
  await f.page.locator('button[value="restart_services.sh"]').click();
  await response;
  assertCommand(f, 'restart_services.sh');
  assert.equal(await f.page.locator('dialog').count(), 0);
});
