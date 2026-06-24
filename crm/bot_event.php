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
// 'whatsapp' (default) or 'web' — web events (e.g. fees-page views) move the
// pipeline but never trigger the WhatsApp intro and log as notes, not chats.
$channel = strtolower(trim((string) ($in['channel'] ?? 'whatsapp')));
// Lead attribution — website forms pass source='website'; default keeps bot intake.
$source  = strtolower(trim((string) ($in['source'] ?? 'whatsapp_bot')));
if (!in_array($source, ['whatsapp_bot', 'website'], true)) $source = 'whatsapp_bot';

if ($phone === '') {
    http_response_code(400);
    echo json_encode(['error' => 'phone required']);
    exit;
}

// intent → destination stage (forward-only). info/unclear never move.
// Mirrors the live pipeline: Leads → New → Details shared → Tour scheduled →
// School visited → Application submitted → Offered → Enrolled (+ Lost).
$STAGE_FOR = [
    'interested'     => 'new',
    'asked_details'  => 'details_shared',        // asked about fees/programmes/timings
    'wants_call'     => 'call_requested',         // tapped "Talk to us" / asked for a call
    'wants_visit'    => 'tour_scheduled',
    'ready'          => 'application_submitted',  // ready to admit / enrol
    'not_interested' => 'lost',
];
$TERMINAL = ['enrolled', 'lost'];

try {
    $pdo = db();

    // 1. Find an existing lead for this phone (format-proof: match on the last
    //    10 digits so "+91 70289…", "9170289…" and "70289…" all resolve to the
    //    same lead instead of creating duplicates). Prefer an open lead, then
    //    the most recent.
    $last10 = substr(preg_replace('/\D/', '', $phone), -10);
    $lead = false;
    if ($last10 !== '') {
        $find = $pdo->prepare("
            SELECT id, primary_name, status FROM inquiry_families
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
            ORDER BY (status NOT IN ('lost','enrolled')) DESC, created_at DESC
            LIMIT 1");
        $find->execute([':d' => $last10]);
        $lead = $find->fetch();
    }

    if (!$lead) {
        $pdo->prepare("INSERT INTO inquiry_families
                (primary_name, primary_phone, status, priority, probability, source, last_inbound_at)
             VALUES (:n, :p, 'lead', 'normal', :prob, :src, NOW())")
            ->execute([
                ':n'    => substr($name !== '' ? $name : $phone, 0, 160),
                ':p'    => $phone,
                ':prob' => crm_default_probability('lead'),
                ':src'  => $source,
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

    // 3. Log the message as a touchpoint ('sms' = WhatsApp chat; web events are notes).
    if ($summary !== '') {
        $pdo->prepare("INSERT INTO inquiry_touchpoints (family_id, kind, occurred_at, body, created_by)
                       VALUES (:f, :k, NOW(), :b, NULL)")
            ->execute([':f' => $leadId, ':k' => $channel === 'web' ? 'note' : 'sms',
                       ':b' => mb_substr($summary, 0, 2000)]);
    }

    // 4. Resolve substitution vars for any outbound message.
    $vars = crm_wa_vars_for_families([$leadId])[$leadId] ?? ['parent_name' => '', 'child_name' => ''];
    if (($vars['parent_name'] ?? '') === '' && $name !== '') $vars['parent_name'] = $name;
    $vars = crm_wa_defaults($vars);

    $moved     = false;
    $askReason = false;
    $target    = $current;
    $replyText = null;
    $documents = [];
    $intro     = false;

    // 5. Decide the move.
    if ($current === 'lead' && $channel !== 'web') {
        // First WhatsApp interaction with this lead — send the intro + buttons
        // and mark 'Intro sent'. n8n turns $intro into an interactive list menu.
        // Web events skip this: a fees-page view should never fake an intro;
        // their intent (asked_details) moves the lead directly instead.
        $dest = 'intro_sent';
        $pdo->prepare("UPDATE inquiry_families SET status=:s, probability=:p WHERE id=:id")
            ->execute([':s' => $dest, ':p' => crm_default_probability($dest), ':id' => $leadId]);
        crm_audit_log('bot_intro_sent', $leadId, ['from' => $current]);
        $moved  = true;
        $target = $dest;
        $intro  = true;
        $wa = crm_stage_wa($dest);
        if ($wa['wa_text'] !== '') {
            $replyText = crm_wa_substitute($wa['wa_text'], $vars);
        }
    } elseif ($intent === 'not_interested') {
        if ($reason !== '') {
            // Step 2: capture the reason. "Not this year" parents are a future
            // intake, not a lost lead — park them for next admissions season.
            $lr = crm_map_lost_reason($reason);
            if ($lr === 'timing') {
                $pdo->prepare("UPDATE inquiry_families SET status='future_intake', lost_reason=NULL, probability=:p WHERE id=:id")
                    ->execute([':p' => crm_default_probability('future_intake'), ':id' => $leadId]);
                crm_audit_log('bot_future_intake', $leadId, ['from' => $current, 'note' => mb_substr($reason, 0, 200)]);
                $moved  = true;
                $target = 'future_intake';
                $wa = crm_stage_wa('future_intake');
                $replyText = $wa['wa_text'] !== ''
                    ? crm_wa_substitute($wa['wa_text'], $vars)
                    : crm_wa_substitute('That\'s completely fine, {parent_name}. We\'ll reach out before next admissions open 😊', $vars);
            } else {
                $pdo->prepare("UPDATE inquiry_families SET status='lost', lost_reason=:lr, probability=0 WHERE id=:id")
                    ->execute([':lr' => $lr, ':id' => $leadId]);
                crm_audit_log('bot_marked_lost', $leadId, ['from' => $current, 'reason' => $lr, 'note' => mb_substr($reason, 0, 200)]);
                $moved  = true;
                $target = 'lost';
                $replyText = crm_wa_substitute(
                    'Thanks for telling us, {parent_name}. All the best to {child_name}, '
                    . 'and if you ever want to look again, just message here 🌸', $vars);
            }
        } elseif (!in_array($current, $TERMINAL, true)) {
            // Step 1: ask why before closing the lead.
            $askReason = true;
            $replyText = crm_wa_substitute(
                'No worries at all, {parent_name}. Just so we can do better, '
                . 'may I ask what held you back? Fees, distance, or something else?', $vars);
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
            // Any PDFs configured on this stage (e.g. Details shared → handbook).
            foreach (crm_stage_docs($dest) as $doc) {
                $documents[] = [
                    'link'     => $doc['link'],
                    'filename' => $doc['filename'],
                    'caption'  => crm_wa_substitute($doc['caption'], $vars),
                ];
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
        'documents'  => $documents,
        'intro'      => $intro,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'event_failed']);
}
