<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// 1. Lifetime Species
$lifetime_species = $db->querySingle('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections') ?: 0;

// 2. Best Day Count
$best_day_res = $db->query('SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date ORDER BY cnt DESC LIMIT 1');
$best_day_row = $best_day_res ? $best_day_res->fetchArray(SQLITE3_ASSOC) : false;
$best_day_count = $best_day_row ? $best_day_row['cnt'] : 0;
$best_day_date = $best_day_row ? date('M j, Y', strtotime($best_day_row['Date'])) : 'N/A';

// 3. Longest Streak (Consecutive Days with any detection)
$streak_res = $db->query('SELECT Date FROM detections GROUP BY Date ORDER BY Date ASC');
$dates = [];
if ($streak_res) {
    while($row = $streak_res->fetchArray(SQLITE3_ASSOC)) {
        $dates[] = $row['Date'];
    }
}

$max_streak = 0;
$current_streak = 0;
$prev_date = null;

foreach ($dates as $date_str) {
    if ($prev_date === null) {
        $current_streak = 1;
    } else {
        $diff = (strtotime($date_str) - strtotime($prev_date)) / 86400;
        if ($diff == 1) {
            $current_streak++;
        } else {
            $max_streak = max($max_streak, $current_streak);
            $current_streak = 1;
        }
    }
    $prev_date = $date_str;
}
$max_streak = max($max_streak, $current_streak);

// 4. Rare Species (Detected < 5 times ever)
$rarest = [];
$rare_res = $db->query('SELECT Com_Name, Sci_Name, COUNT(*) as cnt, MIN(Date) as first_seen, MAX(Date) as last_seen FROM detections GROUP BY Sci_Name HAVING cnt < 5 ORDER BY cnt ASC, last_seen DESC LIMIT 10');
if ($rare_res) {
    while($row = $rare_res->fetchArray(SQLITE3_ASSOC)) {
        $rarest[] = $row;
    }
}
$rare_total = $db->querySingle('SELECT COUNT(*) FROM (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < 5)') ?: 0;

// 5. Personal Milestones
$milestones = [];
$total_detections = $db->querySingle('SELECT COUNT(*) FROM detections') ?: 0;
$first_det = $db->querySingle('SELECT MIN(Date) FROM detections');
$milestones[] = ["title" => "First Detection", "val" => $first_det ?: 'N/A'];
$milestones[] = ["title" => "Lifetime Detections", "val" => number_format($total_detections)];

// Top Daily Record for a Single Species
$top_spec_res = $db->query('SELECT Com_Name, Date, COUNT(*) as cnt FROM detections GROUP BY Sci_Name, Date ORDER BY cnt DESC LIMIT 1');
$top_spec_day = $top_spec_res ? $top_spec_res->fetchArray(SQLITE3_ASSOC) : false;
if ($top_spec_day) {
    $milestones[] = ["title" => "Single Day Record", "val" => $top_spec_day['cnt'] . " " . $top_spec_day['Com_Name'] . " on " . date('M j, Y', strtotime($top_spec_day['Date']))];
}

// =============================================
// PHASE 2: Daily Behavior Patterns
// =============================================

