<?php
/**
 * crm/dedupe.php — archive duplicate leads for a phone (soft, reversible).
 *
 * Marks every lead matching the phone's last 10 digits EXCEPT the one to keep
 * as status='lost', lost_reason='duplicate'. No data is deleted — the rows stay
 * and can be reopened; they just leave the active pipeline.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 * POST   ?phone=<any format>&keep=<lead_id>
 * Reply: { ok, last10, kept, archived:[ids], count }
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

$phone  = (string) ($_GET['phone'] ?? $_POST['phone'] ?? '');
$keep   = (int) ($_GET['keep'] ?? $_POST['keep'] ?? 0);
$last10 = substr(preg_replace('/\D/', '', $phone), -10);
if ($last10 === '' || $keep <= 0) {
    http_response_code(400);
    echo json_encode(['error' => 'phone and keep (lead id) required']);
    exit;
}

try {
    $pdo = db();
    // Which leads will be archived (for the response).
    $sel = $pdo->prepare("
        SELECT id FROM inquiry_families
        WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
          AND id <> :keep AND status NOT IN ('lost', 'enrolled')");
    $sel->execute([':d' => $last10, ':keep' => $keep]);
    $ids = array_map('intval', $sel->fetchAll(PDO::FETCH_COLUMN));

    if ($ids) {
        $pdo->prepare("
            UPDATE inquiry_families
            SET status='lost', lost_reason='duplicate', probability=0
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
              AND id <> :keep AND status NOT IN ('lost', 'enrolled')")
            ->execute([':d' => $last10, ':keep' => $keep]);
        foreach ($ids as $aid) {
            crm_audit_log('lead_deduped', $aid, ['kept' => $keep, 'reason' => 'duplicate']);
        }
    }
    echo json_encode(['ok' => true, 'last10' => $last10, 'kept' => $keep,
                      'archived' => $ids, 'count' => count($ids)], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'dedupe_failed']);
}
