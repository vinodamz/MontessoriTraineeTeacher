<?php
/**
 * materials/gdrive_push.php — build the month's audit ZIP and push it to the
 * school's Google Drive folder (service account, see includes/gdrive.php).
 *
 *   POST period=YYYY-MM  → uploads materials-audit-YYYY-MM.zip, flashes the
 *                          Drive link. GET shows setup instructions when the
 *                          integration isn't configured yet.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';
require_once __DIR__ . '/../includes/gdrive.php';

$user   = require_module('materials');
$period = mm_current_period();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    if (!gdrive_configured()) {
        flash_set('error', 'Google Drive is not set up yet — an admin needs to add the key below first.');
        redirect('/materials/gdrive_push.php?period=' . urlencode($period));
    }
    $tmp = null;
    try {
        @set_time_limit(300);
        $tmp  = mm_build_audit_zip($period);
        $name = 'materials-audit-' . $period . '.zip';
        $res  = gdrive_upload($tmp, $name, 'application/zip');
        flash_set('ok', 'Uploaded ' . $name . ' (' . format_bytes((int)filesize($tmp)) . ') to Google Drive ✓ — <a href="'
            . e($res['link']) . '" target="_blank" rel="noopener">open it in Drive</a>.');
    } catch (Throwable $e) {
        flash_set('error', 'Drive upload failed: ' . e($e->getMessage()));
    } finally {
        if ($tmp !== null && is_file($tmp)) @unlink($tmp);
    }
    redirect('/materials/dashboard.php?period=' . urlencode($period));
}

$configured = gdrive_configured();
$pageTitle  = 'Google Drive backup';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Google Drive backup</h1>
        <p class="muted">Pushes the month's full audit ZIP — report + every photo, video and voice memo — into the school's Drive folder.</p>
    </div>
    <div class="head-actions"><a class="btn btn-ghost" href="dashboard.php?period=<?= e($period) ?>">← Dashboard</a></div>
</div>

<?php if ($configured): ?>
<section class="card">
    <h2 style="margin-top:0;">Ready ✓</h2>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="period" value="<?= e($period) ?>">
        <p>This sends <strong>materials-audit-<?= e($period) ?>.zip</strong> to the configured Drive folder. Large months (many videos) can take a few minutes — leave this page open until the green confirmation appears.</p>
        <button class="btn btn-primary">Upload <?= e(mm_period_label($period)) ?> to Google Drive</button>
    </form>
</section>
<?php else: ?>
<section class="card">
    <h2 style="margin-top:0;">One-time setup (an admin does this once)</h2>
    <ol style="line-height:1.7;">
        <li>Go to <strong>console.cloud.google.com</strong> → create a project (any name).</li>
        <li>Search "Google Drive API" → <strong>Enable</strong> it.</li>
        <li>IAM &amp; Admin → <strong>Service Accounts</strong> → Create service account → then open it → <strong>Keys</strong> → Add key → <strong>JSON</strong>. A .json file downloads.</li>
        <li>In <strong>Google Drive</strong>, create a folder (e.g. "LG Material Audits"). Open the .json file and copy the <code>client_email</code> value — <strong>share the folder with that email</strong> as Editor.</li>
        <li>Copy the folder's ID from its URL (<code>drive.google.com/drive/folders/<strong>THIS-PART</strong></code>).</li>
        <li>Paste both into <a href="/admin.php">Admin → Settings → Google Drive</a> and save.</li>
    </ol>
    <p class="muted small">The key only grants access to files the app itself creates plus folders explicitly shared with it — it cannot read the rest of your Drive.</p>
</section>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
