<?php
/**
 * materials/daily_trends.php — recurring-problem view for the daily audit.
 *
 * Answers "what keeps coming up" across many days of mm_daily_checks:
 * materials repeatedly marked damaged/needing attention, which shelves have
 * the most issues, and how issue volume moves week over week.
 *
 *   GET ?days=30|90|180
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user = require_module('materials');

$days = (int)($_GET['days'] ?? 90);
if (!in_array($days, [30, 90, 180, 365], true)) $days = 90;

$materials  = mm_daily_trend_materials($days);
$categories = mm_daily_trend_categories($days);
$weekly     = mm_daily_trend_weekly((int)ceil($days / 7));

$pageTitle = 'Daily audit trends';
require __DIR__ . '/../includes/header.php';
?>

<style>
.mmt-bar { display: block; height: 10px; border-radius: 6px; background: #eee; overflow: hidden; }
.mmt-bar i { display: block; height: 100%; background: #b3261e; }
</style>

<div class="page-head">
    <div>
        <h1>Recurring problems</h1>
        <p class="muted">Materials and shelves that keep needing attention or repair — last <?= (int)$days ?> days.</p>
    </div>
    <div class="head-actions">
        <a class="btn btn-ghost" href="daily.php">Today's check</a>
        <a class="btn btn-ghost" href="daily_history.php">Audit history</a>
    </div>
</div>

<form class="filters no-print" method="get" style="display:flex; gap:.5rem; flex-wrap:wrap; align-items:center; margin-bottom:1rem;">
    <label>Window
        <select name="days" onchange="this.form.submit()">
            <?php foreach ([30 => 'Last 30 days', 90 => 'Last 90 days', 180 => 'Last 180 days', 365 => 'Last year'] as $v => $lbl): ?>
                <option value="<?= $v ?>" <?= $v === $days ? 'selected' : '' ?>><?= e($lbl) ?></option>
            <?php endforeach; ?>
        </select>
    </label>
    <noscript><button class="btn small">Go</button></noscript>
</form>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Materials with repeated issues</h2>
    <?php if (!$materials): ?>
        <p class="muted">No materials have been marked as damaged, missing, or needing attention in this window.</p>
    <?php else: $max = max(array_column($materials, 'issue_count')); ?>
    <div class="table-scroll">
    <table class="admin-table">
        <thead><tr><th>Material</th><th>Shelf</th><th>Times flagged</th><th>Of which serious</th><th>Last seen</th><th></th></tr></thead>
        <tbody>
        <?php foreach ($materials as $m): ?>
            <tr>
                <td><a href="daily_history.php?material=<?= e(urlencode($m['name'])) ?>"><?= e($m['name']) ?></a></td>
                <td><?= e($m['location']) ?></td>
                <td><?= (int)$m['issue_count'] ?></td>
                <td><?= (int)$m['bad_count'] ?></td>
                <td><?= e($m['last_seen']) ?></td>
                <td style="min-width:100px;"><span class="mmt-bar"><i style="width:<?= (int)round((int)$m['issue_count'] * 100 / max(1, $max)) ?>%"></i></span></td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</section>

<section class="card" style="margin-bottom:1rem;">
    <h2 style="margin-top:0;">Issues by shelf / category</h2>
    <?php if (!$categories): ?>
        <p class="muted">Nothing to show yet.</p>
    <?php else: $max = max(array_column($categories, 'issue_count')); ?>
    <div class="mm-list">
        <?php foreach ($categories as $c): ?>
            <div class="mm-item" style="display:flex; align-items:center; gap:.6rem;">
                <span style="flex:1 1 12rem;"><?= e($c['category']) ?></span>
                <span class="muted small"><?= (int)$c['issue_count'] ?></span>
                <span class="mmt-bar" style="flex:2 1 10rem;"><i style="width:<?= (int)round((int)$c['issue_count'] * 100 / max(1, $max)) ?>%"></i></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<section class="card">
    <h2 style="margin-top:0;">Issues over time (weekly)</h2>
    <?php if (!$weekly): ?>
        <p class="muted">Nothing to show yet.</p>
    <?php else: $max = max(array_column($weekly, 'issue_count')); ?>
    <div style="display:flex; gap:.5rem; align-items:flex-end; height:120px;">
        <?php foreach ($weekly as $w): $h = (int)round((int)$w['issue_count'] * 100 / max(1, $max)); ?>
            <div style="flex:1; display:flex; flex-direction:column; align-items:center; justify-content:flex-end; height:100%;">
                <div title="<?= (int)$w['issue_count'] ?> issues" style="width:100%; max-width:28px; background:#b3261e; border-radius:4px 4px 0 0; height:<?= max(3, $h) ?>%;"></div>
                <span class="muted small" style="font-size:.65rem; margin-top:.2rem; white-space:nowrap;"><?= e(date('j M', strtotime((string)$w['week_start']))) ?></span>
            </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</section>

<?php require __DIR__ . '/../includes/footer.php'; ?>
