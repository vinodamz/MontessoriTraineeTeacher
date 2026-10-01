<?php
/**
 * transport/alert.php — logs that an operator opened a parent alert, then
 * hands off to WhatsApp with the parent's number and message pre-filled.
 * The app cannot see whether the message was actually sent.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$user = transport_require();

if (!hash_equals(csrf_token(), (string)($_GET['k'] ?? ''))) {
    http_response_code(400);
    exit('This alert link has expired. Go back and reload the trip.');
}
$kind = (string)($_GET['kind'] ?? '');
$stop = transport_trip_stop_get((int)($_GET['stop'] ?? 0));
if (!$stop || !in_array($kind, ['eta', 'reached'], true)) {
    http_response_code(404);
    exit('Stop not found.');
}
$trip = transport_trip_get((int)$stop['trip_id']);

$parent = null;
foreach (transport_parents_with_phone((int)$stop['student_id']) as $p) {
    if ((int)$p['id'] === (int)($_GET['parent'] ?? 0)) $parent = $p;
}
if (!$trip || !$parent) {
    http_response_code(404);
    exit('No phone number for that parent.');
}

$link = transport_parent_link_ensure((int)$stop['student_id'], (int)$user['id']);
$text = transport_alert_text($kind, (string)$trip['direction'], (string)$parent['name'],
    (string)$stop['first_name'], transport_track_url((string)$link['token']));

transport_record_alert((int)$stop['id'], $kind);
header('Location: ' . transport_wa_link((string)$parent['phone'], $text), true, 302);
exit;
