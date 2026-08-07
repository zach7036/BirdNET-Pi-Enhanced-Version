<?php
error_reporting(E_ERROR);
ini_set('display_errors', 1);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// Get active subview. Whitelisted: the value is echoed into the page and used
// in the fragment-cache key, so arbitrary input must never get through.
$valid_subviews = ['dashboard', 'behavior', 'migration', 'environmental', 'health', 'forecasting', 'report'];
$subview = (isset($_GET['subview']) && in_array($_GET['subview'], $valid_subviews, true)) ? $_GET['subview'] : 'dashboard';

// Initialize all potential variables to prevent undefined errors in HTML
$lifetime_species = 0; $best_day_count = 0; $best_day_date = 'N/A'; $max_streak = 0;
$rarest = []; $rare_total = 0; $milestones = []; $yard_health_score = 0; $recommendations = [];
$dawn_chorus = []; $hourly_labels_json = '[]'; $hourly_values_json = '[]'; $peak_hour_label = 'N/A'; $peak_hour_count = 0;
$nocturnal = []; $activity_windows = []; $new_arrivals = []; $gone_quiet = []; $yoy_comparison = []; $seasonal_top = [];
$monthly_stats = []; $month_labels = '[]'; $month_div = '[]'; $month_det = '[]'; $shannon_index = 0; $diversity_score_text = 'N/A'; $yoy_diversity_diff = 0;
$temp_brackets = []; $condition_impact = []; $species_ideal = []; $temp_trend_labels = '[]'; $temp_trend_temps = '[]'; $temp_trend_dets = '[]'; $has_weather = false;
$confidence_trend = []; $conf_labels_json = '[]'; $conf_values_json = '[]'; $overall_avg_conf = 0; $phantom_species = []; $burst_days = []; $silent_days = [];
$high_conf_count = 0; $med_conf_count = 0; $low_conf_count = 0; $expected_today = []; $peak_species = [];
// Peak weeks come from SQLite's %W (weeks from the first Monday, 00-based);
// PHP's date('W') is ISO-8601 and disagrees by one for much of the year, which
// lit the PEAK NOW badge on the wrong rows. 'localtime' matches the Date
// column, which stores local dates.
$current_week = db_query_single_safe($db, "SELECT strftime('%W','now','localtime')", date('W'), 'insights current week');

$one_month_ago = date('Y-m-d', strtotime('-30 days'));
$two_weeks_ago = date('Y-m-d', strtotime('-14 days'));
$four_weeks_ago = date('Y-m-d', strtotime('-28 days'));
$today = date('Y-m-d');
$seasonal_batch_size = 50;
$seasonal_species_limit = $seasonal_batch_size;
$seasonal_species_offset = request_int($_GET, 'seasonal_offset', 0, 0, 1000000);
$seasonal_total_species = 0;
$migration_list_limit = request_int($_GET, 'list_limit', 100, 10, 500);

function get_seasonal_species_total($db) {
    return db_query_single_safe($db, 'SELECT COUNT(DISTINCT Sci_Name) FROM detections', 0, 'insights seasonal species total') ?: 0;
}

function insights_query_all($db, $sql) {
    return db_query_all_safe($db, $sql, 'insights');
}

