<?php
/**
 * includes/oauth.php — OAuth 2.1 authorization server for the MCP endpoint.
 *
 * Implements what MCP clients actually need:
 *
 *   RFC 7591  dynamic client registration — clients arrive unannounced, so
 *             there is nothing to pre-share
 *   RFC 7636  PKCE, S256 only — the whole security of a public client
 *   RFC 8414  authorization server metadata
 *   RFC 9728  protected resource metadata
 *   RFC 8707  resource indicators
 *   RFC 8252  loopback redirects for native apps
 *
 * ---------------------------------------------------------------------------
 * Why this exists alongside the bearer tokens
 * ---------------------------------------------------------------------------
 * A bearer token is a long-lived string in a config file. It can be pasted
 * into a chat window, it never expires, and the audit log can only name the
 * label somebody typed when they minted it. An OAuth access token lives an
 * hour, the refresh token sits in the client's keychain and never passes
 * through a human's hands, and every call is attributable to a real member of
 * staff.
 *
 * Bearer tokens stay supported: a cron job cannot open a browser.
 *
 * ---------------------------------------------------------------------------
 * Secrets
 * ---------------------------------------------------------------------------
 * Authorization codes, access tokens, refresh tokens and client secrets are
 * all stored as SHA-256 and never in the clear. Lookups go by hash so they
 * stay indexed, and hash_equals still guards the comparison — the index
 * narrows, it does not authorise.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';
require_once __DIR__ . '/functions.php';

/** Only admins may connect a client. Matches what the tools can reach. */
const OAUTH_SCOPE = 'mcp';

const OAUTH_CODE_TTL     = 60;          // seconds — a redirect hop, nothing more
const OAUTH_ACCESS_TTL   = 3600;        // 1 hour
const OAUTH_REFRESH_TTL  = 2592000;     // 30 days

/*
 * How long a just-rotated refresh token stays usable.
 *
 * Rotation plus reuse-detection is the right design, but taken literally it
 * breaks every honest client that has more than one request in flight. When
 * the access token expires, several queued requests all get a 401 at once,
 * all reach for the same refresh token, and all send it. One wins. The others
 * look exactly like a stolen token being replayed — so the chain gets revoked,
 * including the token the winner just handed back. The connection dies about
 * an hour after it was made, every time, and the client can only say
 * "connection issue".
 *
 * Inside this window a second presentation of a token we ourselves rotated is
 * treated as the race it almost certainly is, and answered with a fresh pair.
 * Outside it — or if the successor has since been revoked — it is still
 * treated as a breach and the chain still goes down. A thief would have to be
 * using the token within seconds of the real client to benefit, which is a far
 * better trade than disconnecting everyone hourly.
 */
const OAUTH_REFRESH_GRACE = 30;         // seconds

/** An OAuth failure that must be rendered as a spec-shaped error. */
class OAuthError extends Exception
{
    public string $errorCode;
    public function __construct(string $errorCode, string $description, int $http = 400)
    {
        $this->errorCode = $errorCode;
        parent::__construct($description, $http);
    }
}

// ------------------------------------------------------------------- helpers

/**
 * The public origin of this install, e.g. https://mtt.thelittlegraduates.in
 *
 * Delegates to app_base_url(), which honours the site_base_url setting and
 * looks past $_SERVER['HTTPS'] at the proxy headers. Advertising http:// here
 * breaks registration outright: the client POSTs to it, the Force-HTTPS
 * redirect 301s, and the body is dropped.
 */
function oauth_base_url(): string
{
    return app_base_url();
}

function oauth_secret(int $bytes = 32): string
{
    return bin2hex(random_bytes($bytes));
}

function oauth_hash(string $s): string
{
    return hash('sha256', $s);
}

/** base64url, no padding — what PKCE and JSON metadata expect. */
function oauth_b64url(string $raw): string
{
    return rtrim(strtr(base64_encode($raw), '+/', '-_'), '=');
}

// ------------------------------------------------------------------- clients

/**
 * Is this redirect URI one a native MCP client may legitimately use?
 *
 * RFC 8252: a desktop app listens on an ephemeral loopback port, so the port
 * cannot be known at registration time and must not be matched on. Everything
 * else must be https, because an authorization code travelling over plain http
 * to a remote host is a code in the clear.
 */
