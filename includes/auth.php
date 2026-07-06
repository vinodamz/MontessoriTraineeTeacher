<?php
// Session + PIN authentication helpers — unified across modules.

require_once __DIR__ . '/errors.php';   // friendly 500s — must load first
require_once __DIR__ . '/db.php';

function app_config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = require __DIR__ . '/config.php';
    }
    return $cfg;
}

function start_session_once(): void
{
    if (session_status() !== PHP_SESSION_ACTIVE) {
        $cfg = app_config();
        session_name($cfg['app']['session_name']);
        session_set_cookie_params([
            'lifetime' => 0,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        session_start();
        date_default_timezone_set($cfg['app']['timezone'] ?? 'UTC');
    }
}

/**
 * Look up the user matching this PIN. PINs are bcrypt-hashed so we iterate
 * active users and password_verify. Fine for small staff lists.
 *
 * Optional $module restricts to users with that module in their access set.
 */
function login_by_pin(string $pin, ?string $module = null): ?array
{
    start_session_once();
    $cfg = app_config();

    $now = time();
    $tries = $_SESSION['_pin_tries'] ?? 0;
    $lockUntil = $_SESSION['_pin_lock_until'] ?? 0;
    if ($lockUntil > $now) {
        return null;
    }

    $pin = preg_replace('/\D/', '', $pin);
    if ($pin === '' || strlen($pin) < 4 || strlen($pin) > 6) {
        $_SESSION['_pin_tries'] = $tries + 1;
        return null;
    }

    $sql = "SELECT id, name, pin_hash, role, modules FROM users WHERE active = 1";
    $stmt = db()->query($sql);
    foreach ($stmt as $row) {
        if (!password_verify($pin, $row['pin_hash'])) continue;

        $modules = user_modules_from_row($row);
        if ($module !== null && !in_array($module, $modules, true) && $row['role'] !== 'admin') {
            // Right PIN, but this user can't access this module.
            return ['error' => 'no_module_access'];
        }

        $_SESSION['user_id']      = (int)$row['id'];
        $_SESSION['user_name']    = $row['name'];
        $_SESSION['user_role']    = $row['role'];
        $_SESSION['user_modules'] = $modules;
        $_SESSION['_pin_tries']      = 0;
        $_SESSION['_pin_lock_until'] = 0;
        session_regenerate_id(true);
        remember_issue((int)$row['id']);
        unset($row['pin_hash']);
        $row['modules'] = $modules;
        return $row;
    }

    $tries++;
    $_SESSION['_pin_tries'] = $tries;
    if ($tries >= ($cfg['app']['max_pin_tries'] ?? 5)) {
        $_SESSION['_pin_lock_until'] = $now + ($cfg['app']['lock_seconds'] ?? 30);
        $_SESSION['_pin_tries'] = 0;
    }
    return null;
}

// ---------- "Remember this device" ------------------------------------------
// Shared-hosting PHP sessions die after ~24 idle minutes and phone browsers
// drop session cookies when the app closes — which read as "always asks for
// login". A 30-day selector/validator cookie re-establishes the session
// transparently. Validator is stored hashed and rotated on every use.

const REMEMBER_COOKIE = 'LG_REMEMBER';
const REMEMBER_DAYS   = 30;

function remember_issue(int $userId): void
{
    try {
        $selector  = bin2hex(random_bytes(12));
        $validator = bin2hex(random_bytes(32));
        db()->prepare("
            INSERT INTO auth_tokens (user_id, selector, validator_hash, expires_at, user_agent)
            VALUES (:u, :s, :h, DATE_ADD(NOW(), INTERVAL :d DAY), :ua)
        ")->execute([
            ':u' => $userId, ':s' => $selector,
            ':h' => hash('sha256', $validator),
            ':d' => REMEMBER_DAYS,
            ':ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 190),
        ]);
        // Keep at most 8 devices per user (old phones age out).
        db()->prepare("
            DELETE FROM auth_tokens WHERE user_id = :u AND id NOT IN (
                SELECT id FROM (SELECT id FROM auth_tokens WHERE user_id = :u2 ORDER BY id DESC LIMIT 8) keep
            )
        ")->execute([':u' => $userId, ':u2' => $userId]);
        setcookie(REMEMBER_COOKIE, $selector . ':' . $validator, [
            'expires'  => time() + 86400 * REMEMBER_DAYS,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    } catch (Throwable $e) {
        // Table not migrated yet / cookie failure — login still works, it
        // just won't persist. Never block the login on this.
    }
}

function remember_clear(): void
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw !== '' && str_contains($raw, ':')) {
        [$selector] = explode(':', $raw, 2);
        try {
            db()->prepare("DELETE FROM auth_tokens WHERE selector = :s")->execute([':s' => $selector]);
        } catch (Throwable $e) { /* best effort */ }
    }
    setcookie(REMEMBER_COOKIE, '', [
        'expires' => time() - 42000, 'path' => '/',
        'secure' => !empty($_SERVER['HTTPS']), 'httponly' => true, 'samesite' => 'Lax',
    ]);
}

/** Try to log in from the remember cookie. Returns the user id or null. */
function remember_login(): ?int
{
    $raw = (string)($_COOKIE[REMEMBER_COOKIE] ?? '');
    if ($raw === '' || !str_contains($raw, ':')) return null;
    [$selector, $validator] = explode(':', $raw, 2);
    if (!preg_match('/^[a-f0-9]{24}$/', $selector) || !preg_match('/^[a-f0-9]{64}$/', $validator)) return null;
    try {
        $st = db()->prepare("
            SELECT t.id, t.user_id, t.validator_hash, u.active
            FROM auth_tokens t JOIN users u ON u.id = t.user_id
            WHERE t.selector = :s AND t.expires_at > NOW()
        ");
        $st->execute([':s' => $selector]);
        $row = $st->fetch();
        if (!$row || !(int)$row['active']) return null;
        if (!hash_equals((string)$row['validator_hash'], hash('sha256', $validator))) {
            // Cookie theft signature (right selector, wrong validator) —
            // burn every token for this user.
            db()->prepare("DELETE FROM auth_tokens WHERE user_id = :u")->execute([':u' => (int)$row['user_id']]);
            return null;
        }
        // Rotate the validator on every use; expiry slides 30 days.
        $newValidator = bin2hex(random_bytes(32));
        db()->prepare("
            UPDATE auth_tokens
            SET validator_hash = :h, last_used_at = NOW(),
                expires_at = DATE_ADD(NOW(), INTERVAL :d DAY)
            WHERE id = :id
        ")->execute([':h' => hash('sha256', $newValidator), ':d' => REMEMBER_DAYS, ':id' => (int)$row['id']]);
        setcookie(REMEMBER_COOKIE, $selector . ':' . $newValidator, [
            'expires'  => time() + 86400 * REMEMBER_DAYS,
            'path'     => '/',
            'secure'   => !empty($_SERVER['HTTPS']),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
        return (int)$row['user_id'];
    } catch (Throwable $e) {
        return null;   // pre-migration DB etc. — behave as before
    }
}

function user_modules_from_row(array $row): array
{
    $raw = $row['modules'] ?? '';
    if ($raw === '' || $raw === null) return [];
    return array_values(array_filter(array_map('trim', explode(',', $raw))));
}

/**
 * Three possible states of the `users` table on the live DB:
 *   - 'missing'  → table doesn't exist at all.
 *   - 'legacy'   → table exists but lacks the `modules` column (usually a
 *                  leftover LG-style users table on the MTT database).
 *   - 'unified'  → the table we want (has `modules`).
 *
 * Login and migrate.php gate on this so the user doesn't see a generic 500
 * during any pre-unified state.
 */
function users_table_state(): string
{
    try {
        $pdo  = db();
        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'users'
        ");
        $stmt->execute();
        if (!$stmt->fetchColumn()) return 'missing';

        $stmt = $pdo->prepare("
            SELECT 1 FROM information_schema.columns
            WHERE table_schema = DATABASE() AND table_name = 'users' AND column_name = 'modules'
        ");
        $stmt->execute();
        return $stmt->fetchColumn() ? 'unified' : 'legacy';
    } catch (Throwable $e) {
        return 'missing';
    }
}

/** True only when the table is in its final unified shape. */
function users_table_is_unified(): bool
{
    return users_table_state() === 'unified';
}

/** Back-compat with the first hotfix. */
function users_table_exists(): bool
{
    try {
        $stmt = db()->prepare("
            SELECT 1 FROM information_schema.tables
            WHERE table_schema = DATABASE() AND table_name = 'users'
        ");
        $stmt->execute();
        return (bool)$stmt->fetchColumn();
    } catch (Throwable $e) {
        return false;
    }
}

function logout(): void
{
    start_session_once();
    remember_clear();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
}

function current_user(): ?array
{
    start_session_once();
    if (empty($_SESSION['user_id'])) {
        // Session expired or the phone browser dropped it — the remember
        // cookie re-establishes it without bothering the teacher.
        $uid = remember_login();
        if ($uid === null) {
            return null;
        }
        $_SESSION['user_id'] = $uid;
        session_regenerate_id(true);
    }
    $uid = (int)$_SESSION['user_id'];

    // Re-read modules + role + active from the DB on every request so that
    // when an admin grants a teacher new module access (or revokes one), the
    // change takes effect immediately — no need for the teacher to log out
    // and back in. Cached per request via a static so we hit the DB once
    // per page load, not once per current_user() call.
    static $cache = [];
    if (!isset($cache[$uid])) {
        try {
            $stmt = db()->prepare("SELECT name, role, modules, active FROM users WHERE id = :id");
            $stmt->execute([':id' => $uid]);
            $row = $stmt->fetch();
        } catch (Throwable $e) {
            $row = null;
        }
        if (!$row || !(int)($row['active'] ?? 0)) {
            // User was deleted or deactivated — invalidate the session.
            session_unset();
            session_destroy();
            $cache[$uid] = null;
            return null;
        }
        $cache[$uid] = [
            'id'      => $uid,
            'name'    => $row['name'] ?? ($_SESSION['user_name'] ?? ''),
            'role'    => $row['role'] ?? 'teacher',
            'modules' => user_modules_from_row($row),
        ];
        // Keep session in sync so other code reading $_SESSION directly
        // (header nav, etc.) sees the fresh values too.
        $_SESSION['user_name']    = $cache[$uid]['name'];
        $_SESSION['user_role']    = $cache[$uid]['role'];
        $_SESSION['user_modules'] = $cache[$uid]['modules'];
    }
    return $cache[$uid];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        header('Location: /login.php');
        exit;
    }
    return $u;
}

function require_admin(): array
{
    $u = require_login();
    if ($u['role'] !== 'admin') {
        http_response_code(403);
        echo 'Forbidden — admins only.';
        exit;
    }
    return $u;
}

/**
 * Gate a page on a module. Admins implicitly have access to all modules.
 * Non-admins must have the module in their `modules` SET on users.
 */
function require_module(string $module): array
{
    $u = require_login();
    if ($u['role'] === 'admin') return $u;
    if (in_array($module, $u['modules'], true)) return $u;
    http_response_code(403);
    echo 'Forbidden — you do not have access to the ' . htmlspecialchars($module) . ' module.';
    exit;
}

function user_has_module(array $user, string $module): bool
{
    if (($user['role'] ?? '') === 'admin') return true;
    return in_array($module, $user['modules'] ?? [], true);
}

function csrf_token(): string
{
    start_session_once();
    if (empty($_SESSION['_csrf'])) {
        $_SESSION['_csrf'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['_csrf'];
}

function csrf_check(): void
{
    start_session_once();
    $sent = $_POST['_csrf'] ?? '';
    if (!hash_equals($_SESSION['_csrf'] ?? '', $sent)) {
        http_response_code(400);
        exit('Bad CSRF token.');
    }
}
