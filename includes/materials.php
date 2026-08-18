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

/** Parse a php.ini shorthand size ('40M', '2G') to bytes. */
function mm_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;   // unlimited
    $n = (float)$v;
    switch (strtoupper(substr($v, -1))) {
        case 'G': $n *= 1024;   // fall through
        case 'M': $n *= 1024;
        case 'K': $n *= 1024;
    }
    return (int)$n;
}

/**
 * The size a media upload can ACTUALLY be: the app's cap bounded by the
 * server's php.ini limits. The client pre-checks against this so a teacher
 * gets "video too big" instantly instead of a dead upload — files silently
 * over post_max_size were a real photos-lost cause.
 */
function mm_effective_upload_limit(): int
{
    return min(
        MM_MEDIA_MAX_BYTES,
        mm_ini_bytes((string)ini_get('upload_max_filesize')),
        mm_ini_bytes((string)ini_get('post_max_size'))
    );
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
 * Latest media PER KIND per material — across ALL months, so every board row
 * can show both the last photo AND the last video/voice memo ever taken.
 * Returns [material_id => [kind => ['id' => …, 'uploaded_at' => …]]].
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
            SELECT c2.material_id, md2.kind, MAX(md2.id) AS max_id
            FROM mm_condition_media md2
            JOIN mm_condition_checks c2 ON c2.id = md2.check_id
            WHERE c2.material_id IN ($place)
            GROUP BY c2.material_id, md2.kind
        ) latest ON latest.max_id = md.id
    ");
    $st->execute($materialIds);
    $out = [];
    foreach ($st as $r) {
        $out[(int)$r['material_id']][$r['kind']] = ['id' => (int)$r['id'], 'uploaded_at' => $r['uploaded_at']];
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

/**
 * Materials whose condition implies replacement (a "damage" code) but which
 * are NOT flagged needs_replacement — an internally inconsistent state that's
 * always worth a second look, and specifically what a since-fixed autosave
 * race could produce (a damage condition saved with the replace flag
 * silently cleared). Surfaced on the dashboard with a one-click repair.
 */
function mm_inconsistent_flags(string $period): array
{
    $damageCodes = array_keys(array_filter(mm_conditions(), fn($c) => $c['suggests_replace']));
    if (!$damageCodes) return [];
    $place = implode(',', array_fill(0, count($damageCodes), '?'));
    $st = db()->prepare("
        SELECT m.id, m.name, m.location, c.condition_code
        FROM mm_condition_checks c
        JOIN mm_materials m ON m.id = c.material_id AND m.is_active = 1
        WHERE c.period = ? AND c.needs_replacement = 0
          AND c.condition_code IN ($place)
        ORDER BY m.location, m.sort_order, m.name
    ");
    $st->execute(array_merge([$period], $damageCodes));
    return $st->fetchAll();
}

/** Safe path segment for inside an export zip. */
function mm_zip_seg(string $s): string
{
    $s = preg_replace('/[^\w\s\-\.]+/u', '', $s) ?? '';
    $s = trim(preg_replace('/\s+/', ' ', $s) ?? '');
    return $s !== '' ? mb_substr($s, 0, 60) : 'unnamed';
}

/**
 * Build the month's full audit ZIP (replacement-list.csv + full-audit.csv +
 * media foldered by shelf/material). Returns the tmp file path — caller
 * streams or uploads it and unlinks it. Throws on failure.
 */
function mm_build_audit_zip(string $period): string
{
    if (!class_exists('ZipArchive')) {
        throw new RuntimeException('ZIP support is not available on this server.');
    }
    $tmp = tempnam(sys_get_temp_dir(), 'mmzip');
    $zip = new ZipArchive();
    if ($zip->open($tmp, ZipArchive::OVERWRITE) !== true) {
        throw new RuntimeException('Could not create the export file.');
    }

    // ---- full-audit.csv -----------------------------------------------------
    $rows = db()->prepare("
        SELECT m.location, m.name, c.condition_code, c.needs_replacement, c.replace_qty,
               c.notes, c.checked_at, u.name AS checked_by,
               (SELECT COUNT(*) FROM mm_condition_media md WHERE md.check_id = c.id) AS media_count
        FROM mm_condition_checks c
        JOIN mm_materials m ON m.id = c.material_id
        LEFT JOIN users u ON u.id = c.checked_by_user_id
        WHERE c.period = :p
        ORDER BY m.location, m.sort_order, m.name
    ");
    $rows->execute([':p' => $period]);
    $audit = $rows->fetchAll();

    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, ['Shelf', 'Material', 'Condition', 'Needs replacement', 'Qty', 'Notes', 'Checked by', 'Checked at', 'Photos/videos']);
    foreach ($audit as $r) {
        fputcsv($csv, [
            $r['location'], $r['name'], mm_condition_label($r['condition_code']),
            $r['needs_replacement'] ? 'YES' : 'no',
            $r['needs_replacement'] ? max(1, (int)$r['replace_qty']) : '',
            $r['notes'], $r['checked_by'] ?? 'Unknown', $r['checked_at'], (int)$r['media_count'],
        ]);
    }
    rewind($csv);
    $zip->addFromString('full-audit.csv', (string)stream_get_contents($csv));
    fclose($csv);

    // ---- replacement-list.csv -------------------------------------------------
    $csv = fopen('php://temp', 'r+');
    fputcsv($csv, ['Shelf', 'Material', 'Condition', 'Qty', 'Notes', 'Flagged by', 'Flagged on']);
    foreach ($audit as $r) {
        if (!$r['needs_replacement']) continue;
        fputcsv($csv, [
            $r['location'], $r['name'], mm_condition_label($r['condition_code']),
            max(1, (int)$r['replace_qty']), $r['notes'],
            $r['checked_by'] ?? 'Unknown', date('Y-m-d', strtotime($r['checked_at'])),
        ]);
    }
    rewind($csv);
    $zip->addFromString('replacement-list.csv', (string)stream_get_contents($csv));
    fclose($csv);

    // ---- media files, foldered by shelf/material ------------------------------
    $media = db()->prepare("
        SELECT md.stored_filename, md.kind, md.uploaded_at, md.mime_type,
               m.name AS material, m.location
        FROM mm_condition_media md
        JOIN mm_condition_checks c ON c.id = md.check_id
        JOIN mm_materials m ON m.id = c.material_id
        WHERE c.period = :p
        ORDER BY m.location, m.name, md.uploaded_at
    ");
    $media->execute([':p' => $period]);
    $dir  = mm_media_dir();
    $seen = [];
    $missing = 0;
    foreach ($media as $md) {
        $src = $dir . '/' . basename((string)$md['stored_filename']);
        if (!is_file($src)) { $missing++; continue; }
        $ext  = pathinfo($src, PATHINFO_EXTENSION);
        $base = 'media/' . mm_zip_seg($md['location']) . '/' . mm_zip_seg($md['material']) . '/'
              . $md['kind'] . '-' . date('Ymd-His', strtotime($md['uploaded_at']));
        $name = $base . '.' . $ext;
        $i = 2;
        while (isset($seen[$name])) { $name = $base . '-' . $i++ . '.' . $ext; }
        $seen[$name] = true;
        $zip->addFile($src, $name);
    }
    if ($missing > 0) {
        $zip->addFromString('MISSING-FILES.txt', "$missing media file(s) recorded in the database were not found on disk and are not in this export.\n");
    }

    $zip->close();
    return $tmp;
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

// ============================================================================
// Daily condition audit — a SEPARATE workflow from the monthly Kreedo
// replacement board above. Schema in sql/migrate_067_materials_daily.sql
// (mm_daily_checks). Reuses mm_materials + mm_conditions(); no replacement
// flag, no media — just "what did this material look like today".
//
// The "fresh every day, history never deleted" requirement falls out of the
// UNIQUE KEY (material_id, check_date) upsert design, exactly like the
// monthly board's UNIQUE KEY (material_id, period): a new date has zero
// rows until someone marks it, and every past date's rows stay forever.
// There is no explicit reset/clear action — the date changing IS the reset.
// ============================================================================

/** The audit date for a request: ?date=YYYY-MM-DD if a real calendar date, else today. */
function mm_daily_date(): string
{
    $d = (string)($_GET['date'] ?? $_POST['date'] ?? '');
    if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $d) && DateTime::createFromFormat('!Y-m-d', $d) !== false) {
        return $d;
    }
    return date('Y-m-d');
}

/** Human label for a date, e.g. "Tuesday, 18 August 2026". */
function mm_daily_date_label(string $date): string
{
    $d = DateTime::createFromFormat('!Y-m-d', $date);
    return $d ? $d->format('l, j F Y') : $date;
}

/**
 * Insert or update today's (or any date's) condition check for one material.
 * The date is the unit of record — re-marking the same material on the same
 * date edits that same row and re-stamps who/when; it never touches any
 * other date's row. Returns the check id.
 */
function mm_daily_save_check(int $materialId, string $date, string $condition, ?string $notes, int $userId): int
{
    if (!isset(mm_conditions()[$condition])) {
        throw new RuntimeException('Unknown condition code.');
    }
    $notes = $notes !== null ? trim($notes) : '';
    db()->prepare("
        INSERT INTO mm_daily_checks
            (material_id, check_date, condition_code, notes, checked_by_user_id, checked_at)
        VALUES (:m, :d, :c, :n, :u, NOW())
        ON DUPLICATE KEY UPDATE
            condition_code = VALUES(condition_code),
            notes = VALUES(notes),
            checked_by_user_id = VALUES(checked_by_user_id),
            checked_at = NOW()
    ")->execute([
        ':m' => $materialId, ':d' => $date, ':c' => $condition,
        ':n' => $notes !== '' ? $notes : null, ':u' => $userId,
    ]);
    $st = db()->prepare("SELECT id FROM mm_daily_checks WHERE material_id = :m AND check_date = :d");
    $st->execute([':m' => $materialId, ':d' => $date]);
    return (int)$st->fetchColumn();
}

/** Existing daily marks for a date, keyed by material_id — for change detection on bulk save. */
function mm_daily_existing(string $date): array
{
    $st = db()->prepare("SELECT * FROM mm_daily_checks WHERE check_date = :d");
    $st->execute([':d' => $date]);
    $out = [];
    foreach ($st as $r) $out[(int)$r['material_id']] = $r;
    return $out;
}

/**
 * Auto-computed summary for one date: total checked, a count per condition
 * category (using the existing mm_conditions() vocabulary — good / needing
 * attention / damaged / missing / anything else already in the system),
 * how many carried a note, and which staff completed the audit.
 */
function mm_daily_summary(string $date): array
{
    $totalActive = (int)db()->query("SELECT COUNT(*) FROM mm_materials WHERE is_active = 1")->fetchColumn();

    $st = db()->prepare("SELECT condition_code, COUNT(*) AS n FROM mm_daily_checks WHERE check_date = :d GROUP BY condition_code");
    $st->execute([':d' => $date]);
    $byCondition = [];
    foreach ($st as $r) $byCondition[$r['condition_code']] = (int)$r['n'];
    $checked = array_sum($byCondition);

    $byTone = ['ok' => 0, 'warn' => 0, 'bad' => 0];
    foreach ($byCondition as $code => $n) $byTone[mm_condition_tone($code)] = ($byTone[mm_condition_tone($code)] ?? 0) + $n;

    $ncSt = db()->prepare("SELECT COUNT(*) FROM mm_daily_checks WHERE check_date = :d AND notes IS NOT NULL AND notes <> ''");
    $ncSt->execute([':d' => $date]);
    $notesCount = (int)$ncSt->fetchColumn();

    $staffSt = db()->prepare("
        SELECT u.id, u.name, COUNT(*) AS n
        FROM mm_daily_checks c
        LEFT JOIN users u ON u.id = c.checked_by_user_id
        WHERE c.check_date = :d
        GROUP BY u.id, u.name
        ORDER BY n DESC
    ");
    $staffSt->execute([':d' => $date]);
    $staff = $staffSt->fetchAll();

    return [
        'date' => $date,
        'total_active' => $totalActive,
        'checked' => $checked,
        'pending' => max(0, $totalActive - $checked),
        'by_condition' => $byCondition,
        'by_tone' => $byTone,
        'notes_count' => $notesCount,
        'staff' => $staff,
    ];
}

/** Distinct dates that have at least one daily check, newest first. */
function mm_daily_dates_with_checks(int $limit = 365): array
{
    $st = db()->prepare("SELECT DISTINCT check_date FROM mm_daily_checks ORDER BY check_date DESC LIMIT :lim");
    $st->bindValue(':lim', $limit, PDO::PARAM_INT);
    $st->execute();
    return $st->fetchAll(PDO::FETCH_COLUMN);
}

/**
 * Filtered history rows across all dates — used by the history/report view.
 * $f may contain: date (YYYY-MM-DD), material (name search), condition,
 * category (location), staff (user id). All optional.
 */
function mm_daily_history(array $f): array
{
    $where  = ['1=1'];
    $params = [];
    if (!empty($f['date']))      { $where[] = 'c.check_date = :date';      $params[':date']  = $f['date']; }
    if (!empty($f['material']))  { $where[] = 'm.name LIKE :material';     $params[':material'] = '%' . $f['material'] . '%'; }
    if (!empty($f['condition'])) { $where[] = 'c.condition_code = :cond';  $params[':cond']  = $f['condition']; }
    if (!empty($f['category']))  { $where[] = 'm.location = :cat';         $params[':cat']   = $f['category']; }
    if (!empty($f['staff']))     { $where[] = 'c.checked_by_user_id = :staff'; $params[':staff'] = (int)$f['staff']; }
    $whereSql = implode(' AND ', $where);

    $st = db()->prepare("
        SELECT c.id, c.material_id, c.check_date, c.condition_code, c.notes, c.checked_at,
               m.name AS material, m.location AS category,
               u.id AS staff_id, u.name AS staff_name
        FROM mm_daily_checks c
        JOIN mm_materials m ON m.id = c.material_id
        LEFT JOIN users u ON u.id = c.checked_by_user_id
        WHERE $whereSql
        ORDER BY c.check_date DESC, m.location, m.sort_order, m.name
        LIMIT 2000
    ");
    $st->execute($params);
    return $st->fetchAll();
}

/**
 * Recurring-problem view: for each material, how many times in the last
 * $days days it was marked with a "warn" or "bad" tone condition — the
 * trend a staff member walking the floor can't see from one day alone.
 * Ordered worst first. Only materials with at least one such mark appear.
 */
function mm_daily_trend_materials(int $days = 90): array
{
    $badCodes  = array_keys(array_filter(mm_conditions(), fn($c) => $c['tone'] !== 'ok'));
    $worstOnly = array_keys(array_filter(mm_conditions(), fn($c) => $c['tone'] === 'bad'));
    if (!$badCodes) return [];
    $place      = implode(',', array_fill(0, count($badCodes), '?'));
    $worstPlace = implode(',', array_fill(0, count($worstOnly), '?'));
    $st = db()->prepare("
        SELECT m.id, m.name, m.location,
               COUNT(*) AS issue_count,
               MAX(c.check_date) AS last_seen,
               SUM(c.condition_code IN ($worstPlace)) AS bad_count
        FROM mm_daily_checks c
        JOIN mm_materials m ON m.id = c.material_id
        WHERE c.condition_code IN ($place)
          AND c.check_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY m.id, m.name, m.location
        ORDER BY issue_count DESC, last_seen DESC
    ");
    $st->execute(array_merge($worstOnly, $badCodes, [$days]));
    return $st->fetchAll();
}

/** Condition-issue counts by shelf/category over the last $days days. */
function mm_daily_trend_categories(int $days = 90): array
{
    $badCodes = array_keys(array_filter(mm_conditions(), fn($c) => $c['tone'] !== 'ok'));
    if (!$badCodes) return [];
    $place = implode(',', array_fill(0, count($badCodes), '?'));
    $st = db()->prepare("
        SELECT m.location AS category, COUNT(*) AS issue_count
        FROM mm_daily_checks c
        JOIN mm_materials m ON m.id = c.material_id
        WHERE c.condition_code IN ($place)
          AND c.check_date >= DATE_SUB(CURDATE(), INTERVAL ? DAY)
        GROUP BY m.location
        ORDER BY issue_count DESC
    ");
    $st->execute(array_merge($badCodes, [$days]));
    return $st->fetchAll();
}

/** Weekly issue counts over the last $weeks weeks — a simple "over time" trend. */
function mm_daily_trend_weekly(int $weeks = 8): array
{
    $badCodes = array_keys(array_filter(mm_conditions(), fn($c) => $c['tone'] !== 'ok'));
    if (!$badCodes) return [];
    $place = implode(',', array_fill(0, count($badCodes), '?'));
    $st = db()->prepare("
        SELECT YEARWEEK(c.check_date, 3) AS yw, MIN(c.check_date) AS week_start, COUNT(*) AS issue_count
        FROM mm_daily_checks c
        WHERE c.condition_code IN ($place)
          AND c.check_date >= DATE_SUB(CURDATE(), INTERVAL ? WEEK)
        GROUP BY yw
        ORDER BY yw ASC
    ");
    $st->execute(array_merge($badCodes, [$weeks]));
    return $st->fetchAll();
}
