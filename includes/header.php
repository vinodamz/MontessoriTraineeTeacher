<?php
require_once __DIR__ . '/auth.php';
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/notify.php';
$cfg  = app_config();
$user = current_user();
$v    = asset_version();
$unreadCount = $user ? unread_count((int)$user['id']) : 0;
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
    <meta name="theme-color" content="#FFF8F0">
    <title><?= e($pageTitle ?? app_name()) ?></title>
    <link rel="stylesheet" href="/assets/css/tasks.css?v=<?= e($v) ?>">
    <link rel="stylesheet" href="/assets/css/style.css?v=<?= e($v) ?>">
</head>
<body<?= isset($bodyClass) ? ' class="' . e($bodyClass) . '"' : '' ?>>
<header class="topbar">
    <a href="/index.php" class="brand">
        <img src="/assets/img/logo.png" alt="" class="brand-logo">
        <span class="brand-mark"><?= e(app_name()) ?></span>
    </a>
    <?php if ($user): ?>
        <nav>
            <a href="/index.php">Home</a>
            <?php if (user_has_module($user, 'tasks')): ?>
                <a href="/tasks/index.php">Tasks</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'montessori')): ?>
                <a href="/assessment/index.php">Assessment</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'students')): ?>
                <a href="/students/index.php">Students</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'expenses')): ?>
                <a href="/expenses/index.php">Expenses</a>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
                <a href="/admin.php">Admin</a>
            <?php endif; ?>
            <a href="/notifications.php" class="bell" title="Notifications" aria-label="Notifications<?= $unreadCount > 0 ? ' (' . $unreadCount . ' unread)' : '' ?>">
                <span class="bell-icon" aria-hidden="true">🔔</span>
                <?php if ($unreadCount > 0): ?>
                    <span class="bell-badge"><?= $unreadCount > 99 ? '99+' : (int)$unreadCount ?></span>
                <?php endif; ?>
            </a>
            <span class="who" style="--card: <?= e(user_color((int)$user['id'])) ?>;">
                <span class="who-avatar"><?= e(user_initials($user['name'])) ?></span>
                <span>
                    <span class="who-name"><?= e(first_name($user['name'])) ?></span><br>
                    <span class="who-role"><?= e($user['role']) ?></span>
                </span>
            </span>
            <a href="/logout.php" class="who-out">Log out</a>
        </nav>
    <?php endif; ?>
</header>
<main class="container<?= !empty($wideLayout) ? ' wide' : '' ?>">
<?php foreach (flash_get() as $f): ?>
    <div class="flash flash-<?= e($f['type']) ?>"><?= e($f['msg']) ?></div>
<?php endforeach; ?>
