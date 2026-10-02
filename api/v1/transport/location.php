<?php
/**
 * POST {trip_id, points: [{lat, lng, accuracy?, speed?, heading?, t (ms)}]}
 * — the driver's phone reports its position while a trip runs. When the trip
 * is no longer running the response says so and the app stops tracking.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
api_require_module(api_require_user(), 'transport');
$in = api_input();
try {
    $res = transport_record_locations((int)($in['trip_id'] ?? 0), (array)($in['points'] ?? []));
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 404, 'not_found');
}
$trip = api_transport_trip_or_404((int)($in['trip_id'] ?? 0));
$stops = transport_trip_stops((int)$trip['id']);
$due = transport_due_eta_alerts($stops, transport_trip_etas($trip, $stops));
api_json(['ok' => true, 'accepted' => $res['accepted'], 'reached' => $res['reached'], 'due' => $due,
          'status' => $trip['status'], 'signature' => transport_trip_signature($trip, $stops, $due)]);
