<?php
/**
 * inventory/index.php — stock list with category / search / low-stock filters.
 *
 * All users with the inventory module can view and record movements;
 * adding / editing item definitions is open to module users too (small team).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');
$pdo  = db();

$q       = trim($_GET['q'] ?? '');
$fCat    = $_GET['category'] ?? '';
$fLow    = !empty($_GET['low']);
$fActive = $_GET['active'] ?? 'active';

if (!array_key_exists($fCat, inventory_categories())) $fCat = '';

$where = []; $params = [];
if ($q !== '') { $where[] = '(name LIKE :q OR sku LIKE :q OR supplier LIKE :q)'; $params[':q'] = "%$q%"; }
if ($fCat !== '') { $where[] = 'category = :c'; $params[':c'] = $fCat; }
if ($fLow) $where[] = 'quantity <= reorder_level AND reorder_level > 0';
if ($fActive === 'active')   $where[] = 'is_active = 1';
elseif ($fActive === 'inactive') $where[] = 'is_active = 0';

$sql = "SELECT * FROM inventory_items"
     . ($where ? ' WHERE ' . implode(' AND ', $where) : '')
     . " ORDER BY (quantity <= reorder_level AND reorder_level > 0) DESC, name";
$stmt = $pdo->prepare($sql);
$stmt->execute($params);
$items = $stmt->fetchAll();

$stats = inventory_stats();

$pageTitle  = 'Inventory';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Inventory</h1>
        <p class="muted"><?= (int)$stats['items'] ?> active items · value <?= e(inventory_money($stats['value'])) ?>
            <?php if ($stats['low'] > 0): ?> · <span class="pill pill-warn"><?= (int)$stats['low'] ?> low</span><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/inventory/edit.php">+ New item</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="q">Search</label>
        <input id="q" type="search" name="q" value="<?= e($q) ?>" placeholder="Name, SKU or supplier" autocomplete="off">
    </div>
    <div class="field">
        <label for="category">Category</label>
        <select id="category" name="category">
            <option value="">All categories</option>
            <?php foreach (inventory_categories() as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= $fCat === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="field">
        <label for="active">Status</label>
        <select id="active" name="active">
            <option value="active"   <?= $fActive === 'active'   ? 'selected' : '' ?>>Active</option>
            <option value="all"      <?= $fActive === 'all'      ? 'selected' : '' ?>>All</option>
            <option value="inactive" <?= $fActive === 'inactive' ? 'selected' : '' ?>>Inactive</option>
        </select>
    </div>
    <div class="field">
        <label class="checkbox" style="margin-top:1.4rem;">
            <input type="checkbox" name="low" value="1" <?= $fLow ? 'checked' : '' ?>>
            <span>Low stock only</span>
        </label>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Filter</button>
        <a class="btn btn-ghost" href="/inventory/index.php">Reset</a>
    </div>
</form>

<?php if (!$items): ?>
    <div class="empty"><p>No items match. <a href="/inventory/edit.php">Add the first item</a>.</p></div>
<?php else: ?>
<div class="card">
    <table class="admin-table">
        <thead>
            <tr><th>Item</th><th>Category</th><th>In stock</th><th>Reorder at</th><th>Location</th><th>Value</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($items as $it):
                $low = $it['reorder_level'] > 0 && $it['quantity'] <= $it['reorder_level'];
                $val = (float)$it['quantity'] * (float)($it['unit_cost'] ?? 0);
            ?>
                <tr class="<?= $low ? 'inv-low' : '' ?>">
                    <td>
                        <a href="/inventory/view.php?id=<?= (int)$it['id'] ?>"><?= e($it['name']) ?></a>
                        <?php if ($it['sku']): ?><span class="muted small"> · <?= e($it['sku']) ?></span><?php endif; ?>
                        <?php if (!$it['is_active']): ?> <span class="pill">inactive</span><?php endif; ?>
                    </td>
                    <td><span class="muted small"><?= e(inventory_category_label($it['category'])) ?></span></td>
                    <td>
                        <strong><?= e(inventory_qty((float)$it['quantity'])) ?></strong> <?= e($it['unit']) ?>
                        <?php if ($low): ?><span class="pill pill-warn">low</span><?php endif; ?>
                    </td>
                    <td class="muted"><?= $it['reorder_level'] > 0 ? e(inventory_qty((float)$it['reorder_level'])) : '—' ?></td>
                    <td class="muted small"><?= e($it['location'] ?? '') ?: '—' ?></td>
                    <td class="muted"><?= $it['unit_cost'] !== null ? e(inventory_money($val)) : '—' ?></td>
                    <td><a class="btn btn-ghost btn-small" href="/inventory/view.php?id=<?= (int)$it['id'] ?>">Stock</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
