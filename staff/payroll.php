<?php
/**
 * staff/payroll.php — admin payroll overview for a month.
 *
 * One row per staff member showing gross / LOP / net (draft or issued) for
 * the selected month, with links to each payslip. Admin only.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

$user = require_module('staff');
if (!staff_is_admin($user)) { http_response_code(403); echo 'Admins only.'; exit; }

$year  = (int)($_GET['year']  ?? date('Y'));
$month = (int)($_GET['month'] ?? date('n'));
if ($month < 1 || $month > 12) $month = (int)date('n');

$roster = staff_roster(true);

$rows = [];
$totals = ['gross' => 0.0, 'lop' => 0.0, 'ded' => 0.0, 'net' => 0.0, 'issued' => 0];
foreach ($roster as $s) {
    $uid    = (int)$s['id'];
    $issued = staff_payslip($uid, $year, $month);
    if ($issued) {
        $gross = (float)$issued['gross_earnings'];
        $lop   = (float)$issued['lop_amount'];
        $ded   = (float)$issued['total_deductions'];
        $net   = (float)$issued['net_pay'];
        $totals['issued']++;
    } else {
        $d     = staff_payslip_draft($uid, $year, $month);
        $gross = $d['gross_earnings'];
        $lop   = $d['lop_amount'];
        $ded   = $d['total_deductions'];
        $net   = $d['net_pay'];
        if (!$d['has_pay']) { $gross = $lop = $ded = $net = 0.0; }
    }
    $rows[] = [
        'id' => $uid, 'name' => $s['name'],
        'gross' => $gross, 'lop' => $lop, 'ded' => $ded, 'net' => $net,
        'issued' => (bool)$issued, 'has_pay' => $issued || staff_current_pay($uid, date('Y-m-t', strtotime("$year-$month-01"))),
    ];
    $totals['gross'] += $gross; $totals['lop'] += $lop; $totals['ded'] += $ded; $totals['net'] += $net;
}

$periodLabel = date('F Y', strtotime(sprintf('%04d-%02d-01', $year, $month)));
$pageTitle   = 'Payroll — ' . $periodLabel;
$wideLayout  = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Payroll</h1>
        <p class="muted"><a href="/staff/index.php">← Staff</a> · <?= e($periodLabel) ?> · <?= (int)$totals['issued'] ?>/<?= count($rows) ?> issued</p>
    </div>
</div>

<form method="get" class="card" style="display:flex; gap:.6rem; align-items:flex-end; flex-wrap:wrap;">
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
</form>

<div class="card">
    <table class="admin-table">
        <thead>
            <tr><th>Staff</th><th>Gross</th><th>LOP</th><th>Deductions</th><th>Net</th><th>Status</th><th></th></tr>
        </thead>
        <tbody>
            <?php foreach ($rows as $r): ?>
                <tr>
                    <td><a href="/staff/view.php?id=<?= $r['id'] ?>"><?= e($r['name']) ?></a></td>
                    <?php if (!$r['has_pay']): ?>
                        <td colspan="4" class="muted">No pay structure — <a href="/staff/pay.php?id=<?= $r['id'] ?>">set one</a></td>
                    <?php else: ?>
                        <td><?= e(staff_money($r['gross'])) ?></td>
                        <td><?= $r['lop'] > 0 ? e(staff_money($r['lop'])) : '<span class="muted">—</span>' ?></td>
                        <td><?= e(staff_money($r['ded'])) ?></td>
                        <td><strong><?= e(staff_money($r['net'])) ?></strong></td>
                    <?php endif; ?>
                    <td>
                        <?php if ($r['issued']): ?>
                            <span class="pill pill-ok">Issued</span>
                        <?php elseif ($r['has_pay']): ?>
                            <span class="pill pill-warn">Draft</span>
                        <?php endif; ?>
                    </td>
                    <td><a class="btn btn-ghost" href="/staff/payslip.php?id=<?= $r['id'] ?>&year=<?= $year ?>&month=<?= $month ?>"><?= $r['issued'] ? 'View' : 'Review & issue' ?></a></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
        <tfoot>
            <tr class="total">
                <td><strong>Total</strong></td>
                <td><?= e(staff_money($totals['gross'])) ?></td>
                <td><?= e(staff_money($totals['lop'])) ?></td>
                <td><?= e(staff_money($totals['ded'])) ?></td>
                <td><strong><?= e(staff_money($totals['net'])) ?></strong></td>
                <td colspan="2"></td>
            </tr>
        </tfoot>
    </table>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
