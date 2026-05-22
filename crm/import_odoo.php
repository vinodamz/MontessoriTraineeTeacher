<?php
/**
 * crm/import_odoo.php — one-shot importer for the Odoo 2026 Admission dump.
 *
 * Admin-only. Reads sql/odoo_dump/{leads,messages,activities}.csv and
 * upserts into inquiry_families / inquiry_touchpoints. Idempotent via the
 * odoo_lead_id / odoo_msg_id columns added by migrate_014.
 *
 *   GET  → dry-run preview: shows what will change.
 *   POST → applies the import inside a transaction; prints a summary.
 *
 * Stage mapping (Odoo "stage_id_label" → inquiry_families.status):
 *   New              → new
 *   Whatsapp / Call  → new
 *   Details Shared   → tour_scheduled
 *   School Visited   → offered
 *   Admission Taken  → enrolled
 *   Future           → waitlisted
 *
 * Pending activities (5 rows) are inserted as inquiry_touchpoints with
 * kind='call' and follow_up_at set to the Odoo deadline.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

require_admin();

const ODOO_STAGE_TO_STATUS = [
    'New'             => 'new',
    'Whatsapp / Call' => 'new',
    'Details Shared'  => 'tour_scheduled',
    'School Visited'  => 'offered',
    'Admission Taken' => 'enrolled',
    'Future'          => 'waitlisted',
];

function odoo_dump_dir(): string
{
    return realpath(__DIR__ . '/..') . '/sql/odoo_dump';
}

function odoo_load_csv(string $name): array
{
    $path = odoo_dump_dir() . '/' . $name;
    if (!is_readable($path)) {
        throw new RuntimeException("Missing CSV: $path");
    }
    $fh = fopen($path, 'r');
    if (!$fh) throw new RuntimeException("Cannot open $path");
    $header = fgetcsv($fh);
    if (!$header) throw new RuntimeException("Empty CSV: $path");
    $rows = [];
    while (($row = fgetcsv($fh)) !== false) {
        if (count($row) === 1 && trim((string)$row[0]) === '') continue;
        $rows[] = array_combine($header, array_pad($row, count($header), ''));
    }
    fclose($fh);
    return $rows;
}

/** "+91 95670 36027" → "9567036027" (last 10 digits, normalised). */
function odoo_normalise_phone(string $p): string
{
    $d = preg_replace('/\D+/', '', $p);
    return $d === '' ? '' : substr($d, -10);
}

/** Match Odoo `user_id_label` to a local users.id (by name, case-insensitive). */
function odoo_user_id_for(?string $label, array $userMap): ?int
{
    $label = trim((string)$label);
    if ($label === '') return null;
    $key = strtolower($label);
    return $userMap[$key] ?? null;
}

/** Convert an HTML body to plain text. */
function odoo_html_to_text(string $html): string
{
    if ($html === '') return '';
    $html = preg_replace('/<\s*(br|\/p|\/div|\/li)\s*[^>]*>/i', "\n", $html);
    $html = strip_tags($html);
    $html = html_entity_decode($html, ENT_QUOTES | ENT_HTML5, 'UTF-8');
    $html = preg_replace("/[ \t]+/", ' ', $html);
    $html = preg_replace("/\n{3,}/", "\n\n", $html);
    return trim($html);
}

/** Map an Odoo message row to inquiry_touchpoints fields. */
function odoo_touchpoint_from_message(array $m): array
{
    $subtype = trim((string)$m['subtype']);
    $bodyText = trim((string)$m['body_text']);
    if ($bodyText === '' && !empty($m['body_html'])) {
        $bodyText = odoo_html_to_text($m['body_html']);
    }

    $kind = 'note';
    if (stripos($subtype, 'Activities') !== false || stripos($bodyText, 'Call done') !== false) {
        $kind = 'call';
    }

    // Compose a body that includes the subject/subtype for context.
    $body = $bodyText;
    if ($subtype !== '' && stripos($body, $subtype) === false) {
        $body = ($body === '') ? $subtype : "[$subtype]\n$body";
    }
    if (!empty($m['author_name'])) {
        $body .= "\n— " . trim((string)$m['author_name']);
    }
    return ['kind' => $kind, 'body' => trim($body)];
}

// ----------- Lookups -----------
$pdo = db();

$userMap = [];
foreach ($pdo->query("SELECT id, name FROM users") as $u) {
    $userMap[strtolower((string)$u['name'])] = (int)$u['id'];
}

// ----------- Load CSVs -----------
$errors = [];
$leads = $messages = $activities = [];
try {
    $leads      = odoo_load_csv('leads.csv');
    $messages   = odoo_load_csv('messages.csv');
    $activities = odoo_load_csv('activities.csv');
} catch (Throwable $e) {
    $errors[] = $e->getMessage();
}

