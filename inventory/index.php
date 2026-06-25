<?php
/**
 * inventory/index.php — items list + filters + views + CSV export.
 *
 *   GET                       → full list
 *   GET ?view=low             → quantity ≤ reorder level (Reorder)
 *   GET ?view=due             → not verified in 90+ days
 *   GET ?view=attention       → condition repair_needed / damaged
 *   GET ?format=csv           → download the current filtered view
 *
 * Filters via query string: q, category, sub_category, location, status,
 * condition. The same filter set drives both the table and the CSV.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');

$VALID_CATS   = inventory_categories();
$VALID_UNITS  = inventory_units();
$VALID_LOCS   = inventory_locations();
$VALID_CONDS  = array_keys(inventory_conditions());
$VALID_STATS  = array_keys(inventory_statuses());
$VALID_VIEWS  = ['', 'low', 'due', 'attention'];

$q       = trim((string)($_GET['q'] ?? ''));
$cat     = (string)($_GET['category'] ?? '');
$sub     = (string)($_GET['sub_category'] ?? '');
$loc     = (string)($_GET['location'] ?? '');
$status  = (string)($_GET['status']   ?? '');
$cond    = (string)($_GET['condition']?? '');
$view    = (string)($_GET['view']     ?? '');
if (!in_array($view, $VALID_VIEWS, true)) $view = '';

$where   = []; $params = [];
if ($q !== '') {
    $where[] = '(name LIKE :q OR sku LIKE :q)';
    $params[':q'] = '%' . $q . '%';
}
if (in_array($cat,  $VALID_CATS,  true)) { $where[] = 'category = :cat';     $params[':cat']  = $cat; }
if ($sub !== '')                          { $where[] = 'sub_category = :sub'; $params[':sub']  = $sub; }
if (in_array($loc,  $VALID_LOCS,  true)) { $where[] = 'location = :loc';     $params[':loc']  = $loc; }
if (in_array($status,$VALID_STATS,true))  { $where[] = 'status   = :st';     $params[':st']   = $status; }
if (in_array($cond, $VALID_CONDS, true))  { $where[] = '`condition` = :cnd'; $params[':cnd']  = $cond; }

if     ($view === 'low')       $where[] = "status='active' AND reorder_level > 0 AND quantity <= reorder_level";
elseif ($view === 'due')       $where[] = "status='active' AND (last_stock_check IS NULL OR last_stock_check < DATE_SUB(CURDATE(), INTERVAL 90 DAY))";
elseif ($view === 'attention') $where[] = "status='active' AND `condition` IN ('repair_needed','damaged')";

$sql = "SELECT * FROM inventory_items"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY status='active' DESC, name";

$stmt = db()->prepare($sql);
$stmt->execute($params);
$rows = $stmt->fetchAll();

$stats = inventory_stats();

// ---- CSV export of the current view ----------------------------------------
if (($_GET['format'] ?? '') === 'csv') {
    header('Content-Type: text/csv; charset=utf-8');
    header('Content-Disposition: attachment; filename="inventory_' . date('Y-m-d') . '.csv"');
    $out = fopen('php://output', 'wb');
    fputcsv($out, [
        'Item ID', 'Item Name', 'Category', 'Sub Category', 'Quantity Available',
        'Unit', 'Minimum Stock Level', 'Purchase Date', 'Purchase Cost',
        'Supplier/Vendor', 'Storage Location', 'Condition', 'Assigned To',
        'Last Stock Check', 'Status', 'Remarks', 'Reorder?',
    ]);
    foreach ($rows as $r) {
        $reorder = ($r['status'] === 'active' && (float)$r['reorder_level'] > 0
                    && (float)$r['quantity'] <= (float)$r['reorder_level']) ? 'Yes' : '';
        fputcsv($out, [
            $r['sku'] ?? '', $r['name'], $r['category'], $r['sub_category'] ?? '',
            inventory_qty((float)$r['quantity']),
            $r['unit'], inventory_qty((float)$r['reorder_level']),
            $r['purchase_date'] ?? '',
            $r['unit_cost'] === null ? '' : number_format((float)$r['unit_cost'], 2, '.', ''),
            $r['supplier'] ?? '',
            $r['location'] ?? '',
            inventory_condition_label((string)$r['condition']),
            $r['assigned_to'] ?? '',
            $r['last_stock_check'] ?? '',
            inventory_status_label((string)$r['status']),
            $r['notes'] ?? '',
            $reorder,
        ]);
    }
    fclose($out);
    exit;
}

$pageTitle = 'Inventory';
require __DIR__ . '/../includes/header.php';

$qsCsv = $_SERVER['QUERY_STRING'] !== '' ? $_SERVER['QUERY_STRING'] . '&format=csv' : 'format=csv';
$viewTitle = ['low' => 'Reorder (low stock)', 'due' => 'Due for stock check', 'attention' => 'Needs attention'][$view] ?? null;
?>

<div class="page-head">
    <div>
        <h1>Inventory<?php if ($viewTitle): ?> · <span class="muted"><?= e($viewTitle) ?></span><?php endif; ?></h1>
        <p class="muted">
            <?= count($rows) ?> item<?= count($rows) === 1 ? '' : 's' ?> shown
            · <?= e(inventory_money($stats['value'])) ?> on hand
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/inventory/reports.php">Reports</a>
        <a class="btn" href="?<?= e($qsCsv) ?>">Export CSV</a>
        <a class="btn btn-primary" href="/inventory/edit.php">+ Add item</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(180px, 1fr));">
    <li><a class="admin-tile" href="/inventory/index.php" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Active items</span>
        <span class="tile-value"><?= $stats['items'] ?></span>
    </a></li>
    <li><a class="admin-tile <?= $stats['low'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=low" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Reorder</span>
        <span class="tile-value"><?= $stats['low'] ?></span>
        <span class="tile-sub">at or below minimum</span>
    </a></li>
    <li><a class="admin-tile <?= $stats['due'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=due" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Due for check</span>
        <span class="tile-value"><?= $stats['due'] ?></span>
        <span class="tile-sub">not verified 90+ days</span>
    </a></li>
    <li><a class="admin-tile <?= $stats['attention'] ? 'tile-warn' : '' ?>" href="/inventory/index.php?view=attention" style="text-decoration:none; color:inherit;">
        <span class="tile-label">Needs attention</span>
        <span class="tile-value"><?= $stats['attention'] ?></span>
        <span class="tile-sub">repair / damaged</span>
    </a></li>
</ul>

<form method="get" class="filter-row card">
    <input type="hidden" name="view" value="<?= e($view) ?>">
    <div class="field"><label>Search</label>
        <input type="search" name="q" value="<?= e($q) ?>" placeholder="Name or Item ID">
    </div>
    <div class="field"><label>Category</label>
        <select name="category" id="filter-cat">
            <option value="">All</option>
            <?php foreach ($VALID_CATS as $c): ?>
                <option value="<?= e($c) ?>" <?= $cat === $c ? 'selected' : '' ?>><?= e($c) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Sub category</label>
        <select name="sub_category" id="filter-sub">
            <option value="">All</option>
            <?php foreach (inventory_subcats_for($cat) as $s): ?>
                <option value="<?= e($s) ?>" <?= $sub === $s ? 'selected' : '' ?>><?= e($s) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Location</label>
        <select name="location">
            <option value="">All</option>
            <?php foreach ($VALID_LOCS as $l): ?>
                <option value="<?= e($l) ?>" <?= $loc === $l ? 'selected' : '' ?>><?= e($l) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Status</label>
        <select name="status">
            <option value="">All</option>
            <?php foreach (inventory_statuses() as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $status === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field"><label>Condition</label>
        <select name="condition">
            <option value="">All</option>
            <?php foreach (inventory_conditions() as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $cond === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions"><button class="btn btn-primary">Filter</button>
        <a class="btn btn-ghost" href="/inventory/index.php<?= $view ? '?view=' . e($view) : '' ?>">Clear</a>
    </div>
</form>

<?php if (!$rows): ?>
    <div class="empty"><p>No items match this filter.</p></div>
<?php else: ?>
<div class="card" style="padding: 0; overflow-x: auto;">
<table class="data-table" style="margin:0;">
    <thead>
        <tr>
            <th>Item</th>
            <th>Category</th>
            <th style="text-align:right;">Qty</th>
            <th>Location</th>
            <th>Condition</th>
            <th>Status</th>
            <th>Last check</th>
            <th></th>
        </tr>
    </thead>
    <tbody>
        <?php foreach ($rows as $r):
            $isLow = $r['status'] === 'active' && (float)$r['reorder_level'] > 0
                  && (float)$r['quantity'] <= (float)$r['reorder_level'];
            $check = $r['last_stock_check'];
            $isDue = $r['status'] === 'active'
                  && ($check === null || $check < (new DateTime('-90 days'))->format('Y-m-d'));
        ?>
        <tr>
            <td>
                <a href="/inventory/view.php?id=<?= (int)$r['id'] ?>"><strong><?= e($r['name']) ?></strong></a>
                <?php if (!empty($r['sku'])): ?><div class="muted small">#<?= e($r['sku']) ?></div><?php endif; ?>
            </td>
            <td>
                <?= e($r['category']) ?>
                <?php if (!empty($r['sub_category'])): ?><div class="muted small"><?= e($r['sub_category']) ?></div><?php endif; ?>
            </td>
            <td style="text-align:right; white-space:nowrap;">
                <?= e(inventory_qty((float)$r['quantity'])) ?>
                <span class="muted small"><?= e($r['unit']) ?></span>
                <?php if ($isLow): ?><br><span class="pill pill-warn">Reorder</span><?php endif; ?>
            </td>
            <td><?= e($r['location'] ?? '') ?></td>
            <td>
                <span class="pill <?= in_array($r['condition'], ['repair_needed','damaged'], true) ? 'pill-warn' : '' ?>">
                    <?= e(inventory_condition_label((string)$r['condition'])) ?>
                </span>
            </td>
            <td>
                <span class="pill <?= $r['status'] === 'active' ? 'pill-ok' : ($r['status'] === 'issued' ? '' : 'pill-warn') ?>">
                    <?= e(inventory_status_label((string)$r['status'])) ?>
                </span>
            </td>
            <td><?= e($check ?: '—') ?>
                <?php if ($isDue): ?><br><span class="pill pill-warn">Due</span><?php endif; ?>
            </td>
            <td><a class="btn btn-ghost" href="/inventory/edit.php?id=<?= (int)$r['id'] ?>">Edit</a></td>
        </tr>
        <?php endforeach; ?>
    </tbody>
</table>
</div>
<?php endif; ?>

<!-- When the category dropdown changes, refresh the sub-category list so the
     filter row mirrors the same cascade the add/edit form uses. -->
<script>
(() => {
    const SUBS = <?= json_encode(inventory_category_map(), JSON_UNESCAPED_UNICODE) ?>;
    const cat = document.getElementById('filter-cat');
    const sub = document.getElementById('filter-sub');
    if (!cat || !sub) return;
    cat.addEventListener('change', () => {
        const list = SUBS[cat.value] || [];
        sub.innerHTML = '<option value="">All</option>' +
            list.map(s => `<option value="${s}">${s}</option>`).join('');
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
