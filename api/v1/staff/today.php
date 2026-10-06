<?php
/** GET — the signed-in person's attendance, leave and today's roster. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_staff.php';

api_boot();
api_require_method('GET');
$user = api_require_user();
api_require_module($user, 'staff');

try {
    api_json(['ok' => true, 'day' => api_staff_day($user)]);
} catch (Throwable $e) {
    api_error('Staff records are not available right now.', 500, 'server_error');
}
