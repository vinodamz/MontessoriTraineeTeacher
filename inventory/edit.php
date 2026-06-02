<?php
/**
 * inventory/edit.php — add or edit an inventory item definition.
 *
 * For a new item, an optional opening quantity records an initial 'in'
 * movement so the ledger starts clean. Stock changes after that happen
 * on /inventory/view.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');
$pdo  = db();

$id     = (int)($_GET['id'] ?? 0);
$isEdit = $id > 0;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id     = (int)($_POST['id'] ?? 0);
    $isEdit = $id > 0;

    $name = trim($_POST['name'] ?? '');
    $cat  = $_POST['category'] ?? 'other';
    if (!array_key_exists($cat, inventory_categories())) $cat = 'other';
    $unit = trim($_POST['unit'] ?? 'pcs') ?: 'pcs';
    $sku  = trim($_POST['sku'] ?? '');
    $reorder  = max(0, (float)($_POST['reorder_level'] ?? 0));
    $location = trim($_POST['location'] ?? '');
    $cost     = $_POST['unit_cost'] !== '' ? max(0, (float)$_POST['unit_cost']) : null;
    $supplier = trim($_POST['supplier'] ?? '');
    $notes    = trim($_POST['notes'] ?? '');
    $active   = !empty($_POST['is_active']) ? 1 : 0;

    if ($name === '') {
        flash_set('error', 'Item name is required.');
        redirect('/inventory/edit.php' . ($isEdit ? '?id=' . $id : ''));
    }

    try {
        if ($isEdit) {
            $pdo->prepare("
                UPDATE inventory_items SET
                    name=:n, category=:c, sku=:sku, unit=:u, reorder_level=:r,
                    location=:loc, unit_cost=:cost, supplier=:sup, notes=:notes, is_active=:a
                WHERE id=:id
            ")->execute([
                ':n' => $name, ':c' => $cat, ':sku' => $sku ?: null, ':u' => $unit,
                ':r' => $reorder, ':loc' => $location ?: null, ':cost' => $cost,
                ':sup' => $supplier ?: null, ':notes' => $notes ?: null, ':a' => $active, ':id' => $id,
            ]);
            flash_set('ok', 'Item updated.');
            redirect('/inventory/view.php?id=' . $id);
        } else {
            $opening = max(0, (float)($_POST['opening_qty'] ?? 0));
            $pdo->beginTransaction();
            $pdo->prepare("
                INSERT INTO inventory_items
                    (name, category, sku, unit, quantity, reorder_level, location, unit_cost, supplier, notes, is_active, created_by)
                VALUES (:n, :c, :sku, :u, 0, :r, :loc, :cost, :sup, :notes, 1, :by)
            ")->execute([
                ':n' => $name, ':c' => $cat, ':sku' => $sku ?: null, ':u' => $unit,
                ':r' => $reorder, ':loc' => $location ?: null, ':cost' => $cost,
                ':sup' => $supplier ?: null, ':notes' => $notes ?: null, ':by' => (int)$user['id'],
            ]);
            $newId = (int)$pdo->lastInsertId();
            if ($opening > 0) {
                inventory_move($newId, 'in', $opening, 'correction', 'Opening stock', (int)$user['id']);
            }
            $pdo->commit();
            flash_set('ok', 'Item added.');
            redirect('/inventory/view.php?id=' . $newId);
        }
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        flash_set('error', 'Save failed: ' . $e->getMessage());
        redirect('/inventory/edit.php' . ($isEdit ? '?id=' . $id : ''));
    }
}

$it = null;
if ($isEdit) {
    $stmt = $pdo->prepare("SELECT * FROM inventory_items WHERE id = :id");
    $stmt->execute([':id' => $id]);
    $it = $stmt->fetch();
    if (!$it) { flash_set('error', 'Item not found.'); redirect('/inventory/index.php'); }
}

$v = fn(string $k) => e((string)($it[$k] ?? ''));
$pageTitle = $isEdit ? ('Edit — ' . $it['name']) : 'New item';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div><h1><?= $isEdit ? 'Edit item' : 'New item' ?></h1></div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="<?= $isEdit ? '/inventory/view.php?id=' . $id : '/inventory/index.php' ?>">Cancel</a>
    </div>
</div>

<form method="post" class="card card-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <?php if ($isEdit): ?><input type="hidden" name="id" value="<?= $id ?>"><?php endif; ?>

    <div class="row">
        <div class="field" style="flex:2 1 280px;">
            <label>Item name *</label>
            <input name="name" required maxlength="160" value="<?= $v('name') ?>" autofocus>
        </div>
        <div class="field">
            <label>Category</label>
            <select name="category">
                <?php foreach (inventory_categories() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($it['category'] ?? 'other') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="field">
            <label>Unit</label>
            <input name="unit" list="unitlist" maxlength="20" value="<?= e((string)($it['unit'] ?? 'pcs')) ?>">
            <datalist id="unitlist">
                <?php foreach (inventory_units() as $u): ?><option value="<?= e($u) ?>"><?php endforeach; ?>
            </datalist>
        </div>
        <div class="field">
            <label>SKU / code</label>
            <input name="sku" maxlength="60" value="<?= $v('sku') ?>" placeholder="optional">
        </div>
        <div class="field">
            <label>Reorder level</label>
            <input type="number" name="reorder_level" min="0" step="any" value="<?= e((string)($it['reorder_level'] ?? '0')) ?>">
        </div>
        <?php if (!$isEdit): ?>
        <div class="field">
            <label>Opening quantity</label>
            <input type="number" name="opening_qty" min="0" step="any" value="0">
        </div>
        <?php endif; ?>
    </div>

    <div class="row">
        <div class="field">
            <label>Unit cost (₹)</label>
            <input type="number" name="unit_cost" min="0" step="0.01" value="<?= $it && $it['unit_cost'] !== null ? e((string)$it['unit_cost']) : '' ?>" placeholder="optional">
        </div>
        <div class="field">
            <label>Location</label>
            <input name="location" maxlength="80" value="<?= $v('location') ?>" placeholder="e.g. Store room shelf 3">
        </div>
        <div class="field" style="flex:2 1 280px;">
            <label>Supplier</label>
            <input name="supplier" maxlength="120" value="<?= $v('supplier') ?>" placeholder="optional">
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex:1 1 100%;">
            <label>Notes</label>
            <textarea name="notes" rows="2" maxlength="2000"><?= $v('notes') ?></textarea>
        </div>
    </div>

    <?php if ($isEdit): ?>
    <div class="row">
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="is_active" value="1" <?= ($it['is_active'] ?? 1) ? 'checked' : '' ?>>
                <span>Active</span>
            </label>
        </div>
    </div>
    <?php endif; ?>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Create item' ?></button>
    </div>
</form>

<?php require __DIR__ . '/../includes/footer.php'; ?>
