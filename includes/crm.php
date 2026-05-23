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
function crm_phone_actions(?string $phone, ?int $familyId = null): string
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
        '<span class="phone-actions"' . $famAttr . '>'
            . '<a class="phone-text" href="tel:+' . $intl . '" title="Call ' . $disp . '">' . $disp . '</a>'
            . '<a class="phone-btn phone-btn-call" href="tel:+' . $intl . '" title="Call ' . $disp . '" aria-label="Call ' . $disp . '" data-audit-action="phone_call_initiated">Call</a>'
            . '<a class="phone-btn phone-btn-wa" href="https://wa.me/' . $intl . '" target="_blank" rel="noopener" title="WhatsApp ' . $disp . '" aria-label="WhatsApp ' . $disp . '" data-audit-action="whatsapp_initiated">WhatsApp</a>'
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
