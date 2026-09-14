// Browser regression checks using the shipped renderer/CSS and synthetic data.
// Requires Playwright with Chromium, or BIRDNET_TEST_BROWSER=chrome (or msedge)
// to use an installed browser. Run: node --test tests/test_heatmap_layout.js
// No PHP server, station database, or live network connection is used.
const assert = require('node:assert/strict');
const path = require('node:path');
const test = require('node:test');
const {chromium} = require('playwright');

const root = path.resolve(__dirname, '..');
let browser;
test.before(async () => {
    browser = await chromium.launch({headless: true, channel: process.env.BIRDNET_TEST_BROWSER || undefined});
});
test.after(async () => { if (browser) await browser.close(); });

async function fixture(t, {width = 1100, dpr = 1, weather = true, embed = true, observer = true} = {}) {
    const context = await browser.newContext({viewport: {width: 1440, height: 1000}, deviceScaleFactor: dpr});
    t.after(() => context.close());
    const page = await context.newPage();
    const errors = [];
    page.on('pageerror', error => errors.push(error.message));
    page.on('console', message => {
        if (message.type() === 'error' && message.text().startsWith('Dashboard charts:')) errors.push(message.text());
    });
    t.after(() => assert.deepEqual(errors, [], 'no script or ResizeObserver-loop errors'));
    // A mocked origin allows us to check species-link navigation as well.
    await page.route('**/*', route => route.request().isNavigationRequest()
        ? route.fulfill({contentType: 'text/html', body: '<!doctype html><html><body></body></html>'})
        : route.abort());
    await page.goto('http://heatmap.test/');
    await page.setContent('<div id="testHost" style="width:' + width + 'px">' +
        '<div id="todaySpeciesContainer" style="max-width:100%" class="' + (embed ? 'heatmap-embed' : 'chart-container') + '">' +
        '<div id="heatmapError"></div><div class="chart-canvas-wrapper ui-chart-scroll" style="max-width:100%">' +
        '<canvas id="hourlyHeatmap"></canvas></div></div></div>');
    for (const file of ['homepage/style.css', 'homepage/static/css/tokens.css', 'homepage/static/css/pages.css']) {
        await page.addStyleTag({path: path.join(root, file)});
    }
    await page.evaluate(({weather, observer}) => {
        if (!observer) window.ResizeObserver = undefined;
        window.requestCount = 0;
        window.drawCount = 0;
        window.drawnText = [];
        const clearRect = CanvasRenderingContext2D.prototype.clearRect;
        const fillText = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.clearRect = function (...args) {
            window.drawCount++;
            window.drawnText = [];
            return clearRect.apply(this, args);
        };
        CanvasRenderingContext2D.prototype.fillText = function (text, ...args) {
            window.drawnText.push(String(text));
            return fillText.call(this, text, ...args);
        };
        const species = [
            {name: 'Northern Cardinal', sciName: 'Cardinalis cardinalis', image: ''},
            {name: 'Black-and-white-casqued Hornbill', sciName: 'Bycanistes subcylindricus', image: ''},
            {name: 'Blue Jay', sciName: 'Cyanocitta cristata', image: ''}
        ];
        const hourly = {};
        const conditions = {};
        for (const [row, bird] of species.entries()) {
            hourly[bird.name] = {};
            for (let hour = 0; hour < 24; hour++) hourly[bird.name][hour] = (row + 1) * 100 + hour;
        }
        if (weather) for (let hour = 0; hour < 24; hour++) conditions[hour] = {temp: 70 + hour, code: 2, is_day: 1};
        window.sampleData = {species, hourly, weather: conditions, currentHour: 8};
        window.XMLHttpRequest = class {
            open() {}
            send() {
                window.requestCount++;
                this.status = 200;
                this.responseText = JSON.stringify(window.sampleData);
                this.onload();
            }
        };
    }, {weather, observer});
    await page.addScriptTag({path: path.join(root, 'homepage/static/dashboard-charts.js')});
    await page.evaluate(() => DashboardCharts.refresh());
    return page;
}

