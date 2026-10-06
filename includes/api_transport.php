<?php
/**
 * includes/api_transport.php — JSON shapes for the app's Transport screens.
 */
declare(strict_types=1);

require_once __DIR__ . '/api.php';
require_once __DIR__ . '/transport.php';

function api_transport_trip_or_404(int $tripId): array
{
    $trip = transport_trip_get($tripId);
    if (!$trip) api_error('Trip not found.', 404, 'not_found');
    return $trip;
}

/** Everything the driver's trip screen needs, in visit order. */
function api_transport_trip_payload(array $trip): array
{
    $stops = transport_trip_stops((int)$trip['id']);
    $etas = transport_trip_etas($trip, $stops);
    $due = transport_due_eta_alerts($stops, $etas);
    $coords = [];
    foreach (transport_route_stops((int)$trip['route_id']) as $rs) {
        if ($rs['lat'] !== null) $coords[(int)$rs['student_id']] = ['lat' => (float)$rs['lat'], 'lng' => (float)$rs['lng']];
    }
    $outStops = [];
    foreach ($stops as $s) {
        $sid = (int)$s['id'];
        $e = $etas[$sid] ?? null;
        $outStops[] = [
            'id'               => $sid,
            'order'            => (int)$s['stop_order'],
            'student_id'       => (int)$s['student_id'],
            'name'             => trim($s['first_name'] . ' ' . $s['last_name']),
            'grade'            => (string)$s['grade'],
            'note'             => (string)($s['stop_note'] ?? ''),
            'location'         => $coords[(int)$s['student_id']] ?? null,
            'status'           => (string)$s['status'],
            'reached_at'       => $s['reached_at'] ? date('c', strtotime((string)$s['reached_at'])) : null,
            'done_at'          => $s['done_at'] ? date('c', strtotime((string)$s['done_at'])) : null,
            'eta_minutes'      => $e['minutes'] ?? null,
            'eta_alert_sent'   => !empty($s['eta_alert_at']),
            'reached_alert_sent' => !empty($s['reached_alert_at']),
            'eta_alert_due'    => in_array($sid, $due, true),
            'pin'              => preg_match('/^\d{4}$/', (string)($s['handover_pin'] ?? '')) ? (string)$s['handover_pin'] : null,
            'parents'          => array_map(static fn($p) => [
                'id'    => (int)$p['id'],
                'label' => (string)($p['relation'] ?: $p['name']),
                'name'  => (string)$p['name'],
            ], transport_parents_with_phone((int)$s['student_id'])),
        ];
    }
    $live = transport_trip_live_position($trip);
    $position = null;
    if ($live) {
        $position = [
            'lat'       => $live['lat'],
            'lng'       => $live['lng'],
            'at'        => date('c', $live['at']),
            'speed_kmh' => transport_latest_speed_kmh((int)$trip['id']),
        ];
    }
    return [
        'id'          => (int)$trip['id'],
        'route_id'    => (int)$trip['route_id'],
        'route_name'  => (string)$trip['route_name'],
        'direction'   => (string)$trip['direction'],
        'direction_label' => transport_direction_label((string)$trip['direction']),
        'run_no'      => (int)($trip['run_no'] ?? 1),
        'date'        => (string)$trip['trip_date'],
        'status'      => (string)$trip['status'],
        'status_label'=> transport_trip_status_label((string)$trip['status']),
        'cab'         => ['name' => (string)($trip['cab_name'] ?? ''), 'vehicle_no' => (string)($trip['vehicle_no'] ?? ''),
                          'driver_name' => (string)($trip['driver_name'] ?? '')],
        'started_at'  => $trip['started_at'] ? date('c', strtotime((string)$trip['started_at'])) : null,
        'last_location_at' => $live ? date('c', $live['at']) : null,
        'position'    => $position,
        'reached_radius_m' => transport_reached_radius_m(),
        'signature'   => transport_trip_signature($trip, $stops, $due),
        'stops'       => $outStops,
    ];
}
