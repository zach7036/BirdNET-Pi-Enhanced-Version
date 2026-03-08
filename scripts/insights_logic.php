<?php
require_once __DIR__ . '/common.php';
$db_path = __ROOT__ . '/scripts/birds.db';
$db = new SQLite3($db_path, SQLITE3_OPEN_READONLY);
if (!$db) {
    echo "<!-- ERROR: Could not open database at $db_path -->";
    $lifetime_species = 0;
    $best_day_count = 0;
    $max_streak = 0;
    $rarest = [];
    $milestones = [];
    return;
}
$db->busyTimeout(1000);

// 1. Lifetime Species
$lifetime_species = $db->querySingle('SELECT COUNT(DISTINCT(Sci_Name)) FROM detections') ?: 0;

// 2. Best Day Count
$best_day_res = $db->query('SELECT Date, COUNT(*) as cnt FROM detections GROUP BY Date ORDER BY cnt DESC LIMIT 1')->fetchArray(SQLITE3_ASSOC);
$best_day_count = $best_day_res ? $best_day_res['cnt'] : 0;
$best_day_date = $best_day_res ? date('M j, Y', strtotime($best_day_res['Date'])) : 'N/A';

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
$tot_det_res = $db->querySingle('SELECT COUNT(*) FROM detections');
$total_detections = $tot_det_res ?: 0;
$first_det = $db->querySingle('SELECT MIN(Date) FROM detections');
$milestones[] = ["title" => "First Detection", "val" => $first_det ?: 'N/A'];
$milestones[] = ["title" => "Lifetime Detections", "val" => number_format($total_detections)];

// Top Daily Record for a Single Species
$top_spec_day = $db->query('SELECT Com_Name, Date, COUNT(*) as cnt FROM detections GROUP BY Sci_Name, Date ORDER BY cnt DESC LIMIT 1')->fetchArray(SQLITE3_ASSOC);
if ($top_spec_day) {
    $milestones[] = ["title" => "Single Day Record", "val" => $top_spec_day['cnt'] . " " . $top_spec_day['Com_Name'] . " on " . date('M j, Y', strtotime($top_spec_day['Date']))];
}

?>
