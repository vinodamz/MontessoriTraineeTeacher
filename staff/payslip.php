<?php
/**
 * staff/payslip.php — generate, issue, view and print payslips.
 *
 *   GET  ?id=&year=&month=     Draft view: computes from pay structure +
 *                              attendance. Admin can adjust working/LOP days
 *                              then issue. If already issued, shows the saved
 *                              snapshot (immutable).
 *   POST op=issue              Admin: snapshot the payslip into staff_payslips.
 *
 * Staff can view their own issued payslips; only admins can issue them.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user    = require_module('staff');
$isAdmin = staff_is_admin($user);

$id = (int)($_GET['id'] ?? $_POST['id'] ?? $user['id']);
if (!staff_can_view($user, $id)) { http_response_code(403); echo 'Forbidden.'; exit; }
$staff = staff_member($id);
if (!$staff) { http_response_code(404); echo 'Staff member not found.'; exit; }

$year  = (int)($_GET['year']  ?? $_POST['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? $_POST['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int)date('n');

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'issue') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); echo 'Admins only.'; exit; }

    $draft = staff_payslip_draft($id, $year, $month);
    if (!$draft['has_pay']) {
        flash_set('error', 'Set a pay structure first.');
        redirect('/staff/pay.php?id=' . $id);
    }

    // Admin overrides for working / LOP days.
    $workingDays = max(1, (float)($_POST['working_days'] ?? $draft['working_days']));
    $lopDays     = max(0, (float)($_POST['lop_days'] ?? $draft['lop_days']));
    $gross       = $draft['gross_earnings'];
    $perDay      = $workingDays > 0 ? $gross / $workingDays : 0.0;
    $lopAmt      = round($perDay * $lopDays, 2);
    $totDed      = $draft['total_deductions'];
    $net         = round($gross - $lopAmt - $totDed, 2);

    db()->prepare("
        INSERT INTO staff_payslips
            (user_id, period_year, period_month, working_days, present_days,
             paid_leave_days, lop_days, hours_worked, earnings_json, deductions_json,
             gross_earnings, lop_amount, total_deductions, net_pay, notes, generated_by)
        VALUES
            (:u, :y, :m, :wd, :pd, :pl, :lop, :hrs, :ej, :dj,
             :gross, :lopamt, :totded, :net, :notes, :by)
        ON DUPLICATE KEY UPDATE
            working_days = VALUES(working_days), present_days = VALUES(present_days),
            paid_leave_days = VALUES(paid_leave_days), lop_days = VALUES(lop_days),
            hours_worked = VALUES(hours_worked), earnings_json = VALUES(earnings_json),
            deductions_json = VALUES(deductions_json), gross_earnings = VALUES(gross_earnings),
            lop_amount = VALUES(lop_amount), total_deductions = VALUES(total_deductions),
            net_pay = VALUES(net_pay), notes = VALUES(notes),
            generated_by = VALUES(generated_by), generated_at = CURRENT_TIMESTAMP
    ")->execute([
        ':u' => $id, ':y' => $year, ':m' => $month,
        ':wd' => $workingDays, ':pd' => $draft['present_days'], ':pl' => $draft['paid_leave_days'],
        ':lop' => $lopDays, ':hrs' => $draft['hours_worked'],
        ':ej' => json_encode($draft['earnings'], JSON_UNESCAPED_UNICODE),
        ':dj' => json_encode($draft['deductions'], JSON_UNESCAPED_UNICODE),
        ':gross' => $gross, ':lopamt' => $lopAmt, ':totded' => $totDed, ':net' => $net,
        ':notes' => trim($_POST['notes'] ?? '') ?: null, ':by' => (int)$user['id'],
    ]);
    flash_set('ok', 'Payslip issued for ' . date('F Y', strtotime("$year-$month-01")) . '.');
    redirect('/staff/payslip.php?id=' . $id . '&year=' . $year . '&month=' . $month);
}

$issued = staff_payslip($id, $year, $month);

// If issued, render from the snapshot; otherwise compute a live draft.
if ($issued) {
    $earnings   = json_decode((string)$issued['earnings_json'], true) ?: [];
    $deductions = json_decode((string)$issued['deductions_json'], true) ?: [];
    $view = [
        'working_days'     => (float)$issued['working_days'],
        'present_days'     => (float)$issued['present_days'],
        'paid_leave_days'  => (float)$issued['paid_leave_days'],
        'lop_days'         => (float)$issued['lop_days'],
        'hours_worked'     => (float)$issued['hours_worked'],
        'earnings'         => $earnings,
        'deductions'       => $deductions,
        'gross_earnings'   => (float)$issued['gross_earnings'],
        'lop_amount'       => (float)$issued['lop_amount'],
        'total_deductions' => (float)$issued['total_deductions'],
        'net_pay'          => (float)$issued['net_pay'],
        'has_pay'          => true,
    ];
} else {
    $view = staff_payslip_draft($id, $year, $month);
}

// Recent issued payslips for this staff member.
$recent = db()->prepare("
    SELECT period_year, period_month, net_pay, generated_at
    FROM staff_payslips WHERE user_id = :u
    ORDER BY period_year DESC, period_month DESC LIMIT 12
");
$recent->execute([':u' => $id]);
$recentSlips = $recent->fetchAll();

$periodLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$pageTitle   = 'Payslip — ' . $staff['name'];
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head no-print">
    <div>
        <h1>Payslip</h1>
        <p class="muted"><a href="/staff/view.php?id=<?= $id ?>">← <?= e($staff['name']) ?></a></p>
    </div>
    <div class="actionbar">
        <?php if ($isAdmin): ?><a class="btn" href="/staff/pay.php?id=<?= $id ?>">Pay structure</a><?php endif; ?>
        <?php if ($issued): ?><button class="btn" onclick="window.print()">Print / PDF</button><?php endif; ?>
    </div>
</div>

<!-- Period picker -->
<form method="get" class="card no-print" style="display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap;">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="field" style="margin:0;">
        <label for="month">Month</label>
        <select id="month" name="month">
            <?php for ($m = 1; $m <= 12; $m++): ?>
                <option value="<?= $m ?>" <?= $m === $month ? 'selected' : '' ?>><?= e(date('F', mktime(0,0,0,$m,1))) ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <div class="field" style="margin:0;">
        <label for="year">Year</label>
        <select id="year" name="year">
            <?php for ($y = (int)date('Y'); $y >= (int)date('Y') - 3; $y--): ?>
                <option value="<?= $y ?>" <?= $y === $year ? 'selected' : '' ?>><?= $y ?></option>
            <?php endfor; ?>
        </select>
    </div>
    <button class="btn btn-primary" type="submit">View</button>
    <?php if ($issued): ?>
        <span class="pill pill-ok">Issued <?= e(date('j M Y', strtotime($issued['generated_at']))) ?></span>
    <?php else: ?>
        <span class="pill pill-warn">Draft — not yet issued</span>
    <?php endif; ?>
</form>

<?php if (!$view['has_pay']): ?>
    <div class="card">
        <p>No pay structure on file for <?= e($staff['name']) ?>.
        <?php if ($isAdmin): ?><a href="/staff/pay.php?id=<?= $id ?>">Set one →</a><?php endif; ?></p>
    </div>
<?php else: ?>

<!-- ===== PAYSLIP ===== -->
<div class="card payslip">
    <div class="payslip-head">
        <div>
            <div class="payslip-school"><?= e(app_name()) ?></div>
            <div class="muted small">Payslip · <?= e($periodLabel) ?></div>
        </div>
        <div class="payslip-net">
            <span class="muted small">Net pay</span>
            <strong><?= e(staff_money($view['net_pay'])) ?></strong>
        </div>
    </div>

    <dl class="payslip-meta">
        <dt>Employee</dt><dd><?= e($staff['name']) ?></dd>
        <dt>Role</dt><dd><?= e(ucfirst((string)$staff['role'])) ?></dd>
        <dt>Working days</dt><dd><?= e(rtrim(rtrim(number_format($view['working_days'],1),'0'),'.')) ?></dd>
        <dt>Present</dt><dd><?= (int)$view['present_days'] ?> · paid leave <?= (int)$view['paid_leave_days'] ?> · LOP <?= (int)$view['lop_days'] ?></dd>
        <dt>Hours worked</dt><dd><?= e(number_format($view['hours_worked'], 1)) ?> h</dd>
    </dl>

    <div class="payslip-cols">
        <table class="payslip-table">
            <thead><tr><th>Earnings</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach (staff_pay_earnings() as $k => $label): if (($view['earnings'][$k] ?? 0) <= 0) continue; ?>
                    <tr><td><?= e($label) ?></td><td><?= e(staff_money((float)$view['earnings'][$k])) ?></td></tr>
                <?php endforeach; ?>
                <tr class="total"><td>Gross earnings</td><td><?= e(staff_money($view['gross_earnings'])) ?></td></tr>
            </tbody>
        </table>

        <table class="payslip-table">
            <thead><tr><th>Deductions</th><th>Amount</th></tr></thead>
            <tbody>
                <?php foreach (staff_pay_deductions() as $k => $label): if (($view['deductions'][$k] ?? 0) <= 0) continue; ?>
                    <tr><td><?= e($label) ?></td><td><?= e(staff_money((float)$view['deductions'][$k])) ?></td></tr>
                <?php endforeach; ?>
                <?php if ($view['lop_amount'] > 0): ?>
                    <tr><td>Loss of pay (<?= (int)$view['lop_days'] ?> d)</td><td><?= e(staff_money($view['lop_amount'])) ?></td></tr>
                <?php endif; ?>
                <tr class="total"><td>Total deductions</td><td><?= e(staff_money($view['total_deductions'] + $view['lop_amount'])) ?></td></tr>
            </tbody>
        </table>
    </div>

    <div class="payslip-netline">
        Net pay = <?= e(staff_money($view['gross_earnings'])) ?>
        − <?= e(staff_money($view['total_deductions'] + $view['lop_amount'])) ?>
        = <strong><?= e(staff_money($view['net_pay'])) ?></strong>
    </div>
    <?php if ($issued && $issued['notes']): ?>
        <p class="muted small">Note: <?= e($issued['notes']) ?></p>
    <?php endif; ?>
</div>

<?php if ($isAdmin && !$issued): ?>
<form method="post" class="card no-print">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="issue">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="hidden" name="month" value="<?= $month ?>">
    <h3>Issue this payslip</h3>
    <p class="muted small">Adjust days if needed, then issue. Issuing snapshots the figures so later pay changes don't alter this slip.</p>
    <div class="row">
        <div class="field" style="max-width:160px;">
            <label for="working_days">Working days</label>
            <input id="working_days" name="working_days" type="number" min="1" max="31" step="0.5" value="<?= e((string)$view['working_days']) ?>">
        </div>
        <div class="field" style="max-width:160px;">
            <label for="lop_days">Loss-of-pay days</label>
            <input id="lop_days" name="lop_days" type="number" min="0" max="31" step="0.5" value="<?= e((string)$view['lop_days']) ?>">
        </div>
        <div class="field" style="flex:1 1 240px;">
            <label for="notes">Note (optional)</label>
            <input id="notes" name="notes" maxlength="255">
        </div>
    </div>
    <div class="actions"><button class="btn btn-primary" type="submit">Issue payslip</button></div>
</form>
<?php elseif ($isAdmin && $issued): ?>
<form method="post" class="card no-print" onsubmit="return confirm('Re-issue overwrites the saved figures with a fresh computation. Continue?')">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="op" value="issue">
    <input type="hidden" name="id" value="<?= $id ?>">
    <input type="hidden" name="year" value="<?= $year ?>">
    <input type="hidden" name="month" value="<?= $month ?>">
    <input type="hidden" name="working_days" value="<?= e((string)$view['working_days']) ?>">
    <input type="hidden" name="lop_days" value="<?= e((string)$view['lop_days']) ?>">
    <button class="btn btn-ghost" type="submit">Re-issue (recompute)</button>
</form>
<?php endif; ?>

<?php endif; ?>

<?php if ($recentSlips): ?>
<div class="card no-print">
    <h3>Issued payslips</h3>
    <table class="admin-table">
        <thead><tr><th>Period</th><th>Net pay</th><th>Issued</th><th></th></tr></thead>
        <tbody>
            <?php foreach ($recentSlips as $s): ?>
                <tr>
                    <td><?= e(date('F Y', strtotime(sprintf('%04d-%02d-01', $s['period_year'], $s['period_month'])))) ?></td>
                    <td><?= e(staff_money((float)$s['net_pay'])) ?></td>
                    <td class="muted"><?= e(date('j M Y', strtotime($s['generated_at']))) ?></td>
                    <td><a class="btn btn-ghost" href="/staff/payslip.php?id=<?= $id ?>&year=<?= (int)$s['period_year'] ?>&month=<?= (int)$s['period_month'] ?>">View</a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
