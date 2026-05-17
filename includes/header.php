<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
$cfg  = app_config();
$user = current_user();
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFF8F0">
    <title><?= e($pageTitle ?? $cfg['app']['name']) ?></title>
    <link rel="stylesheet" href="assets/css/style.css?v=<?= e(asset_version()) ?>">
</head>
<body<?= isset($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<header class="topbar">
    <a href="index.php" class="brand">
        <img src="assets/img/logo.png" alt="" class="brand-logo">
        <span class="brand-mark"><?= e($cfg['app']['name']) ?></span>
    </a>
    <?php if ($user): ?>
        <nav>
            <a href="index.php">Dashboard</a>
            <?php if ($user['role'] === 'admin'): ?>
                <a href="admin.php">Admin</a>
            <?php endif; ?>
            <span class="who" style="--card: <?= e(user_color((int)$user['id'])) ?>;">
                <span class="who-avatar"><?= e(user_initials($user['name'])) ?></span>
                <span>
                    <span class="who-name"><?= e(first_name($user['name'])) ?></span><br>
                    <span class="who-role"><?= e($user['role']) ?></span>
                </span>
            </span>
            <a href="logout.php" class="who-out">Log out</a>
        </nav>
    <?php endif; ?>
</header>
<main class="container">
<?php foreach (flash_get() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
