<?php
/** GET the transport thread. POST {body, kind?} — kind broadcast is admin-only. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
$user = api_require_user();
api_require_module($user, 'transport');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'GET') {
    try {
        api_json(['ok' => true, 'messages' => transport_messages()]);
    } catch (Throwable $e) {
        api_error('Messages are not ready yet. Run the database update.', 500, 'not_ready');
    }
}

api_require_method('POST');
$in = api_input();
$kind = (string)($in['kind'] ?? 'message');
if ($kind === 'broadcast' && ($user['role'] ?? '') !== 'admin') {
    api_error('Only an admin can broadcast.', 403, 'forbidden');
}
if (!in_array($kind, ['message', 'broadcast'], true)) api_error('Use a normal message or a broadcast.');
try {
    $row = transport_message_insert((int)$user['id'], $kind, (string)($in['body'] ?? ''), null);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
api_json(['ok' => true, 'message' => $row]);
