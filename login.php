<?php
/**
 * login.php — dual purpose
 *   GET  → renders the profile-card landing + numpad PIN modal.
 *   POST → AJAX endpoint. Expects { teacher_id, pin, _csrf }.
 *          Returns JSON { ok:true, redirect } or { ok:false, error }.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

start_session_once();

if (current_user()) {
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        header('Content-Type: application/json');
        echo json_encode(['ok' => true, 'redirect' => 'index.php']);
        exit;
    }
    redirect('index.php');
}

// ---------- POST: AJAX PIN verification ----------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    header('Content-Type: application/json; charset=utf-8');

    if (!hash_equals($_SESSION['_csrf'] ?? '', $_POST['_csrf'] ?? '')) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Session expired — refresh the page.']);
        exit;
    }

    $teacherId = isset($_POST['teacher_id']) ? (int)$_POST['teacher_id'] : 0;
    $pin       = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    $cfg       = app_config();

    $now       = time();
    $tries     = (int)($_SESSION['_pin_tries'] ?? 0);
    $lockUntil = (int)($_SESSION['_pin_lock_until'] ?? 0);
    if ($lockUntil > $now) {
        http_response_code(429);
        echo json_encode(['ok' => false, 'error' => 'Too many tries. Wait ' . ($lockUntil - $now) . 's.']);
        exit;
    }

    if ($teacherId <= 0 || strlen($pin) < 4 || strlen($pin) > 6) {
        http_response_code(400);
        echo json_encode(['ok' => false, 'error' => 'Enter a 4–6 digit PIN.']);
        exit;
    }

    $stmt = db()->prepare("SELECT id, name, role, pin_hash FROM teachers WHERE id = :id AND active = 1");
    $stmt->execute([':id' => $teacherId]);
    $t = $stmt->fetch();

    if (!$t || !password_verify($pin, $t['pin_hash'])) {
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

    if (password_needs_rehash($t['pin_hash'], PASSWORD_DEFAULT)) {
        $upd = db()->prepare("UPDATE teachers SET pin_hash = :h WHERE id = :id");
        $upd->execute([':h' => password_hash($pin, PASSWORD_DEFAULT), ':id' => $t['id']]);
    }

    session_regenerate_id(true);
    $_SESSION['teacher_id']      = (int)$t['id'];
    $_SESSION['teacher_name']    = $t['name'];
    $_SESSION['teacher_role']    = $t['role'];
    $_SESSION['_pin_tries']      = 0;
    $_SESSION['_pin_lock_until'] = 0;

    echo json_encode(['ok' => true, 'redirect' => 'index.php']);
    exit;
}

// ---------- GET: render landing ----------
$teachers = db()->query("
    SELECT id, name, role
    FROM teachers
    WHERE active = 1
    ORDER BY (role = 'admin') DESC, name ASC
")->fetchAll();

$cfg = app_config();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFF8F0">
    <title>Sign in — <?= e($cfg['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= e(asset_version()) ?>">
</head>
<body class="landing">

<header class="topbar">
    <a href="login.php" class="brand">
        <img src="assets/img/logo.png" alt="" class="brand-logo">
        <span class="brand-mark"><?= e($cfg['app']['name']) ?></span>
    </a>
    <div class="topbar-date muted"><?= e(date('l, j M')) ?></div>
</header>

<main class="landing-main">
    <h1 class="landing-title">Who&rsquo;s here?</h1>
    <p class="landing-sub">Tap your name to sign in.</p>

    <?php if (!$teachers): ?>
        <div class="empty">
            <p>No active teachers yet. Open <code>install.php</code> to create the first admin.</p>
        </div>
    <?php else: ?>
        <ul class="profile-grid" role="list">
            <?php foreach ($teachers as $t): ?>
                <li>
                    <button type="button"
                            class="profile-card"
                            data-tid="<?= (int)$t['id'] ?>"
                            data-name="<?= e($t['name']) ?>"
                            style="--card: <?= e(user_color((int)$t['id'])) ?>">
                        <span class="profile-avatar"><?= e(user_initials($t['name'])) ?></span>
                        <span class="profile-meta">
                            <span class="profile-name"><?= e($t['name']) ?></span>
                            <span class="profile-role"><?= e($t['role']) ?></span>
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

<script>window.MTT_CSRF = <?= json_encode(csrf_token()) ?>;</script>
<script src="assets/js/login.js?v=<?= e(asset_version()) ?>"></script>
</body>
</html>
