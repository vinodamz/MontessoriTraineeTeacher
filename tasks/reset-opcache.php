<?php
/**
 * /reset-opcache.php — admin-only. Hit this once after a deploy if a
 * fatal-error-on-old-line-number appears (which means PHP's opcache is
 * still serving stale bytecode for a file we've already replaced on disk).
 *
 * Safe to leave on the server: it does nothing without an admin session.
 */
require_once __DIR__ . '/../includes/auth.php';
require_admin();

header('Content-Type: text/plain; charset=utf-8');

if (function_exists('opcache_reset')) {
    $ok = opcache_reset();
    echo $ok ? "opcache_reset(): OK\n" : "opcache_reset(): failed (already cleared?)\n";
} else {
    echo "opcache_reset is not available on this PHP build.\n";
}
$status = function_exists('opcache_get_status') ? opcache_get_status(false) : null;
if ($status) {
    echo "Hits / misses: {$status['opcache_statistics']['hits']} / {$status['opcache_statistics']['misses']}\n";
    echo "Cached scripts: {$status['opcache_statistics']['num_cached_scripts']}\n";
}
