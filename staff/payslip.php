<?php
/**
 * staff/payslip.php — generate, issue, view and print payslips.
 *
 *   GET  ?id=&year=&month=     Draft or issued view. Admin may pass
 *                              working_days / lop_days to preview net without
 *                              saving.
 *   POST op=issue              Admin: save (or update) the snapshot, including
 *                              an edited LOP. Already-issued slips keep their
 *                              earnings snapshot; only days / LOP / net change.
 *   POST op=recall             Admin: delete the issued snapshot so the month
 *                              is a draft again (live from pay + attendance).
 *
 * Staff can view their own issued payslips; only admins can edit LOP / issue.
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

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!$isAdmin) { http_response_code(403); echo 'Admins only.'; exit; }
    $op = (string)($_POST['op'] ?? '');
    $back = '/staff/payslip.php?id=' . $id . '&year=' . $year . '&month=' . $month;
    $when = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));

    if ($op === 'recall') {
        if (staff_payslip_recall($id, $year, $month)) {
            flash_set('ok', 'Payslip for ' . $when . ' called back to draft.');
        } else {
            flash_set('error', 'That payslip was not issued.');
        }
        redirect($back);
    }

    if ($op !== 'issue') {
        flash_set('error', 'Unknown action.');
        redirect($back);
    }

    $existing = staff_payslip($id, $year, $month);
    if ($existing) {
        // Keep the issued earnings snapshot. Only working days / LOP / net
        // change — a later pay-structure edit must not rewrite this slip.
        $base = staff_payslip_view_from_issued($existing);
    } else {
        $base = staff_payslip_draft($id, $year, $month);
        if (!$base['has_pay']) {
            flash_set('error', 'Set a pay structure first.');
            redirect('/staff/pay.php?id=' . $id);
        }
    }

    $workingDays = staff_payslip_day_input($_POST['working_days'] ?? null, (float)$base['working_days'], 1.0, 31.0);
    $lopDays     = staff_payslip_day_input($_POST['lop_days'] ?? null, (float)$base['lop_days'], 0.0, 31.0);
    $view        = staff_payslip_apply_days($base, $workingDays, $lopDays);
    $notes       = array_key_exists('notes', $_POST)
        ? (trim((string)$_POST['notes']) ?: null)
        : ($existing['notes'] ?? null);

    db()->prepare("
        INSERT INTO staff_payslips
            (user_id, period_year, period_month, working_days, present_days,
             paid_leave_days, lop_days, lop_leave_days, lop_absent_days,
             hours_worked, earnings_json, deductions_json,
             gross_earnings, lop_amount, total_deductions, net_pay, notes, generated_by)
        VALUES
            (:u, :y, :m, :wd, :pd, :pl, :lop, :lopl, :lopa, :hrs, :ej, :dj,
             :gross, :lopamt, :totded, :net, :notes, :by)
        ON DUPLICATE KEY UPDATE
            working_days = VALUES(working_days), present_days = VALUES(present_days),
            paid_leave_days = VALUES(paid_leave_days), lop_days = VALUES(lop_days),
            lop_leave_days = VALUES(lop_leave_days), lop_absent_days = VALUES(lop_absent_days),
            hours_worked = VALUES(hours_worked), earnings_json = VALUES(earnings_json),
            deductions_json = VALUES(deductions_json), gross_earnings = VALUES(gross_earnings),
            lop_amount = VALUES(lop_amount), total_deductions = VALUES(total_deductions),
            net_pay = VALUES(net_pay), notes = VALUES(notes),
            generated_by = VALUES(generated_by), generated_at = CURRENT_TIMESTAMP
    ")->execute([
        ':u' => $id, ':y' => $year, ':m' => $month,
        ':wd' => $view['working_days'], ':pd' => $view['present_days'], ':pl' => $view['paid_leave_days'],
        ':lop' => $view['lop_days'],
        ':lopl' => $view['lop_leave_days'],
        ':lopa' => $view['lop_absent_days'],
        ':hrs' => $view['hours_worked'],
        ':ej' => json_encode($view['earnings'], JSON_UNESCAPED_UNICODE),
        ':dj' => json_encode($view['deductions'], JSON_UNESCAPED_UNICODE),
        ':gross' => $view['gross_earnings'], ':lopamt' => $view['lop_amount'],
        ':totded' => $view['total_deductions'], ':net' => $view['net_pay'],
        ':notes' => $notes,
        ':by' => (int)$user['id'],
    ]);
    flash_set('ok', $existing
        ? 'Payslip updated for ' . $when . '.'
        : 'Payslip issued for ' . $when . '.');
    redirect($back);
}

$issued = staff_payslip($id, $year, $month);
$auto   = staff_payslip_draft($id, $year, $month);

if ($issued) {
    $view = staff_payslip_view_from_issued($issued);
} else {
    $view = $auto;
}

// Admin preview: apply From/To-style day overrides from the query string
// so they can see net change before saving.
if ($isAdmin && $view['has_pay'] && (isset($_GET['working_days']) || isset($_GET['lop_days']))) {
    $wd  = staff_payslip_day_input($_GET['working_days'] ?? null, (float)$view['working_days'], 1.0, 31.0);
    $lop = staff_payslip_day_input($_GET['lop_days'] ?? null, (float)$view['lop_days'], 0.0, 31.0);
    $view = staff_payslip_apply_days($view, $wd, $lop);
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
        <?php $dd = fn($v) => rtrim(rtrim(number_format((float)$v, 1), '0'), '.'); ?>
        <dt>Present</dt><dd><?= (int)$view['present_days'] ?> · paid leave <?= e($dd($view['paid_leave_days'])) ?> · LOP <?= e($dd($view['lop_days'])) ?></dd>
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
                    <tr>
                        <td>
                            Loss of pay (<?= e($dd($view['lop_days'])) ?> d)
                            <?php
                            // Where the LOP came from. An unexplained deduction
                            // on a payslip is the fastest way to lose someone's
                            // trust, so name the two sources separately.
                            $ll = (float)($view['lop_leave_days'] ?? 0);
                            $la = (float)($view['lop_absent_days'] ?? 0);
                            if ($ll > 0 || $la > 0): ?>
                                <br><span class="muted small">
                                <?php if ($ll > 0): ?>
                                    <?= e($dd($ll)) ?> leave beyond balance / unpaid<?= $la > 0 ? ' · ' : '' ?>
                                <?php endif; ?>
                                <?php if ($la > 0): ?><?= e($dd($la)) ?> absent<?php endif; ?>
                                </span>
                            <?php endif; ?>
                        </td>
                        <td><?= e(staff_money($view['lop_amount'])) ?></td>
                    </tr>
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

<?php if ($isAdmin && !empty($view['has_pay'])):
    $autoLop = (float)($auto['lop_days'] ?? 0);
    $autoWd  = (float)($auto['working_days'] ?? 0);
    $noteVal = $issued ? (string)($issued['notes'] ?? '') : '';
?>
<div class="card no-print">
    <h3><?= $issued ? 'Edit issued payslip' : 'Issue this payslip' ?></h3>
    <p class="muted small">
        Set loss-of-pay days, then <?= $issued ? 'save' : 'issue' ?>.
        Attendance currently suggests
        <strong><?= e(rtrim(rtrim(number_format($autoLop, 1), '0'), '.')) ?></strong> LOP day<?= abs($autoLop - 1.0) < 0.001 ? '' : 's' ?>
        on a <?= e(rtrim(rtrim(number_format($autoWd, 1), '0'), '.')) ?>-day basis.
        <?php if ($issued): ?>
            Calling back to draft drops the issued snapshot; figures follow live attendance until you issue again.
        <?php else: ?>
            Issuing snapshots the figures so later pay changes don't alter this slip.
        <?php endif; ?>
    </p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="issue">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
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
                <input id="notes" name="notes" maxlength="255" value="<?= e($noteVal) ?>">
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit"><?= $issued ? 'Save LOP' : 'Issue payslip' ?></button>
        </div>
    </form>
    <?php if ($issued): ?>
    <form method="post" style="margin-top:.8rem;" onsubmit="return confirm('Call this payslip back to draft? The issued snapshot is removed and figures will follow attendance until you issue again.');">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="recall">
        <input type="hidden" name="id" value="<?= $id ?>">
        <input type="hidden" name="year" value="<?= $year ?>">
        <input type="hidden" name="month" value="<?= $month ?>">
        <button class="btn btn-ghost" type="submit">Call back to draft</button>
    </form>
    <?php endif; ?>
</div>
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