// 6. Dawn Chorus Order — Top 10 earliest average detection times
$dawn_chorus = [];
$dawn_res = $db->query("
    SELECT Com_Name,
           AVG(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as avg_minutes,
           COUNT(*) as cnt
    FROM detections
    WHERE CAST(substr(Time, 1, 2) AS INTEGER) BETWEEN 4 AND 10
    GROUP BY Sci_Name
    HAVING cnt >= 3
    ORDER BY avg_minutes ASC
    LIMIT 10
");
if ($dawn_res) {
    while($row = $dawn_res->fetchArray(SQLITE3_ASSOC)) {
        $hrs = intval($row['avg_minutes'] / 60);
        $mins = intval($row['avg_minutes']) % 60;
        $row['avg_time'] = sprintf('%d:%02d AM', $hrs, $mins);
        $dawn_chorus[] = $row;
    }
}

// 7. Peak Activity Hours — Busiest hours across all species
$hourly_activity = array_fill(0, 24, 0);
$hourly_res = $db->query("
    SELECT CAST(substr(Time, 1, 2) AS INTEGER) as hour, COUNT(*) as cnt
    FROM detections
    GROUP BY hour
    ORDER BY hour ASC
");
if ($hourly_res) {
    while($row = $hourly_res->fetchArray(SQLITE3_ASSOC)) {
        $hourly_activity[$row['hour']] = $row['cnt'];
    }
}
$hourly_labels_json = json_encode(array_map(function($h) {
    if ($h == 0) return '12 AM';
    if ($h < 12) return $h . ' AM';
    if ($h == 12) return '12 PM';
    return ($h - 12) . ' PM';
}, range(0, 23)));
$hourly_values_json = json_encode(array_values($hourly_activity));

// Find peak hour
$peak_hour_idx = array_search(max($hourly_activity), $hourly_activity);
$peak_hour_label = ($peak_hour_idx == 0) ? '12 AM' : (($peak_hour_idx < 12) ? $peak_hour_idx . ' AM' : (($peak_hour_idx == 12) ? '12 PM' : ($peak_hour_idx - 12) . ' PM'));
$peak_hour_count = max($hourly_activity);

// 8. Nocturnal Detections (10 PM - 4 AM)
$nocturnal = [];
$noct_res = $db->query("
    SELECT Com_Name, COUNT(*) as cnt,
           AVG(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as avg_minutes
    FROM detections
    WHERE CAST(substr(Time, 1, 2) AS INTEGER) >= 22 OR CAST(substr(Time, 1, 2) AS INTEGER) < 4
    GROUP BY Sci_Name
    HAVING cnt >= 2
    ORDER BY cnt DESC
    LIMIT 8
");
if ($noct_res) {
    while($row = $noct_res->fetchArray(SQLITE3_ASSOC)) {
        $m = $row['avg_minutes'];
        $hrs = intval($m / 60);
        $mins = intval($m) % 60;
        if ($hrs >= 12) {
            $row['avg_time'] = sprintf('%d:%02d PM', $hrs == 12 ? 12 : $hrs - 12, $mins);
        } else {
            $row['avg_time'] = sprintf('%d:%02d AM', $hrs == 0 ? 12 : $hrs, $mins);
        }
        $nocturnal[] = $row;
    }
}

// 9. Activity Window Per Species — earliest to latest detection time (top 10 most active)
$activity_windows = [];
$window_res = $db->query("
    SELECT Com_Name,
           MIN(Time) as earliest,
           MAX(Time) as latest,
           COUNT(*) as cnt
    FROM detections
    GROUP BY Sci_Name
    HAVING cnt >= 5
    ORDER BY cnt DESC
    LIMIT 10
");
if ($window_res) {
    while($row = $window_res->fetchArray(SQLITE3_ASSOC)) {
        // Convert to readable format
        $e_h = intval(substr($row['earliest'], 0, 2));
        $e_m = substr($row['earliest'], 3, 2);
        $l_h = intval(substr($row['latest'], 0, 2));
        $l_m = substr($row['latest'], 3, 2);
        $row['earliest_fmt'] = sprintf('%d:%s %s', $e_h % 12 ?: 12, $e_m, $e_h < 12 ? 'AM' : 'PM');
        $row['latest_fmt'] = sprintf('%d:%s %s', $l_h % 12 ?: 12, $l_m, $l_h < 12 ? 'AM' : 'PM');
        $activity_windows[] = $row;
    }
}

// =============================================
// PHASE 3: Migration & Seasonal Patterns
// =============================================

$today = date('Y-m-d');
$two_weeks_ago = date('Y-m-d', strtotime('-14 days'));
$one_month_ago = date('Y-m-d', strtotime('-30 days'));
$current_year = date('Y');
$last_year = $current_year - 1;

// 10. New Arrivals — Species first detected in the last 14 days
$new_arrivals = [];
$arrival_res = $db->query("
    SELECT d.Com_Name, d.Sci_Name, MIN(d.Date) as first_seen, COUNT(*) as cnt
    FROM detections d
    WHERE d.Sci_Name NOT IN (
        SELECT DISTINCT Sci_Name FROM detections WHERE Date < '$two_weeks_ago'
    )
    AND d.Date >= '$two_weeks_ago'
    GROUP BY d.Sci_Name
    ORDER BY first_seen DESC
    LIMIT 10
");
if ($arrival_res) {
    while($row = $arrival_res->fetchArray(SQLITE3_ASSOC)) {
        $new_arrivals[] = $row;
    }
}

// 11. Gone Quiet — Species detected 5+ times before but NOT in the last 14 days
$gone_quiet = [];
$quiet_res = $db->query("
    SELECT Com_Name, Sci_Name, COUNT(*) as total_cnt, MAX(Date) as last_seen
    FROM detections
    WHERE Sci_Name NOT IN (
        SELECT DISTINCT Sci_Name FROM detections WHERE Date >= '$two_weeks_ago'
    )
    GROUP BY Sci_Name
    HAVING total_cnt >= 5
    ORDER BY last_seen DESC
    LIMIT 10
");
if ($quiet_res) {
    while($row = $quiet_res->fetchArray(SQLITE3_ASSOC)) {
        $days_ago = intval((strtotime($today) - strtotime($row['last_seen'])) / 86400);
        $row['days_ago'] = $days_ago;
        $gone_quiet[] = $row;
    }
}

// 12. Year-over-Year First Detection Comparison
$yoy_comparison = [];
$yoy_res = $db->query("
    SELECT
        a.Com_Name,
        a.Sci_Name,
        a.first_this_year,
        b.first_last_year,
        CAST(julianday(a.first_this_year) - julianday(b.first_last_year_adjusted) AS INTEGER) as day_diff
    FROM (
        SELECT Com_Name, Sci_Name, MIN(Date) as first_this_year
        FROM detections
        WHERE strftime('%Y', Date) = '$current_year'
        GROUP BY Sci_Name
    ) a
    INNER JOIN (
        SELECT Sci_Name,
               MIN(Date) as first_last_year,
               '$current_year' || substr(MIN(Date), 5) as first_last_year_adjusted
        FROM detections
        WHERE strftime('%Y', Date) = '$last_year'
        GROUP BY Sci_Name
    ) b ON a.Sci_Name = b.Sci_Name
    WHERE day_diff != 0
    ORDER BY ABS(day_diff) DESC
    LIMIT 10
");
if ($yoy_res) {
    while($row = $yoy_res->fetchArray(SQLITE3_ASSOC)) {
        $yoy_comparison[] = $row;
    }
}

// 13. Seasonal Presence — Month-by-month detection counts for top species (for a mini chart)
$seasonal_top = [];
$seasonal_res = $db->query("
    SELECT Com_Name, Sci_Name,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 1 THEN 1 ELSE 0 END) as m1,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 2 THEN 1 ELSE 0 END) as m2,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 3 THEN 1 ELSE 0 END) as m3,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 4 THEN 1 ELSE 0 END) as m4,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 5 THEN 1 ELSE 0 END) as m5,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 6 THEN 1 ELSE 0 END) as m6,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 7 THEN 1 ELSE 0 END) as m7,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 8 THEN 1 ELSE 0 END) as m8,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 9 THEN 1 ELSE 0 END) as m9,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 10 THEN 1 ELSE 0 END) as m10,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 11 THEN 1 ELSE 0 END) as m11,
           SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 12 THEN 1 ELSE 0 END) as m12,
           COUNT(*) as total
    FROM detections
    GROUP BY Sci_Name
    ORDER BY total DESC
    LIMIT 8
