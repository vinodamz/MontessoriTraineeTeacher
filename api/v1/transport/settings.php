<?php
/** GET the transport desk settings. POST is admin-only. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
$user = api_require_user();
api_require_module($user, 'transport');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    api_json(['ok' => true, 'settings' => transport_desk_settings()]);
}

api_require_method('POST');
if (($user['role'] ?? '') !== 'admin') api_error('Only an admin can change these.', 403, 'forbidden');
try {
    $saved = transport_desk_settings_save(api_input());
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
api_json(['ok' => true, 'settings' => $saved]);
