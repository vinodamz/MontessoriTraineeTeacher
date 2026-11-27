<?php
/** Explicit, session-authenticated handoff. Never accepts the app bearer token. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/app_workspace.php';
require_once __DIR__ . '/../includes/api_cors.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
header('X-Content-Type-Options: nosniff');
function external_response(array $data, int $status): never
{
    http_response_code($status);
    // session_start may set its own cache policy; always finalize ours last.
    header('Cache-Control: no-store');
    echo json_encode($data, JSON_HEX_TAG | JSON_HEX_AMP);
    exit;
}
function external_error(string $message, int $status): never
{
    external_response(['ok' => false, 'error' => $message], $status);
}
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    external_error('Use the Open in browser button.', 405);
}
$origin = (string)($_SERVER['HTTP_ORIGIN'] ?? '');
// The form POST originates in the school page itself (including local fixtures).
$requestOrigin = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off' ? 'https' : 'http')
    . '://' . (string)($_SERVER['HTTP_HOST'] ?? '');
if ($origin !== '' && $origin !== $requestOrigin && !api_allowed_browser_origin($origin)) {
    external_error('Open this integration from your school tool.', 403);
}
$user = current_user(); // Rechecks the API token, current role and module access.
if (!$user || empty($_SESSION['_app_workspace'])) {
    external_error('Your session ended. Close this tool and sign in again.', 401);
}
$csrf = $_POST['_csrf'] ?? null;
$expected = $_SESSION['_csrf'] ?? '';
if (!is_string($csrf) || !is_string($expected) || $expected === '' || !hash_equals($expected, $csrf)) {
    external_error('This page expired. Reopen the tool and try again.', 400);
}
$key = $_POST['app'] ?? null;
if (!is_string($key) || !in_array($key, ['wacrm', 'n8n'], true)) {
    external_error('This integration is not supported.', 400);
}
if (!user_has_module($user, $key)) {
    external_error('You do not have access to this integration.', 403);
}
$url = external_app_url($key);
if (!app_workspace_external_url_valid($url, $key === 'wacrm')) {
    external_error('This integration is not configured with a supported address. Ask an administrator to check it.', 409);
}
// Only this deliberate POST may mint SSO in school-app mode.
if ($key === 'wacrm') $url = wacrm_launch_url();
external_response(['ok' => true, 'url' => $url], 200);
