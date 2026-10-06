<?php
/** GET — active routes a driver can run or switch to. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');

$routes = [];
foreach (transport_routes(true) as $r) {
    $routes[] = [
        'id'         => (int)$r['id'],
        'name'       => (string)$r['name'],
        'direction'  => (string)$r['direction'],
        'directions' => transport_route_directions($r),
        'cab_name'   => (string)($r['cab_name'] ?? ''),
        'children'   => (int)$r['stop_count'],
        'pickup_time'=> $r['pickup_time'] ? substr((string)$r['pickup_time'], 0, 5) : null,
        'drop_time'  => $r['drop_time'] ? substr((string)$r['drop_time'], 0, 5) : null,
    ];
}
api_json(['ok' => true, 'routes' => $routes]);
