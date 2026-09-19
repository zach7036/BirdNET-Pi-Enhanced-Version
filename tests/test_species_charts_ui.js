// Issue #24: real PHP-rendered Species markup and shipped Analytics/Chart.js.
// All data/network requests are synthetic; no station database is opened.
// node --test tests/test_species_charts_ui.js
// Optional: BIRDNET_TEST_PHP, BIRDNET_TEST_BROWSER=chrome, BIRDNET_TEST_ENGINE=webkit.
const assert = require('node:assert/strict');
const fs = require('node:fs');
const os = require('node:os');
const path = require('node:path');
const {execFileSync} = require('node:child_process');
const test = require('node:test');
const playwright = require('playwright');
const root = path.resolve(__dirname, '..');
const read = file => fs.readFileSync(path.join(root, file), 'utf8');
const engine = process.env.BIRDNET_TEST_ENGINE || 'chromium';
let browser, speciesHTML;

test.before(async () => {
    // Evaluate only the renderer and template, not the DB/config/bootstrap code.
    speciesHTML = execFileSync(process.env.BIRDNET_TEST_PHP || 'php', ['-r', `
        function h($s) { return htmlspecialchars($s, ENT_QUOTES, 'UTF-8'); }
        function format_number($n, $d = 0) { return number_format($n, $d); }
        function get_info_url($s) { return ['URL'=>'https://example.test/info', 'TITLE'=>'All About Birds']; }
        function get_wikipedia_url($s) { return 'https://example.test/wiki'; }
        $source = file_get_contents($argv[1]);
        $start = strpos($source, 'function render_species_cards(');
        eval(substr($source, $start, strpos($source, 'if ($is_species_ajax)', $start) - $start));
        $species_list = [];
        foreach (['House Sparrow', 'Northern Cardinal', 'Black-and-white-casqued Hornbill'] as $name) {
            $species_list[] = ['Com_Name'=>$name, 'Sci_Name'=>'Bycanistes subcylindricus',
                'Count'=>263149, 'MaxConf'=>0.978, 'FirstDate'=>'2026-03-02', 'File_Name'=>'test.wav'];
        }
        $kpi_res = ['unique_species'=>203, 'total_detections'=>887316, 'avg_conf'=>0.594];
        $species_total=203; $species_offset=0; $species_page_size=50;
        $time_period='all'; $sort_by='detections'; $search='';
        $image_provider=null; $fallback_provider=null; $config=[];
        eval('?>' . substr($source, strpos($source, '<style>')));
    `, path.join(root, 'scripts/species.php')], {encoding: 'utf8'});
    browser = await playwright[engine].launch({headless: true,
        ...(engine === 'chromium' ? {channel: process.env.BIRDNET_TEST_BROWSER || undefined} : {})});
});
test.after(async () => { if (browser) await browser.close(); });

