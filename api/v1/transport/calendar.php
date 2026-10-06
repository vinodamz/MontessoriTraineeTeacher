<?php
/** GET ?month=YYYY-MM — days in that month that already have a trip. */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('GET');
api_require_module(api_require_user(), 'transport');
$month = (string)($_GET['month'] ?? date('Y-m'));
try {
    $cal = transport_calendar_month($month);
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage());
}
api_json(['ok' => true] + $cal);
