<?php
/**
 * includes/api.php — JSON API for the Little Graduates mobile app.
 *
 * Auth is a bearer token per device (Authorization: Bearer <token>). Only the
 * SHA-256 of the token is stored. Tokens never expire on their own; signing
 * out or deactivating the user ends them.
 */
declare(strict_types=1);

require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';

const API_MAX_PIN_FAILURES = 5;
const API_LOCK_MINUTES = 5;

function api_boot(): void
{
    $cfg = app_config();
    date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
    header('Content-Type: application/json; charset=utf-8');
    header('Cache-Control: no-store');
    header('X-Content-Type-Options: nosniff');
}

function api_json(array $data, int $status = 200): never
{
    http_response_code($status);
    echo json_encode($data, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
    exit;
}

function api_error(string $message, int $status = 400, string $code = 'bad_request'): never
{
    api_json(['ok' => false, 'error' => $message, 'code' => $code], $status);
}

/** JSON body (or form fields as a fallback). */
function api_input(): array
{
    static $in = null;
    if ($in !== null) return $in;
    $raw = (string)file_get_contents('php://input');
    $decoded = $raw !== '' ? json_decode($raw, true) : null;
    $in = is_array($decoded) ? $decoded : $_POST;
    return $in;
}

function api_require_method(string $method): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== $method) {
        header('Allow: ' . $method);
        api_error('Use ' . $method . '.', 405, 'method_not_allowed');
    }
}

function api_bearer_token(): string
{
    $h = (string)($_SERVER['HTTP_AUTHORIZATION'] ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION'] ?? '');
    return preg_match('/^Bearer\s+([a-f0-9]{64})$/i', trim($h), $m) ? strtolower($m[1]) : '';
}

/** Current user for the bearer token, or a 401. Shape matches current_user(). */
function api_require_user(): array
{
    $token = api_bearer_token();
    if ($token === '') api_error('Sign in again.', 401, 'unauthenticated');
    $st = db()->prepare('SELECT t.id AS token_id, u.id, u.name, u.role, u.modules, u.active
                           FROM api_tokens t JOIN users u ON u.id = t.user_id
                          WHERE t.token_hash = :h AND t.revoked_at IS NULL');
    $st->execute([':h' => hash('sha256', $token)]);
    $row = $st->fetch();
    if (!$row || !(int)$row['active']) api_error('Sign in again.', 401, 'unauthenticated');
    db()->prepare('UPDATE api_tokens SET last_used_at = NOW() WHERE id = :id AND (last_used_at IS NULL OR last_used_at < NOW() - INTERVAL 1 MINUTE)')
        ->execute([':id' => (int)$row['token_id']]);
    return [
        'id'       => (int)$row['id'],
        'name'     => (string)$row['name'],
        'role'     => (string)$row['role'],
        'modules'  => user_modules_from_row($row),
        'token_id' => (int)$row['token_id'],
    ];
}

function api_require_module(array $user, string $module): void
{
    if (!user_has_module($user, $module)) {
        api_error('You do not have access to ' . $module . '.', 403, 'forbidden');
    }
}

function api_client_ip(): string
{
    return substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45);
}

/** Seconds until this user may try again from this IP (0 = allowed). */
function api_login_locked_for(int $userId): int
{
    $st = db()->prepare('SELECT COUNT(*), MAX(failed_at) FROM api_login_failures
                          WHERE user_id = :u AND ip = :ip AND failed_at > NOW() - INTERVAL ' . API_LOCK_MINUTES . ' MINUTE');
    $st->execute([':u' => $userId, ':ip' => api_client_ip()]);
    [$n, $last] = $st->fetch(PDO::FETCH_NUM);
    if ((int)$n < API_MAX_PIN_FAILURES || !$last) return 0;
    return max(1, strtotime((string)$last) + API_LOCK_MINUTES * 60 - time());
}

/** Verify a PIN and issue a device token. Returns [token, user] or throws. */
function api_login(int $userId, string $pin, string $deviceName): array
{
    $pin = (string)preg_replace('/\D/', '', $pin);
    if ($userId <= 0 || strlen($pin) < 4 || strlen($pin) > 6) {
        throw new InvalidArgumentException('Enter a 4–6 digit PIN.');
    }
    $wait = api_login_locked_for($userId);
    if ($wait > 0) throw new RuntimeException('Too many tries. Wait ' . ceil($wait / 60) . ' min.');

    $st = db()->prepare('SELECT id, name, role, modules, pin_hash FROM users WHERE id = :id AND active = 1');
    $st->execute([':id' => $userId]);
    $u = $st->fetch();
    if (!$u || !password_verify($pin, (string)$u['pin_hash'])) {
        usleep(random_int(120000, 280000));
        db()->prepare('INSERT INTO api_login_failures (user_id, ip) VALUES (:u, :ip)')
            ->execute([':u' => $userId, ':ip' => api_client_ip()]);
        throw new InvalidArgumentException('Wrong PIN.');
    }
    db()->prepare('DELETE FROM api_login_failures WHERE user_id = :u')->execute([':u' => $userId]);

    $token = bin2hex(random_bytes(32));
    db()->prepare('INSERT INTO api_tokens (user_id, token_hash, device_name, last_used_at) VALUES (:u, :h, :d, NOW())')
        ->execute([':u' => (int)$u['id'], ':h' => hash('sha256', $token), ':d' => mb_substr(trim($deviceName), 0, 120)]);
    return [$token, [
        'id'      => (int)$u['id'],
        'name'    => (string)$u['name'],
        'role'    => (string)$u['role'],
        'modules' => user_modules_from_row($u),
    ]];
}

function api_logout(array $user): void
{
    db()->prepare('UPDATE api_tokens SET revoked_at = NOW() WHERE id = :id')->execute([':id' => (int)$user['token_id']]);
}

/**
 * What the app's home screen shows. Modules the app has native screens for
 * are 'native'; the rest open the website so the app covers all of MTT from
 * day one and screens can move in one module at a time.
 */
function api_app_modules(array $user): array
{
    $base = app_base_url();
    $catalog = [
        'transport'  => ['Transport',   '/transport/index.php', true],
        'students'   => ['Students',    '/students/index.php',  false],
        'montessori' => ['Assessment',  '/assessment/index.php', false],
        'tasks'      => ['Tasks',       '/tasks/index.php',     false],
        'staff'      => ['Staff',       '/staff/index.php',     true],
        'crm'        => ['Admissions',  '/crm/index.php',       false],
        'fees'       => ['Fees',        '/fees/index.php',      false],
        'expenses'   => ['Expenses',    '/expenses/index.php',  false],
        'logbook'    => ['Logbook',     '/logbook/index.php',   false],
        'inventory'  => ['Inventory',   '/inventory/index.php', false],
        'materials'  => ['Materials',   '/materials/daily.php', false],
        'plans'      => ['Weekly Plans','/plans/index.php',     false],
        'daycare'    => ['Daycare',     '/daycare/index.php',   false],
        'recruitment'=> ['Recruitment', '/recruitment/index.php', false],
    ];
    $out = [];
    foreach ($catalog as $key => [$label, $path, $native]) {
        if (!user_has_module($user, $key)) continue;
        $out[] = ['key' => $key, 'label' => $label, 'native' => $native, 'web_url' => $base . $path];
    }
    return $out;
}