function oauth_redirect_uri_allowed(string $uri): bool
{
    $p = parse_url($uri);
    if (!is_array($p) || empty($p['scheme']) || !isset($p['host'])) return false;
    if (!empty($p['fragment'])) return false;              // fragments are never allowed

    $scheme = strtolower($p['scheme']);
    $host   = strtolower($p['host']);

    if ($scheme === 'http') {
        // Loopback only — and only by literal address, never by a name that
        // could be pointed elsewhere by DNS.
        return $host === '127.0.0.1' || $host === '[::1]' || $host === '::1';
    }
    if ($scheme === 'https') return true;

    // A private-use scheme (com.example.app:/cb) is how mobile clients come
    // back. Require a dot so it is a reverse-DNS name, not "javascript".
    return (bool)preg_match('/^[a-z][a-z0-9+.-]*$/', $scheme) && strpos($scheme, '.') !== false;
}

/**
 * Compare a presented redirect URI against a registered one.
 *
 * Exact string match, except that a loopback address ignores the port — the
 * client picks a free one at runtime and cannot have registered it.
 */
function oauth_redirect_uri_matches(string $registered, string $presented): bool
{
    if (hash_equals($registered, $presented)) return true;

    $a = parse_url($registered);
    $b = parse_url($presented);
    if (!is_array($a) || !is_array($b)) return false;

    $loopback = fn($h) => in_array(strtolower((string)$h), ['127.0.0.1', '::1', '[::1]'], true);
    if (strtolower((string)($a['scheme'] ?? '')) !== 'http'
        || strtolower((string)($b['scheme'] ?? '')) !== 'http') return false;
    if (!$loopback($a['host'] ?? '') || !$loopback($b['host'] ?? '')) return false;

    return ($a['path'] ?? '/') === ($b['path'] ?? '/');
}

/**
 * Register a client. Returns the row plus, for a confidential client, the
 * one-and-only plaintext secret.
 */
function oauth_client_register(string $name, array $redirectUris, bool $confidential,
                               ?string $ipHash = null): array
{
    $clean = [];
    foreach ($redirectUris as $u) {
        $u = trim((string)$u);
        if ($u === '') continue;
        if (!oauth_redirect_uri_allowed($u)) {
            throw new OAuthError('invalid_redirect_uri', "Redirect URI not allowed: $u");
        }
        $clean[] = $u;
    }
    if ($clean === []) {
        throw new OAuthError('invalid_redirect_uri', 'At least one redirect_uri is required.');
    }
    if (count($clean) > 10) {
        throw new OAuthError('invalid_client_metadata', 'Too many redirect URIs.');
    }

    $clientId = bin2hex(random_bytes(16));
    $secret   = $confidential ? oauth_secret() : null;

    db()->prepare(
        "INSERT INTO oauth_clients (client_id, secret_hash, client_name, redirect_uris, ip_hash)
         VALUES (:cid, :sh, :n, :r, :ip)"
    )->execute([
        ':cid' => $clientId,
        ':sh'  => $secret === null ? null : oauth_hash($secret),
        ':n'   => mb_substr($name, 0, 120),
        ':r'   => json_encode($clean, JSON_UNESCAPED_SLASHES),
        ':ip'  => $ipHash,
    ]);

    return ['client_id' => $clientId, 'client_secret' => $secret, 'redirect_uris' => $clean];
}

function oauth_client(string $clientId): ?array
{
    if (!preg_match('/^[0-9a-f]{32}$/', $clientId)) return null;
    $s = db()->prepare("SELECT * FROM oauth_clients WHERE client_id = :c AND disabled_at IS NULL");
    $s->execute([':c' => $clientId]);
    $row = $s->fetch();
    if (!$row) return null;
    $row['redirect_uris'] = json_decode((string)$row['redirect_uris'], true) ?: [];
    return $row;
}

/**
 * Authenticate the client at the token endpoint.
 *
 * A public client proves nothing here — PKCE is what protects it. A
 * confidential client must present the secret it was issued.
 */
function oauth_client_authenticate(array $client, ?string $presentedSecret): void
{
    if ($client['secret_hash'] === null) return;                 // public client
    if ($presentedSecret === null || $presentedSecret === '') {
        throw new OAuthError('invalid_client', 'This client must authenticate.', 401);
    }
    if (!hash_equals((string)$client['secret_hash'], oauth_hash($presentedSecret))) {
        throw new OAuthError('invalid_client', 'Bad client credentials.', 401);
    }
}

// ------------------------------------------------------------ authorization

/**
 * Issue an authorization code. Sixty seconds, one use, bound to the client,
 * the redirect URI and the PKCE challenge.
 */
