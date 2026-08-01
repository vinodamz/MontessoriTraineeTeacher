<?php
/**
 * oauth/authorize.php — where a human actually decides.
 *
 * GET  → validate the request, make the visitor sign in if they haven't,
 *        then show a consent screen naming the client and what it can do.
 * POST → Allow issues an authorization code and redirects back to the
 *        client; Deny redirects with access_denied.
 *
 * ---------------------------------------------------------------------------
 * Where errors go, and why it matters
 * ---------------------------------------------------------------------------
 * Once client_id and redirect_uri are known-good, errors are returned *to the
 * client* via the redirect, as the spec requires. Before that point they are
 * rendered here as a page — because bouncing an error to an unvalidated
 * redirect URI is precisely how an open redirector gets built.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/oauth.php';

start_session_once();
oauth_gc();

/** A dead end the client never sees — rendered for the person at the browser. */
function authorize_page_error(string $title, string $detail): void
{
    http_response_code(400);
    header('Content-Type: text/html; charset=utf-8');
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>' . e($title) . '</title></head>'
       . '<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
       . 'background:#fff5fa;color:#2b2b2b;display:flex;min-height:100vh;align-items:center;justify-content:center;">'
       . '<div style="text-align:center;padding:2rem;max-width:460px;">'
       . '<div style="font-size:2.6rem;">🔒</div>'
       . '<h1 style="color:#ad1457;font-size:1.25rem;margin:.5rem 0;">' . e($title) . '</h1>'
       . '<p style="color:#666;font-size:.95rem;">' . e($detail) . '</p>'
       . '</div></body></html>';
    exit;
}

/** Hand an error back to the client, which is where the spec wants it. */
function authorize_redirect_error(string $redirectUri, string $code, string $desc, string $state): void
{
    $q = http_build_query(array_filter([
        'error'             => $code,
        'error_description' => $desc,
        'state'             => $state !== '' ? $state : null,
    ], fn($v) => $v !== null));
    $glue = strpos($redirectUri, '?') === false ? '?' : '&';
    header('Location: ' . $redirectUri . $glue . $q, true, 302);
    exit;
}

// -------------------------------------------------------- validate the ask
// GET carries the request; POST replays it through hidden fields so the
// decision applies to exactly what was shown on screen.
$src = $_SERVER['REQUEST_METHOD'] === 'POST' ? $_POST : $_GET;

$clientId    = trim((string)($src['client_id'] ?? ''));
$redirectUri = trim((string)($src['redirect_uri'] ?? ''));
$responseTy  = trim((string)($src['response_type'] ?? ''));
$challenge   = trim((string)($src['code_challenge'] ?? ''));
$chMethod    = trim((string)($src['code_challenge_method'] ?? ''));
$state       = (string)($src['state'] ?? '');
$scope       = trim((string)($src['scope'] ?? OAUTH_SCOPE));
$resource    = trim((string)($src['resource'] ?? ''));

try {
    $client = oauth_client($clientId);
} catch (PDOException $e) {
    authorize_page_error('Not set up yet',
        'The OAuth tables are not in place. Run the migrations and try again.');
}
if (!$client) {
    authorize_page_error('Unknown application',
        'This application is not registered with the school, or has been disabled.');
}

// The redirect URI must be one this client registered. Everything after this
// line may safely be returned through it; nothing before it may.
$matched = null;
foreach ($client['redirect_uris'] as $registered) {
    if (oauth_redirect_uri_matches((string)$registered, $redirectUri)) { $matched = $redirectUri; break; }
}
if ($matched === null) {
    authorize_page_error('Bad redirect address',
        'This application asked to be sent back to an address it never registered. '
      . 'Nothing has been shared. If you did not start this, you can close the page.');
}

if ($responseTy !== 'code') {
    authorize_redirect_error($matched, 'unsupported_response_type',
        'Only response_type=code is supported.', $state);
}
// PKCE is not optional. Without it a stolen authorization code is enough.
if ($chMethod !== 'S256') {
    authorize_redirect_error($matched, 'invalid_request',
        'code_challenge_method=S256 is required.', $state);
}
if (!preg_match('/^[A-Za-z0-9\-._~]{43,128}$/', $challenge)) {
    authorize_redirect_error($matched, 'invalid_request',
        'code_challenge is missing or malformed.', $state);
}
if ($scope !== '' && $scope !== OAUTH_SCOPE) {
    authorize_redirect_error($matched, 'invalid_scope',
        'The only scope this server issues is "' . OAUTH_SCOPE . '".', $state);
}

