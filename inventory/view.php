<?php
/**
 * inventory/view.php — read-only profile of one inventory item.
 *
 *   GET ?id=N                       → show item details + movement history
 *   POST op=mark_checked            → set last_stock_check to today
 *   POST op=set_status, status=…    → retire (lost/damaged/disposed) or reinstate
 *
 * Movement history is the same ledger used by inventory_move().
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');

$id = isset($_GET['id']) ? (int)$_GET['id'] : 0;
if ($id <= 0) { redirect('/inventory/index.php'); }

// Quick actions for fast stock checks on the phone.
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    try {
        if ($op === 'mark_checked') {
            db()->prepare("UPDATE inventory_items SET last_stock_check = CURDATE() WHERE id = :id")
                ->execute([':id' => $id]);
            flash_set('ok', 'Marked as verified today.');
        } elseif ($op === 'set_status') {
            $st = (string)($_POST['status'] ?? '');
            if (!array_key_exists($st, inventory_statuses())) throw new RuntimeException('Bad status');
            db()->prepare("UPDATE inventory_items SET status = :s, is_active = IF(:s2='active',1,0) WHERE id = :id")
                ->execute([':s' => $st, ':s2' => $st, ':id' => $id]);
            flash_set('ok', 'Status updated to ' . inventory_status_label($st) . '.');
        }
    } catch (Throwable $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('/inventory/view.php?id=' . $id);
}

$s = db()->prepare("SELECT * FROM inventory_items WHERE id = :id");
$s->execute([':id' => $id]);
$item = $s->fetch();
if (!$item) { http_response_code(404); echo 'Item not found.'; exit; }

$ms = db()->prepare("
    SELECT m.*, u.name AS moved_by_name
    FROM   inventory_movements m
    LEFT JOIN users u ON u.id = m.moved_by
    WHERE  m.item_id = :id
    ORDER  BY m.created_at DESC, m.id DESC
    LIMIT  50
");
$ms->execute([':id' => $id]);
$moves = $ms->fetchAll();

$isLow = $item['status'] === 'active' && (float)$item['reorder_level'] > 0
      && (float)$item['quantity'] <= (float)$item['reorder_level'];
$check = $item['last_stock_check'];
$isDue = $item['status'] === 'active'
      && ($check === null || $check < (new DateTime('-90 days'))->format('Y-m-d'));

$pageTitle = (string)$item['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($item['name']) ?>
            <?php if ($isLow): ?> <span class="pill pill-warn">Reorder</span><?php endif; ?>
            <?php if ($isDue): ?> <span class="pill pill-warn">Due for check</span><?php endif; ?>
        </h1>
        <p class="muted">
            <?php if (!empty($item['sku'])): ?>#<?= e($item['sku']) ?> · <?php endif; ?>
            <?= e($item['category']) ?>
            <?php if (!empty($item['sub_category'])): ?> · <?= e($item['sub_category']) ?><?php endif; ?>
            · <span class="pill"><?= e(inventory_status_label((string)$item['status'])) ?></span>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/inventory/index.php">← Inventory</a>
        <a class="btn btn-primary" href="/inventory/edit.php?id=<?= (int)$item['id'] ?>">Edit</a>
    </div>
</div>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 280px;">
        <h2 style="margin-top:0;">Stock</h2>
        <p style="font-size:2rem; font-weight:800; margin:.1rem 0;">
            <?= e(inventory_qty((float)$item['quantity'])) ?>
            <span class="muted" style="font-size:1rem; font-weight:600;"><?= e($item['unit']) ?></span>
        </p>
        <p class="muted small" style="margin:0;">Minimum: <?= e(inventory_qty((float)$item['reorder_level'])) ?> <?= e($item['unit']) ?></p>
        <?php if ($item['unit_cost'] !== null): ?>
            <p class="muted small" style="margin:.35rem 0 0;">
                Value on hand: <strong><?= e(inventory_money((float)$item['quantity'] * (float)$item['unit_cost'])) ?></strong>
                <span class="muted">(<?= e(inventory_money((float)$item['unit_cost'])) ?>/unit)</span>
            </p>
        <?php endif; ?>
    </div>

    <div class="card" style="flex: 1 1 320px;">
        <h2 style="margin-top:0;">Verification</h2>
        <p style="margin:.2rem 0;">Last checked: <strong><?= e($check ?: 'Never') ?></strong></p>
        <form method="post" style="display:inline-flex; gap:.4rem; margin-top:.6rem;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="mark_checked">
            <button class="btn btn-primary" type="submit">I checked this today</button>
        </form>
        <p class="muted small" style="margin-top:.5rem;">Or set the exact date via <a href="/inventory/edit.php?id=<?= (int)$item['id'] ?>">Edit</a>.</p>
    </div>

    <div class="card" style="flex: 1 1 280px;">
        <h2 style="margin-top:0;">Retire / reinstate</h2>
        <p class="muted small" style="margin:.2rem 0 .6rem;">Items are never deleted — change the status instead.</p>
        <form method="post" style="display:flex; gap:.4rem; flex-wrap:wrap;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="set_status">
            <select name="status" style="flex:1 1 160px;">
                <?php foreach (inventory_statuses() as $code => $label): ?>
                    <option value="<?= e($code) ?>" <?= $item['status'] === $code ? 'selected' : '' ?>><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
            <button class="btn" type="submit">Update status</button>
        </form>
    </div>
</div>

<div class="card">
    <h2 style="margin-top:0;">Details</h2>
    <dl class="dl-grid">
        <div class="dl-row"><dt>Item ID</dt><dd><?= e($item['sku'] ?? '—') ?></dd></div>
        <div class="dl-row"><dt>Category</dt><dd><?= e($item['category']) ?><?= !empty($item['sub_category']) ? ' · ' . e($item['sub_category']) : '' ?></dd></div>
        <div class="dl-row"><dt>Storage location</dt><dd><?= e($item['location'] ?? '—') ?></dd></div>
        <div class="dl-row"><dt>Condition</dt><dd><?= e(inventory_condition_label((string)$item['condition'])) ?></dd></div>
        <div class="dl-row"><dt>Purchase date</dt><dd><?= e($item['purchase_date'] ?? '—') ?></dd></div>
        <div class="dl-row"><dt>Purchase cost</dt><dd><?= $item['unit_cost'] === null ? '—' : e(inventory_money((float)$item['unit_cost'])) . '/unit' ?></dd></div>
        <div class="dl-row"><dt>Supplier / vendor</dt><dd><?= e($item['supplier'] ?? '—') ?></dd></div>
        <div class="dl-row"><dt>Assigned to</dt><dd><?= e($item['assigned_to'] ?? '—') ?></dd></div>
        <?php if (!empty($item['notes'])): ?>
            <div class="dl-row"><dt>Remarks</dt><dd><pre class="pre-wrap"><?= e($item['notes']) ?></pre></dd></div>
        <?php endif; ?>
    </dl>
</div>

<?php if ($moves): ?>
<div class="card">
    <h2 style="margin-top:0;">Movement history</h2>
    <table class="data-table">
        <thead><tr><th>When</th><th>Kind</th><th style="text-align:right;">Qty</th><th style="text-align:right;">Balance after</th><th>Reason</th><th>By</th></tr></thead>
        <tbody>
            <?php foreach ($moves as $m): ?>
                <tr>
                    <td><?= e(date('j M Y · H:i', strtotime($m['created_at']))) ?></td>
                    <td><?= e($m['kind']) ?></td>
                    <td style="text-align:right;"><?= e(inventory_qty((float)$m['quantity'])) ?></td>
                    <td style="text-align:right;"><?= e(inventory_qty((float)$m['balance_after'])) ?></td>
                    <td><?= e(inventory_reason_label((string)$m['kind'], (string)($m['reason'] ?? ''))) ?>
                        <?php if (!empty($m['note'])): ?> <span class="muted small"><?= e($m['note']) ?></span><?php endif; ?>
                    </td>
                    <td><?= e($m['moved_by_name'] ?? '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
