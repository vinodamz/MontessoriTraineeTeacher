<?php
/**
 * materials/media.php — auth-gated serve for a condition photo/video/audio.
 *
 * Access, either:
 *   - a logged-in user with the materials module, or
 *   - a valid share token (?t=<token>) whose period matches the media's
 *     check — so the read-only Kreedo page can show its photos and
 *     NOTHING outside the shared month.
 *
 *   GET ?id=N            stream the original file inline
 *   GET ?id=N&thumb=1    stream a 480px JPEG thumbnail (generated on first
 *                        use; falls back to the original if none can be made)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

$id    = (int)($_GET['id'] ?? 0);
$token = (string)($_GET['t'] ?? '');

$st = db()->prepare("
    SELECT md.stored_filename, md.mime_type, md.original_filename, md.kind, c.period
    FROM mm_condition_media md
    JOIN mm_condition_checks c ON c.id = md.check_id
    WHERE md.id = :id
");
$st->execute([':id' => $id]);
$row = $st->fetch();
if (!$row) { http_response_code(404); exit('Not found.'); }

// ---- Access check -----------------------------------------------------------
$allowed = false;
if ($token !== '') {
    $share = mm_share_by_token($token);
    $allowed = $share !== null && $share['period'] === $row['period'];
} else {
    $u = current_user();
    $allowed = $u !== null && user_has_module($u, 'materials');
}
if (!$allowed) { http_response_code(404); exit('Not found.'); }

$path = mm_media_dir() . '/' . basename((string)$row['stored_filename']);
if (!is_file($path)) { http_response_code(404); exit('File missing.'); }

$mime = (string)$row['mime_type'];
if (!empty($_GET['thumb']) && $row['kind'] === 'photo') {
    $thumb = mm_thumb_for((string)$row['stored_filename']);
    if ($thumb !== null) {
        $path = $thumb;
        $mime = 'image/jpeg';
    }
}

header('Content-Type: ' . $mime);
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="' . rawurlencode((string)$row['original_filename']) . '"');
header('X-Content-Type-Options: nosniff');
header('Cache-Control: private, max-age=86400');
readfile($path);
