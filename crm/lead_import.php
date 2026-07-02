<?php
/**
 * crm/lead_import.php — CSV bulk import of leads.
 *
 *   GET                → upload form + template link.
 *   POST step=preview  → parse + show per-row validation; carry rows forward
 *                        via session so commit re-uses validated data.
 *   POST step=commit   → insert valid rows as inquiry_families(status='lead').
 *
 * CSV headers (case-insensitive, any order). Required: name.
 *   name | phone | email | campaign | priority | child_age | owner | notes
 *
 * - campaign  : matched against crm_campaigns.name (case-insensitive). Unknown
 *               → row keeps NULL campaign_id (still imported).
 * - priority  : one of low|normal|high|urgent. Default 'normal'.
 * - owner     : users.name OR numeric users.id. Unknown → null.
 * - child_age : prepended to notes (`Child age: …`).
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

const LEAD_CSV_MAX_BYTES = 1 * 1024 * 1024;   // 1 MB
const LEAD_CSV_MAX_ROWS  = 2000;

$ALLOWED = ['name','phone','email','campaign','priority','child_age','owner','notes'];

start_session_once();

// ---- Lookup maps ---------------------------------------------------------
$campaigns = [];
foreach (db()->query("SELECT id, name FROM crm_campaigns") as $c) {
    $campaigns[mb_strtolower(trim($c['name']))] = (int)$c['id'];
}
$users = [];
foreach (db()->query("SELECT id, name FROM users WHERE active=1") as $u) {
    $users[mb_strtolower(trim($u['name']))] = (int)$u['id'];
}
$userIds = array_flip($users);

function parse_csv(string $body, array $allowed): array
{
    // fgetcsv over a stream — splitting on raw newlines broke any quoted
    // field containing a line break (e.g. a two-line notes cell).
    $fh = fopen('php://temp', 'r+');
    fwrite($fh, $body);
    rewind($fh);
    $rows = [];
    while (($r = fgetcsv($fh, 0, ',', '"', '\\')) !== false) {
        if ($r === [null] || (count($r) === 1 && trim((string)$r[0]) === '')) continue;
        $rows[] = $r;
    }
    fclose($fh);
    if (!$rows) return ['headers' => [], 'data' => []];
    $headers = array_map(fn($h) => mb_strtolower(trim((string)$h)), array_shift($rows));
    $headers = array_map(fn($h) => in_array($h, $allowed, true) ? $h : null, $headers);
    return ['headers' => $headers, 'data' => $rows];
}

function row_to_record(array $row, array $headers, array $campaigns, array $users, int $defaultOwner): array
{
    $rec = ['name'=>'', 'phone'=>'', 'email'=>'', 'campaign'=>'', 'priority'=>'',
            'child_age'=>'', 'owner'=>'', 'notes'=>''];
    foreach ($headers as $i => $h) {
        if ($h !== null && isset($row[$i])) $rec[$h] = trim((string)$row[$i]);
    }
    $errs = [];
    if ($rec['name'] === '') $errs[] = 'name is required';
    $priority = mb_strtolower($rec['priority']) ?: 'normal';
    if (!array_key_exists($priority, crm_priorities())) {
        $errs[] = "unknown priority '$rec[priority]'";
        $priority = 'normal';
    }
    $campId = null;
    if ($rec['campaign'] !== '') {
        $campId = $campaigns[mb_strtolower($rec['campaign'])] ?? null;
        if ($campId === null) $errs[] = "unknown campaign '$rec[campaign]' (will import with no campaign)";
    }
    $ownerId = $defaultOwner;
    if ($rec['owner'] !== '') {
        if (ctype_digit($rec['owner']) && isset(array_flip($users)[(int)$rec['owner']])) {
            // numeric id that matches an active user
            $ownerId = (int)$rec['owner'];
        } elseif (isset($users[mb_strtolower($rec['owner'])])) {
            $ownerId = $users[mb_strtolower($rec['owner'])];
        } else {
            $errs[] = "unknown owner '$rec[owner]' (will import as me)";
        }
    }

    return [
        'rec'      => $rec,
        'errors'   => $errs,
        'priority' => $priority,
        'campaign_id' => $campId,
        'owner_id' => $ownerId,
    ];
}

// ---- POST handlers -------------------------------------------------------
$preview   = null;
$step      = $_POST['step'] ?? '';

if ($step === 'preview') {
    csrf_check();
    $body = '';
    if (!empty($_FILES['file']['tmp_name'])) {
        if ($_FILES['file']['size'] > LEAD_CSV_MAX_BYTES) {
            flash_set('error', 'CSV is over the 1 MB limit.');
            redirect('/crm/lead_import.php');
        }
        $body = (string)file_get_contents($_FILES['file']['tmp_name']);
    }
    if ($body === '') {
        $body = (string)($_POST['paste'] ?? '');
    }
    if (trim($body) === '') {
        flash_set('error', 'Upload a CSV or paste rows below.');
        redirect('/crm/lead_import.php');
    }

    $p = parse_csv($body, $ALLOWED);
    if (!in_array('name', $p['headers'], true)) {
        flash_set('error', 'CSV must include a `name` column (case-insensitive).');
        redirect('/crm/lead_import.php');
    }
    if (count($p['data']) > LEAD_CSV_MAX_ROWS) {
        flash_set('error', 'CSV has more than ' . LEAD_CSV_MAX_ROWS . ' rows.');
        redirect('/crm/lead_import.php');
    }

    $records = [];
    $seenPhones = [];
    foreach ($p['data'] as $row) {
        $rec = row_to_record($row, $p['headers'], $campaigns, $users, (int)$user['id']);
        // Flag duplicates: a phone already in the CRM, or repeated within
        // this very file (classic re-upload of last month's list).
        $digits = substr(preg_replace('/\D/', '', $rec['rec']['phone']), -10);
        if ($digits !== '') {
            if (isset($seenPhones[$digits])) {
                $rec['errors'][] = 'duplicate phone within this file (row ' . $seenPhones[$digits] . ') — will be skipped';
                $rec['dup'] = true;
            } elseif (crm_find_lead_by_phone($rec['rec']['phone']) !== null) {
                $rec['errors'][] = 'phone already exists in the CRM — will be skipped';
                $rec['dup'] = true;
            }
            $seenPhones[$digits] = count($records) + 1;
        }
        $records[] = $rec;
    }
    $_SESSION['_lead_import'] = ['records' => $records];
    $preview = $records;
}

if ($step === 'commit') {
    csrf_check();
    $records = $_SESSION['_lead_import']['records'] ?? null;
    if (!$records) {
        flash_set('error', 'Preview expired — re-upload and try again.');
        redirect('/crm/lead_import.php');
    }
    $asWalkIns   = !empty($_POST['as_walk_ins']);
    $status      = $asWalkIns ? 'new'  : 'lead';
    $defaultProb = crm_default_probability($status);

    $ok = 0; $skipped = 0;
    $ins = db()->prepare("
        INSERT INTO inquiry_families
            (primary_name, primary_phone, primary_email,
             status, priority, probability, campaign_id, owner_id, notes)
        VALUES (:n, :p, :e, :st, :pr, :prob, :c, :o, :notes)
    ");
    foreach ($records as $r) {
        $hasFatal = !empty($r['dup']);
        foreach ($r['errors'] as $err) {
            if (str_starts_with($err, 'name')) { $hasFatal = true; break; }
        }
        if ($hasFatal) { $skipped++; continue; }
        $notes = $r['rec']['notes'];
        if ($r['rec']['child_age'] !== '') {
            $notes = "Child age: " . $r['rec']['child_age']
                   . ($notes !== '' ? "\n\n$notes" : '');
        }
        $ins->execute([
            ':n'    => $r['rec']['name'],
            ':p'    => $r['rec']['phone'] ?: null,
            ':e'    => $r['rec']['email'] ?: null,
            ':st'   => $status,
            ':pr'   => $r['priority'],
            ':prob' => $defaultProb,
            ':c'    => $r['campaign_id'],
            ':o'    => $r['owner_id'],
            ':notes'=> $notes ?: null,
        ]);
        $ok++;
    }
    unset($_SESSION['_lead_import']);
    $label = $asWalkIns ? 'walk-in' : 'lead';
    flash_set('ok', "Imported $ok $label" . ($ok === 1 ? '' : 's')
                   . ($skipped ? " · skipped $skipped invalid row" . ($skipped === 1 ? '' : 's') : ''));
    redirect($asWalkIns ? '/crm/index.php' : '/crm/leads.php');
}

$pageTitle = 'Bulk import leads';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Bulk import leads</h1>
        <p class="muted"><a href="/crm/leads.php">← Leads</a></p>
    </div>
</div>

<?php if ($preview === null): ?>
    <form method="post" enctype="multipart/form-data" class="card">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="step" value="preview">
        <h3>Step 1 — upload</h3>
        <p class="muted small">CSV columns (case-insensitive, any order):
            <code>name</code> (required), <code>phone</code>, <code>email</code>,
            <code>campaign</code>, <code>priority</code>, <code>child_age</code>,
            <code>owner</code>, <code>notes</code>.</p>
        <div class="field">
            <label>CSV file</label>
            <input type="file" name="file" accept=".csv,text/csv">
        </div>
        <div class="field">
            <label>…or paste rows here</label>
            <textarea name="paste" rows="6" placeholder="name,phone,campaign,priority&#10;Anita Sharma,+91 …,Instagram,high"></textarea>
        </div>
        <div class="actions"><button class="btn btn-primary">Preview</button></div>
    </form>
<?php else: ?>
    <div class="card">
        <h3>Step 2 — preview <span class="muted small">(<?= count($preview) ?> row<?= count($preview) === 1 ? '' : 's' ?>)</span></h3>
        <div class="table-scroll">
            <table class="admin-table">
                <thead>
                    <tr><th>#</th><th>Name</th><th>Phone</th><th>Campaign</th><th>Priority</th><th>Owner</th><th>Issues</th></tr>
                </thead>
                <tbody>
                <?php foreach ($preview as $i => $r):
                    $bad = !empty($r['errors']) && (
                        str_starts_with($r['errors'][0] ?? '', 'name')
                    );
                    $ownerLbl = $userIds[$r['owner_id']] ?? '—';
                ?>
                    <tr class="<?= $bad ? 'is-inactive' : '' ?>">
                        <td><?= $i + 1 ?></td>
                        <td><?= e($r['rec']['name'] ?: '—') ?></td>
                        <td><?= e($r['rec']['phone']) ?></td>
                        <td><?= e($r['rec']['campaign']) ?></td>
                        <td><span class="pill pill-prio-<?= e($r['priority']) ?>"><?= e(crm_priority_label($r['priority'])) ?></span></td>
                        <td><?= e((string)$ownerLbl) ?></td>
                        <td class="muted small">
                            <?= $r['errors'] ? e(implode('; ', $r['errors'])) : 'ok' ?>
                        </td>
                    </tr>
                <?php endforeach; ?>
                </tbody>
            </table>
        </div>
        <form method="post" class="section-h-spaced">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="step" value="commit">
            <label class="checkbox" style="margin-bottom:.5rem;">
                <input type="checkbox" name="as_walk_ins" value="1">
                <span>Import as walk-ins (skip lead stage — these rows land directly on the pipeline board)</span>
            </label>
            <div class="actions">
                <button class="btn btn-primary">Commit valid rows</button>
                <a class="btn btn-ghost" href="/crm/lead_import.php">Re-upload</a>
            </div>
        </form>
    </div>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
