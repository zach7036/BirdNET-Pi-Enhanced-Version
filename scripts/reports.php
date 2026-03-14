<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);
require_once 'scripts/common.php';

// --- Parameter Handling ---
$type = isset($_GET['type']) ? $_GET['type'] : 'weekly';
$date_raw = isset($_GET['date']) ? $_GET['date'] : 'today';

// --- Date Range Calculation ---
if (!isset($_GET['date'])) {
    // Default to CURRENT period
    $current_time = time();
} else {
    $current_time = strtotime($_GET['date']);
}

if (!$current_time) $current_time = time();

$startdate = 0;
$enddate = 0;
$prior_start = 0;
$prior_end = 0;
$title = "";
$date_label = "";

switch ($type) {
    case 'yearly':
        $year = date('Y', $current_time);
        $startdate = strtotime("$year-01-01");
        $enddate = strtotime("$year-12-31");
        $prior_start = strtotime(($year - 1) . "-01-01");
        $prior_end = strtotime(($year - 1) . "-12-31");
        $title = "$year Yearly Report";
        $date_label = "Year of $year";
        $prev_date = date('Y-m-d', strtotime("-1 year", $startdate));
        $next_date = date('Y-m-d', strtotime("+1 year", $startdate));
        break;

    case 'monthly':
        $month_start = date('Y-m-01', $current_time);
        $startdate = strtotime($month_start);
        $enddate = strtotime("last day of this month", $startdate);
        $prior_start = strtotime("-1 month", $startdate);
        $prior_end = strtotime("last day of this month", $prior_start);
        $title = date('F Y', $startdate) . " Monthly Report";
        $date_label = date('F Y', $startdate);
        $prev_date = date('Y-m-d', $prior_start);
        $next_date = date('Y-m-d', strtotime("+1 month", $startdate));
        break;

    case 'weekly':
    default:
        $type = 'weekly';
        // Anchor to the Sunday of the week containing $current_time
        $w = date('w', $current_time);
        $anchor_sunday = $current_time - ($w * 86400);
        $startdate = $anchor_sunday;
        $enddate = $anchor_sunday + (6 * 86400);
        
        $prior_start = $startdate - (7 * 86400);
        $prior_end = $enddate - (7 * 86400);
        
        $title = "Week " . date('W', $enddate) . " Report";
        $date_label = date('M jS', $startdate) . " — " . date('M jS, Y', $enddate);
        $prev_date = date('Y-m-d', $prior_start);
        $next_date = date('Y-m-d', $enddate + 86400);
        break;
}

$start_str = date("Y-m-d", $startdate);
$end_str = date("Y-m-d", $enddate);
$prior_start_str = date("Y-m-d", $prior_start);
$prior_end_str = date("Y-m-d", $prior_end);

$db = new SQLite3('./scripts/birds.db', SQLITE3_OPEN_READONLY);
$db->busyTimeout(1000);

// 1. Fetch species counts for the current period
$stmt1 = $db->prepare('SELECT Sci_Name, Com_Name, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY Sci_Name ORDER BY cnt DESC');
$stmt1->bindValue(':start', $start_str);
$stmt1->bindValue(':end', $end_str);
ensure_db_ok($stmt1);
$result1 = $stmt1->execute();