// ------------------------------------------------------------- who is this
$user = current_user();
if (!$user) {
    // Bounce through login and come straight back to this exact request.
    $self = '/oauth/authorize.php?' . http_build_query([
        'client_id'             => $clientId,
        'redirect_uri'          => $redirectUri,
        'response_type'         => 'code',
        'code_challenge'        => $challenge,
        'code_challenge_method' => 'S256',
        'state'                 => $state,
        'scope'                 => $scope,
        'resource'              => $resource,
    ]);
    redirect('/login.php?next=' . rawurlencode($self));
}

// Admins only, by decision: the tools have full read/write, so anyone who
// connects a client can reach everything. Told to the person, not the client,
// because it is a fact about them rather than about the request.
if (($user['role'] ?? '') !== 'admin') {
    authorize_page_error('Admins only',
        'Connecting an assistant to the school\'s data is limited to admin accounts. '
      . 'You are signed in as ' . (string)$user['name'] . '.');
}

// ------------------------------------------------------------ the decision
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();

    if ((string)($_POST['decision'] ?? '') !== 'allow') {
        authorize_redirect_error($matched, 'access_denied',
            'The request was declined.', $state);
    }

    try {
        $code = oauth_code_issue($clientId, (int)$user['id'], $redirectUri, $challenge,
                                 $scope !== '' ? $scope : OAUTH_SCOPE,
                                 $resource !== '' ? $resource : null);
        db()->prepare("UPDATE oauth_clients SET last_used_at = NOW() WHERE client_id = :c")
            ->execute([':c' => $clientId]);
    } catch (Throwable $e) {
        error_log('oauth authorize failed: ' . $e->getMessage());
        authorize_redirect_error($matched, 'server_error', 'Could not issue a code.', $state);
    }

    $q = http_build_query(array_filter([
        'code'  => $code,
        'state' => $state !== '' ? $state : null,
    ], fn($v) => $v !== null));
    $glue = strpos($matched, '?') === false ? '?' : '&';
    header('Location: ' . $matched . $glue . $q, true, 302);
    exit;
}

// --------------------------------------------------------- the consent page
$clientName = (string)$client['client_name'];
$host       = (string)(parse_url($redirectUri, PHP_URL_HOST) ?: 'this device');

$pageTitle = 'Connect an assistant';
require __DIR__ . '/../includes/header.php';
?>

<div class="card" style="max-width:34rem;margin:2rem auto;">
    <h1 style="margin-top:0;font-size:1.3rem;">Connect <?= e($clientName) ?>?</h1>

    <p>
        <strong><?= e($clientName) ?></strong> is asking to use
        <strong><?= e(app_name()) ?></strong> as
        <strong><?= e((string)$user['name']) ?></strong>.
    </p>

    <div class="card" style="background:#fff5fa;margin:1rem 0;">
        <p class="small" style="margin:0 0 .5rem;"><strong>If you allow this, it can:</strong></p>
        <ul class="small" style="margin:0;padding-left:1.1rem;">
            <li><strong>Read everything</strong> — students, attendance, fees, parent surveys,
                CRM leads, staff records.</li>
            <li><strong>Change and delete records.</strong> Bulk changes are capped and every
                write keeps the previous values, so a mistake can be undone.</li>
            <li>It <strong>cannot</strong> read or set anyone's PIN, and it cannot erase the
                activity log.</li>
        </ul>
    </div>

    <p class="muted small">
        Everything it does is recorded against your name at
        <a href="/mcp_admin.php">MCP API</a>, and you can disconnect it there at any time.
        Only allow this if you started it yourself just now.
    </p>

    <p class="muted small">Returning to <code><?= e($host) ?></code></p>

    <form method="post" style="display:flex;gap:.6rem;margin-top:1.2rem;">
        <input type="hidden" name="_csrf"                 value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="client_id"             value="<?= e($clientId) ?>">
        <input type="hidden" name="redirect_uri"          value="<?= e($redirectUri) ?>">
        <input type="hidden" name="response_type"         value="code">
        <input type="hidden" name="code_challenge"        value="<?= e($challenge) ?>">
        <input type="hidden" name="code_challenge_method" value="S256">
        <input type="hidden" name="state"                 value="<?= e($state) ?>">
        <input type="hidden" name="scope"                 value="<?= e($scope) ?>">
        <input type="hidden" name="resource"              value="<?= e($resource) ?>">
        <button class="btn btn-primary" type="submit" name="decision" value="allow">Allow</button>
        <button class="btn btn-ghost"   type="submit" name="decision" value="deny">Cancel</button>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
