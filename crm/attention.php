<?php
/**
 * crm/attention.php — AUTHENTICATED "leads needing attention" feed for n8n.
 *
 * A scheduled n8n workflow polls this every few hours and turns each row into
 * the three reminders (email + WhatsApp-to-you + CRM follow-up). After sending,
 * n8n POSTs the ids back with ?ack so the same reminder never repeats.
 * See docs/admissions-automation.md.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 *
 * GET   → {
 *   post_visit: [ {id, name, phone, status, visited_at, summary} ],   // visited ≥3 days, not yet reminded
 *   gone_quiet: [ {id, name, phone, status, last_inbound_at, summary} ] // open, no reply ≥3 days, not yet reminded
 * }
 * POST  body JSON { ack_post_visit?: [ids], ack_quiet?: [ids] }
 *       → marks post_visit_reminded_at / quiet_reminded_at = NOW() for those ids.
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

// Days thresholds (kept here so they're easy to tune).
$POST_VISIT_DAYS = 3;
$QUIET_DAYS      = 3;

/** Latest touchpoint body for a lead — used as the chat summary in reminders. */
function _attn_summary(PDO $pdo, int $leadId): string
{
    try {
        $s = $pdo->prepare("SELECT body FROM inquiry_touchpoints
                            WHERE family_id = :id AND body IS NOT NULL
                            ORDER BY occurred_at DESC, id DESC LIMIT 1");
        $s->execute([':id' => $leadId]);
        return (string) ($s->fetchColumn() ?: '');
    } catch (Throwable $e) {
        return '';
    }
}

try {
    $pdo = db();

    // ---- POST: acknowledge (mark reminded so it won't fire again) ----------
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        if (!is_array($in)) $in = [];
        $ackPost  = array_values(array_filter(array_map('intval', (array) ($in['ack_post_visit'] ?? []))));
        $ackQuiet = array_values(array_filter(array_map('intval', (array) ($in['ack_quiet'] ?? []))));

        $marked = 0;
        if ($ackPost) {
            $ph = implode(',', array_fill(0, count($ackPost), '?'));
            $st = $pdo->prepare("UPDATE inquiry_families SET post_visit_reminded_at = NOW() WHERE id IN ($ph)");
            $st->execute($ackPost);
            $marked += $st->rowCount();
        }
        if ($ackQuiet) {
            $ph = implode(',', array_fill(0, count($ackQuiet), '?'));
            $st = $pdo->prepare("UPDATE inquiry_families SET quiet_reminded_at = NOW() WHERE id IN ($ph)");
            $st->execute($ackQuiet);
            $marked += $st->rowCount();
        }
        echo json_encode(['ok' => true, 'marked' => $marked]);
        exit;
    }

    // ---- GET: leads needing each reminder ----------------------------------
    // Post-visit: marked visited ≥ N days ago, not reminded, still open.
    $pv = $pdo->prepare("
        SELECT id, primary_name, primary_phone, status, visited_at
        FROM inquiry_families
        WHERE status = 'school_visited'
          AND visited_at IS NOT NULL
          AND visited_at <= NOW() - INTERVAL :d DAY
          AND post_visit_reminded_at IS NULL
        ORDER BY visited_at ASC LIMIT 100");
    $pv->bindValue(':d', $POST_VISIT_DAYS, PDO::PARAM_INT);
    $pv->execute();
    $postVisit = [];
    foreach ($pv as $r) {
        $postVisit[] = [
            'id'         => (int) $r['id'],
            'name'       => (string) $r['primary_name'],
            'phone'      => (string) $r['primary_phone'],
            'status'     => (string) $r['status'],
            'visited_at' => (string) $r['visited_at'],
            'summary'    => _attn_summary($pdo, (int) $r['id']),
        ];
    }

    // Gone quiet: open lead, parent messaged before but not in the last N days.
    $gq = $pdo->prepare("
        SELECT id, primary_name, primary_phone, status, last_inbound_at
        FROM inquiry_families
        WHERE status NOT IN ('enrolled', 'lost', 'waitlisted')
          AND last_inbound_at IS NOT NULL
          AND last_inbound_at <= NOW() - INTERVAL :d DAY
          AND quiet_reminded_at IS NULL
        ORDER BY last_inbound_at ASC LIMIT 100");
    $gq->bindValue(':d', $QUIET_DAYS, PDO::PARAM_INT);
    $gq->execute();
    $goneQuiet = [];
    foreach ($gq as $r) {
        $goneQuiet[] = [
            'id'              => (int) $r['id'],
            'name'            => (string) $r['primary_name'],
            'phone'           => (string) $r['primary_phone'],
            'status'          => (string) $r['status'],
            'last_inbound_at' => (string) $r['last_inbound_at'],
            'summary'         => _attn_summary($pdo, (int) $r['id']),
        ];
    }

    echo json_encode([
        'ok'         => true,
        'post_visit' => $postVisit,
        'gone_quiet' => $goneQuiet,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'query_failed']);
}
