<?php
/**
 * POST {trip_id, op, stop_id?, route_id?, student_id?}
 * op: start | finish | cancel | reached | done | absent | undo |
 *     set_route | add | remove | up | down | set_location.
 * set_location needs stop_id, lat and lng, and the stop must already be reached.
 * Returns the updated trip.
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
    } elseif ($op === 'set_route') {
        transport_trip_set_route((int)$trip['id'], (int)($in['route_id'] ?? 0));
    } elseif ($op === 'add') {
        transport_trip_add_student((int)$trip['id'], (int)($in['student_id'] ?? 0));
    } elseif (in_array($op, ['remove', 'up', 'down'], true)) {
        $stop = transport_trip_stop_get((int)($in['stop_id'] ?? 0));
        if (!$stop || (int)$stop['trip_id'] !== (int)$trip['id']) api_error('Stop not found.', 404, 'not_found');
        if ($op === 'remove') transport_trip_remove_stop((int)$stop['id']);
        else transport_trip_move_stop((int)$stop['id'], $op);
    } elseif ($op === 'set_location') {
        $stop = transport_trip_stop_get((int)($in['stop_id'] ?? 0));
        if (!$stop || (int)$stop['trip_id'] !== (int)$trip['id']) api_error('Stop not found.', 404, 'not_found');
        transport_save_reached_location((int)$stop['id'], (float)($in['lat'] ?? 0), (float)($in['lng'] ?? 0));
    } else {
        api_error('Unknown action.');
    }
} catch (InvalidArgumentException $e) {
    api_error($e->getMessage(), 409, 'conflict');
}
api_json(['ok' => true, 'trip' => api_transport_trip_payload(api_transport_trip_or_404((int)$trip['id']))]);