");
if ($seasonal_res) {
    while($row = $seasonal_res->fetchArray(SQLITE3_ASSOC)) {
        $months_active = 0;
        for ($i = 1; $i <= 12; $i++) {
            if ($row['m'.$i] > 0) $months_active++;
        }
        $row['months_active'] = $months_active;
        $row['status'] = $months_active >= 10 ? 'Year-round' : ($months_active >= 5 ? 'Seasonal' : 'Transient');
        $seasonal_top[] = $row;
    }
}

// =============================================
// PHASE 4: Weather Correlations
// =============================================

// WMO Weather Code descriptions
$wmo_codes = [
    0 => 'Clear sky', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast',
    45 => 'Fog', 48 => 'Rime fog',
    51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle',
    61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain',
    71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow',
    80 => 'Slight showers', 81 => 'Moderate showers', 82 => 'Violent showers',
    95 => 'Thunderstorm', 96 => 'Thunderstorm + hail', 99 => 'Thunderstorm + heavy hail'
];

// Check if weather table exists and has data
$has_weather = false;
$weather_check = $db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'");
if ($weather_check > 0) {
    $weather_count = $db->querySingle("SELECT COUNT(*) FROM weather");
    $has_weather = ($weather_count > 0);
}

$temp_brackets = [];
$condition_impact = [];
$species_ideal = [];
$temp_vs_detections = [];

