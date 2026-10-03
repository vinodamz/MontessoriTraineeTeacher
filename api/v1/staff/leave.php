<?php
/** POST {op: apply|cancel, ...} — the signed-in person's own leave. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_staff.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
api_require_module($user, 'staff');

try {
    $result = api_staff_leave($user, api_input());
    api_json([
        'ok'      => true,
        'message' => $result['message'],
        'notice'  => $result['notice'],
        'day'     => api_staff_day($user),
    ]);
} catch (Throwable $e) {
    if ($e instanceof InvalidArgumentException || $e instanceof RuntimeException) {
        api_error($e->getMessage(), 400, 'bad_request');
    }
    api_error('Could not save that leave request.', 500, 'server_error');
}
