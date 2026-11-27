<?php
/** Called by the module-gated wrappers, before any external iframe or SSO. */
declare(strict_types=1);
if (!isset($appKey) || !in_array($appKey, ['wacrm', 'n8n'], true)) {
    http_response_code(404);
    exit;
}
require_once __DIR__ . '/../includes/app_workspace.php';
$meta = external_apps_registry()[$appKey];
$configured = app_workspace_external_url_valid(external_app_url($appKey), $appKey === 'wacrm');
$pageTitle = $meta['name'];
require __DIR__ . '/../includes/header.php';
?>
<div class="page-head">
    <div><h1><?= e($meta['name']) ?></h1><p class="muted"><?= e($meta['subtitle']) ?></p></div>
</div>
<?php if ($configured): ?>
    <p>Open <?= e($meta['name']) ?> in your browser. This school tool will stay here so you can return to it.</p>
    <p class="muted">Sign in if prompted.</p>
    <form method="post" action="/app/external.php" data-workspace-external data-user="<?= (int)$user['id'] ?>">
        <input type="hidden" name="app" value="<?= e($appKey) ?>">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <button class="btn btn-primary" type="submit">Open <?= e($meta['name']) ?> in browser</button>
        <p data-external-status role="status" aria-live="polite"></p>
    </form>
    <noscript>Enable JavaScript to open this integration.</noscript>
    <script src="/app/external.js?v=<?= e(asset_version()) ?>" defer></script>
<?php else: ?>
    <div class="empty"><p>This integration is not configured with a supported address. Ask an administrator to check it.</p></div>
<?php endif; ?>
<?php require __DIR__ . '/../includes/footer.php'; ?>
