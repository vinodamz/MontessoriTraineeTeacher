<?php
/**
 * oauth/register.php — dynamic client registration (RFC 7591).
 *
 * An MCP client the school has never seen before POSTs its own metadata here
 * and receives a client_id. This has to be open: there is no way to pre-share
 * an id with software that has not been installed yet, and every MCP client
 * expects to register itself.
 *
 * Open registration is safe here because a client_id alone grants nothing.
 * The only thing it can do is *ask* for authorization — and that still ends
 * at a consent screen where a human has to sign in with their PIN. What the
 * registration must not be allowed to do is nominate a redirect URI that
 * sends the authorization code somewhere hostile, which is why the URIs are
 * validated (https, loopback, or a reverse-DNS private scheme) rather than
 * taken on trust.
 *
 * Rate-limited by IP so it cannot be used to fill the table.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/oauth.php';
require_once __DIR__ . '/../includes/mcp.php';
mcp_debug_watch('oauth: dynamic client registration');

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Headers: Content-Type');
header('Access-Control-Allow-Methods: POST, OPTIONS');

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') { http_response_code(204); exit; }

function reg_fail(string $code, string $desc, int $http = 400): void
{
    http_response_code($http);
    echo json_encode(['error' => $code, 'error_description' => $desc]);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    header('Allow: POST');
    reg_fail('invalid_request', 'POST a client metadata document here.', 405);
}

/*
 * The school can close this door (MCP API → Registered applications).
 *
 * With it shut, only applications an admin created by hand may sign in, and
 * the client_id and secret are handed over out of band. The wording matters:
 * a client that is refused here shows it to the person setting it up, and
 * "ask the school for a client ID" is the only useful thing they can be told.
 */
try {
    if (!oauth_open_registration()) {
        reg_fail('access_denied',
            'This server does not accept self-registration. Ask the school administrator '
          . 'for a client ID and secret, and enter them in your client\'s settings.', 403);
    }
} catch (PDOException $e) {
    reg_fail('temporarily_unavailable', 'Registration is not available — the database is not ready.', 503);
}

/*
 * Flood guard, per caller.
 *
 * The first version of this computed an IP hash, discarded it, and counted
 * every registration from everyone — a global cap wearing a per-IP comment.
 * One client retrying could lock out every other client, itself included,
 * for an hour, and all a user sees is "couldn't register with the sign-in
 * service". Clients legitimately re-register on restart and on any failure
 * further down the flow, so retries are normal traffic, not abuse.
 *
 * Per-IP is generous, because being wrong in that direction costs a few
 * unused rows. The global ceiling behind it exists only to stop a
 * distributed flood filling the table, and is set far above anything one
 * honest client could reach.
 */
const REG_PER_IP_PER_HOUR = 40;
const REG_TOTAL_PER_HOUR  = 500;

try {
    $ipHash = hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? ''));

    $mine = db()->prepare(
        "SELECT COUNT(*) FROM oauth_clients
          WHERE ip_hash = :ip AND created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    );
    $mine->execute([':ip' => $ipHash]);
    if ((int)$mine->fetchColumn() > REG_PER_IP_PER_HOUR) {
        reg_fail('temporarily_unavailable',
            'This client has registered too many times in the last hour. Wait a few minutes and try again.', 429);
    }

    $all = (int)db()->query(
        "SELECT COUNT(*) FROM oauth_clients WHERE created_at > DATE_SUB(NOW(), INTERVAL 1 HOUR)"
    )->fetchColumn();
    if ($all > REG_TOTAL_PER_HOUR) {
        reg_fail('temporarily_unavailable', 'Too many registrations right now. Try again shortly.', 429);
    }
} catch (PDOException $e) {
    reg_fail('temporarily_unavailable', 'Registration is not available — the database is not ready.', 503);
}

$raw = (string)file_get_contents('php://input');
if ($raw === '' && PHP_SAPI === 'cli') $raw = (string)file_get_contents('php://stdin');
$in = json_decode($raw, true);
if (!is_array($in)) $in = $_POST;
if (!is_array($in) || $in === []) {
    reg_fail('invalid_client_metadata', 'Body must be a JSON client metadata object.');
}

$name = trim((string)($in['client_name'] ?? 'Unnamed MCP client'));
$uris = $in['redirect_uris'] ?? [];
if (is_string($uris)) $uris = [$uris];
if (!is_array($uris)) {
    reg_fail('invalid_redirect_uri', 'redirect_uris must be an array.');
}

// "none" means a public client relying on PKCE — the normal case for a
// desktop MCP client, which cannot keep a secret on the user's machine.
$authMethod   = (string)($in['token_endpoint_auth_method'] ?? 'none');
$confidential = $authMethod !== 'none';

$grants = $in['grant_types'] ?? ['authorization_code', 'refresh_token'];
if (!is_array($grants)) $grants = [$grants];
foreach ($grants as $g) {
    if (!in_array($g, ['authorization_code', 'refresh_token'], true)) {
        reg_fail('invalid_client_metadata', "Unsupported grant_type: $g");
    }
}

try {
    $c = oauth_client_register($name, $uris, $confidential, $ipHash);
} catch (OAuthError $e) {
    reg_fail($e->errorCode, $e->getMessage());
} catch (Throwable $e) {
    error_log('oauth register failed: ' . $e->getMessage());
    reg_fail('server_error', 'Could not register the client.', 500);
}

$out = [
    'client_id'                  => $c['client_id'],
    'client_id_issued_at'        => time(),
    'client_name'                => $name,
    'redirect_uris'              => $c['redirect_uris'],
    'grant_types'                => ['authorization_code', 'refresh_token'],
    'response_types'             => ['code'],
    'token_endpoint_auth_method' => $confidential ? 'client_secret_post' : 'none',
];
if ($c['client_secret'] !== null) {
    $out['client_secret'] = $c['client_secret'];
    $out['client_secret_expires_at'] = 0;      // 0 = does not expire
}

http_response_code(201);
echo json_encode($out, JSON_UNESCAPED_SLASHES);
