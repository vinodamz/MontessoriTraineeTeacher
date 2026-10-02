<?php
/**
 * POST {trip_id, op, stop_id?} — op: start | finish | cancel | reached |
 * done | absent | undo. Returns the updated trip.
 */
declare(strict_types=1);

require_once __DIR__ . '/../../../includes/api_transport.php';

api_boot();
api_require_method('POST');
$user = api_require_user();
api_require_module($user, 'transport');
$in = api_input();
$trip = api_transport_trip_or_404((int)($in['trip_id'] ?? 0));
$op = (string)($in['op'] ?? '');
try {
    if ($op === 'start') {
        transport_trip_start((int)$trip['id'], $user['id']);
    } elseif ($op === 'finish') {
        transport_trip_finish((int)$trip['id']);
    } elseif ($op === 'cancel') {
        transport_trip_cancel((int)$trip['id']);
    } elseif (in_array($op, ['reached', 'done', 'absent', 'undo'], true)) {
        $stop = transport_trip_stop_get((int)($in['stop_id'] ?? 0));
        if (!$stop || (int)$stop['trip_id'] !== (int)$trip['id']) api_error('Stop not found.', 404, 'not_found');
        transport_mark_stop((int)$stop['id'], $op, $user['id']);
    } else {
        api_error('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 409, 'conflict');
}
api_json(['ok' => true, 'trip' => api_transport_trip_payload(api_transport_trip_or_404((int)$trip['id']))]);
