<?php
/**
 * login.php — unified PIN login.
 *   GET  → profile-card landing + numpad PIN modal.
 *   POST → AJAX endpoint. Expects { user_id, pin, _csrf }.
 *          Returns JSON { ok:true, redirect } or { ok:false, error }.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_session_once();

if (current_user()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => '/index.php']);
        exit;
    }
    redirect('/index.php');
}

// ---------- POST: AJAX PIN verification ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Session expired — refresh the page.']);
        exit;
    }

    $userId = isset($_POST['user_id']) ? (int)$_POST['user_id'] : 0;
    $pin    = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $cfg    = app_config();

    $now       = time();
    $tries     = (int)($_SESSION['_pin_tries'] ?? 0);
    $lockUntil = (int)($_SESSION['_pin_lock_until'] ?? 0);
    if ($lockUntil > $now) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many tries. Wait ' . ($lockUntil - $now) . 's.']);
        exit;
    }

    if ($userId <= 0 || strlen($pin) < 4 || strlen($pin) > 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Enter a 4–6 digit PIN.']);
        exit;
    }

    $stmt = db()->prepare("SELECT id, name, role, modules, pin_hash FROM users WHERE id = :id AND active = 1");
    $stmt->execute([':id' => $userId]);
    $u = $stmt->fetch();

    if (!$u || !password_verify($pin, $u['pin_hash'])) {
        usleep(random_int(120000, 280000));
        $tries++;
        $_SESSION['_pin_tries'] = $tries;
        if ($tries >= ($cfg['app']['max_pin_tries'] ?? 5)) {
            $_SESSION['_pin_lock_until'] = $now + ($cfg['app']['lock_seconds'] ?? 30);
            $_SESSION['_pin_tries'] = 0;
        }
        http_response_code(401);
        echo json_encode(['ok' => false, 'error' => 'Wrong PIN']);
        exit;
    }

    if (password_needs_rehash($u['pin_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare("UPDATE users SET pin_hash = :h WHERE id = :id");
        $upd->execute([':h' => password_hash($pin, PASSWORD_DEFAULT), ':id' => $u['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['user_id']      = (int)$u['id'];
    $_SESSION['user_name']    = $u['name'];
    $_SESSION['user_role']    = $u['role'];
    $_SESSION['user_modules'] = user_modules_from_row($u);
    $_SESSION['_pin_tries']      = 0;
    $_SESSION['_pin_lock_until'] = 0;

    echo json_encode(['ok' => true, 'redirect' => '/index.php']);
    exit;
}

// ---------- GET: render landing ----------
$cfg = app_config();

// Bootstrap gate: if the unified `users` table doesn't exist yet, the database
// is still on the old `teachers`-only schema and every query below would 500.
// Render a one-shot "Run setup" page instead — the user clicks through to
// /migrate.php which runs without auth specifically for this case.
if (!users_table_exists()) {
    ?>
    <!doctype html>
    <html lang="en">
    <head>
        <meta charset="utf-8">
        <meta name="viewport" content="width=device-width, initial-scale=1">
        <title>Setup needed — <?= e($cfg['app']['name']) ?></title>
        <link rel="stylesheet" href="/assets/css/tasks.css?v=<?= e(asset_version()) ?>">
        <link rel="stylesheet" href="/assets/css/style.css?v=<?= e(asset_version()) ?>">
    </head>
    <body>
    <main class="container">
        <h1>One-time database upgrade needed</h1>
        <p>The new unified app is deployed, but the database is still on the old schema.</p>
        <p>Click below to run the migration. It's safe — every step is idempotent,
           and your existing teachers, students, and assessments are preserved.</p>
        <p style="margin-top: 1.5rem;">
            <a class="btn btn-primary" href="/migrate.php">Run database upgrade</a>
        </p>
        <p class="muted" style="margin-top: 2rem; font-size: .9rem;">
            After it finishes, come back to <a href="/login.php">/login.php</a> and sign in with your existing PIN.
        </p>
    </main>
    </body>
    </html>
    <?php
    exit;
}

$users = db()->query("
    SELECT id, name, role, modules
    FROM users
    WHERE active = 1
    ORDER BY (role = 'admin') DESC, name ASC
")->fetchAll();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFF8F0">
    <title>Sign in — <?= e($cfg['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/tasks.css?v=<?= e(asset_version()) ?>">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= e(asset_version()) ?>">
</head>
<body class="landing">

<header class="topbar">
    <a href="/login.php" class="brand">
        <img src="/assets/img/logo.png" alt="" class="brand-logo">
        <span class="brand-mark"><?= e($cfg['app']['name']) ?></span>
    </a>
    <div class="topbar-date muted"><?= e(date('l, j M')) ?></div>
</header>

<main class="landing-main">
    <h1 class="landing-title">Who&rsquo;s here?</h1>
    <p class="landing-sub">Tap your name to sign in.</p>

    <?php if (!$users): ?>
        <div class="empty">
            <p>No active users yet. Open <code>/install.php</code> to create the first admin.</p>
        </div>
    <?php else: ?>
        <ul class="profile-grid" role="list">
            <?php foreach ($users as $u): ?>
                <li>
                    <button type="button"
                            class="profile-card"
                            data-uid="<?= (int)$u['id'] ?>"
                            data-name="<?= e($u['name']) ?>"
                            style="--card: <?= e(user_color((int)$u['id'])) ?>">
                        <span class="profile-avatar"><?= e(user_initials($u['name'])) ?></span>
                        <span class="profile-meta">
                            <span class="profile-name"><?= e($u['name']) ?></span>
                            <span class="profile-role"><?= e($u['role']) ?></span>
                        </span>
                    </button>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</main>

<div class="pin-overlay" id="pinOverlay" hidden>
    <div class="pin-modal" role="dialog" aria-modal="true" aria-labelledby="pinHello">
        <button class="pin-close" type="button" aria-label="Close" id="pinClose">&times;</button>
        <p class="pin-hello" id="pinHello">Hi —</p>
        <p class="pin-prompt">Enter your PIN</p>
        <div class="pin-dots" id="pinDots">
            <span></span><span></span><span></span><span></span><span></span><span></span>
        </div>
        <p class="pin-error" id="pinError">&nbsp;</p>
        <div class="numpad" id="numpad">
            <?php foreach ([1,2,3,4,5,6,7,8,9] as $n): ?>
                <button type="button" class="key" data-k="<?= $n ?>"><?= $n ?></button>
            <?php endforeach; ?>
            <button type="button" class="key key-ghost" data-k="clear">Clear</button>
            <button type="button" class="key" data-k="0">0</button>
            <button type="button" class="key key-ghost" data-k="back">&larr;</button>
        </div>
        <button type="button" id="pinSubmit" class="btn btn-primary pin-submit" disabled>Sign in</button>
    </div>
</div>

<script>window.LG_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="/assets/js/login.js?v=<?= e(asset_version()) ?>"></script>
</body>
</html>
