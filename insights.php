<?php
require_once 'scripts/common.php';
require_once 'scripts/insights_logic.php';
$config = get_config();
?>

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
