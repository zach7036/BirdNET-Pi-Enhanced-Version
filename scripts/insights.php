<?php
error_reporting(E_ERROR);
ini_set('display_errors', 1);
require_once 'scripts/common.php';

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// Get active subview
$subview = isset($_GET['subview']) ? $_GET['subview'] : 'dashboard';

// Initialize all potential variables to prevent undefined errors in HTML
$lifetime_species = 0; $best_day_count = 0; $best_day_date = 'N/A'; $max_streak = 0;
$rarest = []; $rare_total = 0; $milestones = []; $yard_health_score = 0; $recommendations = [];
$dawn_chorus = []; $hourly_labels_json = '[]'; $hourly_values_json = '[]'; $peak_hour_label = 'N/A'; $peak_hour_count = 0;
$nocturnal = []; $activity_windows = []; $new_arrivals = []; $gone_quiet = []; $yoy_comparison = []; $seasonal_top = [];
$monthly_stats = []; $month_labels = '[]'; $month_div = '[]'; $month_det = '[]'; $shannon_index = 0; $diversity_score_text = 'N/A'; $yoy_diversity_diff = 0;
$temp_brackets = []; $condition_impact = []; $species_ideal = []; $temp_trend_labels = '[]'; $temp_trend_temps = '[]'; $temp_trend_dets = '[]'; $has_weather = false;
$confidence_trend = []; $conf_labels_json = '[]'; $conf_values_json = '[]'; $overall_avg_conf = 0; $phantom_species = []; $burst_days = []; $silent_days = [];
$high_conf_count = 0; $med_conf_count = 0; $low_conf_count = 0; $expected_today = []; $top_5_species = []; $current_week = date('W');

$one_month_ago = date('Y-m-d', strtotime('-30 days'));
$two_weeks_ago = date('Y-m-d', strtotime('-14 days'));
$today = date('Y-m-d');

if ($subview == 'dashboard') {
    $lifetime_species = $db->querySingle('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections') ?: 0;
    $best_day_res = $db->query('SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date ORDER BY cnt DESC LIMIT 1');
    if ($best_day_row = $best_day_res->fetchArray(SQLITE3_ASSOC)) {
        $best_day_count = $best_day_row['cnt'];
        $best_day_date = date('M j, Y', strtotime($best_day_row['Date']));
    }
    $streak_res = $db->query('SELECT Date FROM detections GROUP BY Date ORDER BY Date ASC');
    $dates = []; while($row = $streak_res->fetchArray(SQLITE3_ASSOC)) { $dates[] = $row['Date']; }
    $max_s = 0; $cur_s = 0; $prev = null;
    foreach ($dates as $d_str) {
        if ($prev === null) { $cur_s = 1; }
        else { if ((strtotime($d_str) - strtotime($prev)) / 86400 == 1) $cur_s++; else { $max_s = max($max_s, $cur_s); $cur_s = 1; } }
        $prev = $d_str;
    }
    $max_streak = max($max_s, $cur_s);
    $rare_res = $db->query('SELECT Com_Name, Sci_Name, COUNT(*) as cnt, MIN(Date) as first_seen, MAX(Date) as last_seen FROM detections GROUP BY Sci_Name HAVING cnt < 5 ORDER BY cnt ASC, last_seen DESC');
    while($row = $rare_res->fetchArray(SQLITE3_ASSOC)) { $rarest[] = $row; }
    $rare_total = $db->querySingle('SELECT COUNT(*) FROM (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < 5)') ?: 0;
    $total_detections = $db->querySingle('SELECT COUNT(*) FROM detections') ?: 0;
    $first_det = $db->querySingle('SELECT MIN(Date) FROM detections');
    $milestones[] = ["title" => "First Detection", "val" => $first_det ?: 'N/A'];
    $milestones[] = ["title" => "Lifetime Detections", "val" => number_format($total_detections)];
    $top_spec_res = $db->query('SELECT Com_Name, Date, COUNT(*) as cnt FROM detections GROUP BY Sci_Name, Date ORDER BY cnt DESC LIMIT 1');
    if ($top_spec_day = $top_spec_res->fetchArray(SQLITE3_ASSOC)) {
        $milestones[] = ["title" => "Single Day Record", "val" => $top_spec_day['cnt'] . " " . $top_spec_day['Com_Name'] . " on " . date('M j, Y', strtotime($top_spec_day['Date']))];
    }
    // Yard Health Calculation
    $stable_days = $db->querySingle("SELECT COUNT(*) FROM (SELECT Date FROM detections WHERE Date >= '$one_month_ago' GROUP BY Date HAVING COUNT(*) >= 5)") ?: 0;
    $stability_score = ($stable_days / 30) * 20;
    $today_vol = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Date = '$today'") ?: 0;
    $avg_30d_vol = $db->querySingle("SELECT COUNT(*)/30.0 FROM detections WHERE Date >= '$one_month_ago'") ?: 1;
    $volume_score = min(20, ($today_vol / (max(1, $avg_30d_vol))) * 10);
    $rare_detections = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago' AND Confidence >= 0.85 AND Sci_Name IN (SELECT Sci_Name FROM detections GROUP BY Sci_Name HAVING COUNT(*) < (SELECT COUNT(*) FROM detections) * 0.01)") ?: 0;
    $rarity_score = min(20, $rare_detections * 2);
    $s_idx = 0; $s_counts = $db->query("SELECT COUNT(*) as cnt FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name");
    $t_30d = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago'") ?: 0;
    if ($s_counts && $t_30d > 0) { while($r = $s_counts->fetchArray(SQLITE3_ASSOC)) { $pi = $r['cnt'] / $t_30d; $s_idx -= $pi * log($pi); } }
    $diversity_pts = min(40, ($s_idx / 3.0) * 40);
    $yard_health_score = round($stability_score + $volume_score + $rarity_score + $diversity_pts);
    if ($yard_health_score > 100) $yard_health_score = 100;
    if ($s_idx < 1.5) $recommendations[] = ["icon" => "🌻", "text" => "Diversity is low. Try adding different types of seed or a water feature to attract more species."];
    if ($stable_days < 20) $recommendations[] = ["icon" => "🎤", "text" => "Multiple quiet days detected. Ensure your microphone is unobstructed and the system is running 24/7."];
    $high_c = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence >= 0.8") ?: 0;
    $med_c = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence >= 0.5 AND Confidence < 0.8") ?: 0;
    $low_c = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence < 0.5") ?: 0;
    if ($low_c > ($high_c + $med_c)) $recommendations[] = ["icon" => "⚙️", "text" => "High number of low-confidence detections. You might need to adjust your sensitivity or filter out phantom species."];
    if ($today_vol < $avg_30d_vol * 0.5) $recommendations[] = ["icon" => "🌥️", "text" => "Activity is unusually low today. Check if the weather or local disturbances have gone up."];
    if (empty($recommendations)) $recommendations[] = ["icon" => "🌟", "text" => "Your yard is booming! Keep up the great work maintaining a healthy habitat."];
}