function get_seasonal_presence_batch($db, $limit, $offset) {
    $seasonal_top = [];
    $seasonal_base = [];
    $seasonal_scis = [];

    $top_species_stmt = $db->prepare("
        SELECT Com_Name, Sci_Name, COUNT(*) AS total
        FROM detections
        GROUP BY Sci_Name
        ORDER BY total DESC, Com_Name ASC, Sci_Name ASC
        LIMIT :limit OFFSET :offset
    ");
    ensure_db_ok($top_species_stmt);
    $top_species_stmt->bindValue(':limit', $limit, SQLITE3_INTEGER);
    $top_species_stmt->bindValue(':offset', $offset, SQLITE3_INTEGER);
    $top_species_res = db_execute_safe($db, $top_species_stmt, 'insights seasonal species batch');
    while($row = db_fetch_assoc_safe($top_species_res)) {
        $row['actual_segments'] = array_fill(0, 48, 0);
        $seasonal_base[$row['Sci_Name']] = $row;
        $seasonal_scis[] = $row['Sci_Name'];
    }

    if (!empty($seasonal_scis)) {
        $escaped_scis = array_map(function($s) use ($db) { return "'" . $db->escapeString($s) . "'"; }, $seasonal_scis);
        $segments_sql = "
            SELECT Sci_Name,
                   ((CAST(strftime('%m', Date) AS INTEGER) - 1) * 4) +
                   CASE
                       WHEN CAST(strftime('%d', Date) AS INTEGER) <= 7 THEN 1
                       WHEN CAST(strftime('%d', Date) AS INTEGER) <= 14 THEN 2
                       WHEN CAST(strftime('%d', Date) AS INTEGER) <= 21 THEN 3
                       ELSE 4
                   END AS segment,
                   COUNT(*) AS cnt
            FROM detections
            WHERE Sci_Name IN (" . implode(',', $escaped_scis) . ")
            GROUP BY Sci_Name, segment
        ";
        $segments_res = db_query_safe($db, $segments_sql, 'insights seasonal segments');
        while($row = db_fetch_assoc_safe($segments_res)) {
            $idx = intval($row['segment']) - 1;
            if (isset($seasonal_base[$row['Sci_Name']]) && $idx >= 0 && $idx < 48) {
                $seasonal_base[$row['Sci_Name']]['actual_segments'][$idx] = intval($row['cnt']);
            }
        }
    }

    $expected_freqs = [];
    if (!empty($seasonal_scis)) {
        $sci_str = implode(',', $seasonal_scis);
        $cmd = get_home() . "/BirdNET-Pi/birdnet/bin/python3 " . __ROOT__ . "/scripts/get_seasonal_expected.py " . escapeshellarg($sci_str);
        $output = shell_exec($cmd);
        $expected_freqs = json_decode($output, true) ?: [];
    }

    foreach($seasonal_base as $row) {
        $active_segments = count(array_filter($row['actual_segments'], function($val) { return $val > 0; }));
        $row['segments_active'] = $active_segments;
        $row['status'] = $active_segments >= 36 ? 'Year-round' : ($active_segments >= 12 ? 'Seasonal' : 'Transient');
        $row['expected_segments'] = isset($expected_freqs[$row['Sci_Name']]) ? $expected_freqs[$row['Sci_Name']] : array_fill(0, 48, 0.0);
        $seasonal_top[] = $row;
    }

    return $seasonal_top;
}

function render_seasonal_species_item($s) {
    $status_colors = ['Year-round' => '#10b981', 'Seasonal' => '#f59e0b', 'Transient' => '#ef4444'];
    $status_color = isset($status_colors[$s['status']]) ? $status_colors[$s['status']] : '#64748b';
    $months_names = ['Jan','Feb','Mar','Apr','May','Jun','Jul','Aug','Sep','Oct','Nov','Dec'];
    $month_initials = ['J','F','M','A','M','J','J','A','S','O','N','D'];
    $expected_segments = isset($s['expected_segments']) && is_array($s['expected_segments']) ? $s['expected_segments'] : array_fill(0, 48, 0.0);
    $actual_segments = isset($s['actual_segments']) && is_array($s['actual_segments']) ? $s['actual_segments'] : array_fill(0, 48, 0);
?>
            <div class="insights-stats-item" style="flex-wrap: wrap; gap: 12px; padding: 16px 20px;">
                <div style="flex: 1 1 220px;">
                    <div class="insights-stats-name" style="margin-bottom: 4px; font-size: 1.05em;"><?php echo h($s['Com_Name']); ?></div>
                    <div style="font-size: 0.85em; color: var(--text-muted);">
                        <span style="display: inline-block; padding: 2px 8px; background: var(--bg-card); border-radius: 10px; border: 1px solid var(--border-light); margin-right: 8px;"><?php echo format_number($s['total']); ?> detections</span>
                        <span style="color: <?php echo h($status_color); ?>; font-weight: 700;"><?php echo h($s['status']); ?></span>
                    </div>
                </div>
                <div style="display: flex; flex-direction: column; flex: 1 1 320px; min-width: 280px;">
                    <div class="seasonal-bars-container">
                        <?php for ($i = 0; $i < 48; $i++): ?>
                            <?php if ($i > 0 && $i % 4 == 0): ?>
                                <div class="seasonal-month-divider"></div>
                            <?php endif; ?>
                            <?php
                                $expected = isset($expected_segments[$i]) ? floatval($expected_segments[$i]) : 0.0;
                                $actual = isset($actual_segments[$i]) ? intval($actual_segments[$i]) : 0;
                                $month_idx = floor($i / 4);
                                $week_in_month = ($i % 4) + 1;
                                $tooltip = $months_names[$month_idx] . " (Seg $week_in_month): " . ($actual > 0 ? $actual . " detections" : "Expected frequency: " . format_number($expected * 100, 1) . "%");
                            ?>
                            <div class="seasonal-bar-wrap" title="<?php echo h($tooltip); ?>">
                                <div class="seasonal-bar-expected" style="height: <?php echo max(5, $expected * 30); ?>%;"></div>
                                <div class="seasonal-bar-actual <?php echo $actual > 0 ? 'detected' : ''; ?>" style="height: <?php echo max(5, $expected * 30); ?>%;"></div>
                            </div>
                        <?php endfor; ?>
                    </div>
                    <div class="seasonal-month-labels">
                        <?php foreach($month_initials as $idx => $mi): ?>
                            <?php if ($idx > 0): ?>
                                <div class="seasonal-label-spacer"></div>
                            <?php endif; ?>
                            <div class="seasonal-month-label"><?php echo $mi; ?></div>
                        <?php endforeach; ?>
                    </div>
                </div>
            </div>
<?php
}

function render_seasonal_species_items($species_rows) {
    ob_start();
    foreach($species_rows as $s) {
        render_seasonal_species_item($s);
    }
    return ob_get_clean();
}

if ($subview == 'migration' && isset($_GET['seasonal_batch'])) {
    $seasonal_batch = get_seasonal_presence_batch($db, $seasonal_species_limit, $seasonal_species_offset);
    $seasonal_total_species = get_seasonal_species_total($db);
    $loaded_count = count($seasonal_batch);
    $next_offset = $seasonal_species_offset + $loaded_count;

    header('Content-Type: application/json');
    echo json_encode([
        'html' => render_seasonal_species_items($seasonal_batch),
        'count' => $loaded_count,
        'next_offset' => $next_offset,
        'total' => $seasonal_total_species,
        'has_more' => $loaded_count > 0 && $next_offset < $seasonal_total_species
    ]);
    exit;
}

/* Serve repeat views from the fragment cache. Keyed on the detections
   watermark so any new detection invalidates it automatically; date/hour keep
   "today" sections and weather data fresh; the file mtime invalidates after
   code updates. The seasonal_batch AJAX branch above bypasses this cache.
   The report subview also varies by its period type and anchor date, and is
   rendered by reports.php, so both are part of the key (otherwise weekly,
   monthly, and yearly would collide on one cached entry). */
$report_type = isset($_GET['type']) ? $_GET['type'] : '';
$report_date = isset($_GET['date']) ? $_GET['date'] : '';
$reports_mtime = @filemtime(__ROOT__ . '/scripts/reports.php');
$insights_cache_key = birdnet_cache_key(
    'insights', $subview, $seasonal_species_offset, $migration_list_limit,
    $report_type, $report_date, $reports_mtime,
    date('Y-m-d'), date('G'), detections_watermark(), filemtime(__FILE__)
);
$insights_cached = birdnet_cache_get($insights_cache_key);
if ($insights_cached !== false) {
    echo $insights_cached;
    return;
}
ob_start();

if ($subview == 'dashboard') {
    $lifetime_species = db_query_single_safe($db, 'SELECT COUNT(DISTINCT(Sci_Name)) FROM detections', 0, 'insights lifetime species') ?: 0;
    if ($best_day_row = db_query_one_safe($db, 'SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date ORDER BY cnt DESC LIMIT 1', 'insights best day')) {
        $best_day_count = $best_day_row['cnt'];
        $best_day_date = date('M j, Y', strtotime($best_day_row['Date']));
    }
    $dates = [];
    foreach (insights_query_all($db, 'SELECT Date FROM detections GROUP BY Date ORDER BY Date ASC') as $row) { $dates[] = $row['Date']; }
    $max_s = 0; $cur_s = 0; $prev = null;
    foreach ($dates as $d_str) {
        if ($prev === null) { $cur_s = 1; }
        else { if ((strtotime($d_str) - strtotime($prev)) / 86400 == 1) $cur_s++; else { $max_s = max($max_s, $cur_s); $cur_s = 1; } }
        $prev = $d_str;
    }
    $max_streak = max($max_s, $cur_s);
    $rarest = insights_query_all($db, 'SELECT Com_Name, Sci_Name, COUNT(*) as cnt, MIN(Date) as first_seen, MAX(Date) as last_seen FROM detections GROUP BY Sci_Name HAVING COUNT(*) < 5 ORDER BY cnt ASC, last_seen DESC');
    $rare_total = db_query_single_safe($db, 'SELECT COUNT(*) FROM (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < 5)', 0, 'insights rare total') ?: 0;
    $total_detections = db_query_single_safe($db, 'SELECT COUNT(*) FROM detections', 0, 'insights total detections') ?: 0;
    $first_det = db_query_single_safe($db, 'SELECT MIN(Date) FROM detections', null, 'insights first detection');
    $milestones[] = ["title" => "First Detection", "val" => $first_det ?: 'N/A'];
    $milestones[] = ["title" => "Lifetime Detections", "val" => format_number($total_detections)];
    if ($top_spec_day = db_query_one_safe($db, 'SELECT Com_Name, Date, COUNT(*) as cnt FROM detections GROUP BY Sci_Name, Date ORDER BY cnt DESC LIMIT 1', 'insights single day record')) {
        $milestones[] = ["title" => "Single Day Record", "val" => $top_spec_day['cnt'] . " " . $top_spec_day['Com_Name'] . " on " . date('M j, Y', strtotime($top_spec_day['Date']))];
    }
    // Yard Health Calculation
    $stable_days = db_query_single_safe($db, "SELECT COUNT(*) FROM (SELECT Date FROM detections WHERE Date >= '$one_month_ago' GROUP BY Date HAVING COUNT(*) >= 5)", 0, 'insights stable days') ?: 0;
    $stability_score = ($stable_days / 30) * 20;
    $today_vol = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Date = '$today'", 0, 'insights today volume') ?: 0;
    $avg_30d_vol = db_query_single_safe($db, "SELECT COUNT(*)/30.0 FROM detections WHERE Date >= '$one_month_ago'", 1, 'insights 30d volume') ?: 1;
    $volume_score = min(20, ($today_vol / (max(1, $avg_30d_vol))) * 10);
    $rare_detections = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago' AND Confidence >= 0.85 AND Sci_Name IN (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < (SELECT COUNT(*) FROM detections) * 0.01)", 0, 'insights rare detections') ?: 0;
    $rarity_score = min(20, $rare_detections * 2);
    $s_idx = 0; $s_counts = insights_query_all($db, "SELECT COUNT(*) as cnt FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name");
    $t_30d = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago'", 0, 'insights diversity total') ?: 0;
    if ($t_30d > 0) { foreach($s_counts as $r) { $pi = $r['cnt'] / $t_30d; if ($pi > 0) $s_idx -= $pi * log($pi); } }
    $diversity_pts = min(40, ($s_idx / 3.0) * 40);
    $yard_health_score = round($stability_score + $volume_score + $rarity_score + $diversity_pts);
    if ($yard_health_score > 100) $yard_health_score = 100;
    if ($s_idx < 1.5) $recommendations[] = ["icon" => "🌻", "text" => "Diversity is low. Try adding different types of seed or a water feature to attract more species."];
    if ($stable_days < 20) $recommendations[] = ["icon" => "🎤", "text" => "Multiple quiet days detected. Ensure your microphone is unobstructed and the system is running 24/7."];
    $high_c = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence >= 0.8", 0, 'insights high confidence') ?: 0;
    $med_c = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence >= 0.5 AND Confidence < 0.8", 0, 'insights medium confidence') ?: 0;
    $low_c = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence < 0.5", 0, 'insights low confidence') ?: 0;
    if ($low_c > ($high_c + $med_c)) $recommendations[] = ["icon" => "⚙️", "text" => "High number of low-confidence detections. You might need to adjust your sensitivity or filter out phantom species."];
    if ($today_vol < $avg_30d_vol * 0.5) $recommendations[] = ["icon" => "🌥️", "text" => "Activity is unusually low today. Check if the weather or local disturbances have gone up."];
    if (empty($recommendations)) $recommendations[] = ["icon" => "🌟", "text" => "Your yard is booming! Keep up the great work maintaining a healthy habitat."];
}

if ($subview == 'behavior') {
    $dawn_chorus = [];
    foreach(insights_query_all($db, "SELECT Com_Name, AVG(first_minutes) as avg_minutes, COUNT(*) as cnt FROM (SELECT Com_Name, Sci_Name, MIN(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as first_minutes FROM detections WHERE CAST(substr(Time, 1, 2) AS INTEGER) BETWEEN 4 AND 10 GROUP BY Sci_Name, Date) GROUP BY Sci_Name HAVING COUNT(*) >= 3 ORDER BY avg_minutes ASC") as $row) { $row['avg_time'] = format_minutes_label($row['avg_minutes']); $dawn_chorus[] = $row; }
    $hourly_activity = array_fill(0, 24, 0);
    foreach(insights_query_all($db, "SELECT CAST(substr(Time, 1, 2) AS INTEGER) as hour, COUNT(*) as cnt FROM detections GROUP BY hour ORDER BY hour ASC") as $row) { $hourly_activity[$row['hour']] = $row['cnt']; }
    $hourly_labels_json = json_encode(array_map('format_hour_label', range(0, 23)));
    $hourly_values_json = json_encode(array_values($hourly_activity));
    $peak_hour_idx = array_search(max($hourly_activity), $hourly_activity);
    $peak_hour_label = format_hour_label($peak_hour_idx);
    $peak_hour_count = max($hourly_activity);
    $nocturnal = [];
    foreach(insights_query_all($db, "SELECT Com_Name, COUNT(*) as cnt, AVG(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as avg_minutes FROM detections WHERE CAST(substr(Time, 1, 2) AS INTEGER) >= 22 OR CAST(substr(Time, 1, 2) AS INTEGER) < 4 GROUP BY Sci_Name HAVING COUNT(*) >= 2 ORDER BY cnt DESC") as $row) { $row['avg_time'] = format_minutes_label($row['avg_minutes']); $nocturnal[] = $row; }
    $activity_windows = [];
    foreach(insights_query_all($db, "SELECT Com_Name, MIN(Time) as earliest, MAX(Time) as latest, COUNT(*) as cnt FROM detections GROUP BY Sci_Name HAVING COUNT(*) >= 5 ORDER BY cnt DESC") as $row) { $row['earliest_fmt'] = format_time_label($row['earliest']); $row['latest_fmt'] = format_time_label($row['latest']); $activity_windows[] = $row; }
}

if ($subview == 'migration') {
    $arrival_stmt = $db->prepare("
        WITH recent AS (
            SELECT Com_Name, Sci_Name, MIN(Date) AS first_seen, COUNT(*) AS cnt
            FROM detections
            WHERE Date >= :two_weeks_ago
            GROUP BY Sci_Name
        ),
        previous_window AS (
            SELECT DISTINCT Sci_Name
            FROM detections
            WHERE Date >= :four_weeks_ago AND Date < :two_weeks_ago
        )
        SELECT recent.Com_Name, recent.Sci_Name, recent.first_seen, recent.cnt
        FROM recent
        LEFT JOIN previous_window prev ON prev.Sci_Name = recent.Sci_Name
        WHERE prev.Sci_Name IS NULL
        ORDER BY recent.first_seen DESC
        LIMIT :limit
    ");
    ensure_db_ok($arrival_stmt);
    $arrival_stmt->bindValue(':two_weeks_ago', $two_weeks_ago, SQLITE3_TEXT);
    $arrival_stmt->bindValue(':four_weeks_ago', $four_weeks_ago, SQLITE3_TEXT);
    $arrival_stmt->bindValue(':limit', $migration_list_limit, SQLITE3_INTEGER);
    $arrival_res = db_execute_safe($db, $arrival_stmt, 'insights new arrivals');
    while($row = db_fetch_assoc_safe($arrival_res)) { $new_arrivals[] = $row; }
    $quiet_stmt = $db->prepare("
        SELECT Com_Name, Sci_Name, COUNT(*) AS total_cnt, MAX(Date) AS last_seen
        FROM detections
        GROUP BY Sci_Name
        HAVING COUNT(*) >= 5 AND MAX(Date) < :two_weeks_ago
        ORDER BY last_seen DESC
        LIMIT :limit
    ");
    ensure_db_ok($quiet_stmt);
    $quiet_stmt->bindValue(':two_weeks_ago', $two_weeks_ago, SQLITE3_TEXT);
    $quiet_stmt->bindValue(':limit', $migration_list_limit, SQLITE3_INTEGER);
    $quiet_res = db_execute_safe($db, $quiet_stmt, 'insights quiet species');
    while($row = db_fetch_assoc_safe($quiet_res)) { $row['days_ago'] = intval((time() - strtotime($row['last_seen'])) / 86400); $gone_quiet[] = $row; }
    $cur_yr = date('Y'); $last_yr = $cur_yr - 1;
    $current_year = $cur_yr;
    $last_year = $last_yr;
    $cur_year_start = $cur_yr . '-01-01';
    $next_year_start = ($cur_yr + 1) . '-01-01';
    $last_year_start = $last_yr . '-01-01';
    $cur_year_as_last_end = $cur_yr . '-01-01';
    $yoy_stmt = $db->prepare("
        WITH this_year AS (
            SELECT Com_Name, Sci_Name, MIN(Date) AS first_this_year
            FROM detections
            WHERE Date >= :cur_year_start AND Date < :next_year_start
            GROUP BY Sci_Name
        ),
        prior_year AS (
            SELECT Sci_Name, MIN(Date) AS first_last_year,
                   :cur_yr || substr(MIN(Date), 5) AS first_last_year_adjusted
            FROM detections
            WHERE Date >= :last_year_start AND Date < :cur_year_as_last_end
            GROUP BY Sci_Name
        ),
        comparison AS (
            SELECT this_year.Com_Name, this_year.Sci_Name, this_year.first_this_year, prior_year.first_last_year,
                   CAST(julianday(this_year.first_this_year) - julianday(prior_year.first_last_year_adjusted) AS INTEGER) AS day_diff
            FROM this_year
            INNER JOIN prior_year ON this_year.Sci_Name = prior_year.Sci_Name
        )
        SELECT Com_Name, Sci_Name, first_this_year, first_last_year, day_diff
        FROM comparison
        WHERE day_diff != 0
        ORDER BY ABS(day_diff) DESC
        LIMIT :limit
    ");
    ensure_db_ok($yoy_stmt);
    $yoy_stmt->bindValue(':cur_year_start', $cur_year_start, SQLITE3_TEXT);
    $yoy_stmt->bindValue(':next_year_start', $next_year_start, SQLITE3_TEXT);
    $yoy_stmt->bindValue(':last_year_start', $last_year_start, SQLITE3_TEXT);
    $yoy_stmt->bindValue(':cur_year_as_last_end', $cur_year_as_last_end, SQLITE3_TEXT);
    $yoy_stmt->bindValue(':cur_yr', (string)$cur_yr, SQLITE3_TEXT);
    $yoy_stmt->bindValue(':limit', $migration_list_limit, SQLITE3_INTEGER);
    $yoy_res = db_execute_safe($db, $yoy_stmt, 'insights yoy migration comparison');
    while($row = db_fetch_assoc_safe($yoy_res)) { $yoy_comparison[] = $row; }

    $seasonal_total_species = get_seasonal_species_total($db);
    $seasonal_top = get_seasonal_presence_batch($db, $seasonal_species_limit, 0);
    $monthly_stats = insights_query_all($db, "SELECT * FROM (SELECT strftime('%Y-%m', Date) as month, COUNT(DISTINCT Sci_Name) as diversity, COUNT(*) as detections FROM detections GROUP BY month ORDER BY month DESC LIMIT 24) ORDER BY month ASC");
    $month_labels = json_encode(array_map(function($r) { return $r['month']; }, $monthly_stats));
    $month_div = json_encode(array_map(function($r) { return $r['diversity']; }, $monthly_stats));
    $month_det = json_encode(array_map(function($r) { return $r['detections']; }, $monthly_stats));
    $s_counts = insights_query_all($db, "SELECT COUNT(*) as cnt FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name");
    $t_30d = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago'", 0, 'insights migration diversity total') ?: 0;
    if ($t_30d > 0) { foreach($s_counts as $r) { $pi = $r['cnt'] / $t_30d; if ($pi > 0) $shannon_index -= $pi * log($pi); } }
    $shannon_index = round($shannon_index, 3);
    $diversity_score_text = ($shannon_index > 2.5) ? "Very High" : (($shannon_index > 1.8) ? "High" : (($shannon_index > 1.2) ? "Moderate" : "Low"));
    $this_month_start = date('Y-m-01');
    $next_month_start = date('Y-m-01', strtotime('+1 month', strtotime($this_month_start)));
    $last_year_month_start = date('Y-m-01', strtotime('-1 year', strtotime($this_month_start)));
    $last_year_next_month_start = date('Y-m-01', strtotime('+1 month', strtotime($last_year_month_start)));
    $this_month_diversity = db_query_single_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date >= '$this_month_start' AND Date < '$next_month_start'", 0, 'insights current month diversity') ?: 0;
    $last_year_diversity = db_query_single_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE Date >= '$last_year_month_start' AND Date < '$last_year_next_month_start'", 0, 'insights last year diversity') ?: 0;
    $yoy_diversity_diff = $this_month_diversity - $last_year_diversity;
}

if ($subview == 'environmental') {
    $wmo_codes = [0 => 'Clear sky', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast', 45 => 'Fog', 48 => 'Rime fog', 51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle', 61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain', 71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 80 => 'Slight showers', 81 => 'Moderate showers', 82 => 'Violent showers', 95 => 'Thunderstorm', 96 => 'Thunderstorm + hail', 99 => 'Thunderstorm + heavy hail'];
    $has_weather = (db_query_single_safe($db, "SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'", 0, 'insights weather table exists') > 0) && (db_query_single_safe($db, "SELECT COUNT(*) FROM weather", 0, 'insights weather row count') > 0);
    if ($has_weather) {
        /* Bracket edges live in °F (how weather is stored), but in Celsius
           mode the edges land on exact 5°C steps (32/41/.../86°F = 0/5/.../30°C)
           so the bins are real Celsius bins, not relabeled Fahrenheit ones. */
        if (get_temp_unit() === 'C') {
            $bracket_edges = [32, 41, 50, 59, 68, 77, 86];
            $bracket_labels = ['Below 0°C', '0–4°C', '5–9°C', '10–14°C', '15–19°C', '20–24°C', '25–29°C', 'Above 30°C'];
        } else {
            $bracket_edges = [32, 46, 56, 66, 76, 86, 96];
            $bracket_labels = ['Below 32°F', '32–45°F', '46–55°F', '56–65°F', '66–75°F', '76–85°F', '86–95°F', 'Above 95°F'];
        }
        $bracket_case = "CASE WHEN w.Temp IS NULL THEN 'Unknown'";
        foreach ($bracket_edges as $bi => $edge) {
            $bracket_case .= " WHEN w.Temp < $edge THEN '" . $bracket_labels[$bi] . "'";
        }
        $bracket_case .= " ELSE '" . end($bracket_labels) . "' END";
        $temp_res = db_query_safe($db, "SELECT $bracket_case as bracket, COUNT(*) as det_count, COUNT(DISTINCT d.Sci_Name) as species_count, ROUND(AVG(w.Temp), 1) as avg_temp FROM detections d INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour GROUP BY bracket", 'insights weather temperature brackets');
        $master_brackets = [];
        foreach ($bracket_labels as $bl) {
            $master_brackets[$bl] = ['bracket' => $bl, 'det_count' => 0, 'species_count' => 0];
        }
        while($row = db_fetch_assoc_safe($temp_res)) {
            if (isset($master_brackets[$row['bracket']])) {
                $master_brackets[$row['bracket']] = $row;
            } else if ($row['bracket'] == 'Unknown') {
                $master_brackets['Unknown'] = $row;
            }
        }
        $temp_brackets = array_values($master_brackets);
        $master_conditions = [
            'Clear' => ['emoji' => '☀️', 'det_count' => 0, 'species_count' => 0],
            'Cloudy' => ['emoji' => '☁️', 'det_count' => 0, 'species_count' => 0],
            'Fog' => ['emoji' => '🌫️', 'det_count' => 0, 'species_count' => 0],
            'Rain' => ['emoji' => '🌧️', 'det_count' => 0, 'species_count' => 0],
            'Snow' => ['emoji' => '❄️', 'det_count' => 0, 'species_count' => 0],
            'Thunderstorm' => ['emoji' => '⛈️', 'det_count' => 0, 'species_count' => 0]
        ];

        $cond_res = db_query_safe($db, "SELECT
            CASE 
                WHEN w.ConditionCode = 0 THEN 'Clear'
                WHEN w.ConditionCode BETWEEN 1 AND 3 THEN 'Cloudy'
                WHEN w.ConditionCode IN (45, 48) THEN 'Fog'
                WHEN w.ConditionCode BETWEEN 51 AND 67 OR w.ConditionCode BETWEEN 80 AND 82 THEN 'Rain'
                WHEN w.ConditionCode BETWEEN 71 AND 77 OR w.ConditionCode IN (85, 86) THEN 'Snow'
                WHEN w.ConditionCode BETWEEN 95 AND 99 THEN 'Thunderstorm'
                ELSE 'Cloudy' 
            END as description,
            COUNT(*) as det_count, 
            COUNT(DISTINCT d.Sci_Name) as species_count 
            FROM detections d 
            INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour 
            GROUP BY description", 'insights weather conditions');
        
        while($row = db_fetch_assoc_safe($cond_res)) {
            $desc = $row['description'];
            if (isset($master_conditions[$desc])) {
                $master_conditions[$desc]['det_count'] = $row['det_count'];
                $master_conditions[$desc]['species_count'] = $row['species_count'];
            }
        }
        $condition_impact = $master_conditions;

        // --- Unified Wind Analytics (Speed + Direction) ---
        // Thresholds are fixed in stored mph (5/15/25); only the labels show
        // the configured unit's equivalents.
        $wind_unit = get_wind_unit();
        if ($wind_unit === 'kmh') {
            $wind_labels = ['Calm (0-8)', 'Breezy (9-24)', 'Windy (25-40)', 'Very Windy (40+)'];
        } elseif ($wind_unit === 'ms') {
            $wind_labels = ['Calm (0-2)', 'Breezy (3-7)', 'Windy (8-11)', 'Very Windy (11+)'];
        } else {
            $wind_labels = ['Calm (0-5)', 'Breezy (6-15)', 'Windy (16-25)', 'Very Windy (26-35+)'];
        }
        $wind_case = "CASE
                WHEN w.WindSpeed <= 5 THEN '" . $wind_labels[0] . "'
                WHEN w.WindSpeed <= 15 THEN '" . $wind_labels[1] . "'
                WHEN w.WindSpeed <= 25 THEN '" . $wind_labels[2] . "'
                ELSE '" . $wind_labels[3] . "'
            END";
        $unified_wind = [
            $wind_labels[0] => ['emoji' => '🍃', 'det_count' => 0, 'species_count' => 0, 'cardinals' => []],
            $wind_labels[1] => ['emoji' => '🌬️', 'det_count' => 0, 'species_count' => 0, 'cardinals' => []],
            $wind_labels[2] => ['emoji' => '💨', 'det_count' => 0, 'species_count' => 0, 'cardinals' => []],
            $wind_labels[3] => ['emoji' => '🌪️', 'det_count' => 0, 'species_count' => 0, 'cardinals' => []]
        ];

        // 1. Bracket totals
        $wind_res = db_query_safe($db, "SELECT
            $wind_case as bracket,
            COUNT(*) as det_count,
            COUNT(DISTINCT d.Sci_Name) as species_count
            FROM detections d
            INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour
            WHERE w.WindSpeed IS NOT NULL
            GROUP BY bracket", 'insights weather wind speed');

        if ($wind_res) {
            while($row = db_fetch_assoc_safe($wind_res)) {
                $b = $row['bracket'];
                if (isset($unified_wind[$b])) {
                    $unified_wind[$b]['det_count'] = $row['det_count'];
                    $unified_wind[$b]['species_count'] = $row['species_count'];
                }
            }
        }

        // 2. Cardinal breakdown per bracket
        $nest_res = db_query_safe($db, "SELECT
            $wind_case as bracket,
            CASE 
                WHEN w.WindDirection >= 337.5 OR w.WindDirection < 22.5 THEN 'N'
                WHEN w.WindDirection < 67.5 THEN 'NE'
                WHEN w.WindDirection < 112.5 THEN 'E'
                WHEN w.WindDirection < 157.5 THEN 'SE'
                WHEN w.WindDirection < 202.5 THEN 'S'
                WHEN w.WindDirection < 247.5 THEN 'SW'
                WHEN w.WindDirection < 292.5 THEN 'W'
                ELSE 'NW'
            END as cardinal,
            COUNT(*) as det_count
            FROM detections d 
            INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour 
            WHERE w.WindSpeed IS NOT NULL AND w.WindDirection IS NOT NULL
            GROUP BY bracket, cardinal", 'insights weather wind direction');

        if ($nest_res) {
            $cardinal_emojis = ['N'=>'⬇️','NE'=>'↙️','E'=>'⬅️','SE'=>'↖️','S'=>'⬆️','SW'=>'↗️','W'=>'➡️','NW'=>'↘️'];
            while($row = db_fetch_assoc_safe($nest_res)) {
                $b = $row['bracket'];
                if (isset($unified_wind[$b])) {
                    $unified_wind[$b]['cardinals'][] = [
                        'label' => $row['cardinal'],
                        'emoji' => $cardinal_emojis[$row['cardinal']],
                        'count' => $row['det_count']
                    ];
                }
            }
        }
        $wind_impact = $unified_wind;
        $ideal_res = db_query_safe($db, "SELECT d.Com_Name, ROUND(AVG(w.Temp), 1) as avg_temp, ROUND(MIN(w.Temp), 1) as min_temp, ROUND(MAX(w.Temp), 1) as max_temp, COUNT(*) as cnt FROM detections d INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour GROUP BY d.Sci_Name HAVING cnt >= 5 ORDER BY cnt DESC", 'insights species ideal temperature');
        while($row = db_fetch_assoc_safe($ideal_res)) {
            $row['avg_temp'] = display_temp($row['avg_temp'], 1);
            $row['min_temp'] = display_temp($row['min_temp'], 1);
            $row['max_temp'] = display_temp($row['max_temp'], 1);
            $species_ideal[] = $row;
        }
        $trend_stmt = $db->prepare("
            SELECT d.Date,
                   COUNT(*) as det_count,
                   ROUND(dw.avg_temp, 1) as avg_temp
            FROM detections d
            LEFT JOIN (
                SELECT Date, AVG(Temp) as avg_temp
                FROM weather
                WHERE Temp IS NOT NULL
                GROUP BY Date
            ) dw ON dw.Date = d.Date
            WHERE d.Date >= :one_month_ago
            GROUP BY d.Date, dw.avg_temp
            ORDER BY d.Date ASC
        ");
        ensure_db_ok($trend_stmt);
        $trend_stmt->bindValue(':one_month_ago', $one_month_ago, SQLITE3_TEXT);
        $trend_res = db_execute_safe($db, $trend_stmt, 'insights weather trend');
        $temp_vs_detections = [];
        while($row = db_fetch_assoc_safe($trend_res)) { $temp_vs_detections[] = $row; }
        $temp_trend_labels = json_encode(array_map(function($r) { return date('M j', strtotime($r['Date'])); }, $temp_vs_detections));
        $temp_trend_temps = json_encode(array_map(function($r) { return $r['avg_temp'] === null ? null : display_temp($r['avg_temp'], 1); }, $temp_vs_detections));
        $temp_trend_dets = json_encode(array_map(function($r) { return $r['det_count']; }, $temp_vs_detections));
    }
}

if ($subview == 'health') {
    $confidence_trend = insights_query_all($db, "SELECT Date, ROUND(AVG(Confidence), 3) as avg_conf, COUNT(*) as det_count FROM detections WHERE Date >= '$one_month_ago' GROUP BY Date ORDER BY Date ASC");
    $conf_labels_json = json_encode(array_map(function($r) { return date('M j', strtotime($r['Date'])); }, $confidence_trend));
    $conf_values_json = json_encode(array_map(function($r) { return floatval($r['avg_conf']); }, $confidence_trend));
    $overall_avg_conf = db_query_single_safe($db, "SELECT ROUND(AVG(Confidence), 3) FROM detections", 0, 'insights overall confidence') ?: 0;
    $phantom_species = insights_query_all($db, "SELECT Com_Name, Sci_Name, COUNT(*) as cnt, ROUND(AVG(Confidence), 3) as avg_conf, ROUND(MIN(Confidence), 3) as min_conf FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name HAVING COUNT(*) >= 3 AND AVG(Confidence) < 0.6 ORDER BY avg_conf ASC");
    $avg_daily = db_query_single_safe($db, "SELECT ROUND(AVG(cnt), 1) FROM (SELECT COUNT(*) as cnt FROM detections GROUP BY Date)", 0, 'insights average daily detections') ?: 0;
    $burst_days = insights_query_all($db, "SELECT Date, COUNT(*) as cnt, COUNT(DISTINCT Sci_Name) as species_count FROM detections GROUP BY Date HAVING COUNT(*) > $avg_daily * 1.5 ORDER BY cnt DESC LIMIT 5");
    $silent_days = insights_query_all($db, "SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date HAVING COUNT(*) <= 3 ORDER BY Date DESC LIMIT 5");
    $high_conf_count = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence >= 0.8", 0, 'insights high confidence count') ?: 0;
    $med_conf_count = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence >= 0.5 AND Confidence < 0.8", 0, 'insights medium confidence count') ?: 0;
    $low_conf_count = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Confidence < 0.5", 0, 'insights low confidence count') ?: 0;
}

if ($subview == 'forecasting') {
    $monthly_stats = insights_query_all($db, "SELECT strftime('%Y-%m', Date) as month, COUNT(DISTINCT Sci_Name) as diversity, COUNT(*) as detections FROM detections GROUP BY month ORDER BY month ASC LIMIT 24");
    $month_labels = json_encode(array_map(function($r) { return $r['month']; }, $monthly_stats));
    $month_div = json_encode(array_map(function($r) { return $r['diversity']; }, $monthly_stats));
    $month_det = json_encode(array_map(function($r) { return $r['detections']; }, $monthly_stats));
    
    $t_30d = db_query_single_safe($db, "SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago'", 0, 'insights forecasting diversity total') ?: 0;
    $s_counts = insights_query_all($db, "SELECT COUNT(*) as cnt FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name");
    if ($t_30d > 0) {
        foreach($s_counts as $r) { $pi = $r['cnt'] / $t_30d; if ($pi > 0) $shannon_index -= $pi * log($pi); }
    }
    $shannon_index = round($shannon_index, 3);
    $diversity_score_text = ($shannon_index > 2.5) ? "Very High" : (($shannon_index > 1.8) ? "High" : (($shannon_index > 1.2) ? "Moderate" : "Low"));
    
    $this_month_diversity = db_query_single_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE strftime('%m', Date) = strftime('%m', 'now') AND strftime('%Y', Date) = strftime('%Y', 'now')", 0, 'insights forecasting current month diversity') ?: 0;
    $last_year_diversity = db_query_single_safe($db, "SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE strftime('%m', Date) = strftime('%m', 'now') AND strftime('%Y', Date) = strftime('%Y', 'now', '-1 year')", 0, 'insights forecasting last year diversity') ?: 0;
    $yoy_diversity_diff = $this_month_diversity - $last_year_diversity;
    $current_month_name = date('F');
    $yoy_diversity_pct = $last_year_diversity > 0 ? round(($yoy_diversity_diff / $last_year_diversity) * 100) : 0;

    // The +-3-day window wraps the year boundary in late December / early
    // January, where a plain BETWEEN (lower > upper) matches nothing. Local
    // time throughout: the Date column stores local dates. "History" means
    // strictly before the window itself - a plain year comparison would count
    // last week's late-December birds as prior-year history on Jan 1-4.
    $doy_lo = sprintf('%03d', (int)date('z', strtotime('-3 days')) + 1);
    $doy_hi = sprintf('%03d', (int)date('z', strtotime('+3 days')) + 1);
    $doy_filter = $doy_lo <= $doy_hi
        ? "strftime('%j', Date) BETWEEN '$doy_lo' AND '$doy_hi'"
        : "(strftime('%j', Date) >= '$doy_lo' OR strftime('%j', Date) <= '$doy_hi')";
    $expected_today = insights_query_all($db, "SELECT Com_Name, Sci_Name, COUNT(DISTINCT strftime('%Y', Date)) as years_present FROM detections WHERE $doy_filter AND Date < date('now','localtime','-3 days') GROUP BY Sci_Name ORDER BY years_present DESC");

    // One grouped pass instead of a per-species peak-week query (unbounded N+1)
    $peak_rows = insights_query_all($db, "SELECT Sci_Name, Com_Name, week, cnt FROM (SELECT Sci_Name, Com_Name, strftime('%W', Date) AS week, COUNT(*) AS cnt, SUM(COUNT(*)) OVER (PARTITION BY Sci_Name) AS total, ROW_NUMBER() OVER (PARTITION BY Sci_Name ORDER BY COUNT(*) DESC) AS rn FROM detections GROUP BY Sci_Name, week) WHERE rn = 1 ORDER BY total DESC");
    foreach($peak_rows as $row) { $peak_species[] = ['Sci_Name' => $row['Sci_Name'], 'Com_Name' => $row['Com_Name'], 'peak_week' => $row['week'], 'peak_count' => $row['cnt']]; }
}

/* ===== Plain-English takeaways (Phase 4b) =====
   Each subview opens with a few sentences answering "so what does this
   mean?", computed from the data gathered above. Notability-gated: a line
   only appears when there is something worth saying. */
function render_takeaways($takeaways) {
    if (empty($takeaways)) {
        return;
    }
    echo '<div class="takeaways-card"><div class="takeaways-title">' . nav_icon('zap') . ' In plain English</div><ul class="takeaways-list">';
    foreach ($takeaways as $t) {
        echo '<li>' . $t . '</li>';
    }
    echo '</ul></div>';
}

$takeaways = [];
if ($subview == 'dashboard') {
    if ($yard_health_score >= 75) {
        $takeaways[] = 'Your yard is thriving: a health score of <strong>' . (int)$yard_health_score . '/100</strong>, built from diversity, steady daily activity, rarity, and station stability.';
    } elseif ($yard_health_score >= 50) {
        $takeaways[] = 'Your yard is doing fine — health score <strong>' . (int)$yard_health_score . '/100</strong> — with room to grow; the recommendations below show where.';
    } else {
        $takeaways[] = 'The health score is low right now (<strong>' . (int)$yard_health_score . '/100</strong>). Quiet days or low diversity usually explain it — see the recommendations below.';
    }
    if ((int)$best_day_count > 0) {
        $takeaways[] = 'Busiest day ever: <strong>' . format_number((int)$best_day_count) . ' detections</strong> on ' . h($best_day_date) . '.';
    }
    if ((int)$max_streak >= 7) {
        $takeaways[] = 'Longest unbroken run of daily detections: <strong>' . (int)$max_streak . ' days</strong>.';
    }
    if ((int)$rare_total > 0) {
        $takeaways[] = (int)$rare_total . ' species have fewer than 5 lifetime detections — the rare-visitor list below is where new yard birds first show up.';
    }
} elseif ($subview == 'behavior') {
    if (!empty($dawn_chorus)) {
        $takeaways[] = '<strong>' . h($dawn_chorus[0]['Com_Name']) . '</strong> opens your dawn chorus, first heard around ' . h($dawn_chorus[0]['avg_time']) . ' on a typical morning.';
    }
    if ($peak_hour_count > 0) {
        $takeaways[] = 'Your yard is loudest around <strong>' . h($peak_hour_label) . '</strong>.';
    }
    if (!empty($nocturnal)) {
        $takeaways[] = 'Night shift: <strong>' . count($nocturnal) . ' species</strong> active between 10 PM and 4 AM, led by ' . h($nocturnal[0]['Com_Name']) . '.';
    } else {
        $takeaways[] = 'No regular night-time activity on record — normal unless owls or nightjars live nearby.';
    }
} elseif ($subview == 'migration') {
    if (!empty($new_arrivals)) {
        $takeaways[] = '<strong>' . count($new_arrivals) . ' species arrived</strong> in the last two weeks after being absent — most recently ' . h($new_arrivals[0]['Com_Name']) . '.';
    }
    if (!empty($gone_quiet)) {
        $takeaways[] = '<strong>' . h($gone_quiet[0]['Com_Name']) . '</strong> has gone quiet: last heard ' . (int)$gone_quiet[0]['days_ago'] . ' days ago after ' . format_number((int)$gone_quiet[0]['total_cnt']) . ' detections'
            . (count($gone_quiet) > 1 ? ' — ' . count($gone_quiet) . ' regulars are on this list' : '') . '.';
    }
    if (!empty($yoy_comparison)) {
        $yoy_first = $yoy_comparison[0];
        $takeaways[] = 'Biggest schedule change vs last year: <strong>' . h($yoy_first['Com_Name']) . '</strong> first appeared ' . abs((int)$yoy_first['day_diff']) . ' days ' . ((int)$yoy_first['day_diff'] < 0 ? 'earlier' : 'later') . ' this year.';
    }
    if ($shannon_index > 0) {
        $div_trend = $yoy_diversity_diff > 0 ? 'up ' . (int)$yoy_diversity_diff . ' species' : ($yoy_diversity_diff < 0 ? 'down ' . abs((int)$yoy_diversity_diff) . ' species' : 'level');
        $takeaways[] = '30-day diversity is <strong>' . h($diversity_score_text) . '</strong> (Shannon index ' . h($shannon_index) . '), ' . $div_trend . ' compared with this month last year.';
    }
} elseif ($subview == 'environmental') {
    if (!$has_weather) {
        $takeaways[] = 'No weather data yet — the station syncs hourly from Open-Meteo once it has internet access.';
    } else {
        $best_bracket = null;
        foreach ((array)$temp_brackets as $tb) {
            if ((int)($tb['det_count'] ?? 0) > 0 && ($best_bracket === null || $tb['det_count'] > $best_bracket['det_count'])) {
                $best_bracket = $tb;
            }
        }
        if ($best_bracket) {
            $takeaways[] = 'Your birds are most vocal in the <strong>' . h($best_bracket['bracket']) . '</strong> range (' . format_number((int)$best_bracket['det_count']) . ' detections recorded there).';
        }
        $best_cond_name = '';
        $best_cond_count = 0;
        foreach ((array)$condition_impact as $cond_name => $cond) {
            if (is_array($cond) && (int)($cond['det_count'] ?? 0) > $best_cond_count) {
                $best_cond_count = (int)$cond['det_count'];
                $best_cond_name = (string)$cond_name;
            }
        }
        if ($best_cond_name !== '') {
            $takeaways[] = 'Most singing happens in <strong>' . h($best_cond_name) . '</strong> conditions.';
        }
    }
} elseif ($subview == 'health') {
    if ($overall_avg_conf > 0) {
        $conf_pct = round($overall_avg_conf * 100);
        $conf_quality = $conf_pct >= 75 ? 'strong' : ($conf_pct >= 60 ? 'reasonable' : 'weak');
        $takeaways[] = 'Average ID confidence across every detection is <strong>' . $conf_pct . '%</strong> — ' . $conf_quality . ' for a backyard station.';
    }
    if (!empty($phantom_species)) {
        $takeaways[] = '<strong>' . count($phantom_species) . ' species</strong> show consistently low confidence this month — usually distant birds or misidentifications; repeat offenders are routed to Review automatically.';
    }
    if (!empty($silent_days)) {
        $takeaways[] = count($silent_days) . ' near-silent day' . (count($silent_days) === 1 ? '' : 's') . ' on record (3 or fewer detections) — typically storms or station downtime.';
    }
} elseif ($subview == 'forecasting') {
    if (!empty($expected_today)) {
        $expected_names = array_slice(array_map(function ($r) { return $r['Com_Name']; }, $expected_today), 0, 3);
        $takeaways[] = 'Based on previous years, expect <strong>' . h(implode(', ', $expected_names)) . '</strong> around this date.';
    }
    if (isset($current_month_name) && $last_year_diversity > 0 && $yoy_diversity_diff != 0) {
        $takeaways[] = h($current_month_name) . ' is running <strong>' . abs((int)$yoy_diversity_diff) . ' species ' . ($yoy_diversity_diff >= 0 ? 'ahead of' : 'behind') . '</strong> the same month last year.';
    }
}

$db->close();
?>

<style>
    .insights-container {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
        color: var(--text-primary);
    }
    .insights-header {
        text-align: left;
        margin-bottom: 30px;
        background: var(--bg-card);
        padding: 30px 40px;
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
        display: flex;
        justify-content: space-between;
        align-items: center;
        flex-wrap: wrap;
        gap: 20px;
    }
    .insights-header h1 {
        margin: 0;
        font-size: 2.2em;
        background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .insights-subtitle {
        color: var(--text-secondary);
        font-size: 1.1em;
        margin-top: 10px;
    }
    .insights-kpi-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 40px;
        width: 100%;
    }
    .insights-kpi-card {
        background: var(--bg-card);
        padding: 24px 15px;
        border-radius: 16px;
        border: 1px solid var(--border);
        /* overflow: hidden; removed to allow tooltips to show */
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: all 0.3s cubic-bezier(0.4, 0, 0.2, 1);
        flex: 1 1 180px;
        min-width: 180px;
        backdrop-filter: blur(8px);
        overflow: visible !important;
    }
    .insights-kpi-card:hover { 
        transform: translateY(-8px); 
        box-shadow: var(--shadow-lg);
        border-color: var(--accent);
    }
    .insights-kpi-val { font-size: 2.2em; font-weight: 900; display: block; margin-bottom: 4px; white-space: nowrap; }
    .insights-kpi-label { font-size: 0.75em; text-transform: uppercase; letter-spacing: 1.2px; color: var(--text-muted); font-weight: 700; }

    .insights-sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(380px, 1fr));
        gap: 30px;
    }
    .insights-section {
        background: var(--bg-card);
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        transition: all 0.3s ease;
        overflow: visible !important;
        display: flex;
        flex-direction: column;
        height: 100%;
    }
    .insights-section:hover {
        box-shadow: var(--shadow-md);
        border-color: rgba(99, 102, 241, 0.3);
    }
    .insights-section-title {
        background: var(--bg-primary);
        padding: 18px 25px;
        font-weight: 800;
        border-bottom: 1px solid var(--border);
        font-size: 1.05em;
        display: flex;
        align-items: center;
        gap: 12px;
        color: var(--text-heading);
        letter-spacing: -0.02em;
    }
    .insights-stats-list { 
        display: flex; 
        flex-direction: column; 
        gap: 10px; 
        padding: 20px; 
        flex-grow: 1;
    }
    .insights-stats-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 14px 18px;
        background: var(--bg-primary);
        border-radius: 14px;
        border: 1px solid var(--border-light);
        transition: all 0.2s ease;
    }
    .insights-stats-item:hover {
        background: var(--bg-card);
        border-color: var(--accent);
        transform: scale(1.02);
    }
    .insights-stats-name { font-weight: 600; color: var(--text-heading); font-size: 0.95em; }
    .insights-stats-count { font-weight: 800; color: var(--accent); font-family: 'JetBrains Mono', monospace; }

    /* Info Tooltip Styles */
    .info-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        background: var(--accent-subtle);
        color: var(--accent);
        border-radius: 50%;
        font-size: 11px;
        font-weight: 800;
        cursor: help;
        margin-left: 8px;
        vertical-align: middle;
        transition: all 0.2s ease;
        position: relative;
    }
    .info-btn:hover {
        background: var(--accent);
        color: white;
    }
    .info-tooltip {
        position: absolute;
        bottom: 125%;
        left: 50%;
        transform: translateX(-50%);
        background: #1e293b;
        color: #f8fafc;
        padding: 12px 16px;
        border-radius: 10px;
        font-size: 13px;
        line-height: 1.4;
        width: 220px;
        max-width: 80vw;
        white-space: normal;
        word-wrap: break-word;
        box-shadow: var(--shadow-lg);
        pointer-events: none;
        opacity: 0;
        transition: all 0.2s ease;
        z-index: 9999; /* Ensure it's above everything */
        font-weight: 400;
        text-align: left;
        text-transform: none;
        letter-spacing: normal;
    }
    .info-tooltip::after {
        content: '';
        position: absolute;
        top: 100%;
        left: 50%;
        margin-left: -5px;
        border-width: 5px;
        border-style: solid;
        border-color: #1e293b transparent transparent transparent;
    }
    .info-btn:hover .info-tooltip {
        opacity: 1;
        bottom: 140%;
    }
    
    /* Seasonal Bar Charts */
    .seasonal-bars-container {
        display: flex;
        gap: 2px;
        align-items: flex-end;
        height: 48px;
        background: var(--bg-primary);
        padding: 4px 6px;
        border-radius: 8px;
        border: 1px solid var(--border-light);
        position: relative;
    }
    .seasonal-bar-wrap {
        flex: 1;
        height: 100%;
        display: flex;
        align-items: flex-end;
        position: relative;
    }
    .seasonal-bar-expected {
        width: 100%;
        background: var(--text-muted);
        opacity: 0.25; /* Highly subtle for baseline effect */
        border-radius: 1px;
        transition: height 0.3s ease;
    }
    .seasonal-bar-actual {
        position: absolute;
        bottom: 0;
        left: 0;
        width: 100%;
        background: var(--accent);
        border-radius: 1px;
        opacity: 0;
        transition: opacity 0.2s ease;
        z-index: 2;
    }
    .seasonal-bar-actual.detected {
        opacity: 1;
    }
    .seasonal-month-divider {
        width: 1px;
        height: 100%;
        background: var(--border);
        margin: 0 1px;
        opacity: 0.5;
    }
    .seasonal-month-labels {
        display: flex;
        gap: 2px;
        margin-top: 4px;
        padding: 0 6px;
    }
    .seasonal-month-label {
        flex: 1;
        text-align: center;
        font-size: 0.6em;
        font-weight: 700;
        color: var(--text-muted);
        text-transform: uppercase;
    }
    .seasonal-label-spacer {
        width: 1px;
        margin: 0 1px;
    }
    @media (max-width: 768px) {
        .info-tooltip {
            left: auto;
            right: -20px;
            transform: none;
            width: 200px;
        }
        .info-tooltip::after {
            left: auto;
            right: 25px;
        }
    }

    @media print {
        .navbar, .sidebar, button { display: none !important; }
        .insights-container { width: 100% !important; max-width: none !important; margin: 0 !important; padding: 0 !important; }
        .insights-section { break-inside: avoid; border: 1px solid #eee !important; box-shadow: none !important; }
        body { background: white !important; color: black !important; }
    }
    .hidden-item { display: none !important; }
    .show-list-btn {
        width: 100%;
        padding: 12px;
        background: var(--bg-card);
        border: 1px solid var(--border-light);
        border-bottom-left-radius: 20px;
        border-bottom-right-radius: 20px;
        color: var(--accent);
        font-weight: 700;
        cursor: pointer;
        transition: all 0.2s ease;
        font-size: 0.9em;
        text-transform: uppercase;
        letter-spacing: 0.5px;
    }
    .show-list-btn:hover { background: var(--accent-subtle); color: var(--accent); }
    .show-list-btn:disabled { cursor: wait; opacity: 0.65; }
</style>

<div class="insights-container">
    <header class="insights-header">
        <div>
            <h1>Insights: <?php 
                if ($subview == 'dashboard') echo 'Dashboard';
                elseif ($subview == 'environmental') echo 'Weather Impacts';
                elseif ($subview == 'report') echo 'Reports';
                elseif ($subview == 'forecasting') echo 'Trends & Forecasting';
                else echo h(ucfirst($subview));
            ?></h1>
            <div class="insights-subtitle">
                <?php
                switch($subview) {
                    case 'behavior': echo 'Daily activity patterns and behavioral analysis.'; break;
                    case 'migration': echo 'Seasonal trends and migration tracking.'; break;
                    case 'environmental': echo 'Correlations between weather and bird activity.'; break;
                    case 'health': echo 'Data quality and system health.'; break;
                    case 'forecasting': echo 'Long-term biodiversity trends and historical predictions.'; break;
                    case 'report': echo 'Comprehensive summaries across weekly, monthly, and yearly periods.'; break;
                    default: echo 'Deep behavioral analysis and seasonal trends for your station.'; break;
                }
                ?>
            </div>
        </div>
        <button onclick="window.print()" style="background: var(--bg-card); color: var(--text-primary); border: 1px solid var(--border); padding: 10px 20px; border-radius: 12px; cursor: pointer; display: flex; align-items: center; gap: 8px; font-weight: 600; transition: all 0.2s;">
            <span>🖨️</span> Print Report
        </button>
    </header>

    <?php if ($subview == 'dashboard'): ?>
    <?php render_takeaways($takeaways); ?>
    <!-- ====== PHASE 9: Yard Health Score Hero ====== -->
    <section class="insights-section" style="margin-bottom: 30px; border: none; background: linear-gradient(135deg, rgba(99, 102, 241, 0.08) 0%, rgba(16, 185, 129, 0.08) 100%); border: 1px solid var(--border-light);">
        <div style="display: flex; flex-wrap: wrap; align-items: center; padding: 30px; gap: 40px;">
            <!-- Score Ring -->
            <div style="position: relative; width: 150px; height: 150px; flex-shrink: 0;">
                <svg viewBox="0 0 36 36" style="width: 100%; height: 100%; transform: rotate(-90deg); filter: drop-shadow(0 0 8px rgba(99, 102, 241, 0.2));">
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="var(--border)" stroke-width="2.5" />
                    <path d="M18 2.0845 a 15.9155 15.9155 0 0 1 0 31.831 a 15.9155 15.9155 0 0 1 0 -31.831" fill="none" stroke="<?php echo $yard_health_score >= 80 ? '#10b981' : ($yard_health_score >= 50 ? '#f59e0b' : '#ef4444'); ?>" stroke-width="2.5" stroke-dasharray="<?php echo $yard_health_score; ?>, 100" stroke-linecap="round" transition="stroke-dasharray 1s ease" />
                </svg>
                <div style="position: absolute; top: 50%; left: 50%; transform: translate(-50%, -50%); text-align: center;">
                    <div style="font-size: 2.2em; font-weight: 900; color: var(--text-heading); line-height: 1;"><?php echo $yard_health_score; ?><span class="info-btn">i<span class="info-tooltip">A weighted index (0-100) calculated from station stability, detection volume, species rarity, and biodiversity.</span></span></div>
                    <div style="font-size: 0.7em; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-top: 4px;">Yard Score</div>
                </div>
            </div>

            <!-- Recommendations -->
            <div style="flex: 1 1 300px;">
                <h3 style="margin: 0 0 15px; font-size: 1.3em; color: var(--text-heading);">🏡 Habitat Insights & Recommendations <span class="info-btn">i<span class="info-tooltip" style="width: 300px;"><strong>Diagnostic Scan Results:</strong><br><br>• <strong>Diversity</strong>: Shannon Index (Variety & Evenness)<br>• <strong>Stability</strong>: Active vs Quiet days (last 30d)<br>• <strong>Quality</strong>: High vs Low confidence detections<br>• <strong>Activity</strong>: Current vs Recent history trends<br><br>Yard Health is a weighted average of these 4 pillars.</span></span></h3>
                <div style="display: flex; flex-direction: column; gap: 10px;">
                    <?php foreach($recommendations as $rec): ?>
                    <div style="display: flex; gap: 15px; align-items: center; background: var(--bg-card); padding: 12px 18px; border-radius: 12px; border: 1px solid var(--border-light); box-shadow: var(--shadow-sm);">
                        <span style="font-size: 1.4em;"><?php echo $rec['icon']; ?></span>
                        <div style="font-size: 0.95em; line-height: 1.4; color: var(--text-secondary);"><?php echo $rec['text']; ?></div>
                    </div>
                    <?php endforeach; ?>
                </div>
            </div>
        </div>
    </section>

    <div class="insights-kpi-cards">
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo format_number($lifetime_species); ?><span class="info-btn">i<span class="info-tooltip">The total count of unique bird species identified since station installation.</span></span></span>
            <span class="insights-kpi-label">Lifetime Species</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo format_number($best_day_count); ?></span>
            <span class="insights-kpi-label">Best Day (<?php echo $best_day_date; ?>)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo $max_streak; ?> Days</span>
            <span class="insights-kpi-label">Longest Streak</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo format_number($rare_total); ?><span class="info-btn">i<span class="info-tooltip">Species with fewer than 5 total detections at your station since installation.</span></span></span>
            <span class="insights-kpi-label">Rare Species</span>
        </div>
    </div>

    <div class="insights-sections-grid">
        <section class="insights-section">
            <div class="insights-section-title">🏆 Personal Records & Milestones</div>
            <div class="insights-stats-list">
                <?php foreach($milestones as $m): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name"><?php echo $m['title']; ?></span>
                    <span class="insights-stats-count"><?php echo $m['val']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="insights-section">
            <div class="insights-section-title">💎 Rarest Detections (&lt; 5 ever) <span class="info-btn">i<span class="info-tooltip">These species are vagrants or potential misidentifications that have appeared very infrequently at your station.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($rarest)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No rare species detected yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank_r = 1; foreach($rarest as $r): ?>
                <div class="insights-stats-item <?php echo $rank_r > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $r['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">First seen: <?php echo date('M j, Y', strtotime($r['first_seen'] ?? '')); ?> · Last: <?php echo date('M j, Y', strtotime($r['last_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count"><?php echo $r['cnt']; ?>x</span>
                </div>
                <?php $rank_r++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($rarest) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($rarest); ?> rare species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($rarest); ?> rare species ↓
            </button>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>

    <?php if ($subview == 'behavior'): ?>
    <?php render_takeaways($takeaways); ?>
    <!-- ====== PHASE 2: Daily Behavior Patterns ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🕐 Daily Behavior Patterns</h2>

    <!-- Hourly Activity Chart -->
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📊 Hourly Activity Distribution <span style="margin-left: auto; font-weight: normal; font-size: 0.85em; color: var(--text-muted);">Peak: <?php echo $peak_hour_label; ?> (<?php echo format_number($peak_hour_count); ?> detections)</span></div>
        <div style="padding: 20px;">
            <canvas id="hourlyActivityChart" height="100"></canvas>
        </div>
    </section>

    <div class="insights-sections-grid">
        <!-- Dawn Chorus Order -->
        <section class="insights-section">
            <div class="insights-section-title">🌅 Dawn Chorus Order (4 AM – 10 AM) <span class="info-btn">i<span class="info-tooltip">Species are ranked by their average "wake up" time (the first time they are heard each day between 4 AM and 10 AM). At least 3 days of dawn data are required for a bird to be ranked.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($dawn_chorus)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough dawn data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank = 1; foreach($dawn_chorus as $d): ?>
                <div class="insights-stats-item <?php echo $rank > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;">
                            <span style="color: var(--accent); font-weight: 800; margin-right: 6px;">#<?php echo $rank; ?></span>
                            <?php echo $d['Com_Name']; ?>
                        </div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $d['cnt']; ?> morning detections</div>
                    </div>
                    <span class="insights-stats-count">~<?php echo $d['avg_time']; ?></span>
                </div>
                <?php $rank++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($dawn_chorus) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($dawn_chorus); ?> species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($dawn_chorus); ?> species ↓
            </button>
            <?php endif; ?>
        </section>

        <!-- Nocturnal Detections -->
        <section class="insights-section">
            <div class="insights-section-title">🦉 Nocturnal Activity (10 PM – 4 AM) <span class="info-btn">i<span class="info-tooltip">Bird activity detected during night hours. Includes owls, nightjars, and some late-night or early-morning songsters.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($nocturnal)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No regular night-time visitors yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank_n = 1; foreach($nocturnal as $n): ?>
                <div class="insights-stats-item <?php echo $rank_n > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $n['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Avg time: <?php echo $n['avg_time']; ?></div>
                    </div>
                    <span class="insights-stats-count"><?php echo $n['cnt']; ?>x</span>
                </div>
                <?php $rank_n++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($nocturnal) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($nocturnal); ?> species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($nocturnal); ?> species ↓
            </button>
            <?php endif; ?>
        </section>
    </div>

    <!-- Activity Windows -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">⏱️ Activity Windows (Top Species) <span class="info-btn">i<span class="info-tooltip">The typical earliest and latest times a species is active at your station. Only species with 5+ detections are included to ensure reliable timing data.</span></span></div>
        <div class="insights-stats-list">
            <?php if(empty($activity_windows)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough detections yet &mdash; this fills in as your station listens</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php $rank_aw = 1; foreach($activity_windows as $w): ?>
            <div class="insights-stats-item <?php echo $rank_aw > 10 ? 'hidden-item' : ''; ?>">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $w['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo format_number($w['cnt']); ?> total detections</div>
                </div>
                <span class="insights-stats-count" style="font-size: 0.9em;"><?php echo $w['earliest_fmt']; ?> → <?php echo $w['latest_fmt']; ?></span>
            </div>
            <?php $rank_aw++; endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if(count($activity_windows) > 10): ?>
        <button class="show-list-btn" 
                onclick="toggleItems(this)" 
                data-expanded="false" 
                data-show-text="Show all <?php echo count($activity_windows); ?> species ↓" 
                data-hide-text="Show top 10 species ↑">
            Show all <?php echo count($activity_windows); ?> species ↓
        </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($subview == 'migration'): ?>
    <?php render_takeaways($takeaways); ?>
    <!-- ====== PHASE 3: Migration & Seasonal Patterns ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🦅 Migration & Seasonal Patterns</h2>

    <div class="insights-sections-grid">
        <!-- New Arrivals -->
        <section class="insights-section">
            <div class="insights-section-title">🆕 New Arrivals (Last 14 Days) <span class="info-btn">i<span class="info-tooltip">Species appearing in the last 14 days that were absent for the previous 2 weeks. Useful for tracking return migration.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($new_arrivals)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No new or returning species in the last 2 weeks</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank_na = 1; foreach($new_arrivals as $a): ?>
                <div class="insights-stats-item <?php echo $rank_na > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo h($a['Com_Name']); ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">First seen: <?php echo date('M j', strtotime($a['first_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count" style="color: #10b981;"><?php echo format_number($a['cnt']); ?> detections</span>
                </div>
                <?php $rank_na++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($new_arrivals) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($new_arrivals); ?> new arrivals ↓" 
                    data-hide-text="Show top 10 ↑">
                Show all <?php echo count($new_arrivals); ?> new arrivals ↓
            </button>
            <?php endif; ?>
        </section>

        <!-- Gone Quiet -->
        <section class="insights-section">
            <div class="insights-section-title">🔇 Gone Quiet <span class="info-btn">i<span class="info-tooltip">Regular residents that haven't been detected in at least 14 days. This may indicate they have migrated away or changed territories.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($gone_quiet)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">All regular species still active!</span>
                    <span class="insights-stats-count">✓</span>
                </div>
                <?php else: ?>
                <?php $rank_gq = 1; foreach($gone_quiet as $q): ?>
                <div class="insights-stats-item <?php echo $rank_gq > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo h($q['Com_Name']); ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo format_number($q['total_cnt']); ?> total · Last: <?php echo date('M j', strtotime($q['last_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count" style="color: #ef4444;"><?php echo $q['days_ago']; ?>d ago</span>
                </div>
                <?php $rank_gq++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($gone_quiet) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($gone_quiet); ?> inactive species ↓" 
                    data-hide-text="Show top 10 ↑">
                Show all <?php echo count($gone_quiet); ?> inactive species ↓
            </button>
            <?php endif; ?>
        </section>
    </div>

    <!-- Year-over-Year Comparison -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">📅 Year-over-Year Arrival Comparison (<?php echo $last_year; ?> vs <?php echo $current_year; ?>) <span class="info-btn">i<span class="info-tooltip">Tracking if migratory species arrived earlier or later than they did in the previous calendar year.</span></span></div>
        <div class="insights-stats-list">
            <?php if(empty($yoy_comparison)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough multi-year data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php $rank_yoy = 1; foreach($yoy_comparison as $y): ?>
            <div class="insights-stats-item <?php echo $rank_yoy > 10 ? 'hidden-item' : ''; ?>">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo h($y['Com_Name']); ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);">
                        <?php echo $cur_yr; ?>: <?php echo date('M j', strtotime($y['first_this_year'])); ?>
                        · <?php echo $last_year; ?>: <?php echo date('M j', strtotime($y['first_last_year'])); ?>
                    </div>
                </div>
                <?php
                    $diff = $y['day_diff'];
                    if ($diff < 0) {
                        $color = '#10b981';
                        $label = abs($diff) . 'd earlier';
                    } else {
                        $color = '#ef4444';
                        $label = $diff . 'd later';
                    }
                ?>
                <span class="insights-stats-count" style="color: <?php echo h($color); ?>;"><?php echo h($label); ?></span>
            </div>
            <?php $rank_yoy++; endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if(count($yoy_comparison) > 10): ?>
        <button class="show-list-btn" 
                onclick="toggleItems(this)" 
                data-expanded="false" 
                data-show-text="Show all <?php echo count($yoy_comparison); ?> comparisons ↓" 
                data-hide-text="Show top 10 ↑">
            Show all <?php echo count($yoy_comparison); ?> comparisons ↓
        </button>
        <?php endif; ?>
    </section>

    <!-- Seasonal Presence -->
    <section class="insights-section" style="margin-top: 30px;">
        <?php $seasonal_loaded_count = count($seasonal_top); ?>
        <div class="insights-section-title">🗓️ Seasonal Presence <span class="info-btn">i<span class="info-tooltip" style="width: 340px;"><strong>Classification Guide:</strong><br>• <strong>Year-round:</strong> Detected across 9+ months<br>• <strong>Seasonal:</strong> Detected across 3-8 months<br>• <strong>Transient:</strong> Detected &lt;3 months<br><br><strong>Data Accuracy Disclaimer:</strong><br>These classifications may be inaccurate if the station has been running for less than a full year, as it lacks seasonal historical context.<br><br>Bar <strong>height</strong> is expected frequency. <strong>Purple highlights</strong> are actual detections.</span></span></div>
        <div class="insights-stats-list" id="seasonal-presence-list">
            <?php if(empty($seasonal_top)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough detections yet &mdash; this fills in as your station listens</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php echo render_seasonal_species_items($seasonal_top); ?>
            <?php endif; ?>
        </div>
        <?php if($seasonal_total_species > $seasonal_loaded_count): ?>
        <button class="show-list-btn"
                id="seasonal-load-more"
                onclick="loadSeasonalBatch(this)"
                data-offset="<?php echo $seasonal_loaded_count; ?>"
                data-limit="<?php echo $seasonal_batch_size; ?>"
                data-total="<?php echo $seasonal_total_species; ?>">
            Load <?php echo format_number(min($seasonal_batch_size, $seasonal_total_species - $seasonal_loaded_count)); ?> more species ↓
        </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>

    <?php if ($subview == 'environmental'): ?>
    <?php render_takeaways($takeaways); ?>
    <?php if (!$has_weather): ?>
        <div style="text-align: center; padding: 100px 20px; color: var(--text-muted);">
            <div style="font-size: 4em; margin-bottom: 20px;">🌤️</div>
            <h2>No weather data yet</h2>
            <p>Weather syncs automatically every hour once your station's latitude and longitude are set in <a href="?view=Settings">Settings</a>.</p>
            <p style="font-size: 0.9em;">First data usually appears within the hour &mdash; <a href="?view=Doctor">Station Doctor</a> shows the sync status. Correlations get more interesting after a few days of history.</p>
        </div>
    <?php else: ?>
    <!-- ====== PHASE 4: Weather Correlations ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🌤️ Weather Correlations</h2>

    <!-- Temp vs Detections Chart -->
    <?php if(!empty($temp_vs_detections)): ?>
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📈 Temperature vs Detections (Last 30 Days) <span class="info-btn">i<span class="info-tooltip">A day-by-day comparison of average ambient temperature against the total number of bird detections.</span></span></div>
        <div style="padding: 20px;">
            <canvas id="tempVsDetChart" height="120"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <div class="insights-sections-grid">
        <!-- Temperature Brackets -->
        <section class="insights-section">
            <div class="insights-section-title">🌡️ Detections by Temperature <span class="info-btn">i<span class="info-tooltip">Grouping activity into temperature brackets to show if your birds are more active in the heat or the cold.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($temp_brackets)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough weather-matched data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($temp_brackets as $t): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $t['bracket']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">
                            <?php echo $t['det_count'] > 0 ? $t['species_count'] . ' species active' : 'No species recorded'; ?>
                        </div>
                    </div>
                    <span class="insights-stats-count"><?php echo $t['det_count'] > 0 ? format_number($t['det_count']) : '0'; ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Weather Conditions -->
        <section class="insights-section">
            <div class="insights-section-title">☁️ Detections by Weather Condition <span class="info-btn">i<span class="info-tooltip">Correlating bird activity with specific conditions like rain, fog, or clear skies.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($condition_impact)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough weather data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($condition_impact as $desc => $c): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $c['emoji']; ?> <?php echo $desc; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">
                            <?php echo $c['det_count'] > 0 ? $c['species_count'] . ' species active' : 'No species recorded'; ?>
                        </div>
                    </div>
                    <span class="insights-stats-count"><?php echo $c['det_count'] > 0 ? format_number($c['det_count']) : '0'; ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Unified Wind Trends -->
        <section class="insights-section" style="grid-column: 1 / -1; margin-top: 10px;">
            <div class="insights-section-title">🌬️ Detections by Wind Speed & Direction <span class="info-btn">i<span class="info-tooltip">Correlates bird detections with wind intensity and direction. Rows show wind speed ranges from Calm to Gale Force. The middle columns break down detections by cardinal direction (N, NE, etc.), and the right column shows total detection counts for that speed category. This identifies how wind patterns and direction influence local bird activity.</span></span></div>
            <div class="insights-stats-list" style="padding: 15px 25px;">
                <?php foreach($wind_impact as $bracket => $w): ?>
                <div class="insights-stats-item" style="display: flex; flex-direction: row; align-items: stretch; justify-content: space-between; gap: 0; padding: 16px 20px; margin-bottom: 8px;">
                    <!-- Speed & Species Mix -->
                    <div style="flex: 0 0 220px; padding-right: 25px; border-right: 1.5px solid var(--border); display: flex; flex-direction: column; justify-content: center;">
                        <div class="insights-stats-name" style="font-size: 1.1em; margin-bottom: 2px; white-space: nowrap;"><?php echo $w['emoji']; ?> <?php echo $bracket; ?> <?php echo wind_unit_label(); ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted); white-space: nowrap;">
                            <?php echo $w['det_count'] > 0 ? $w['species_count'] . ' species active' : 'No species recorded'; ?>
                        </div>
                    </div>
                    
                    <!-- Cardinal Mini-Grid (Center) -->
                    <div style="flex: 1; display: flex; justify-content: center; align-items: center; padding: 0 25px; border-right: 1.5px solid var(--border);">
                        <?php if(!empty($w['cardinals'])): ?>
                        <div style="display: flex; gap: 20px; flex-wrap: wrap; justify-content: center;">
                            <?php foreach($w['cardinals'] as $dir): ?>
                            <div style="text-align: center; min-width: 45px;">
                                <div style="font-size: 0.85em; color: var(--text-muted); font-weight: 700; margin-bottom: 2px;">
                                    <?php echo $dir['emoji']; ?> <?php echo $dir['label']; ?>
                                </div>
                                <div style="color: var(--accent); font-weight: 800; font-size: 0.95em;">
                                    <?php echo format_number($dir['count']); ?>
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <?php else: ?>
                            <span style="color: var(--text-muted); font-size: 0.9em; font-style: italic;">No directional data</span>
                        <?php endif; ?>
                    </div>

                    <!-- Total Detections (Right) -->
                    <div style="flex: 0 0 120px; text-align: right; padding-left: 25px; display: flex; align-items: center; justify-content: flex-end;">
                        <span class="insights-stats-count" style="font-size: 1.5em;"><?php echo $w['det_count'] > 0 ? format_number($w['det_count']) : '0'; ?></span>
                    </div>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>

    <!-- Ideal Conditions Per Species -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">🎯 Average Recorded Temperature per Species <span class="info-btn">i<span class="info-tooltip">The average ambient temperature recorded at the station during all hours this species was detected. Only includes species with 5+ detections.</span></span></div>
        <div class="insights-stats-list">
            <?php if(empty($species_ideal)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough weather-matched data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php $rank_temp = 1; foreach($species_ideal as $sp): ?>
            <div class="insights-stats-item <?php echo $rank_temp > 10 ? 'hidden-item' : ''; ?>">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $sp['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);">Range: <?php echo format_number($sp['min_temp'], 1) . temp_unit_suffix(); ?> – <?php echo format_number($sp['max_temp'], 1) . temp_unit_suffix(); ?> · <?php echo format_number($sp['cnt']); ?> detections</div>
                </div>
                <span class="insights-stats-count">~<?php echo format_number($sp['avg_temp'], 1) . temp_unit_suffix(); ?></span>
            </div>
            <?php $rank_temp++; endforeach; ?>
            <?php endif; ?>
        </div>
        <?php if(count($species_ideal) > 10): ?>
        <button class="show-list-btn" 
                onclick="toggleItems(this)" 
                data-expanded="false" 
                data-show-text="Show all <?php echo count($species_ideal); ?> species ↓" 
                data-hide-text="Show top 10 species ↑">
            Show all <?php echo count($species_ideal); ?> species ↓
        </button>
        <?php endif; ?>
    </section>
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($subview == 'health'): ?>
    <?php render_takeaways($takeaways); ?>
    <!-- ====== PHASE 6: Confidence, Quality & Silence Anomalies ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🔍 Confidence & System Health</h2>

    <!-- Confidence KPIs -->
    <div class="insights-kpi-cards" style="margin-bottom: 30px;">
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo $overall_avg_conf; ?><span class="info-btn">i<span class="info-tooltip">The average AI classification confidence across all detections. Lower numbers may indicate background noise or interference.</span></span></span>
            <span class="insights-kpi-label">Avg Confidence</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #10b981;"><?php echo format_number($high_conf_count); ?></span>
            <span class="insights-kpi-label">High (≥80%)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #f59e0b;"><?php echo format_number($med_conf_count); ?></span>
            <span class="insights-kpi-label">Medium (50-79%)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #ef4444;"><?php echo format_number($low_conf_count); ?></span>
            <span class="insights-kpi-label">Low (<50%)</span>
        </div>
    </div>

    <!-- Confidence Trend Chart -->
    <?php if(!empty($confidence_trend)): ?>
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📉 Confidence Trend (Last 30 Days)</div>
        <div style="padding: 20px;">
            <canvas id="confidenceTrendChart" height="100"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <div class="insights-sections-grid">
        <!-- Phantom Species -->
        <section class="insights-section">
            <div class="insights-section-title">👻 Phantom Suspects (Lowest Confidence) <span class="info-btn">i<span class="info-tooltip">Species with consistently low confidence scores. While these may be valid detections of distant birds, they have a higher chance of being misidentifications and may warrant manual review.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($phantom_species)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank_ph = 1; foreach($phantom_species as $ph): ?>
                <div class="insights-stats-item <?php echo $rank_ph > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $ph['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $ph['cnt']; ?> detections · Min: <?php echo $ph['min_conf']; ?></div>
                    </div>
                    <?php
                        $conf_color = $ph['avg_conf'] >= 0.7 ? '#10b981' : ($ph['avg_conf'] >= 0.5 ? '#f59e0b' : '#ef4444');
                    ?>
                    <span class="insights-stats-count" style="color: <?php echo $conf_color; ?>;">~<?php echo $ph['avg_conf']; ?></span>
                </div>
                <?php $rank_ph++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($phantom_species) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($phantom_species); ?> phantom species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($phantom_species); ?> phantom species ↓
            </button>
            <?php endif; ?>
        </section>

        <!-- Detection Bursts & Silent Days -->
        <section class="insights-section">
            <div class="insights-section-title">📊 Anomaly Days <span class="info-btn">i<span class="info-tooltip">Statistical outliers: 'Bursts' indicate unusual activity spikes, while 'Quiet Days' may signal station downtime or storms.</span></span></div>
            <div class="insights-stats-list">
                <?php if(!empty($burst_days)): ?>
                <div style="font-size: 0.9em; font-weight: 600; color: var(--text-heading); padding: 0 15px;">🔥 Detection Bursts (>1.5× avg of <?php echo $avg_daily; ?>/day)</div>
                <?php foreach($burst_days as $b): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo date('M j, Y', strtotime($b['Date'])); ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $b['species_count']; ?> species</div>
                    </div>
                    <span class="insights-stats-count" style="color: #10b981;"><?php echo $b['cnt']; ?> detections</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if(!empty($silent_days)): ?>
                <div style="font-size: 0.9em; font-weight: 600; color: var(--text-heading); padding: 10px 15px 0;">🤫 Quiet Days (≤3 detections)</div>
                <?php foreach($silent_days as $s): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name"><?php echo date('M j, Y', strtotime($s['Date'])); ?></div>
                    </div>
                    <span class="insights-stats-count" style="color: #ef4444;"><?php echo $s['cnt']; ?> detections</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>

                <?php if(empty($burst_days) && empty($silent_days)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No anomaly days detected</span>
                    <span class="insights-stats-count">✓</span>
                </div>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <?php endif; ?>

    <?php if ($subview == 'forecasting'): ?>
    <?php render_takeaways($takeaways); ?>
    <!-- ====== PHASE 7: Long-term Trends & Diversity ====== -->
    <h2 style="margin: 50px 0 20px; font-size: 1.5em; color: var(--text-heading);">📈 Long-term Trends & Diversity</h2>

    <div class="insights-kpi-cards" style="margin-bottom: 30px;">
        <!-- Shannon Diversity Index Card -->
        <div class="insights-kpi-card" style="flex: 1 1 300px;">
            <div>
                <span class="insights-kpi-val"><?php echo $shannon_index; ?><span class="info-btn">i<span class="info-tooltip">A scientific measure of biodiversity that counts both species richness and population balance (evenness). Higher is better.</span></span></span>
                <span class="insights-kpi-label">Shannon Diversity Index (30d)</span>
            </div>
            <div style="margin-top: 10px; font-size: 0.9em;">
                Score: <strong style="color: var(--accent);"><?php echo $diversity_score_text; ?></strong>
                <div style="color: var(--text-muted); font-size: 0.8em; margin-top: 4px;">Measures both species richness and evenness.</div>
            </div>
        </div>

        <!-- YoY Comparison Card -->
        <div class="insights-kpi-card" style="flex: 1 1 300px;">
            <div>
                <span class="insights-kpi-val"><?php echo $this_month_diversity; ?></span>
                <span class="insights-kpi-label">Species this <?php echo $current_month_name; ?></span>
            </div>
            <div style="margin-top: 10px; font-size: 0.9em;">
                vs Last Year: <strong style="color: <?php echo $yoy_diversity_diff >= 0 ? '#10b981' : '#ef4444'; ?>;">
                    <?php echo ($yoy_diversity_diff >= 0 ? '+' : '') . $yoy_diversity_diff; ?> species
                    (<?php echo ($yoy_diversity_diff >= 0 ? '+' : '') . $yoy_diversity_pct; ?>%)
                </strong>
            </div>
        </div>
    </div>

    <!-- Monthly Stats Chart -->
    <section class="insights-section">
        <div class="insights-section-title">📊 Diversity vs. Detection Volume (Monthly)</div>
        <div style="padding: 20px;">
            <canvas id="monthlyTrendsChart" height="100"></canvas>
        </div>
    </section>

    <!-- ====== PHASE 8: Forecasting & Predictions ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🔮 Forecasting & Predictions</h2>

    <div class="insights-sections-grid">
        <!-- Expect Today -->
        <section class="insights-section">
            <div class="insights-section-title">📅 Expected Today (Historical Consistency) <span class="info-btn">i<span class="info-tooltip">Species that have appeared within +/- 3 days of today's date in previous years. Predicts seasonal residents.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($expected_today)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough historical data for this date</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank_ex = 1; foreach($expected_today as $exp): ?>
                <div class="insights-stats-item <?php echo $rank_ex > 10 ? 'hidden-item' : ''; ?>">
                    <span class="insights-stats-name"><?php echo $exp['Com_Name']; ?></span>
                    <span class="insights-stats-count">Present in <?php echo $exp['years_present']; ?> past years</span>
                </div>
                <?php $rank_ex++; endforeach; ?>
                <?php endif; ?>
            </div>
            <?php if(count($expected_today) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($expected_today); ?> expected species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($expected_today); ?> expected species ↓
            </button>
            <?php endif; ?>
            <div style="padding: 10px 15px; font-size: 0.8em; color: var(--text-muted); border-top: 1px solid var(--border-light);">
                Based on species detected within +/- 3 days of today in previous years.
            </div>
        </section>

        <!-- Peak Weeks -->
        <section class="insights-section">
            <div class="insights-section-title">📍 Historical Peak Weeks <span class="info-btn">i<span class="info-tooltip">The specific week of the year (1-52) when each species has historically reached its maximum detection volume.</span></span></div>
            <div class="insights-stats-list">
                <?php $rank_pw = 1; foreach($peak_species as $s): ?>
                <div class="insights-stats-item <?php echo $rank_pw > 10 ? 'hidden-item' : ''; ?>">
                    <div>
                        <div class="insights-stats-name"><?php echo $s['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Week <?php echo $s['peak_week']; ?> Detections: <?php echo format_number($s['peak_count']); ?></div>
                    </div>
                    <?php
                        $is_peak = ($s['peak_week'] == $current_week);
                        $peak_color = $is_peak ? '#10b981' : 'var(--text-muted)';
                        $peak_weight = $is_peak ? '800' : '400';
                    ?>
                    <div style="text-align: right;">
                        <span class="insights-stats-count" style="color: <?php echo $peak_color; ?>; font-weight: <?php echo $peak_weight; ?>;">Week <?php echo $s['peak_week']; ?></span>
                        <?php if($is_peak): ?><div style="font-size: 0.7em; color: #10b981; font-weight: 700;">PEAK NOW</div><?php endif; ?>
                    </div>
                </div>
                <?php $rank_pw++; endforeach; ?>
            </div>
            <?php if(count($peak_species) > 10): ?>
            <button class="show-list-btn" 
                    onclick="toggleItems(this)" 
                    data-expanded="false" 
                    data-show-text="Show all <?php echo count($peak_species); ?> species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($peak_species); ?> species ↓
            </button>
            <?php endif; ?>
        </section>
    </div>
    <?php endif; ?>
    
    <?php if ($subview == 'report'): ?>
        <?php include 'scripts/reports.php'; ?>
    <?php endif; ?>
</div>

<!-- Chart.js for Hourly Activity -->
<script src="static/Chart.bundle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
    window.toggleItems = function(btn) {
        const section = btn.closest('.insights-section');
        const hiddenItems = section.querySelectorAll('.insights-stats-item');
        const isExpanded = btn.getAttribute('data-expanded') === 'true';
        
        if (isExpanded) {
            // Collapse: hide items > 10
            hiddenItems.forEach((el, index) => {
                if (index >= 10) el.classList.add('hidden-item');
            });
            btn.innerHTML = btn.getAttribute('data-show-text');
            btn.setAttribute('data-expanded', 'false');
        } else {
            // Expand: show everything
            hiddenItems.forEach(el => el.classList.remove('hidden-item'));
            btn.innerHTML = btn.getAttribute('data-hide-text');
            btn.setAttribute('data-expanded', 'true');
        }
    };

    window.loadSeasonalBatch = async function(btn) {
        const list = document.getElementById('seasonal-presence-list');
        const limit = parseInt(btn.getAttribute('data-limit') || '50', 10);
        const offset = parseInt(btn.getAttribute('data-offset') || '0', 10);
        const previousText = btn.textContent;

        if (!list || btn.disabled) return;

        btn.disabled = true;
        btn.textContent = 'Loading species...';

        try {
            const batchUrl = new URL('insights.php', window.location.href);
            batchUrl.searchParams.set('subview', 'migration');
            batchUrl.searchParams.set('seasonal_batch', '1');
            batchUrl.searchParams.set('seasonal_offset', String(offset));

            const response = await fetch(batchUrl.toString(), {
                headers: { 'X-Requested-With': 'XMLHttpRequest' }
            });

            if (!response.ok) {
                throw new Error('Seasonal batch request failed');
            }

            const data = await response.json();
            if (data.html) {
                list.insertAdjacentHTML('beforeend', data.html);
            }

            const nextOffset = Number(data.next_offset || offset);
            const total = Number(data.total || btn.getAttribute('data-total') || 0);
            const remaining = Math.max(0, total - nextOffset);

            btn.setAttribute('data-offset', String(nextOffset));
            btn.setAttribute('data-total', String(total));
            if (!data.has_more || remaining === 0) {
                btn.remove();
            } else {
                btn.disabled = false;
                btn.textContent = 'Load ' + Math.min(limit, remaining).toLocaleString(window.BIRDNET_UNITS.numLocale) + ' more species ↓';
            }
        } catch (err) {
            console.error(err);
            btn.disabled = false;
            btn.textContent = previousText;
            alert('Could not load more species. Please try again.');
        }
    };

    <?php if ($subview == 'behavior'): ?>
    var ctx = document.getElementById('hourlyActivityChart');
    if (ctx) {
        var isDark = document.documentElement.classList.contains('dark') ||
                     window.matchMedia('(prefers-color-scheme: dark)').matches;
        var fontColor = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() || (isDark ? '#e0e0e0' : '#444');
        var accentColor = getComputedStyle(document.documentElement).getPropertyValue('--accent').trim() || '#6366f1';

        var data = <?php echo $hourly_values_json; ?>;
        var maxVal = Math.max(...data);
        var colors = data.map(function(v) {
            var opacity = 0.3 + (v / maxVal) * 0.7;
            return 'rgba(99, 102, 241, ' + opacity + ')';
        });

        new Chart(ctx, {
            type: 'bar',
            data: {
                labels: <?php echo $hourly_labels_json; ?>,
                datasets: [{
                    label: 'Detections',
                    data: data,
                    backgroundColor: colors,
                    borderColor: accentColor,
                    borderWidth: 1
                }]
            },
            options: {
                responsive: true,
                legend: { display: false },
                scales: {
                    yAxes: [{ ticks: { beginAtZero: true, fontColor: fontColor } }],
                    xAxes: [{ ticks: { fontColor: fontColor, maxRotation: 45, minRotation: 0 } }]
                },
                tooltips: {
                    callbacks: {
                        label: function(tooltipItem) {
                            return tooltipItem.yLabel.toLocaleString(window.BIRDNET_UNITS.numLocale) + ' detections';
                        }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php 
    $isDark = true; // Default to dark for JS var if not behavioral section
    ?>
    var isDark = document.documentElement.classList.contains('dark') ||
                 window.matchMedia('(prefers-color-scheme: dark)').matches;
    var fontColor = getComputedStyle(document.documentElement).getPropertyValue('--text-primary').trim() || (isDark ? '#e0e0e0' : '#444');

    <?php if ($subview == 'environmental'): ?>
    var tempCtx = document.getElementById('tempVsDetChart');
    if (tempCtx) {
        new Chart(tempCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $temp_trend_labels; ?>,
                datasets: [{
                    label: 'Detections',
                    type: 'bar',
                    data: <?php echo $temp_trend_dets; ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.5)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    yAxisID: 'y-dets'
                }, {
                    label: 'Avg Temp (<?php echo temp_unit_suffix(); ?>)',
                    type: 'line',
                    data: <?php echo $temp_trend_temps; ?>,
                    borderColor: '#f59e0b',
                    backgroundColor: 'rgba(245, 158, 11, 0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: false,
                    yAxisID: 'y-temp'
                }]
            },
            options: {
                responsive: true,
                legend: { labels: { fontColor: fontColor } },
                scales: {
                    yAxes: [
                        { id: 'y-dets', position: 'left', ticks: { beginAtZero: true, fontColor: fontColor }, scaleLabel: { display: true, labelString: 'Detections', fontColor: fontColor } },
                        { id: 'y-temp', position: 'right', ticks: { fontColor: '#f59e0b' }, scaleLabel: { display: true, labelString: 'Temp (<?php echo temp_unit_suffix(); ?>)', fontColor: '#f59e0b' }, gridLines: { drawOnChartArea: false } }
                    ],
                    xAxes: [{ ticks: { fontColor: fontColor, maxRotation: 45, minRotation: 0 } }]
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($subview == 'health'): ?>
    var confCtx = document.getElementById('confidenceTrendChart');
    if (confCtx) {
        new Chart(confCtx, {
            type: 'line',
            data: {
                labels: <?php echo $conf_labels_json; ?>,
                datasets: [{
                    label: 'Avg Confidence',
                    data: <?php echo $conf_values_json; ?>,
                    borderColor: '#6366f1',
                    backgroundColor: 'rgba(99, 102, 241, 0.1)',
                    borderWidth: 2,
                    pointRadius: 3,
                    fill: true
                }]
            },
            options: {
                responsive: true,
                legend: { labels: { fontColor: fontColor } },
                scales: {
                    yAxes: [{ ticks: { fontColor: fontColor, min: 0, max: 1, callback: function(v) { return (v * 100) + '%'; } } }],
                    xAxes: [{ ticks: { fontColor: fontColor, maxRotation: 45, minRotation: 0 } }]
                },
                tooltips: {
                    callbacks: {
                        label: function(item) { return (item.yLabel * 100).toFixed(1) + '% confidence'; }
                    }
                }
            }
        });
    }
    <?php endif; ?>

    <?php if ($subview == 'forecasting'): ?>
    var monthCtx = document.getElementById('monthlyTrendsChart');
    if (monthCtx) {
        new Chart(monthCtx, {
            type: 'bar',
            data: {
                labels: <?php echo $month_labels; ?>,
                datasets: [{
                    label: 'Detections',
                    type: 'bar',
                    data: <?php echo $month_det; ?>,
                    backgroundColor: 'rgba(99, 102, 241, 0.3)',
                    borderColor: 'rgba(99, 102, 241, 1)',
                    borderWidth: 1,
                    yAxisID: 'y-dets'
                }, {
                    label: 'Species Diversity',
                    type: 'line',
                    data: <?php echo $month_div; ?>,
                    borderColor: '#10b981',
                    backgroundColor: 'rgba(16, 185, 129, 0.1)',
                    borderWidth: 3,
                    pointRadius: 4,
                    fill: false,
                    yAxisID: 'y-div'
                }]
            },
            options: {
                responsive: true,
                legend: { labels: { fontColor: fontColor } },
                scales: {
                    yAxes: [
                        { id: 'y-dets', position: 'left', ticks: { beginAtZero: true, fontColor: fontColor }, scaleLabel: { display: true, labelString: 'Total Detections', fontColor: fontColor } },
                        { id: 'y-div', position: 'right', ticks: { beginAtZero: true, fontColor: '#10b981' }, scaleLabel: { display: true, labelString: 'Species Count', fontColor: '#10b981' }, gridLines: { drawOnChartArea: false } }
                    ],
                    xAxes: [{ ticks: { fontColor: fontColor, maxRotation: 45, minRotation: 0 } }]
                }
            }
        });
    }
    <?php endif; ?>
});
</script>

<?php
// Save the rendered fragment for the cache key computed above (Phase 0 cache).
birdnet_cache_put($insights_cache_key, ob_get_contents());
ob_end_flush();
?>
