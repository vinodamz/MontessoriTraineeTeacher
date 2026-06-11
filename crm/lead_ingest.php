<?php
/**
 * crm/lead_ingest.php — AUTHENTICATED lead ingest for the WhatsApp CRM.
 *
 * The WACRM bot pushes a lead here when a parent shows intent (Fees /
 * Tour / Talk-to-a-human). Unlike the public lead_submit.php form, this
 * is server-to-server with a shared secret and NO per-IP rate limit.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 *        (same shared secret used for WACRM SSO).
 * Body:  JSON { name, phone, email?, message?, source? }
 * Effect: creates an inquiry_families row (status='lead'), or, if a lead
 *         for the same phone exists in the last 14 days, appends the new
 *         message to it (dedupe — repeated bot intents don't spam).
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

$name   = trim((string) ($in['name'] ?? ''));
$phone  = trim((string) ($in['phone'] ?? ''));
$email  = trim((string) ($in['email'] ?? ''));
$msg    = trim((string) ($in['message'] ?? ''));
$source = trim((string) ($in['source'] ?? 'whatsapp_bot'));

if ($name === '' && $phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'name or phone required']);
    exit;
}
if ($name === '') $name = $phone;

try {
    // Dedupe: any existing lead for this phone (format-proof, no time window)
    // gets updated — never duplicated.
    $existing = $phone !== '' ? crm_find_lead_by_phone($phone) : null;
    if ($existing) {
        db()->prepare("
            UPDATE inquiry_families
            SET notes = CONCAT(COALESCE(notes, ''), '\n', :m),
                primary_email = COALESCE(primary_email, :e)
            WHERE id = :id
        ")->execute([
            ':m'  => '[' . date('Y-m-d H:i') . '] ' . ($msg !== '' ? $msg : $source),
            ':e'  => ($email !== '' ? $email : null),
            ':id' => $existing,
        ]);
        echo json_encode(['ok' => true, 'deduped' => true, 'id' => (int) $existing]);
        exit;
    }

    db()->prepare("
        INSERT INTO inquiry_families
            (primary_name, primary_phone, primary_email, status, priority, probability, source, notes)
        VALUES (:n, :p, :e, 'lead', 'normal', :prob, :s, :msg)
    ")->execute([
        ':n'    => substr($name, 0, 160),
        ':p'    => ($phone !== '' ? $phone : null),
        ':e'    => ($email !== '' ? $email : null),
        ':prob' => crm_default_probability('lead'),
        ':s'    => substr($source, 0, 60),
        ':msg'  => ($msg !== '' ? $msg : null),
    ]);
    echo json_encode(['ok' => true, 'id' => (int) db()->lastInsertId()]);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'insert_failed']);
}
