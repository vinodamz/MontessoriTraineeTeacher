<?php
/**
 * crm/index.php — admissions pipeline board.
 *
 * Kanban view of every inquiry family grouped by pipeline status, plus a
 * revenue-projection card at the top (weighted by per-inquiry probability).
 * Cards link through to the detail page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

$rows = db()->query("
    SELECT f.*,
           (SELECT COUNT(*) FROM inquiry_children c WHERE c.family_id = f.id) AS kid_count,
           (SELECT MIN(t.follow_up_at) FROM inquiry_touchpoints t
             WHERE t.family_id = f.id AND t.follow_up_at >= NOW())            AS next_followup
    FROM inquiry_families f
    ORDER BY f.updated_at DESC
")->fetchAll();

// Group by status for the kanban columns.
$byStatus = [];
foreach (array_keys(crm_statuses()) as $code) $byStatus[$code] = [];
foreach ($rows as $r) {
    $byStatus[$r['status']][] = $r;
}

$projection = crm_revenue_projection();
$money      = fn(float $v) => '₹' . number_format($v, 0);

// Follow-ups due in the next 7 days — handy reminder above the board.
$dueFollowups = db()->query("
    SELECT t.id, t.family_id, t.follow_up_at, t.kind, f.primary_name
    FROM inquiry_touchpoints t
    JOIN inquiry_families f ON f.id = t.family_id
    WHERE t.follow_up_at IS NOT NULL
      AND t.follow_up_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
      AND f.status IN ('" . implode("','", crm_open_statuses()) . "')
    ORDER BY t.follow_up_at
    LIMIT 10
")->fetchAll();

$pageTitle  = 'Admissions';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Admissions</h1>
        <p class="muted"><?= count($rows) ?> inquir<?= count($rows) === 1 ? 'y' : 'ies' ?>
            · <?= $projection['count'] ?> open in funnel</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/crm/edit.php">+ New inquiry</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Projected revenue</span>
            <span class="tile-value"><?= e($money($projection['weighted'])) ?>/mo</span>
            <span class="tile-sub">Probability-weighted, open funnel</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Pipeline ceiling</span>
            <span class="tile-value"><?= e($money($projection['pipeline'])) ?>/mo</span>
            <span class="tile-sub">If everything converted</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-nav">
            <span class="tile-label">Open inquiries</span>
            <span class="tile-value"><?= (int)$projection['count'] ?></span>
            <span class="tile-sub">Across all open stages</span>
        </div>
    </li>
    <li>
        <div class="admin-tile <?= $dueFollowups ? 'tile-warn' : 'tile-ok' ?>">
            <span class="tile-label">Follow-ups (7d)</span>
            <span class="tile-value"><?= count($dueFollowups) ?></span>
            <span class="tile-sub">Scheduled in next week</span>
        </div>
    </li>
</ul>

<?php if ($dueFollowups): ?>
    <div class="card">
        <h3 style="margin-bottom:.6rem;">Upcoming follow-ups</h3>
        <ul class="followup-list" role="list">
            <?php foreach ($dueFollowups as $f): ?>
                <li>
                    <a href="/crm/view.php?id=<?= (int)$f['family_id'] ?>"><?= e($f['primary_name']) ?></a>
                    <span class="muted small">
                        · <?= e(crm_touchpoint_kinds()[$f['kind']] ?? $f['kind']) ?>
                        · <?= e(date('D, j M · H:i', strtotime($f['follow_up_at']))) ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty">
        <p>No inquiries yet. <a href="/crm/edit.php">Add your first inquiry</a> to kick off the funnel.</p>
    </div>
<?php else: ?>
    <div class="crm-board">
        <?php foreach (crm_statuses() as $code => $meta):
            $cards = $byStatus[$code];
            $colCount = count($cards);
        ?>
            <section class="crm-col crm-col-<?= e($code) ?>">
                <header class="crm-col-head">
                    <h3><?= e($meta['label']) ?></h3>
                    <span class="pill"><?= $colCount ?></span>
                </header>
                <?php if (!$cards): ?>
                    <p class="crm-col-empty muted small">No inquiries here.</p>
                <?php else: foreach ($cards as $r):
                    $fee  = $r['expected_fee'] !== null ? $money((float)$r['expected_fee']) : '—';
                    $prob = (int)$r['probability'];
                ?>
                    <a class="crm-card" href="/crm/view.php?id=<?= (int)$r['id'] ?>">
                        <div class="crm-card-name"><?= e($r['primary_name']) ?></div>
                        <div class="crm-card-meta">
                            <span class="pill"><?= (int)$r['kid_count'] ?> kid<?= (int)$r['kid_count'] === 1 ? '' : 's' ?></span>
                            <?php if ($r['source']): ?>
                                <span class="muted small">· <?= e($r['source']) ?></span>
                            <?php endif; ?>
                        </div>
                        <div class="crm-card-meta">
                            <span><?= e($fee) ?>/mo</span>
                            <span class="muted small">· <?= $prob ?>%</span>
                        </div>
                        <?php if ($r['next_followup']): ?>
                            <div class="crm-card-meta muted small">
                                ↻ <?= e(date('j M', strtotime($r['next_followup']))) ?>
                            </div>
                        <?php endif; ?>
                    </a>
                <?php endforeach; endif; ?>
            </section>
        <?php endforeach; ?>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
