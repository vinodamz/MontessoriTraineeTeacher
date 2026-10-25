<?php
declare(strict_types=1);

/** Only local school pages may be the destination of an app sign-in. */
function app_workspace_path(string $path): ?string
{
    if (strlen($path) > 2048 || preg_match('/[\x00-\x20\x7f\\\\#]/', $path)) return null;
    $parts = parse_url($path);
    if ($parts === false || isset($parts['host']) || isset($parts['scheme'])) return null;
    $page = $parts['path'] ?? '';
    if (!preg_match('~^/(?:[a-z_]+/)*[a-z_]+\.php$~D', $page)) return null;
    $dirs = ['transport', 'students', 'assessment', 'tasks', 'staff', 'crm', 'fees',
        'expenses', 'logbook', 'inventory', 'materials', 'plans', 'daycare',
        'recruitment', 'duties', 'files', 'surveys', 'feedback', 'wacrm', 'n8n'];
    $root = ['index.php', 'today.php', 'admin.php', 'grades.php', 'notifications.php',
        'notification_preferences.php', 'money.php', 'receipt.php', 'fees_calculator.php'];
    $first = explode('/', ltrim($page, '/'))[0];
    if (!in_array($first, $dirs, true) && !in_array(ltrim($page, '/'), $root, true)) return null;
    if (preg_match('/[\x00-\x1f\x7f]/', rawurldecode($parts['query'] ?? ''))) return null;
    return $path;
}

/** Revoking the originating API login also ends its workspace session. */
function app_workspace_token_valid(PDO $pdo, int $tokenId, int $userId): bool
{
    $st = $pdo->prepare('SELECT t.id FROM api_tokens t JOIN users u ON u.id = t.user_id WHERE t.id = :t AND t.user_id = :u AND t.revoked_at IS NULL AND u.active = 1');
    $st->execute([':t' => $tokenId, ':u' => $userId]);
    return (bool)$st->fetchColumn();
}

function app_workspace_error(string $message, int $status): never
{
    http_response_code($status);
    header('Content-Type: text/html; charset=utf-8');
    header('Cache-Control: no-store');
    header('Referrer-Policy: no-referrer');
    $safe = htmlspecialchars($message, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    $data = json_encode(['type' => 'lg-workspace', 'event' => 'error', 'message' => $message], JSON_HEX_TAG | JSON_HEX_AMP);
    echo '<!doctype html><html lang="en"><meta name="viewport" content="width=device-width,initial-scale=1"><title>School tool</title><body><p>' . $safe . '</p><script>const m=' . $data . ';parent.postMessage(m,"https://app.thelittlegraduates.in");if(window.LGWorkspace)LGWorkspace.postMessage(JSON.stringify(m));</script></body></html>';
    exit;
}
