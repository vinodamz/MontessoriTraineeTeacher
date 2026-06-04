<?php
/**
 * crm/index.php — admissions pipeline board.
 *
 * Kanban view of every inquiry family grouped by pipeline status, plus a
 * revenue-projection card at the top (weighted by per-inquiry probability).
 * Cards link through to the detail page.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');
$userId = (int)$user['id'];

// ---- AJAX: drag-and-drop status change -----------------------------------
// Mirrors the tasks/tasks.php op=move pattern: POST + X-Requested-With,
// JSON back. Status-only — probability stays whatever the user last set.
$isAjax = strtolower($_SERVER['HTTP_X_REQUESTED_WITH'] ?? '') === 'xmlhttprequest';
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'move' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
        $id = (int)($_POST['id'] ?? 0);
        $st = $_POST['status'] ?? '';
        if ($id <= 0 || !array_key_exists($st, crm_statuses())) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad input']);
            exit;
        }
        // Read previous status for the audit entry.
        $prev = db()->prepare("SELECT status, lost_reason FROM inquiry_families WHERE id = :id");
        $prev->execute([':id' => $id]);
        $prevRow    = $prev->fetch() ?: [];
        $prevStatus = (string)($prevRow['status'] ?? '');
        $prevReason = $prevRow['lost_reason'] ?? null;

        // When the card lands in "lost", the client must include a reason
        // chosen from crm_lost_reasons(). Moving OUT of lost clears it.
        if ($st === 'lost') {
            $reason = (string)($_POST['lost_reason'] ?? '');
            if (!array_key_exists($reason, crm_lost_reasons())) {
                http_response_code(400);
                echo json_encode(['ok' => false, 'error' => 'lost_reason required', 'need_reason' => true]);
                exit;
            }
            db()->prepare("UPDATE inquiry_families SET status = :s, lost_reason = :r WHERE id = :id")
                ->execute([':s' => $st, ':r' => $reason, ':id' => $id]);
        } else {
            db()->prepare("UPDATE inquiry_families SET status = :s, lost_reason = NULL WHERE id = :id")
                ->execute([':s' => $st, ':id' => $id]);
        }

        if ($prevStatus !== '' && $prevStatus !== $st) {
            $meta = ['from' => $prevStatus, 'to' => $st, 'via' => 'kanban_drag'];
            if ($st === 'lost')          $meta['lost_reason'] = $_POST['lost_reason'] ?? null;
            if ($prevStatus === 'lost')  $meta['prev_lost_reason'] = $prevReason;
            crm_audit_log('status_changed', $id, $meta);
        }
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---- AJAX: reassign owner ------------------------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['op'] ?? '') === 'reassign' && $isAjax) {
    header('Content-Type: application/json; charset=utf-8');
    try {
        csrf_check();
        $id    = (int)($_POST['id'] ?? 0);
        $newId = (int)($_POST['owner_id'] ?? 0);
        if ($id <= 0) {
            http_response_code(400);
            echo json_encode(['ok' => false, 'error' => 'bad input']);
            exit;
        }
        $prev = db()->prepare("SELECT owner_id FROM inquiry_families WHERE id = :id");
        $prev->execute([':id' => $id]);
        $prevOwner = $prev->fetchColumn();
        $prevOwner = $prevOwner === false || $prevOwner === null ? null : (int)$prevOwner;

        db()->prepare("UPDATE inquiry_families SET owner_id = :o WHERE id = :id")
            ->execute([':o' => $newId > 0 ? $newId : null, ':id' => $id]);

        if (($prevOwner ?? 0) !== ($newId > 0 ? $newId : 0)) {
            crm_audit_log('owner_changed', $id, [
                'from' => $prevOwner, 'to' => $newId > 0 ? $newId : null, 'via' => 'kanban_picker',
            ]);
        }
        echo json_encode(['ok' => true]);
        exit;
    } catch (Throwable $e) {
        http_response_code(500);
        echo json_encode(['ok' => false, 'error' => $e->getMessage()]);
        exit;
    }
}

// ---- Owner filter --------------------------------------------------------
// Default to "mine" so each user sees their pipeline first; toggle to "all"
// for the team-wide view or "unassigned" to triage.
//
// When the user types a search term without picking an owner chip, expand
// to the whole team — otherwise a search for someone you don't own returns
// "nothing found" and feels broken.
$hasOwnerParam = isset($_GET['owner']) && $_GET['owner'] !== '';
$hasSearch     = isset($_GET['q']) && trim((string)$_GET['q']) !== '';
$ownerFilter = $hasOwnerParam
    ? (string)$_GET['owner']
    : ($hasSearch ? 'all' : 'mine');
$ownerWhere  = '';
$ownerParam  = null;
if ($ownerFilter === 'mine') {
    $ownerWhere = ' AND f.owner_id = :owner_param';
    $ownerParam = $userId;
} elseif ($ownerFilter === 'unassigned') {
    $ownerWhere = ' AND f.owner_id IS NULL';
} elseif (ctype_digit($ownerFilter) && (int)$ownerFilter > 0) {
    $ownerWhere = ' AND f.owner_id = :owner_param';
    $ownerParam = (int)$ownerFilter;
}
// 'all' → no extra clause.

// Team list for the filter dropdown + the reassign picker on each card.
$team = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('crm', modules) > 0)
    ORDER BY name
")->fetchAll();
$teamById = [];
foreach ($team as $tu) $teamById[(int)$tu['id']] = $tu['name'];

// ---- Free-text search ----------------------------------------------------
// Search is whitespace-tokenized: every word must hit at least one column,
// across name / phone / email / source / notes / status code+label /
// campaign / owner / child / parent / tag.
//
// Phone matching strips non-digits on both sides so "9876543210" finds
// "+91 98765-43210" and vice versa.
//
// Status matching covers both the code ("school_visited") and the human
// label ("School visited") via a CASE expression.
$q     = trim((string)($_GET['q'] ?? ''));
$words = $q === '' ? [] : preg_split('/\s+/', $q);

// Build the CASE expression that maps status codes to their human labels
// once so each word can LIKE against it. Status codes are safe — they
// come from crm_statuses().
$statusCase = "CASE f.status";
foreach (crm_statuses() as $code => $meta) {
    $statusCase .= " WHEN " . db()->quote($code) . " THEN " . db()->quote($meta['label']);
}
$statusCase .= " ELSE f.status END";

// Strip non-digits from a phone column with nested REPLACE — works across
// MySQL versions that don't have REGEXP_REPLACE.
$digitOnly = function (string $col): string {
    return "REPLACE(REPLACE(REPLACE(REPLACE(REPLACE(REPLACE($col,' ',''),'+',''),'-',''),'(',''),')',''),'.','')";
};

$qWhere  = '';
$qParams = [];
$wordConds = [];
foreach ($words as $i => $w) {
    $w = trim($w);
    if ($w === '') continue;
    // 14 unique placeholders (one per text column) + 2 for phone digits.
    $names = [];
    for ($j = 0; $j < 14; $j++) {
        $name = ':w' . $i . '_' . $j;
        $names[$j] = $name;
        $qParams[$name] = '%' . $w . '%';
    }
    $phoneName = ':w' . $i . '_pf';
    $parPhone  = ':w' . $i . '_pp';
    $digits    = preg_replace('/\D/', '', $w);
    // If the word had no digits, fall back to the raw term so non-phone
    // searches still match "Sharma" against the phone column normally.
    $phoneVal  = $digits !== '' ? '%' . $digits . '%' : '%' . $w . '%';
    $qParams[$phoneName] = $phoneVal;
    $qParams[$parPhone]  = $phoneVal;

    $famPhoneCol = $digitOnly('f.primary_phone');
    $parPhoneCol = $digitOnly('p.phone');

    $wordConds[] = "(
           f.primary_name  LIKE {$names[0]}
        OR $famPhoneCol    LIKE {$phoneName}
        OR f.primary_email LIKE {$names[1]}
        OR f.source        LIKE {$names[2]}
        OR f.notes         LIKE {$names[3]}
        OR f.status        LIKE {$names[4]}
        OR {$statusCase}   LIKE {$names[5]}
        OR c.name          LIKE {$names[6]}
        OR u.name          LIKE {$names[7]}
        OR EXISTS (
            SELECT 1 FROM inquiry_children k
             WHERE k.family_id = f.id
               AND (CONCAT_WS(' ', k.first_name, k.last_name) LIKE {$names[8]}
                    OR k.target_grade LIKE {$names[9]})
        )
        OR EXISTS (
            SELECT 1 FROM inquiry_parents p
             WHERE p.family_id = f.id
               AND (p.name LIKE {$names[10]} OR $parPhoneCol LIKE {$parPhone}
                    OR p.email LIKE {$names[11]} OR p.occupation LIKE {$names[12]})
        )
        OR EXISTS (
            SELECT 1 FROM inquiry_family_tags ft
             JOIN crm_tags ct ON ct.id = ft.tag_id
             WHERE ft.family_id = f.id AND ct.name LIKE {$names[13]}
        )
    )";
}
if ($wordConds) {
    $qWhere = ' AND ' . implode(' AND ', $wordConds);
}

$sql = "
    SELECT f.*,
           c.name AS campaign_name,
           u.name AS owner_name,
           (SELECT COUNT(*) FROM inquiry_children k WHERE k.family_id = f.id) AS kid_count,
           (SELECT MIN(t.follow_up_at) FROM inquiry_touchpoints t
             WHERE t.family_id = f.id AND t.follow_up_at >= NOW())            AS next_followup
    FROM inquiry_families f
    LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
    LEFT JOIN users         u ON u.id = f.owner_id
    WHERE f.status <> 'lead'
    $ownerWhere
    $qWhere
    ORDER BY FIELD(f.priority,'urgent','high','normal','low'), f.updated_at DESC
";
$stmt = db()->prepare($sql);
if ($ownerParam !== null) $stmt->bindValue(':owner_param', $ownerParam, PDO::PARAM_INT);
foreach ($qParams as $k => $v) $stmt->bindValue($k, $v);
$stmt->execute();
$rows = $stmt->fetchAll();

// Batch-load substitution vars for the WhatsApp template picker so the
// kanban doesn't go N+1 (one parent + child lookup per card).
$waVarsByFam = crm_wa_vars_for_families(array_column($rows, 'id'));
$waTemplates = crm_wa_templates_active();
$tagsByFam   = crm_tags_for_families(array_column($rows, 'id'));

// Group by status for the kanban columns (pipeline only — leads excluded).
$byStatus = [];
foreach (array_keys(crm_pipeline_statuses()) as $code) $byStatus[$code] = [];
foreach ($rows as $r) {
    $byStatus[$r['status']][] = $r;
}

// Count of leads sitting in /crm/leads.php — shown as a quick-link chip.
$leadCount = (int)db()->query("SELECT COUNT(*) FROM inquiry_families WHERE status = 'lead'")->fetchColumn();

// Counts for the owner-filter chips so users see how many they own at a glance.
$ownerCounts = [];
try {
    $cstmt = db()->query("
        SELECT
            SUM(owner_id = $userId)               AS mine,
            SUM(owner_id IS NULL)                 AS unassigned,
            COUNT(*)                              AS total
        FROM inquiry_families
        WHERE status <> 'lead'
    ")->fetch();
    $ownerCounts = [
        'mine'       => (int)($cstmt['mine'] ?? 0),
        'unassigned' => (int)($cstmt['unassigned'] ?? 0),
        'all'        => (int)($cstmt['total'] ?? 0),
    ];
} catch (Throwable $e) {
    $ownerCounts = ['mine' => 0, 'unassigned' => 0, 'all' => 0];
}

// Build the filter querystring helper so each chip preserves the search term.
$filterUrl = function (string $ownerVal) use ($q): string {
    $params = ['owner' => $ownerVal];
    if ($q !== '') $params['q'] = $q;
    return '/crm/index.php?' . http_build_query($params);
};

$projection = crm_revenue_projection();
$money      = fn(float $v) => '₹' . number_format($v, 0);

// Follow-ups due in the next 7 days — handy reminder above the board.
// Filtered by the same owner chip as the kanban so the card and the
// board stay in sync (Mine → only the user's, Team → everyone's,
// Unassigned → null-owner only, specific teammate → that user's).
$dueSql = "
    SELECT t.id, t.family_id, t.follow_up_at, t.kind, f.primary_name, f.owner_id
    FROM inquiry_touchpoints t
    JOIN inquiry_families f ON f.id = t.family_id
    WHERE t.follow_up_at IS NOT NULL
      AND t.follow_up_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
      AND f.status IN ('" . implode("','", crm_open_statuses()) . "')
      $ownerWhere
    ORDER BY t.follow_up_at
    LIMIT 10
";
$dueStmt = db()->prepare($dueSql);
if ($ownerParam !== null) $dueStmt->bindValue(':owner_param', $ownerParam, PDO::PARAM_INT);
$dueStmt->execute();
$dueFollowups = $dueStmt->fetchAll();

$pageTitle  = 'Admissions';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Admissions</h1>
        <p class="muted"><?= count($rows) ?> inquir<?= count($rows) === 1 ? 'y' : 'ies' ?>
            · <?= $projection['count'] ?> open in funnel</p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/crm/leads.php">
            Leads
            <?php if ($leadCount > 0): ?>
                <span class="pill pill-status-lead" style="margin-left:.35rem;"><?= $leadCount ?></span>
            <?php endif; ?>
        </a>
        <a class="btn" href="/crm/today.php"    title="Today's calls + stagnant leads">Today</a>
        <a class="btn" href="/crm/calendar.php" title="Monthly calendar view">Calendar</a>
        <a class="btn" href="/crm/funnel.php"   title="Conversion funnel report">Funnel</a>
        <a class="btn" href="/crm/campaigns.php">Campaigns</a>
        <a class="btn" href="/crm/tags.php" title="Add or edit inquiry tags">Tags</a>
        <?php if ($user['role'] === 'admin'): ?>
            <a class="btn" href="/crm/stages.php"       title="Manage pipeline stages">Stages</a>
            <a class="btn" href="/crm/probability_rules.php" title="Auto-set probability based on tags">Rules</a>
            <a class="btn" href="/crm/wa_templates.php" title="Manage WhatsApp message templates">WA templates</a>
            <a class="btn" href="/crm/audit.php"        title="Admin: full activity log">Audit</a>
        <?php endif; ?>
        <?php if ($user['role'] === 'admin' && is_readable(__DIR__ . '/../sql/odoo_dump/leads.csv')): ?>
            <a class="btn" href="/crm/import_odoo.php" title="One-shot importer for the Odoo 2026 Admission dump">Import Odoo</a>
        <?php endif; ?>
        <a class="btn btn-primary" href="/crm/edit.php">+ New inquiry</a>
    </div>
</div>

<form method="get" class="crm-pipe-filter card" role="search">
    <div class="crm-pipe-search">
        <label for="crm-q" class="sr-only">Search</label>
        <input id="crm-q" type="search" name="q" value="<?= e($q) ?>"
               placeholder="Search by name, phone, email, child, tag, source, stage…" autocomplete="off">
        <?php if ($hasOwnerParam): ?>
            <input type="hidden" name="owner" value="<?= e($ownerFilter) ?>">
        <?php endif; ?>
        <button class="btn btn-small btn-primary" type="submit">Search</button>
        <?php if ($q !== ''): ?>
            <a class="btn btn-small btn-ghost" href="/crm/index.php">Clear</a>
        <?php endif; ?>
    </div>
    <?php if ($q !== ''): ?>
        <p class="crm-pipe-hint muted small" style="margin:0;">
            <?= count($rows) ?> match<?= count($rows) === 1 ? '' : 'es' ?> for
            <strong>&ldquo;<?= e($q) ?>&rdquo;</strong>
            <?php if (!$hasOwnerParam): ?> · searched the whole team<?php endif; ?>
            · multiple words = all must match (e.g. <em>sharma 9876</em>).
        </p>
    <?php endif; ?>
    <div class="crm-pipe-chips" role="tablist" aria-label="Owner filter">
        <a class="crm-pipe-chip <?= $ownerFilter === 'mine' ? 'on' : '' ?>" href="<?= e($filterUrl('mine')) ?>">
            Mine <span class="pill"><?= (int)$ownerCounts['mine'] ?></span>
        </a>
        <a class="crm-pipe-chip <?= $ownerFilter === 'all' ? 'on' : '' ?>" href="<?= e($filterUrl('all')) ?>">
            Team <span class="pill"><?= (int)$ownerCounts['all'] ?></span>
        </a>
        <a class="crm-pipe-chip <?= $ownerFilter === 'unassigned' ? 'on' : '' ?>" href="<?= e($filterUrl('unassigned')) ?>">
            Unassigned <span class="pill"><?= (int)$ownerCounts['unassigned'] ?></span>
        </a>
        <select class="crm-pipe-owner-select"
                onchange="if(this.value)location.href=this.value;"
                aria-label="Filter by team member">
            <option value="">Pick teammate…</option>
            <?php foreach ($team as $tu):
                $tid = (int)$tu['id'];
                if ($tid === $userId) continue; // already a "Mine" chip
            ?>
                <option value="<?= e($filterUrl((string)$tid)) ?>"
                        <?= $ownerFilter === (string)$tid ? 'selected' : '' ?>>
                    <?= e($tu['name']) ?>
                </option>
            <?php endforeach; ?>
        </select>
    </div>
</form>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Projected revenue</span>
            <span class="tile-value"><?= e($money($projection['weighted'])) ?>/mo</span>
            <span class="tile-sub">Probability-weighted, open funnel</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">Pipeline ceiling</span>
            <span class="tile-value"><?= e($money($projection['pipeline'])) ?>/mo</span>
            <span class="tile-sub">If everything converted</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-nav">
            <span class="tile-label">Open inquiries</span>
            <span class="tile-value"><?= (int)$projection['count'] ?></span>
            <span class="tile-sub">Across all open stages</span>
        </div>
    </li>
    <li>
        <div class="admin-tile <?= $dueFollowups ? 'tile-warn' : 'tile-ok' ?>">
            <span class="tile-label">Follow-ups (7d)</span>
            <span class="tile-value"><?= count($dueFollowups) ?></span>
            <span class="tile-sub">Scheduled in next week</span>
        </div>
    </li>
</ul>

<?php
// Scope label mirrors the owner chip so users know whose follow-ups they're seeing.
$followupScope = $ownerFilter === 'mine'       ? 'Mine'
              : ($ownerFilter === 'all'        ? 'Team'
              : ($ownerFilter === 'unassigned' ? 'Unassigned'
              : ($teamById[(int)$ownerFilter] ?? null)));
?>
<?php if ($dueFollowups): ?>
    <div class="card">
        <h3 style="margin-bottom:.6rem;">
            Upcoming follow-ups
            <?php if ($followupScope): ?>
                <span class="muted small" style="font-weight:400;">· <?= e($followupScope) ?></span>
            <?php endif; ?>
        </h3>
        <ul class="followup-list" role="list">
            <?php foreach ($dueFollowups as $f):
                $ownerLabel = $f['owner_id'] ? ($teamById[(int)$f['owner_id']] ?? null) : 'Unassigned';
            ?>
                <li>
                    <a href="/crm/view.php?id=<?= (int)$f['family_id'] ?>"><?= e($f['primary_name']) ?></a>
                    <span class="muted small">
                        · <?= e(crm_touchpoint_kinds()[$f['kind']] ?? $f['kind']) ?>
                        · <?= e(date('D, j M · H:i', strtotime($f['follow_up_at']))) ?>
                        <?php if ($ownerLabel): ?> · <?= e($ownerLabel) ?><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty">
        <p>No inquiries yet. <a href="/crm/edit.php">Add your first inquiry</a> to kick off the funnel.</p>
    </div>
<?php else: ?>
    <div class="crm-board"
         data-csrf="<?= e(csrf_token()) ?>"
         data-lost-reasons="<?= e(json_encode(crm_lost_reasons(), JSON_UNESCAPED_UNICODE)) ?>">
        <?php foreach (crm_pipeline_statuses() as $code => $meta):
            $cards = $byStatus[$code];
            $colCount = count($cards);
        ?>
            <section class="crm-col crm-col-<?= e($code) ?>" data-status="<?= e($code) ?>">
                <header class="crm-col-head">
                    <h3><?= e($meta['label']) ?></h3>
                    <span class="pill"><?= $colCount ?></span>
                </header>
                <ul class="crm-col-list" data-status="<?= e($code) ?>" role="list">
                    <?php foreach ($cards as $r):
                        $fee  = $r['expected_fee'] !== null ? $money((float)$r['expected_fee']) : '—';
                        $prob = (int)$r['probability'];
                    ?>
                        <li class="crm-card-li" data-inquiry-id="<?= (int)$r['id'] ?>">
                            <article class="crm-card">
                                <div class="crm-card-name">
                                    <a href="/crm/view.php?id=<?= (int)$r['id'] ?>"><?= e($r['primary_name']) ?></a>
                                    <?= crm_tag_pills($tagsByFam[(int)$r['id']] ?? []) ?>
                                </div>
                                <?php if (!empty($r['primary_phone'])): ?>
                                    <div class="crm-card-phone"><?= crm_phone_actions($r['primary_phone'], (int)$r['id'], $waVarsByFam[(int)$r['id']] ?? []) ?></div>
                                <?php endif; ?>
                                <div class="crm-card-meta">
                                    <?php if (($r['priority'] ?? 'normal') !== 'normal'): ?>
                                        <span class="pill pill-prio-<?= e($r['priority']) ?>"><?= e(crm_priority_label($r['priority'])) ?></span>
                                    <?php endif; ?>
                                    <?php if ((int)$r['kid_count'] > 0): ?>
                                        <span class="pill"><?= (int)$r['kid_count'] ?> kid<?= (int)$r['kid_count'] === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                    <?php if ($r['campaign_name']): ?>
                                        <span class="muted small">· <?= e($r['campaign_name']) ?></span>
                                    <?php elseif ($r['source']): ?>
                                        <span class="muted small">· <?= e($r['source']) ?></span>
                                    <?php endif; ?>
                                </div>
                                <div class="crm-card-meta">
                                    <span><?= e($fee) ?>/mo</span>
                                    <span class="muted small">· <?= $prob ?>%</span>
                                </div>
                                <?php if ($r['next_followup']): ?>
                                    <div class="crm-card-meta muted small">
                                        ↻ <?= e(date('j M', strtotime($r['next_followup']))) ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['status'] === 'lost' && !empty($r['lost_reason'])): ?>
                                    <div class="crm-card-meta crm-card-lost">
                                        <span class="pill pill-lost-reason">Lost: <?= e(crm_lost_reason_label($r['lost_reason'])) ?></span>
                                    </div>
                                <?php endif; ?>
                                <div class="crm-card-meta crm-card-owner">
                                    <span class="muted small">Owner:</span>
                                    <strong class="small"><?= e($r['owner_name'] ?? '—') ?></strong>
                                </div>
                                <div class="crm-card-move">
                                    <select class="crm-card-status-select"
                                            aria-label="Move to another stage"
                                            data-current="<?= e($code) ?>">
                                        <option value="">Move to…</option>
                                        <?php foreach (crm_pipeline_statuses() as $sc => $sm): ?>
                                            <option value="<?= e($sc) ?>"
                                                    <?= $sc === $code ? 'hidden' : '' ?>>
                                                <?= e($sm['label']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <select class="crm-card-owner-select"
                                            aria-label="Reassign owner"
                                            data-current="<?= (int)($r['owner_id'] ?? 0) ?>">
                                        <option value="">Reassign to…</option>
                                        <option value="0" <?= (int)($r['owner_id'] ?? 0) === 0 ? 'hidden' : '' ?>>— Unassigned —</option>
                                        <?php foreach ($team as $tu):
                                            $tid = (int)$tu['id'];
                                        ?>
                                            <option value="<?= $tid ?>"
                                                    <?= $tid === (int)($r['owner_id'] ?? 0) ? 'hidden' : '' ?>>
                                                <?= e($tu['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                </div>
                            </article>
                        </li>
                    <?php endforeach; ?>
                </ul>
                <?php if (!$cards): ?>
                    <p class="crm-col-empty muted small">Drop cards here.</p>
                <?php endif; ?>
            </section>
        <?php endforeach; ?>
    </div>

    <script src="https://cdn.jsdelivr.net/npm/sortablejs@1.15.2/Sortable.min.js"></script>
    <script src="/assets/js/crm-board.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-board.js')) ?>"></script>
<?php endif; ?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