if ($subview == 'behavior') {
    $dawn_chorus = []; $dawn_res = $db->query("SELECT Com_Name, AVG(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as avg_minutes, COUNT(*) as cnt FROM detections WHERE CAST(substr(Time, 1, 2) AS INTEGER) BETWEEN 4 AND 10 GROUP BY Sci_Name HAVING cnt >= 3 ORDER BY avg_minutes ASC LIMIT 10");
    while($row = $dawn_res->fetchArray(SQLITE3_ASSOC)) { $hrs = intval($row['avg_minutes'] / 60); $mins = intval($row['avg_minutes']) % 60; $row['avg_time'] = sprintf('%d:%02d AM', $hrs, $mins); $dawn_chorus[] = $row; }
    $hourly_activity = array_fill(0, 24, 0); $hourly_res = $db->query("SELECT CAST(substr(Time, 1, 2) AS INTEGER) as hour, COUNT(*) as cnt FROM detections GROUP BY hour ORDER BY hour ASC");
    while($row = $hourly_res->fetchArray(SQLITE3_ASSOC)) { $hourly_activity[$row['hour']] = $row['cnt']; }
    $hourly_labels_json = json_encode(array_map(function($h) { if ($h == 0) return '12 AM'; if ($h < 12) return $h . ' AM'; if ($h == 12) return '12 PM'; return ($h - 12) . ' PM'; }, range(0, 23)));
    $hourly_values_json = json_encode(array_values($hourly_activity));
    $peak_hour_idx = array_search(max($hourly_activity), $hourly_activity);
    $peak_hour_label = ($peak_hour_idx == 0) ? '12 AM' : (($peak_hour_idx < 12) ? $peak_hour_idx . ' AM' : (($peak_hour_idx == 12) ? '12 PM' : ($peak_hour_idx - 12) . ' PM'));
    $peak_hour_count = max($hourly_activity);
    $nocturnal = []; $noct_res = $db->query("SELECT Com_Name, COUNT(*) as cnt, AVG(CAST(substr(Time, 1, 2) AS REAL) * 60 + CAST(substr(Time, 4, 2) AS REAL)) as avg_minutes FROM detections WHERE CAST(substr(Time, 1, 2) AS INTEGER) >= 22 OR CAST(substr(Time, 1, 2) AS INTEGER) < 4 GROUP BY Sci_Name HAVING cnt >= 2 ORDER BY cnt DESC LIMIT 8");
    while($row = $noct_res->fetchArray(SQLITE3_ASSOC)) { $m = $row['avg_minutes']; $hrs = intval($m / 60); $mins = intval($m) % 60; if ($hrs >= 12) { $row['avg_time'] = sprintf('%d:%02d PM', $hrs == 12 ? 12 : $hrs - 12, $mins); } else { $row['avg_time'] = sprintf('%d:%02d AM', $hrs == 0 ? 12 : $hrs, $mins); } $nocturnal[] = $row; }
    $activity_windows = []; $window_res = $db->query("SELECT Com_Name, MIN(Time) as earliest, MAX(Time) as latest, COUNT(*) as cnt FROM detections GROUP BY Sci_Name HAVING cnt >= 5 ORDER BY cnt DESC LIMIT 10");
    while($row = $window_res->fetchArray(SQLITE3_ASSOC)) { $e_h = intval(substr($row['earliest'], 0, 2)); $e_m = substr($row['earliest'], 3, 2); $l_h = intval(substr($row['latest'], 0, 2)); $l_m = substr($row['latest'], 3, 2); $row['earliest_fmt'] = sprintf('%d:%s %s', $e_h % 12 ?: 12, $e_m, $e_h < 12 ? 'AM' : 'PM'); $row['latest_fmt'] = sprintf('%d:%s %s', $l_h % 12 ?: 12, $l_m, $l_h < 12 ? 'AM' : 'PM'); $activity_windows[] = $row; }
}

