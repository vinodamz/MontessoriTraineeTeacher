<?php
/**
 * staff/smoke_timesheet.php — assertions for the monthly check-in/out register.
 *
 * CLI or loopback only. Creates a SMOKE- teacher, attendance + leave rows,
 * checks staff_timesheet() grouping / leave overlay / custom range, then
 * hard-deletes the fixtures.
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
set_exception_handler(function (Throwable $e): void {
    echo "FAIL — uncaught " . get_class($e) . "\n";
    echo "  - " . $e->getMessage() . "\n";
    echo "  - " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});

if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "BEGIN staff timesheet smoke (sapi=" . PHP_SAPI . ")\n";

$admin = db()->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) { fwrite(STDERR, "FAIL\n  - no active admin user found\n"); exit(1); }
$adminId = (int)$admin['id'];

$failures = [];
$userId = 0;
$leaveIds = [];

try {
    [$a, $b] = staff_timesheet_range('2026-03-10', '2026-03-01');
    if ($a !== '2026-03-01' || $b !== '2026-03-10') {
        $failures[] = "range swap expected 2026-03-01..10, got $a..$b";
    }
    [$a, $b] = staff_timesheet_range('nope', 'also-nope');
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $a) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $b)) {
        $failures[] = 'invalid dates should fall back to current month';
    }
    if (staff_day_hours('09:00:00', '17:30:00') !== 8.5) {
        $failures[] = 'staff_day_hours 09:00-17:30 should be 8.5';
    }
    if (staff_day_hours('09:00', null) !== null) {
        $failures[] = 'staff_day_hours missing out should be null';
    }

    $ts = time() . '-' . bin2hex(random_bytes(2));
    db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :p, 'teacher', 'staff', 1)")
        ->execute([':n' => "SMOKE-TS-$ts", ':p' => password_hash("smoke-$ts", PASSWORD_DEFAULT)]);
    $userId = (int)db()->lastInsertId();
    if ($userId < 1) $failures[] = 'failed to insert smoke user';

    $from = '2099-06-01';
    $to   = '2099-06-30';
    $inDay    = '2099-06-03';
    $leaveDay = '2099-06-10';
    $pendDay  = '2099-06-20';
    $presentOnLeave = '2099-06-11'; // approved leave + they still clocked in

    db()->prepare("
        INSERT INTO staff_attendance (user_id, att_date, status, check_in, check_out, notes, marked_by)
        VALUES (:u, :d, 'present', '09:00:00', '17:30:00', 'SMOKE in/out', :by)
    ")->execute([':u' => $userId, ':d' => $inDay, ':by' => $adminId]);

    db()->prepare("
        INSERT INTO staff_attendance (user_id, att_date, status, check_in, check_out, notes, marked_by)
        VALUES (:u, :d, 'present', '09:00:00', '16:00:00', 'SMOKE present during leave', :by)
    ")->execute([':u' => $userId, ':d' => $presentOnLeave, ':by' => $adminId]);

    db()->prepare("
        INSERT INTO staff_leave_requests
            (user_id, leave_type, start_date, end_date, half_day, days_count, reason, status, decided_by, decided_at)
        VALUES (:u, 'sick', :s, :e, '', 2, 'SMOKE approved', 'approved', :by, NOW())
    ")->execute([':u' => $userId, ':s' => $leaveDay, ':e' => $presentOnLeave, ':by' => $adminId]);
    $leaveIds[] = (int)db()->lastInsertId();

    db()->prepare("
        INSERT INTO staff_leave_requests
            (user_id, leave_type, start_date, end_date, half_day, days_count, reason, status)
        VALUES (:u, 'casual', :s, :e, 'first', 0.5, 'SMOKE pending', 'pending')
    ")->execute([':u' => $userId, ':s' => $pendDay, ':e' => $pendDay]);
    $leaveIds[] = (int)db()->lastInsertId();

    $sheet = staff_timesheet($from, $to, $userId);
    if (count($sheet) !== 1) {
        $failures[] = 'expected 1 person in filtered timesheet, got ' . count($sheet);
    } else {
        $p = $sheet[0];
        if ((int)$p['user_id'] !== $userId) $failures[] = 'timesheet user_id mismatch';
        $byDate = [];
        foreach ($p['days'] as $d) $byDate[$d['date']] = $d;

        if (!isset($byDate[$inDay])) {
            $failures[] = "missing clocked day $inDay";
        } else {
            $d = $byDate[$inDay];
            if ($d['status'] !== 'present') $failures[] = "clocked day status {$d['status']}";
            if ((float)$d['hours'] !== 8.5) $failures[] = "clocked hours {$d['hours']} expected 8.5";
            if (!empty($d['on_leave'])) $failures[] = 'clocked day should not be on leave';
        }

        if (!isset($byDate[$leaveDay])) {
            $failures[] = "approved leave day $leaveDay missing (no attendance row)";
        } else {
            $d = $byDate[$leaveDay];
            if (empty($d['on_leave'])) $failures[] = 'approved leave day not called out';
            if (($d['leave_type'] ?? '') !== 'sick') $failures[] = 'leave_type should be sick';
            if ($d['status'] !== 'leave') $failures[] = 'leave-only day status should be leave';
            if ($d['check_in'] !== null) $failures[] = 'leave-only day should have no check-in';
        }

        if (!isset($byDate[$presentOnLeave])) {
            $failures[] = "present+leave day $presentOnLeave missing";
        } else {
            $d = $byDate[$presentOnLeave];
            if ($d['status'] !== 'present') $failures[] = 'clocked day during leave should keep present';
            if (empty($d['on_leave'])) $failures[] = 'present+leave day should still call out leave';
        }

        if (!isset($byDate[$pendDay])) {
            $failures[] = "pending leave day $pendDay missing";
        } else {
            $d = $byDate[$pendDay];
            if (!empty($d['on_leave'])) $failures[] = 'pending leave must not count as on leave';
            if (empty($d['leave_pending'])) $failures[] = 'pending leave not flagged';
        }

        if ((int)$p['totals']['leave_days'] !== 2) {
            $failures[] = 'leave_days expected 2 (10th + 11th), got ' . $p['totals']['leave_days'];
        }
        if ((int)$p['totals']['clocked_days'] !== 2) {
            $failures[] = 'clocked_days expected 2, got ' . $p['totals']['clocked_days'];
        }
        if (abs((float)$p['totals']['hours'] - 15.5) > 0.01) {
            $failures[] = 'hours expected 15.5, got ' . $p['totals']['hours'];
        }
    }

    $narrow = staff_timesheet($inDay, $inDay, $userId);
    if (count($narrow) !== 1 || count($narrow[0]['days']) !== 1) {
        $failures[] = 'custom single-day filter should return only the clocked day';
    }

    $empty = staff_timesheet('2098-01-01', '2098-01-31', $userId);
    if ($empty !== []) $failures[] = 'empty range should return no people';

} catch (Throwable $e) {
    $failures[] = 'exception: ' . $e->getMessage();
} finally {
    foreach ($leaveIds as $lid) {
        if ($lid > 0) {
            db()->prepare("DELETE FROM staff_leave_requests WHERE id = :i AND reason LIKE 'SMOKE%'")
                ->execute([':i' => $lid]);
        }
    }
    if ($userId > 0) {
        db()->prepare("DELETE FROM staff_attendance WHERE user_id = :u AND notes LIKE 'SMOKE%'")
            ->execute([':u' => $userId]);
        db()->prepare("DELETE FROM staff_leave_requests WHERE user_id = :u AND reason LIKE 'SMOKE%'")
            ->execute([':u' => $userId]);
        db()->prepare("DELETE FROM users WHERE id = :u AND name LIKE 'SMOKE-TS-%'")
            ->execute([':u' => $userId]);
    }
}

if ($failures) {
    echo "FAIL\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "PASS staff timesheet smoke\n";
exit(0);