async function fixture(t, {view = 'Species', width = 1440, app = 'light', osTheme = 'light', legacyDark = false} = {}) {
    const context = await browser.newContext({viewport: {width, height: 1000}, colorScheme: osTheme,
        reducedMotion: 'reduce', serviceWorkers: 'block'});
    const errors = [], unexpected = [], requests = [], navigations = [];
    t.after(async () => {
        await context.close();
        assert.deepEqual(errors, [], 'no browser errors');
        assert.deepEqual(unexpected, [], 'no unmocked/external requests');
    });
    const baseStyle = legacyDark ? '<link rel="stylesheet" href="/static/dark-style.css">' : '<style>' + read('homepage/style.css') + '</style>';
    const styles = baseStyle + ['tokens', 'shell', 'pages'].map(name => '<style>' + read('homepage/static/css/' + name + '.css') + '</style>').join('');
    const body = view === 'Species' ? speciesHTML : read('scripts/analytics.php').replace(/<\?php[\s\S]*?\?>/g, '');
    const markup = '<!doctype html><html' + (app ? ' data-theme="' + app + '"' : '') + '><head><meta charset="utf-8">' +
        '<meta name="viewport" content="width=device-width,initial-scale=1">' + styles + '</head><body>' +
        '<aside class="sidebar"><div class="sidebar-header">BirdNET-Pi</div></aside>' +
        '<div class="mobile-header">BirdNET-Pi</div><div class="views">' + body + '</div></body></html>';
    await context.addInitScript(() => {
        window.BIRDNET_UNITS = {numLocale: 'en-US'};
        // Inspect colors actually painted to canvas, not just JS defaults.
        window.chartText = [];
        const fillText = CanvasRenderingContext2D.prototype.fillText;
        CanvasRenderingContext2D.prototype.fillText = function(text, ...args) {
            chartText.push({id: this.canvas.id, text: String(text), color: this.fillStyle});
            return fillText.call(this, text, ...args);
        };
    });
    const data = {
        stats: {total_detections: 887316, unique_species: 203, avg_confidence: 59.4, most_common: 'House Sparrow', most_common_count: 263149},
        top_species: [{species: 'House Sparrow', count: 123}, {species: 'Northern Cardinal', count: 45}],
        activity: [{hour: 6, count: 12}, {hour: 7, count: 20}],
        detections: {dates: ['2026-09-17', '2026-09-18'], counts: [12, 20]},
        diversity: {dates: ['2026-09-17', '2026-09-18'], counts: [2, 3]},
        new_species: [], recent: [],
        patterns: {'House Sparrow': Array(24).fill(4), 'Northern Cardinal': Array(24).fill(2)},
        trends: {dates: ['2026-09-17', '2026-09-18'], series: {'House Sparrow': [12, 20], 'Northern Cardinal': [4, 6]}}
    };
    await context.route('**/*', route => {
        const req = route.request(), url = new URL(req.url());
        const json = value => route.fulfill({contentType: 'application/json', body: JSON.stringify(value)});
        if (url.origin !== 'http://display.test') { unexpected.push(url.href); return route.abort(); }
        if (url.searchParams.has('ajax_species_batch')) {
            requests.push(url.href);
            return json({html: speciesHTML.match(/<div class="species-grid" id="species-grid">([\s\S]*?)<div id="species-load-error"/)[1].replace(/<\/div>\s*$/, ''),
                total: 6, next_offset: Number(url.searchParams.get('offset')) + 3, has_more: false,
                kpi_species: 6, kpi_detections: 123, kpi_conf: 88.2});
        }
        if (url.pathname.startsWith('/api/v1/')) {
            requests.push(url.href);
            const key = url.pathname.split('/').pop();
            if (key in data) return json(data[key]);
        }
        if (url.pathname === '/' || url.pathname === '/views.php') {
            navigations.push(url.href);
            return route.fulfill({contentType: 'text/html', body: markup});
        }
        const assets = {
            '/style.css': ['homepage/style.css', 'text/css'],
            '/static/dark-style.css': ['homepage/static/dark-style.css', 'text/css'],
            '/static/RobotoFlex-Regular.ttf': ['homepage/static/RobotoFlex-Regular.ttf', 'font/ttf'],
            '/static/Chart.bundle.js': ['homepage/static/Chart.bundle.js', 'application/javascript'],
            '/images/bird.png': ['homepage/images/bird.png', 'image/png'],
            '/images/info.png': ['homepage/images/info.png', 'image/png'],
            '/images/wiki.png': ['homepage/images/wiki.png', 'image/png']
        };
        if (assets[url.pathname]) {
            const [file, contentType] = assets[url.pathname];
            return route.fulfill({path: path.join(root, file), contentType});
        }
        unexpected.push(url.href);
        return route.abort();
    });
    const page = await context.newPage();
    page.on('pageerror', error => errors.push(error.message));
    await page.goto('http://display.test/?view=' + view);
    await page.evaluate(() => document.fonts.ready);
    if (view === 'Analytics') {
        await page.waitForFunction(() => Object.keys(charts).length === 6 && charts.speciesTrends.data.datasets.length === 2);
        await page.evaluate(() => Object.values(charts).forEach(chart => { chart.options.animation.duration = 0; chart.update(0); }));
    }
    return {page, requests, navigations};
}

async function speciesBounds(page) {
    return page.evaluate(() => {
        const rect = el => { const r = el.getBoundingClientRect(); return {x: r.x, y: r.y, right: r.right, width: r.width, bottom: r.bottom}; };
        const dashboard = document.querySelector('.species-dashboard');
        const outside = [...dashboard.querySelectorAll('.kpi-card, .filter-section, .styled-select, .styled-input, .filter-actions > *, .bird-card, .stats-table')]
            .filter(el => { const r = el.getBoundingClientRect(); return r.left < 0 || r.right > innerWidth + 1; }).map(el => el.className);
        return {outside, overflow: document.documentElement.scrollWidth - innerWidth,
            header: rect(document.querySelector('.header-text')),
            cards: [...document.querySelectorAll('.kpi-card')].map(rect),
            filters: rect(document.querySelector('.filter-section'))};
    });
}

