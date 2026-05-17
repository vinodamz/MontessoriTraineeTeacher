<?php
// Session + PIN authentication helpers.

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

function current_user(): ?array
{
    start_session_once();
    if (empty($_SESSION['teacher_id'])) {
        return null;
    }
    return [
        'id'   => (int)$_SESSION['teacher_id'],
        'name' => $_SESSION['teacher_name'] ?? '',
        'role' => $_SESSION['teacher_role'] ?? 'teacher',
    ];
}

function require_login(): array
{
    $u = current_user();
    if (!$u) {
        redirect('login.php');
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

function logout(): void
{
    start_session_once();
    $_SESSION = [];
    if (ini_get('session.use_cookies')) {
        $p = session_get_cookie_params();
        setcookie(session_name(), '', time() - 42000,
            $p['path'], $p['domain'], $p['secure'], $p['httponly']);
    }
    session_destroy();
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
