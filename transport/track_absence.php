<?php
/**
 * transport/track_absence.php — PUBLIC. A parent says their child is not
 * riding. The token in the form is the only credential. Redirects back to
 * the private page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

date_default_timezone_set(app_config()['app']['timezone'] ?? 'UTC');

if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}

$token = (string)($_POST['t'] ?? '');
$studentId = transport_tables_ready() ? transport_student_for_token($token) : null;
$back = '/transport/track.php?t=' . rawurlencode($token);
if ($studentId === null) {
    header('Location: ' . $back);
    exit;
}
try {
    transport_parent_set_absence($studentId, (string)($_POST['scope'] ?? ''), trim((string)($_POST['reason'] ?? '')));
} catch (InvalidArgumentException $e) {
    header('Location: ' . $back);
    exit;
}
header('Location: ' . $back);
exit;
