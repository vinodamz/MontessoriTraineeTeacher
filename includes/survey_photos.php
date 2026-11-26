<?php
/**
 * includes/survey_photos.php — photo answers on a parent survey.
 *
 * A `photo` question lets a parent upload one image (the child's latest
 * photo, on a field-trip consent form). The bytes are not stored in the
 * database and are not served as a static file:
 *
 *   - The response JSON holds only a reference: file, mime, size, original
 *     name. `file` is a random 128-bit name, never the parent's filename
 *     and never a path.
 *   - Bytes live in uploads/survey_photos/. That directory is inside the
 *     deployed tree (cPanel rsyncs the app into the docroot), so it is
 *     locked with the same deny rules as every other upload folder:
 *     uploads/.htaccess plus uploads/survey_photos/.htaccess. LiteSpeed
 *     honours both. Staff read the file only through surveys/photo.php,
 *     which requires the same admin login as the responses grid.
 *   - A file just received is named p_<random>.ext and is not a valid
 *     stored reference, so the staff endpoint will not serve it. It is
 *     copied to its final name only once the response row is inserted.
 *     If saving the row fails, the copy is removed and the parent still
 *     has the staged file for the next submit. Staged files older than
 *     two days are deleted; withdrawing a response deletes its photo.
 */
declare(strict_types=1);

const SURVEY_PHOTO_MAX_BYTES = 10 * 1024 * 1024; // 10 MB — a phone photo, not a video
const SURVEY_PHOTO_PENDING_MAX_AGE = 172800;     // 48 hours

/** MIME → stored extension. JPEG is always .jpg; the client's name is not trusted. */
const SURVEY_PHOTO_MIME_EXT = [
    'image/jpeg' => 'jpg',
    'image/png'  => 'png',
    'image/webp' => 'webp',
    'image/heic' => 'heic',
    'image/heif' => 'heif',
];

/**
 * Point storage at a temp directory. Production never calls this; tests do,
 * so a unit test cannot leave a child's photo in the real upload folder.
 */
function survey_photos_testing_dir(?string $dir = null): ?string
{
    static $override = false;
    if (func_num_args() >= 1) $override = $dir;
    return $override === false ? null : $override;
}

function survey_photos_dir(): string
{
    $override = survey_photos_testing_dir();
    if ($override !== null && $override !== '') {
        if (!is_dir($override) && !@mkdir($override, 0700, true) && !is_dir($override)) {
            throw new RuntimeException('Could not create the photo folder.');
        }
        return $override;
    }

    $root = realpath(__DIR__ . '/..');
    if ($root === false) {
        throw new RuntimeException('Could not create the photo folder.');
    }
    $dir = $root . '/uploads/survey_photos';
    if (!is_dir($dir) && !@mkdir($dir, 0755, true) && !is_dir($dir)) {
        throw new RuntimeException('Could not create the photo folder.');
    }
    return $dir;
}

function survey_ini_bytes(string $v): int
{
    $v = trim($v);
    if ($v === '' || $v === '-1') return PHP_INT_MAX;
    $n = (float)$v;
    switch (strtoupper(substr($v, -1))) {
        case 'G': $n *= 1024; // fall through
        case 'M': $n *= 1024;
        case 'K': $n *= 1024;
    }
    return (int)$n;
}

/**
 * The size we will actually accept: the 10 MB product cap, or lower when
 * this server's upload/post limits are tighter. The form states this number
 * so a parent is not told "10 MB" and then rejected at 2 MB.
 */
function survey_photo_upload_ceiling(): int
{
    $cap  = SURVEY_PHOTO_MAX_BYTES;
    $up   = survey_ini_bytes((string)ini_get('upload_max_filesize'));
    $post = survey_ini_bytes((string)ini_get('post_max_size'));
    if ($up > 0 && $up < $cap) $cap = $up;
    // post_max_size has to hold the file and the rest of the form. Keep
    // about a megabyte of room so a full form is not dropped as an empty body.
    if ($post > 0 && $post !== PHP_INT_MAX && $post < $cap + 1048576) {
        $cap = max(1, min($cap, $post - 1048576));
    }
    return $cap;
}

