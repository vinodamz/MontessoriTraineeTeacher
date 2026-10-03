<?php
/**
 * includes/api_staff.php — JSON shapes for the app's Staff screens.
 *
 * The phone covers the daily loop: check in, see leave, glance at who is in.
 * Payroll, documents and messages stay on the website.
 */
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/staff.php';

function api_staff_hhmm(?string $t): ?string
{
    if ($t === null || $t === '') return null;
    return substr($t, 0, 5);
}

/** The signed-in person's day, plus the roster when they can see attendance. */
function api_staff_day(array $user): array
{
    $uid = (int)$user['id'];
    $today = date('Y-m-d');
    $isAdmin = staff_is_admin($user);

    $row = null;
    $stmt = db()->prepare('SELECT * FROM staff_attendance WHERE user_id = :u AND att_date = :d');
    $stmt->execute([':u' => $uid, ':d' => $today]);
    $found = $stmt->fetch();
    if ($found) $row = $found;

    $shift = staff_shift($uid);
    $cutoff = staff_late_cutoff($shift['start'] ?? null, staff_late_grace_minutes());
    $statuses = staff_attendance_statuses();
    $status = $row ? (string)$row['status'] : null;

    $leave = null;
    try {
        $bal = staff_leave_balance_at($uid);
        $pendingStmt = db()->prepare("SELECT COUNT(*) FROM staff_leave_requests WHERE user_id = :u AND status = 'pending'");
        $pendingStmt->execute([':u' => $uid]);
        $mine = db()->prepare("
            SELECT id, leave_type, start_date, end_date, half_day, days_count, reason, status
            FROM staff_leave_requests
            WHERE user_id = :u
            ORDER BY start_date DESC, id DESC
            LIMIT 8
        ");
        $mine->execute([':u' => $uid]);
        $types = staff_leave_types();
        $leaveStatuses = staff_leave_statuses();
        $requests = [];
        foreach ($mine->fetchAll() as $r) {
            $type = (string)$r['leave_type'];
            $st = (string)$r['status'];
            $requests[] = [
                'id'           => (int)$r['id'],
                'type'         => $type,
                'type_label'   => $types[$type] ?? $type,
                'start_date'   => (string)$r['start_date'],
                'end_date'     => (string)$r['end_date'],
                'half_day'     => (string)($r['half_day'] ?? ''),
                'days'         => (float)$r['days_count'],
                'days_label'   => staff_leave_days_phrase((float)$r['days_count']),
                'reason'       => $r['reason'] !== null ? (string)$r['reason'] : '',
                'status'       => $st,
                'status_label' => $leaveStatuses[$st] ?? $st,
            ];
        }
        $typeList = [];
        foreach ($types as $key => $label) {
            $typeList[] = ['key' => $key, 'label' => $label];
        }
        $queue = 0;
        if ($isAdmin) {
            $queue = (int)db()->query("SELECT COUNT(*) FROM staff_leave_requests WHERE status = 'pending'")->fetchColumn();
        }
        $leave = [
            'balance'         => (float)$bal['balance'],
            'balance_label'   => staff_leave_days_phrase((float)$bal['balance']),
            'pending'         => (int)$pendingStmt->fetchColumn(),
            'pending_reviews' => $queue,
            'types'           => $typeList,
            'requests'        => $requests,
        ];
    } catch (Throwable $e) {
        $leave = null;
    }

    $duties = 0;
    try {
        require_once __DIR__ . '/duties.php';
        $duties = duty_pending_count($uid);
    } catch (Throwable $e) {
        $duties = 0;
    }

    $roster = [];
    $rosterStmt = db()->prepare('SELECT * FROM staff_attendance WHERE att_date = :d');
    $rosterStmt->execute([':d' => $today]);
    $byUser = [];
    foreach ($rosterStmt->fetchAll() as $r) $byUser[(int)$r['user_id']] = $r;
    foreach (staff_roster(true) as $person) {
        $pid = (int)$person['id'];
        $att = $byUser[$pid] ?? null;
        $st = $att ? (string)$att['status'] : null;
        $roster[] = [
            'id'           => $pid,
            'name'         => (string)$person['name'],
            'role_label'   => role_label((string)$person['role']),
            'status'       => $st,
            'status_label' => $st !== null ? ($statuses[$st] ?? $st) : 'Not in yet',
            'check_in'     => $att ? api_staff_hhmm(isset($att['check_in']) ? (string)$att['check_in'] : null) : null,
            'check_out'    => $att ? api_staff_hhmm(isset($att['check_out']) ? (string)$att['check_out'] : null) : null,
        ];
    }
    usort($roster, static function (array $a, array $b): int {
        $aWait = $a['check_in'] === null ? 0 : 1;
        $bWait = $b['check_in'] === null ? 0 : 1;
        if ($aWait !== $bWait) return $aWait <=> $bWait;
        return strcasecmp($a['name'], $b['name']);
    });

    $base = app_base_url();
    return [
        'date'       => $today,
        'date_label' => date('l, j M'),
        'is_admin'   => $isAdmin,
        'attendance' => [
            'status'       => $status,
            'status_label' => $status !== null ? ($statuses[$status] ?? $status) : 'Not checked in',
            'check_in'     => $row ? api_staff_hhmm(isset($row['check_in']) ? (string)$row['check_in'] : null) : null,
            'check_out'    => $row ? api_staff_hhmm(isset($row['check_out']) ? (string)$row['check_out'] : null) : null,
            'shift_label'  => staff_shift_label($shift),
            'late_after'   => $cutoff !== null ? staff_time_label($cutoff) : null,
        ],
        'leave'          => $leave,
        'duties_pending' => $duties,
        'roster'         => $roster,
        'links'          => [
            'staff'      => $base . '/staff/index.php',
            'attendance' => $base . '/staff/attendance.php',
            'leave'      => $base . '/staff/leave.php',
            'duties'     => $base . '/duties/index.php',
        ],
    ];
}

/** Same check-in / check-out rules as staff/attendance.php. */
function api_staff_check(array $user, string $op): void
{
    if (!staff_is_on_roster($user)) {
        api_error('Only staff can check in.', 403, 'forbidden');
    }
    if ($op !== 'self_in' && $op !== 'self_out') {
        api_error('Unknown check-in action.', 400, 'bad_request');
    }
    $today = date('Y-m-d');
    $uid = (int)$user['id'];
    $stmt = db()->prepare('SELECT id, check_in, status FROM staff_attendance WHERE user_id = :u AND att_date = :d');
    $stmt->execute([':u' => $uid, ':d' => $today]);
    $existing = $stmt->fetch();

    if ($op === 'self_in') {
        $now = date('H:i:s');
        $late = staff_arrival_status(staff_shift($uid)['start'], $now);
        if ($existing) {
            db()->prepare("
                UPDATE staff_attendance
                   SET check_in = COALESCE(check_in, :t), status = IF(status='absent', :st, status)
                 WHERE id = :id
            ")->execute([':t' => $now, ':st' => $late, ':id' => $existing['id']]);
        } else {
            db()->prepare("
                INSERT INTO staff_attendance (user_id, att_date, status, check_in, marked_by)
                VALUES (:u, :d, :st, :t, :by)
            ")->execute([':u' => $uid, ':d' => $today, ':st' => $late, ':t' => $now, ':by' => $uid]);
        }
        return;
    }

    $now = date('H:i:s');
    if (!$existing) api_error('Check in first before checking out.', 400, 'bad_request');
    db()->prepare('UPDATE staff_attendance SET check_out = :t WHERE id = :id')
        ->execute([':t' => $now, ':id' => $existing['id']]);
}

/**
 * Apply or cancel the signed-in person's own leave.
 * Returns a short confirmation and an optional heads-up (for example loss of pay).
 *
 * @return array{message: string, notice: string}
 */
function api_staff_leave(array $user, array $in): array
{
    require_once __DIR__ . '/notify.php';
    $op = (string)($in['op'] ?? '');
    if ($op === 'cancel') {
        $rid = (int)($in['id'] ?? 0);
        $stmt = db()->prepare('SELECT * FROM staff_leave_requests WHERE id = :id');
        $stmt->execute([':id' => $rid]);
        $r = $stmt->fetch();
        if (!$r || (int)$r['user_id'] !== (int)$user['id'] || $r['status'] !== 'pending') {
            api_error('That request can no longer be cancelled.', 400, 'bad_request');
        }
        db()->prepare("UPDATE staff_leave_requests SET status = 'cancelled' WHERE id = :id")->execute([':id' => $rid]);
        staff_leave_notify_cancelled($r, (string)$user['name']);
        return ['message' => 'Request cancelled.', 'notice' => ''];
    }
    if ($op !== 'apply') api_error('Unknown leave action.', 400, 'bad_request');

    $type = (string)($in['leave_type'] ?? 'casual');
    if (!array_key_exists($type, staff_leave_types())) $type = 'casual';
    $start = (string)($in['start_date'] ?? '');
    $end = (string)($in['end_date'] ?? $start);
    $reason = trim((string)($in['reason'] ?? ''));
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $start) || !preg_match('/^\d{4}-\d{2}-\d{2}$/', $end) || $end < $start) {
        api_error('Pick a valid date range.', 400, 'bad_request');
    }
    $half = (string)($in['half_day'] ?? '');
    if (!in_array($half, ['', 'first', 'second'], true)) $half = '';
    if ($start !== $end) $half = '';
    $days = staff_leave_days($start, $end, $half);
    if ($days <= 0) api_error('Pick a valid date range.', 400, 'bad_request');

    db()->prepare("
        INSERT INTO staff_leave_requests
            (user_id, leave_type, start_date, end_date, half_day, days_count, reason, status)
        VALUES (:u, :t, :s, :e, :h, :d, :r, 'pending')
    ")->execute([
        ':u' => (int)$user['id'],
        ':t' => $type,
        ':s' => $start,
        ':e' => $end,
        ':h' => $half,
        ':d' => $days,
        ':r' => $reason !== '' ? $reason : null,
    ]);
    $newId = (int)db()->lastInsertId();
    $bal = staff_leave_balance_at((int)$user['id']);
    $notice = '';
    if ($type === 'unpaid') {
        $notice = 'This is unpaid leave. It will be deducted as loss of pay if approved.';
    } elseif ($days > (float)$bal['balance']) {
        $short = round($days - max(0.0, (float)$bal['balance']), 2);
        $notice = 'Your balance is ' . staff_leave_days_phrase((float)$bal['balance'])
            . ', so ' . staff_leave_days_phrase($short) . ' would be loss of pay if approved.';
    }
    $q = db()->prepare('SELECT * FROM staff_leave_requests WHERE id = :i');
    $q->execute([':i' => $newId]);
    if ($row = $q->fetch()) staff_leave_notify_applied($row, (string)$user['name']);
    return [
        'message' => 'Leave request submitted (' . staff_leave_days_phrase($days) . ').',
        'notice'  => $notice,
    ];
}
