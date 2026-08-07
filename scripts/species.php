<?php
// scripts/species.php

require_once 'scripts/common.php';
$config = get_config();

// Get filter parameters
$time_period = isset($_GET['time_period']) ? $_GET['time_period'] : 'all';
$sort_by = isset($_GET['sort_by']) ? $_GET['sort_by'] : 'detections';
$search = isset($_GET['search']) ? $_GET['search'] : '';
$species_page_size = request_int($_GET, 'limit', 50, 1, 100);
$species_offset = request_int($_GET, 'offset', 0, 0, 1000000);
$is_species_ajax = isset($_GET['ajax_species_batch']) && $_GET['ajax_species_batch'] == 'true';
$is_csv_export = isset($_GET['export']) && $_GET['export'] == 'csv';

$db = get_db();

// Build WHERE clause for time period
$where_clauses = [];
if ($time_period !== 'all') {
    switch ($time_period) {
        case '24h': $where_clauses[] = "Date >= date('now', '-1 day')"; break;
        case '7d':  $where_clauses[] = "Date >= date('now', '-7 days')"; break;
        case '30d': $where_clauses[] = "Date >= date('now', '-30 days')"; break;
        case '90d': $where_clauses[] = "Date >= date('now', '-90 days')"; break;
        case '1y':  $where_clauses[] = "Date >= date('now', '-1 year')"; break;
    }
}
if (!empty($search)) {
    $where_clauses[] = "(Com_Name LIKE :search OR Sci_Name LIKE :search)";
}

$where_sql = !empty($where_clauses) ? "WHERE " . implode(" AND ", $where_clauses) : "";

// KPI Data
$kpi_stmt = $db->prepare("SELECT COUNT(DISTINCT Sci_Name) as unique_species, COUNT(*) as total_detections, AVG(Confidence) as avg_conf FROM detections $where_sql");
if (!empty($search)) {
    $kpi_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
}
$kpi_res = db_fetch_assoc_safe(db_execute_safe($db, $kpi_stmt, 'species kpis')) ?: [];

// Species List Data
$order_by = "COUNT(*) DESC";
switch ($sort_by) {
    case 'sci_name': $order_by = "Sci_Name ASC"; break;
    case 'com_name': $order_by = "Com_Name ASC"; break;
    case 'confidence': $order_by = "MAX(Confidence) DESC"; break;
}

$count_stmt = $db->prepare("SELECT COUNT(*) AS total FROM (SELECT Sci_Name FROM detections $where_sql GROUP BY Sci_Name)");
if (!empty($search)) {
    $count_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
}
$species_total_row = db_fetch_assoc_safe(db_execute_safe($db, $count_stmt, 'species count'));
$species_total = (int)($species_total_row['total'] ?? 0);

$list_sql = "SELECT Com_Name, Sci_Name, COUNT(*) as Count, MAX(Confidence) as MaxConf, MIN(Date) as FirstDate, File_Name FROM detections $where_sql GROUP BY Sci_Name ORDER BY $order_by";
if (!$is_csv_export) {
    $list_sql .= " LIMIT :limit OFFSET :offset";
}
$list_stmt = $db->prepare($list_sql);
if (!empty($search)) {
    $list_stmt->bindValue(':search', '%' . $search . '%', SQLITE3_TEXT);
}
if (!$is_csv_export) {
    $list_stmt->bindValue(':limit', $species_page_size, SQLITE3_INTEGER);
    $list_stmt->bindValue(':offset', $species_offset, SQLITE3_INTEGER);
}
$list_res = db_execute_safe($db, $list_stmt, 'species list');

$species_list = [];
while ($row = db_fetch_assoc_safe($list_res)) {
    $species_list[] = $row;
}

if ($is_csv_export) {
    while (ob_get_level() > 0) {
        ob_end_clean();
    }
    header('Content-Type: text/csv');
    header('Content-Disposition: attachment; filename="species_list.csv"');
    $output = fopen('php://output', 'w');
    fputcsv($output, ['Common Name', 'Scientific Name', 'Detections', 'Max Confidence', 'First Detected'], ',', '"', '');
    foreach ($species_list as $bird) {
        fputcsv($output, [
            $bird['Com_Name'],
            $bird['Sci_Name'],
            $bird['Count'],
            round($bird['MaxConf'] * 100, 1) . '%',
            $bird['FirstDate']
        ], ',', '"', '');
    }
    fclose($output);
    exit();
}

