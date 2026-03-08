<?php
ini_set('display_errors', 1);
ini_set('display_startup_errors', 1);
error_reporting(E_ALL);

require_once __DIR__ . '/common.php';
require_once __DIR__ . '/insights_logic.php';
$config = get_config();
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
        transition: transform 0.2s;
        flex: 1 1 180px;
        min-width: 180px;
    }
    .kpi-card:hover { transform: translateY(-5px); }
    .kpi-val { font-size: 2em; font-weight: 800; display: block; margin-bottom: 4px; white-space: nowrap; }
    .kpi-label { font-size: 0.85em; text-transform: uppercase; letter-spacing: 0.5px; color: var(--text-muted); }

    .sections-grid {
        display: grid;
        grid-template-columns: repeat(auto-fit, minmax(400px, 1fr));
        gap: 30px;
    }
    .report-section {
        background: var(--bg-card);
        border-radius: 20px;
        border: 1px solid var(--border);
        box-shadow: var(--shadow-sm);
        overflow: hidden;
    }
    .section-title {
        background: var(--bg-primary);
        padding: 15px 20px;
        font-weight: bold;
        border-bottom: 1px solid var(--border);
        font-size: 1.1em;
        display: flex;
        align-items: center;
        gap: 10px;
    }
    .stats-list { display: flex; flex-direction: column; gap: 8px; padding: 15px; }
    .stats-item { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        padding: 12px 15px; 
        background: var(--bg-primary); 
        border-radius: 12px;
        border: 1px solid var(--border-light);
    }
    .stats-name { font-weight: 600; color: var(--text-heading); }
    .stats-count { font-weight: 800; color: var(--accent); }
</style>

<div class="report-container">
    <header class="report-header">
        <h1>BirdNET Insights</h1>
        <div class="report-date">Deep behavioral analysis and seasonal trends for your station.</div>
    </header>

    <div class="kpi-cards">
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($lifetime_species); ?></span>
            <span class="kpi-label">Lifetime Species</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($best_day_count); ?></span>
            <span class="kpi-label">Best Day (<?php echo $best_day_date; ?>)</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo $max_streak; ?> Days</span>
            <span class="kpi-label">Longest Streak</span>
        </div>
        <div class="kpi-card">
            <span class="kpi-val"><?php echo number_format($rare_total); ?></span>
            <span class="kpi-label">Rare Species</span>
        </div>
    </div>

    <div class="sections-grid">
        <section class="report-section">
            <div class="section-title">🏆 Personal Records & Milestones</div>
            <div class="stats-list" style="padding: 10px 0;">
                <?php foreach($milestones as $m): ?>
                <div class="stats-item">
                    <span class="stats-name"><?php echo $m['title']; ?></span>
                    <span class="stats-count"><?php echo $m['val']; ?></span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>

        <section class="report-section">
            <div class="section-title">💎 Rarest Detections (< 5 ever)</div>
            <div class="stats-list" style="padding: 10px 0;">
                <?php foreach($rarest as $r): ?>
                <div class="stats-item">
                    <div>
                        <div class="stats-name" style="margin-bottom: 2px;"><?php echo $r['Com_Name']; ?></div>
                        <div style="font-size: 0.8em; color: var(--text-muted);">Seen: <?php echo date('M j, Y', strtotime($r['last_seen'])); ?></div>
                    </div>
                    <span class="stats-count"><?php echo $r['cnt']; ?>x</span>
                </div>
                <?php endforeach; ?>
            </div>
        </section>
    </div>
</div>

<style>
    .stats-list { display: flex; flex-direction: column; gap: 8px; }
    .stats-item { 
        display: flex; 
        justify-content: space-between; 
        align-items: center; 
        padding: 12px 15px; 
        background: var(--bg-primary); 
        border-radius: 12px;
        border: 1px solid var(--border-light);
    }
    .stats-name { font-weight: 600; color: var(--text-heading); }
    .stats-count { font-weight: 800; color: var(--accent); }
</style>
