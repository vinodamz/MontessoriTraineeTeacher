<?php
/**
 * materials/replacement.php — the Kreedo replacement list for a month.
 *
 * Every material flagged needs_replacement in the chosen period, grouped by
 * shelf, with condition + qty + who flagged it. Print-friendly, and a CSV
 * export to hand straight to Kreedo.
 *
 *   GET ?period=YYYY-MM
 *   GET ?period=YYYY-MM&format=csv
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$user   = require_module('materials');
$period = mm_current_period();

$rows = db()->prepare("
    SELECT m.name, m.location, c.condition_code, c.replace_qty, c.notes,
           c.checked_at, u.name AS checked_by
    FROM mm_condition_checks c
    JOIN mm_materials m ON m.id = c.material_id
    LEFT JOIN users u ON u.id = c.checked_by_user_id
    WHERE c.period = :p AND c.needs_replacement = 1
    ORDER BY m.location, m.sort_order, m.name
");
$rows->execute([':p' => $period]);
$list = $rows->fetchAll();

// ---- CSV export -----------------------------------------------------------
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="kreedo-replacement-' . $period . '.csv"');
    $out = fopen('php://output', 'w');
    fputcsv($out, ['Shelf', 'Material', 'Condition', 'Qty', 'Notes', 'Flagged by', 'Flagged on']);
    foreach ($list as $r) {
        fputcsv($out, [
            $r['location'], $r['name'], mm_condition_label($r['condition_code']),
            (int)$r['replace_qty'] > 0 ? (int)$r['replace_qty'] : 1,
            $r['notes'], $r['checked_by'] ?? 'Unknown',
            date('Y-m-d', strtotime($r['checked_at'])),
        ]);
    }
    fclose($out);
    exit;
}

$byLoc = [];
foreach ($list as $r) $byLoc[$r['location']][] = $r;

$pageTitle = 'Kreedo replacement list — ' . mm_period_label($period);
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Replacement list — Kreedo</h1>
        <p class="muted"><strong><?= e(mm_period_label($period)) ?></strong> · <?= count($list) ?> material<?= count($list) === 1 ? '' : 's' ?> to replace</p>
    </div>
    <div class="head-actions no-print">
        <a class="btn" href="replacement.php?period=<?= e($period) ?>&format=csv">Download CSV</a>
        <button class="btn btn-ghost" onclick="window.print()">Print</button>
        <a class="btn btn-ghost" href="index.php?period=<?= e($period) ?>">← Audit</a>
    </div>
</div>

<?php if (!$list): ?>
    <div class="empty"><p>Nothing flagged for replacement in <?= e(mm_period_label($period)) ?>. Mark materials on the <a href="index.php?period=<?= e($period) ?>">audit page</a> first.</p></div>
<?php else: ?>
    <?php foreach ($byLoc as $location => $items): ?>
    <section class="card" style="margin-bottom:1rem;">
        <div class="card-head"><h2 style="margin:0;"><?= e($location) ?> <span class="muted small">· <?= count($items) ?></span></h2></div>
        <div class="table-scroll">
        <table class="admin-table">
            <thead><tr><th style="width:30%">Material</th><th>Condition</th><th>Qty</th><th>Notes</th><th>Flagged by</th></tr></thead>
            <tbody>
            <?php foreach ($items as $r): ?>
                <tr>
                    <td><strong><?= e($r['name']) ?></strong></td>
                    <td><span class="pill" style="background:#fbdcd8;color:#8b1c14;"><?= e(mm_condition_label($r['condition_code'])) ?></span></td>
                    <td><?= (int)$r['replace_qty'] > 0 ? (int)$r['replace_qty'] : 1 ?></td>
                    <td class="muted small"><?= e($r['notes'] ?? '') ?></td>
                    <td class="muted small"><?= e($r['checked_by'] ?? 'Unknown') ?><br><?= e(date('j M Y', strtotime($r['checked_at']))) ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        </div>
    </section>
    <?php endforeach; ?>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
