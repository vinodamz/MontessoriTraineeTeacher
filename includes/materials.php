<?php
/**
 * materials.php — Montessori material inventory + monthly condition audit.
 *
 * The "Kreedo replacement" workflow: each month a teacher walks the shelves
 * and marks every material's condition, flags what needs replacing, and can
 * attach a photo/video. The replacement page then produces the list to send
 * to Kreedo. Schema in sql/migrate_039_materials.sql.
 */

/**
 * Condition codes, worst → best-ish, each with a label, a tone for the pill,
 * and whether it SUGGESTS replacement (used to pre-tick the box; the teacher
 * always has the final say).
 */
function mm_conditions(): array
{
    return [
        'good'       => ['label' => 'Good',                     'tone' => 'ok',   'suggests_replace' => false],
        'minor'      => ['label' => 'Minor damage',             'tone' => 'warn', 'suggests_replace' => false],
        'starting'   => ['label' => 'Wear starting',            'tone' => 'warn', 'suggests_replace' => false],
        'faded'      => ['label' => 'Colour faded',             'tone' => 'warn', 'suggests_replace' => false],
        'peeled'     => ['label' => 'Peeled off',               'tone' => 'warn', 'suggests_replace' => true],
        'mold_low'   => ['label' => 'Mould — low',              'tone' => 'warn', 'suggests_replace' => true],
        'mold_high'  => ['label' => 'Very high mould affected', 'tone' => 'bad',  'suggests_replace' => true],
        'broken'     => ['label' => 'Broken / parts missing',   'tone' => 'bad',  'suggests_replace' => true],
        'missing'    => ['label' => 'Item missing',             'tone' => 'bad',  'suggests_replace' => true],
        'bad'        => ['label' => 'Bad / unusable',           'tone' => 'bad',  'suggests_replace' => true],
    ];
}

function mm_condition_label(string $code): string
{
    return mm_conditions()[$code]['label'] ?? $code;
}

function mm_condition_tone(string $code): string
{
    return mm_conditions()[$code]['tone'] ?? 'neutral';
}

/** The audit period for a request: ?period=YYYY-MM if valid, else this month. */
function mm_current_period(): string
{
    $p = (string)($_GET['period'] ?? $_POST['period'] ?? '');
    if (preg_match('/^\d{4}-(0[1-9]|1[0-2])$/', $p)) return $p;
    return date('Y-m');
}

/** Human label for a 'YYYY-MM' period, e.g. "July 2026". */
function mm_period_label(string $period): string
{
    $d = DateTime::createFromFormat('!Y-m', $period);
    return $d ? $d->format('F Y') : $period;
}

/**
 * The last N periods (including this month), newest first, for the period
 * picker — plus any period that already has checks, so history stays reachable.
 */
function mm_period_options(int $back = 12): array
{
    $out = [];
    $d = new DateTime('first day of this month');
    for ($i = 0; $i < $back; $i++) {
        $out[$d->format('Y-m')] = true;
        $d->modify('-1 month');
    }
    foreach (db()->query("SELECT DISTINCT period FROM mm_condition_checks") as $r) {
        $out[$r['period']] = true;
    }
    $keys = array_keys($out);
    rsort($keys);
    return $keys;
}