function survey_photo_limit_label(): string
{
    $n = survey_photo_upload_ceiling();
    if ($n >= 1048576 && $n % 1048576 === 0) return (int)($n / 1048576) . ' MB';
    if ($n >= 1048576) return number_format($n / 1048576, 1) . ' MB';
    if (function_exists('format_bytes')) return format_bytes($n);
    return (int)max(1, round($n / 1048576)) . ' MB';
}

function survey_photo_too_big_message(): string
{
    return 'That photo is too large. Please choose one under ' . survey_photo_limit_label() . '.';
}

/**
 * True when PHP discarded the body because it exceeded post_max_size.
 * $_POST and $_FILES are both empty in that case, so the form cannot
 * replay what the parent typed — the message has to say why.
 */
function survey_post_exceeded_limit(): bool
{
    if (strtoupper((string)($_SERVER['REQUEST_METHOD'] ?? '')) !== 'POST') return false;
    $len = (int)($_SERVER['CONTENT_LENGTH'] ?? 0);
    if ($len <= 0) return false;
    $post = survey_ini_bytes((string)ini_get('post_max_size'));
    if ($post <= 0 || $post === PHP_INT_MAX || $len <= $post) return false;
    return $_POST === [] && $_FILES === [];
}

function survey_photo_mime_alias(string $mime): string
{
    $map = [
        'image/jpg'           => 'image/jpeg',
        'image/pjpeg'         => 'image/jpeg',
        'image/x-png'         => 'image/png',
        'image/heic-sequence' => 'image/heic',
        'image/heif-sequence' => 'image/heif',
    ];
    return $map[$mime] ?? $mime;
}

/** Read the leading bytes and return a MIME we accept, or null. */
function survey_photo_sniff(string $path): ?string
{
    $fh = @fopen($path, 'rb');
    if ($fh === false) return null;
    $head = fread($fh, 128);
    fclose($fh);
    if (!is_string($head) || $head === '') return null;

    if (strncmp($head, "\xFF\xD8\xFF", 3) === 0) return 'image/jpeg';
    if (strncmp($head, "\x89PNG\r\n\x1A\n", 8) === 0) return 'image/png';
    if (strlen($head) >= 12 && substr($head, 0, 4) === 'RIFF' && substr($head, 8, 4) === 'WEBP') {
        return 'image/webp';
    }
    return survey_photo_sniff_heif($head);
}

/**
 * HEIC/HEIF live in an ISO BMFF `ftyp` box. iPhone photos use the `heic`
 * brand; some Android HEIF files only advertise `mif1`. AVIF uses the same
 * container with an `avif` brand and is not a photo type we accept.
 */
function survey_photo_sniff_heif(string $head): ?string
{
    if (strlen($head) < 12 || substr($head, 4, 4) !== 'ftyp') return null;
    $size = unpack('N', substr($head, 0, 4));
    $boxLen = is_array($size) ? (int)$size[1] : 0;
    if ($boxLen < 16) {
        $box = substr($head, 8);
    } else {
        $box = substr($head, 8, min($boxLen - 8, strlen($head) - 8));
    }
    if ($box === false || strlen($box) < 4) return null;

    $brands = [substr($box, 0, 4)];
    for ($i = 8; $i + 4 <= strlen($box); $i += 4) {
        $brands[] = substr($box, $i, 4);
    }

    $heicBrands = ['heic', 'heix', 'hevc', 'hevx', 'heim', 'heis', 'hevm', 'hevs'];
    $heifBrands = ['heif', 'mif1', 'msf1'];
    $hasHeic = false;
    $hasHeif = false;
    $hasAvif = false;
    foreach ($brands as $b) {
        if (in_array($b, $heicBrands, true)) $hasHeic = true;
        if (in_array($b, $heifBrands, true)) $hasHeif = true;
        if ($b === 'avif' || $b === 'avis') $hasAvif = true;
    }
    if ($hasAvif && !$hasHeic && !$hasHeif) return null;
    if ($hasHeic) return 'image/heic';
    if ($hasHeif) return 'image/heif';
    return null;
}

