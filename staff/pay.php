<?php
/**
 * staff/pay.php — admin: set / revise a staff member's pay structure.
 *
 * Pay is effective-dated: saving a new structure with a later effective_from
 * keeps the old rows on file so historical payslips stay correct. Admin only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');
if (!staff_is_admin($user)) { http_response_code(403); echo 'Admins only.'; exit; }

$id    = (int)($_GET['id'] ?? 0);
$staff = staff_member($id);
if (!$staff) { http_response_code(404); echo 'Staff member not found.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $effFrom = trim($_POST['effective_from'] ?? '');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $effFrom)) $effFrom = date('Y-m-01');

    $num = fn(string $k) => max(0, round((float)($_POST[$k] ?? 0), 2));
    $basis = (int)($_POST['payable_days_basis'] ?? 30);
    if ($basis < 1 || $basis > 31) $basis = 30;

    db()->prepare("
        INSERT INTO staff_pay
            (user_id, effective_from, basic, hra, conveyance, special_allowance, other_earning,
             pf, esi, professional_tax, tds, other_deduction, payable_days_basis, notes, created_by)
        VALUES
            (:u, :eff, :basic, :hra, :conv, :spec, :oe,
             :pf, :esi, :pt, :tds, :od, :basis, :notes, :by)
    ")->execute([
        ':u' => $id, ':eff' => $effFrom,
        ':basic' => $num('basic'), ':hra' => $num('hra'), ':conv' => $num('conveyance'),
        ':spec' => $num('special_allowance'), ':oe' => $num('other_earning'),
        ':pf' => $num('pf'), ':esi' => $num('esi'), ':pt' => $num('professional_tax'),
        ':tds' => $num('tds'), ':od' => $num('other_deduction'),
        ':basis' => $basis, ':notes' => trim($_POST['notes'] ?? '') ?: null,
        ':by' => (int)$user['id'],
    ]);
    flash_set('ok', 'Pay structure saved, effective ' . date('j M Y', strtotime($effFrom)) . '.');
    redirect('/staff/pay.php?id=' . $id);
}

$current = staff_current_pay($id, date('Y-m-d'));
$history = staff_pay_history($id);
$cur     = fn(string $k) => $current ? (float)$current[$k] : 0.0;

$pageTitle = 'Pay — ' . $staff['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Pay structure</h1>
        <p class="muted"><a href="/staff/view.php?id=<?= $id ?>">← <?= e($staff['name']) ?></a></p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/staff/payslip.php?id=<?= $id ?>">Payslips →</a>
    </div>
</div>

<?php if ($current): ?>
<div class="card">
    <h3>Current — effective <?= e(date('j M Y', strtotime($current['effective_from']))) ?></h3>
    <p>
        Gross <strong><?= e(staff_money(staff_pay_gross($current))) ?></strong>/mo ·
        deductions <?= e(staff_money(staff_pay_total_deductions($current))) ?> ·
        net <strong><?= e(staff_money(staff_pay_gross($current) - staff_pay_total_deductions($current))) ?></strong>
        <span class="muted">(before any loss-of-pay)</span>
    </p>
</div>
<?php endif; ?>

<form method="post" class="card">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <h3><?= $current ? 'Revise pay' : 'Set pay structure' ?></h3>

    <div class="field" style="max-width:260px;">
        <label for="effective_from">Effective from</label>
        <input id="effective_from" name="effective_from" type="date" value="<?= e(date('Y-m-01')) ?>" required>
    </div>

    <div class="row" style="gap:2rem; align-items:flex-start;">
        <div style="flex:1 1 280px;">
            <h4>Earnings (monthly)</h4>
            <?php foreach (staff_pay_earnings() as $k => $label): ?>
                <div class="field">
                    <label for="<?= e($k) ?>"><?= e($label) ?></label>
                    <input id="<?= e($k) ?>" name="<?= e($k) ?>" type="number" min="0" step="0.01" value="<?= e(number_format($cur($k), 2, '.', '')) ?>">
                </div>
            <?php endforeach; ?>
        </div>
        <div style="flex:1 1 280px;">
            <h4>Deductions (monthly)</h4>
            <?php foreach (staff_pay_deductions() as $k => $label): ?>
                <div class="field">
                    <label for="<?= e($k) ?>"><?= e($label) ?></label>
                    <input id="<?= e($k) ?>" name="<?= e($k) ?>" type="number" min="0" step="0.01" value="<?= e(number_format($cur($k), 2, '.', '')) ?>">
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <div class="row">
        <div class="field" style="max-width:220px;">
            <label for="payable_days_basis">Payable-days basis</label>
            <select id="payable_days_basis" name="payable_days_basis">
                <?php $b = $current ? (int)$current['payable_days_basis'] : 30; ?>
                <option value="30" <?= $b === 30 ? 'selected' : '' ?>>30 (fixed)</option>
                <option value="26" <?= $b === 26 ? 'selected' : '' ?>>26 (working days)</option>
                <option value="31" <?= $b === 31 ? 'selected' : '' ?>>31</option>
                <option value="28" <?= $b === 28 ? 'selected' : '' ?>>28</option>
            </select>
            <span class="muted small">Used for the per-day rate when computing loss-of-pay.</span>
        </div>
        <div class="field" style="flex:1 1 280px;">
            <label for="notes">Notes</label>
            <input id="notes" name="notes" maxlength="255" value="">
        </div>
    </div>

    <div class="actions" style="margin-top:.8rem;">
        <button class="btn btn-primary" type="submit">Save pay structure</button>
    </div>
</form>

<?php if (count($history) > 1): ?>
<div class="card">
    <h3>History</h3>
    <table class="admin-table">
        <thead><tr><th>Effective</th><th>Gross</th><th>Deductions</th><th>Net (pre-LOP)</th><th>Notes</th></tr></thead>
        <tbody>
            <?php foreach ($history as $h): ?>
                <tr>
                    <td><?= e(date('j M Y', strtotime($h['effective_from']))) ?></td>
                    <td><?= e(staff_money(staff_pay_gross($h))) ?></td>
                    <td><?= e(staff_money(staff_pay_total_deductions($h))) ?></td>
                    <td><?= e(staff_money(staff_pay_gross($h) - staff_pay_total_deductions($h))) ?></td>
                    <td class="muted"><?= e((string)($h['notes'] ?? '')) ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
