<?php
/** POST {trip_id?, note?} — tells the school desk, with a map link when the cab is live. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
api_require_module($user, 'transport');
$in = api_input();
$tripId = (int)($in['trip_id'] ?? 0);
try {
    $row = transport_send_sos((int)$user['id'], $tripId > 0 ? $tripId : null, (string)($in['note'] ?? ''));
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 404, 'not_found');
}
api_json(['ok' => true, 'message' => $row]);