async function sizing(page) {
    return page.evaluate(() => {
        const canvas = document.getElementById('hourlyHeatmap');
        const host = canvas.parentElement;
        return {
            width: canvas.getBoundingClientRect().width,
            height: canvas.getBoundingClientRect().height,
            backingWidth: canvas.width,
            backingHeight: canvas.height,
            overflow: host.scrollWidth - host.clientWidth,
            outerOverflow: host.parentElement.scrollWidth - host.parentElement.clientWidth,
            requests: window.requestCount,
            draws: window.drawCount
        };
    });
}

async function waitForWidth(page, width) {
    await page.waitForFunction(expected => document.getElementById('hourlyHeatmap').style.width === expected + 'px', width);
}

async function hoverCell(page, hour, row = 0) {
    const point = await page.evaluate(({hour, row}) => {
        const canvas = document.getElementById('hourlyHeatmap');
        const rect = canvas.getBoundingClientRect();
        const label = Math.min(220, rect.width * 0.35);
        const header = Object.keys(window.sampleData.weather).length ? 48 : 30;
        return {x: rect.left + label + (hour + 0.5) * (rect.width - label - 10) / 24,
            y: rect.top + header + row * 32 + 16};
    }, {hour, row});
    await page.mouse.move(point.x, point.y);
    assert.match(await page.locator('.chart-tooltip').innerText(), new RegExp(hour + ':00 — ' + ((row + 1) * 100 + hour) + ' detections'));
    const bounds = await page.evaluate(() => {
        const host = document.getElementById('hourlyHeatmap').parentElement;
        const rect = host.getBoundingClientRect();
        const tip = host.querySelector('.chart-tooltip').getBoundingClientRect();
        return {left: tip.left - rect.left - host.clientLeft, right: tip.right - rect.left - host.clientLeft,
            top: tip.top - rect.top - host.clientTop, bottom: tip.bottom - rect.top - host.clientTop,
            width: host.clientWidth, height: host.clientHeight};
    });
    assert.ok(bounds.left >= 0 && bounds.right <= bounds.width + 0.5, 'tooltip stays inside the visible horizontal scrollport');
    assert.ok(bounds.top >= 0 && bounds.bottom <= bounds.height + 0.5, 'tooltip stays inside the vertical bounds');
}

for (const embed of [true, false]) {
    test((embed ? 'Home' : 'Overview') + ': wide heatmaps fit without scrollbars', async t => {
        const page = await fixture(t, {embed});
        // Overview adds another 20px of chart-container padding.
        for (const width of [1100, 900, embed ? 780 : 800, 1100.25, 900.75]) {
            await page.evaluate(width => {document.getElementById('testHost').style.width = width + 'px'; DashboardCharts.refresh();}, width);
            const size = await sizing(page);
            assert.ok(size.width <= Math.round(width) - 20, 'canvas excludes both 10px paddings');
            assert.equal(size.overflow, 0);
            assert.equal(size.outerOverflow, 0);
        }
    });
}

test('Small screens retain the 760px heatmap and all 24 hours', async t => {
    const page = await fixture(t, {width: 360});
    await page.setViewportSize({width: 390, height: 844});
    const size = await sizing(page);
    assert.equal(size.width, 760);
    assert.equal(size.overflow, 420);
    assert.equal(size.outerOverflow, 0, 'only the inner chart scrolls');
    for (const hour of ['00', '08', '23']) assert.ok(await page.evaluate(hour => drawnText.includes(hour), hour));
    await page.evaluate(() => {document.getElementById('hourlyHeatmap').parentElement.scrollLeft = 10000;});
    await hoverCell(page, 23);
    assert.equal((await sizing(page)).overflow, 420, 'hovering does not extend the scroll area');
});

test('Container-only resizing redraws cached data without new requests', async t => {
    const page = await fixture(t);
    const requests = (await sizing(page)).requests;
    for (const [container, canvas] of [[900, 880], [600, 760], [1100, 1080]]) {
        await page.evaluate(width => {document.getElementById('testHost').style.width = width + 'px';}, container);
        await waitForWidth(page, canvas);
    }
    assert.equal((await sizing(page)).requests, requests);
    assert.equal((await sizing(page)).overflow, 0);
});