/** Filesystem dir for condition media (gitignored, served via media.php). */
function mm_media_dir(): string
{
    $dir = realpath(__DIR__ . '/..') . '/uploads/material_media';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

const MM_MEDIA_MAX_BYTES = 40 * 1024 * 1024; // 40 MB — allows short phone clips
const MM_MEDIA_MIME_ALLOW = [
    'image/jpeg'      => ['photo', 'jpg'],
    'image/png'       => ['photo', 'png'],
    'image/webp'      => ['photo', 'webp'],
    'image/gif'       => ['photo', 'gif'],
    'image/heic'      => ['photo', 'heic'],
    'video/mp4'       => ['video', 'mp4'],
    'video/quicktime' => ['video', 'mov'],
    'video/webm'      => ['video', 'webm'],
    'video/3gpp'      => ['video', '3gp'],
];

/**
 * Store an uploaded photo/video against a condition check. Returns the new
 * mm_condition_media id, or null if no file was uploaded. Throws on bad type
 * or oversize.
 */
function mm_media_store(array $file, int $checkId, int $userId): ?int
{
    if (!isset($file['error']) || $file['error'] === UPLOAD_ERR_NO_FILE) return null;
    if ($file['error'] !== UPLOAD_ERR_OK) {
        throw new RuntimeException('Upload failed (code ' . (int)$file['error'] . ').');
    }
    if ((int)$file['size'] <= 0 || (int)$file['size'] > MM_MEDIA_MAX_BYTES) {
        throw new RuntimeException('File too large — max ' . format_bytes(MM_MEDIA_MAX_BYTES) . '.');
    }
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime  = (string)$finfo->file($file['tmp_name']);
    if (!isset(MM_MEDIA_MIME_ALLOW[$mime])) {
        throw new RuntimeException('Only photos (JPG/PNG/WebP/HEIC) or videos (MP4/MOV/WebM/3GP) are allowed.');
    }
    [$kind, $ext] = MM_MEDIA_MIME_ALLOW[$mime];
    $stored = 'mm_' . $checkId . '_' . bin2hex(random_bytes(8)) . '.' . $ext;
    $dest   = mm_media_dir() . '/' . $stored;
    if (!move_uploaded_file($file['tmp_name'], $dest)) {
        throw new RuntimeException('Could not move the uploaded file.');
    }
    @chmod($dest, 0644);

    db()->prepare("
        INSERT INTO mm_condition_media
            (check_id, kind, original_filename, stored_filename, mime_type, size_bytes, uploaded_by_user_id)
        VALUES (:c, :k, :o, :s, :m, :sz, :u)
    ")->execute([
        ':c' => $checkId, ':k' => $kind,
        ':o' => mb_substr((string)$file['name'], 0, 255),
        ':s' => $stored, ':m' => $mime, ':sz' => (int)$file['size'], ':u' => $userId,
    ]);
    return (int)db()->lastInsertId();
}

/** Best-effort unlink + row delete for a media item. */
function mm_media_delete(int $mediaId): void
{
    $st = db()->prepare("SELECT stored_filename FROM mm_condition_media WHERE id = :id");
    $st->execute([':id' => $mediaId]);
    $stored = $st->fetchColumn();
    if ($stored === false) return;
    db()->prepare("DELETE FROM mm_condition_media WHERE id = :id")->execute([':id' => $mediaId]);
    $p = mm_media_dir() . '/' . basename((string)$stored);
    if (is_file($p)) @unlink($p);
}

/**
 * Insert or update the condition check for (material, period). The month is
 * the unit of record — re-marking a material in the same month edits the same
 * row and re-stamps who/when. Returns the check id.
 *
 * $notes semantics: a string (even '') SETS the notes ('' clears them);
 * NULL means "keep whatever is already there" — callers that don't carry a
 * notes field (dashboard verdict edits, bulk saves) must not wipe a
 * teacher's note as a side effect.
 */
function mm_save_check(int $materialId, string $period, string $condition, bool $needsReplace, int $qty, ?string $notes, int $userId): int
{
    if (!isset(mm_conditions()[$condition])) {
        throw new RuntimeException('Unknown condition code.');
    }
    $keepNotes = $notes === null ? 1 : 0;
    db()->prepare("
        INSERT INTO mm_condition_checks
            (material_id, period, condition_code, needs_replacement, replace_qty, notes, checked_by_user_id, checked_at)
        VALUES (:m, :p, :c, :n, :q, :notes, :u, NOW())
        ON DUPLICATE KEY UPDATE
            condition_code = VALUES(condition_code),
            needs_replacement = VALUES(needs_replacement),
            replace_qty = VALUES(replace_qty),
            notes = IF(:keep = 1, notes, VALUES(notes)),
            checked_by_user_id = VALUES(checked_by_user_id),
            checked_at = NOW()
    ")->execute([
        ':m' => $materialId, ':p' => $period, ':c' => $condition,
        ':n' => $needsReplace ? 1 : 0, ':q' => max(0, $qty),
        ':notes' => ($notes !== null && $notes !== '') ? $notes : null,
        ':keep'  => $keepNotes, ':u' => $userId,
    ]);
    $st = db()->prepare("SELECT id FROM mm_condition_checks WHERE material_id = :m AND period = :p");
    $st->execute([':m' => $materialId, ':p' => $period]);
    return (int)$st->fetchColumn();
}
