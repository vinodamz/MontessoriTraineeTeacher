<?php
/**
 * recruitment.php — Hiring pipeline domain helpers.
 *
 * Status table, eval dimensions, position list, and the hire-to-user
 * transition. No DB schema here — see sql/migrate_011_recruitment.sql.
 */

/**
 * Ordered pipeline statuses with display labels. Order drives the kanban
 * column order. 'open' marks stages still moving through the funnel.
 */
function recruit_statuses(): array
{
    return [
        'resume_received'  => ['label' => 'Resume received',   'open' => true],
        'screening'        => ['label' => 'Initial screening', 'open' => true],
        'demo'             => ['label' => 'Practical demo',    'open' => true],
        'background_check' => ['label' => 'Background check',  'open' => true],
        'offered'          => ['label' => 'Offered',           'open' => true],
        'hired'            => ['label' => 'Hired',             'open' => false],
        'rejected'         => ['label' => 'Rejected',          'open' => false],
        'withdrawn'        => ['label' => 'Withdrawn',         'open' => false],
    ];
}

function recruit_status_label(string $s): string
{
    return recruit_statuses()[$s]['label'] ?? $s;
}

function recruit_open_statuses(): array
{
    return array_keys(array_filter(recruit_statuses(), fn($s) => $s['open']));
}

function recruit_positions(): array
{
    return [
        'lead_teacher'      => 'Lead teacher',
        'assistant_teacher' => 'Assistant teacher',
        'caregiver'         => 'Caregiver',
        'admin_staff'       => 'Admin staff',
        'other'             => 'Other',
    ];
}

function recruit_position_label(string $code): string
{
    return recruit_positions()[$code] ?? $code;
}

function recruit_priorities(): array
{
    return [
        'urgent' => 'Urgent',
        'high'   => 'High',
        'normal' => 'Normal',
        'low'    => 'Low',
    ];
}

/** Soft-skill dimensions for the evaluation scorecard. */
function recruit_eval_dimensions(): array
{
    return [
        'care'                 => 'Care',
        'curiosity'            => 'Curiosity',
        'empathy'              => 'Empathy / Heart',
        'montessori_alignment' => 'Montessori alignment',
        'patience'             => 'Patience',
        'communication'        => 'Communication',
    ];
}

function recruit_recommendations(): array
{
    return [
        'strong_yes' => 'Strong yes',
        'yes'        => 'Yes',
        'maybe'      => 'Maybe',
        'no'         => 'No',
        'strong_no'  => 'Strong no',
    ];
}

function recruit_interview_stages(): array
{
    return [
        'screening'        => 'Screening call',
        'demo'             => 'Practical demo / observation',
        'background_check' => 'Background / reference check',
        'panel'            => 'Panel interview',
        'final'            => 'Final interview',
        'note'             => 'Note',
    ];
}

function recruit_attachment_kinds(): array
{
    return [
        'resume'        => 'Resume / CV',
        'certification' => 'Certification',
        'id_proof'      => 'ID proof',
        'reference'     => 'Reference letter',
        'other'         => 'Other',
    ];
}

