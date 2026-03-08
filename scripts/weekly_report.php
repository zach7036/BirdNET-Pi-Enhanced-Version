<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';

// Determine the start and end of the most recently completed week (Sunday to Saturday)
// If today is Sunday, the completed week is the one that ended yesterday.
if (date('w') == 0) {
    // Today is Sunday, 'last sunday' would be 7 days ago, so we use 'today' as the anchor.
    $anchor_sunday = strtotime('today');
} else {
    $anchor_sunday = strtotime('last sunday');
}

$startdate = $anchor_sunday - (7 * 86400);
$enddate = $anchor_sunday - (1 * 86400);

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// 1. Fetch species counts for the current week
$stmt1 = $db->prepare('SELECT Sci_Name, Com_Name, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY Sci_Name ORDER BY cnt DESC');
$stmt1->bindValue(':start', date("Y-m-d", $startdate));
$stmt1->bindValue(':end', date("Y-m-d", $enddate));
ensure_db_ok($stmt1);
$result1 = $stmt1->execute();

$detections = [];
while ($row = $result1->fetchArray(SQLITE3_ASSOC)) {
    $sci_name = $row['Sci_Name'];
    $com_name = $row['Com_Name'];
    $count = $row['cnt'];

    // 2. Prior week comparison
    $prior_start = date("Y-m-d", $startdate - (7 * 86400));
    $prior_end = date("Y-m-d", $enddate - (7 * 86400));
    $stmt2 = $db->prepare('SELECT COUNT(*) as cnt FROM detections WHERE Sci_Name = :sci AND Date BETWEEN :pstart AND :pend');
    $stmt2->bindValue(':sci', $sci_name);
    $stmt2->bindValue(':pstart', $prior_start);
    $stmt2->bindValue(':pend', $prior_end);
    $prior_count = $stmt2->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    // 3. Check if first seen (never seen before this week)
    $stmt3 = $db->prepare('SELECT COUNT(*) as cnt FROM detections WHERE Sci_Name = :sci AND Date < :start');
    $stmt3->bindValue(':sci', $sci_name);
    $stmt3->bindValue(':start', date("Y-m-d", $startdate));
    $ever_seen_before = $stmt3->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    $detections[] = [
        'name' => $com_name,
        'sci' => $sci_name,
        'count' => $count,
        'prior_count' => $prior_count,
        'is_first_seen' => ($ever_seen_before == 0)
    ];
}

// Summary stats
$total_detections = array_sum(array_column($detections, 'count'));
$unique_species = count($detections);

$stmt_p = $db->prepare('SELECT COUNT(*) as cnt FROM detections WHERE Date BETWEEN :pstart AND :pend');
$stmt_p->bindValue(':pstart', date("Y-m-d", $startdate - (7 * 86400)));
$stmt_p->bindValue(':pend', date("Y-m-d", $enddate - (7 * 86400)));
$prior_total = $stmt_p->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

$stmt_ps = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) as cnt FROM detections WHERE Date BETWEEN :pstart AND :pend');
$stmt_ps->bindValue(':pstart', date("Y-m-d", $startdate - (7 * 86400)));
$stmt_ps->bindValue(':pend', date("Y-m-d", $enddate - (7 * 86400)));
$prior_species = $stmt_ps->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

// 4. Fetch additional KPIs
$daily_avg = round($total_detections / 7);

// Busiest Day
$stmt_bd = $db->prepare('SELECT Date, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY Date ORDER BY cnt DESC LIMIT 1');
$stmt_bd->bindValue(':start', date("Y-m-d", $startdate));
$stmt_bd->bindValue(':end', date("Y-m-d", $enddate));
$bd_res = $stmt_bd->execute()->fetchArray(SQLITE3_ASSOC);
$busiest_day_name = $bd_res ? date('l', strtotime($bd_res['Date'])) : 'N/A';
$busiest_day_count = $bd_res ? $bd_res['cnt'] : 0;

// Peak Hour
$stmt_ph = $db->prepare('SELECT strftime("%H", Time) as hr, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY hr ORDER BY cnt DESC LIMIT 1');
$stmt_ph->bindValue(':start', date("Y-m-d", $startdate));
$stmt_ph->bindValue(':end', date("Y-m-d", $enddate));
$ph_res = $stmt_ph->execute()->fetchArray(SQLITE3_ASSOC);
$peak_time = $ph_res ? $ph_res['hr'] . ":00" : 'N/A';

function get_trend_html($current, $prior) {
    if ($prior == 0) return $current > 0 ? '<span class="trend up">NEW</span>' : '';
    $diff = (($current - $prior) / $prior) * 100;
    $class = $diff >= 0 ? 'up' : 'down';
    $sign = $diff >= 0 ? '+' : '';
    return sprintf('<span class="trend %s">%s%d%%</span>', $class, $sign, round($diff));
}

if (isset($_GET['ascii'])) {
    // Keep legacy ASCII support if needed, but simplified
    echo "BirdNET-Pi Weekly Report (Week " . date('W', $enddate) . ")\n";
    echo "Range: " . date('Y-m-d', $startdate) . " to " . date('Y-m-d', $enddate) . "\n\n";
    echo "Total Detections: $total_detections\n";
    echo "Unique Species: $unique_species\n\n";
    echo "Top 10 Species:\n";
    for ($i = 0; $i < min(10, count($detections)); $i++) {
        $d = $detections[$i];
        echo "- " . $d['name'] . ": " . $d['count'] . "\n";
    }
    die();
}
?>

