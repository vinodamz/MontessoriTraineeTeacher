<?php
/**
 * crm/smoke_internal.php — master-spec assertions for the Admissions / CRM
 * goal (docs/goal). Same pattern as tasks/smoke_internal.php: IP-gated
 * over HTTP (cPanel deploy curls it from 127.0.0.1) and also runnable from
 * CLI as the cPanel deploy account.
 *
 * Asserts:
 *   - Schema: crm_stages has wa_text/wa_template/wa_template_lang/intro_text;
 *     crm_stage_intros_sent exists; stages intro_sent/call_requested/
 *     future_intake present and 'visited' gone (migrate_038); app_settings
 *     has a non-empty crm_school_name.
 *   - crm_move_stage(): probability snaps to the stage default, 'lost'
 *     refuses without a lost_reason (row untouched), 'lost' with a reason
 *     persists it, 'enrolled' pins probability=100 + stamps enrolled_at.
 *   - Explicit ['probability' => 55] survives a move (not the stage default).
 *   - Dedupe UPDATE (same WHERE as crm/dedupe.php) never touches an
 *     enrolled row sharing the phone.
 *   - crm_find_lead_by_phone() is format-proof ("+91 " + spaced variant).
 *   - crm_wa_defaults() school identity matches app_settings.crm_school_name.
 *   - Stage-intro once-only tracking: not-sent → mark → sent, idempotent.
 *   - wacrm_template_params('intro_admission_enquiry') maps to [parent_name].
 *
 * Test rows all use 'SMOKE-' prefix in primary_name plus reserved fake
 * 990000xxxxx phones, and are hard-deleted in the finally block (smoke
 * artifacts, never real data; prefix guard makes the DELETEs incapable of
 * touching real inquiries; children/parents/intros cascade via FK).
 */
declare(strict_types=1);

$isCli = PHP_SAPI === 'cli';
if (!$isCli) {
    $remote = $_SERVER['REMOTE_ADDR'] ?? '';
    if (!in_array($remote, ['127.0.0.1', '::1'], true)) { http_response_code(404); exit; }
}

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

// Override the global friendly-error handler so CLI / loopback runs surface
// fatals as FAIL lines instead of exiting silently (errors.php's handler
// returns early in CLI mode, which earlier deploys swallowed mid-smoke).
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
    if ($err !== null
        && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        echo "FAIL — fatal\n";
        echo "  - " . $err['message'] . "\n";
        echo "  - " . ($err['file'] ?? '?') . ':' . ($err['line'] ?? '?') . "\n";
    }
});

if (PHP_SAPI !== 'cli') {
    header('Content-Type: text/plain; charset=utf-8');
}
echo "BEGIN crm smoke (sapi=" . PHP_SAPI . ")\n";

$failures = [];
$createdFamilyIds = [];

/**
 * Reserved fake phone (990000xxxxx) whose last-10 digits collide with no
 * existing inquiry — regenerated until unique so the phone-matching asserts
 * can never latch onto real data.
 */
