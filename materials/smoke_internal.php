<?php
/**
 * materials/smoke_internal.php — master-spec assertions for the Material
 * Condition Audit module. Same pattern as tasks/smoke_internal.php:
 * IP-gated over HTTP (the cPanel deploy curls it from 127.0.0.1) and also
 * runnable from CLI as the deploy account.
 *
 * Asserts:
 *   - Schema: mm_materials / mm_condition_checks / mm_condition_media present
 *     with the master-spec columns; the (material,period) uniqueness key.
 *   - Catalogue seeded (>= 100 materials from the school's sheet).
 *   - mm_save_check inserts, records who/when, and EDITS in place on a second
 *     mark for the same month (no duplicate row).
 *   - needs_replacement + qty land on the replacement query for the period.
 *   - Condition codes are stable and mm_condition_label resolves them.
 *   - Media table FK cascade wiring exists (row deletes with its check).
 *
 * SMOKE- prefixed test rows only; hard-deleted in the finally block.
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/materials.php';

ini_set('display_errors', '1');
error_reporting(E_ALL);
set_exception_handler(function (Throwable $e): void {
    echo "FAIL — uncaught " . get_class($e) . "\n";
    echo "  - " . $e->getMessage() . "\n";
    echo "  - " . $e->getFile() . ':' . $e->getLine() . "\n";
    exit(1);
});
register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "FAIL — fatal\n  - " . $err['message'] . "\n  - " . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?') . "\n";
    }
});

if (!$isCli) header('Content-Type: text/plain; charset=utf-8');
echo "BEGIN materials smoke (sapi=" . PHP_SAPI . ")\n";

$admin = db()->query("SELECT id FROM users WHERE role = 'admin' AND active = 1 ORDER BY id LIMIT 1")->fetch();
if (!$admin) { http_response_code(500); exit("FAIL\n  - no active admin user found\n"); }
$adminId = (int)$admin['id'];

$failures = [];
$matId = null;
$period = '2099-01';   // a future period that can never collide with real data

try {
    // ---- 1. Schema ------------------------------------------------------
    $need = [
        'mm_materials'        => ['name', 'location', 'sort_order', 'is_active'],
        'mm_condition_checks' => ['material_id', 'period', 'condition_code', 'needs_replacement', 'replace_qty', 'notes', 'checked_by_user_id', 'checked_at'],
        'mm_condition_media'  => ['check_id', 'kind', 'stored_filename', 'mime_type', 'size_bytes', 'uploaded_by_user_id'],
    ];
    foreach ($need as $table => $cols) {
        $have = [];
        foreach (db()->query("SHOW COLUMNS FROM `$table`") as $r) $have[] = $r['Field'];
        foreach ($cols as $c) {
            if (!in_array($c, $have, true)) $failures[] = "$table missing column $c";
        }
    }
    // uniqueness key material_id+period
    $idx = db()->query("SHOW INDEX FROM mm_condition_checks WHERE Key_name = 'uq_mm_check'")->fetchAll();
    if (count($idx) < 2) $failures[] = "mm_condition_checks missing (material_id, period) unique key";

    // ---- 2. Catalogue seeded -------------------------------------------
    $catCount = (int)db()->query("SELECT COUNT(*) FROM mm_materials WHERE is_active = 1")->fetchColumn();
    if ($catCount < 100) $failures[] = "material catalogue looks unseeded ($catCount rows, expected >= 100)";

    // A throwaway material to run the check flow against.
    db()->prepare("INSERT INTO mm_materials (name, location, sort_order) VALUES (:n, 'SMOKE-shelf', 9999)")
        ->execute([':n' => 'SMOKE-material-' . bin2hex(random_bytes(4))]);
    $matId = (int)db()->lastInsertId();

    // ---- 3. Save + who/when + edit-in-place ----------------------------
    $c1 = mm_save_check($matId, $period, 'mold_high', true, 3, 'base panel', $adminId);
    $row = db()->query("SELECT * FROM mm_condition_checks WHERE id = $c1")->fetch();
    if (($row['condition_code'] ?? '') !== 'mold_high') $failures[] = "condition not saved";
    if ((int)($row['needs_replacement'] ?? 0) !== 1)    $failures[] = "needs_replacement not saved";
    if ((int)($row['replace_qty'] ?? 0) !== 3)          $failures[] = "replace_qty not saved";
    if ((int)($row['checked_by_user_id'] ?? 0) !== $adminId) $failures[] = "checked_by not recorded";
    if (empty($row['checked_at']))                      $failures[] = "checked_at not stamped";

    $c2 = mm_save_check($matId, $period, 'peeled', false, 0, 'edited', $adminId);
    if ($c1 !== $c2) $failures[] = "second mark created a duplicate row instead of editing in place";
    $dupN = (int)db()->query("SELECT COUNT(*) FROM mm_condition_checks WHERE material_id = $matId AND period = '$period'")->fetchColumn();
    if ($dupN !== 1) $failures[] = "expected exactly 1 check row per (material,period), found $dupN";
    $now = db()->query("SELECT condition_code, needs_replacement FROM mm_condition_checks WHERE id = $c1")->fetch();
    if (($now['condition_code'] ?? '') !== 'peeled' || (int)$now['needs_replacement'] !== 0) {
        $failures[] = "edit did not overwrite condition/replacement flag";
    }

    // ---- 4. Replacement query picks up flagged rows --------------------
    mm_save_check($matId, $period, 'broken', true, 2, '', $adminId);
    $repl = db()->prepare("
        SELECT m.name, c.replace_qty FROM mm_condition_checks c
        JOIN mm_materials m ON m.id = c.material_id
        WHERE c.period = :p AND c.needs_replacement = 1 AND c.material_id = :m
    ");
    $repl->execute([':p' => $period, ':m' => $matId]);
    $rr = $repl->fetch();
    if (!$rr || (int)$rr['replace_qty'] !== 2) $failures[] = "replacement list didn't reflect the flagged material";

    // ---- 5. Condition codes stable -------------------------------------
    foreach (['good', 'starting', 'peeled', 'mold_low', 'mold_high', 'broken', 'bad'] as $code) {
        if (!isset(mm_conditions()[$code])) $failures[] = "condition code '$code' missing";
        if (mm_condition_label($code) === $code) $failures[] = "condition '$code' has no label";
    }

    // ---- 6. Media FK cascade -------------------------------------------
    db()->prepare("INSERT INTO mm_condition_media (check_id, kind, original_filename, stored_filename, mime_type, size_bytes)
                   VALUES (:c, 'photo', 'x.jpg', :s, 'image/jpeg', 10)")
        ->execute([':c' => $c1, ':s' => 'SMOKE-' . bin2hex(random_bytes(6)) . '.jpg']);
    $mediaBefore = (int)db()->query("SELECT COUNT(*) FROM mm_condition_media WHERE check_id = $c1")->fetchColumn();
    if ($mediaBefore < 1) $failures[] = "media row not inserted";
    // deleting the material cascades checks → media
    db()->exec("DELETE FROM mm_materials WHERE id = $matId");
    $matId = null;
    $mediaAfter = (int)db()->query("SELECT COUNT(*) FROM mm_condition_media WHERE check_id = $c1")->fetchColumn();
    if ($mediaAfter !== 0) $failures[] = "media rows did not cascade-delete with the material";

} catch (Throwable $e) {
    $failures[] = "exception: " . $e->getMessage() . " @ " . $e->getFile() . ':' . $e->getLine();
} finally {
    if ($matId !== null) {
        db()->prepare("DELETE FROM mm_materials WHERE id = :id AND location = 'SMOKE-shelf'")->execute([':id' => $matId]);
    }
    db()->exec("DELETE FROM mm_materials WHERE location = 'SMOKE-shelf'");
}

if ($failures) {
    echo "FAIL\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}
echo "PASS — material condition audit criteria verified on the live app ("
    . ($isCli ? 'CLI (data layer)' : 'HTTP loopback') . ")\n";
echo "  - schema: materials + condition checks + media, (material,period) unique\n";
echo "  - catalogue seeded from the school sheet\n";
echo "  - save records who/when; second mark edits in place (no duplicate)\n";
echo "  - needs-replacement + qty drive the Kreedo replacement list\n";
echo "  - condition codes + labels stable; media cascades with its material\n";
