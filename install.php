<?php
/**
 * install.php — first-run admin bootstrap.
 *
 * Open this once after creating the database and applying sql/schema.sql
 * (or sql/migrate_001_unify_users.sql on an existing MTT database).
 *
 * DELETE THIS FILE after the first admin is created.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$adminExists = (int)db()->query("SELECT COUNT(*) FROM users WHERE role = 'admin' AND active = 1")
    ->fetchColumn() > 0;

if ($adminExists) {
    http_response_code(403);
    echo '<p>An admin user already exists. Delete <code>install.php</code> from the server.</p>';
    exit;
}

$err = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
        $err = 'Name and a 4–6 digit PIN are required.';
    } else {
        $stmt = db()->prepare("
            INSERT INTO users (name, pin_hash, role, modules, active)
            VALUES (:n, :h, 'admin', 'tasks,montessori', 1)
        ");
        $stmt->execute([
            ':n' => $name,
            ':h' => password_hash($pin, PASSWORD_DEFAULT),
        ]);
        flash_set('ok', "Admin \"$name\" created with PIN $pin. Delete install.php now.");
        redirect('/login.php');
    }
}

$cfg = app_config();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Install — <?= e($cfg['app']['name']) ?></title>
    <link rel="stylesheet" href="/assets/css/tasks.css?v=<?= e(asset_version()) ?>">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= e(asset_version()) ?>">
</head>
<body>
<main class="container">
    <h1>Create the first admin</h1>
    <p class="muted">This page is only available while no admin exists. Delete it after use.</p>
    <?php if ($err): ?><div class="flash flash-error"><?= e($err) ?></div><?php endif; ?>
    <form method="post" class="card card-form">
        <div class="field">
            <label>Your name</label>
            <input name="name" required maxlength="120" autofocus>
        </div>
        <div class="field">
            <label>Choose a PIN (4–6 digits)</label>
            <input name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Create admin</button>
        </div>
    </form>
</main>
</body>
</html>