/**
 * Real type of a file on disk. Magic bytes are required; finfo has to agree
 * when it recognises the file. Extension is never consulted. HEIC often
 * comes back from finfo as application/octet-stream, so an unrecognised
 * finfo result falls through to the magic bytes instead of failing closed
 * on a real iPhone photo.
 */
function survey_photo_detect_mime(string $path): ?string
{
    $magic = survey_photo_sniff($path);
    if ($magic === null || !isset(SURVEY_PHOTO_MIME_EXT[$magic])) return null;
    if (!class_exists('finfo')) return $magic;

    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $detected = survey_photo_mime_alias((string)$finfo->file($path));
    // libmagic often cannot name a HEIC file. It reports a generic container
    // (quicktime/mp4) or octet-stream. The ftyp brand is the reliable signal
    // in that case. A definite disagreement (text, pdf, a different image)
    // is still a reject.
    $inconclusive = ['', 'application/octet-stream', 'application/x-empty', 'application/x-unknown'];
    if ($magic === 'image/heic' || $magic === 'image/heif') {
        array_push($inconclusive, 'video/quicktime', 'video/mp4', 'application/mp4');
    }
    if (in_array($detected, $inconclusive, true)) return $magic;
    if (!isset(SURVEY_PHOTO_MIME_EXT[$detected])) return null;

    $family = static function (string $m): string {
        return ($m === 'image/heic' || $m === 'image/heif') ? 'heif' : $m;
    };
    if ($family($detected) !== $family($magic)) return null;
    return $detected;
}

function survey_photo_safe_name(string $name): string
{
    $name = basename(str_replace('\\', '/', $name));
    $name = preg_replace('/[^\p{L}\p{N}._ -]+/u', '', $name) ?? '';
    $name = trim($name);
    if ($name === '' || $name === '.' || $name === '..') $name = 'photo';
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 80);
    else                              $name = substr($name, 0, 80);
    return $name;
}

function survey_photo_file_pattern(bool $pending): string
{
    $prefix = $pending ? 'p_' : '';
    return '/^' . $prefix . '[a-f0-9]{32}\.(jpg|png|webp|heic|heif)$/';
}

/**
 * A photo reference is safe to trust when the filename is one of ours and
 * the MIME is one we store. Anything else — a string, a path, a pending
 * name on a saved response — is not a photo.
 *
 * Pending (not yet attached to a response) names start with p_ and are only
 * accepted when $allowPending is set. The staff endpoint never sets it.
 */
function survey_photo_ref($value, bool $allowPending = false): ?array
{
    if (!is_array($value)) return null;
    $pending = !empty($value['pending']);
    if ($pending && !$allowPending) return null;
    $file = (string)($value['file'] ?? '');
    if (!preg_match(survey_photo_file_pattern($pending), $file)) return null;

    $mime = survey_photo_mime_alias((string)($value['mime'] ?? ''));
    if (!isset(SURVEY_PHOTO_MIME_EXT[$mime])) return null;
    $ext = strtolower(pathinfo($file, PATHINFO_EXTENSION));
    if ($ext !== SURVEY_PHOTO_MIME_EXT[$mime]) return null;

    $name = (string)($value['name'] ?? '');
    if (function_exists('mb_substr')) $name = mb_substr($name, 0, 80);
    else                              $name = substr($name, 0, 80);

    return [
        'file'    => $file,
        'mime'    => $mime,
        'size'    => max(0, (int)($value['size'] ?? 0)),
        'name'    => $name,
        'pending' => $pending,
    ];
}