$smokePhone = function (): string {
    for ($i = 0; $i < 20; $i++) {
        $phone  = '990000' . str_pad((string)random_int(0, 99999), 5, '0', STR_PAD_LEFT);
        $last10 = substr($phone, -10);
        $st = db()->prepare("
            SELECT COUNT(*) FROM inquiry_families
            WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d");
        $st->execute([':d' => $last10]);
        if ((int)$st->fetchColumn() === 0) return $phone;
    }
    throw new RuntimeException('could not reserve a unique 990000xxxxx smoke phone');
};

$makeFamily = function (string $suffix, string $phone, string $status, int $prob) use (&$createdFamilyIds): int {
    db()->prepare("
        INSERT INTO inquiry_families (primary_name, primary_phone, status, probability)
        VALUES (:n, :ph, :s, :p)")
        ->execute([':n' => "SMOKE-$suffix", ':ph' => $phone, ':s' => $status, ':p' => $prob]);
    $id = (int)db()->lastInsertId();
    $createdFamilyIds[] = $id;
    return $id;
};

$familyRow = function (int $id): array {
    $st = db()->prepare("SELECT status, lost_reason, probability, enrolled_at FROM inquiry_families WHERE id = :id");
    $st->execute([':id' => $id]);
    $r = $st->fetch();
    return $r ?: [];
};

try {
    $ts = time() . '-' . bin2hex(random_bytes(2));

    // ---- 1. Schema -------------------------------------------------------
    $stageCols = [];
    try {
        foreach (db()->query("SHOW COLUMNS FROM crm_stages") as $r) $stageCols[] = $r['Field'];
    } catch (Throwable $e) { $failures[] = "schema missing table crm_stages"; }
    foreach (['wa_text', 'wa_template', 'wa_template_lang', 'intro_text'] as $c) {
        if (!in_array($c, $stageCols, true)) $failures[] = "schema missing column crm_stages.$c";
    }

    $introCols = [];
    try {
        foreach (db()->query("SHOW COLUMNS FROM crm_stage_intros_sent") as $r) $introCols[] = $r['Field'];
    } catch (Throwable $e) { $failures[] = "schema missing table crm_stage_intros_sent"; }
    foreach (['family_id', 'stage_code'] as $c) {
        if ($introCols && !in_array($c, $introCols, true)) $failures[] = "schema missing column crm_stage_intros_sent.$c";
    }

    $stageCodes = db()->query("SELECT code FROM crm_stages")->fetchAll(PDO::FETCH_COLUMN);
    foreach (['intro_sent', 'call_requested', 'future_intake'] as $code) {
        if (!in_array($code, $stageCodes, true)) $failures[] = "stage '$code' missing from crm_stages";
    }
    if (in_array('visited', $stageCodes, true)) $failures[] = "stage 'visited' still present (migrate_038 should have removed it)";

    $schoolName = trim((string)app_setting('crm_school_name', ''));
    if ($schoolName === '') $failures[] = "app_settings.crm_school_name missing or empty";

    // ---- 2. crm_move_stage() side-effects ---------------------------------
    $phone1 = $smokePhone();
    $fid = $makeFamily("move-$ts", $phone1, 'new', 20);

    $res = crm_move_stage($fid, 'offered', ['via' => 'smoke']);
    $row = $familyRow($fid);
    if (!$res['ok']) $failures[] = "move to offered refused: " . ($res['error'] ?? '?');
    if (($row['status'] ?? '') !== 'offered') $failures[] = "move to offered did not set status (got '" . ($row['status'] ?? '') . "')";
    if ((int)($row['probability'] ?? -1) !== crm_default_probability('offered')) {
        $failures[] = "probability did not snap to offered default: got " . ($row['probability'] ?? 'null')
                    . ", want " . crm_default_probability('offered');
    }

    $res = crm_move_stage($fid, 'lost', ['via' => 'smoke']);   // no lost_reason on purpose
    $after = $familyRow($fid);
    if (!empty($res['ok'])) $failures[] = "move to lost without lost_reason was not refused";
    if ($after !== $row) $failures[] = "refused lost move still mutated the row";

    $res = crm_move_stage($fid, 'lost', ['lost_reason' => 'no_response', 'via' => 'smoke']);
    $row = $familyRow($fid);
    if (!$res['ok']) $failures[] = "move to lost with reason refused: " . ($res['error'] ?? '?');
    if (($row['status'] ?? '') !== 'lost') $failures[] = "move to lost did not set status";
    if (($row['lost_reason'] ?? '') !== 'no_response') $failures[] = "lost_reason not persisted (got '" . ($row['lost_reason'] ?? '') . "')";

    $res = crm_move_stage($fid, 'enrolled', ['via' => 'smoke']);
    $row = $familyRow($fid);
    if (!$res['ok']) $failures[] = "move to enrolled refused: " . ($res['error'] ?? '?');
    if ((int)($row['probability'] ?? -1) !== 100) $failures[] = "enrolled did not pin probability=100";
    if (empty($row['enrolled_at'])) $failures[] = "enrolled did not stamp enrolled_at";

    // ---- 3. Explicit probability override survives ------------------------
    $res = crm_move_stage($fid, 'tour_scheduled', ['probability' => 55, 'via' => 'smoke']);
    $row = $familyRow($fid);
    if (!$res['ok']) $failures[] = "move with explicit probability refused: " . ($res['error'] ?? '?');
    if ((int)($row['probability'] ?? -1) !== 55) {
        $failures[] = "explicit probability=55 did not survive (got " . ($row['probability'] ?? 'null')
                    . ", stage default is " . crm_default_probability('tour_scheduled') . ")";
    }

    // ---- 4. Dedupe UPDATE never touches an enrolled row -------------------
    $phone2 = $smokePhone();
    $enrolledId = $makeFamily("dupe-enrolled-$ts", $phone2, 'enrolled', 100);
    $keepId     = $makeFamily("dupe-keep-$ts",     $phone2, 'new',      20);
    $last10 = substr(preg_replace('/\D/', '', $phone2), -10);
    // Same UPDATE + WHERE as crm/dedupe.php uses:
    db()->prepare("
        UPDATE inquiry_families
        SET status='lost', lost_reason='duplicate', probability=0
        WHERE RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) = :d
          AND id <> :keep AND status NOT IN ('lost', 'enrolled')")
        ->execute([':d' => $last10, ':keep' => $keepId]);
    if (($familyRow($enrolledId)['status'] ?? '') !== 'enrolled') {
        $failures[] = "dedupe UPDATE archived an ENROLLED row (must never happen)";
    }
    if (($familyRow($keepId)['status'] ?? '') !== 'new') {
        $failures[] = "dedupe UPDATE touched the kept row";
    }

    // ---- 5. crm_find_lead_by_phone is format-proof -------------------------
    $phone3 = $smokePhone();
    $findId = $makeFamily("find-$ts", $phone3, 'new', 20);
    $variant = '+91 ' . substr($phone3, 1, 5) . ' ' . substr($phone3, 6);   // "+91 90000 xxxxx"
    $found = crm_find_lead_by_phone($variant);
    if ($found !== $findId) {
        $failures[] = "crm_find_lead_by_phone('$variant') returned " . var_export($found, true) . ", want $findId";
    }

    // ---- 6. WhatsApp school identity ---------------------------------------
    $defaults = crm_wa_defaults([]);
    if (($defaults['school_name'] ?? '') !== $schoolName) {
        $failures[] = "crm_wa_defaults school_name != app_settings.crm_school_name";
    }
    if (strpos((string)($defaults['school_name'] ?? ''), 'Kaloor') === false) {
        $failures[] = "school_name does not contain 'Kaloor' (got '" . ($defaults['school_name'] ?? '') . "')";
    }

    // ---- 7. Stage intro once-only tracking ---------------------------------
    if (crm_intro_already_sent($findId, 'new') !== false) {
        $failures[] = "intro_already_sent true for a fresh family";
    }
    crm_mark_intro_sent($findId, 'new');
    if (crm_intro_already_sent($findId, 'new') !== true) {
        $failures[] = "intro not marked sent after crm_mark_intro_sent";
    }
    crm_mark_intro_sent($findId, 'new');   // second mark must be idempotent, not throw
    if (crm_intro_already_sent($findId, 'new') !== true) {
        $failures[] = "second crm_mark_intro_sent lost the sent flag";
    }

    // ---- 8. Template param mapping ------------------------------------------
    $params = wacrm_template_params('intro_admission_enquiry', ['parent_name' => 'X']);
    if ($params !== ['X']) {
        $failures[] = "wacrm_template_params(intro_admission_enquiry) wrong: " . json_encode($params);
    }

} finally {
    // Hard-delete every SMOKE-* inquiry we created, plus its audit rows.
    // Children / parents / intros-sent cascade via FK; prefix guard makes
    // this incapable of touching real inquiries.
    foreach ($createdFamilyIds as $fid) {
        try {
            db()->prepare("
                DELETE ia FROM inquiry_audit ia
                JOIN inquiry_families f ON f.id = ia.family_id
                WHERE f.id = :id AND f.primary_name LIKE 'SMOKE-%'")
                ->execute([':id' => $fid]);
            db()->prepare("DELETE FROM inquiry_families WHERE id = :id AND primary_name LIKE 'SMOKE-%'")
                ->execute([':id' => $fid]);
        } catch (Throwable $e) { /* leave residue; admin can clean up */ }
    }
}

if ($failures) {
    http_response_code(500);
    echo "FAIL — " . count($failures) . " assertion(s) failed\n";
    foreach ($failures as $f) echo "  - $f\n";
    exit(1);
}

$mode = $isCli ? 'CLI (data layer)' : 'HTTP loopback (data layer)';
echo "PASS — CRM master-spec criteria verified on the live app ($mode)\n";
echo "  - schema has crm_stages WA/intro columns + crm_stage_intros_sent + migrate_038 stages\n";
echo "  - crm_move_stage snaps probability, guards lost_reason, pins enrolled=100 + enrolled_at\n";
echo "  - explicit probability override survives a stage move\n";
echo "  - dedupe UPDATE leaves enrolled + kept rows untouched\n";
echo "  - crm_find_lead_by_phone matches a +91/spaced variant of the same phone\n";
echo "  - crm_wa_defaults school identity comes from app_settings.crm_school_name\n";
echo "  - stage intro once-only tracking is idempotent\n";
echo "  - intro_admission_enquiry template maps to the single parent_name param\n";
