<?php
/**
 * errors.php — friendly fatal-error handling (Phase 5 of the UX roadmap).
 *
 * Without this, an uncaught exception or PHP fatal surfaces as the
 * browser's bare "HTTP ERROR 500" — which parents holding form links
 * have already seen twice. With it:
 *
 *   - the full error still goes to the server error_log (unchanged), and
 *   - the visitor gets a small branded "Something went wrong" page.
 *
 * Loaded from the top of includes/auth.php so every entry point —
 * including the login-free parent pages, which require auth.php for
 * app_config() — gets the handlers without remembering to add them.
 */
declare(strict_types=1);

// Raw errors belong in the log, never in the response.
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e): void {
    error_log('Uncaught ' . get_class($e) . ': ' . $e->getMessage()
              . ' in ' . $e->getFile() . ':' . $e->getLine());
    lg_friendly_error_page();
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null
        && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        // PHP already wrote the fatal to error_log; we only fix the response.
        lg_friendly_error_page();
    }
});

/**
 * Emit the branded error page. Safe to call mid-render: if headers are
 * already out we can't change the status code, but appending the visible
 * message still beats a half-rendered page that just stops.
 */
function lg_friendly_error_page(): void
{
    if (PHP_SAPI === 'cli') return;

    static $shown = false;          // exception handler + shutdown can both fire
    if ($shown) return;
    $shown = true;

    if (!headers_sent()) {
        http_response_code(500);
        header('Content-Type: text/html; charset=utf-8');
    }
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
       . '<meta name="viewport" content="width=device-width, initial-scale=1">'
       . '<title>Something went wrong</title></head>'
       . '<body style="margin:0;font-family:-apple-system,BlinkMacSystemFont,\'Segoe UI\',Roboto,sans-serif;'
       . 'background:#fff5fa;color:#2b2b2b;display:flex;min-height:100vh;align-items:center;justify-content:center;">'
       . '<div style="text-align:center;padding:2rem;max-width:420px;">'
       . '<div style="font-size:3rem;">🐛</div>'
       . '<h1 style="color:#ad1457;font-size:1.3rem;margin:.5rem 0;">Something went wrong</h1>'
       . '<p style="color:#666;font-size:.95rem;">Sorry — this page hit a snag. It\'s been logged. '
       . 'Please try again in a minute, or contact the school if it keeps happening.</p>'
       . '<a href="javascript:location.reload()" style="display:inline-block;margin-top:.8rem;padding:.6rem 1.2rem;'
       . 'background:#e91e63;color:#fff;border-radius:6px;text-decoration:none;font-weight:600;">Try again</a>'
       . '</div></body></html>';
}
