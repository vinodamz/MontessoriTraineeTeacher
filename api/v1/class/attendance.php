<?php
/**
 * POST {op: "rest_present"}                         — mark my unmarked children present
 * POST {op: "set", student_id, status, notes?}      — set one child's mark for today
 * Returns the refreshed Today payload.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_today.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
if (!class_attendance_can_open($user)) api_error('Class attendance is not turned on for this login.', 403, 'forbidden');
$in = api_input();
$date = date('Y-m-d');

try {
    $op = (string)($in['op'] ?? '');
    if ($op === 'rest_present') {
        class_attendance_rest_present($user, $date);
    } elseif ($op === 'set') {
        $mark = ['status' => (string)($in['status'] ?? '')];
        if (array_key_exists('notes', $in)) $mark['notes'] = (string)$in['notes'];
        class_attendance_save($user, $date, [(int)($in['student_id'] ?? 0) => $mark]);
    } else {
        throw new InvalidArgumentException('Unknown attendance action.');
    }
    api_json(['ok' => true, 'today' => api_today($user)]);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 400, 'bad_request');
} catch (Throwable $e) {
    api_error('Could not save attendance.', 500, 'server_error');
}
