<?php
/**
 * inventory/edit.php — create or update one inventory item.
 *
 *   GET            → blank form (add)
 *   GET ?id=N      → edit existing
 *   POST           → upsert (inventory module / admin)
 *
 * Retiring = setting status to Lost / Damaged / Disposed (NEVER hard-delete).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');

$id     = isset($_GET['id']) ? (int)$_GET['id'] : 0;
$isEdit = $id > 0;
$row    = null;
if ($isEdit) {
    $s = db()->prepare("SELECT * FROM inventory_items WHERE id = :id");
    $s->execute([':id' => $id]);
    $row = $s->fetch();
    if (!$row) { http_response_code(404); echo 'Item not found.'; exit; }
}

$VALID_CATS  = inventory_categories();
$VALID_UNITS = inventory_units();
$VALID_LOCS  = inventory_locations();
$VALID_CONDS = array_keys(inventory_conditions());
$VALID_STATS = array_keys(inventory_statuses());

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $errs = [];

    $sku    = trim((string)($_POST['sku']  ?? ''));
    $name   = trim((string)($_POST['name'] ?? ''));
    $cat    = (string)($_POST['category']     ?? '');
    $sub    = trim((string)($_POST['sub_category'] ?? ''));
    $qty    = (float)($_POST['quantity']      ?? 0);
    $unit   = (string)($_POST['unit']         ?? '');
    $reord  = (float)($_POST['reorder_level'] ?? 0);
    $pdate  = trim((string)($_POST['purchase_date'] ?? '')) ?: null;
    $cost   = $_POST['unit_cost'] === '' || $_POST['unit_cost'] === null ? null : (float)$_POST['unit_cost'];
    $supp   = trim((string)($_POST['supplier'] ?? '')) ?: null;
    $loc    = (string)($_POST['location'] ?? '');
    $cond   = (string)($_POST['condition'] ?? 'good');
    $assign = trim((string)($_POST['assigned_to'] ?? '')) ?: null;
    $check  = trim((string)($_POST['last_stock_check'] ?? '')) ?: null;
    $status = (string)($_POST['status'] ?? 'active');
    $notes  = trim((string)($_POST['notes'] ?? '')) ?: null;

    if ($name === '')                                  $errs[] = 'Item name is required.';
    if ($sku  === '')                                  $errs[] = 'Item ID is required.';
    if (!in_array($cat, $VALID_CATS, true))            $errs[] = 'Pick a category.';
    if ($sub !== '' && !in_array($sub, inventory_subcats_for($cat), true)) {
        $errs[] = 'Sub category is not valid for that category.';
    }
    if (!in_array($unit, $VALID_UNITS, true))          $errs[] = 'Pick a unit.';
    if ($loc !== '' && !in_array($loc, $VALID_LOCS, true)) $errs[] = 'Pick a storage location.';
    if (!in_array($cond, $VALID_CONDS, true))          $errs[] = 'Pick a condition.';
    if (!in_array($status, $VALID_STATS, true))        $errs[] = 'Pick a status.';
    if ($qty   < 0)                                    $errs[] = 'Quantity must be 0 or more.';
    if ($reord < 0)                                    $errs[] = 'Minimum stock must be 0 or more.';
    if ($cost !== null && $cost < 0)                   $errs[] = 'Purchase cost must be 0 or more.';
    foreach (['purchase_date' => $pdate, 'last_stock_check' => $check] as $label => $val) {
        if ($val !== null && !preg_match('/^\d{4}-\d{2}-\d{2}$/', $val)) $errs[] = "$label must be YYYY-MM-DD.";
    }

    // Item-ID uniqueness (skip the row being edited).
    if (!$errs) {
        $uq = db()->prepare("SELECT id FROM inventory_items WHERE sku = :s AND id <> :id LIMIT 1");
        $uq->execute([':s' => $sku, ':id' => $id]);
        if ($uq->fetchColumn()) $errs[] = 'Another item already uses that Item ID.';
    }

    if ($errs) {
        flash_set('error', implode(' ', $errs));
    } else {
        try {
            if ($isEdit) {
                db()->prepare("
                    UPDATE inventory_items SET
                        sku=:sku, name=:n, category=:cat, sub_category=:sub, quantity=:q,
                        unit=:u, reorder_level=:r, purchase_date=:pd, unit_cost=:c, supplier=:sp,
                        location=:loc, `condition`=:cond, assigned_to=:as, last_stock_check=:lc,
                        status=:st, is_active=IF(:st2='active',1,0), notes=:nt
                    WHERE id=:id
                ")->execute([
                    ':sku'=>$sku, ':n'=>$name, ':cat'=>$cat, ':sub'=>$sub ?: null,
                    ':q'=>$qty, ':u'=>$unit, ':r'=>$reord, ':pd'=>$pdate, ':c'=>$cost,
                    ':sp'=>$supp, ':loc'=>$loc ?: null, ':cond'=>$cond, ':as'=>$assign,
                    ':lc'=>$check, ':st'=>$status, ':st2'=>$status,
                    ':nt'=>$notes, ':id'=>$id,
                ]);
                flash_set('ok', 'Item updated.');
                redirect('/inventory/view.php?id=' . $id);
            } else {
                db()->prepare("
                    INSERT INTO inventory_items
                        (sku, name, category, sub_category, quantity, unit, reorder_level,
                         purchase_date, unit_cost, supplier, location, `condition`,
                         assigned_to, last_stock_check, status, is_active, notes, created_by)
                    VALUES
                        (:sku, :n, :cat, :sub, :q, :u, :r,
                         :pd, :c, :sp, :loc, :cond,
                         :as, :lc, :st, IF(:st2='active',1,0), :nt, :by)
                ")->execute([
                    ':sku'=>$sku, ':n'=>$name, ':cat'=>$cat, ':sub'=>$sub ?: null,
                    ':q'=>$qty, ':u'=>$unit, ':r'=>$reord, ':pd'=>$pdate, ':c'=>$cost,
                    ':sp'=>$supp, ':loc'=>$loc ?: null, ':cond'=>$cond, ':as'=>$assign,
                    ':lc'=>$check, ':st'=>$status, ':st2'=>$status, ':nt'=>$notes,
                    ':by'=>(int)$user['id'],
                ]);
                $newId = (int)db()->lastInsertId();
                flash_set('ok', 'Item added.');
                redirect('/inventory/view.php?id=' . $newId);
            }
        } catch (Throwable $e) {
            flash_set('error', 'Save failed: ' . $e->getMessage());
        }
    }

    // Repopulate $row from POST so the form remembers what the user typed.
    $row = [
        'sku'=>$sku, 'name'=>$name, 'category'=>$cat, 'sub_category'=>$sub,
        'quantity'=>$qty, 'unit'=>$unit, 'reorder_level'=>$reord,
        'purchase_date'=>$pdate, 'unit_cost'=>$cost, 'supplier'=>$supp,
        'location'=>$loc, 'condition'=>$cond, 'assigned_to'=>$assign,
        'last_stock_check'=>$check, 'status'=>$status, 'notes'=>$notes,
    ] + ($row ?? []);
}

$pageTitle = $isEdit ? 'Edit ' . $row['name'] : 'New inventory item';
require __DIR__ . '/../includes/header.php';

$val = fn(string $k, $d = '') => e((string)($row[$k] ?? $d));
?>

<div class="page-head">
    <div>
        <h1><?= $isEdit ? 'Edit item' : 'New item' ?></h1>
        <?php if ($isEdit): ?><p class="muted"><?= e($row['name']) ?></p><?php endif; ?>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/inventory/index.php">← Inventory</a>
        <?php if ($isEdit): ?>
            <a class="btn" href="/inventory/view.php?id=<?= (int)$row['id'] ?>">View</a>
        <?php endif; ?>
    </div>
</div>

<form method="post" class="card card-form" style="max-width: 880px;">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <div class="row">
        <div class="field" style="flex:1 1 220px;"><label>Item ID *</label>
            <input type="text" name="sku" value="<?= $val('sku') ?>" required maxlength="60" placeholder="e.g. MM-0001">
        </div>
        <div class="field" style="flex:2 1 320px;"><label>Item name *</label>
            <input type="text" name="name" value="<?= $val('name') ?>" required maxlength="160">
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex:1 1 220px;"><label>Category *</label>
            <select name="category" id="f-cat" required>
                <option value="">—</option>
                <?php foreach ($VALID_CATS as $c): ?>
                    <option value="<?= e($c) ?>" <?= ($row['category'] ?? '') === $c ? 'selected' : '' ?>><?= e($c) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:1 1 220px;"><label>Sub category</label>
            <select name="sub_category" id="f-sub">
                <option value="">—</option>
                <?php $curCat = $row['category'] ?? ''; foreach (inventory_subcats_for($curCat) as $s): ?>
                    <option value="<?= e($s) ?>" <?= ($row['sub_category'] ?? '') === $s ? 'selected' : '' ?>><?= e($s) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:1 1 160px;"><label>Storage location</label>
            <select name="location">
                <option value="">—</option>
                <?php foreach ($VALID_LOCS as $l): ?>
                    <option value="<?= e($l) ?>" <?= ($row['location'] ?? '') === $l ? 'selected' : '' ?>><?= e($l) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex:1 1 140px;"><label>Quantity available *</label>
            <input type="number" name="quantity" value="<?= e((string)(($row['quantity'] ?? '0'))) ?>" min="0" step="0.01" required>
        </div>
        <div class="field" style="flex:1 1 140px;"><label>Unit *</label>
            <select name="unit" required>
                <?php foreach ($VALID_UNITS as $u): ?>
                    <option value="<?= e($u) ?>" <?= ($row['unit'] ?? 'Nos') === $u ? 'selected' : '' ?>><?= e($u) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:1 1 160px;"><label>Minimum stock level</label>
            <input type="number" name="reorder_level" value="<?= e((string)($row['reorder_level'] ?? '0')) ?>" min="0" step="0.01">
        </div>
        <div class="field" style="flex:1 1 160px;"><label>Purchase cost (per unit)</label>
            <input type="number" name="unit_cost" value="<?= e($row['unit_cost'] === null ? '' : (string)$row['unit_cost']) ?>" min="0" step="0.01" placeholder="₹">
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex:1 1 200px;"><label>Purchase date</label>
            <input type="date" name="purchase_date" value="<?= $val('purchase_date') ?>">
        </div>
        <div class="field" style="flex:2 1 240px;"><label>Supplier / Vendor</label>
            <input type="text" name="supplier" value="<?= $val('supplier') ?>" maxlength="120">
        </div>
    </div>

    <div class="row">
        <div class="field" style="flex:1 1 220px;"><label>Condition *</label>
            <select name="condition" required>
                <?php foreach (inventory_conditions() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= ($row['condition'] ?? 'good') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field" style="flex:1 1 220px;"><label>Assigned to <span class="muted small">(optional)</span></label>
            <input type="text" name="assigned_to" value="<?= $val('assigned_to') ?>" maxlength="120" placeholder="Teacher / staff name">
        </div>
        <div class="field" style="flex:1 1 200px;"><label>Last stock check</label>
            <input type="date" name="last_stock_check" value="<?= $val('last_stock_check') ?>">
        </div>
    </div>

    <div class="field"><label>Status *</label>
        <select name="status" required>
            <?php foreach (inventory_statuses() as $code => $label): ?>
                <option value="<?= e($code) ?>" <?= ($row['status'] ?? 'active') === $code ? 'selected' : '' ?>><?= e($label) ?></option>
            <?php endforeach; ?>
        </select>
        <p class="muted small" style="margin:.25rem 0 0;">To retire an item set the status to Lost, Damaged or Disposed — never delete the record.</p>
    </div>

    <div class="field"><label>Remarks</label>
        <textarea name="notes" rows="3" maxlength="1000"><?= e($row['notes'] ?? '') ?></textarea>
    </div>

    <div class="actions">
        <button class="btn btn-primary" type="submit"><?= $isEdit ? 'Save changes' : 'Add item' ?></button>
        <a class="btn btn-ghost" href="/inventory/index.php">Cancel</a>
    </div>
</form>

<script>
// Cascade: changing category repopulates sub-category list + resets selection.
(() => {
    const MAP = <?= json_encode(inventory_category_map(), JSON_UNESCAPED_UNICODE) ?>;
    const cat = document.getElementById('f-cat');
    const sub = document.getElementById('f-sub');
    if (!cat || !sub) return;
    cat.addEventListener('change', () => {
        const list = MAP[cat.value] || [];
        sub.innerHTML = '<option value="">—</option>' +
            list.map(s => `<option value="${s}">${s}</option>`).join('');
    });
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
