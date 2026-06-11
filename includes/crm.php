<?php
/**
 * crm.php — Admissions / CRM domain helpers.
 *
 * Pipeline status definitions, default win-probability per stage, the
 * revenue-projection math, and the promote-to-student transition. No DB
 * schema lives here — see sql/migrate_009_crm.sql.
 */

/**
 * Ordered pipeline statuses with display labels and default win-probability.
 * Reads from the crm_stages table (active rows only), keyed by stage code
 * and ordered by display_order. The kanban board renders one column per
 * row returned here.
 *
 * Cached per-request via a static so kanban renders that loop the helper
 * don't hit the DB once per column.
 */
function crm_statuses(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;

    try {
        $rows = db()->query("
            SELECT code, label, probability, is_open
            FROM crm_stages
            WHERE is_active = 1
            ORDER BY display_order, id
        ")->fetchAll();
    } catch (Throwable $e) {
        // Table not yet created (fresh checkout, pre-migration) — fall back
        // to the historical hardcoded list so the app still loads.
        $rows = [];
    }

    if (!$rows) {
        $cache = [
            'lead'                  => ['label' => 'Leads',                 'prob' => 10, 'open' => true],
            'new'                   => ['label' => 'New inquiry',           'prob' => 20, 'open' => true],
            'tour_scheduled'        => ['label' => 'Tour scheduled',        'prob' => 45, 'open' => true],
            'application_submitted' => ['label' => 'Application submitted', 'prob' => 70, 'open' => true],
            'offered'               => ['label' => 'Offered',               'prob' => 85, 'open' => true],
            'enrolled'              => ['label' => 'Enrolled',              'prob' => 100, 'open' => false],
            'waitlisted'            => ['label' => 'Waitlisted',            'prob' => 25, 'open' => true],
            'lost'                  => ['label' => 'Lost',                  'prob' => 0,  'open' => false],
        ];
        return $cache;
    }

    $cache = [];
    foreach ($rows as $r) {
        $cache[$r['code']] = [
            'label' => $r['label'],
            'prob'  => (int)$r['probability'],
            'open'  => (bool)$r['is_open'],
        ];
    }
    return $cache;
}

/** Per-lead urgency, in display order. */
function crm_priorities(): array
{
    return [
        'urgent' => ['label' => 'Urgent', 'tone' => 'warn'],
        'high'   => ['label' => 'High',   'tone' => 'warn'],
        'normal' => ['label' => 'Normal', 'tone' => 'neutral'],
        'low'    => ['label' => 'Low',    'tone' => 'neutral'],
    ];
}

function crm_priority_label(string $code): string
{
    return crm_priorities()[$code]['label'] ?? $code;
}

/** Channels that a campaign can run through. */
function crm_channels(): array
{
    return [
        'walk_in'   => 'Walk-in',
        'referral'  => 'Referral',
        'website'   => 'Website',
        'instagram' => 'Instagram',
        'facebook'  => 'Facebook',
        'google'    => 'Google',
        'whatsapp'  => 'WhatsApp',
        'event'     => 'Event',
        'other'     => 'Other',
    ];
}

function crm_active_campaigns(): array
{
    return db()->query("
        SELECT id, name, channel FROM crm_campaigns
        WHERE active = 1 ORDER BY name
    ")->fetchAll();
}

function crm_status_label(string $code): string
{
    return crm_statuses()[$code]['label'] ?? $code;
}

function crm_default_probability(string $status): int
{
    return crm_statuses()[$status]['prob'] ?? 0;
}

/** Statuses still in the funnel (everything except enrolled/lost). */
function crm_open_statuses(): array
{
    return array_keys(array_filter(crm_statuses(), fn($s) => $s['open']));
}

/**
 * Pipeline statuses for the kanban board. Excludes 'lead' — leads live in
 * /crm/leads.php and only enter the board once explicitly promoted via the
 * "Add to pipeline" action on the inquiry detail page.
 */
function crm_pipeline_statuses(): array
{
    return array_filter(crm_statuses(), fn($_, $code) => $code !== 'lead', ARRAY_FILTER_USE_BOTH);
}

/**
 * Reasons a family can be "Lost" — shown as the dropdown that pops up
 * whenever a card is moved into the Lost column. Stored on
 * inquiry_families.lost_reason as the short code; the label here is what
 * the funnel report and the audit log show.
 *
 * Extend by appending entries — no schema change needed since lost_reason
 * is a free-form VARCHAR(40).
 */
function crm_lost_reasons(): array
{
    return [
        'too_expensive'      => 'Cost / fees too high',
        'distance'           => 'Distance / location',
        'chose_other_school' => 'Chose another school',
        'no_response'        => 'Stopped responding',
        'timing'             => 'Timing — not this year',
        'fit'                => 'Not the right fit',
        'duplicate'          => 'Duplicate / spam',
        'other'              => 'Other reason',
    ];
}

function crm_lost_reason_label(?string $code): string
{
    if ($code === null || $code === '') return '';
    return crm_lost_reasons()[$code] ?? $code;
}

function crm_touchpoint_kinds(): array
{
    return [
        'call'    => 'Phone call',
        'email'   => 'Email',
        'sms'     => 'SMS / WhatsApp',
        'meeting' => 'Meeting',
        'tour'    => 'Tour',
        'note'    => 'Note',
        'other'   => 'Other',
    ];
}

function crm_source_options(): array
{
    return ['Walk-in', 'Referral', 'Website', 'Instagram', 'Facebook', 'Google', 'Other'];
}

// ============================================================================
// Tags — short labels that the team attaches to inquiries. Filterable,
// visible on kanban cards, and feed the probability-rule engine.
// ============================================================================

/** All active tags, ordered. Cached per request. */
function crm_tags_active(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = db()->query("
            SELECT id, name, color FROM crm_tags
            WHERE is_active = 1 ORDER BY display_order, id
        ")->fetchAll();
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/** Tag IDs currently on a family. Returns int[] */
function crm_family_tag_ids(int $familyId): array
{
    try {
        $stmt = db()->prepare("SELECT tag_id FROM inquiry_family_tags WHERE family_id = :f");
        $stmt->execute([':f' => $familyId]);
        return $stmt->fetchAll(PDO::FETCH_COLUMN);
    } catch (Throwable $e) {
        return [];
    }
}

/** Batch-load tags for a set of family IDs. Returns [family_id => [{id,name,color},...]] */
function crm_tags_for_families(array $familyIds): array
{
    $familyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds))));
    if (!$familyIds) return [];
    $out = array_fill_keys($familyIds, []);
    try {
        $place = implode(',', array_fill(0, count($familyIds), '?'));
        $rows = db()->prepare("
            SELECT ft.family_id, t.id, t.name, t.color
            FROM inquiry_family_tags ft
            JOIN crm_tags t ON t.id = ft.tag_id
            WHERE ft.family_id IN ($place) AND t.is_active = 1
            ORDER BY t.display_order, t.id
        ");
        $rows->execute($familyIds);
        foreach ($rows as $r) {
            $out[(int)$r['family_id']][] = ['id' => (int)$r['id'], 'name' => $r['name'], 'color' => $r['color']];
        }
    } catch (Throwable $e) {}
    return $out;
}

/** Render tag pills HTML for a family (used on kanban cards + detail page). */
function crm_tag_pills(array $tags): string
{
    if (!$tags) return '';
    $html = '';
    foreach ($tags as $t) {
        $bg   = htmlspecialchars($t['color'], ENT_QUOTES);
        $name = htmlspecialchars($t['name'], ENT_QUOTES);
        $html .= '<span class="crm-tag-pill" style="background:' . $bg . ';">' . $name . '</span>';
    }
    return '<span class="crm-tag-group">' . $html . '</span>';
}

/**
 * Evaluate probability rules against an inquiry's current tags.
 * Returns the target_probability of the first matching rule (by
 * display_order), or null if no rule matches (leave probability manual).
 */
function crm_evaluate_probability_rules(array $familyTagIds): ?int
{
    try {
        $rules = db()->query("
            SELECT required_tag_ids, target_probability
            FROM crm_probability_rules
            WHERE is_active = 1
            ORDER BY display_order, id
        ")->fetchAll();
    } catch (Throwable $e) {
        return null;
    }
    if (!$rules) return null;

    $famSet = array_flip(array_map('intval', $familyTagIds));
    foreach ($rules as $rule) {
        $required = array_filter(array_map('intval', explode(',', (string)$rule['required_tag_ids'])));
        if (!$required) continue;
        $allPresent = true;
        foreach ($required as $tid) {
            if (!isset($famSet[$tid])) { $allPresent = false; break; }
        }
        if ($allPresent) {
            return max(0, min(100, (int)$rule['target_probability']));
        }
    }
    return null;
}

/**
 * After tags are added/removed on a family, recalculate its probability
 * based on the rule engine. If a rule matches, update the DB. If no rule
 * matches, leave the current probability untouched.
 */
function crm_recalculate_probability(int $familyId): void
{
    $tagIds = crm_family_tag_ids($familyId);
    $prob = crm_evaluate_probability_rules($tagIds);
    if ($prob !== null) {
        db()->prepare("UPDATE inquiry_families SET probability = :p WHERE id = :id")
            ->execute([':p' => $prob, ':id' => $familyId]);
    }
}

// ============================================================================
// WhatsApp templates — pre-written messages an admin manages from
// /crm/wa_templates.php. Click the WhatsApp pill on any inquiry → small
// picker shows active templates → pick one → wa.me opens with the
// substituted message pre-filled in the input box (the admin can still
// edit before tapping send).
// ============================================================================

/** Active templates ordered for the picker. Empty array if table missing. */
function crm_wa_templates_active(): array
{
    static $cache = null;
    if ($cache !== null) return $cache;
    try {
        $cache = db()->query("
            SELECT id, name, body
            FROM crm_wa_templates
            WHERE is_active = 1
            ORDER BY display_order, id
        ")->fetchAll();
    } catch (Throwable $e) {
        $cache = [];
    }
    return $cache;
}

/**
 * Substitute {placeholder} tokens in a template body.
 * Currently supported keys: parent_name, child_name, school_name, stage.
 * Unknown tokens are left as-is so the admin sees the gap on screen.
 */
function crm_wa_substitute(string $body, array $vars): string
{
    foreach ($vars as $k => $v) {
        $body = str_replace('{' . $k . '}', (string)$v, $body);
    }
    return $body;
}

/**
 * Find an existing lead by phone — format-proof (matches on the last 10
 * digits, so "+91 70289…", "9170289…" and "70289…" all resolve to the same
 * family). Prefers an open lead, then the most recent. The single source of
 * truth for "does this parent already exist?" — every ingest path must use it
 * so no entry point creates duplicates.
 */
function crm_find_lead_by_phone(string $phone): ?int
{
    $last10 = substr(preg_replace('/\D/', '', $phone), -10);
    if ($last10 === '') return null;
    try {
        $st = db()->prepare("
            SELECT id FROM inquiry_families
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
            ORDER BY (status NOT IN ('lost','enrolled')) DESC, created_at DESC
            LIMIT 1");
        $st->execute([':d' => $last10]);
        $id = $st->fetchColumn();
        return $id ? (int) $id : null;
    } catch (Throwable $e) {
        return null;
    }
}

/**
 * Per-stage WhatsApp message config (migrate_027). Drives the "Send via
 * WhatsApp CRM" button on each lead: the lead's current stage decides which
 * text/template gets sent. Returns blanks if unset or the columns are missing
 * (pre-migration), so callers never break.
 *
 * @return array{wa_text:string, wa_template:string, wa_template_lang:string}
 */
function crm_stage_wa(string $code): array
{
    $blank = ['wa_text' => '', 'wa_template' => '', 'wa_template_lang' => 'en_US'];
    if ($code === '') return $blank;
    try {
        $st = db()->prepare("
            SELECT wa_text, wa_template, wa_template_lang
            FROM crm_stages WHERE code = :c LIMIT 1
        ");
        $st->execute([':c' => $code]);
        $r = $st->fetch();
        if (!$r) return $blank;
        $lang = trim((string)($r['wa_template_lang'] ?? ''));
        return [
            'wa_text'          => (string)($r['wa_text'] ?? ''),
            'wa_template'      => trim((string)($r['wa_template'] ?? '')),
            'wa_template_lang' => $lang !== '' ? $lang : 'en_US',
        ];
    } catch (Throwable $e) {
        return $blank;
    }
}

/**
 * Document attachments (PDFs) configured for a stage (migrate_030). Returns a
 * list of ['link'=>, 'filename'=>, 'caption'=>]. Isolated in its own try/catch
 * so a missing wa_docs column never breaks crm_stage_wa() or the send button.
 */
function crm_stage_docs(string $code): array
{
    if ($code === '') return [];
    try {
        $st = db()->prepare("SELECT wa_docs FROM crm_stages WHERE code = :c LIMIT 1");
        $st->execute([':c' => $code]);
        $raw = (string) ($st->fetchColumn() ?: '');
        if ($raw === '') return [];
        $arr = json_decode($raw, true);
        if (!is_array($arr)) return [];
        $out = [];
        foreach ($arr as $d) {
            $link = trim((string) ($d['link'] ?? ''));
            if ($link === '') continue;
            $out[] = [
                'link'     => $link,
                'filename' => (string) ($d['filename'] ?? ''),
                'caption'  => (string) ($d['caption'] ?? ''),
            ];
        }
        return $out;
    } catch (Throwable $e) {
        return [];
    }
}

/**
 * True once migrate_027 has added the WhatsApp columns to crm_stages — used to
 * decide whether to show the "Send via WhatsApp CRM" button at all. Cached.
 */
function crm_stage_wa_ready(): bool
{
    static $ready = null;
    if ($ready !== null) return $ready;
    try {
        db()->query("SELECT wa_text FROM crm_stages LIMIT 1");
        $ready = true;
    } catch (Throwable $e) {
        $ready = false;
    }
    return $ready;
}

/**
 * Ordinal rank of a stage by its pipeline position (display_order), so the bot
 * can enforce forward-only auto-moves. Unknown/terminal codes return a sentinel
 * that keeps them from being auto-advanced. Cached.
 */
function crm_stage_rank(string $code): int
{
    static $ranks = null;
    if ($ranks === null) {
        $ranks = [];
        try {
            foreach (db()->query("SELECT code, display_order FROM crm_stages") as $r) {
                $ranks[$r['code']] = (int)$r['display_order'];
            }
        } catch (Throwable $e) {
            $ranks = [];
        }
    }
    return $ranks[$code] ?? -1;
}

/**
 * Fill blank WhatsApp substitution vars with friendly fallbacks so messages
 * never render gaps like "you and ." when a lead has no child name on file.
 */
function crm_wa_defaults(array $vars): array
{
    if (trim((string) ($vars['parent_name'] ?? '')) === '') $vars['parent_name'] = 'there';
    if (trim((string) ($vars['child_name'] ?? '')) === '')  $vars['child_name']  = 'your little one';
    if (trim((string) ($vars['school_name'] ?? '')) === '') $vars['school_name'] = app_name();
    return $vars;
}

/**
 * Map a free-text "why not interested" reply onto a crm_lost_reasons() key.
 * Falls back to 'other'. Keep the keyword lists lowercase.
 */
function crm_map_lost_reason(string $text): string
{
    $t = mb_strtolower(trim($text));
    if ($t === '') return 'no_response';
    $rules = [
        'too_expensive'      => ['expensive', 'costly', 'cost', 'fee', 'fees', 'budget', 'afford', 'price'],
        'distance'           => ['far', 'distance', 'location', 'commute', 'travel', 'away'],
        'chose_other_school' => ['another school', 'other school', 'already admitted', 'chose', 'joined', 'enrolled elsewhere'],
        'timing'             => ['next year', 'later', 'not this year', 'too early', 'not now', 'postpone'],
        'fit'                => ['not suitable', 'not the right', 'age', 'curriculum', 'not a fit', "doesn't fit"],
    ];
    foreach ($rules as $key => $words) {
        foreach ($words as $w) {
            if (mb_strpos($t, $w) !== false) return $key;
        }
    }
    return 'other';
}

/**
 * Pull per-family substitution vars in one batched query so the kanban
 * doesn't go N+1. Returns [family_id => ['parent_name' => …, 'child_name' => …]].
 */
function crm_wa_vars_for_families(array $familyIds): array
{
    $familyIds = array_values(array_unique(array_filter(array_map('intval', $familyIds))));
    if (!$familyIds) return [];

    $pdo = db();
    $place = implode(',', array_fill(0, count($familyIds), '?'));

    $out = [];
    foreach ($familyIds as $fid) {
        $out[$fid] = ['parent_name' => '', 'child_name' => ''];
    }

    // primary_name from inquiry_families as fallback parent name.
    $famRows = $pdo->prepare("SELECT id, primary_name FROM inquiry_families WHERE id IN ($place)");
    $famRows->execute($familyIds);
    foreach ($famRows as $r) {
        $out[(int)$r['id']]['parent_name'] = trim(explode(',', (string)$r['primary_name'])[0] ?: '');
    }

    // First-parent name (prefer is_primary) overrides primary_name.
    $pRows = $pdo->prepare("
        SELECT p.family_id, p.name
        FROM inquiry_parents p
        INNER JOIN (
            SELECT family_id, MIN(id) AS min_id
            FROM inquiry_parents
            WHERE family_id IN ($place)
            GROUP BY family_id
        ) m ON m.family_id = p.family_id AND m.min_id = p.id
    ");
    $pRows->execute($familyIds);
    foreach ($pRows as $r) {
        $name = trim((string)$r['name']);
        if ($name !== '') $out[(int)$r['family_id']]['parent_name'] = $name;
    }

    // First child's first_name.
    $kRows = $pdo->prepare("
        SELECT c.family_id, c.first_name
        FROM inquiry_children c
        INNER JOIN (
            SELECT family_id, MIN(id) AS min_id
            FROM inquiry_children
            WHERE family_id IN ($place)
            GROUP BY family_id
        ) m ON m.family_id = c.family_id AND m.min_id = c.id
    ");
    $kRows->execute($familyIds);
    foreach ($kRows as $r) {
        $out[(int)$r['family_id']]['child_name'] = trim((string)$r['first_name']);
    }

    return $out;
}

/**
 * Normalise a user-entered phone to digits-only, with the +91 country
 * code applied when the input looks like a local Indian number (10 or
 * 11-digit-starting-with-0). Returns '' if the input has fewer than
 * 10 digits (i.e. not a real phone number).
 *
 *   "+91 95670 36027" → "919567036027"
 *   "9567036027"      → "919567036027"
 *   "095670 36027"    → "919567036027"
 *   "44 20 7946 0958" → "442079460958"  (already international)
 *   "abc"             → ""
 */
function crm_phone_intl_digits(?string $phone): string
{
    $digits = preg_replace('/\D+/', '', (string)$phone);
    if ($digits === '' || strlen($digits) < 10) return '';
    if (strlen($digits) === 10) {
        return '91' . $digits;
    }
    if (strlen($digits) === 11 && $digits[0] === '0') {
        return '91' . substr($digits, 1);
    }
    return $digits;
}

/**
 * Render the phone number with two click actions next to it: a tel:
 * dialler link and a wa.me (WhatsApp) link. Returns an empty string if
 * the phone isn't a usable number, so callers can splat it unconditionally.
 *
 * When $familyId is provided, the wrapper carries data-inquiry-id so the
 * client-side audit script (assets/js/crm-phone-log.js) can sendBeacon
 * a 'phone_call_initiated' / 'whatsapp_initiated' event before the
 * browser navigates to the dialler / WhatsApp.
 *
 * Output is pre-escaped — the phone display is run through htmlspecialchars
 * before being injected.
 */
function crm_phone_actions(?string $phone, ?int $familyId = null, array $waVars = []): string
{
    $phone = trim((string)$phone);
    if ($phone === '') return '';
    $intl = crm_phone_intl_digits($phone);
    if ($intl === '') {
        // Not a valid phone — just show the text the user entered.
        return '<span class="phone-text">' . htmlspecialchars($phone, ENT_QUOTES) . '</span>';
    }
    $disp = htmlspecialchars($phone, ENT_QUOTES);
    $famAttr = $familyId ? ' data-inquiry-id="' . (int)$familyId . '"' : '';

    // WhatsApp template vars — wa-templates.js reads these to substitute
    // {parent_name} / {child_name} when the picker shows a template.
    $waAttrs = '';
    if ($familyId && $waVars) {
        $p = htmlspecialchars((string)($waVars['parent_name'] ?? ''), ENT_QUOTES);
        $c = htmlspecialchars((string)($waVars['child_name']  ?? ''), ENT_QUOTES);
        $waAttrs = ' data-wa-parent="' . $p . '" data-wa-child="' . $c . '"';
    }

    // "Save as contact" link — only render when we have a family_id since
    // the vCard endpoint can't build a meaningful name without it. The
    // endpoint itself logs the 'contact_saved' action, so no JS hook here.
    $saveLink = '';
    if ($familyId) {
        $saveLink = '<a class="phone-btn phone-btn-save" href="/crm/contact_vcard.php?id=' . (int)$familyId . '"'
                  . ' title="Save as contact (LG-parent-child-Enquiry.vcf)" aria-label="Save as contact"'
                  . ' download>Save</a>';
    }

    return
        '<span class="phone-actions"' . $famAttr . $waAttrs . '>'
            . '<a class="phone-text" href="tel:+' . $intl . '" title="Call ' . $disp . '">' . $disp . '</a>'
            . '<a class="phone-btn phone-btn-call" href="tel:+' . $intl . '" title="Call ' . $disp . '" aria-label="Call ' . $disp . '" data-audit-action="phone_call_initiated">Call</a>'
            . '<a class="phone-btn phone-btn-wa" href="https://wa.me/' . $intl . '" target="_blank" rel="noopener" title="WhatsApp ' . $disp . '" aria-label="WhatsApp ' . $disp . '" data-audit-action="whatsapp_initiated" data-wa-phone="' . $intl . '">WhatsApp</a>'
            . $saveLink
        . '</span>';
}

// ============================================================================
// Audit log — admin-visible activity feed for the admissions module.
// See migrate_017_crm_audit.sql. Logged actions are surfaced on
// /crm/view.php (per family) and /crm/audit.php (global).
// ============================================================================

/**
 * Whitelist of audit actions and their human-readable labels. Anything
 * outside this list is rejected by /crm/log_action.php so callers can't
 * pollute the feed with arbitrary action codes.
 */
function crm_audit_actions(): array
{
    return [
        'inquiry_created'       => 'Inquiry created',
        'inquiry_updated'       => 'Inquiry updated',
        'inquiry_deleted'       => 'Inquiry deleted',
        'status_changed'        => 'Status changed',
        'lead_qualified'        => 'Lead added to pipeline',
        'enrolled'              => 'Promoted to student',
        'touchpoint_added'      => 'Touchpoint added',
        'touchpoint_deleted'    => 'Touchpoint deleted',
        'tags_updated'          => 'Tags updated',
        'owner_changed'         => 'Owner reassigned',
        'phone_call_initiated'  => 'Phone call (Call button)',
        'whatsapp_initiated'    => 'WhatsApp opened',
        'contact_saved'         => 'Saved as contact (vCard)',
    ];
}

function crm_audit_action_label(string $code): string
{
    return crm_audit_actions()[$code] ?? $code;
}

/**
 * Append an entry to the admissions audit log. Never throws — if the
 * audit insert fails, the surrounding action still completes. The
 * caller passes the current user via $byUserId (so callers in CLI / cron
 * contexts can pass a system user id).
 */
function crm_audit_log(
    string $action,
    ?int $familyId = null,
    ?array $meta = null,
    ?string $targetType = null,
    ?int $targetId = null,
    ?int $byUserId = null
): void {
    try {
        if ($byUserId === null) {
            // Session keys are flat ($_SESSION['user_id']), not nested under
            // a 'user' array — see includes/auth.php where login.php sets them.
            $byUserId = $_SESSION['user_id'] ?? null;
        }
        $ip = $_SERVER['HTTP_X_FORWARDED_FOR'] ?? $_SERVER['REMOTE_ADDR'] ?? null;
        if ($ip) {
            // XFF can be a comma-separated list; take the first hop.
            $ip = substr(trim(explode(',', (string)$ip)[0]), 0, 45);
        }
        $ua = $_SERVER['HTTP_USER_AGENT'] ?? null;
        if ($ua) $ua = substr((string)$ua, 0, 255);
        $metaJson = $meta ? json_encode($meta, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) : null;

        db()->prepare("
            INSERT INTO inquiry_audit
                (family_id, user_id, action, target_type, target_id, meta_json, ip_address, user_agent)
            VALUES
                (:fam, :uid, :act, :tt, :tid, :mj, :ip, :ua)
        ")->execute([
            ':fam' => $familyId, ':uid' => $byUserId, ':act' => $action,
            ':tt'  => $targetType, ':tid' => $targetId,
            ':mj'  => $metaJson,   ':ip'  => $ip, ':ua' => $ua,
        ]);
    } catch (Throwable $e) {
        // Never block the main action because audit failed.
        error_log('crm_audit_log: ' . $e->getMessage());
    }
}

/**
 * Projected monthly revenue across the open funnel:
 *   weighted = Σ (probability/100 × expected_fee)
 *   pipeline = Σ expected_fee (raw total if everything closed)
 * Returns ['weighted' => float, 'pipeline' => float, 'count' => int].
 */
function crm_revenue_projection(): array
{
    $open = "'" . implode("','", crm_open_statuses()) . "'";
    $stmt = db()->query("
        SELECT probability, expected_fee
        FROM inquiry_families
        WHERE status IN ($open)
          AND expected_fee IS NOT NULL
    ");
    $weighted = 0.0; $pipeline = 0.0; $count = 0;
    foreach ($stmt as $r) {
        $fee  = (float)$r['expected_fee'];
        $prob = max(0, min(100, (int)$r['probability'])) / 100.0;
        $weighted += $fee * $prob;
        $pipeline += $fee;
        $count++;
    }
    return ['weighted' => $weighted, 'pipeline' => $pipeline, 'count' => $count];
}

/**
 * Promote selected children from an inquiry into real students. Returns the
 * list of new student IDs. Caller wraps in a transaction.
 *
 * $assignments is a [childId => ['grade' => 'Nursery', 'teacher_id' => 3]] map.
 * Children not in $assignments are skipped (e.g. only some kids enrolling).
 * Children with promoted_student_id already set are skipped (idempotent).
 *
 * All parents on the family are copied onto each new student so the
 * student_parents table reflects the whole family unit.
 */
function crm_promote_inquiry(int $familyId, array $assignments, int $byUserId): array
{
    $pdo = db();

    $fam = $pdo->prepare("SELECT * FROM inquiry_families WHERE id = :f");
    $fam->execute([':f' => $familyId]);
    $family = $fam->fetch();
    if (!$family) {
        throw new RuntimeException("Inquiry family $familyId not found.");
    }

    $kids = $pdo->prepare("SELECT * FROM inquiry_children WHERE family_id = :f");
    $kids->execute([':f' => $familyId]);
    $children = $kids->fetchAll();

    $rents = $pdo->prepare("SELECT * FROM inquiry_parents WHERE family_id = :f");
    $rents->execute([':f' => $familyId]);
    $parents = $rents->fetchAll();

    $insStudent = $pdo->prepare("
        INSERT INTO students
            (first_name, last_name, gender, dob, grade, teacher_id,
             joining_date, is_active, enrollment_status, academic_year)
        VALUES
            (:fn, :ln, :g, :dob, :grade, :tid,
             :join, 1, 'enrolled', :ay)
    ");
    $markPromoted = $pdo->prepare("
        UPDATE inquiry_children SET promoted_student_id = :sid WHERE id = :id
    ");
    $insParent = $pdo->prepare("
        INSERT INTO student_parents
            (student_id, relation, name, phone, email, occupation, is_primary)
        VALUES
            (:sid, :rel, :n, :ph, :em, :oc, :pri)
    ");

    // Pick the academic year from the family's expected_start when set —
    // a child being admitted in May 2026 for a June 2026 start belongs
    // to "2026-27", not the calendar-current "2025-26". When no
    // expected_start is on file, academic_year_for_start_date() falls
    // back to the latest year in use, which prefers the upcoming year.
    $academicYear = function_exists('academic_year_for_start_date')
        ? academic_year_for_start_date($family['expected_start'] ?? null)
        : (function_exists('current_academic_year') ? current_academic_year() : null);
    $newIds = [];

    foreach ($children as $kid) {
        if (!empty($kid['promoted_student_id']))            continue;
        if (!isset($assignments[(int)$kid['id']]))          continue;

        $a = $assignments[(int)$kid['id']];
        $grade   = $a['grade']      ?? $kid['target_grade'] ?? null;
        $teacher = (int)($a['teacher_id'] ?? 0);
        if (!$grade || !$teacher) {
            throw new RuntimeException("Each enrolling child needs a grade and a teacher.");
        }

        $insStudent->execute([
            ':fn'    => $kid['first_name'],
            ':ln'    => $kid['last_name'] ?: '',
            ':g'     => $kid['gender']    ?: null,
            ':dob'   => $kid['dob']       ?: null,
            ':grade' => $grade,
            ':tid'   => $teacher,
            ':join'  => $family['expected_start'] ?: date('Y-m-d'),
            ':ay'    => $academicYear,
        ]);
        $sid = (int)$pdo->lastInsertId();
        $newIds[] = $sid;
        $markPromoted->execute([':sid' => $sid, ':id' => $kid['id']]);

        foreach ($parents as $p) {
            $insParent->execute([
                ':sid' => $sid,
                ':rel' => $p['relation'],
                ':n'   => $p['name'],
                ':ph'  => $p['phone'] ?: null,
                ':em'  => $p['email'] ?: null,
                ':oc'  => $p['occupation'] ?: null,
                ':pri' => (int)$p['is_primary'],
            ]);
        }
    }

    if ($newIds) {
        $pdo->prepare("
            UPDATE inquiry_families
            SET status = 'enrolled', probability = 100, enrolled_at = NOW()
            WHERE id = :f
        ")->execute([':f' => $familyId]);
    }

    return $newIds;
}
