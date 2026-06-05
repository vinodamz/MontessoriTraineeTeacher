<?php
/**
 * crm/lead_summary.php — store/update the "WA Conversation Summary" for a lead.
 *
 * The n8n bot generates a short running summary of the WhatsApp conversation
 * and posts it here after each message; it's saved on the matching lead and
 * shown on the lead detail page.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 * Body:  JSON { phone, summary }
 * Reply: { ok, lead_id }
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

header('Content-Type: application/json');

$secret   = (string) app_setting('wacrm_sso_secret', '');
$provided = (string) ($_SERVER['HTTP_X_LEAD_SECRET'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

$in = json_decode((string) file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;
$phone   = trim((string) ($in['phone'] ?? ''));
$summary = trim((string) ($in['summary'] ?? ''));
$last10  = substr(preg_replace('/\D/', '', $phone), -10);

if ($last10 === '' || $summary === '') {
    http_response_code(400);
    echo json_encode(['error' => 'phone and summary required']);
    exit;
}

try {
    $pdo = db();
    // Same preference as bot_event: open lead, then most recent.
    $find = $pdo->prepare("
        SELECT id FROM inquiry_families
        WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
        ORDER BY (status NOT IN ('lost','enrolled')) DESC, created_at DESC
        LIMIT 1");
    $find->execute([':d' => $last10]);
    $leadId = (int) $find->fetchColumn();
    if ($leadId <= 0) {
        echo json_encode(['ok' => false, 'error' => 'no_lead']);
        exit;
    }
    $pdo->prepare("UPDATE inquiry_families SET wa_summary = :s, wa_summary_at = NOW() WHERE id = :id")
        ->execute([':s' => mb_substr($summary, 0, 2000), ':id' => $leadId]);
    echo json_encode(['ok' => true, 'lead_id' => $leadId], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'summary_failed']);
}
