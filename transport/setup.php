<?php
/**
 * transport/setup.php — admin: cabs, routes, and transport settings.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/transport.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'cab_save') {
            transport_cab_save($_POST);
            flash_set('ok', 'Cab saved.');
        } elseif ($op === 'route_save') {
            $id = transport_route_save($_POST);
            flash_set('ok', 'Route saved.');
            if (empty($_POST['id'])) redirect('/transport/route.php?id=' . $id);
        } elseif ($op === 'settings_save') {
            $mins = (int)($_POST['transport_default_stop_minutes'] ?? 5);
            if ($mins < 1 || $mins > 30) throw new InvalidArgumentException('Minutes per stop must be 1–30.');
            $upsert = db()->prepare('INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
                                     ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)');
            $upsert->execute([':k' => 'transport_default_stop_minutes', ':v' => (string)$mins]);
            if (!empty($_POST['clear_maps_key'])) {
                $upsert->execute([':k' => 'transport_maps_api_key', ':v' => '']);
            } elseif (trim((string)($_POST['transport_maps_api_key'] ?? '')) !== '') {
                $upsert->execute([':k' => 'transport_maps_api_key',
                                  ':v' => substr(trim((string)$_POST['transport_maps_api_key']), 0, 200)]);
            }
            app_setting_clear_cache();
            flash_set('ok', 'Settings saved.');
        }
    } catch (InvalidArgumentException $e) {
        flash_set('error', $e->getMessage());
    }
    redirect('/transport/setup.php');
}

$cabs = transport_cabs();
$routes = transport_routes();
$editCab = isset($_GET['cab']) ? transport_cab_get((int)$_GET['cab']) : null;
$editRoute = isset($_GET['route']) ? transport_route_get((int)$_GET['route']) : null;
$hasKey = transport_maps_key() !== '';

$pageTitle = 'Transport setup';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Transport setup</h1>
        <p class="muted">Cabs, routes and the order children are picked up.</p>
    </div>
    <div class="actionbar"><a class="btn btn-ghost" href="/transport/index.php">← Today’s trips</a></div>
</div>

<div class="card">
    <h2 style="margin-top:0">Routes</h2>
    <?php if (!$routes): ?><p class="muted">No routes yet.</p><?php endif; ?>
    <?php foreach ($routes as $r): ?>
        <div style="display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px solid var(--line,#eee);flex-wrap:wrap">
            <div>
                <strong><?= e((string)$r['name']) ?></strong>
                <?php if (!(int)$r['is_active']): ?><span class="pill">Inactive</span><?php endif; ?>
                <div class="muted small">
                    <?= e(transport_direction_label((string)$r['direction'])) ?>
                    · <?= $r['cab_name'] ? e((string)$r['cab_name']) : 'No cab' ?>
                    · <?= (int)$r['stop_count'] ?> children
                </div>
            </div>
            <div class="row-actions">
                <a class="btn btn-primary btn-small" href="/transport/route.php?id=<?= (int)$r['id'] ?>">Stops &amp; parents</a>
                <a class="btn btn-ghost btn-small" href="?route=<?= (int)$r['id'] ?>#route-form">Edit</a>
            </div>
        </div>
    <?php endforeach; ?>

    <form method="post" id="route-form" style="margin-top:1rem">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="route_save">
        <input type="hidden" name="id" value="<?= (int)($editRoute['id'] ?? 0) ?>">
        <h3><?= $editRoute ? 'Edit route' : 'Add a route' ?></h3>
        <label>Route name
            <input type="text" name="name" maxlength="120" required value="<?= e((string)($editRoute['name'] ?? '')) ?>" placeholder="e.g. HSR Layout cab">
        </label>
        <label>Cab
            <select name="cab_id">
                <option value="0">— none yet —</option>
                <?php foreach ($cabs as $c): ?>
                    <option value="<?= (int)$c['id'] ?>" <?= (int)($editRoute['cab_id'] ?? 0) === (int)$c['id'] ? 'selected' : '' ?>>
                        <?= e((string)$c['name']) ?><?= $c['vehicle_no'] ? ' · ' . e((string)$c['vehicle_no']) : '' ?>
                    </option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Runs
            <select name="direction">
                <?php foreach (TRANSPORT_ROUTE_DIRECTIONS as $d): ?>
                    <option value="<?= e($d) ?>" <?= ($editRoute['direction'] ?? 'both') === $d ? 'selected' : '' ?>><?= e(transport_direction_label($d)) ?></option>
                <?php endforeach; ?>
            </select>
        </label>
        <label>Pickup start time
            <input type="time" name="pickup_time" value="<?= e(substr((string)($editRoute['pickup_time'] ?? ''), 0, 5)) ?>">
        </label>
        <label>Drop start time
            <input type="time" name="drop_time" value="<?= e(substr((string)($editRoute['drop_time'] ?? ''), 0, 5)) ?>">
        </label>
        <label><input type="checkbox" name="is_active" value="1" <?= !$editRoute || (int)$editRoute['is_active'] ? 'checked' : '' ?>> Active</label>
        <div class="actionbar form-actions">
            <?php if ($editRoute): ?><a class="btn btn-ghost" href="/transport/setup.php">Cancel</a><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $editRoute ? 'Save route' : 'Add route' ?></button>
        </div>
    </form>
</div>

<div class="card">
    <h2 style="margin-top:0">Cabs &amp; drivers</h2>
    <?php if (!$cabs): ?><p class="muted">No cabs yet.</p><?php endif; ?>
    <?php foreach ($cabs as $c): ?>
        <div style="display:flex;justify-content:space-between;gap:1rem;padding:.5rem 0;border-bottom:1px solid var(--line,#eee)">
            <div>
                <strong><?= e((string)$c['name']) ?></strong>
                <?php if (!(int)$c['is_active']): ?><span class="pill">Inactive</span><?php endif; ?>
                <div class="muted small">
                    <?= e((string)$c['vehicle_no']) ?>
                    <?php if ($c['driver_name']): ?> · <?= e((string)$c['driver_name']) ?><?php endif; ?>
                    <?php if ($c['driver_phone']): ?> · <?= e((string)$c['driver_phone']) ?><?php endif; ?>
                    <?php if ((int)$c['capacity'] > 0): ?> · <?= (int)$c['capacity'] ?> seats<?php endif; ?>
                </div>
            </div>
            <a class="btn btn-ghost btn-small" href="?cab=<?= (int)$c['id'] ?>#cab-form">Edit</a>
        </div>
    <?php endforeach; ?>

    <form method="post" id="cab-form" style="margin-top:1rem">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="cab_save">
        <input type="hidden" name="id" value="<?= (int)($editCab['id'] ?? 0) ?>">
        <h3><?= $editCab ? 'Edit cab' : 'Add a cab' ?></h3>
        <label>Cab name <input type="text" name="name" maxlength="80" required value="<?= e((string)($editCab['name'] ?? '')) ?>" placeholder="e.g. Cab 1"></label>
        <label>Vehicle number <input type="text" name="vehicle_no" maxlength="40" value="<?= e((string)($editCab['vehicle_no'] ?? '')) ?>"></label>
        <label>Driver name <input type="text" name="driver_name" maxlength="120" value="<?= e((string)($editCab['driver_name'] ?? '')) ?>"></label>
        <label>Driver phone <input type="tel" name="driver_phone" maxlength="40" value="<?= e((string)($editCab['driver_phone'] ?? '')) ?>"></label>
        <label>Seats <input type="number" name="capacity" min="0" max="200" value="<?= (int)($editCab['capacity'] ?? 0) ?>"></label>
        <label><input type="checkbox" name="is_active" value="1" <?= !$editCab || (int)$editCab['is_active'] ? 'checked' : '' ?>> Active</label>
        <div class="actionbar form-actions">
            <?php if ($editCab): ?><a class="btn btn-ghost" href="/transport/setup.php">Cancel</a><?php endif; ?>
            <button class="btn btn-primary" type="submit"><?= $editCab ? 'Save cab' : 'Add cab' ?></button>
        </div>
    </form>
    <p class="muted small">To let a driver run trips from their phone, give their login the Transport module in Admin.</p>
</div>

<div class="card">
    <h2 style="margin-top:0">Arrival estimates</h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="settings_save">
        <label>Minutes between stops (until the app learns from real trips)
            <input type="number" name="transport_default_stop_minutes" min="1" max="30"
                   value="<?= e((string)app_setting('transport_default_stop_minutes', '5')) ?>">
        </label>
        <label>Google Maps API key (optional, Routes API)
            <input type="password" name="transport_maps_api_key" autocomplete="off"
                   placeholder="<?= $hasKey ? 'Saved — leave blank to keep' : 'Not set' ?>">
        </label>
        <?php if ($hasKey): ?>
            <label><input type="checkbox" name="clear_maps_key" value="1"> Remove the saved key</label>
        <?php endif; ?>
        <p class="muted small">
            With a key and stop locations, use “Refresh travel times” on a route to get traffic-aware road times between stops.
            When the driver runs the trip from the Little Graduates app, parents also see the cab on a map
            while their child is waiting, and stops with a location are marked “reached” automatically.
        </p>
        <div class="actionbar form-actions"><button class="btn btn-primary" type="submit">Save settings</button></div>
    </form>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
