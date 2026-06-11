<?php
/**
 * crm/dedupe_all.php — bulk merge of duplicate leads, phone = primary identity.
 *
 * Groups inquiry_families by the last 10 digits of primary_phone. In each
 * group the keeper is chosen exactly like crm_find_lead_by_phone() (open lead
 * first, then most recent), and every other row is MERGED into it:
 *   - touchpoints, appointments, children, parents move to the keeper
 *   - notes / WhatsApp summary / email backfill onto the keeper when empty
 *   - the duplicate shell is archived as status=lost, lost_reason=duplicate
 *     with a "[merged into #N]" note — nothing is hard-deleted.
 *
 * Auth:  header  X-Lead-Secret: <app_settings.wacrm_sso_secret>
 * GET    → dry-run scan: the duplicate groups and what would happen.
 * POST   {"apply": true} → perform the merge. Reply lists every action.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

header('Content-Type: application/json');

$secret   = (string) app_setting('wacrm_sso_secret', '');
$provided = (string) ($_SERVER['HTTP_X_LEAD_SECRET'] ?? '');
if ($secret === '' || !hash_equals($secret, $provided)) {
    http_response_code(401);
    echo json_encode(['error' => 'unauthorized']);
    exit;
}

try {
    $pdo = db();

    // Duplicate groups: >1 lead sharing a last-10 phone, ignoring rows that
    // were already archived as duplicates.
    $rows = $pdo->query("
        SELECT id, primary_name, primary_phone, primary_email, status, notes, created_at,
               RIGHT(REGEXP_REPLACE(COALESCE(primary_phone,''), '[^0-9]', ''), 10) AS p10
        FROM inquiry_families
        WHERE COALESCE(primary_phone,'') <> ''
          AND NOT (status = 'lost' AND lost_reason = 'duplicate')
        ORDER BY (status NOT IN ('lost','enrolled')) DESC, created_at DESC
    ")->fetchAll();

    $groups = [];
    foreach ($rows as $r) {
        if (strlen((string) $r['p10']) < 10) continue;
        $groups[$r['p10']][] = $r;
    }
    $groups = array_filter($groups, fn($g) => count($g) > 1);

    $apply = false;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        $in = json_decode((string) file_get_contents('php://input'), true);
        $apply = !empty($in['apply']);
    }

    $report = [];
    foreach ($groups as $p10 => $g) {
        // Rows arrive pre-sorted: keeper is the first (open, most recent).
        $keeper = $g[0];
        $dupes  = array_slice($g, 1);
        $entry  = [
            'phone'  => $p10,
            'keeper' => ['id' => (int) $keeper['id'], 'name' => $keeper['primary_name'], 'status' => $keeper['status']],
            'merged' => array_map(fn($d) => ['id' => (int) $d['id'], 'name' => $d['primary_name'], 'status' => $d['status']], $dupes),
        ];

        if ($apply) {
            $kid = (int) $keeper['id'];
            foreach ($dupes as $d) {
                $did = (int) $d['id'];
                // Move child records onto the keeper.
                foreach (['inquiry_touchpoints', 'crm_appointments', 'inquiry_children', 'inquiry_parents'] as $tbl) {
                    try {
                        $pdo->prepare("UPDATE $tbl SET family_id = :k WHERE family_id = :d")
                            ->execute([':k' => $kid, ':d' => $did]);
                    } catch (Throwable $e) {
                        // Table may not exist on this install — skip.
                    }
                }
                // Backfill identity fields the keeper is missing.
                $pdo->prepare("
                    UPDATE inquiry_families k
                    JOIN inquiry_families d ON d.id = :d
                    SET k.primary_email = COALESCE(k.primary_email, d.primary_email),
                        k.wa_summary    = COALESCE(k.wa_summary,    d.wa_summary),
                        k.notes = TRIM(BOTH '\n' FROM CONCAT_WS('\n', k.notes, d.notes))
                    WHERE k.id = :k
                ")->execute([':d' => $did, ':k' => $kid]);
                // Archive the shell.
                $pdo->prepare("
                    UPDATE inquiry_families
                    SET status='lost', lost_reason='duplicate', probability=0,
                        notes = TRIM(BOTH '\n' FROM CONCAT_WS('\n', notes, :m))
                    WHERE id = :d
                ")->execute([':m' => '[' . date('Y-m-d H:i') . "] merged into #$kid (phone dedupe)", ':d' => $did]);
                crm_audit_log('lead_merged', $did, ['into' => $kid, 'via' => 'dedupe_all']);
            }
        }
        $report[] = $entry;
    }

    echo json_encode([
        'ok'      => true,
        'mode'    => $apply ? 'APPLIED' : 'dry-run',
        'groups'  => count($report),
        'detail'  => $report,
    ], JSON_UNESCAPED_UNICODE);
} catch (Throwable $e) {
    http_response_code(500);
    echo json_encode(['error' => 'dedupe_all_failed', 'detail' => $e->getMessage()]);
}
