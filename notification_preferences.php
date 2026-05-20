<?php
/**
 * notification_preferences.php — per-user notification preferences.
 *
 *   GET  → show current toggles + email address (stored as app_setting
 *          key "email_for_user_{id}" so we don't need to alter users yet).
 *   POST → save.
 *
 * Each user manages their own preferences. Admins manage everyone's email
 * addresses too (via /admin.php's user-edit form in a follow-up — not in
 * this PR; for now admins just edit their own here).
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/notify.php';

$user = require_login();
$me   = (int)$user['id'];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $email      = trim($_POST['email'] ?? '');
    $emailOn    = !empty($_POST['email_enabled']) ? 1 : 0;
    $tasks      = !empty($_POST['tasks_enabled']) ? 1 : 0;
    $attendance = !empty($_POST['attendance_enabled']) ? 1 : 0;
    $fees       = !empty($_POST['fees_enabled']) ? 1 : 0;
    $students   = !empty($_POST['students_enabled']) ? 1 : 0;

    if ($email !== '' && !filter_var($email, FILTER_VALIDATE_EMAIL)) {
        flash_set('error', 'That email address looks invalid.');
        redirect('/notification_preferences.php');
    }

    try {
        $pdo = db();
        $pdo->prepare("
            INSERT INTO notification_preferences
                (user_id, email_enabled, tasks_enabled, attendance_enabled, fees_enabled, students_enabled)
            VALUES (:uid, :e, :t, :a, :f, :s)
            ON DUPLICATE KEY UPDATE
                email_enabled = VALUES(email_enabled),
                tasks_enabled = VALUES(tasks_enabled),
                attendance_enabled = VALUES(attendance_enabled),
                fees_enabled = VALUES(fees_enabled),
                students_enabled = VALUES(students_enabled)
        ")->execute([
            ':uid' => $me, ':e' => $emailOn,
            ':t' => $tasks, ':a' => $attendance, ':f' => $fees, ':s' => $students,
        ]);

        // Store the address in app_settings under a per-user key for now.
        $key = 'email_for_user_' . $me;
        $pdo->prepare("
            INSERT INTO app_settings (setting_key, setting_value) VALUES (:k, :v)
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)
        ")->execute([':k' => $key, ':v' => $email]);
        app_setting_clear_cache();

        flash_set('ok', 'Notification preferences saved.');
    } catch (Throwable $e) {
        flash_set('error', 'Could not save preferences: ' . $e->getMessage());
    }
    redirect('/notification_preferences.php');
}

// Load current prefs (with defaults for the seed case).
try {
    $stmt = db()->prepare("SELECT * FROM notification_preferences WHERE user_id = :id");
    $stmt->execute([':id' => $me]);
    $prefs = $stmt->fetch() ?: [];
} catch (Throwable $e) {
    $prefs = [];
}

$emailAddr = (string)app_setting('email_for_user_' . $me, '');

$pageTitle = 'Notification preferences';
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Notification preferences</h1>
        <p class="muted">How <?= e(app_name()) ?> reaches you. Changes apply to future notifications only.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/notifications.php">← Inbox</a>
    </div>
</div>

<form method="post" class="card card-form">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">

    <h2>Email</h2>
    <div class="row">
        <div class="field" style="flex: 2 1 320px;">
            <label>Your email address</label>
            <input type="email" name="email" value="<?= e($emailAddr) ?>" maxlength="120" placeholder="you@example.com">
            <span class="muted small">Leave blank to never receive notification emails. The sender address is set in <a href="/admin.php">Admin → App settings</a>.</span>
        </div>
        <div class="field">
            <label class="checkbox" style="margin-top: 1.5rem;">
                <input type="checkbox" name="email_enabled" value="1" <?= !empty($prefs['email_enabled']) ? 'checked' : '' ?>>
                <span>Send me emails</span>
            </label>
        </div>
    </div>

    <h2 class="section-h-spaced">Categories</h2>
    <p class="muted small">Mute whole categories without listing every event. In-app bell entries still appear but stop emailing.</p>
    <div class="row">
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="tasks_enabled" value="1" <?= !isset($prefs['tasks_enabled']) || $prefs['tasks_enabled'] ? 'checked' : '' ?>>
                <span>Tasks (assignments, due today, overdue)</span>
            </label>
        </div>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="attendance_enabled" value="1" <?= !isset($prefs['attendance_enabled']) || $prefs['attendance_enabled'] ? 'checked' : '' ?>>
                <span>Attendance (not marked, repeat absences)</span>
            </label>
        </div>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="fees_enabled" value="1" <?= !isset($prefs['fees_enabled']) || $prefs['fees_enabled'] ? 'checked' : '' ?>>
                <span>Fees (invoices, payments, dues)</span>
            </label>
        </div>
        <div class="field">
            <label class="checkbox">
                <input type="checkbox" name="students_enabled" value="1" <?= !isset($prefs['students_enabled']) || $prefs['students_enabled'] ? 'checked' : '' ?>>
                <span>Students (new admissions, withdrawals, assessment due)</span>
            </label>
        </div>
    </div>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit">Save preferences</button>
    </div>
</form>

<?php require __DIR__ . '/includes/footer.php'; ?>