// Image fetching logic. make_image_provider returns [null, null] for provider
// "None" - previously this page fell through to Wikipedia and made outbound
// lookups a provider-less station had opted out of.
list($image_provider, $fallback_provider) = make_image_provider($config);

if ($image_provider && $image_provider->is_reset()) {
    $_SESSION['species_portal_v8_cache'] = [];
}

function render_species_cards($species_list, $image_provider, $fallback_provider, $config) {
    ob_start();
    foreach ($species_list as $bird):
        $com_name = $bird['Com_Name'];
        $sci_name = $bird['Sci_Name'];
        $image_url = 'images/bird.png';

        if ($image_provider) {
            if (!isset($_SESSION['species_portal_v8_cache'])) {
                $_SESSION['species_portal_v8_cache'] = [];
            }

            $search_name = trim($com_name);
            $key = array_search($search_name, array_column($_SESSION['species_portal_v8_cache'], 0));

            if ($key !== false) {
                $image = $_SESSION['species_portal_v8_cache'][$key];
            } else {
                $cached_image = $image_provider->get_image($sci_name, $fallback_provider);
                if ($cached_image && !empty($cached_image["image_url"])) {
                    $image_data = array($search_name, $cached_image["image_url"], $cached_image["title"], $cached_image["photos_url"], $cached_image["author_url"], $cached_image["license_url"]);
                    array_push($_SESSION["species_portal_v8_cache"], $image_data);
                    $image = $image_data;
                } else {
                    $image_data = array($search_name, "", "Not Found", "", "", "");
                    array_push($_SESSION["species_portal_v8_cache"], $image_data);
                    $image = $image_data;
                }
            }
            $image_url = ($image && !empty($image[1])) ? $image[1] : 'images/bird.png';
        }

        $info = get_info_url($sci_name);
        $wiki_url = get_wikipedia_url($sci_name);
?>
            <div class="bird-card">
                <a class="bird-image-container" href="?view=Bird&amp;sci_name=<?php echo rawurlencode($sci_name); ?>">
                    <img src="<?php echo h($image_url); ?>" alt="<?php echo h($com_name); ?>" class="bird-image" loading="lazy" onerror="this.onerror=null; this.src='images/bird.png'">
                </a>
                <div class="card-content">
                    <a class="bird-name" href="?view=Bird&amp;sci_name=<?php echo rawurlencode($sci_name); ?>"><?php echo h($com_name); ?></a>
                    <span class="bird-sci"><?php echo h($sci_name); ?></span>
                    <table class="stats-table">
                        <tr><td>Detections:</td><td><?php echo format_number($bird['Count']); ?></td></tr>
                        <tr><td>Confidence:</td><td><?php echo format_number($bird['MaxConf'] * 100, 1); ?>%</td></tr>
                        <tr><td>First:</td><td><?php echo date('n/j/Y', strtotime($bird['FirstDate'])); ?></td></tr>
                    </table>
                    <div class="species-card-links">
                        <a href="?view=Bird&amp;sci_name=<?php echo rawurlencode($sci_name); ?>" class="mrd-link-pill bird-detail-pill">
                            Details &amp; history
                        </a>
                        <a href="<?php echo h($info['URL']); ?>" target="_blank" rel="noopener noreferrer" class="mrd-link-pill">
                            <img src="images/info.png" alt=""> <?php echo h($info['TITLE']); ?>
                        </a>
                        <a href="<?php echo h($wiki_url); ?>" target="_blank" rel="noopener noreferrer" class="mrd-link-pill">
                            <img src="images/wiki.png" alt=""> Wikipedia
                        </a>
                    </div>
                </div>
            </div>
<?php
    endforeach;
    return ob_get_clean();
}