<style>
    .report-container {
        padding: 20px;
        max-width: 1200px;
        margin: 0 auto;
        color: var(--text-primary);
    }
    .report-header {
        text-align: center;
        margin-bottom: 30px;
        background: var(--bg-card);
        padding: 30px;
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-md);
    }
    .report-header h1 {
        margin: 0;
        font-size: 2.2em;
        background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .report-date {
        color: var(--text-secondary);
        font-size: 1.1em;
        margin-top: 10px;
    }
    .kpi-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(240px, 1fr));
        gap: 20px;
        margin-bottom: 40px;
    }
    .kpi-card {
        background: var(--bg-card);
        padding: 24px;
        border-radius: 16px;
        border: 1px solid var(--border);
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s;
    }
    .kpi-card:hover { transform: translateY(-5px); }
    .kpi-val { font-size: 2.1em; font-weight: 800; display: block; margin-bottom: 4px; white-space: nowrap; }
    .kpi-label { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }
    
    .trend { font-size: 0.7em; padding: 2px 8px; border-radius: 10px; font-weight: bold; margin-left: 8px; vertical-align: middle; }
    .trend.up { background: #dcfce7; color: #166534; }
    .trend.down { background: #fee2e2; color: #991b1b; }

    .sections-grid {
        display: grid;
        grid-template-columns: 1fr 1fr;
        gap: 30px;
    }
    .report-section {
        background: var(--bg-card);
        border-radius: 16px;
        border: 1px solid var(--border);
        overflow: hidden;
        box-shadow: var(--shadow-sm);
    }
    .section-title {
        background: var(--bg-table-row);
        padding: 15px 20px;
        font-weight: 700;
        border-bottom: 1px solid var(--border);
        font-size: 1.1em;
    }
    .report-list { list-style: none; padding: 0; margin: 0; }
    .report-item {
        padding: 12px 20px;
        display: flex;
        justify-content: space-between;
        align-items: center;
        border-bottom: 1px solid var(--border-light);
    }
    .report-item:last-child { border-bottom: none; }
    .report-item:hover { background: rgba(0,0,0,0.02); }
    .species-info { display: flex; flex-direction: column; }
    .species-name { font-weight: 600; font-size: 1.05em; }
    .species-sci { font-style: italic; font-size: 0.85em; color: var(--text-secondary); }
    .count-box { text-align: right; }
    .count-num { font-weight: 700; font-size: 1.1em; }

    @media (max-width: 800px) {
        .sections-grid { grid-template-columns: 1fr; }
    }
</style>

<div class="report-container">
    <header class="report-header">
        <h1>Week <?php echo date('W', $enddate); ?> Report</h1>
        <div class="report-date"><?php echo date('M jS', $startdate); ?> — <?php echo date('M jS, Y', $enddate); ?></div>
    </header>

    <div class="kpi-grid">
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($total_detections); ?></span>
            <span class="kpi-label">Total Detections <?php echo get_trend_html($total_detections, $prior_total); ?></span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($daily_avg); ?></span>
            <span class="kpi-label">Daily Average</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($unique_species); ?></span>
            <span class="kpi-label">Unique Species <?php echo get_trend_html($unique_species, $prior_species); ?></span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo $busiest_day_name; ?></span>
            <span class="kpi-label">Busiest Day (<?php echo number_format($busiest_day_count); ?>)</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo $peak_time; ?></span>
            <span class="kpi-label">Peak Activity Hour</span>
        </div>
    </div>

    <div class="sections-grid">
        <section class="report-section">
            <div class="section-title">🏆 Top 10 Species</div>
            <ul class="report-list">
                <?php
                for ($i = 0; $i < min(10, count($detections)); $i++) {
                    $d = $detections[$i];
                    echo '<li class="report-item">';
                    echo '  <div class="species-info">';
                    echo '    <span class="species-name">' . $d['name'] . '</span>';
                    echo '    <span class="species-sci">' . $d['sci'] . '</span>';
                    echo '  </div>';
                    echo '  <div class="count-box">';
                    echo '    <span class="count-num">' . number_format($d['count']) . '</span>';
                    echo '    ' . get_trend_html($d['count'], $d['prior_count']);
                    echo '  </div>';
                    echo '</li>';
                }
                ?>
            </ul>
        </section>

        <section class="report-section">
            <div class="section-title">✨ First Time Seen This Week</div>
            <ul class="report-list">
                <?php
                $new_count = 0;
                foreach ($detections as $d) {
                    if ($d['is_first_seen']) {
                        $new_count++;
                        echo '<li class="report-item">';
                        echo '  <div class="species-info">';
                        echo '    <span class="species-name">' . $d['name'] . '</span>';
                        echo '    <span class="species-sci">' . $d['sci'] . '</span>';
                        echo '  </div>';
                        echo '  <div class="count-box">';
                        echo '    <span class="count-num">' . number_format($d['count']) . '</span>';
                        echo '  </div>';
                        echo '</li>';
                    }
                }
                if ($new_count == 0) {
                    echo '<li class="report-item" style="justify-content:center; color:var(--text-muted); padding:40px;">No new species detected this week.</li>';
                }
                ?>
            </ul>
        </section>
    </div>

    <footer style="margin-top: 40px; text-align: center; color: var(--text-muted); font-size: 0.9em;">
        * Trends are calculated relative to week <?php echo date('W', $enddate) - 1; ?><br>
        * Data range: <?php echo date('Y-m-d', $startdate); ?> to <?php echo date('Y-m-d', $enddate); ?>
    </footer>
</div>