function oauth_code_issue(string $clientId, int $userId, string $redirectUri,
                          string $challenge, string $scope, ?string $resource): string
{
    $code = oauth_secret();
    db()->prepare(
        "INSERT INTO oauth_auth_codes
            (code_hash, client_id, user_id, redirect_uri, code_challenge, scope, resource, expires_at)
         VALUES (:h, :c, :u, :r, :ch, :s, :res, DATE_ADD(NOW(), INTERVAL :ttl SECOND))"
    )->execute([
        ':h'   => oauth_hash($code),
        ':c'   => $clientId,
        ':u'   => $userId,
        ':r'   => $redirectUri,
        ':ch'  => $challenge,
        ':s'   => $scope,
        ':res' => $resource,
        ':ttl' => OAUTH_CODE_TTL,
    ]);
    return $code;
}

/**
 * Redeem an authorization code.
 *
 * Everything about the original request must line up: same client, same
 * redirect URI, a verifier that hashes to the stored challenge, and the code
 * unused and unexpired.
 *
 * A code presented twice revokes every token it ever produced. Replay means
 * the code leaked, and the safe assumption is that whatever it produced is in
 * the wrong hands too.
 */
function oauth_code_redeem(string $code, string $clientId, string $redirectUri, string $verifier): array
{
    $pdo = db();
    $s = $pdo->prepare("SELECT * FROM oauth_auth_codes WHERE code_hash = :h");
    $s->execute([':h' => oauth_hash($code)]);
    $row = $s->fetch();

    if (!$row) throw new OAuthError('invalid_grant', 'Unknown or expired authorization code.');

    if ($row['used_at'] !== null) {
        oauth_revoke_for_code((int)$row['id']);
        throw new OAuthError('invalid_grant',
            'This authorization code has already been used. Every token it issued has been revoked.');
    }
    if (strtotime((string)$row['expires_at']) < time()) {
        throw new OAuthError('invalid_grant', 'Authorization code expired — start again.');
    }
    if (!hash_equals((string)$row['client_id'], $clientId)) {
        throw new OAuthError('invalid_grant', 'This code was issued to a different client.');
    }
    if (!hash_equals((string)$row['redirect_uri'], $redirectUri)) {
        throw new OAuthError('invalid_grant', 'redirect_uri does not match the one used to authorize.');
    }

    // PKCE S256: BASE64URL(SHA256(verifier)) must equal the stored challenge.
    if ($verifier === '' || strlen($verifier) < 43 || strlen($verifier) > 128) {
        throw new OAuthError('invalid_grant', 'code_verifier missing or the wrong length.');
    }
    $computed = oauth_b64url(hash('sha256', $verifier, true));
    if (!hash_equals((string)$row['code_challenge'], $computed)) {
        throw new OAuthError('invalid_grant', 'code_verifier does not match the challenge.');
    }

    $pdo->prepare("UPDATE oauth_auth_codes SET used_at = NOW() WHERE id = :i")
        ->execute([':i' => (int)$row['id']]);

    return $row;
}

/** Kill every token descended from a replayed code. */
function oauth_revoke_for_code(int $codeId): void
{
    $s = db()->prepare("SELECT user_id, client_id FROM oauth_auth_codes WHERE id = :i");
    $s->execute([':i' => $codeId]);
    $c = $s->fetch();
    if (!$c) return;
    db()->prepare(
        "UPDATE oauth_tokens SET revoked_at = NOW()
          WHERE user_id = :u AND client_id = :c AND revoked_at IS NULL"
    )->execute([':u' => (int)$c['user_id'], ':c' => (string)$c['client_id']]);
}

// -------------------------------------------------------------------- tokens

/** Mint an access/refresh pair. Returns the plaintext tokens — stored hashed. */
function oauth_token_issue(string $clientId, int $userId, string $scope, ?int $replacesId = null): array
{
    $access  = oauth_secret();
    $refresh = oauth_secret();

    db()->prepare(
        "INSERT INTO oauth_tokens
            (access_hash, refresh_hash, client_id, user_id, scope, expires_at, refresh_expires_at)
         VALUES (:a, :r, :c, :u, :s,
                 DATE_ADD(NOW(), INTERVAL :at SECOND),
                 DATE_ADD(NOW(), INTERVAL :rt SECOND))"
    )->execute([
        ':a'  => oauth_hash($access),
        ':r'  => oauth_hash($refresh),
        ':c'  => $clientId,
        ':u'  => $userId,
        ':s'  => $scope,
        ':at' => OAUTH_ACCESS_TTL,
        ':rt' => OAUTH_REFRESH_TTL,
    ]);
    $newId = (int)db()->lastInsertId();

    if ($replacesId !== null) {
        db()->prepare("UPDATE oauth_tokens SET replaced_by = :n WHERE id = :o")
            ->execute([':n' => $newId, ':o' => $replacesId]);
    }

    return [
        'access_token'  => $access,
        'refresh_token' => $refresh,
        'token_type'    => 'Bearer',
        'expires_in'    => OAUTH_ACCESS_TTL,
        'scope'         => $scope,
        '_id'           => $newId,
    ];
}

