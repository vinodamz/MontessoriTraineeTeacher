<?php
/**
 * admin.php — unified user management.
 *
 * Cross-module admin lives here. Per-module admin (students, indicators,
 * task columns, recurrences) stays in the module's own admin page.
 *
 * Actions: create / update / activate / deactivate / delete users,
 *          assign modules + role.
 */
require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';

$me = require_admin();

function pin_is_in_use(string $pin, ?int $excludeUserId = null): bool
{
    $stmt = db()->query("SELECT id, pin_hash FROM users");
    foreach ($stmt as $row) {
        if ($excludeUserId !== null && (int)$row['id'] === $excludeUserId) continue;
        if (password_verify($pin, $row['pin_hash'])) return true;
    }
    return false;
}

function modules_from_post(array $post): string
{
    $picked = $post['modules'] ?? [];
    if (!is_array($picked)) $picked = [];
    $valid = array_intersect($picked, ['tasks', 'montessori', 'students', 'crm', 'recruitment', 'staff', 'expenses', 'fees', 'logbook', 'inventory', 'wacrm', 'n8n']);
    return implode(',', $valid);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';

    if ($op === 'create') {
        $name    = trim($_POST['name'] ?? '');
        $pin     = preg_replace('/\D/', '', $_POST['pin'] ?? '');
        $role    = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
        $modules = modules_from_post($_POST);

        if ($name === '' || strlen($pin) < 4 || strlen($pin) > 6) {
            flash_set('error', 'Name and a 4–6 digit PIN are required.');
            redirect('/admin.php');
        }
        if (pin_is_in_use($pin)) {
            flash_set('error', 'That PIN is already in use. Pick another.');
            redirect('/admin.php');
        }

        $stmt = db()->prepare("INSERT INTO users (name, pin_hash, role, modules, active) VALUES (:n, :h, :r, :m, 1)");
        $stmt->execute([
            ':n' => $name,
            ':h' => password_hash($pin, PASSWORD_DEFAULT),
            ':r' => $role,
            ':m' => $modules,
        ]);
        flash_set('ok', "User added. Their PIN is $pin — share it with them privately.");
        redirect('/admin.php');
    }

    if ($op === 'update') {
        $id      = (int)($_POST['id'] ?? 0);
        $name    = trim($_POST['name'] ?? '');
        $role    = ($_POST['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';
        $active  = !empty($_POST['active']) ? 1 : 0;
        $modules = modules_from_post($_POST);
        $newPin  = preg_replace('/\D/', '', $_POST['pin'] ?? '');

        if ($id === $me['id'] && $role !== 'admin') {
            flash_set('error', "You can't demote yourself.");
            redirect('/admin.php');
        }
        if ($id === $me['id'] && !$active) {
            flash_set('error', "You can't deactivate yourself.");
            redirect('/admin.php');
        }
        if ($name === '' || $id <= 0) {
            flash_set('error', 'Bad input.');
            redirect('/admin.php');
        }

        if ($newPin !== '') {
            if (strlen($newPin) < 4 || strlen($newPin) > 6) {
                flash_set('error', 'PIN must be 4–6 digits.');
                redirect('/admin.php');
            }
            if (pin_is_in_use($newPin, $id)) {
                flash_set('error', 'That PIN is already in use.');
                redirect('/admin.php');
            }
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, modules=:m, pin_hash=:h WHERE id=:id");
            $stmt->execute([
                ':n' => $name, ':r' => $role, ':a' => $active, ':m' => $modules,
                ':h' => password_hash($newPin, PASSWORD_DEFAULT), ':id' => $id,
            ]);
        } else {
            $stmt = db()->prepare("UPDATE users SET name=:n, role=:r, active=:a, modules=:m WHERE id=:id");
            $stmt->execute([':n'=>$name, ':r'=>$role, ':a'=>$active, ':m'=>$modules, ':id'=>$id]);
        }
        flash_set('ok', 'User updated.');
        redirect('/admin.php');
    }

    if ($op === 'delete') {
        $id = (int)($_POST['id'] ?? 0);
        if ($id === $me['id']) {
            flash_set('error', "You can't delete yourself.");
            redirect('/admin.php');
        }
        try {
            $stmt = db()->prepare("DELETE FROM users WHERE id = :id");
            $stmt->execute([':id' => $id]);
            flash_set('ok', 'User deleted.');
        } catch (PDOException $e) {
            flash_set('error', 'Cannot delete: this user has rows that reference them (students, assessments, or tasks). Deactivate instead.');
        }
        redirect('/admin.php');
    }

    if ($op === 'settings_save') {
        $newName  = trim($_POST['app_name'] ?? '');
        $newShort = trim($_POST['app_short_name'] ?? '');
        if ($newName === '') {
            flash_set('error', 'App name cannot be empty.');
            redirect('/admin.php');
        }
        $upsert = db()->prepare("
            INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ");
        $upsert->execute([':k' => 'app_name',       ':v' => substr($newName,  0, 120)]);
        $upsert->execute([':k' => 'app_short_name', ':v' => substr($newShort, 0, 30)]);
        // Long-form school identity that substitutes for {school_name} inside
        // every WA template + intro line (migration 034). Falls back to
        // app_name() when blank.
        $crmName = trim((string)($_POST['crm_school_name'] ?? ''));
        $upsert->execute([':k' => 'crm_school_name', ':v' => substr($crmName, 0, 160)]);
        app_setting_clear_cache();
        // External app endpoints — admin can change these without a code
        // deploy. Empty string clears the integration.
        foreach (external_apps_registry() as $appKey => $appMeta) {
            $url = trim((string)($_POST[$appMeta['settings_key']] ?? ''));
            $upsert->execute([':k' => $appMeta['settings_key'], ':v' => substr($url, 0, 255)]);
        }
        // WACRM single-sign-on secret — must match the CRM's MTT_SSO_SECRET.
        $ssoSecret = trim((string)($_POST['wacrm_sso_secret'] ?? ''));
        $upsert->execute([':k' => 'wacrm_sso_secret', ':v' => substr($ssoSecret, 0, 128)]);
        app_setting_clear_cache();
        flash_set('ok', 'App settings saved. The new name appears everywhere on next page load.');
        redirect('/admin.php');
    }
}

$users = db()->query("
    SELECT id, name, role, modules, active, created_at
    FROM users
    ORDER BY (role='admin') DESC, name
")->fetchAll();

// At-a-glance: active students + fee totals. Dues are clamped per-student
// (mirroring students/fees_report.php) so over-payments don't offset others.
$activeStudents = (int)db()->query(
    "SELECT COUNT(*) FROM students WHERE COALESCE(is_active, 1) = 1"
)->fetchColumn();

$feeTotals = db()->query("
    SELECT
        COALESCE(SUM(billed), 0) AS billed,
        COALESCE(SUM(paid),   0) AS paid,
        COALESCE(SUM(GREATEST(billed - paid, 0)), 0) AS due
    FROM (
        SELECT s.id,
            COALESCE(SUM(CASE WHEN fi.status NOT IN ('cancelled','waived') THEN fi.amount ELSE 0 END), 0) AS billed,
            COALESCE((
                SELECT SUM(fp.amount)
                FROM fee_payments fp
                JOIN fee_invoices fi2 ON fi2.id = fp.invoice_id
                WHERE fi2.student_id = s.id
                  AND fi2.status NOT IN ('cancelled','waived')
            ), 0) AS paid
        FROM students s
        LEFT JOIN fee_invoices fi ON fi.student_id = s.id
        WHERE COALESCE(s.is_active, 1) = 1
        GROUP BY s.id
    ) t
")->fetch();
$feeBilled = (float)($feeTotals['billed'] ?? 0);
$feePaid   = (float)($feeTotals['paid']   ?? 0);
$feeDue    = (float)($feeTotals['due']    ?? 0);
$money     = fn(float $v) => '₹' . number_format($v, 2);

$pageTitle = 'Admin — Users';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <h1>Users &amp; access</h1>
    <p class="muted">Manage who can sign in and which modules they see.
        Module-specific admin lives inside each module
        (<a href="/assessment/admin.php">Assessment admin</a> ·
         <a href="/tasks/admin.php">Tasks admin</a>).</p>
</div>

<ul class="admin-tiles" role="list">
    <li>
        <a class="admin-tile" href="/students/index.php">
            <span class="tile-label">Active students</span>
            <span class="tile-value"><?= (int)$activeStudents ?></span>
            <span class="tile-sub">Tap to manage</span>
        </a>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Billed</span>
            <span class="tile-value"><?= e($money($feeBilled)) ?></span>
            <span class="tile-sub">All active students</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Paid</span>
            <span class="tile-value"><?= e($money($feePaid)) ?></span>
            <span class="tile-sub">Received so far</span>
        </div>
    </li>
    <li>
        <a class="admin-tile <?= $feeDue > 0 ? 'tile-warn' : 'tile-ok' ?>" href="/students/fees_report.php?status=due">
            <span class="tile-label">Dues</span>
            <span class="tile-value"><?= $feeDue > 0 ? e($money($feeDue)) : 'All settled' ?></span>
            <span class="tile-sub">Open balances</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/crm/index.php">
            <span class="tile-label">Admissions</span>
            <span class="tile-value">Pipeline</span>
            <span class="tile-sub">Inquiries &amp; revenue</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/crm/leads.php">
            <span class="tile-label">Leads</span>
            <span class="tile-value">Inbox</span>
            <span class="tile-sub">By campaign &amp; priority</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/crm/campaigns.php">
            <span class="tile-label">Campaigns</span>
            <span class="tile-value">Sources</span>
            <span class="tile-sub">Channels &amp; embed URLs</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/assessment/admin.php">
            <span class="tile-label">Assessment</span>
            <span class="tile-value">Admin</span>
            <span class="tile-sub">Indicators &amp; rating</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/tasks/admin.php">
            <span class="tile-label">Tasks</span>
            <span class="tile-value">Admin</span>
            <span class="tile-sub">Columns &amp; recurrences</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="/notifications.php">
            <span class="tile-label">Notifications</span>
            <span class="tile-value">Inbox</span>
            <span class="tile-sub">Recent activity</span>
        </a>
    </li>
    <li>
        <a class="admin-tile tile-nav" href="#app-settings">
            <span class="tile-label">App settings</span>
            <span class="tile-value">Configure</span>
            <span class="tile-sub">Display name &amp; more</span>
        </a>
    </li>
</ul>

<details id="app-settings" class="card card-form">
    <summary>App settings</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="settings_save">
        <div class="row">
            <div class="field" style="flex: 2 1 320px;">
                <label>Display name</label>
                <input name="app_name" value="<?= e(app_name()) ?>" maxlength="120" required>
                <span class="muted small">Appears in the top bar, the browser tab title and the login screen.</span>
            </div>
            <div class="field">
                <label>Short name</label>
                <input name="app_short_name" value="<?= e(app_short_name()) ?>" maxlength="30">
                <span class="muted small">Reserved for spaces where the long name doesn't fit. Currently unused on UI.</span>
            </div>
            <div class="field" style="flex: 2 1 100%;">
                <label>WhatsApp school name</label>
                <input name="crm_school_name" value="<?= e((string)app_setting('crm_school_name', '')) ?>" maxlength="160" placeholder="e.g. The Little Graduates Playschool, Kaloor, Kochi">
                <span class="muted small">Long-form identity that substitutes for <code>{school_name}</code> in every CRM WhatsApp template + intro. Blank falls back to the Display name above — but spelling out the location helps cold parents recognise who's writing.</span>
            </div>
        </div>
        <h3 style="margin:.8rem 0 .4rem;">External app URLs</h3>
        <p class="muted small" style="margin-top:0;">Each external integration appears as a tile on the home dashboard for users you've granted the matching module to. Set the URL here, then assign the module on a user.</p>
        <div class="row">
            <?php foreach (external_apps_registry() as $appKey => $appMeta): ?>
                <div class="field" style="flex: 1 1 280px;">
                    <label><?= e($appMeta['name']) ?> URL</label>
                    <input type="url" name="<?= e($appMeta['settings_key']) ?>"
                           value="<?= e(external_app_url($appKey)) ?>"
                           placeholder="https://…"
                           maxlength="255">
                    <span class="muted small"><?= e($appMeta['subtitle']) ?> — leave blank to hide the embed.</span>
                </div>
            <?php endforeach; ?>
        </div>
        <h3 style="margin:.8rem 0 .4rem;">WACRM single sign-on</h3>
        <p class="muted small" style="margin-top:0;">Paste the shared secret from the CRM server (its <code>MTT_SSO_SECRET</code>). When set, opening WACRM logs the user straight in — no second login. Leave blank to disable SSO.</p>
        <div class="row">
            <div class="field" style="flex: 2 1 360px;">
                <label>WACRM SSO secret</label>
                <input type="text" name="wacrm_sso_secret"
                       value="<?= e((string)app_setting('wacrm_sso_secret', '')) ?>"
                       placeholder="paste the secret from the CRM server"
                       maxlength="128" autocomplete="off" spellcheck="false">
                <span class="muted small">Must exactly match the CRM's <code>MTT_SSO_SECRET</code>. Only users with the WACRM module are synced.</span>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary" type="submit">Save</button>
        </div>
    </form>
</details>

<details class="card card-form" open>
    <summary>Add a user</summary>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="create">
        <div class="row">
            <div class="field">
                <label>Name</label>
                <input name="name" required maxlength="120" placeholder="e.g. Priya Sharma">
            </div>
            <div class="field">
                <label>PIN (4–6 digits)</label>
                <input name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6" required placeholder="e.g. 1234">
            </div>
            <div class="field">
                <label>Role</label>
                <select name="role">
                    <option value="teacher">Teacher</option>
                    <option value="admin">Admin</option>
                </select>
            </div>
            <div class="field">
                <label>Modules</label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="montessori" checked><span>Assessment</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="tasks"><span>Tasks</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="students"><span>Students</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="crm"><span>Admissions</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="recruitment"><span>Recruitment</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="staff"><span>Staff</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="expenses"><span>Expenses</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="fees"><span>Fees</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="logbook"><span>Logbook</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="inventory"><span>Inventory</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="wacrm"><span>WACRM</span></label>
                <label class="checkbox"><input type="checkbox" name="modules[]" value="n8n"><span>n8n</span></label>
            </div>
        </div>
        <div class="actions">
            <button class="btn btn-primary">Add user</button>
        </div>
    </form>
</details>

<h2 class="section-h-spaced">Active &amp; inactive</h2>

<?php foreach ($users as $u): ?>
    <form id="u-edit-<?= (int)$u['id'] ?>" method="post" hidden>
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="update">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
    <form id="u-del-<?= (int)$u['id'] ?>" method="post" hidden
          onsubmit="return confirm('Delete this user? Anything they created or are assigned to will block deletion — deactivate instead if so.')">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="delete">
        <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
    </form>
<?php endforeach; ?>

<ul class="team-list">
    <?php foreach ($users as $u):
        $fid     = 'u-edit-' . (int)$u['id'];
        $mods    = user_modules_from_row($u);
        $hasA    = in_array('montessori', $mods, true);
        $hasT    = in_array('tasks', $mods, true);
        $hasS    = in_array('students', $mods, true);
        $hasC    = in_array('crm', $mods, true);
        $hasR    = in_array('recruitment', $mods, true);
        $hasSt   = in_array('staff', $mods, true);
        $hasE    = in_array('expenses', $mods, true);
        $hasF    = in_array('fees', $mods, true);
        $hasL    = in_array('logbook', $mods, true);
        $hasInv  = in_array('inventory', $mods, true);
        $hasWa   = in_array('wacrm',     $mods, true);
        $hasN8n  = in_array('n8n',       $mods, true);
    ?>
        <li class="team-row" style="--card: <?= e(user_color((int)$u['id'])) ?>;">
            <div class="team-dot"><?= e(user_initials($u['name'])) ?></div>
            <div>
                <div class="team-name"><?= e($u['name']) ?></div>
                <div class="team-meta">
                    <?= e($u['role']) ?>
                    · <?= $u['active'] ? 'active' : 'inactive' ?>
                    <?php if ($u['role'] !== 'admin'): ?>
                        · modules: <?= $mods ? e(implode(', ', $mods)) : '<em>none</em>' ?>
                    <?php else: ?>
                        · <em>all modules (admin)</em>
                    <?php endif; ?>
                    · since <?= e(substr((string)$u['created_at'], 0, 10)) ?>
                </div>
            </div>
            <div class="team-edit">
                <input form="<?= $fid ?>" name="name" value="<?= e($u['name']) ?>" maxlength="120" aria-label="Name">
                <select form="<?= $fid ?>" name="role" aria-label="Role">
                    <option value="teacher" <?= $u['role'] === 'teacher' ? 'selected' : '' ?>>Teacher</option>
                    <option value="admin"   <?= $u['role'] === 'admin'   ? 'selected' : '' ?>>Admin</option>
                </select>
                <label class="checkbox" title="Assessment module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="montessori" <?= $hasA ? 'checked' : '' ?>>
                    <span>Assess</span>
                </label>
                <label class="checkbox" title="Tasks module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="tasks" <?= $hasT ? 'checked' : '' ?>>
                    <span>Tasks</span>
                </label>
                <label class="checkbox" title="Students module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="students" <?= $hasS ? 'checked' : '' ?>>
                    <span>Students</span>
                </label>
                <label class="checkbox" title="Admissions / CRM module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="crm" <?= $hasC ? 'checked' : '' ?>>
                    <span>Admiss.</span>
                </label>
                <label class="checkbox" title="Recruitment / staff hiring module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="recruitment" <?= $hasR ? 'checked' : '' ?>>
                    <span>Recruit</span>
                </label>
                <label class="checkbox" title="Staff management (attendance, leave, docs, messages)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="staff" <?= $hasSt ? 'checked' : '' ?>>
                    <span>Staff</span>
                </label>
                <label class="checkbox" title="Expenses module">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="expenses" <?= $hasE ? 'checked' : '' ?>>
                    <span>Expenses</span>
                </label>
                <label class="checkbox" title="Fees module (calculator + parent fee guide + CoFee enrollment)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="fees" <?= $hasF ? 'checked' : '' ?>>
                    <span>Fees</span>
                </label>
                <label class="checkbox" title="Logbook module (visitor, incident, observation, pickup logs)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="logbook" <?= $hasL ? 'checked' : '' ?>>
                    <span>Logbook</span>
                </label>
                <label class="checkbox" title="Inventory module (stock of materials, supplies, equipment)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="inventory" <?= $hasInv ? 'checked' : '' ?>>
                    <span>Inventory</span>
                </label>
                <label class="checkbox" title="WACRM — WhatsApp CRM workspace (external app)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="wacrm" <?= $hasWa ? 'checked' : '' ?>>
                    <span>WACRM</span>
                </label>
                <label class="checkbox" title="n8n — workflow automation (external app)">
                    <input form="<?= $fid ?>" type="checkbox" name="modules[]" value="n8n" <?= $hasN8n ? 'checked' : '' ?>>
                    <span>n8n</span>
                </label>
                <label class="checkbox" title="Active">
                    <input form="<?= $fid ?>" type="checkbox" name="active" value="1" <?= $u['active'] ? 'checked' : '' ?>>
                    <span>Active</span>
                </label>
                <input form="<?= $fid ?>" name="pin" inputmode="numeric" pattern="[0-9]{4,6}" maxlength="6"
                       placeholder="New PIN" aria-label="New PIN">
                <button class="btn" form="<?= $fid ?>">Save</button>
                <button class="link-btn" form="u-del-<?= (int)$u['id'] ?>">Delete</button>
            </div>
        </li>
    <?php endforeach; ?>
</ul>

<?php require __DIR__ . '/includes/footer.php'; ?>