test('Height-only changes and completed resizing do not cause a redraw loop', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px';});
    await waitForWidth(page, 880);
    // Allow more than two debounce periods to expose observer feedback loops.
    await page.waitForTimeout(400);
    const before = await sizing(page);
    await page.evaluate(() => {document.getElementById('hourlyHeatmap').parentElement.style.paddingBottom = '40px';});
    await page.waitForTimeout(400);
    assert.equal((await sizing(page)).draws, before.draws);
    assert.equal((await sizing(page)).requests, before.requests);
});

test('Padding and border changes use the actual content width', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {
        const host = document.getElementById('hourlyHeatmap').parentElement;
        host.style.padding = '13px 17px';
        host.style.border = '2px solid transparent';
    });
    await waitForWidth(page, 1062);
    await hoverCell(page, 23, 1);
    assert.equal((await sizing(page)).overflow, 0);
    assert.equal((await sizing(page)).requests, 1);
});

test('Resizing hides a stale tooltip before it can extend the scroll area', async t => {
    const page = await fixture(t);
    await hoverCell(page, 20);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px';});
    await waitForWidth(page, 880);
    assert.equal(await page.locator('.chart-tooltip').isVisible(), false);
    assert.equal((await sizing(page)).overflow, 0);
    await hoverCell(page, 23);
});

test('Window resize remains a fallback without ResizeObserver', async t => {
    const page = await fixture(t, {observer: false});
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px'; window.dispatchEvent(new Event('resize'));});
    await waitForWidth(page, 880);
    assert.equal((await sizing(page)).requests, 1);
});

for (const dpr of [1.25, 2]) {
    test('High-DPI rendering and hit testing at scale ' + dpr, async t => {
        const page = await fixture(t, {dpr});
        const size = await sizing(page);
        assert.equal(size.width, 1080);
        assert.equal(size.backingWidth, Math.floor(size.width * dpr));
        assert.equal(size.backingHeight, Math.floor(size.height * dpr));
        assert.equal(size.overflow, 0);
        await hoverCell(page, 0);
        await hoverCell(page, 23, 1);
        assert.equal((await sizing(page)).overflow, 0);
    });
}

test('Window/viewport changes preserve width and pointer alignment', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '80vw';});
    for (const width of [1250, 1000, 1500]) {
        await page.setViewportSize({width, height: 1000});
        await waitForWidth(page, width * 0.8 - 20);
        await hoverCell(page, 20, 1);
        assert.equal((await sizing(page)).overflow, 0);
    }
    assert.equal((await sizing(page)).requests, 1);
});

test('Hidden containers never reuse the high-DPI backing width', async t => {
    const page = await fixture(t, {dpr: 2});
    await page.evaluate(() => {document.getElementById('testHost').style.display = 'none'; DashboardCharts.refresh(); DashboardCharts.refresh();});
    assert.equal(await page.locator('#hourlyHeatmap').evaluate(canvas => canvas.style.width), '760px');
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px'; document.getElementById('testHost').style.display = '';});
    await waitForWidth(page, 880);
    assert.equal((await sizing(page)).overflow, 0);
});

test('Grid/Heatmap replacement rebinds observation and handles pending resize', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {
        const host = document.getElementById('testHost');
        host.style.width = '900px';
        window.dispatchEvent(new Event('resize'));
        document.getElementById('todaySpeciesContainer').innerHTML = '<p>Grid view</p>';
    });
    await page.waitForTimeout(250);
    await page.evaluate(() => {
        document.getElementById('todaySpeciesContainer').innerHTML = '<div class="chart-canvas-wrapper ui-chart-scroll"><canvas id="hourlyHeatmap"></canvas></div>';
        DashboardCharts.refresh();
    });
    await waitForWidth(page, 880);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '1000px';});
    await waitForWidth(page, 980);
    assert.equal(await page.locator('.chart-tooltip').count(), 1);
    await hoverCell(page, 8);
    assert.equal((await sizing(page)).requests, 2);
});

test('Empty data recovers without replacing the canvas or losing size observation', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {window.savedSpecies = sampleData.species; sampleData.species = []; DashboardCharts.refresh();});
    assert.equal(await page.locator('#hourlyHeatmap').isVisible(), false);
    assert.equal(await page.locator('.heatmap-empty-msg').count(), 1);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px'; sampleData.species = savedSpecies; DashboardCharts.refresh();});
    await waitForWidth(page, 880);
    assert.equal(await page.locator('.heatmap-empty-msg').count(), 0);
    assert.equal(await page.locator('#hourlyHeatmap').isVisible(), true);
    await hoverCell(page, 8);
});

