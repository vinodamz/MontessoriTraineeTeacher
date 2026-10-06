<?php
/** GET ?date=YYYY-MM-DD — that day's routes × directions with trip status. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');
try {
    $date = transport_valid_date($_GET['date'] ?? null, true);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}

$items = [];
foreach (transport_today_runs($date) as $row) {
    $r = $row['route'];
    $t = $row['trip'];
    $time = $row['direction'] === 'drop' ? $r['drop_time'] : $r['pickup_time'];
    $runNo = $t ? (int)($t['run_no'] ?? 1) : 1;
    $items[] = [
        'route_id'        => (int)$r['id'],
        'route_name'      => (string)$r['name'],
        'direction'       => $row['direction'],
        'direction_label' => transport_direction_label($row['direction']),
        'time'            => $time ? substr((string)$time, 0, 5) : null,
        'cab_name'        => (string)($r['cab_name'] ?? ''),
        'children'        => (int)$r['stop_count'],
        'trip_id'         => $t ? (int)$t['id'] : null,
        'run_no'          => $runNo,
        'status'          => $t ? (string)$t['status'] : 'scheduled',
        'status_label'    => transport_trip_status_label($t ? (string)$t['status'] : 'scheduled'),
        'finished'        => $t ? (int)$t['finished'] : 0,
        'total'           => $t ? (int)$t['total'] : (int)$r['stop_count'],
        'can_start_another' => (bool)$row['can_start_another'],
    ];
}
api_json(['ok' => true, 'date' => $date, 'trips' => $items]);
