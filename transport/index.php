<?php
/**
 * transport/index.php — today's cab trips. Anyone with the transport module
 * can open a trip and run it; admins also get setup links.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$user = transport_require();
$isAdmin = ($user['role'] ?? '') === 'admin';

if (!transport_tables_ready()) {
    $pageTitle = 'Transport';
    require __DIR__ . '/../includes/header.php';
    echo '<div class="card"><p>Run migrations — transport tables are missing.</p></div>';
    require __DIR__ . '/../includes/footer.php';
    exit;
}

$today = date('Y-m-d');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    try {
        if (($_POST['op'] ?? '') === 'open') {
            $trip = transport_trip_ensure((int)($_POST['route_id'] ?? 0), $today, (string)($_POST['direction'] ?? ''));
            redirect('/transport/trip.php?id=' . (int)$trip['id']);
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('/transport/index.php');
}

$board = transport_board($today);

$pageTitle = 'Transport';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Transport</h1>
        <p class="muted">Today’s cab trips · <?= e(date('D j M Y')) ?></p>
    </div>
    <?php if ($isAdmin): ?>
        <div class="actionbar">
            <a class="btn btn-ghost" href="/transport/setup.php">Cabs, routes &amp; settings</a>
        </div>
    <?php endif; ?>
</div>

<?php if (!$board): ?>
    <div class="card">
        <p class="muted">No active routes yet.</p>
        <?php if ($isAdmin): ?>
            <a class="btn btn-primary" href="/transport/setup.php">Add a cab and a route</a>
        <?php endif; ?>
    </div>
<?php endif; ?>

<?php foreach ($board as $row):
    $r = $row['route'];
    $t = $row['trip'];
    $time = $row['direction'] === 'drop' ? $r['drop_time'] : $r['pickup_time'];
?>
    <div class="card transport-board-row">
        <div style="display:flex;justify-content:space-between;gap:1rem;flex-wrap:wrap;align-items:center">
            <div>
                <h2 style="margin:0"><?= e((string)$r['name']) ?></h2>
                <p class="muted small" style="margin:.25rem 0 0">
                    <?= e(transport_direction_label($row['direction'])) ?>
                    <?php if ($time): ?> · <?= e(date('g:i a', strtotime((string)$time))) ?><?php endif; ?>
                    · <?= $r['cab_name'] ? e((string)$r['cab_name']) : 'No cab assigned' ?>
                    <?php if ($r['driver_name']): ?> · <?= e((string)$r['driver_name']) ?><?php endif; ?>
                    · <?= (int)$r['stop_count'] ?> children
                </p>
            </div>
            <div style="display:flex;gap:.5rem;align-items:center">
                <?php if ($t): ?>
                    <span class="pill <?= $t['status'] === 'completed' ? 'pill-ok' : ($t['status'] === 'running' ? 'pill-warn' : '') ?>">
                        <?= e(transport_trip_status_label((string)$t['status'])) ?>
                        <?php if ((int)$t['total'] > 0): ?> · <?= (int)$t['finished'] ?>/<?= (int)$t['total'] ?><?php endif; ?>
                    </span>
                <?php endif; ?>
                <form method="post" style="margin:0">
                    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                    <input type="hidden" name="op" value="open">
                    <input type="hidden" name="route_id" value="<?= (int)$r['id'] ?>">
                    <input type="hidden" name="direction" value="<?= e($row['direction']) ?>">
                    <button class="btn <?= $t && $t['status'] === 'running' ? 'btn-primary' : 'btn-ghost' ?>" type="submit">
                        <?= !$t || $t['status'] === 'scheduled' ? 'Open trip' : 'View trip' ?>
                    </button>
                </form>
            </div>
        </div>
    </div>
<?php endforeach; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
