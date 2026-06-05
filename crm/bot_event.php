<?php
/**
 * crm/bot_event.php — AUTHENTICATED conversation event from the n8n bot.
 *
 * Called on every inbound parent message after the bot's LLM has classified it.
 * Updates the lead's activity, optionally moves the pipeline stage (forward
 * only), handles the "not interested → ask reason → Lost" path, and returns the
 * destination stage's WhatsApp message for the bot to send inside the 24h
 * window. See docs/admissions-automation.md.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 * Body:  JSON {
 *          phone     (required)
 *          name?                     used only when creating a new lead
 *          intent    (required)      info | interested | wants_visit | ready
 *                                    | not_interested | unclear
 *          summary?                  short text logged as a touchpoint
 *          message?                  raw parent message (fallback for summary)
 *          reason?                   free-text reason (for not_interested step 2)
 *        }
 * Reply: JSON {
 *          ok, lead_id, intent, moved, from, to, from_label, to_label,
 *          ask_reason, reply_text   // message the bot should send (or null)
 *        }
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
$name    = trim((string) ($in['name'] ?? ''));
$intent  = strtolower(trim((string) ($in['intent'] ?? 'unclear')));
$summary = trim((string) ($in['summary'] ?? ($in['message'] ?? '')));
$reason  = trim((string) ($in['reason'] ?? ''));

if ($phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'phone required']);
    exit;
}

// intent → destination stage (forward-only). info/unclear never move.
$STAGE_FOR = [
    'interested'     => 'new',
    'wants_visit'    => 'tour_scheduled',
    'ready'          => 'offered',
    'not_interested' => 'lost',
];
$TERMINAL = ['enrolled', 'lost'];

try {
    $pdo = db();

    // 1. Find the most recent lead for this phone, else create one.
    $find = $pdo->prepare("SELECT id, primary_name, status FROM inquiry_families
                           WHERE primary_phone = :p ORDER BY created_at DESC LIMIT 1");
    $find->execute([':p' => $phone]);
    $lead = $find->fetch();

    if (!$lead) {
        $pdo->prepare("INSERT INTO inquiry_families
                (primary_name, primary_phone, status, priority, probability, source, last_inbound_at)
             VALUES (:n, :p, 'lead', 'normal', :prob, 'whatsapp_bot', NOW())")
            ->execute([
                ':n'    => substr($name !== '' ? $name : $phone, 0, 160),
                ':p'    => $phone,
                ':prob' => crm_default_probability('lead'),
            ]);
        $leadId  = (int) $pdo->lastInsertId();
        $current = 'lead';
        crm_audit_log('bot_lead_created', $leadId, ['intent' => $intent]);
    } else {
        $leadId  = (int) $lead['id'];
        $current = (string) $lead['status'];
    }

    // 2. Record activity — fresh inbound resets the "gone quiet" guard.
    $pdo->prepare("UPDATE inquiry_families
                   SET last_inbound_at = NOW(), quiet_reminded_at = NULL
                   WHERE id = :id")->execute([':id' => $leadId]);

    // 3. Log the message as a touchpoint (kind 'sms' = SMS / WhatsApp).
    if ($summary !== '') {
        $pdo->prepare("INSERT INTO inquiry_touchpoints (family_id, kind, occurred_at, body, created_by)
                       VALUES (:f, 'sms', NOW(), :b, NULL)")
            ->execute([':f' => $leadId, ':b' => mb_substr($summary, 0, 2000)]);
    }

    // 4. Resolve substitution vars for any outbound message.
    $vars = crm_wa_vars_for_families([$leadId])[$leadId] ?? ['parent_name' => '', 'child_name' => ''];
    if (($vars['parent_name'] ?? '') === '' && $name !== '') $vars['parent_name'] = $name;
    $vars = crm_wa_defaults($vars);

    $moved     = false;
    $askReason = false;
    $target    = $current;
    $replyText = null;

    // 5. Decide the move.
    if ($intent === 'not_interested') {
        if ($reason !== '') {
            // Step 2: capture the reason and mark Lost.
            $lr = crm_map_lost_reason($reason);
            $pdo->prepare("UPDATE inquiry_families SET status='lost', lost_reason=:lr, probability=0 WHERE id=:id")
                ->execute([':lr' => $lr, ':id' => $leadId]);
            crm_audit_log('bot_marked_lost', $leadId, ['from' => $current, 'reason' => $lr, 'note' => mb_substr($reason, 0, 200)]);
            $moved  = true;
            $target = 'lost';
            $replyText = crm_wa_substitute(
                'Thank you for letting us know, {parent_name}. We wish {child_name} all the best — '
                . 'and we are always here if you change your mind. 🌸', $vars);
        } elseif (!in_array($current, $TERMINAL, true)) {
            // Step 1: ask why before closing the lead.
            $askReason = true;
            $replyText = crm_wa_substitute(
                'No problem at all, {parent_name}. So we can keep improving — may I ask what '
                . 'made you decide not to proceed?', $vars);
        }
    } elseif (isset($STAGE_FOR[$intent]) && !in_array($current, $TERMINAL, true)) {
        $dest = $STAGE_FOR[$intent];
        if (crm_stage_rank($dest) > crm_stage_rank($current)) {
            $pdo->prepare("UPDATE inquiry_families SET status=:s, probability=:p WHERE id=:id")
                ->execute([':s' => $dest, ':p' => crm_default_probability($dest), ':id' => $leadId]);
            crm_audit_log('bot_stage_changed', $leadId, ['from' => $current, 'to' => $dest, 'intent' => $intent]);
            $moved  = true;
            $target = $dest;
            // The destination stage's message becomes the bot's reply (in-window).
            $wa = crm_stage_wa($dest);
            if ($wa['wa_text'] !== '') {
                $replyText = crm_wa_substitute($wa['wa_text'], $vars);
            }
        }
    }

    echo json_encode([
        'ok'         => true,
        'lead_id'    => $leadId,
        'intent'     => $intent,
        'moved'      => $moved,
        'from'       => $current,
        'to'         => $target,
        'from_label' => crm_status_label($current),
        'to_label'   => crm_status_label($target),
        'ask_reason' => $askReason,
        'reply_text' => $replyText,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'event_failed']);
}
