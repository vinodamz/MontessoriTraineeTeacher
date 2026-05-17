<?php
/**
 * install.php — first-run admin bootstrap.
 *
 * Open this once in your browser after creating the database and running
 * sql/schema.sql (+ sql/seeds.sql if you want the pre-loaded indicators).
 *
 * DELETE THIS FILE after the first admin is created.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$adminExists = (int)db()->query(
    "SELECT COUNT(*) FROM teachers WHERE role = 'admin' AND active = 1"
)->fetchColumn() > 0;

if ($adminExists) {
    http_response_code(403);
    echo '<!doctype html><meta charset=utf-8><title>Already installed</title>';
    echo '<h1>Already installed</h1>';
    echo '<p>An admin user already exists. Delete <code>install.php</code> from the server now.</p>';
    exit;
}

$error = null;
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $name = trim($_POST['name'] ?? '');
    $pin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');
    if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
        $error = 'Name and a 4–6 digit PIN are required.';
    } else {
        $stmt = db()->prepare(
            "INSERT INTO teachers (name, pin_hash, role, active) VALUES (:n, :h, 'admin', 1)"
        );
        $stmt->execute([':n' => $name, ':h' => password_hash($pin, PASSWORD_DEFAULT)]);
        echo '<!doctype html><meta charset=utf-8><title>Installed</title>';
        echo '<link rel="stylesheet" href="assets/css/style.css">';
        echo '<main class="container"><h1>Admin created ✔</h1>';
        echo '<p>Now <strong>delete <code>install.php</code></strong> from the server, ';
        echo 'then <a href="login.php">log in</a>.</p></main>';
        exit;
    }
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Install — Trainee Teacher Assessment</title>
    <link rel="stylesheet" href="assets/css/style.css">
</head>
<body>
<main class="container">
    <h1>Create the first admin</h1>
    <p class="muted">This page only works because no admin teacher exists yet.</p>
    <?php if ($error): ?><div class="flash flash-error"><?= e($error) ?></div><?php endif; ?>
    <form method="post" class="login-card">
        <label>Your name
            <input name="name" required maxlength="100" autocomplete="name">
        </label>
        <label>PIN (4–6 digits)
            <input name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required>
        </label>
        <button class="btn btn-primary">Create admin</button>
    </form>
</main>
</body>
</html>
