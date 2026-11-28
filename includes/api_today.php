<?php
/**
 * includes/api_today.php — the app's Today screen in one call: my class,
 * duties, assessments, and (for admins) who is in and what needs approval.
 * Uses the same helpers as My Day (today.php), so app and website agree.
 */
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/class_attendance.php';

function api_today_class(array $user, string $date): ?array
{
    if (!class_attendance_can_open($user)) return null;
    $rows = class_attendance_my_class((int)$user['id'], $date);
    $students = [];
    foreach ($rows as $r) {
        $students[] = [
            'id'     => (int)$r['id'],
            'name'   => trim($r['first_name'] . ' ' . $r['last_name']),
            'grade'  => (string)$r['grade'],
            'status' => ($r['status'] ?? '') !== '' ? (string)$r['status'] : null,
            'notes'  => ($r['notes'] ?? '') !== '' ? (string)$r['notes'] : null,
        ];
    }
    return [
        'counts'       => class_attendance_counts($rows),
        'students'     => $students,
        'statuses'     => CLASS_ATTENDANCE_STATUSES,
        // No class of their own, but may cover one (a subteacher). Admins have the full tools.
        'can_cover'    => !$rows && class_attendance_can_mark_any($user) && ($user['role'] ?? '') !== 'admin',
    ];
}

function api_today(array $user): array
{
    $date = date('Y-m-d');
    $base = app_base_url();
    $isAdmin = ($user['role'] ?? '') === 'admin';

    $duties = 0;
    try {
        require_once __DIR__ . '/duties.php';
        $duties = duty_pending_count((int)$user['id']);
    } catch (Throwable $e) {
        $duties = 0;
    }

    $admin = null;
    if ($isAdmin) {
        try {
            require_once __DIR__ . '/staff.php';
            $total = count(staff_roster(true));
            $in = db()->prepare("SELECT COUNT(DISTINCT sa.user_id) FROM staff_attendance sa
                                   JOIN users u ON u.id = sa.user_id AND u.active = 1
                                  WHERE sa.att_date = :d AND sa.check_in IS NOT NULL");
            $in->execute([':d' => $date]);
            $leave = (int)db()->query("SELECT COUNT(*) FROM staff_leave_requests WHERE status = 'pending'")->fetchColumn();
            $admin = ['staff_total' => $total, 'staff_in' => (int)$in->fetchColumn(), 'leave_to_review' => $leave];
        } catch (Throwable $e) {
            $admin = null;
        }
    }

    $class = api_today_class($user, $date);
    return [
        'date'                => $date,
        'class'               => $class,
        'duties_pending'      => $duties,
        'assessments_pending' => $class !== null ? class_attendance_pending_assessments($user) : 0,
        'admin'               => $admin,
        'links'               => [
            'my_day'       => $base . '/today.php',
            'attendance'   => $base . '/students/attendance.php',
            'duties'       => $base . '/duties/index.php',
            'assessment'   => $base . '/assessment/index.php',
            'leave_review' => $base . '/staff/leave.php',
            'staff_today'  => $base . '/staff/attendance.php',
        ],
    ];
}