test('Weather-free, partial-weather, and full-weather rows keep correct hit regions', async t => {
    const page = await fixture(t, {weather: false});
    await hoverCell(page, 8, 1);
    const dryHeight = (await sizing(page)).height;
    await page.evaluate(() => {sampleData.weather = {8: {temp: null, code: null, is_day: null}}; DashboardCharts.refresh();});
    assert.equal((await sizing(page)).height, dryHeight + 18);
    await hoverCell(page, 8, 1);
    assert.match(await page.locator('.chart-tooltip').innerText(), /Temperature unavailable/);
    await page.evaluate(() => {sampleData.weather[8] = {temp: 0, code: 0, is_day: 1}; DashboardCharts.refresh();});
    await hoverCell(page, 8, 1);
    assert.match(await page.locator('.chart-tooltip').innerText(), /0°F/);
    assert.equal((await sizing(page)).overflow, 0);
});

test('Long tooltip labels do not add horizontal overflow', async t => {
    const page = await fixture(t, {width: 900});
    await page.evaluate(() => {sampleData.species[0].name = 'LongSpeciesName'.repeat(25); sampleData.hourly[sampleData.species[0].name] = Object.fromEntries(Array.from({length:24}, (_,hour) => [hour, 100 + hour])); DashboardCharts.refresh();});
    await hoverCell(page, 18);
    assert.equal((await sizing(page)).overflow, 0);
});

test('Species clicks still navigate to the correct bird after resizing', async t => {
    const page = await fixture(t, {dpr: 2});
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px';});
    await waitForWidth(page, 880);
    const rect = await page.locator('#hourlyHeatmap').boundingBox();
    await page.mouse.click(rect.x + 120, rect.y + 48 + 32 + 16);
    await page.waitForURL('**/?view=Bird&sci_name=Bycanistes%20subcylindricus');
});

test('Thumbnails and image previews still work after resizing', async t => {
    const page = await fixture(t);
    await page.evaluate(() => {
        window.thumbnailDraws = 0;
        const drawImage = CanvasRenderingContext2D.prototype.drawImage;
        CanvasRenderingContext2D.prototype.drawImage = function (...args) {window.thumbnailDraws++; return drawImage.apply(this, args);};
        sampleData.species[0].image = 'data:image/svg+xml,' + encodeURIComponent('<svg xmlns="http://www.w3.org/2000/svg" width="24" height="24"><rect width="24" height="24" fill="teal"/></svg>');
        DashboardCharts.refresh();
    });
    await page.waitForFunction(() => thumbnailDraws > 0);
    await page.evaluate(() => {document.getElementById('testHost').style.width = '900px';});
    await waitForWidth(page, 880);
    const rect = await page.locator('#hourlyHeatmap').boundingBox();
    await page.mouse.move(rect.x + 22, rect.y + 48 + 16);
    assert.equal(await page.locator('.heatmap-img-preview').isVisible(), true);
    assert.equal((await sizing(page)).overflow, 0);
    await page.mouse.move(0, 0);
    assert.equal(await page.locator('.heatmap-img-preview').isVisible(), false);
});

test('Dark-mode redraw preserves fitted width without fetching data', async t => {
    const page = await fixture(t);
    const before = await sizing(page);
    // dark-style.css imports the base stylesheet; serve that from disk too.
    await page.route('**/style.css', route => route.fulfill({path: path.join(root, 'homepage/style.css'), contentType: 'text/css'}));
    await page.addStyleTag({path: path.join(root, 'homepage/static/dark-style.css')});
    await page.evaluate(() => {document.documentElement.setAttribute('data-theme', 'dark');});
    await page.waitForFunction(draws => drawCount > draws, before.draws);
    assert.equal((await sizing(page)).width, before.width);
    assert.equal((await sizing(page)).overflow, 0);
    assert.equal((await sizing(page)).requests, before.requests);
    await hoverCell(page, 23);
});