// ----------- Build planned actions -----------
$plan = [
    'leads_insert' => 0, 'leads_update' => 0, 'leads_skip' => 0,
    'msgs_insert'  => 0, 'msgs_skip'    => 0,
    'acts_insert'  => 0, 'acts_skip'    => 0,
    'unmapped_stages' => [],
    'unmapped_users'  => [],
    'rows' => [],
];

if (!$errors) {
    $existingLeadIds = [];
    foreach ($pdo->query("SELECT odoo_lead_id FROM inquiry_families WHERE odoo_lead_id IS NOT NULL") as $r) {
        $existingLeadIds[(int)$r['odoo_lead_id']] = true;
    }
    $existingMsgIds = [];
    foreach ($pdo->query("SELECT odoo_msg_id FROM inquiry_touchpoints WHERE odoo_msg_id IS NOT NULL") as $r) {
        $existingMsgIds[(int)$r['odoo_msg_id']] = true;
    }

    foreach ($leads as $row) {
        $oid    = (int)$row['id'];
        $stage  = trim((string)$row['stage_id_label']);
        $owner  = trim((string)$row['user_id_label']);
        if (!array_key_exists($stage, ODOO_STAGE_TO_STATUS)) {
            $plan['unmapped_stages'][$stage] = ($plan['unmapped_stages'][$stage] ?? 0) + 1;
            $plan['leads_skip']++;
            continue;
        }
        if ($owner !== '' && odoo_user_id_for($owner, $userMap) === null) {
            $plan['unmapped_users'][$owner] = ($plan['unmapped_users'][$owner] ?? 0) + 1;
        }
        if (isset($existingLeadIds[$oid])) {
            $plan['leads_update']++;
        } else {
            $plan['leads_insert']++;
        }
        $plan['rows'][] = [
            'id'     => $oid,
            'name'   => $row['name'],
            'phone'  => $row['phone'],
            'email'  => $row['email_from'],
            'stage'  => $stage,
            'status' => ODOO_STAGE_TO_STATUS[$stage],
            'owner'  => $owner,
        ];
    }
    foreach ($messages as $m) {
        if (isset($existingMsgIds[(int)$m['id']])) $plan['msgs_skip']++;
        else                                       $plan['msgs_insert']++;
    }
    foreach ($activities as $a) {
        // Activities reuse the same odoo_msg_id space (Odoo IDs are unique
        // across mail.message + mail.activity since we never collide here
        // — verified by extracting both ranges).
        if (isset($existingMsgIds[(int)$a['id']])) $plan['acts_skip']++;
        else                                       $plan['acts_insert']++;
    }
}