/**
 * Resolve an access token to its row, or null if it is unknown, expired or
 * revoked. This is what mcp.php calls on every request.
 */
function oauth_access_token_check(string $token): ?array
{
    if (strlen($token) !== 64 || !ctype_xdigit($token)) return null;

    $s = db()->prepare(
        "SELECT t.*, u.name AS user_name, u.role AS user_role, u.active AS user_active
           FROM oauth_tokens t
           JOIN users u ON u.id = t.user_id
          WHERE t.access_hash = :h AND t.revoked_at IS NULL
          LIMIT 1"
    );
    $s->execute([':h' => oauth_hash($token)]);
    $row = $s->fetch();
    if (!$row) return null;
    if (!hash_equals((string)$row['access_hash'], oauth_hash($token))) return null;
    if (strtotime((string)$row['expires_at']) < time()) return null;

    // A member of staff deactivated in /admin.php loses API access with them:
    // their tokens must not outlive their account.
    if ((int)$row['user_active'] !== 1) return null;

    db()->prepare("UPDATE oauth_tokens SET last_used_at = NOW() WHERE id = :i")
        ->execute([':i' => (int)$row['id']]);
    return $row;
}

/**
 * Exchange a refresh token for a new pair.
 *
 * The refresh token rotates: the old one dies the moment it is used. If an
 * *already-rotated* refresh token turns up long after the fact, two parties
 * hold it — the whole chain for that user and client is revoked, and they
 * sign in again.
 *
 * The exception is OAUTH_REFRESH_GRACE, above: a token we rotated seconds ago,
 * whose successor is still live, is a client racing itself rather than a
 * thief, and gets a pair of its own.
 */
function oauth_refresh(string $refresh, string $clientId): array
{
    $s = db()->prepare("SELECT * FROM oauth_tokens WHERE refresh_hash = :h LIMIT 1");
    $s->execute([':h' => oauth_hash($refresh)]);
    $row = $s->fetch();

    if (!$row) throw new OAuthError('invalid_grant', 'Unknown refresh token.');
    if (!hash_equals((string)$row['client_id'], $clientId)) {
        throw new OAuthError('invalid_grant', 'This refresh token belongs to a different client.');
    }

    $spent = $row['replaced_by'] !== null || $row['revoked_at'] !== null;

    if ($spent && !oauth_refresh_within_grace($row)) {
        db()->prepare(
            "UPDATE oauth_tokens SET revoked_at = NOW()
              WHERE user_id = :u AND client_id = :c AND revoked_at IS NULL"
        )->execute([':u' => (int)$row['user_id'], ':c' => (string)$row['client_id']]);
        throw new OAuthError('invalid_grant',
            'This refresh token was already used. Every session for this client has been revoked — sign in again.');
    }
    if ($row['refresh_expires_at'] !== null && strtotime((string)$row['refresh_expires_at']) < time()) {
        throw new OAuthError('invalid_grant', 'Refresh token expired — sign in again.');
    }

    $user = db()->prepare("SELECT active, role FROM users WHERE id = :i");
    $user->execute([':i' => (int)$row['user_id']]);
    $u = $user->fetch();
    if (!$u || (int)$u['active'] !== 1) {
        throw new OAuthError('invalid_grant', 'That account is no longer active.');
    }

    // Retire the old row, then mint its successor.
    //
    // On the grace path the row is already retired and already points at the
    // successor it lost the race to, so it is left alone: overwriting
    // replaced_by would lose the first winner and make the chain unreadable.
    // The two siblings then live side by side until they expire, and whichever
    // one the client kept is the one that works.
    if (!$spent) {
        db()->prepare("UPDATE oauth_tokens SET revoked_at = NOW() WHERE id = :i")
            ->execute([':i' => (int)$row['id']]);
    }

    return oauth_token_issue((string)$row['client_id'], (int)$row['user_id'],
                             (string)$row['scope'], $spent ? null : (int)$row['id']);
}

