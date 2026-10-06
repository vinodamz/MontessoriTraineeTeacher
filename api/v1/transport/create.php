<?php
/**
 * POST — start a trip.
 *   {route_id, direction, date?}              another run of a saved route
 *   {name, direction, student_ids[], date?}   a new route and that day's trip
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
api_require_module(api_require_user(), 'transport');
$in = api_input();
try {
    $date = transport_writable_date($in['date'] ?? null);
    if (trim((string)($in['name'] ?? '')) !== '') {
        $trip = transport_trip_custom((string)$in['name'], (string)($in['direction'] ?? ''), (array)($in['student_ids'] ?? []), $date);
    } else {
        $trip = transport_trip_begin((int)($in['route_id'] ?? 0), $date, (string)($in['direction'] ?? ''));
    }
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
api_json(['ok' => true, 'trip' => api_transport_trip_payload($trip)]);