if ($has_weather) {
    // 14. Detections by Temperature Bracket
    $temp_res = $db->query("
        SELECT
            CASE
                WHEN w.Temp < 32 THEN 'Below 32°F'
                WHEN w.Temp BETWEEN 32 AND 45 THEN '32–45°F'
                WHEN w.Temp BETWEEN 46 AND 55 THEN '46–55°F'
                WHEN w.Temp BETWEEN 56 AND 65 THEN '56–65°F'
                WHEN w.Temp BETWEEN 66 AND 75 THEN '66–75°F'
                WHEN w.Temp BETWEEN 76 AND 85 THEN '76–85°F'
                ELSE 'Above 85°F'
            END as bracket,
            COUNT(*) as det_count,
            COUNT(DISTINCT d.Sci_Name) as species_count,
            ROUND(AVG(w.Temp), 1) as avg_temp
        FROM detections d
        INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour
        GROUP BY bracket
        ORDER BY MIN(w.Temp) ASC
    ");
    if ($temp_res) {
        while($row = $temp_res->fetchArray(SQLITE3_ASSOC)) {
            $temp_brackets[] = $row;
        }
    }

    // 15. Detections by Weather Condition
    $cond_res = $db->query("
        SELECT w.ConditionCode, COUNT(*) as det_count,
               COUNT(DISTINCT d.Sci_Name) as species_count
        FROM detections d
        INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour
        GROUP BY w.ConditionCode
        ORDER BY det_count DESC
        LIMIT 8
    ");
    if ($cond_res) {
        while($row = $cond_res->fetchArray(SQLITE3_ASSOC)) {
            $code = $row['ConditionCode'];
            $row['description'] = isset($wmo_codes[$code]) ? $wmo_codes[$code] : "Code $code";
            $condition_impact[] = $row;
        }
    }

    // 16. Ideal Conditions Per Species (top 8 species)
    $ideal_res = $db->query("
        SELECT d.Com_Name,
               ROUND(AVG(w.Temp), 1) as avg_temp,
               ROUND(MIN(w.Temp), 1) as min_temp,
               ROUND(MAX(w.Temp), 1) as max_temp,
               COUNT(*) as cnt
        FROM detections d
        INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour
        GROUP BY d.Sci_Name
        HAVING cnt >= 5
        ORDER BY cnt DESC
        LIMIT 8
    ");
    if ($ideal_res) {
        while($row = $ideal_res->fetchArray(SQLITE3_ASSOC)) {
            $species_ideal[] = $row;
        }
    }

    // 17. Daily temp vs detection count for chart (last 30 days)
    $trend_res = $db->query("
        SELECT d.Date,
               COUNT(*) as det_count,
               ROUND(AVG(w.Temp), 1) as avg_temp
        FROM detections d
        LEFT JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour
        WHERE d.Date >= '$one_month_ago'
        GROUP BY d.Date
        ORDER BY d.Date ASC
    ");
    if ($trend_res) {
        while($row = $trend_res->fetchArray(SQLITE3_ASSOC)) {
            $temp_vs_detections[] = $row;
        }
    }
}

$temp_trend_labels = json_encode(array_map(function($r) { return date('M j', strtotime($r['Date'])); }, $temp_vs_detections));
$temp_trend_temps = json_encode(array_map(function($r) { return $r['avg_temp']; }, $temp_vs_detections));
$temp_trend_dets = json_encode(array_map(function($r) { return $r['det_count']; }, $temp_vs_detections));

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
        text-align: center;
        margin-bottom: 30px;
        background: var(--bg-card);
        padding: 30px;
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
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
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s;
        flex: 1 1 180px;
        min-width: 180px;
    }
    .insights-kpi-card:hover { transform: translateY(-5px); }
    .insights-kpi-val { font-size: 2em; font-weight: 800; display: block; margin-bottom: 4px; white-space: nowrap; }
    .insights-kpi-label { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }

    .insights-sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 30px;
    }
    .insights-section {
        background: var(--bg-card);
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .insights-section-title {
        background: var(--bg-primary);
        padding: 15px 20px;
        font-weight: bold;
        border-bottom: 1px solid var(--border);
        font-size: 1.1em;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .insights-stats-list { display: flex; flex-direction: column; gap: 8px; padding: 15px; }
    .insights-stats-item {
        display: flex;
        justify-content: space-between;
        align-items: center;
        padding: 12px 15px;
        background: var(--bg-primary);
        border-radius: 12px;
        border: 1px solid var(--border-light);
    }
    .insights-stats-name { font-weight: 600; color: var(--text-heading); }
    .insights-stats-count { font-weight: 800; color: var(--accent); }
</style>

<div class="insights-container">
    <header class="insights-header">
        <h1>BirdNET Insights</h1>
        <div class="insights-subtitle">Deep behavioral analysis and seasonal trends for your station.</div>
    </header>

    <div class="insights-kpi-cards">
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($lifetime_species); ?></span>
            <span class="insights-kpi-label">Lifetime Species</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($best_day_count); ?></span>
            <span class="insights-kpi-label">Best Day (<?php echo $best_day_date; ?>)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo $max_streak; ?> Days</span>
            <span class="insights-kpi-label">Longest Streak</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo number_format($rare_total); ?></span>
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
            <div class="insights-section-title">💎 Rarest Detections (&lt; 5 ever)</div>
            <div class="insights-stats-list">
                <?php if(empty($rarest)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No rare species detected yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($rarest as $r): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $r['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Last seen: <?php echo date('M j, Y', strtotime($r['last_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count"><?php echo $r['cnt']; ?>x</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- ====== PHASE 2: Daily Behavior Patterns ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🕐 Daily Behavior Patterns</h2>

    <!-- Hourly Activity Chart -->
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📊 Hourly Activity Distribution <span style="margin-left: auto; font-weight: normal; font-size: 0.85em; color: var(--text-muted);">Peak: <?php echo $peak_hour_label; ?> (<?php echo number_format($peak_hour_count); ?> detections)</span></div>
        <div style="padding: 20px;">
            <canvas id="hourlyActivityChart" height="100"></canvas>
        </div>
    </section>

    <div class="insights-sections-grid">
        <!-- Dawn Chorus Order -->
        <section class="insights-section">
            <div class="insights-section-title">🌅 Dawn Chorus Order</div>
            <div class="insights-stats-list">
                <?php if(empty($dawn_chorus)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough dawn data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php $rank = 1; foreach($dawn_chorus as $d): ?>
                <div class="insights-stats-item">
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
        </section>

        <!-- Nocturnal Detections -->
        <section class="insights-section">
            <div class="insights-section-title">🦉 Nocturnal Activity (10 PM – 4 AM)</div>
            <div class="insights-stats-list">
                <?php if(empty($nocturnal)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No regular night-time visitors yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($nocturnal as $n): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $n['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Avg time: <?php echo $n['avg_time']; ?></div>
                    </div>
                    <span class="insights-stats-count"><?php echo $n['cnt']; ?>x</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Activity Windows -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">⏱️ Activity Windows (Top Species)</div>
        <div class="insights-stats-list">
            <?php if(empty($activity_windows)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php foreach($activity_windows as $w): ?>
            <div class="insights-stats-item">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $w['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo number_format($w['cnt']); ?> total detections</div>
                </div>
                <span class="insights-stats-count" style="font-size: 0.9em;"><?php echo $w['earliest_fmt']; ?> → <?php echo $w['latest_fmt']; ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- ====== PHASE 3: Migration & Seasonal Patterns ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🦅 Migration & Seasonal Patterns</h2>

    <div class="insights-sections-grid">
        <!-- New Arrivals -->
        <section class="insights-section">
            <div class="insights-section-title">🆕 New Arrivals (Last 14 Days)</div>
            <div class="insights-stats-list">
                <?php if(empty($new_arrivals)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No brand-new species in the last 2 weeks</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($new_arrivals as $a): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $a['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">First seen: <?php echo date('M j', strtotime($a['first_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count" style="color: #10b981;"><?php echo $a['cnt']; ?> detections</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Gone Quiet -->
        <section class="insights-section">
            <div class="insights-section-title">🔇 Gone Quiet</div>
            <div class="insights-stats-list">
                <?php if(empty($gone_quiet)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">All regular species still active!</span>
                    <span class="insights-stats-count">✓</span>
                </div>
                <?php else: ?>
                <?php foreach($gone_quiet as $q): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $q['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $q['total_cnt']; ?> total · Last: <?php echo date('M j', strtotime($q['last_seen'])); ?></div>
                    </div>
                    <span class="insights-stats-count" style="color: #ef4444;"><?php echo $q['days_ago']; ?>d ago</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Year-over-Year Comparison -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">📅 Year-over-Year Arrival Comparison (<?php echo $last_year; ?> vs <?php echo $current_year; ?>)</div>
        <div class="insights-stats-list">
            <?php if(empty($yoy_comparison)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough multi-year data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php foreach($yoy_comparison as $y): ?>
            <div class="insights-stats-item">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $y['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);">
                        <?php echo $current_year; ?>: <?php echo date('M j', strtotime($y['first_this_year'])); ?>
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
                <span class="insights-stats-count" style="color: <?php echo $color; ?>;"><?php echo $label; ?></span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>

    <!-- Seasonal Presence -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">🗓️ Seasonal Presence (Top Species)</div>
        <div class="insights-stats-list">
            <?php if(empty($seasonal_top)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php
                $status_colors = ['Year-round' => '#10b981', 'Seasonal' => '#f59e0b', 'Transient' => '#ef4444'];
                $months_short = ['J','F','M','A','M','J','J','A','S','O','N','D'];
            ?>
            <?php foreach($seasonal_top as $s): ?>
            <div class="insights-stats-item" style="flex-wrap: wrap; gap: 8px;">
                <div style="flex: 1 1 200px;">
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $s['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);">
                        <?php echo $s['months_active']; ?>/12 months ·
                        <span style="color: <?php echo $status_colors[$s['status']]; ?>; font-weight: 700;"><?php echo $s['status']; ?></span>
                    </div>
                </div>
                <div style="display: flex; gap: 2px; align-items: center;">
                    <?php for ($i = 1; $i <= 12; $i++): ?>
                    <div title="<?php echo date('F', mktime(0,0,0,$i,1)); ?>: <?php echo $s['m'.$i]; ?>" style="
                        width: 18px; height: 24px; border-radius: 3px; text-align: center; line-height: 24px; font-size: 0.65em; font-weight: 600;
                        background: <?php echo $s['m'.$i] > 0 ? 'var(--accent)' : 'var(--bg-primary)'; ?>;
                        color: <?php echo $s['m'.$i] > 0 ? '#fff' : 'var(--text-muted)'; ?>;
                        border: 1px solid <?php echo $s['m'.$i] > 0 ? 'transparent' : 'var(--border-light)'; ?>;
                    "><?php echo $months_short[$i-1]; ?></div>
                    <?php endfor; ?>
                </div>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>

<?php if ($has_weather): ?>
<!-- ====== PHASE 4: Weather Correlations ====== -->
<div class="insights-container">
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🌤️ Weather Correlations</h2>

    <!-- Temp vs Detections Chart -->
    <?php if(!empty($temp_vs_detections)): ?>
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📈 Temperature vs Detections (Last 30 Days)</div>
        <div style="padding: 20px;">
            <canvas id="tempVsDetChart" height="120"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <div class="insights-sections-grid">
        <!-- Temperature Brackets -->
        <section class="insights-section">
            <div class="insights-section-title">🌡️ Detections by Temperature</div>
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
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $t['species_count']; ?> species active</div>
                    </div>
                    <span class="insights-stats-count"><?php echo number_format($t['det_count']); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Weather Conditions -->
        <section class="insights-section">
            <div class="insights-section-title">☁️ Detections by Weather Condition</div>
            <div class="insights-stats-list">
                <?php if(empty($condition_impact)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough weather data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($condition_impact as $c): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $c['description']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $c['species_count']; ?> species active</div>
                    </div>
                    <span class="insights-stats-count"><?php echo number_format($c['det_count']); ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>
    </div>

    <!-- Ideal Conditions Per Species -->
    <section class="insights-section" style="margin-top: 30px;">
        <div class="insights-section-title">🎯 Preferred Temperature per Species</div>
        <div class="insights-stats-list">
            <?php if(empty($species_ideal)): ?>
            <div class="insights-stats-item">
                <span class="insights-stats-name">Not enough weather-matched data yet</span>
                <span class="insights-stats-count">—</span>
            </div>
            <?php else: ?>
            <?php foreach($species_ideal as $sp): ?>
            <div class="insights-stats-item">
                <div>
                    <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $sp['Com_Name']; ?></div>
                    <div style="font-size: 0.8em; color: var(--text-muted);">Range: <?php echo $sp['min_temp']; ?>°F – <?php echo $sp['max_temp']; ?>°F · <?php echo number_format($sp['cnt']); ?> detections</div>
                </div>
                <span class="insights-stats-count">~<?php echo $sp['avg_temp']; ?>°F</span>
            </div>
            <?php endforeach; ?>
            <?php endif; ?>
        </div>
    </section>
</div>
<?php endif; ?>

<!-- Chart.js for Hourly Activity -->
<script src="static/Chart.bundle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
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
                            return tooltipItem.yLabel.toLocaleString() + ' detections';
                        }
                    }
                }
            }
        });
    }

    // Phase 4: Temp vs Detections dual-axis chart
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
                    label: 'Avg Temp (°F)',
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
                        { id: 'y-temp', position: 'right', ticks: { fontColor: '#f59e0b' }, scaleLabel: { display: true, labelString: 'Temp (°F)', fontColor: '#f59e0b' }, gridLines: { drawOnChartArea: false } }
                    ],
                    xAxes: [{ ticks: { fontColor: fontColor, maxRotation: 45, minRotation: 0 } }]
                }
            }
        });
    }
});
</script>