/**
 * Was this refresh token rotated by us, moments ago, into a successor that is
 * still alive? That is a client racing itself, not a replay.
 *
 * Every clause matters. `replaced_by` set means *we* rotated it, rather than
 * it being revoked by a disconnect or by an earlier breach. The successor
 * still being live means the chain has not already been torn down — once it
 * has, every straggler must be refused, or a thief could ride the grace window
 * straight back in. And the window itself is measured from the moment of
 * rotation, so it cannot be stretched by repeated attempts.
 */
function oauth_refresh_within_grace(array $row): bool
{
    if ($row['replaced_by'] === null || $row['revoked_at'] === null) return false;

    $rotatedAt = strtotime((string)$row['revoked_at']);
    if ($rotatedAt === false || $rotatedAt < time() - OAUTH_REFRESH_GRACE) return false;

    $s = db()->prepare("SELECT revoked_at FROM oauth_tokens WHERE id = :i");
    $s->execute([':i' => (int)$row['replaced_by']]);
    $successor = $s->fetch();

    return $successor !== false && $successor['revoked_at'] === null;
}

function oauth_token_revoke(string $token): void
{
    $h = oauth_hash($token);
    db()->prepare(
        "UPDATE oauth_tokens SET revoked_at = NOW()
          WHERE (access_hash = :a OR refresh_hash = :r) AND revoked_at IS NULL"
    )->execute([':a' => $h, ':r' => $h]);
}

/** Revoke a whole connection from the admin page. */
function oauth_session_revoke(int $tokenId): void
{
    db()->prepare("UPDATE oauth_tokens SET revoked_at = NOW() WHERE id = :i AND revoked_at IS NULL")
        ->execute([':i' => $tokenId]);
}

/** Live connections, newest first, for the admin page. */
function oauth_sessions(): array
{
    return db()->query(
        "SELECT t.id, t.client_id, t.user_id, t.created_at, t.last_used_at,
                t.expires_at, t.revoked_at,
                u.name AS user_name, c.client_name
           FROM oauth_tokens t
           JOIN users u          ON u.id = t.user_id
           LEFT JOIN oauth_clients c ON c.client_id = t.client_id
          WHERE t.revoked_at IS NULL
          ORDER BY t.created_at DESC
          LIMIT 100"
    )->fetchAll();
}

function oauth_clients_all(): array
{
    return db()->query(
        "SELECT c.*, (SELECT COUNT(*) FROM oauth_tokens t
                       WHERE t.client_id = c.client_id AND t.revoked_at IS NULL) AS live_sessions
           FROM oauth_clients c
          ORDER BY c.created_at DESC
          LIMIT 100"
    )->fetchAll();
}

/**
 * Expired codes and tokens are noise, and an expired secret sitting in a table
 * is a secret that can still leak. Swept opportunistically.
 */
function oauth_gc(): void
{
    try {
        db()->exec("DELETE FROM oauth_auth_codes WHERE expires_at < DATE_SUB(NOW(), INTERVAL 1 DAY)");
        db()->exec("DELETE FROM oauth_tokens
                     WHERE COALESCE(refresh_expires_at, expires_at) < DATE_SUB(NOW(), INTERVAL 7 DAY)");
    } catch (Throwable $e) {
        // Housekeeping must never break a live request.
    }
}

// ------------------------------------------------------------------ metadata

/** RFC 8414 — what the authorization server can do. */
function oauth_as_metadata(): array
{
    $b = oauth_base_url();
    return [
        'issuer'                                => $b,
        'authorization_endpoint'                => $b . '/oauth/authorize.php',
        'token_endpoint'                        => $b . '/oauth/token.php',
        'registration_endpoint'                 => $b . '/oauth/register.php',
        'revocation_endpoint'                   => $b . '/oauth/revoke.php',
        'scopes_supported'                      => [OAUTH_SCOPE],
        'response_types_supported'              => ['code'],
        'grant_types_supported'                 => ['authorization_code', 'refresh_token'],
        'code_challenge_methods_supported'      => ['S256'],
        'token_endpoint_auth_methods_supported' => ['none', 'client_secret_post', 'client_secret_basic'],
        'service_documentation'                 => $b . '/docs/mcp.md',
    ];
}

/** RFC 9728 — which authorization server guards this resource. */
function oauth_rs_metadata(): array
{
    $b = oauth_base_url();
    return [
        'resource'                 => $b . '/mcp.php',
        'authorization_servers'    => [$b],
        'scopes_supported'         => [OAUTH_SCOPE],
        'bearer_methods_supported' => ['header'],
        'resource_documentation'   => $b . '/docs/mcp.md',
    ];
}
