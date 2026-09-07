<?php
/**
 * plans/media.php — auth-gated streamer for plan_media (owner or admin).
 *
 * Failures return 404 (not 403) to avoid existence leaks.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/plans.php';

$id = (int)($_GET['id'] ?? 0);
$row = plan_media_get($id);
if (!$row) { http_response_code(404); exit('Not found.'); }

$plan = plan_get((int)$row['plan_id']);
$u = current_user();
if ($plan === null || $u === null || !plan_can_view($u, $plan)) {
    http_response_code(404);
    exit('Not found.');
}

$path = plan_media_dir() . '/' . basename((string)$row['stored_filename']);
if (!is_file($path)) { http_response_code(404); exit('File missing.'); }

header('Content-Type: ' . (string)$row['mime_type']);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$row['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
