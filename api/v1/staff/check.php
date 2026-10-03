<?php
/** POST {op: self_in|self_out} — stamp today's check-in or check-out. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_staff.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
api_require_module($user, 'staff');
$in = api_input();

try {
    api_staff_check($user, (string)($in['op'] ?? ''));
    api_json(['ok' => true, 'day' => api_staff_day($user)]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
        api_error($e->getMessage(), 400, 'bad_request');
    }
    api_error('Could not save attendance.', 500, 'server_error');
}
