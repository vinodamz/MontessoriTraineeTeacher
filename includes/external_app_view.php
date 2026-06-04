<?php
/**
 * external_app_view.php — render an embedded external app inside the MTT
 * shell. Called by /wacrm/index.php and /n8n/index.php (and any future
 * external integration). The caller has already module-guarded the page
 * and set $appKey.
 */

declare(strict_types=1);

if (!isset($appKey)) {
    http_response_code(500); exit('external_app_view: $appKey not set.');
}
$reg = external_apps_registry();
if (!isset($reg[$appKey])) {
    http_response_code(404); exit('Unknown app.');
}
$meta = $reg[$appKey];
$url  = external_app_url($appKey);

// For WACRM, hand off via SSO so the embedded/opened CRM is already
// logged in. $frameUrl carries a fresh token for the iframe; $openUrl
// points at the launcher so the new-tab token is always fresh on click.
$frameUrl = $url;
$openUrl  = $url;
if ($appKey === 'wacrm' && $url !== '') {
    $frameUrl = wacrm_launch_url();
    $openUrl  = '/wacrm/index.php?launch=1';
}

$pageTitle  = $meta['name'];
$wideLayout = true;
require __DIR__ . '/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($meta['name']) ?></h1>
        <p class="muted"><?= e($meta['subtitle']) ?></p>
    </div>
    <?php if ($url !== ''): ?>
        <div class="actionbar">
            <a class="btn btn-primary" href="<?= e($openUrl) ?>" target="_blank" rel="noopener noreferrer">Open logged-in in new tab ↗</a>
        </div>
    <?php endif; ?>
</div>

<?php if ($url === ''): ?>
    <div class="empty">
        <p>This app hasn't been configured yet. Set <code><?= e($meta['settings_key']) ?></code> in
            <?php if (($user['role'] ?? '') === 'admin'): ?>
                <a href="/admin.php#app-settings">App settings</a> to point it at your hosted URL.
            <?php else: ?>
                App settings — ask an admin to configure it.
            <?php endif; ?>
        </p>
    </div>
<?php else: ?>
    <div class="external-frame-wrap">
        <iframe class="external-frame"
                src="<?= e($frameUrl) ?>"
                title="<?= e($meta['name']) ?>"
                referrerpolicy="no-referrer"
                allow="clipboard-read; clipboard-write"
                loading="lazy"></iframe>
        <p class="muted small" style="margin-top:.5rem;">
            If the panel above stays blank (some browsers block embedded logins), use
            <a href="<?= e($openUrl) ?>" target="_blank" rel="noopener noreferrer">Open logged-in in new tab ↗</a> instead.
        </p>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/footer.php'; ?>
