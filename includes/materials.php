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
    // Voice memos (MediaRecorder: Chrome/Android → webm/opus, iOS → mp4/AAC).
    'audio/webm'      => ['audio', 'weba'],
    'audio/ogg'       => ['audio', 'ogg'],
    'audio/mp4'       => ['audio', 'm4a'],
    'audio/mpeg'      => ['audio', 'mp3'],
    'audio/wav'       => ['audio', 'wav'],
    'audio/x-m4a'     => ['audio', 'm4a'],
    'audio/x-wav'     => ['audio', 'wav'],
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
        throw new RuntimeException('Only photos (JPG/PNG/WebP/HEIC), videos (MP4/MOV/WebM/3GP) or voice memos (WebM/M4A/MP3/WAV) are allowed.');
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

/**
 * Latest media item per material — across ALL months, so the board can always
 * show "how this material last looked" even before this month's photo exists.
 * Returns [material_id => ['id' => …, 'kind' => …, 'uploaded_at' => …]].
 */
function mm_latest_media(array $materialIds): array
{
    $materialIds = array_values(array_unique(array_filter(array_map('intval', $materialIds))));
    if (!$materialIds) return [];
    $place = implode(',', array_fill(0, count($materialIds), '?'));
    $st = db()->prepare("
        SELECT c.material_id, md.id, md.kind, md.uploaded_at
        FROM mm_condition_media md
        JOIN mm_condition_checks c ON c.id = md.check_id
        JOIN (
            SELECT c2.material_id, MAX(md2.id) AS max_id
            FROM mm_condition_media md2
            JOIN mm_condition_checks c2 ON c2.id = md2.check_id
            WHERE c2.material_id IN ($place)
            GROUP BY c2.material_id
        ) latest ON latest.max_id = md.id
    ");
    $st->execute($materialIds);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['material_id']] = ['id' => (int)$r['id'], 'kind' => $r['kind'], 'uploaded_at' => $r['uploaded_at']];
    }
    return $out;
}

/**
 * Evidence gaps for a month: materials flagged needs_replacement whose check
 * has NO photo/video — Kreedo asks for proof, so these need a picture before
 * the list goes out. Ordered by shelf.
 */
function mm_evidence_gaps(string $period): array
{
    $st = db()->prepare("
        SELECT m.id, m.name, m.location, c.condition_code, c.replace_qty, c.notes
        FROM mm_condition_checks c
        JOIN mm_materials m ON m.id = c.material_id AND m.is_active = 1
        WHERE c.period = :p AND c.needs_replacement = 1
          AND NOT EXISTS (SELECT 1 FROM mm_condition_media md WHERE md.check_id = c.id)
        ORDER BY m.location, m.sort_order, m.name
    ");
    $st->execute([':p' => $period]);
    return $st->fetchAll();
}

/**
 * Per-shelf priority for a month, most urgent first. Urgency = materials
 * still unchecked, then flagged-without-photo, then completion %. Each row:
 * location, total, checked, unchecked, flagged, gaps (flagged w/o photo).
 */
function mm_shelf_priorities(string $period): array
{
    $st = db()->prepare("
        SELECT m.location,
               COUNT(*) AS total,
               SUM(c.id IS NOT NULL) AS checked,
               SUM(c.id IS NULL) AS unchecked,
               SUM(COALESCE(c.needs_replacement, 0)) AS flagged,
               SUM(CASE WHEN c.needs_replacement = 1
                         AND NOT EXISTS (SELECT 1 FROM mm_condition_media md WHERE md.check_id = c.id)
                        THEN 1 ELSE 0 END) AS gaps
        FROM mm_materials m
        LEFT JOIN mm_condition_checks c ON c.material_id = m.id AND c.period = :p
        WHERE m.is_active = 1
        GROUP BY m.location
        ORDER BY MIN(m.sort_order)
    ");
    $st->execute([':p' => $period]);
    $rows = $st->fetchAll();
    usort($rows, function ($a, $b) {
        // Unfinished shelves first (most unchecked), then photo gaps,
        // then keep the natural shelf order (stable via location compare).
        $c = (int)$b['unchecked'] <=> (int)$a['unchecked'];
        if ($c !== 0) return $c;
        $c = (int)$b['gaps'] <=> (int)$a['gaps'];
        if ($c !== 0) return $c;
        return strnatcasecmp((string)$a['location'], (string)$b['location']);
    });
    return $rows;
}

// ---------- Thumbnails -------------------------------------------------------
// Phone photos are 3–8 MB; rendering them as 44px board thumbs / 150px gallery
// tiles is what made the pages crawl. We keep a 480px-max JPEG next to the
// original (uploads/material_media/thumbs/) generated on first request.

function mm_thumbs_dir(): string
{
    $dir = mm_media_dir() . '/thumbs';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/**
 * Filesystem path of the thumbnail for a stored photo, generating it on first
 * use. Returns null when a thumb can't be made (video/audio/HEIC/GD failure) —
 * caller falls back to the original file.
 */
function mm_thumb_for(string $storedFilename): ?string
{
    $src = mm_media_dir() . '/' . basename($storedFilename);
    if (!is_file($src)) return null;
    $dst = mm_thumbs_dir() . '/' . basename($storedFilename) . '.jpg';
    if (is_file($dst)) return $dst;

    $info = @getimagesize($src);
    if ($info === false) return null;
    [$w, $h] = $info;
    $img = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($src),
        IMAGETYPE_PNG  => @imagecreatefrompng($src),
        IMAGETYPE_GIF  => @imagecreatefromgif($src),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($src) : false,
        default        => false,
    };
    if (!$img) return null;

    $max = 480;
    $scale = min(1.0, $max / max($w, $h, 1));
    $tw = max(1, (int)round($w * $scale));
    $th = max(1, (int)round($h * $scale));
    $thumb = imagecreatetruecolor($tw, $th);
    // White background so transparent PNGs don't go black in JPEG.
    imagefill($thumb, 0, 0, imagecolorallocate($thumb, 255, 255, 255));
    imagecopyresampled($thumb, $img, 0, 0, 0, 0, $tw, $th, $w, $h);
    imagedestroy($img);
    $ok = imagejpeg($thumb, $dst, 78);
    imagedestroy($thumb);
    if (!$ok) return null;
    @chmod($dst, 0644);
    return $dst;
}

// ---------- Share links (Kreedo) ---------------------------------------------

/** Active share link row for a raw token, or null. */
function mm_share_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{64}$/', $token)) return null;
    $st = db()->prepare("SELECT * FROM mm_share_links WHERE token = :t AND is_active = 1");
    $st->execute([':t' => $token]);
    $row = $st->fetch();
    return $row ?: null;
}

/** Log one view of a shared page. */
function mm_share_log_view(int $shareId, string $viewerName): void
{
    db()->prepare("
        INSERT INTO mm_share_views (share_id, viewer_name, ip, user_agent)
        VALUES (:s, :n, :ip, :ua)
    ")->execute([
        ':s'  => $shareId,
        ':n'  => mb_substr(trim($viewerName), 0, 120),
        ':ip' => mb_substr((string)($_SERVER['REMOTE_ADDR'] ?? ''), 0, 45),
        ':ua' => mb_substr((string)($_SERVER['HTTP_USER_AGENT'] ?? ''), 0, 255),
    ]);
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
    $t = mm_thumbs_dir() . '/' . basename((string)$stored) . '.jpg';
    if (is_file($t)) @unlink($t);
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