if ($subview == 'migration') {
    $new_arrivals = []; $arrival_res = $db->query("SELECT d.Com_Name, d.Sci_Name, MIN(d.Date) as first_seen, COUNT(*) as cnt FROM detections d WHERE d.Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date < '$two_weeks_ago') AND d.Date >= '$two_weeks_ago' GROUP BY d.Sci_Name ORDER BY first_seen DESC LIMIT 10");
    while($row = $arrival_res->fetchArray(SQLITE3_ASSOC)) { $new_arrivals[] = $row; }
    $gone_quiet = []; $quiet_res = $db->query("SELECT Com_Name, Sci_Name, COUNT(*) as total_cnt, MAX(Date) as last_seen FROM detections WHERE Sci_Name NOT IN (SELECT DISTINCT Sci_Name FROM detections WHERE Date >= '$two_weeks_ago') GROUP BY Sci_Name HAVING total_cnt >= 5 ORDER BY last_seen DESC LIMIT 10");
    while($row = $quiet_res->fetchArray(SQLITE3_ASSOC)) { $row['days_ago'] = intval((time() - strtotime($row['last_seen'])) / 86400); $gone_quiet[] = $row; }
    $cur_yr = date('Y'); $last_yr = $cur_yr - 1;
    $yoy_res = $db->query("SELECT a.Com_Name, a.Sci_Name, a.first_this_year, b.first_last_year, CAST(julianday(a.first_this_year) - julianday(b.first_last_year_adjusted) AS INTEGER) as day_diff FROM (SELECT Com_Name, Sci_Name, MIN(Date) as first_this_year FROM detections WHERE strftime('%Y', Date) = '$cur_yr' GROUP BY Sci_Name) a INNER JOIN (SELECT Sci_Name, MIN(Date) as first_last_year, '$cur_yr' || substr(MIN(Date), 5) as first_last_year_adjusted FROM detections WHERE strftime('%Y', Date) = '$last_yr' GROUP BY Sci_Name) b ON a.Sci_Name = b.Sci_Name WHERE day_diff != 0 ORDER BY ABS(day_diff) DESC LIMIT 10");
    while($row = $yoy_res->fetchArray(SQLITE3_ASSOC)) { $yoy_comparison[] = $row; }
    $seasonal_res = $db->query("SELECT Com_Name, Sci_Name, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 1 THEN 1 ELSE 0 END) as m1, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 2 THEN 1 ELSE 0 END) as m2, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 3 THEN 1 ELSE 0 END) as m3, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 4 THEN 1 ELSE 0 END) as m4, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 5 THEN 1 ELSE 0 END) as m5, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 6 THEN 1 ELSE 0 END) as m6, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 7 THEN 1 ELSE 0 END) as m7, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 8 THEN 1 ELSE 0 END) as m8, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 9 THEN 1 ELSE 0 END) as m9, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 10 THEN 1 ELSE 0 END) as m10, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 11 THEN 1 ELSE 0 END) as m11, SUM(CASE WHEN CAST(strftime('%m', Date) AS INTEGER) = 12 THEN 1 ELSE 0 END) as m12, COUNT(*) as total FROM detections GROUP BY Sci_Name ORDER BY total DESC LIMIT 8");
    while($row = $seasonal_res->fetchArray(SQLITE3_ASSOC)) { $ma = 0; for ($i=1;$i<=12;$i++) if ($row['m'.$i]>0) $ma++; $row['months_active'] = $ma; $row['status'] = $ma >= 10 ? 'Year-round' : ($ma >= 5 ? 'Seasonal' : 'Transient'); $seasonal_top[] = $row; }
    $monthly_res = $db->query("SELECT strftime('%Y-%m', Date) as month, COUNT(DISTINCT Sci_Name) as diversity, COUNT(*) as detections FROM detections GROUP BY month ORDER BY month ASC LIMIT 24");
    while($row = $monthly_res->fetchArray(SQLITE3_ASSOC)) { $monthly_stats[] = $row; }
    $month_labels = json_encode(array_map(function($r) { return $r['month']; }, $monthly_stats));
    $month_div = json_encode(array_map(function($r) { return $r['diversity']; }, $monthly_stats));
    $month_det = json_encode(array_map(function($r) { return $r['detections']; }, $monthly_stats));
    $s_counts = $db->query("SELECT COUNT(*) as cnt FROM detections WHERE Date >= '$one_month_ago' GROUP BY Sci_Name");
    $t_30d = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Date >= '$one_month_ago'") ?: 0;
    if ($s_counts && $t_30d > 0) { while($r = $s_counts->fetchArray(SQLITE3_ASSOC)) { $pi = $r['cnt'] / $t_30d; $shannon_index -= $pi * log($pi); } }
    $shannon_index = round($shannon_index, 3);
    $diversity_score_text = ($shannon_index > 2.5) ? "Very High" : (($shannon_index > 1.8) ? "High" : (($shannon_index > 1.2) ? "Moderate" : "Low"));
    $this_month_diversity = $db->querySingle("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE strftime('%m', Date) = strftime('%m', 'now') AND strftime('%Y', Date) = strftime('%Y', 'now')") ?: 0;
    $last_year_diversity = $db->querySingle("SELECT COUNT(DISTINCT Sci_Name) FROM detections WHERE strftime('%m', Date) = strftime('%m', 'now') AND strftime('%Y', Date) = strftime('%Y', 'now', '-1 year')") ?: 0;
    $yoy_diversity_diff = $this_month_diversity - $last_year_diversity;
}