// ----------- Apply -----------
$applied = null;
if (!$errors && $_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $applied = ['leads_insert' => 0, 'leads_update' => 0, 'msgs_insert' => 0, 'acts_insert' => 0, 'errors' => []];

    $pdo->beginTransaction();
    try {
        // Re-read existing odoo_lead_id → inquiry_families.id map under the transaction.
        $famByOdoo = [];
        foreach ($pdo->query("SELECT id, odoo_lead_id FROM inquiry_families WHERE odoo_lead_id IS NOT NULL") as $r) {
            $famByOdoo[(int)$r['odoo_lead_id']] = (int)$r['id'];
        }

        $insertFam = $pdo->prepare("
            INSERT INTO inquiry_families
                (primary_name, primary_phone, primary_email, source, status,
                 probability, owner_id, expected_start, notes,
                 created_at, updated_at, odoo_lead_id)
            VALUES
                (:n, :p, :e, :src, :s, :prob, :uid, :exp, :notes, :cd, :ud, :oid)
        ");
        $updateFam = $pdo->prepare("
            UPDATE inquiry_families
            SET primary_name = :n, primary_phone = :p, primary_email = :e,
                source = :src, status = :s, probability = :prob,
                owner_id = :uid, notes = :notes
            WHERE odoo_lead_id = :oid
        ");

        foreach ($leads as $row) {
            $oid   = (int)$row['id'];
            $stage = trim((string)$row['stage_id_label']);
            if (!array_key_exists($stage, ODOO_STAGE_TO_STATUS)) continue;

            $name   = trim((string)$row['name']) ?: trim((string)$row['contact_name']) ?: trim((string)$row['partner_name']);
            $phone  = trim((string)$row['phone']);
            $email  = trim((string)$row['email_from']);
            $status = ODOO_STAGE_TO_STATUS[$stage];
            $prob   = (int)round((float)($row['probability'] ?? 0));
            $owner  = odoo_user_id_for($row['user_id_label'] ?? '', $userMap);
            $notes  = "Imported from Odoo CRM (lead id $oid, stage \"$stage\")";
            $created= trim((string)$row['create_date']) ?: date('Y-m-d H:i:s');
            $updated= trim((string)$row['date_open'])   ?: $created;

            $params = [
                ':n' => substr($name, 0, 160),
                ':p' => $phone !== '' ? substr($phone, 0, 40) : null,
                ':e' => $email !== '' ? substr($email, 0, 160) : null,
                ':src' => 'odoo',
                ':s' => $status,
                ':prob' => max(0, min(100, $prob)),
                ':uid' => $owner,
                ':notes' => $notes,
                ':oid' => $oid,
            ];

            if (isset($famByOdoo[$oid])) {
                $updateFam->execute($params);
                $applied['leads_update']++;
            } else {
                $insertFam->execute($params + [':exp' => null, ':cd' => $created, ':ud' => $updated]);
                $famByOdoo[$oid] = (int)$pdo->lastInsertId();
                $applied['leads_insert']++;
            }
        }

        // Touchpoints — messages.
        $existingMsgIds = [];
        foreach ($pdo->query("SELECT odoo_msg_id FROM inquiry_touchpoints WHERE odoo_msg_id IS NOT NULL") as $r) {
            $existingMsgIds[(int)$r['odoo_msg_id']] = true;
        }

        $insertTp = $pdo->prepare("
            INSERT INTO inquiry_touchpoints
                (family_id, kind, occurred_at, follow_up_at, body, odoo_msg_id, created_by, created_at)
            VALUES
                (:fam, :k, :occ, :fup, :body, :oid, :by, :occ2)
        ");

        foreach ($messages as $m) {
            $mid = (int)$m['id'];
            if (isset($existingMsgIds[$mid])) continue;
            $famId = $famByOdoo[(int)$m['lead_id']] ?? null;
            if (!$famId) {
                $applied['errors'][] = "Message $mid: lead {$m['lead_id']} not imported, skipping.";
                continue;
            }
            $tp = odoo_touchpoint_from_message($m);
            $by = odoo_user_id_for($m['author_name'] ?? '', $userMap);
            $occ = trim((string)$m['date']) ?: date('Y-m-d H:i:s');
            $insertTp->execute([
                ':fam' => $famId,
                ':k'   => $tp['kind'],
                ':occ' => $occ,
                ':occ2'=> $occ,
                ':fup' => null,
                ':body'=> mb_substr($tp['body'], 0, 5000),
                ':oid' => $mid,
                ':by'  => $by,
            ]);
            $applied['msgs_insert']++;
        }

        // Touchpoints — pending activities (follow-ups).
        foreach ($activities as $a) {
            $aid = (int)$a['id'];
            if (isset($existingMsgIds[$aid])) continue;
            $famId = $famByOdoo[(int)$a['lead_id']] ?? null;
            if (!$famId) {
                $applied['errors'][] = "Activity $aid: lead {$a['lead_id']} not imported, skipping.";
                continue;
            }
            $assignee = trim((string)$a['assigned_user']);
            $by = odoo_user_id_for($assignee, $userMap) ?? odoo_user_id_for($a['created_by'] ?? '', $userMap);
            $created = trim((string)$a['create_date']) ?: date('Y-m-d H:i:s');
            $deadline = trim((string)$a['date_deadline']);
            $followUp = $deadline !== '' ? "$deadline 09:00:00" : null;
            $body = sprintf(
                "[%s — %s] %s\nAssignee: %s · State: %s",
                $a['activity_type'] ?: 'Activity',
                $a['summary'] ?: 'Pending',
                trim((string)$a['note_text']),
                $assignee ?: '(unassigned)',
                $a['state'] ?: 'planned'
            );
            $insertTp->execute([
                ':fam' => $famId,
                ':k'   => 'call',
                ':occ' => $created,
                ':occ2'=> $created,
                ':fup' => $followUp,
                ':body'=> mb_substr(trim($body), 0, 5000),
                ':oid' => $aid,
                ':by'  => $by,
            ]);
            $applied['acts_insert']++;
        }

        $pdo->commit();
    } catch (Throwable $e) {
        $pdo->rollBack();
        $applied['errors'][] = 'TRANSACTION ROLLED BACK: ' . $e->getMessage();
    }
}

$pageTitle = 'Import Odoo CRM dump';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Import Odoo CRM — 2026 Admission</h1>
        <p class="muted">One-shot importer. Idempotent — re-running updates existing rows by their Odoo IDs.</p>
    </div>
    <div class="page-head-actions">
        <a class="btn" href="/crm/index.php">← Pipeline</a>
    </div>
</div>

<?php if ($errors): ?>
    <div class="flash flash-error">
        <strong>Import disabled:</strong>
        <ul><?php foreach ($errors as $e): ?><li><?= e($e) ?></li><?php endforeach; ?></ul>
    </div>
<?php endif; ?>

<?php if ($applied): ?>
    <div class="flash flash-<?= $applied['errors'] ? 'error' : 'ok' ?>">
        <strong>Import complete.</strong>
        Inserted <?= (int)$applied['leads_insert'] ?> new lead<?= $applied['leads_insert'] === 1 ? '' : 's' ?>,
        updated <?= (int)$applied['leads_update'] ?>,
        added <?= (int)$applied['msgs_insert'] ?> message touchpoint<?= $applied['msgs_insert'] === 1 ? '' : 's' ?>
        and <?= (int)$applied['acts_insert'] ?> pending activit<?= $applied['acts_insert'] === 1 ? 'y' : 'ies' ?>.
        <?php if ($applied['errors']): ?>
            <details><summary><?= count($applied['errors']) ?> warning(s)</summary>
                <ul><?php foreach ($applied['errors'] as $w): ?><li><?= e($w) ?></li><?php endforeach; ?></ul>
            </details>
        <?php endif; ?>
        <a href="/crm/index.php">Open the pipeline →</a>
    </div>
<?php endif; ?>

<?php if (!$errors): ?>
<section class="card">
    <h3>Dry-run summary</h3>
    <ul class="bl-list">
        <li><dt>Leads in CSV</dt><dd><?= count($leads) ?></dd></li>
        <li><dt>· will insert</dt><dd><?= (int)$plan['leads_insert'] ?></dd></li>
        <li><dt>· will update</dt><dd><?= (int)$plan['leads_update'] ?></dd></li>
        <li><dt>· skipped (unmapped stage)</dt><dd><?= (int)$plan['leads_skip'] ?></dd></li>
        <li><dt>Messages in CSV</dt><dd><?= count($messages) ?> (<?= (int)$plan['msgs_insert'] ?> new, <?= (int)$plan['msgs_skip'] ?> already imported)</dd></li>
        <li><dt>Activities in CSV</dt><dd><?= count($activities) ?> (<?= (int)$plan['acts_insert'] ?> new, <?= (int)$plan['acts_skip'] ?> already imported)</dd></li>
    </ul>

    <?php if ($plan['unmapped_stages']): ?>
        <p><strong>Unmapped stages (will be skipped):</strong>
            <?php foreach ($plan['unmapped_stages'] as $s => $n): ?>
                <span class="pill"><?= e($s) ?> × <?= (int)$n ?></span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
    <?php if ($plan['unmapped_users']): ?>
        <p><strong>Odoo users with no matching local user (owner will be left blank):</strong>
            <?php foreach ($plan['unmapped_users'] as $u => $n): ?>
                <span class="pill"><?= e($u) ?> × <?= (int)$n ?></span>
            <?php endforeach; ?>
        </p>
    <?php endif; ?>
</section>

<section class="card">
    <h3>Stage mapping</h3>
    <table class="data-table">
        <thead><tr><th>Odoo stage</th><th>App status</th></tr></thead>
        <tbody>
            <?php foreach (ODOO_STAGE_TO_STATUS as $odoo => $local): ?>
                <tr><td><?= e($odoo) ?></td><td><?= e(crm_status_label($local)) ?> <span class="muted">(<?= e($local) ?>)</span></td></tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<section class="card">
    <h3>Leads (<?= count($plan['rows']) ?>)</h3>
    <table class="data-table">
        <thead><tr><th>Odoo ID</th><th>Name</th><th>Phone</th><th>Email</th><th>Odoo stage</th><th>→ App status</th><th>Owner</th></tr></thead>
        <tbody>
            <?php foreach ($plan['rows'] as $r): ?>
                <tr>
                    <td><?= (int)$r['id'] ?></td>
                    <td><?= e($r['name']) ?></td>
                    <td><?= e($r['phone'] ?: '—') ?></td>
                    <td><?= e($r['email'] ?: '—') ?></td>
                    <td><?= e($r['stage']) ?></td>
                    <td><?= e(crm_status_label($r['status'])) ?></td>
                    <td><?= e($r['owner'] ?: '—') ?></td>
                </tr>
            <?php endforeach; ?>
        </tbody>
    </table>
</section>

<form method="post" class="actions" onsubmit="return confirm('Apply the import now? This is wrapped in a transaction and is idempotent — safe to re-run.');">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <button type="submit" class="btn btn-primary">Run import</button>
    <a href="/crm/index.php" class="link-btn">Cancel</a>
</form>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