$detections = [];
while ($row = $result1->fetchArray(SQLITE3_ASSOC)) {
    $sci_name = $row['Sci_Name'];
    $com_name = $row['Com_Name'];
    $count = $row['cnt'];

    // 2. Prior period comparison
    $stmt2 = $db->prepare('SELECT COUNT(*) as cnt FROM detections WHERE Sci_Name = :sci AND Date BETWEEN :pstart AND :pend');
    $stmt2->bindValue(':sci', $sci_name);
    $stmt2->bindValue(':pstart', $prior_start_str);
    $stmt2->bindValue(':pend', $prior_end_str);
    $prior_count = $stmt2->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

    // 3. Check if first seen (never seen before this period)
    $stmt3 = $db->prepare('SELECT COUNT(*) as cnt FROM detections WHERE Sci_Name = :sci AND Date < :start');
    $stmt3->bindValue(':sci', $sci_name);
    $stmt3->bindValue(':start', $start_str);
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
$stmt_p->bindValue(':pstart', $prior_start_str);
$stmt_p->bindValue(':pend', $prior_end_str);
$prior_total = $stmt_p->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

$stmt_ps = $db->prepare('SELECT COUNT(DISTINCT(Sci_Name)) as cnt FROM detections WHERE Date BETWEEN :pstart AND :pend');
$stmt_ps->bindValue(':pstart', $prior_start_str);
$stmt_ps->bindValue(':pend', $prior_end_str);
$prior_species = $stmt_ps->execute()->fetchArray(SQLITE3_ASSOC)['cnt'];

// 4. Fetch additional KPIs
$days_in_period = max(1, round(($enddate - $startdate) / 86400) + 1);
$daily_avg = round($total_detections / $days_in_period);

// Busiest Day
$stmt_bd = $db->prepare('SELECT Date, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY Date ORDER BY cnt DESC LIMIT 1');
$stmt_bd->bindValue(':start', $start_str);
$stmt_bd->bindValue(':end', $end_str);
$bd_res = $stmt_bd->execute()->fetchArray(SQLITE3_ASSOC);
$busiest_day_name = $bd_res ? date('M jS', strtotime($bd_res['Date'])) : 'N/A';
$busiest_day_count = $bd_res ? $bd_res['cnt'] : 0;

// Peak Hour
$stmt_ph = $db->prepare('SELECT strftime("%H", Time) as hr, COUNT(*) as cnt FROM detections WHERE Date BETWEEN :start AND :end GROUP BY hr ORDER BY cnt DESC LIMIT 1');
$stmt_ph->bindValue(':start', $start_str);
$stmt_ph->bindValue(':end', $end_str);
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
    echo "BirdNET-Pi Periodic Report - $title\n";
    echo "Range: $start_str to $end_str\n\n";
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
        padding: <?php echo (isset($subview) && $subview == 'report') ? '0' : '20px'; ?>;
        max-width: <?php echo (isset($subview) && $subview == 'report') ? 'none' : '1200px'; ?>;
        margin: <?php echo (isset($subview) && $subview == 'report') ? '0' : '0 auto'; ?>;
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
        display: flex;
        flex-direction: column;
        align-items: center;
        gap: 10px;
    }
    .report-nav-row {
        display: flex;
        align-items: center;
        justify-content: center;
        gap: 30px;
        width: 100%;
        margin-top: 10px;
    }
    .nav-arrow {
        font-size: 1.5em;
        color: var(--accent);
        text-decoration: none;
        padding: 5px 15px;
        border-radius: 10px;
        background: var(--bg-table-row);
        transition: all 0.2s;
    }
    .nav-arrow:hover {
        background: var(--accent);
        color: white;
    }
    .report-header h1 {
        margin: 0;
        font-size: 2.2em;
        background: linear-gradient(135deg, var(--accent) 0%, #6366f1 100%);
        -webkit-background-clip: text;
        -webkit-text-fill-color: transparent;
    }
    .period-tabs {
        display: flex;
        gap: 10px;
        margin-bottom: 15px;
    }
    .period-tab {
        padding: 6px 16px;
        border-radius: 20px;
        background: var(--bg-table-row);
        color: var(--text-secondary);
        text-decoration: none;
        font-size: 0.9em;
        font-weight: 600;
        transition: all 0.2s;
        border: 1px solid var(--border);
    }
    .period-tab.active {
        background: var(--accent);
        color: white;
        border-color: var(--accent);
    }
    .report-date {
        color: var(--text-secondary);
        font-size: 1.1em;
    }
    .kpi-cards {
        display: flex;
        flex-wrap: wrap;
        gap: 20px;
        margin-bottom: 40px;
        width: 100%;
    }
    .kpi-card {
        background: var(--bg-card);
        padding: 24px 15px;
        border-radius: 16px;
        border: 1px solid var(--border);
        text-align: center;
        box-shadow: var(--shadow-sm);
        transition: transform 0.2s, z-index 0.2s;
        flex: 1 1 180px;
        min-width: 180px;
        max-width: none;
        position: relative;
        z-index: 1;
        overflow: visible !important;
    }
    .kpi-card:hover { transform: translateY(-5px); z-index: 1000; }
    .kpi-val { font-size: 2em; font-weight: 800; display: block; margin-bottom: 4px; white-space: nowrap; }
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
        box-shadow: var(--shadow-sm);
        position: relative;
        z-index: 1;
        overflow: visible !important;
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
    .species-info { display: flex; flex-direction: column; }
    .species-name { font-weight: 600; font-size: 1.05em; }
    .species-sci { font-style: italic; font-size: 0.85em; color: var(--text-secondary); }
    .count-box { text-align: right; }
    .count-num { font-weight: 700; font-size: 1.1em; }

    @media (max-width: 800px) {
        .sections-grid { grid-template-columns: 1fr; }
    }

    /* Info Tooltip Styles */
    .info-btn {
        display: inline-flex;
        align-items: center;
        justify-content: center;
        width: 18px;
        height: 18px;
        background: var(--accent-subtle, rgba(99, 102, 241, 0.1));
        color: var(--accent, #6366f1);
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
        background: var(--accent, #6366f1);
        color: white;
        z-index: 10000;
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
        box-shadow: 0 10px 15px -3px rgba(0, 0, 0, 0.1);
        pointer-events: none;
        opacity: 0;
        transition: all 0.2s ease;
        z-index: 9999;
        font-weight: 400;
        text-align: left;
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
</style>

<div class="report-container">
    <header class="report-header">
        <div class="period-tabs">
            <a href="?view=Insights&subview=report&type=weekly" class="period-tab <?php echo $type=='weekly'?'active':''; ?>">Weekly</a>
            <a href="?view=Insights&subview=report&type=monthly" class="period-tab <?php echo $type=='monthly'?'active':''; ?>">Monthly</a>
            <a href="?view=Insights&subview=report&type=yearly" class="period-tab <?php echo $type=='yearly'?'active':''; ?>">Yearly</a>
        </div>
        
        <div class="report-nav-row">
            <a href="?view=Insights&subview=report&type=<?php echo $type; ?>&date=<?php echo $prev_date; ?>" class="nav-arrow" title="Previous Period">❮</a>
            <h1><?php echo $title; ?></h1>
            <a href="?view=Insights&subview=report&type=<?php echo $type; ?>&date=<?php echo $next_date; ?>" class="nav-arrow" title="Next Period">❯</a>
        </div>
        
        <div class="report-date"><?php echo $date_label; ?></div>
    </header>

    <div class="kpi-cards">
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($total_detections); ?></span>
            <span class="kpi-label">Total Detections <?php echo get_trend_html($total_detections, $prior_total); ?> <span class="info-btn">ⓘ<span class="info-tooltip">Total number of bird sounds identified during this <?php echo $type; ?> period.</span></span></span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($daily_avg); ?></span>
            <span class="kpi-label">Daily Average</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($unique_species); ?></span>
            <span class="kpi-label">Unique Species <?php echo get_trend_html($unique_species, $prior_species); ?> <span class="info-btn">ⓘ<span class="info-tooltip">Number of different bird species identified in this period.</span></span></span>
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
            <div class="section-title">🏆 Top Species <span class="info-btn">ⓘ<span class="info-tooltip">The most frequently detected species of the period, ranked by total count.</span></span></div>
            <ul class="report-list">
                <?php
                $rank = 1;
                foreach ($detections as $d) {
                    $hidden_class = ($rank > 10) ? 'hidden-item' : '';
                    echo '<li class="report-item ' . $hidden_class . '" style="display:' . ($rank > 10 ? 'none' : 'flex') . '">';
                    echo '  <div class="species-info">';
                    echo '    <span class="species-name">' . $d['name'] . '</span>';
                    echo '    <span class="species-sci">' . $d['sci'] . '</span>';
                    echo '  </div>';
                    echo '  <div class="count-box">';
                    echo '    <span class="count-num">' . number_format($d['count']) . '</span>';
                    echo '    ' . get_trend_html($d['count'], $d['prior_count']);
                    echo '  </div>';
                    echo '</li>';
                    $rank++;
                }
                ?>
            </ul>
            <?php if (count($detections) > 10): ?>
            <button class="show-list-btn" onclick="toggleItems(this)" data-expanded="false" 
                    data-show-text="Show all <?php echo count($detections); ?> species ↓" 
                    data-hide-text="Show top 10 species ↑">
                Show all <?php echo count($detections); ?> species ↓
            </button>
            <?php endif; ?>
        </section>

        <section class="report-section">
            <div class="section-title">✨ First Time Seen <span class="info-btn">ⓘ<span class="info-tooltip">Species that were detected this period but have NO record in the system before this period started.</span></span></div>
            <ul class="report-list">
                <?php
                $new_count = 0;
                $new_rank = 1;
                foreach ($detections as $d) {
                    if ($d['is_first_seen']) {
                        $new_count++;
                        $hidden_class = ($new_rank > 10) ? 'hidden-item' : '';
                        echo '<li class="report-item ' . $hidden_class . '" style="display:' . ($new_rank > 10 ? 'none' : 'flex') . '">';
                        echo '  <div class="species-info">';
                        echo '    <span class="species-name">' . $d['name'] . '</span>';
                        echo '    <span class="species-sci">' . $d['sci'] . '</span>';
                        echo '  </div>';
                        echo '  <div class="count-box">';
                        echo '    <span class="count-num">' . number_format($d['count']) . '</span>';
                        echo '  </div>';
                        echo '</li>';
                        $new_rank++;
                    }
                }
                if ($new_count == 0) {
                    echo '<li class="report-item" style="justify-content:center; color:var(--text-muted); padding:40px;">No new species detected this period.</li>';
                }
                ?>
            </ul>
            <?php if ($new_count > 10): ?>
            <button class="show-list-btn" onclick="toggleItems(this)" data-expanded="false" 
                    data-show-text="Show all <?php echo $new_count; ?> new species ↓" 
                    data-hide-text="Show top 10 new species ↑">
                Show all <?php echo $new_count; ?> new species ↓
            </button>
            <?php endif; ?>
        </section>
    </div>

    <footer style="margin-top: 40px; text-align: center; color: var(--text-muted); font-size: 0.9em;">
        * Trends are calculated relative to the previous <?php echo $type; ?> period.<br>
        * Data range: <?php echo $start_str; ?> to <?php echo $end_str; ?>
    </footer>
</div>

<script>
function toggleItems(btn) {
    const list = btn.previousElementSibling;
    const items = list.querySelectorAll('.report-item');
    const isExpanded = btn.getAttribute('data-expanded') === 'true';
    
    items.forEach((item, index) => {
        if (index >= 10) {
            item.style.display = isExpanded ? 'none' : 'flex';
        }
    });
    
    btn.innerHTML = isExpanded ? btn.getAttribute('data-show-text') : btn.getAttribute('data-hide-text');
    btn.setAttribute('data-expanded', !isExpanded);
}
</script>