if ($subview == 'environmental') {
    $wmo_codes = [0 => 'Clear sky', 1 => 'Mostly clear', 2 => 'Partly cloudy', 3 => 'Overcast', 45 => 'Fog', 48 => 'Rime fog', 51 => 'Light drizzle', 53 => 'Moderate drizzle', 55 => 'Dense drizzle', 61 => 'Slight rain', 63 => 'Moderate rain', 65 => 'Heavy rain', 71 => 'Slight snow', 73 => 'Moderate snow', 75 => 'Heavy snow', 80 => 'Slight showers', 81 => 'Moderate showers', 82 => 'Violent showers', 95 => 'Thunderstorm', 96 => 'Thunderstorm + hail', 99 => 'Thunderstorm + heavy hail'];
    $has_weather = ($db->querySingle("SELECT COUNT(*) FROM sqlite_master WHERE type='table' AND name='weather'") > 0) && ($db->querySingle("SELECT COUNT(*) FROM weather") > 0);
    if ($has_weather) {
        $temp_res = $db->query("SELECT CASE WHEN w.Temp < 32 THEN 'Below 32°F' WHEN w.Temp BETWEEN 32 AND 45 THEN '32–45°F' WHEN w.Temp BETWEEN 46 AND 55 THEN '46–55°F' WHEN w.Temp BETWEEN 56 AND 65 THEN '56–65°F' WHEN w.Temp BETWEEN 66 AND 75 THEN '66–75°F' WHEN w.Temp BETWEEN 76 AND 85 THEN '76–85°F' ELSE 'Above 85°F' END as bracket, COUNT(*) as det_count, COUNT(DISTINCT d.Sci_Name) as species_count, ROUND(AVG(w.Temp), 1) as avg_temp FROM detections d INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour GROUP BY bracket ORDER BY MIN(w.Temp) ASC");
        while($row = $temp_res->fetchArray(SQLITE3_ASSOC)) { $temp_brackets[] = $row; }
        $cond_res = $db->query("SELECT w.ConditionCode, COUNT(*) as det_count, COUNT(DISTINCT d.Sci_Name) as species_count FROM detections d INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour GROUP BY w.ConditionCode ORDER BY det_count DESC LIMIT 8");
        while($row = $cond_res->fetchArray(SQLITE3_ASSOC)) { $code = $row['ConditionCode']; $row['description'] = isset($wmo_codes[$code]) ? $wmo_codes[$code] : "Code $code"; $condition_impact[] = $row; }
        $ideal_res = $db->query("SELECT d.Com_Name, ROUND(AVG(w.Temp), 1) as avg_temp, ROUND(MIN(w.Temp), 1) as min_temp, ROUND(MAX(w.Temp), 1) as max_temp, COUNT(*) as cnt FROM detections d INNER JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour GROUP BY d.Sci_Name HAVING cnt >= 5 ORDER BY cnt DESC LIMIT 8");
        while($row = $ideal_res->fetchArray(SQLITE3_ASSOC)) { $species_ideal[] = $row; }
        $trend_res = $db->query("SELECT d.Date, COUNT(*) as det_count, ROUND(AVG(w.Temp), 1) as avg_temp FROM detections d LEFT JOIN weather w ON d.Date = w.Date AND CAST(substr(d.Time, 1, 2) AS INTEGER) = w.Hour WHERE d.Date >= '$one_month_ago' GROUP BY d.Date ORDER BY d.Date ASC");
        while($row = $trend_res->fetchArray(SQLITE3_ASSOC)) { $temp_vs_detections[] = $row; }
        $temp_trend_labels = json_encode(array_map(function($r) { return date('M j', strtotime($r['Date'])); }, $temp_vs_detections));
        $temp_trend_temps = json_encode(array_map(function($r) { return $r['avg_temp']; }, $temp_vs_detections));
        $temp_trend_dets = json_encode(array_map(function($r) { return $r['det_count']; }, $temp_vs_detections));
    }
}

