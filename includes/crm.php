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
 * The order here drives the column order on the kanban board.
 */
function crm_statuses(): array
{
    return [
        'lead'                  => ['label' => 'Leads',                 'prob' => 10, 'open' => true],
        'new'                   => ['label' => 'New inquiry',           'prob' => 20, 'open' => true],
        'tour_scheduled'        => ['label' => 'Tour scheduled',        'prob' => 45, 'open' => true],
        'application_submitted' => ['label' => 'Application submitted', 'prob' => 70, 'open' => true],
        'offered'               => ['label' => 'Offered',               'prob' => 85, 'open' => true],
        'enrolled'              => ['label' => 'Enrolled',              'prob' => 100, 'open' => false],
        'waitlisted'            => ['label' => 'Waitlisted',            'prob' => 25, 'open' => true],
        'lost'                  => ['label' => 'Lost',                  'prob' => 0,  'open' => false],
    ];
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

    $academicYear = function_exists('current_academic_year') ? current_academic_year() : null;
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
