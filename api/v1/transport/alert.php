<?php
/**
 * POST {stop_id, kind: eta|reached, parent_id} — logs the alert and returns
 * the wa.me link the app opens. The app cannot confirm the message was sent.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
api_require_module($user, 'transport');
$in = api_input();
$kind = (string)($in['kind'] ?? '');
$stop = transport_trip_stop_get((int)($in['stop_id'] ?? 0));
if (!$stop || !in_array($kind, ['eta', 'reached'], true)) api_error('Stop not found.', 404, 'not_found');
$trip = api_transport_trip_or_404((int)$stop['trip_id']);

$parent = null;
foreach (transport_parents_with_phone((int)$stop['student_id']) as $p) {
    if ((int)$p['id'] === (int)($in['parent_id'] ?? 0)) $parent = $p;
}
if (!$parent) api_error('No phone number for that parent.', 404, 'not_found');

$link = transport_parent_link_ensure((int)$stop['student_id'], $user['id']);
$text = transport_alert_text($kind, (string)$trip['direction'], (string)$parent['name'],
    (string)$stop['first_name'], transport_track_url((string)$link['token']));
transport_record_alert((int)$stop['id'], $kind);
api_json(['ok' => true, 'url' => transport_wa_link((string)$parent['phone'], $text), 'text' => $text]);