if ($subview == 'health') {
    $conf_res = $db->query("SELECT Date, ROUND(AVG(Confidence), 3) as avg_conf, COUNT(*) as det_count FROM detections WHERE Date >= '$one_month_ago' GROUP BY Date ORDER BY Date ASC");
    while($row = $conf_res->fetchArray(SQLITE3_ASSOC)) { $confidence_trend[] = $row; }
    $conf_labels_json = json_encode(array_map(function($r) { return date('M j', strtotime($r['Date'])); }, $confidence_trend));
    $conf_values_json = json_encode(array_map(function($r) { return floatval($r['avg_conf']); }, $confidence_trend));
    $overall_avg_conf = $db->querySingle("SELECT ROUND(AVG(Confidence), 3) FROM detections") ?: 0;
    $phantom_res = $db->query("SELECT Com_Name, ROUND(AVG(Confidence), 3) as avg_conf, COUNT(*) as cnt, ROUND(MIN(Confidence), 3) as min_conf FROM detections GROUP BY Sci_Name HAVING cnt >= 3 ORDER BY avg_conf ASC LIMIT 8");
    while($row = $phantom_res->fetchArray(SQLITE3_ASSOC)) { $phantom_species[] = $row; }
    $avg_daily = $db->querySingle("SELECT ROUND(AVG(cnt), 1) FROM (SELECT COUNT(*) as cnt FROM detections GROUP BY Date)") ?: 0;
    $burst_res = $db->query("SELECT Date, COUNT(*) as cnt, COUNT(DISTINCT Sci_Name) as species_count FROM detections GROUP BY Date HAVING cnt > $avg_daily * 1.5 ORDER BY cnt DESC LIMIT 5");
    while($row = $burst_res->fetchArray(SQLITE3_ASSOC)) { $burst_days[] = $row; }
    $silent_res = $db->query("SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date HAVING cnt <= 3 ORDER BY Date DESC LIMIT 5");
    while($row = $silent_res->fetchArray(SQLITE3_ASSOC)) { $silent_days[] = $row; }
    $high_conf_count = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence >= 0.8") ?: 0;
    $med_conf_count = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence >= 0.5 AND Confidence < 0.8") ?: 0;
    $low_conf_count = $db->querySingle("SELECT COUNT(*) FROM detections WHERE Confidence < 0.5") ?: 0;
    $exp_res = $db->query("SELECT Com_Name, COUNT(DISTINCT strftime('%Y', Date)) as years_present FROM detections WHERE strftime('%j', Date) BETWEEN strftime('%j', 'now', '-3 days') AND strftime('%j', 'now', '+3 days') AND strftime('%Y', Date) < strftime('%Y', 'now') GROUP BY Sci_Name ORDER BY years_present DESC LIMIT 10");
    while($row = $exp_res->fetchArray(SQLITE3_ASSOC)) { $expected_today[] = $row; }
    $top_5_res = $db->query("SELECT Sci_Name, Com_Name FROM detections GROUP BY Sci_Name ORDER BY COUNT(*) DESC LIMIT 5");
    while($row = $top_5_res->fetchArray(SQLITE3_ASSOC)) { $pw = $db->querySingle("SELECT strftime('%W', Date) as week FROM detections WHERE Sci_Name = '" . $db->escapeString($row['Sci_Name']) . "' GROUP BY week ORDER BY COUNT(*) DESC LIMIT 1"); $row['peak_week'] = $pw ?: '??'; $top_5_species[] = $row; }
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
        /* overflow: hidden; removed to allow tooltips to show */
        transition: all 0.3s ease;
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
    .insights-stats-list { display: flex; flex-direction: column; gap: 10px; padding: 20px; }
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
</style>

<div class="insights-container">
    <header class="insights-header">
        <div>
            <h1>Insights: <?php 
                if ($subview == 'dashboard') echo 'Dashboard';
                elseif ($subview == 'environmental') echo 'Weather Impacts';
                elseif ($subview == 'report') echo 'Weekly Report';
                else echo ucfirst($subview); 
            ?></h1>
            <div class="insights-subtitle">
                <?php
                switch($subview) {
                    case 'behavior': echo 'Daily activity patterns and behavioral analysis.'; break;
                    case 'migration': echo 'Seasonal trends and migration tracking.'; break;
                    case 'environmental': echo 'Correlations between weather and bird activity.'; break;
                    case 'health': echo 'Forecasting, data quality, and system health.'; break;
                    case 'report': echo 'Comprehensive summary of last week\'s activity.'; break;
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
                    <div style="font-size: 2.2em; font-weight: 900; color: var(--text-heading); line-height: 1;"><?php echo $yard_health_score; ?><span class="info-btn">ⓘ<span class="info-tooltip">A weighted index (0-100) calculated from station stability, detection volume, species rarity, and biodiversity.</span></span></div>
                    <div style="font-size: 0.7em; text-transform: uppercase; letter-spacing: 1px; color: var(--text-muted); margin-top: 4px;">Yard Score</div>
                </div>
            </div>

            <!-- Recommendations -->
            <div style="flex: 1 1 300px;">
                <h3 style="margin: 0 0 15px; font-size: 1.3em; color: var(--text-heading);">🏡 Habitat Insights & Recommendations <span class="info-btn">ⓘ<span class="info-tooltip" style="width: 300px;"><strong>Diagnostic Scan Results:</strong><br><br>• <strong>Diversity</strong>: Shannon Index (Variety & Evenness)<br>• <strong>Stability</strong>: Active vs Quiet days (last 30d)<br>• <strong>Quality</strong>: High vs Low confidence detections<br>• <strong>Activity</strong>: Current vs Recent history trends<br><br>Yard Health is a weighted average of these 4 pillars.</span></span></h3>
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
            <span class="insights-kpi-val"><?php echo number_format($lifetime_species); ?><span class="info-btn">ⓘ<span class="info-tooltip">The total count of unique bird species identified since station installation.</span></span></span>
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
            <span class="insights-kpi-val"><?php echo number_format($rare_total); ?><span class="info-btn">ⓘ<span class="info-tooltip">Species with fewer than 5 total detections at your station since installation.</span></span></span>
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
            <div class="insights-section-title">💎 Rarest Detections (&lt; 5 ever) <span class="info-btn">ⓘ<span class="info-tooltip">These species are vagrants or potential misidentifications that have appeared very infrequently at your station.</span></span></div>
            <div class="insights-stats-list" style="max-height: 400px; overflow-y: auto;">
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
    <?php endif; ?>

    <?php if ($subview == 'behavior'): ?>
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
            <div class="insights-section-title">🌅 Dawn Chorus Order <span class="info-btn">ⓘ<span class="info-tooltip">Species ranked by their earliest typical detection time (4 AM – 10 AM). This shows which birds are the early risers in your area.</span></span></div>
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
            <div class="insights-section-title">🦉 Nocturnal Activity (10 PM – 4 AM) <span class="info-btn">ⓘ<span class="info-tooltip">Bird activity detected during night hours. Includes owls, nightjars, and some late-night or early-morning songsters.</span></span></div>
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
        <div class="insights-section-title">⏱️ Activity Windows (Top Species) <span class="info-btn">ⓘ<span class="info-tooltip">The typical earliest and latest times a species is active at your station, calculated from historical density data.</span></span></div>
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
    <?php endif; ?>

    <?php if ($subview == 'migration'): ?>
    <!-- ====== PHASE 3: Migration & Seasonal Patterns ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🦅 Migration & Seasonal Patterns</h2>

    <div class="insights-sections-grid">
        <!-- New Arrivals -->
        <section class="insights-section">
            <div class="insights-section-title">🆕 New Arrivals (Last 14 Days) <span class="info-btn">ⓘ<span class="info-tooltip">Species appearing in the last 14 days that were absent for the previous 2 weeks. Useful for tracking return migration.</span></span></div>
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
            <div class="insights-section-title">🔇 Gone Quiet <span class="info-btn">ⓘ<span class="info-tooltip">Regular residents that haven't been detected in at least 14 days. This may indicate they have migrated away or changed territories.</span></span></div>
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
        <div class="insights-section-title">📅 Year-over-Year Arrival Comparison (<?php echo $last_year; ?> vs <?php echo $current_year; ?>) <span class="info-btn">ⓘ<span class="info-tooltip">Tracking if migratory species arrived earlier or later than they did in the previous calendar year.</span></span></div>
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
    <?php endif; ?>

    <?php if ($subview == 'environmental'): ?>
    <?php if (!$has_weather): ?>
        <div style="text-align: center; padding: 100px 20px; color: var(--text-muted);">
            <div style="font-size: 4em; margin-bottom: 20px;">🌤️</div>
            <h2>Weather Data Not Available</h2>
            <p>Your station does not have any weather data recorded in the database yet.</p>
            <p style="font-size: 0.9em;">Weather correlations require the <strong>Weather Plugin</strong> to be active and populated.</p>
        </div>
    <?php else: ?>
    <!-- ====== PHASE 4: Weather Correlations ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🌤️ Weather Correlations</h2>

    <!-- Temp vs Detections Chart -->
    <?php if(!empty($temp_vs_detections)): ?>
    <section class="insights-section" style="margin-bottom: 30px;">
        <div class="insights-section-title">📈 Temperature vs Detections (Last 30 Days) <span class="info-btn">ⓘ<span class="info-tooltip">A day-by-day comparison of average ambient temperature against the total number of bird detections.</span></span></div>
        <div style="padding: 20px;">
            <canvas id="tempVsDetChart" height="120"></canvas>
        </div>
    </section>
    <?php endif; ?>

    <div class="insights-sections-grid">
        <!-- Temperature Brackets -->
        <section class="insights-section">
            <div class="insights-section-title">🌡️ Detections by Temperature <span class="info-btn">ⓘ<span class="info-tooltip">Grouping activity into temperature brackets to show if your birds are more active in the heat or the cold.</span></span></div>
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
            <div class="insights-section-title">☁️ Detections by Weather Condition <span class="info-btn">ⓘ<span class="info-tooltip">Correlating bird activity with specific conditions like rain, fog, or clear skies.</span></span></div>
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
        <div class="insights-section-title">🎯 Preferred Temperature per Species <span class="info-btn">ⓘ<span class="info-tooltip">The average temperature at which each species is most frequently detected at your station.</span></span></div>
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
    <?php endif; ?>
    <?php endif; ?>

    <?php if ($subview == 'health'): ?>
    <!-- ====== PHASE 6: Confidence, Quality & Silence Anomalies ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🔍 Confidence & System Health</h2>

    <!-- Confidence KPIs -->
    <div class="insights-kpi-cards" style="margin-bottom: 30px;">
        <div class="insights-kpi-card">
            <span class="insights-kpi-val"><?php echo $overall_avg_conf; ?><span class="info-btn">ⓘ<span class="info-tooltip">The average AI classification confidence across all detections. Lower numbers may indicate background noise or interference.</span></span></span>
            <span class="insights-kpi-label">Avg Confidence</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #10b981;"><?php echo number_format($high_conf_count); ?></span>
            <span class="insights-kpi-label">High (≥80%)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #f59e0b;"><?php echo number_format($med_conf_count); ?></span>
            <span class="insights-kpi-label">Medium (50-79%)</span>
        </div>
        <div class="insights-kpi-card">
            <span class="insights-kpi-val" style="color: #ef4444;"><?php echo number_format($low_conf_count); ?></span>
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
            <div class="insights-section-title">👻 Phantom Suspects (Lowest Confidence) <span class="info-btn">ⓘ<span class="info-tooltip">Species with many detections but very low average confidence. These are likely false positives/misidentifications.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($phantom_species)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">No data yet</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($phantom_species as $ph): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name" style="margin-bottom: 2px;"><?php echo $ph['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);"><?php echo $ph['cnt']; ?> detections · Min: <?php echo $ph['min_conf']; ?></div>
                    </div>
                    <?php
                        $conf_color = $ph['avg_conf'] >= 0.7 ? '#10b981' : ($ph['avg_conf'] >= 0.5 ? '#f59e0b' : '#ef4444');
                    ?>
                    <span class="insights-stats-count" style="color: <?php echo $conf_color; ?>;">~<?php echo $ph['avg_conf']; ?></span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
        </section>

        <!-- Detection Bursts & Silent Days -->
        <section class="insights-section">
            <div class="insights-section-title">📊 Anomaly Days <span class="info-btn">ⓘ<span class="info-tooltip">Statistical outliers: 'Bursts' indicate unusual activity spikes, while 'Quiet Days' may signal station downtime or storms.</span></span></div>
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

    <!-- ====== PHASE 7: Long-term Trends & Diversity ====== -->
    <h2 style="margin: 50px 0 20px; font-size: 1.5em; color: var(--text-heading);">📈 Long-term Trends & Diversity</h2>

    <div class="insights-kpi-cards" style="margin-bottom: 30px;">
        <!-- Shannon Diversity Index Card -->
        <div class="insights-kpi-card" style="flex: 1 1 300px;">
            <div>
                <span class="insights-kpi-val"><?php echo $shannon_index; ?><span class="info-btn">ⓘ<span class="info-tooltip">A scientific measure of biodiversity that counts both species richness and population balance (evenness). Higher is better.</span></span></span>
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
    <?php endif; ?>

    <?php if ($subview == 'health'): ?>
    <!-- ====== PHASE 8: Forecasting & Predictions ====== -->
    <h2 style="margin: 40px 0 20px; font-size: 1.5em; color: var(--text-heading);">🔮 Forecasting & Predictions</h2>

    <div class="insights-sections-grid">
        <!-- Expect Today -->
        <section class="insights-section">
            <div class="insights-section-title">📅 Expected Today (Historical Consistency) <span class="info-btn">ⓘ<span class="info-tooltip">Species that have appeared within +/- 3 days of today's date in previous years. Predicts seasonal residents.</span></span></div>
            <div class="insights-stats-list">
                <?php if(empty($expected_today)): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name">Not enough historical data for this date</span>
                    <span class="insights-stats-count">—</span>
                </div>
                <?php else: ?>
                <?php foreach($expected_today as $exp): ?>
                <div class="insights-stats-item">
                    <span class="insights-stats-name"><?php echo $exp['Com_Name']; ?></span>
                    <span class="insights-stats-count">Present in <?php echo $exp['years_present']; ?> past years</span>
                </div>
                <?php endforeach; ?>
                <?php endif; ?>
            </div>
            <div style="padding: 10px 15px; font-size: 0.8em; color: var(--text-muted); border-top: 1px solid var(--border-light);">
                Based on species detected within +/- 3 days of today in previous years.
            </div>
        </section>

        <!-- Peak Weeks -->
        <section class="insights-section">
            <div class="insights-section-title">📍 Historical Peak Weeks <span class="info-btn">ⓘ<span class="info-tooltip">The specific week of the year (1-52) when each species has historically reached its maximum detection volume.</span></span></div>
            <div class="insights-stats-list">
                <?php foreach($top_5_species as $s): ?>
                <div class="insights-stats-item">
                    <div>
                        <div class="insights-stats-name"><?php echo $s['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Current week: <?php echo $current_week; ?></div>
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
                <?php endforeach; ?>
            </div>
        </section>
    </div>
    <?php endif; ?>
    
    <?php if ($subview == 'report'): ?>
        <?php include 'weekly_report.php'; ?>
    <?php endif; ?>
</div>

<!-- Chart.js for Hourly Activity -->
<script src="static/Chart.bundle.js"></script>
<script>
document.addEventListener('DOMContentLoaded', function() {
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
                            return tooltipItem.yLabel.toLocaleString() + ' detections';
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

    <?php if ($subview == 'migration'): ?>
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