/** Aggregate per-candidate scorecard: averages across all evaluators. */
function recruit_avg_scores(int $candidateId): array
{
    $stmt = db()->prepare("
        SELECT
            AVG(care)                 AS care,
            AVG(curiosity)            AS curiosity,
            AVG(empathy)              AS empathy,
            AVG(montessori_alignment) AS montessori_alignment,
            AVG(patience)             AS patience,
            AVG(communication)        AS communication,
            COUNT(*)                  AS evaluators
        FROM recruit_evaluations
        WHERE candidate_id = :c
    ");
    $stmt->execute([':c' => $candidateId]);
    $row = $stmt->fetch();
    return $row ?: ['evaluators' => 0];
}

/** Absolute filesystem path to a candidate's upload dir. Created on demand. */
function recruit_docs_dir(int $candidateId): string
{
    $base = realpath(__DIR__ . '/..') . '/uploads/recruit_docs';
    if (!is_dir($base)) @mkdir($base, 0755, true);
    $dir = "$base/$candidateId";
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

const RECRUIT_DOC_MAX_BYTES  = 8 * 1024 * 1024; // 8 MB
const RECRUIT_DOC_MIME_ALLOW = [
    'application/pdf'                                                            => 'pdf',
    'application/msword'                                                         => 'doc',
    'application/vnd.openxmlformats-officedocument.wordprocessingml.document'    => 'docx',
    'image/jpeg'                                                                 => 'jpg',
    'image/png'                                                                  => 'png',
];

/**
 * Validate + persist one $_FILES[...] entry as a candidate attachment.
 * Throws on validation/IO failure so callers can flash + redirect. The kind
 * defaults to 'resume' since this is the common case (upload-on-create).
 */
function recruit_save_uploaded_attachment(int $candidateId, array $file, int $byUserId, string $kind = 'resume'): int
{
    if (!array_key_exists($kind, recruit_attachment_kinds())) $kind = 'resume';
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new RuntimeException('upload error ' . ($file['error'] ?? '?'));
    }
    if ((int)($file['size'] ?? 0) > RECRUIT_DOC_MAX_BYTES) {
        throw new RuntimeException('file too large (8 MB max)');
    }
    $mime = sniff_mime_type($file['tmp_name']);
    if ($mime === null || !isset(RECRUIT_DOC_MIME_ALLOW[$mime])) {
        throw new RuntimeException('file type not allowed');
    }
    $ext    = RECRUIT_DOC_MIME_ALLOW[$mime];
    $dir    = recruit_docs_dir($candidateId);
    $stored = bin2hex(random_bytes(12)) . '.' . $ext;
    if (!move_uploaded_file($file['tmp_name'], "$dir/$stored")) {
        throw new RuntimeException('failed to move uploaded file');
    }
    $stmt = db()->prepare("
        INSERT INTO recruit_attachments
            (candidate_id, kind, original_name, stored_name, mime_type, size_bytes, uploaded_by)
        VALUES (:c, :k, :o, :s, :m, :z, :u)
    ");
    $stmt->execute([
        ':c' => $candidateId,
        ':k' => $kind,
        ':o' => substr((string)($file['name'] ?? 'file'), 0, 255),
        ':s' => $stored,
        ':m' => $mime,
        ':z' => (int)($file['size'] ?? 0),
        ':u' => $byUserId,
    ]);
    return (int)db()->lastInsertId();
}

/**
 * Promote a candidate into a users row (role=teacher, active=0). Idempotent —
 * if promoted_user_id is already set, returns that user id. Caller wraps in
 * a transaction.
 *
 * The created user has no usable PIN. An admin finishes onboarding from
 * /admin.php by setting a PIN and the modules SET. Mirrors how
 * crm_promote_inquiry hands off children to the students module: this module
 * decides "they're in", the admin handles the access-control details.
 */
function recruit_promote_to_staff(int $candidateId, int $byUserId, array $opts = []): int
{
    $pdo = db();

    $stmt = $pdo->prepare("SELECT * FROM recruit_candidates WHERE id = :id");
    $stmt->execute([':id' => $candidateId]);
    $cand = $stmt->fetch();
    if (!$cand) {
        throw new RuntimeException("Candidate $candidateId not found.");
    }
    if (!empty($cand['promoted_user_id'])) {
        return (int)$cand['promoted_user_id'];
    }

    $fullName = trim(($cand['first_name'] ?? '') . ' ' . ($cand['last_name'] ?? ''));
    if ($fullName === '') {
        throw new RuntimeException("Candidate has no name — cannot create a user row.");
    }
    $role = ($opts['role'] ?? 'teacher') === 'admin' ? 'admin' : 'teacher';

    // Unusable bcrypt hash so password_verify() never matches until an admin
    // sets a real PIN. active=0 keeps login gated until then.
    $unusableHash = password_hash(bin2hex(random_bytes(16)), PASSWORD_BCRYPT);

    $insUser = $pdo->prepare("
        INSERT INTO users (name, pin_hash, role, modules, active)
        VALUES (:n, :p, :r, '', 0)
    ");
    $insUser->execute([':n' => $fullName, ':p' => $unusableHash, ':r' => $role]);
    $newUserId = (int)$pdo->lastInsertId();

    $pdo->prepare("
        UPDATE recruit_candidates
        SET status = 'hired', promoted_user_id = :uid, hired_at = NOW()
        WHERE id = :id
    ")->execute([':uid' => $newUserId, ':id' => $candidateId]);

    return $newUserId;
}