for (const app of ['light', 'dark']) for (const width of [320, 375, 390, 430, 600, 768, 1024, 1440]) {
    test(`Species ${width}px ${app}: cards and controls fit`, async t => {
        const {page} = await fixture(t, {width, app});
        const size = await speciesBounds(page);
        assert.deepEqual(size.outside, []);
        assert.ok(size.overflow <= 1, 'no horizontal page scrolling');
        assert.ok(size.header.width >= Math.min(240, width - 72), 'description is not squeezed into a narrow column');
        if (width <= 600) {
            assert.ok(size.cards[1].y >= size.cards[0].bottom, 'KPI cards stack vertically');
            assert.ok(size.cards[0].y >= size.header.bottom, 'KPIs follow the heading');
            assert.ok(Math.abs(size.cards[0].width - size.filters.width) <= 1, 'cards fill the content width');
        } else {
            assert.equal(size.cards[0].y, size.cards[1].y, 'KPIs remain side by side when space allows');
        }
        if (width === 1440) assert.equal(size.header.y, size.cards[0].y, 'wide desktop header remains one row');
        if (process.env.BIRDNET_DISPLAY_SCREENSHOTS && [390, 1440].includes(width)) {
            await page.screenshot({path: path.join(os.tmpdir(), `birdnet-species-${width}-${app}.png`), fullPage: true});
        }
    });
}

test('Species: sidebar collapse and narrow/zoom-equivalent viewport do not overflow', async t => {
    const {page} = await fixture(t, {width: 1200});
    for (const expanded of [true, false]) {
        await page.locator('.views').evaluate((el, value) => el.classList.toggle('expanded', value), expanded);
        assert.ok((await speciesBounds(page)).overflow <= 1);
    }
    await page.setViewportSize({width: 600, height: 800});
    assert.ok((await speciesBounds(page)).overflow <= 1);
    await page.setViewportSize({width: 320, height: 800});
    await page.locator('.bird-sci').first().evaluate(el => el.textContent = 'VeryLongScientificName'.repeat(5));
    assert.ok((await speciesBounds(page)).overflow <= 1, 'long names wrap');
});

test('Species: filtering, search and Load More still update in place on mobile', async t => {
    const {page, requests} = await fixture(t, {width: 390});
    await page.locator('[name=time_period]').selectOption('7d');
    await page.waitForFunction(() => document.getElementById('species-kpi-count').textContent === '6');
    await page.locator('[name=sort_by]').selectOption('confidence');
    const searched = page.waitForResponse(response => new URL(response.url()).searchParams.get('search') === 'Sparrow');
    await page.locator('#species-search-input').fill('Sparrow');
    await searched;
    await page.waitForFunction(() => document.getElementById('species-grid').style.opacity === '' && !location.search.includes('search='));
    assert.ok(requests.some(url => new URL(url).searchParams.get('search') === 'Sparrow'));
    assert.equal(await page.locator('#species-search-input').inputValue(), 'Sparrow');
    assert.deepEqual((await speciesBounds(page)).outside, []);
    // Fresh page has additional species to load; it must append, not navigate.
    await page.reload();
    const before = await page.locator('.bird-card').count();
    await page.locator('#species-load-more').click();
    await page.waitForFunction(() => document.querySelectorAll('.bird-card').length === 6);
    assert.equal(await page.locator('.bird-card').count(), before + 3);
});

for (const button of ['.btn-apply', '.btn-export']) {
    test(`Species mobile: ${button} retains its GET form behavior`, async t => {
        const {page, navigations} = await fixture(t, {width: 320});
        await page.locator('#species-filters ' + button).click();
        await page.waitForURL(url => url.searchParams.has('time_period'));
        const params = new URL(navigations.at(-1)).searchParams;
        assert.equal(params.get('view'), 'Species');
        assert.equal(params.get('sort_by'), 'detections');
        assert.equal(params.get('export'), button === '.btn-export' ? 'csv' : null);
    });
}

async function chartColors(page) {
    return page.evaluate(() => {
        const style = getComputedStyle(document.querySelector('.analytics-dashboard'));
        return {text: style.getPropertyValue('--text-primary').trim(), grid: style.getPropertyValue('--border').trim(),
            background: getComputedStyle(document.querySelector('.viz-card')).backgroundColor,
            subtitle: getComputedStyle(document.querySelector('.chart-sub')).color,
            charts: Object.values(charts).map(chart => ({id: chart.canvas.id, legend: chart.options.legend.labels.fontColor,
                axes: [...chart.options.scales.xAxes, ...chart.options.scales.yAxes].map(axis => ({text: axis.ticks.fontColor, grid: axis.gridLines.color}))})),
            painted: chartText};
    });
}

