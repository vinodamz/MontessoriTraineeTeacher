<?php
/**
 * n8n/index.php — landing page for the n8n external integration.
 *
 * Embeds the URL stored in app_settings.n8n_url. Access gated by the
 * `n8n` module — admin assigns it on /admin.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_module('n8n');
$appKey = 'n8n';
require __DIR__ . '/../includes/external_app_view.php';
