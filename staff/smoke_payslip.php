<?php
/**
 * staff/smoke_payslip.php — LOP edit + call-back-to-draft.
 *
 * CLI or loopback only. SMOKE- fixtures are hard-deleted in finally.
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/staff.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "BEGIN staff payslip smoke (sapi=" . PHP_SAPI . ")\n";

$admin = db()->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) { fwrite(STDERR, "FAIL\n  - no active admin\n"); exit(1); }
$adminId = (int)$admin['id'];

$failures = [];
$userId = 0;

try {
    $applied = staff_payslip_apply_days([
        'gross_earnings'   => 30000.0,
        'total_deductions' => 500.0,
        'lop_leave_days'   => 1.0,
        'lop_absent_days'  => 0.0,
        'working_days'     => 30,
        'lop_days'         => 0,
        'lop_amount'       => 0,
        'net_pay'          => 0,
    ], 30.0, 3.0);
    if ((float)$applied['lop_amount'] !== 3000.0) {
        $failures[] = 'LOP amount expected 3000, got ' . $applied['lop_amount'];
    }
    if ((float)$applied['net_pay'] !== 26500.0) {
        $failures[] = 'net expected 26500, got ' . $applied['net_pay'];
    }
    if ((float)$applied['lop_leave_days'] !== 1.0) {
        $failures[] = 'leave LOP should stay capped at 1';
    }
    if ((float)$applied['lop_absent_days'] !== 2.0) {
        $failures[] = 'extra LOP should land on absent days';
    }

    $ts = time() . '-' . bin2hex(random_bytes(2));
    db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :p, 'teacher', 'staff', 1)")
        ->execute([':n' => "SMOKE-PS-$ts", ':p' => password_hash("smoke-$ts", PASSWORD_DEFAULT)]);
    $userId = (int)db()->lastInsertId();

    db()->prepare("
        INSERT INTO staff_pay (user_id, effective_from, basic, payable_days_basis, created_by)
        VALUES (:u, '2099-01-01', 30000, 30, :by)
    ")->execute([':u' => $userId, ':by' => $adminId]);

    db()->prepare("
        INSERT INTO staff_attendance (user_id, att_date, status, notes, marked_by)
        VALUES (:u, '2099-06-10', 'absent', 'SMOKE absent', :by)
    ")->execute([':u' => $userId, ':by' => $adminId]);

    $draft = staff_payslip_draft($userId, 2099, 6);
    if (!$draft['has_pay']) $failures[] = 'draft should have pay';
    if ((float)$draft['lop_days'] < 1) $failures[] = 'draft LOP should include the absent day';

    $issuedView = staff_payslip_apply_days($draft, (float)$draft['working_days'], 2.0);
    db()->prepare("
        INSERT INTO staff_payslips
            (user_id, period_year, period_month, working_days, present_days,
             paid_leave_days, lop_days, lop_leave_days, lop_absent_days,
             hours_worked, earnings_json, deductions_json,
             gross_earnings, lop_amount, total_deductions, net_pay, generated_by)
        VALUES
            (:u, 2099, 6, :wd, :pd, :pl, :lop, :lopl, :lopa, :hrs, :ej, :dj,
             :gross, :lopamt, :totded, :net, :by)
    ")->execute([
        ':u' => $userId,
        ':wd' => $issuedView['working_days'], ':pd' => $issuedView['present_days'],
        ':pl' => $issuedView['paid_leave_days'], ':lop' => $issuedView['lop_days'],
        ':lopl' => $issuedView['lop_leave_days'], ':lopa' => $issuedView['lop_absent_days'],
        ':hrs' => $issuedView['hours_worked'],
        ':ej' => json_encode($issuedView['earnings']),
        ':dj' => json_encode($issuedView['deductions']),
        ':gross' => $issuedView['gross_earnings'], ':lopamt' => $issuedView['lop_amount'],
        ':totded' => $issuedView['total_deductions'], ':net' => $issuedView['net_pay'],
        ':by' => $adminId,
    ]);

    $row = staff_payslip($userId, 2099, 6);
    if (!$row) $failures[] = 'issued row missing';
    if ($row && (float)$row['lop_days'] !== 2.0) $failures[] = 'issued LOP should be 2';

    $fromIssued = staff_payslip_view_from_issued($row);
    $edited = staff_payslip_apply_days($fromIssued, (float)$fromIssued['working_days'], 0.5);
    if ((float)$edited['lop_days'] !== 0.5) $failures[] = 'edited LOP should be 0.5';
    if ((float)$edited['gross_earnings'] !== (float)$fromIssued['gross_earnings']) {
        $failures[] = 'editing LOP must not change gross';
    }

    if (!staff_payslip_recall($userId, 2099, 6)) $failures[] = 'recall should delete the issued row';
    if (staff_payslip($userId, 2099, 6) !== null) $failures[] = 'after recall the month must be draft';
    if (staff_payslip_recall($userId, 2099, 6)) $failures[] = 'second recall should be a no-op';

    $draftAgain = staff_payslip_draft($userId, 2099, 6);
    if ((float)$draftAgain['lop_days'] !== (float)$draft['lop_days']) {
        $failures[] = 'draft after recall should follow attendance again';
    }
} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
} finally {
    if ($userId > 0) {
        db()->prepare("DELETE FROM staff_payslips WHERE user_id = :u")->execute([':u' => $userId]);
        db()->prepare("DELETE FROM staff_pay WHERE user_id = :u")->execute([':u' => $userId]);
        db()->prepare("DELETE FROM staff_attendance WHERE user_id = :u AND notes LIKE 'SMOKE%'")->execute([':u' => $userId]);
        db()->prepare("DELETE FROM users WHERE id = :u AND name LIKE 'SMOKE-PS-%'")->execute([':u' => $userId]);
    }
}

if ($failures) {
    echo "FAIL\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "PASS staff payslip smoke\n";
exit(0);
