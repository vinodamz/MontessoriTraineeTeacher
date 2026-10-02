<?php
/** GET ?id= — one trip with stops, ETAs and which alerts are due. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');
api_json(['ok' => true, 'trip' => api_transport_trip_payload(api_transport_trip_or_404((int)($_GET['id'] ?? 0)))]);