function contrast(a, b) {
    const luminance = color => {
        const rgb = color.startsWith('#') ? color.slice(1).match(/../g).map(c => parseInt(c, 16)) : color.match(/[\d.]+/g).slice(0, 3).map(Number);
        return rgb.map(v => v / 255).map(v => v <= .04045 ? v / 12.92 : ((v + .055) / 1.055) ** 2.4).reduce((sum, v, i) => sum + v * [.2126, .7152, .0722][i], 0);
    };
    const values = [luminance(a), luminance(b)].sort((x, y) => y - x);
    return (values[0] + .05) / (values[1] + .05);
}

async function assertChartColors(page) {
    const colors = await chartColors(page);
    assert.ok(contrast(colors.text, colors.background) >= 4.5, 'normal-size chart text has readable contrast');
    assert.ok(contrast(colors.subtitle, colors.background) >= 4.5, 'chart descriptions have readable contrast');
    for (const chart of colors.charts) {
        assert.equal(chart.legend, colors.text);
        for (const axis of chart.axes) { assert.equal(axis.text, colors.text); assert.equal(axis.grid, colors.grid); }
        assert.ok(colors.painted.some(draw => draw.id === chart.id && draw.color === colors.text), 'each canvas paints themed text');
    }
}

for (const app of ['light', 'dark']) for (const osTheme of ['light', 'dark']) {
    test(`Charts: app ${app}, OS ${osTheme} uses app colors`, async t => {
        const {page} = await fixture(t, {view: 'Analytics', app, osTheme});
        await assertChartColors(page);
        if (process.env.BIRDNET_DISPLAY_SCREENSHOTS && osTheme === 'dark') {
            await page.screenshot({path: path.join(os.tmpdir(), `birdnet-charts-${app}.png`), fullPage: true});
        }
    });
}

test('Charts: first visit without saved theme ignores dark OS preference', async t => {
    const {page} = await fixture(t, {view: 'Analytics', app: '', osTheme: 'dark'});
    await assertChartColors(page);
    assert.equal((await chartColors(page)).text, '#334155');
});

test('Charts: theme changes repaint all six canvases without refetching or losing filters/legend state', async t => {
    const {page, requests} = await fixture(t, {view: 'Analytics'});
    await page.evaluate(() => {
        document.getElementById('time-period').value = '30';
        selectedSpecies.patterns = ['House Sparrow'];
        selectedSpecies.trends = ['Northern Cardinal'];
        charts.patterns.getDatasetMeta(0).hidden = true;
        window.originalCharts = Object.values(charts);
        // Chart.js attaches circular internal metadata to datasets.
        window.chartDataSnapshot = chart => JSON.stringify(chart.data, (key, value) => key === '_meta' ? undefined : value);
        window.savedData = Object.values(charts).map(chartDataSnapshot);
    });
    const requestCount = requests.length;
    for (const theme of ['dark', 'light', 'dark', 'light']) {
        await page.evaluate(theme => { chartText = []; document.documentElement.dataset.theme = theme; }, theme);
        await page.waitForFunction(() => charts.top.options.scales.xAxes[0].ticks.fontColor === getComputedStyle(document.querySelector('.analytics-dashboard')).getPropertyValue('--text-primary').trim());
        await assertChartColors(page);
        assert.equal(requests.length, requestCount);
        assert.equal(await page.locator('#time-period').inputValue(), '30');
        assert.ok(await page.evaluate(() => Object.values(charts).every((chart, i) => chart === originalCharts[i] && chartDataSnapshot(chart) === savedData[i])));
        assert.equal(await page.evaluate(() => charts.patterns.getDatasetMeta(0).hidden), true);
        assert.deepEqual(await page.evaluate(() => selectedSpecies), {patterns: ['House Sparrow'], trends: ['Northern Cardinal']});
    }
    // Data refresh after toggling still uses the current theme.
    await page.evaluate(() => applyFilters());
    await page.waitForLoadState('networkidle');
    assert.ok(requests.length > requestCount);
    await assertChartColors(page);
});

test('Charts: legacy dark stylesheet resolves its actual CSS colors', async t => {
    const {page} = await fixture(t, {view: 'Analytics', legacyDark: true, app: '', osTheme: 'light'});
    await assertChartColors(page);
});
