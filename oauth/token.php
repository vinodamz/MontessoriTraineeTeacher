<?php
/**
 * oauth/token.php — code → tokens, and refresh → new tokens.
 *
 *   grant_type=authorization_code   code, redirect_uri, code_verifier
 *   grant_type=refresh_token        refresh_token
 *
 * Client authentication is by client_secret_post, HTTP Basic, or nothing at
 * all for a public client — which is the normal case for a desktop MCP
 * client, where PKCE rather than a secret is what ties the exchange back to
 * the browser session that authorized it.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/mcp.php';
mcp_debug_watch('oauth: token exchange');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Pragma: no-cache');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Authorization, Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function token_fail(string $code, string $desc, int $http = 400): void
{
    http_response_code($http);
    if ($http === 401) header('WWW-Authenticate: Basic realm="oauth"');
    echo json_encode(['error' => $code, 'error_description' => $desc]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    token_fail('invalid_request', 'POST form-encoded parameters here.', 405);
}

// Normally form-encoded; accept JSON too, since some clients send it.
$in = $_POST;
if ($in === []) {
    $raw = (string)file_get_contents('php://input');
    if ($raw === '' && PHP_SAPI === 'cli') $raw = (string)file_get_contents('php://stdin');
    if ($raw !== '') {
        $j = json_decode($raw, true);
        if (is_array($j)) $in = $j;
        else parse_str($raw, $in);
    }
}

/** Client credentials may arrive in the body or as HTTP Basic. */
function token_client_credentials(array $in): array
{
    $id     = (string)($in['client_id'] ?? '');
    $secret = (string)($in['client_secret'] ?? '');

    $auth = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    if ($auth === '' && function_exists('apache_request_headers')) {
        foreach ((array)apache_request_headers() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) { $auth = (string)$v; break; }
        }
    }
    if (preg_match('/^\s*Basic\s+([A-Za-z0-9+\/=]+)\s*$/i', $auth, $m)) {
        $decoded = base64_decode($m[1], true);
        if ($decoded !== false && strpos($decoded, ':') !== false) {
            [$bid, $bsecret] = explode(':', $decoded, 2);
            if ($id === '')     $id     = urldecode($bid);
            if ($secret === '') $secret = urldecode($bsecret);
        }
    }
    return [$id, $secret];
}

[$clientId, $clientSecret] = token_client_credentials(is_array($in) ? $in : []);
$grant = (string)($in['grant_type'] ?? '');

try {
    $client = oauth_client($clientId);
} catch (PDOException $e) {
    token_fail('temporarily_unavailable', 'The database is not ready.', 503);
}
if (!$client) token_fail('invalid_client', 'Unknown client.', 401);

try {
    oauth_client_authenticate($client, $clientSecret !== '' ? $clientSecret : null);

    if ($grant === 'authorization_code') {
        $code     = (string)($in['code'] ?? '');
        $redirect = (string)($in['redirect_uri'] ?? '');
        $verifier = (string)($in['code_verifier'] ?? '');
        if ($code === '') throw new OAuthError('invalid_request', 'code is required.');

        $row = oauth_code_redeem($code, $clientId, $redirect, $verifier);
        $out = oauth_token_issue($clientId, (int)$row['user_id'], (string)$row['scope']);

    } elseif ($grant === 'refresh_token') {
        $refresh = (string)($in['refresh_token'] ?? '');
        if ($refresh === '') throw new OAuthError('invalid_request', 'refresh_token is required.');
        $out = oauth_refresh($refresh, $clientId);

    } else {
        throw new OAuthError('unsupported_grant_type',
            'Supported grants: authorization_code, refresh_token.');
    }
} catch (OAuthError $e) {
    token_fail($e->errorCode, $e->getMessage(), $e->getCode() ?: 400);
} catch (Throwable $e) {
    error_log('oauth token failed: ' . $e->getMessage());
    token_fail('server_error', 'Could not issue a token.', 500);
}

unset($out['_id']);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
