<?php
/** GET — today's routes × directions with trip status. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');

$items = [];
foreach (transport_board(date('Y-m-d')) as $row) {
    $r = $row['route'];
    $t = $row['trip'];
    $time = $row['direction'] === 'drop' ? $r['drop_time'] : $r['pickup_time'];
    $items[] = [
        'route_id'        => (int)$r['id'],
        'route_name'      => (string)$r['name'],
        'direction'       => $row['direction'],
        'direction_label' => transport_direction_label($row['direction']),
        'time'            => $time ? substr((string)$time, 0, 5) : null,
        'cab_name'        => (string)($r['cab_name'] ?? ''),
        'children'        => (int)$r['stop_count'],
        'trip_id'         => $t ? (int)$t['id'] : null,
        'status'          => $t ? (string)$t['status'] : 'scheduled',
        'status_label'    => transport_trip_status_label($t ? (string)$t['status'] : 'scheduled'),
        'finished'        => $t ? (int)$t['finished'] : 0,
        'total'           => $t ? (int)$t['total'] : (int)$r['stop_count'],
    ];
}
api_json(['ok' => true, 'date' => date('Y-m-d'), 'trips' => $items]);
