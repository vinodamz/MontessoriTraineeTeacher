<?php
/** GET — everything the app's Today screen needs, for every role. */
declare(strict_types=1);

require_once __DIR__ . '/../../includes/api_today.php';

api_boot();
api_require_method('GET');
$user = api_require_user();

try {
    api_json(['ok' => true, 'today' => api_today($user)]);
} catch (Throwable $e) {
    api_error('Today is not available right now.', 500, 'server_error');
}
