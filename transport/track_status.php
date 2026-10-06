<?php
/**
 * transport/track_status.php?t=… — PUBLIC JSON for the parent page's live
 * refresh. Same data and same rules as track.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');
header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

$studentId = transport_tables_ready() ? transport_student_for_token((string)($_GET['t'] ?? '')) : null;
if ($studentId === null) {
    http_response_code(404);
    echo json_encode(['ok' => false]);
    exit;
}
$s = transport_parent_status($studentId);
echo json_encode([
    'ok'          => true,
    'state'       => $s['state'],
    'phrase'      => $s['phrase'],
    'eta'         => $s['minutes'] !== null ? transport_eta_phrase((int)$s['minutes']) : null,
    'stops_ahead' => $s['stops_ahead'],
    'speed_kmh'   => $s['speed_kmh'] ?? null,
    'seat'        => $s['seat'] ?? null,
    'cab'         => $s['cab'],
    'home'        => $s['home'],
    'updated'     => date('g:i a'),
], JSON_UNESCAPED_UNICODE);
