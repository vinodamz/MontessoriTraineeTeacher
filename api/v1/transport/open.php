<?php
/** POST {route_id, direction, date?} — find or create that day's trip, return it. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
api_require_module(api_require_user(), 'transport');
$in = api_input();
try {
    $trip = transport_trip_ensure((int)($in['route_id'] ?? 0), transport_writable_date($in['date'] ?? null), (string)($in['direction'] ?? ''));
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
api_json(['ok' => true, 'trip' => api_transport_trip_payload($trip)]);
