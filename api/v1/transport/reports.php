<?php
/** GET ?date= — this week's trips, pickups and absences. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');
try {
    $date = transport_valid_date($_GET['date'] ?? null, true);
    api_json(['ok' => true] + transport_reports($date));
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
