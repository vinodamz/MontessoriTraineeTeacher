<?php
/**
 * inventory/view.php — item detail + stock movement (in / out / adjust)
 * + the full movement ledger.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/inventory.php';

$user = require_module('inventory');
$pdo  = db();

$id = (int)($_GET['id'] ?? 0);

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $id   = (int)($_POST['id'] ?? 0);
    $kind = $_POST['kind'] ?? '';
    $qty  = (float)($_POST['quantity'] ?? 0);
    $reason = $_POST['reason'] ?? '';
    $note   = trim($_POST['note'] ?? '');
    if ($reason !== '' && !array_key_exists($reason, inventory_reasons($kind === 'adjust' ? 'adjust' : $kind))) {
        $reason = '';
    }
    try {
        inventory_move($id, $kind, $qty, $reason ?: null, $note ?: null, (int)$user['id']);
        $verb = $kind === 'in' ? 'Stock added' : ($kind === 'out' ? 'Stock removed' : 'Stock adjusted');
        flash_set('ok', "$verb.");
    } catch (Throwable $e) {
        flash_set('error', 'Could not record movement: ' . $e->getMessage());
    }
    redirect('/inventory/view.php?id=' . $id);
}

$stmt = $pdo->prepare("SELECT i.*, u.name AS by_name FROM inventory_items i LEFT JOIN users u ON u.id = i.created_by WHERE i.id = :id");
$stmt->execute([':id' => $id]);
$it = $stmt->fetch();
if (!$it) { http_response_code(404); echo 'Item not found.'; exit; }

$mv = $pdo->prepare("
    SELECT m.*, u.name AS by_name
    FROM inventory_movements m LEFT JOIN users u ON u.id = m.moved_by
    WHERE m.item_id = :id ORDER BY m.created_at DESC, m.id DESC LIMIT 100
");
$mv->execute([':id' => $id]);
$moves = $mv->fetchAll();

$low = $it['reorder_level'] > 0 && $it['quantity'] <= $it['reorder_level'];
$pageTitle = $it['name'] . ' — Inventory';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($it['name']) ?></h1>
        <p class="muted">
            <a href="/inventory/index.php">← Inventory</a> ·
            <?= e(inventory_category_label($it['category'])) ?>
            <?php if ($it['sku']): ?> · <?= e($it['sku']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/inventory/edit.php?id=<?= $id ?>">Edit item</a>
    </div>
</div>

<div class="row" style="align-items:stretch;">
    <div class="card" style="flex:1 1 240px;">
        <h3>Current stock</h3>
        <p style="font-size:2rem; font-weight:700; margin:.2rem 0;">
            <?= e(inventory_qty((float)$it['quantity'])) ?> <span style="font-size:1rem; font-weight:400; color:var(--muted);"><?= e($it['unit']) ?></span>
            <?php if ($low): ?><span class="pill pill-warn">low</span><?php endif; ?>
        </p>
        <dl class="dl-grid">
            <dt>Reorder at</dt><dd><?= $it['reorder_level'] > 0 ? e(inventory_qty((float)$it['reorder_level'])) . ' ' . e($it['unit']) : '—' ?></dd>
            <dt>Unit cost</dt><dd><?= $it['unit_cost'] !== null ? e(inventory_money((float)$it['unit_cost'])) : '—' ?></dd>
            <dt>Stock value</dt><dd><?= $it['unit_cost'] !== null ? e(inventory_money((float)$it['quantity'] * (float)$it['unit_cost'])) : '—' ?></dd>
            <dt>Location</dt><dd><?= e($it['location'] ?? '') ?: '—' ?></dd>
            <dt>Supplier</dt><dd><?= e($it['supplier'] ?? '') ?: '—' ?></dd>
        </dl>
        <?php if ($it['notes']): ?><p class="muted small" style="white-space:pre-wrap;"><?= e($it['notes']) ?></p><?php endif; ?>
    </div>

    <div class="card" style="flex:1 1 320px;">
        <h3>Record stock movement</h3>
        <form method="post">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="id" value="<?= $id ?>">
            <div class="row">
                <div class="field">
                    <label>Type</label>
                    <select name="kind" id="mvKind">
                        <option value="in">Stock in (+)</option>
                        <option value="out">Stock out (−)</option>
                        <option value="adjust">Set exact (stock-take)</option>
                    </select>
                </div>
                <div class="field">
                    <label>Quantity (<?= e($it['unit']) ?>)</label>
                    <input type="number" name="quantity" min="0" step="any" required>
                </div>
            </div>
            <div class="row">
                <div class="field">
                    <label>Reason</label>
                    <select name="reason" id="mvReason"></select>
                </div>
                <div class="field" style="flex:2 1 240px;">
                    <label>Note</label>
                    <input name="note" maxlength="255" placeholder="optional">
                </div>
            </div>
            <div class="actions"><button class="btn btn-primary" type="submit">Record</button></div>
        </form>
    </div>
</div>

<div class="card">
    <h3>Movement history</h3>
    <?php if (!$moves): ?>
        <p class="muted">No movements yet.</p>
    <?php else: ?>
        <table class="admin-table">
            <thead><tr><th>When</th><th>Type</th><th>Qty</th><th>Balance</th><th>Reason</th><th>Note</th><th>By</th></tr></thead>
            <tbody>
                <?php foreach ($moves as $m):
                    $sign = $m['kind'] === 'in' ? '+' : ($m['kind'] === 'out' ? '−' : '=');
                    $kindLabel = ['in' => 'In', 'out' => 'Out', 'adjust' => 'Set'][$m['kind']] ?? $m['kind'];
                ?>
                    <tr>
                        <td class="muted small"><?= e(date('j M Y · H:i', strtotime($m['created_at']))) ?></td>
                        <td><span class="pill <?= $m['kind'] === 'out' ? 'pill-warn' : ($m['kind'] === 'in' ? 'pill-ok' : '') ?>"><?= e($kindLabel) ?></span></td>
                        <td><?= e($sign) ?><?= e(inventory_qty((float)$m['quantity'])) ?></td>
                        <td><strong><?= e(inventory_qty((float)$m['balance_after'])) ?></strong></td>
                        <td class="muted small"><?= $m['reason'] ? e(inventory_reason_label($m['kind'] === 'adjust' ? 'adjust' : $m['kind'], $m['reason'])) : '—' ?></td>
                        <td class="muted small"><?= e($m['note'] ?? '') ?></td>
                        <td class="muted small"><?= e($m['by_name'] ?: '—') ?></td>
                    </tr>
                <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>

<script>
// Populate the reason dropdown based on the movement type.
(() => {
    const reasons = {
        in:     <?= json_encode(inventory_reasons('in'), JSON_UNESCAPED_UNICODE) ?>,
        out:    <?= json_encode(inventory_reasons('out'), JSON_UNESCAPED_UNICODE) ?>,
        adjust: <?= json_encode(inventory_reasons('adjust'), JSON_UNESCAPED_UNICODE) ?>,
    };
    const kind = document.getElementById('mvKind');
    const rsel = document.getElementById('mvReason');
    function fill() {
        const map = reasons[kind.value] || {};
        rsel.innerHTML = '<option value="">— optional —</option>' +
            Object.entries(map).map(([k, v]) => `<option value="${k}">${v}</option>`).join('');
    }
    kind.addEventListener('change', fill);
    fill();
})();
</script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
