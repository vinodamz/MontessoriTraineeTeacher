<?php
/** GET ?date= — today's vehicles, children aboard, on-time share, alerts and live cabs. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');
try {
    $date = transport_valid_date($_GET['date'] ?? null, true);
    api_json(['ok' => true] + transport_overview($date));
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