if ($is_species_ajax) {
    header('Content-Type: application/json');
    $next_offset = $species_offset + count($species_list);
    echo json_encode([
        'html' => render_species_cards($species_list, $image_provider, $fallback_provider, $config),
        'count' => count($species_list),
        'next_offset' => $next_offset,
        'total' => $species_total,
        'has_more' => $next_offset < $species_total,
        // Header KPIs so in-place filter refreshes stay in sync with what a
        // full page load would show for the same filters
        'kpi_species' => (int)($kpi_res['unique_species'] ?? 0),
        'kpi_detections' => (int)($kpi_res['total_detections'] ?? 0),
        'kpi_conf' => round((float)($kpi_res['avg_conf'] ?? 0) * 100, 1)
    ]);
    exit;
}


?>

<style>
/* Reusing styles from analytics.php and adding species-specific ones */
.species-dashboard {
    padding: 20px 40px;
    width: 100%;
    max-width: none !important;
    margin: 0;
    box-sizing: border-box;
    font-family: -apple-system, BlinkMacSystemFont, "Segoe UI", Roboto, Helvetica, Arial, sans-serif;
}
.dashboard-header { display: flex; justify-content: space-between; align-items: flex-start; margin-bottom: 24px; }
.header-text h1 { font-size: 1.8rem; margin: 0; color: var(--text-heading); }
.header-text p { margin: 4px 0 0 0; color: var(--text-muted, #64748b); }

.kpi-row { display: flex; gap: 20px; align-items: stretch; }
.kpi-card {
    background: var(--bg-card);
    padding: 12px 28px;
    border-radius: 12px;
    border: 1px solid var(--border-light, #f1f5f9);
    display: flex;
    align-items: center;
    gap: 16px;
    min-width: 280px;
    box-shadow: 0 1px 3px rgba(0,0,0,0.05);
}
.kpi-icon {
    width: 40px; height: 40px;
    border-radius: 50%;
    background: #eff6ff;
    color: #3b82f6;
    display: flex;
    align-items: center;
    justify-content: center;
    font-size: 1.2rem;
    flex-shrink: 0;
}
.kpi-info { display: flex; flex-direction: column; }
.kpi-label { font-size: 0.9rem; font-weight: 600; color: var(--text-heading); }
.kpi-value { font-size: 1.6rem; font-weight: 700; color: var(--text-primary); line-height: 1; margin: 4px 0; }
.kpi-sub { font-size: 0.75rem; color: var(--text-muted); }

/* Filters */
.filter-section {
    background: var(--bg-card);
    padding: 24px;
    border-radius: 12px;
    margin-bottom: 32px;
    border: 1px solid var(--border-light, #f1f5f9);
}
.filter-grid {
    display: grid;
    grid-template-columns: repeat(auto-fit, minmax(200px, 1fr));
    gap: 24px;
    align-items: end;
}
.filter-group { display: flex; flex-direction: column; gap: 8px; }
.filter-group label { font-size: 0.85rem; font-weight: 600; color: var(--text-muted); }
.styled-select, .styled-input {
    padding: 10px 12px;
    border-radius: 8px;
    border: 1px solid var(--border);
    background: var(--bg-input, #fff);
    color: var(--text-primary);
    outline: none;
    font-size: 0.9rem;
}
.search-group { grid-column: 1 / -1; }
.filter-footer { display: flex; justify-content: space-between; align-items: center; margin-top: 20px; }
.results-count { font-size: 0.85rem; color: var(--text-muted); }
.filter-actions { display: flex; gap: 12px; align-items: center; }
/* These classes had no styles at all: Apply fell back to the browser's
   default button, Reset was a bare baseline-aligned link (it sat higher),
   and Export CSV compensated with an unreadable inline dark background. */
.btn-apply, .btn-reset, .btn-export {
    display: inline-flex;
    align-items: center;
    gap: 6px;
    padding: 10px 18px;
    border-radius: 8px;
    font-size: 0.9rem;
    font-weight: 600;
    line-height: 1;
    cursor: pointer;
    text-decoration: none;
    transition: background 0.15s, color 0.15s, border-color 0.15s;
}
.btn-apply {
    background: var(--accent, #4f46e5);
    color: #fff;
    border: 1px solid var(--accent, #4f46e5);
}
.btn-apply:hover { filter: brightness(1.08); }
.btn-reset, .btn-export {
    background: var(--bg-card);
    color: var(--text-secondary, #64748b);
    border: 1px solid var(--border);
}
.btn-reset:hover, .btn-export:hover {
    color: var(--text-primary);
    border-color: var(--accent, #4f46e5);
}

/* Grid */
.species-grid {
    display: grid;
    grid-template-columns: repeat(auto-fill, minmax(280px, 1fr));
    gap: 24px;
}
.bird-card {
    background: var(--bg-card);
    border-radius: 16px;
    overflow: hidden;
    border: 1px solid var(--border-light, #f1f5f9);
    transition: transform 0.2s, box-shadow 0.2s;
    position: relative;
    display: flex;
    flex-direction: column;
}
.bird-card:hover { 
    transform: translateY(-4px);
    box-shadow: 0 12px 20px -8px rgba(0,0,0,0.1);
}
.bird-image-container {
    height: 200px;
    background: #f8fafc;
    position: relative;
    overflow: hidden;
    display: flex;
    align-items: center;
    justify-content: center;
}
.bird-image {
    width: 100%;
    height: 100%;
    object-fit: contain;
}
.card-content { padding: 20px; }
.bird-name { font-size: 1.1rem; font-weight: 700; color: var(--text-heading); margin-bottom: 2px; }
.bird-sci { font-size: 0.85rem; font-style: italic; color: var(--text-muted); margin-bottom: 16px; display: block; }
.stats-table { width: 100%; font-size: 0.85rem; }
.stats-table tr td:first-child { color: var(--text-muted); padding-bottom: 4px; }
.stats-table tr td:last-child { text-align: right; font-weight: 600; color: var(--text-primary); padding-bottom: 4px; }
.species-card-links {
    display: flex;
    gap: 6px;
    flex-wrap: wrap;
    margin-top: 14px;
}
.species-card-links .mrd-link-pill {
    display: inline-flex;
    align-items: center;
    gap: 4px;
    padding: 4px 10px;
    border-radius: 20px;
    font-size: 0.78rem;
    font-weight: 600;
    text-decoration: none;
    color: var(--text-muted);
    background: var(--bg-input, #f1f5f9);
    border: 1px solid var(--border);
    transition: background 0.2s, color 0.2s;
}
.species-card-links .mrd-link-pill img {
    width: 12px;
    height: 12px;
}
.species-card-links .mrd-link-pill:hover {
    background: var(--accent);
    color: white;
}

</style>

<div class="species-dashboard">
    <div class="dashboard-header">
        <div class="header-text">
            <h1>Species</h1>
            <p>Comprehensive list of all bird species that have been detected</p>
        </div>
        <div class="kpi-row">
            <div class="kpi-card">
                <div class="kpi-icon">📋</div>
                <div class="kpi-info">
                    <span class="kpi-label">Total Species</span>
                    <span class="kpi-value" id="species-kpi-count"><?php echo format_number($kpi_res['unique_species']); ?></span>
                    <span class="kpi-sub" id="species-kpi-detections"><?php echo format_number($kpi_res['total_detections']); ?> detections</span>
                </div>
            </div>
            <div class="kpi-card">
                <div class="kpi-icon">🎯</div>
                <div class="kpi-info">
                    <span class="kpi-label">Avg. Confidence</span>
                    <span class="kpi-value" id="species-kpi-conf"><?php echo format_number($kpi_res['avg_conf'] * 100, 1); ?>%</span>
                    <span class="kpi-sub">Overall average</span>
                </div>
            </div>
        </div>
    </div>

    <div class="filter-section">
        <form action="" method="GET" id="species-filters">
            <input type="hidden" name="view" value="Species">
            <div class="filter-grid">
                <div class="filter-group">
                    <label>Time Period</label>
                    <select name="time_period" class="styled-select" data-ui-persist="species-time-period" onchange="window.refreshSpecies && refreshSpecies()">
                        <option value="all" <?php echo $time_period == 'all' ? 'selected' : ''; ?>>All Time</option>
                        <option value="24h" <?php echo $time_period == '24h' ? 'selected' : ''; ?>>Last 24 Hours</option>
                        <option value="7d" <?php echo $time_period == '7d' ? 'selected' : ''; ?>>Last 7 Days</option>
                        <option value="30d" <?php echo $time_period == '30d' ? 'selected' : ''; ?>>Last 30 Days</option>
                        <option value="90d" <?php echo $time_period == '90d' ? 'selected' : ''; ?>>Last 90 Days</option>
                        <option value="1y" <?php echo $time_period == '1y' ? 'selected' : ''; ?>>Last Year</option>
                    </select>
                </div>
                <div class="filter-group">
                    <label>Sort By</label>
                    <select name="sort_by" class="styled-select" data-ui-persist="species-sort-by" onchange="window.refreshSpecies && refreshSpecies()">
                        <option value="detections" <?php echo $sort_by == 'detections' ? 'selected' : ''; ?>>Most Detections</option>
                        <option value="com_name" <?php echo $sort_by == 'com_name' ? 'selected' : ''; ?>>Common Name</option>
                        <option value="sci_name" <?php echo $sort_by == 'sci_name' ? 'selected' : ''; ?>>Scientific Name</option>
                        <option value="confidence" <?php echo $sort_by == 'confidence' ? 'selected' : ''; ?>>Highest Confidence</option>
                    </select>
                </div>
                <div class="filter-group search-group">
                    <label>Search Species</label>
                    <input type="text" name="search" id="species-search-input" class="styled-input" data-ui-persist="species-search" placeholder="Search by name..." value="<?php echo htmlspecialchars($search); ?>">
                </div>
            </div>
            <div class="filter-footer">
                <span class="results-count" id="species-results-count">Showing <?php echo min($species_total, $species_offset + count($species_list)); ?> of <?php echo format_number($species_total); ?> species</span>
                <div class="filter-actions">
                    <a href="?view=Species" class="btn-reset">Reset</a>
                    <button type="submit" class="btn-apply">Apply Filters</button>
                    <button type="submit" name="export" value="csv" class="btn-export">📥 Export CSV</button>
                </div>
            </div>
        </form>
    </div>

    <div class="species-grid" id="species-grid">
        <?php echo render_species_cards($species_list, $image_provider, $fallback_provider, $config); ?>
    </div>
    <div id="species-load-error" style="margin-top:16px;"></div>
    <?php $next_species_offset = $species_offset + count($species_list); ?>
    <div style="text-align:center; margin:24px 0;" id="species-load-more-wrap" <?php if($next_species_offset >= $species_total) echo 'hidden'; ?>>
        <button type="button" class="btn-apply" id="species-load-more" data-next-offset="<?php echo $next_species_offset; ?>">Load 50 More</button>
    </div>
</div>

<script>
(function() {
    var urlParams = new URLSearchParams(window.location.search);
    var filterForm = document.getElementById('species-filters');
    var shouldAutoApplyPersistedFilters = filterForm && !urlParams.has('time_period') && !urlParams.has('sort_by') && !urlParams.has('search');
    var persistedFilterTimer;
    document.addEventListener('birdnet:restored', function(event) {
        if (!shouldAutoApplyPersistedFilters || !filterForm || !filterForm.contains(event.target)) return;
        clearTimeout(persistedFilterTimer);
        persistedFilterTimer = setTimeout(function() {
            filterForm.submit();
        }, 50);
    });

    var btn = document.getElementById('species-load-more');
    var grid = document.getElementById('species-grid');
    var countLabel = document.getElementById('species-results-count');
    var errorBox = document.getElementById('species-load-error');
    var total = <?php echo (int)$species_total; ?>;
    var pageSize = <?php echo (int)$species_page_size; ?>;

    function fmtNum(n) {
        return Number(n).toLocaleString(window.BIRDNET_UNITS && window.BIRDNET_UNITS.numLocale);
    }

    function filterParams() {
        var params = new URLSearchParams();
        params.set('view', 'Species');
        ['time_period', 'sort_by', 'search'].forEach(function(name) {
            var field = filterForm ? filterForm.elements[name] : null;
            if (field && field.value !== '') params.set(name, field.value);
        });
        return params;
    }

    // Filters update the grid in place - a full reload would blank the page
    // and drop focus out of the search box mid-thought. Enter and the Apply
    // button still do a normal submit, so everything works without JS too.
    var lastFilterQuery = null;
    window.refreshSpecies = function() {
        if (!grid || !filterForm) { if (filterForm) filterForm.submit(); return; }
        var params = filterParams();
        var query = params.toString();
        if (query === lastFilterQuery) return;
        lastFilterQuery = query;
        history.replaceState(null, '', '?' + query);

        var fetchParams = new URLSearchParams(params);
        fetchParams.set('ajax_species_batch', 'true');
        fetchParams.set('limit', pageSize);
        fetchParams.set('offset', 0);
        grid.style.opacity = '0.5';
        if (errorBox) errorBox.innerHTML = '';
        fetch('views.php?' + fetchParams.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function(response) {
                if (!response.ok) throw new Error('Species request failed');
                return response.json();
            })
            .then(function(data) {
                if (params.toString() !== lastFilterQuery) return; // superseded
                grid.innerHTML = data.html || '';
                total = data.total;
                if (countLabel) countLabel.textContent = 'Showing ' + fmtNum(Math.min(data.total, data.next_offset)) + ' of ' + fmtNum(data.total) + ' species';
                var kpiCount = document.getElementById('species-kpi-count');
                var kpiDet = document.getElementById('species-kpi-detections');
                var kpiConf = document.getElementById('species-kpi-conf');
                if (kpiCount) kpiCount.textContent = fmtNum(data.kpi_species);
                if (kpiDet) kpiDet.textContent = fmtNum(data.kpi_detections) + ' detections';
                if (kpiConf) kpiConf.textContent = fmtNum(data.kpi_conf) + '%';
                var wrap = document.getElementById('species-load-more-wrap');
                if (wrap) wrap.hidden = !data.has_more;
                if (btn) btn.dataset.nextOffset = data.next_offset;
            })
            .catch(function() {
                if (window.BirdNETUI && errorBox) {
                    BirdNETUI.setMessage(errorBox, 'error', 'Filter failed', 'Results could not be refreshed. Use Apply Filters to reload.');
                }
            })
            .finally(function() {
                grid.style.opacity = '';
            });
    };

    // Search applies as you type; the debounce waits for a pause so we
    // don't refetch mid-word.
    var searchInput = document.getElementById('species-search-input');
    if (searchInput && filterForm) {
        var searchTimer;
        searchInput.addEventListener('input', function() {
            clearTimeout(searchTimer);
            searchTimer = setTimeout(window.refreshSpecies, 500);
        });
    }

    if (!btn) return;

    btn.addEventListener('click', function() {
        var nextOffset = parseInt(btn.dataset.nextOffset || '0', 10);
        var params = new URLSearchParams(window.location.search);
        params.set('view', 'Species');
        params.set('ajax_species_batch', 'true');
        params.set('limit', pageSize);
        params.set('offset', nextOffset);
        btn.disabled = true;
        btn.textContent = 'Loading species...';
        if (errorBox) errorBox.innerHTML = '';

        fetch('views.php?' + params.toString(), { headers: { 'Accept': 'application/json' } })
            .then(function(response) {
                if (!response.ok) throw new Error('Species request failed');
                return response.json();
            })
            .then(function(data) {
                grid.insertAdjacentHTML('beforeend', data.html || '');
                btn.dataset.nextOffset = data.next_offset;
                total = data.total;
                if (countLabel) countLabel.textContent = 'Showing ' + fmtNum(Math.min(data.total, data.next_offset)) + ' of ' + fmtNum(data.total) + ' species';
                if (!data.has_more) {
                    document.getElementById('species-load-more-wrap').hidden = true;
                }
            })
            .catch(function() {
                if (window.BirdNETUI && errorBox) {
                    BirdNETUI.setMessage(errorBox, 'error', 'Species load failed', 'More species could not be loaded. Try again.');
                }
            })
            .finally(function() {
                btn.disabled = false;
                btn.textContent = 'Load 50 More';
            });
    });
})();
</script>
