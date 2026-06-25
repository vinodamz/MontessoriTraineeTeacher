<?php
/**
 * inventory/reports.php — rollups for the master spec's Reports section.
 *
 *   - Total stock value (sum quantity × purchase cost) per Category + overall.
 *   - Counts of: active items, items at/below reorder level, items not
 *     verified in 90+ days, items in poor condition (repair/damaged).
 *   - Deep links into each filtered view on /inventory/index.php so the
 *     numbers are clickable, not dead text.
 *
 * Read-only — the source of truth stays on the items list.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');

$stats = inventory_stats();

// Category rollup — active items only, ordered by value descending.
$cats = [];
try {
    $cats = db()->query("
        SELECT category,
               COUNT(*)                                              AS n,
               COALESCE(SUM(quantity * COALESCE(unit_cost,0)), 0)    AS value,
               SUM(reorder_level > 0 AND quantity <= reorder_level)  AS low
        FROM inventory_items
        WHERE status = 'active'
        GROUP BY category
        ORDER BY value DESC, n DESC
    ")->fetchAll();
} catch (Throwable $e) {}

$pageTitle = 'Inventory reports';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Inventory reports</h1>
        <p class="muted">Numbers update as items are added or marked. Click any tile to see the rows behind it.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/inventory/index.php">← Inventory</a>
        <a class="btn" href="/inventory/index.php?format=csv">Export CSV (full table)</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
    <li><div class="admin-tile tile-ok">
        <span class="tile-label">Total stock value</span>
        <span class="tile-value"><?= e(inventory_money($stats['value'])) ?></span>
        <span class="tile-sub">active items only</span>
    </div></li>
    <li><a class="admin-tile" href="/inventory/index.php?status=active" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Active items</span>
        <span class="tile-value"><?= $stats['items'] ?></span>
    </a></li>
    <li><a class="admin-tile <?= $stats['low'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=low" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Reorder now</span>
        <span class="tile-value"><?= $stats['low'] ?></span>
        <span class="tile-sub">at or below minimum</span>
    </a></li>
    <li><a class="admin-tile <?= $stats['due'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=due" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Due for stock check</span>
        <span class="tile-value"><?= $stats['due'] ?></span>
        <span class="tile-sub">not verified 90+ days</span>
    </a></li>
    <li><a class="admin-tile <?= $stats['attention'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=attention" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Needs attention</span>
        <span class="tile-value"><?= $stats['attention'] ?></span>
        <span class="tile-sub">repair / damaged</span>
    </a></li>
</ul>

<div class="card">
    <h2 style="margin-top:0;">Stock value by category</h2>
    <?php if (!$cats): ?>
        <p class="muted">No active items yet.</p>
    <?php else:
        $maxVal = max(array_column($cats, 'value')) ?: 1;
    ?>
        <table class="data-table">
            <thead><tr><th>Category</th><th style="text-align:right;">Items</th><th style="text-align:right;">Value</th><th>Share</th><th style="text-align:right;">Reorder</th></tr></thead>
            <tbody>
                <?php foreach ($cats as $r):
                    $share = $maxVal > 0 ? (float)$r['value'] / $maxVal * 100 : 0; ?>
                    <tr>
                        <td><a href="/inventory/index.php?category=<?= e(urlencode($r['category'])) ?>"><?= e($r['category']) ?></a></td>
                        <td style="text-align:right;"><?= (int)$r['n'] ?></td>
                        <td style="text-align:right;"><?= e(inventory_money((float)$r['value'])) ?></td>
                        <td style="min-width: 200px;">
                            <div style="background:var(--bg-soft); height:8px; border-radius:999px; overflow:hidden;">
                                <div style="background:var(--accent); height:100%; width:<?= number_format($share, 1) ?>%;"></div>
                            </div>
                        </td>
                        <td style="text-align:right;">
                            <?php if ((int)$r['low'] > 0): ?>
                                <a href="/inventory/index.php?category=<?= e(urlencode($r['category'])) ?>&view=low" class="pill pill-warn"><?= (int)$r['low'] ?> due</a>
                            <?php else: ?>
                                <span class="muted small">—</span>
                            <?php endif; ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
            <tfoot>
                <tr style="font-weight:700; background: var(--bg-tint);">
                    <td>Total (active)</td>
                    <td style="text-align:right;"><?= (int)$stats['items'] ?></td>
                    <td style="text-align:right;"><?= e(inventory_money($stats['value'])) ?></td>
                    <td colspan="2"></td>
                </tr>
            </tfoot>
        </table>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
