<?php
/** GET ?date=&q= — children on routes, with this morning's status. Staff only. */
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
$roster = transport_roster($date, (string)($_GET['q'] ?? ''));
api_json(['ok' => true, 'date' => $date] + $roster);
