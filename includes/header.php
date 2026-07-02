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
    <meta name="csrf-token" content="<?= e(function_exists('csrf_token') ? csrf_token() : '') ?>">
    <meta name="school-name" content="<?= e(function_exists('crm_school_full_name') ? crm_school_full_name() : app_name()) ?>">
    <title><?= e($pageTitle ?? app_name()) ?></title>
    <link rel="preconnect" href="https://fonts.googleapis.com">
    <link rel="preconnect" href="https://fonts.gstatic.com" crossorigin>
    <link rel="stylesheet" href="https://fonts.googleapis.com/css2?family=Quicksand:wght@500;600;700&display=swap">
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
            <?php if ($user['role'] === 'teacher'
                      && (user_has_module($user, 'students') || user_has_module($user, 'montessori'))):
                // Teacher nav (Phase 1): the daily loop up front, everything
                // else folded into one More menu. Admins keep the full nav.
                $teacherExtras = [];
                foreach ([
                    'tasks'       => ['Tasks',       '/tasks/index.php'],
                    'montessori'  => ['Assessment',  '/assessment/index.php'],
                    'crm'         => ['Admissions',  '/crm/index.php'],
                    'recruitment' => ['Recruitment', '/recruitment/index.php'],
                    'staff'       => ['Staff',       '/staff/index.php'],
                    'expenses'    => ['Expenses',    '/expenses/index.php'],
                    'fees'        => ['Fees',        '/fees/index.php'],
                    'logbook'     => ['Logbook',     '/logbook/index.php'],
                    'inventory'   => ['Inventory',   '/inventory/index.php'],
                    'wacrm'       => ['WACRM',       '/wacrm/index.php'],
                    'n8n'         => ['n8n',         '/n8n/index.php'],
                ] as $mk => [$mLabel, $mHref]) {
                    if (user_has_module($user, $mk)) $teacherExtras[] = [$mLabel, $mHref];
                }
            ?>
                <a href="/today.php">My Day</a>
                <a href="<?= user_has_module($user, 'students') ? '/students/index.php' : '/assessment/index.php' ?>">My Class</a>
                <details class="more-menu">
                    <summary>More ▾</summary>
                    <div class="more-menu-list">
                        <?php foreach ($teacherExtras as [$mLabel, $mHref]): ?>
                            <a href="<?= e($mHref) ?>"><?= e($mLabel) ?></a>
                        <?php endforeach; ?>
                        <a href="/index.php?all=1">All apps</a>
                    </div>
                </details>
            <?php else: ?>
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
            <?php if (user_has_module($user, 'crm')): ?>
                <a href="/crm/index.php">Admissions</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'recruitment')): ?>
                <a href="/recruitment/index.php">Recruitment</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'staff')): ?>
                <a href="/staff/index.php">Staff</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'expenses')): ?>
                <a href="/expenses/index.php">Expenses</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'fees')): ?>
                <a href="/fees/index.php">Fees</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'logbook')): ?>
                <a href="/logbook/index.php">Logbook</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'inventory')): ?>
                <a href="/inventory/index.php">Inventory</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'wacrm')): ?>
                <a href="/wacrm/index.php">WACRM</a>
            <?php endif; ?>
            <?php if (user_has_module($user, 'n8n')): ?>
                <a href="/n8n/index.php">n8n</a>
            <?php endif; ?>
            <?php if ($user['role'] === 'admin'): ?>
                <a href="/admin.php">Admin</a>
            <?php endif; ?>
            <?php endif; /* teacher vs full nav */ ?>
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