function survey_photo_unlink(string $file): void
{
    $pending = str_starts_with($file, 'p_');
    if (!preg_match(survey_photo_file_pattern($pending), $file)) return;
    $dir = realpath(survey_photos_dir());
    if ($dir === false) return;
    $real = realpath(survey_photos_dir() . '/' . $file);
    if ($real === false || !str_starts_with($real, $dir . DIRECTORY_SEPARATOR) || !is_file($real)) return;
    @unlink($real);
}

/** Drop staged uploads that were never attached to a saved response. */
function survey_photo_gc_pending(): void
{
    $dir = survey_photos_dir();
    $cut = time() - SURVEY_PHOTO_PENDING_MAX_AGE;
    $paths = glob($dir . '/p_*') ?: [];
    foreach ($paths as $path) {
        if (!is_file($path)) continue;
        $base = basename($path);
        if (!preg_match(survey_photo_file_pattern(true), $base)) continue;
        $mtime = filemtime($path);
        if ($mtime !== false && $mtime < $cut) @unlink($path);
    }
}

/**
 * Validate one $_FILES entry and copy it into the staging area.
 * Returns ['ok' => bool, 'error' => string, 'ref' => ?array].
 * Does not throw — the public form turns `error` into a field message and
 * re-renders every other answer.
 */
function survey_photo_accept(array $file): array
{
    survey_photo_gc_pending();

    $fail = static function (string $msg): array {
        return ['ok' => false, 'error' => $msg, 'ref' => null];
    };

    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) return $fail('Please upload a photo.');
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) {
        return $fail(survey_photo_too_big_message());
    }
    if ($err === UPLOAD_ERR_PARTIAL) return $fail('The upload did not finish. Please try again.');
    if ($err !== UPLOAD_ERR_OK) return $fail('The photo could not be uploaded. Please try again.');

    $tmp = (string)($file['tmp_name'] ?? '');
    if ($tmp === '' || !is_file($tmp)) return $fail('The photo could not be uploaded. Please try again.');

    $size = (int)filesize($tmp);
    $claimed = (int)($file['size'] ?? 0);
    if ($size <= 0) return $fail('That file is empty. Please choose a photo.');
    if ($size > SURVEY_PHOTO_MAX_BYTES || $claimed > SURVEY_PHOTO_MAX_BYTES) {
        return $fail(survey_photo_too_big_message());
    }

    $mime = survey_photo_detect_mime($tmp);
    if ($mime === null || !isset(SURVEY_PHOTO_MIME_EXT[$mime])) {
        return $fail('Please upload a photo as a JPG, PNG, WebP or HEIC image. Other files are not accepted.');
    }

    // php://input is not how this arrives. $_FILES tmp names are uploads.
    // The copy branch exists so the test suite can pass a normal temp file
    // under PHP_SAPI=cli. The built-in server is cli-server and is refused.
    $uploaded = is_uploaded_file($tmp);
    if (!$uploaded && PHP_SAPI !== 'cli') {
        return $fail('The photo could not be uploaded. Please try again.');
    }

    $name = 'p_' . bin2hex(random_bytes(16)) . '.' . SURVEY_PHOTO_MIME_EXT[$mime];
    $dest = survey_photos_dir() . '/' . $name;
    $ok = $uploaded ? move_uploaded_file($tmp, $dest) : @copy($tmp, $dest);
    if (!$ok) return $fail('The photo could not be saved. Please try again.');
    @chmod($dest, 0644);

    return [
        'ok'    => true,
        'error' => '',
        'ref'   => [
            'file'    => $name,
            'mime'    => $mime,
            'size'    => $size,
            'name'    => survey_photo_safe_name((string)($file['name'] ?? '')),
            'pending' => true,
        ],
    ];
}

/**
 * Copy staged photos onto their permanent names and return answers whose
 * photo values are final references (no `pending` flag). On any failure the
 * copies made so far are removed; staged files are left for another try.
 */
