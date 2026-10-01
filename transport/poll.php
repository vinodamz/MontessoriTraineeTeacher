<?php
/**
 * transport/poll.php — JSON for the trip screen's 30-second check. The page
 * reloads when the signature changes (another operator marked a stop, or a
 * 5-minute alert became due).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

transport_require();
header('Content-Type: application/json');
header('Cache-Control: no-store');

$trip = transport_trip_get((int)($_GET['id'] ?? 0));
if (!$trip) {
    http_response_code(404);
    echo json_encode(['error' => 'not_found']);
    exit;
}
$stops = transport_trip_stops((int)$trip['id']);
$due = transport_due_eta_alerts($stops, transport_trip_etas($trip, $stops));

echo json_encode([
    'status' => $trip['status'],
    'due'    => $due,
    'sig'    => transport_trip_signature($trip, $stops, $due),
]);
