<?php
/** POST-only bearer-to-session bridge. Credentials never appear in URLs. */
declare(strict_types=1);
require_once __DIR__ . '/../includes/api.php';
require_once __DIR__ . '/../includes/app_workspace.php';

api_handle_cors();
header('Cache-Control: no-store');
header('Referrer-Policy: no-referrer');
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    header('Allow: POST');
    app_workspace_error('Open this tool from the Little Graduates app.', 405);
}
$path = app_workspace_path((string)($_POST['path'] ?? ''));
if ($path === null) app_workspace_error('This school link is not supported.', 400);
$token = (string)($_POST['token'] ?? '');
if (!preg_match('/^[a-f0-9]{64}$/D', $token)) app_workspace_error('Sign in to the app again.', 401);
$user = api_user_for_token($token);
if (!$user) app_workspace_error('Sign in to the app again.', 401);

start_session_once();
// New CSRF state prevents an older tab submitting as a newly selected user.
$_SESSION = [
    'user_id' => $user['id'], 'user_name' => $user['name'],
    'user_role' => $user['role'], 'user_modules' => $user['modules'],
    '_app_workspace' => true, '_app_token_id' => $user['token_id'],
];
session_regenerate_id(true);
// The destination still applies its own module, row access and CSRF checks.
header('Location: ' . $path, true, 303);
exit;
