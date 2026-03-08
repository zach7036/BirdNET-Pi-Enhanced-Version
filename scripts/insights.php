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
        $hrs = floor($row['avg_minutes'] / 60);
        $mins = round($row['avg_minutes'] % 60);
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
        $hrs = floor($m / 60);
        $mins = round($m % 60);
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
</div>

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
});
</script>