function survey_photos_commit(array $answers): array
{
    $created = [];
    try {
        foreach ($answers as $k => $v) {
            if (!is_array($v) || empty($v['pending'])) continue;
            $ref = survey_photo_ref($v, true);
            if ($ref === null || empty($ref['pending'])) {
                throw new RuntimeException('Please upload the photo again.');
            }
            $src = survey_photos_dir() . '/' . $ref['file'];
            if (!is_file($src)) throw new RuntimeException('Please upload the photo again.');

            $destName = bin2hex(random_bytes(16)) . '.' . SURVEY_PHOTO_MIME_EXT[$ref['mime']];
            $dest = survey_photos_dir() . '/' . $destName;
            if (!@copy($src, $dest)) {
                throw new RuntimeException('The photo could not be saved. Please try again.');
            }
            @chmod($dest, 0644);
            $created[] = $dest;
            $answers[$k] = [
                'file' => $destName,
                'mime' => $ref['mime'],
                'size' => $ref['size'],
                'name' => $ref['name'],
            ];
        }
        return $answers;
    } catch (Throwable $e) {
        foreach ($created as $p) @unlink($p);
        throw $e;
    }
}

/** Delete permanent copies when the response row was not saved. */
function survey_photos_rollback_committed(array $answers): void
{
    foreach ($answers as $v) {
        if (!is_array($v) || !empty($v['pending'])) continue;
        $ref = survey_photo_ref($v);
        if ($ref) survey_photo_unlink($ref['file']);
    }
}

/** Delete staged files once their permanent copies are on a saved response. */
function survey_photos_discard_pending(array $answers): void
{
    foreach ($answers as $v) {
        $ref = survey_photo_ref($v, true);
        if ($ref && !empty($ref['pending'])) survey_photo_unlink($ref['file']);
    }
}

/** Delete every photo referenced by a saved response. */
function survey_photos_release_answers(array $answers): void
{
    foreach ($answers as $v) {
        $ref = survey_photo_ref($v);
        if ($ref) survey_photo_unlink($ref['file']);
    }
}

function survey_photo_staff_url(int $responseId, string $questionKey): string
{
    return '/surveys/photo.php?id=' . $responseId . '&k=' . rawurlencode($questionKey);
}

/** Absolute when this is a real web request; a path otherwise (tests, CLI). */
function survey_photo_staff_link(int $responseId, string $questionKey): string
{
    $path = survey_photo_staff_url($responseId, $questionKey);
    $host = (string)($_SERVER['HTTP_HOST'] ?? '');
    if ($host === '' || !function_exists('app_base_url')) return $path;
    return app_base_url() . $path;
}

function survey_photo_previewable(string $mime): bool
{
    return $mime === 'image/jpeg' || $mime === 'image/png' || $mime === 'image/webp';
}

/**
 * Staff-only thumbnail or full image. HEIC does not render in most browsers,
 * so those get a link rather than a broken image. Output is escaped.
 */
function survey_photo_render(int $responseId, string $questionKey, $value, string $size = 'thumb'): void
{
    $ref = survey_photo_ref($value);
    if ($ref === null) {
        echo '<span class="muted">—</span>';
        return;
    }
    $href = e(survey_photo_staff_url($responseId, $questionKey));
    $alt  = e($ref['name'] !== '' ? $ref['name'] : 'Uploaded photo');
    if ($size === 'full') {
        $style = 'max-width:100%;height:auto;max-height:480px;border-radius:8px;border:1px solid #eadfe5;display:block;';
    } else {
        $style = 'width:72px;height:72px;object-fit:cover;border-radius:8px;border:1px solid #eadfe5;display:block;background:#fff5fa;';
    }
    echo '<a href="' . $href . '" target="_blank" rel="noopener">';
    if (survey_photo_previewable($ref['mime'])) {
        echo '<img src="' . $href . '" alt="' . $alt . '" style="' . $style . '" loading="lazy">';
    } else {
        $kind = e(strtoupper(SURVEY_PHOTO_MIME_EXT[$ref['mime']]));
        echo 'View ' . $kind . ' photo';
    }
    echo '</a>';
}
