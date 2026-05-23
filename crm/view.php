<?php
/**
 * crm/view.php — inquiry detail page.
 *
 * Shows everything about a family in one place: primary contact, children,
 * parents, the touchpoint timeline, and (when offered) a promote-to-student
 * form. All mutations on this page POST back here:
 *
 *   op=status        → change pipeline status (+ optional probability)
 *   op=touchpoint    → add a touchpoint
 *   op=touchpoint_del→ delete a touchpoint
 *   op=promote       → enroll selected children (creates students)
 *   op=delete        → hard delete the inquiry (cascades to children/parents/touchpoints)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/crm.php';

$user = require_module('crm');

$id = (int)($_GET['id'] ?? 0);
if ($id <= 0) { http_response_code(404); echo 'Bad id.'; exit; }

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = $_POST['op'] ?? '';
    $pdo = db();

    if ($op === 'qualify') {
        // Promote a lead into the pipeline — sets status='new' and resets
        // probability to the pipeline's default. No-op if already promoted.
        $stmt = $pdo->prepare("
            UPDATE inquiry_families
            SET status = 'new',
                probability = CASE WHEN status = 'lead' THEN :p ELSE probability END
            WHERE id = :id AND status = 'lead'
        ");
        $stmt->execute([':p' => crm_default_probability('new'), ':id' => $id]);
        if ($stmt->rowCount() > 0) {
            crm_audit_log('lead_qualified', $id);
        }
        flash_set('ok', 'Added to pipeline. They\'re in the "New inquiry" column now.');
        redirect('/crm/view.php?id=' . $id);
    }

    if ($op === 'status') {
        $st = $_POST['status'] ?? '';
        if (!array_key_exists($st, crm_statuses())) {
            flash_set('error', 'Unknown status.');
            redirect('/crm/view.php?id=' . $id);
        }
        $prob = isset($_POST['probability'])
            ? max(0, min(100, (int)$_POST['probability']))
            : crm_default_probability($st);
        $prevStmt = $pdo->prepare("SELECT status FROM inquiry_families WHERE id = :id");
        $prevStmt->execute([':id' => $id]);
        $prevStatus = (string)$prevStmt->fetchColumn();
        $pdo->prepare("UPDATE inquiry_families SET status=:s, probability=:p WHERE id=:id")
            ->execute([':s' => $st, ':p' => $prob, ':id' => $id]);
        if ($prevStatus !== $st) {
            crm_audit_log('status_changed', $id, [
                'from' => $prevStatus, 'to' => $st, 'via' => 'detail_form',
            ]);
        }
        flash_set('ok', 'Status updated.');
        redirect('/crm/view.php?id=' . $id);
    }

    if ($op === 'touchpoint') {
        $kind = $_POST['kind'] ?? 'note';
        if (!array_key_exists($kind, crm_touchpoint_kinds())) $kind = 'note';
        $occurred = trim($_POST['occurred_at'] ?? '') ?: date('Y-m-d H:i:s');
        $follow   = trim($_POST['follow_up_at'] ?? '') ?: null;
        $body     = trim($_POST['body'] ?? '');
        if ($body === '') {
            flash_set('error', 'Touchpoint body is required.');
            redirect('/crm/view.php?id=' . $id);
        }
        $pdo->prepare("
            INSERT INTO inquiry_touchpoints
                (family_id, kind, occurred_at, follow_up_at, body, created_by)
            VALUES (:f, :k, :o, :fu, :b, :by)
        ")->execute([
            ':f'  => $id, ':k' => $kind,
            ':o'  => $occurred, ':fu' => $follow,
            ':b'  => $body, ':by' => $user['id'],
        ]);
        crm_audit_log('touchpoint_added', $id, ['kind' => $kind], 'touchpoint', (int)$pdo->lastInsertId());
        flash_set('ok', 'Touchpoint logged.');
        redirect('/crm/view.php?id=' . $id . '#timeline');
    }

    if ($op === 'touchpoint_del') {
        $tid = (int)($_POST['tid'] ?? 0);
        $pdo->prepare("DELETE FROM inquiry_touchpoints WHERE id=:t AND family_id=:f")
            ->execute([':t' => $tid, ':f' => $id]);
        crm_audit_log('touchpoint_deleted', $id, null, 'touchpoint', $tid);
        flash_set('ok', 'Touchpoint removed.');
        redirect('/crm/view.php?id=' . $id . '#timeline');
    }

    if ($op === 'promote') {
        $assignments = [];
        $kids = $_POST['kid_id'] ?? [];
        foreach ($kids as $i => $kidId) {
            $kidId = (int)$kidId;
            if (!$kidId)                                        continue;
            if (empty($_POST['kid_enroll'][$i]))                continue;
            $grade   = $_POST['kid_grade'][$i]   ?? '';
            $teacher = (int)($_POST['kid_teacher'][$i] ?? 0);
            if (!in_array($grade, ['Playgroup','Nursery','LKG','UKG'], true) || !$teacher) {
                flash_set('error', 'Each enrolling child needs a grade and a teacher.');
                redirect('/crm/view.php?id=' . $id . '#enroll');
            }
            $assignments[$kidId] = ['grade' => $grade, 'teacher_id' => $teacher];
        }
        if (!$assignments) {
            flash_set('error', 'Tick at least one child to enroll.');
            redirect('/crm/view.php?id=' . $id . '#enroll');
        }
        $pdo->beginTransaction();
        try {
            $newIds = crm_promote_inquiry($id, $assignments, (int)$user['id']);
            $pdo->commit();
        } catch (Throwable $e) {
            $pdo->rollBack();
            flash_set('error', 'Enroll failed: ' . $e->getMessage());
            redirect('/crm/view.php?id=' . $id . '#enroll');
        }
        crm_audit_log('enrolled', $id, [
            'student_ids' => $newIds,
            'count'       => count($newIds),
        ]);
        flash_set('ok', count($newIds) . ' child' . (count($newIds) === 1 ? '' : 'ren')
                       . ' enrolled and added to the students module.');
        redirect('/crm/view.php?id=' . $id);
    }

    if ($op === 'delete') {
        // Snapshot the family name before delete so the audit row can show
        // who/what was removed even after the FK NULLs out family_id.
        $nameStmt = $pdo->prepare("SELECT primary_name FROM inquiry_families WHERE id = :id");
        $nameStmt->execute([':id' => $id]);
        $deletedName = (string)$nameStmt->fetchColumn();
        $pdo->prepare("DELETE FROM inquiry_families WHERE id=:id")->execute([':id' => $id]);
        crm_audit_log('inquiry_deleted', null, ['primary_name' => $deletedName, 'original_id' => $id]);
        flash_set('ok', 'Inquiry deleted.');
        redirect('/crm/index.php');
    }
}

// ---- Load data -----------------------------------------------------------
$family = (function() use ($id) {
    $stmt = db()->prepare("
        SELECT f.*, u.name AS owner_name, c.name AS campaign_name, c.channel AS campaign_channel
        FROM inquiry_families f
        LEFT JOIN users u         ON u.id = f.owner_id
        LEFT JOIN crm_campaigns c ON c.id = f.campaign_id
        WHERE f.id = :id
    ");
    $stmt->execute([':id' => $id]);
    return $stmt->fetch();
})();
if (!$family) { http_response_code(404); echo 'Inquiry not found.'; exit; }

$children = (function() use ($id) {
    $s = db()->prepare("SELECT * FROM inquiry_children WHERE family_id=:id ORDER BY dob, id");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();
$parents = (function() use ($id) {
    $s = db()->prepare("SELECT * FROM inquiry_parents WHERE family_id=:id ORDER BY is_primary DESC, id");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();
$touchpoints = (function() use ($id) {
    $s = db()->prepare("
        SELECT t.*, u.name AS by_name
        FROM inquiry_touchpoints t
        LEFT JOIN users u ON u.id = t.created_by
        WHERE t.family_id = :id
        ORDER BY t.occurred_at DESC
    ");
    $s->execute([':id' => $id]);
    return $s->fetchAll();
})();

$teachers = db()->query("
    SELECT id, name FROM users
    WHERE active = 1 AND (role = 'admin' OR FIND_IN_SET('montessori', modules) > 0
                                       OR FIND_IN_SET('students', modules) > 0)
    ORDER BY name
")->fetchAll();

$canEnroll = !in_array($family['status'], ['enrolled','lost'], true);
$unpromotedKids = array_values(array_filter($children, fn($k) => empty($k['promoted_student_id'])));
$isLead         = $family['status'] === 'lead';
$touchpointCount = count($touchpoints);

// Audit log — admin-only feed of every action against this family. Wrapped
// in try/catch so a missing inquiry_audit table (e.g. migrate_017 not yet
// run on a fresh install) doesn't blank out the detail page.
$auditRows = [];
if (($user['role'] ?? '') === 'admin') {
    try {
        $s = db()->prepare("
            SELECT a.*, u.name AS by_name
            FROM inquiry_audit a
            LEFT JOIN users u ON u.id = a.user_id
            WHERE a.family_id = :id
            ORDER BY a.created_at DESC, a.id DESC
            LIMIT 100
        ");
        $s->execute([':id' => $id]);
        $auditRows = $s->fetchAll();
    } catch (Throwable $e) {
        $auditRows = [];
    }
}

$money = fn(float $v) => '₹' . number_format($v, 0);

// WhatsApp template substitution vars + active templates for the picker.
$waVarsAll   = crm_wa_vars_for_families([$id]);
$waVars      = $waVarsAll[$id] ?? [];
$waTemplates = crm_wa_templates_active();

$pageTitle = $family['primary_name'] . ' — Admissions';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= e($family['primary_name']) ?></h1>
        <p class="muted">
            <a href="/crm/index.php">← Pipeline</a>
            · <span class="pill pill-status-<?= e($family['status']) ?>"><?= e(crm_status_label($family['status'])) ?></span>
            <?php if (($family['priority'] ?? 'normal') !== 'normal'): ?>
                · <span class="pill pill-prio-<?= e($family['priority']) ?>"><?= e(crm_priority_label($family['priority'])) ?></span>
            <?php endif; ?>
            <?php if ($family['campaign_name']): ?>
                · <span class="pill"><?= e($family['campaign_name']) ?></span>
            <?php elseif ($family['source']): ?> · <?= e($family['source']) ?><?php endif; ?>
            <?php if ($family['owner_name']): ?> · owner: <?= e($family['owner_name']) ?><?php endif; ?>
        </p>
    </div>
    <div class="actionbar">
        <?php if ($isLead): ?>
            <form method="post" style="display:inline;"
                  onsubmit="<?= $touchpointCount === 0 ? "return confirm('No touchpoints logged yet — promote this lead anyway?');" : '' ?>">
                <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                <input type="hidden" name="op" value="qualify">
                <button class="btn btn-primary" title="Move into the pipeline as a New inquiry">
                    Add to pipeline →
                </button>
            </form>
        <?php endif; ?>
        <a class="btn" href="/crm/edit.php?id=<?= $id ?>">Edit</a>
        <form method="post" style="display:inline;"
              onsubmit="return confirm('Delete this inquiry permanently? Touchpoints and unpromoted children will be lost.')">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="delete">
            <button class="btn btn-ghost">Delete</button>
        </form>
    </div>
</div>

<?php if ($isLead): ?>
    <div class="flash flash-ok" style="background:#fdf0d3; border-color:#f0c98a; color:#78420a;">
        This is still a <strong>lead</strong> — it isn't on the pipeline board yet.
        Log at least one touchpoint, then hit <strong>Add to pipeline →</strong> above to promote them to <em>New inquiry</em>.
    </div>
<?php endif; ?>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 320px;">
        <h3>Contact</h3>
        <dl class="dl-grid">
            <dt>Phone</dt><dd><?= $family['primary_phone'] ? crm_phone_actions($family['primary_phone'], (int)$family['id'], $waVars) : '—' ?></dd>
            <dt>Email</dt><dd><?= e((string)$family['primary_email']) ?: '—' ?></dd>
            <dt>Expected fee</dt><dd>
                <?= $family['expected_fee'] !== null ? e($money((float)$family['expected_fee'])) . '/mo' : '—' ?>
            </dd>
            <dt>Expected start</dt><dd><?= e((string)$family['expected_start']) ?: '—' ?></dd>
            <dt>Probability</dt><dd><?= (int)$family['probability'] ?>%</dd>
        </dl>
        <?php if ($family['notes']): ?>
            <p class="muted small" style="margin-top:.6rem; white-space:pre-wrap;"><?= e($family['notes']) ?></p>
        <?php endif; ?>
    </div>

    <div class="card" style="flex: 1 1 320px;">
        <h3>Move stage</h3>
        <form method="post" class="row">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="status">
            <div class="field">
                <label>Status</label>
                <select name="status">
                    <?php foreach (crm_statuses() as $code => $meta): ?>
                        <option value="<?= e($code) ?>" <?= $family['status'] === $code ? 'selected' : '' ?>>
                            <?= e($meta['label']) ?>
                        </option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="field">
                <label>Probability %</label>
                <input name="probability" type="number" min="0" max="100" step="5"
                       value="<?= (int)$family['probability'] ?>">
            </div>
            <div class="actions"><button class="btn btn-primary">Save</button></div>
        </form>
        <p class="muted small">Tip: when status is <em>Offered</em>, scroll down to the
            <a href="#enroll">Enroll children</a> form to promote them into the students module.</p>
    </div>
</div>

<div class="row" style="align-items: stretch;">
    <div class="card" style="flex: 1 1 320px;">
        <h3>Children</h3>
        <?php if (!$children): ?>
            <p class="muted">None added yet. <a href="/crm/edit.php?id=<?= $id ?>">Add one</a>.</p>
        <?php else: ?>
            <ul class="team-list">
                <?php foreach ($children as $k):
                    $full = trim($k['first_name'] . ' ' . ($k['last_name'] ?? ''));
                    $age  = $k['dob'] ? (int)((time() - strtotime($k['dob'])) / (365.25 * 86400)) : null;
                ?>
                    <li class="team-row" style="--card: <?= e(user_color((int)$k['id'])) ?>;">
                        <div class="team-dot"><?= e(user_initials($full)) ?></div>
                        <div>
                            <div class="team-name"><?= e($full) ?></div>
                            <div class="team-meta">
                                <?= $age !== null ? "$age yr · " : '' ?>
                                <?= e((string)$k['target_grade']) ?: 'no grade set' ?>
                                <?php if ($k['promoted_student_id']): ?>
                                    · <a href="/students/view.php?id=<?= (int)$k['promoted_student_id'] ?>">enrolled →</a>
                                <?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>

    <div class="card" style="flex: 1 1 320px;">
        <h3>Parents</h3>
        <?php if (!$parents): ?>
            <p class="muted">None added yet.</p>
        <?php else: ?>
            <ul class="team-list">
                <?php foreach ($parents as $p): ?>
                    <li class="team-row" style="--card: <?= e(user_color((int)$p['id'])) ?>;">
                        <div class="team-dot"><?= e(user_initials($p['name'])) ?></div>
                        <div>
                            <div class="team-name">
                                <?= e($p['name']) ?>
                                <?php if ($p['is_primary']): ?> <span class="pill">primary</span><?php endif; ?>
                            </div>
                            <div class="team-meta">
                                <?= e($p['relation']) ?>
                                <?php if ($p['phone']): ?> · <?= e($p['phone']) ?><?php endif; ?>
                                <?php if ($p['email']): ?> · <?= e($p['email']) ?><?php endif; ?>
                            </div>
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        <?php endif; ?>
    </div>
</div>

<?php if ($canEnroll && $unpromotedKids): ?>
<div class="card" id="enroll">
    <h3>Enroll children</h3>
    <p class="muted small">Tick the children to enroll. Each needs a grade and a class teacher.
       Saving creates real student records (with parents copied across) and moves
       this inquiry to <em>Enrolled</em>.</p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="promote">
        <table class="admin-table">
            <thead><tr><th></th><th>Child</th><th>Grade</th><th>Teacher</th></tr></thead>
            <tbody>
            <?php foreach ($unpromotedKids as $i => $k): ?>
                <tr>
                    <td>
                        <input type="hidden" name="kid_id[<?= $i ?>]" value="<?= (int)$k['id'] ?>">
                        <input type="checkbox" name="kid_enroll[<?= $i ?>]" value="1" checked>
                    </td>
                    <td><?= e(trim($k['first_name'] . ' ' . ($k['last_name'] ?? ''))) ?></td>
                    <td>
                        <select name="kid_grade[<?= $i ?>]">
                            <?php foreach (['Playgroup','Nursery','LKG','UKG'] as $g): ?>
                                <option value="<?= $g ?>" <?= ($k['target_grade'] ?? '') === $g ? 'selected' : '' ?>><?= $g ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                    <td>
                        <select name="kid_teacher[<?= $i ?>]">
                            <option value="0">— pick a teacher —</option>
                            <?php foreach ($teachers as $t): ?>
                                <option value="<?= (int)$t['id'] ?>"><?= e($t['name']) ?></option>
                            <?php endforeach; ?>
                        </select>
                    </td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
        <div class="actions section-h-spaced">
            <button class="btn btn-primary">Enroll selected</button>
        </div>
    </form>
</div>
<?php endif; ?>

<div class="card" id="timeline">
    <h3>Touchpoints</h3>
    <form method="post" class="row" style="margin-bottom: 1rem;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="touchpoint">
        <div class="field">
            <label>Kind</label>
            <select name="kind">
                <?php foreach (crm_touchpoint_kinds() as $code => $label): ?>
                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                <?php endforeach; ?>
            </select>
        </div>
        <div class="field">
            <label>When</label>
            <input name="occurred_at" type="datetime-local" value="<?= e(date('Y-m-d\TH:i')) ?>">
        </div>
        <div class="field">
            <label>Follow-up by (optional)</label>
            <input name="follow_up_at" type="datetime-local">
        </div>
        <div class="field" style="flex: 2 1 280px;">
            <label>What happened</label>
            <input name="body" required maxlength="500" placeholder="e.g. Called mum — touring Tue 10am">
        </div>
        <div class="actions"><button class="btn btn-primary">Log</button></div>
    </form>

    <?php if (!$touchpoints): ?>
        <p class="muted">No touchpoints logged yet.</p>
    <?php else: ?>
        <ul class="timeline" role="list">
            <?php foreach ($touchpoints as $t): ?>
                <li class="timeline-row">
                    <div class="timeline-when">
                        <strong><?= e(date('j M Y', strtotime($t['occurred_at']))) ?></strong>
                        <span class="muted small"><?= e(date('H:i', strtotime($t['occurred_at']))) ?></span>
                    </div>
                    <div class="timeline-body">
                        <span class="pill"><?= e(crm_touchpoint_kinds()[$t['kind']] ?? $t['kind']) ?></span>
                        <span><?= e($t['body']) ?></span>
                        <?php if ($t['follow_up_at']): ?>
                            <div class="muted small">Follow up by <?= e(date('j M H:i', strtotime($t['follow_up_at']))) ?></div>
                        <?php endif; ?>
                        <?php if ($t['by_name']): ?>
                            <div class="muted small">— <?= e($t['by_name']) ?></div>
                        <?php endif; ?>
                    </div>
                    <form method="post" class="timeline-del"
                          onsubmit="return confirm('Delete this touchpoint?')">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="touchpoint_del">
                        <input type="hidden" name="tid" value="<?= (int)$t['id'] ?>">
                        <button class="link-btn" title="Delete">×</button>
                    </form>
                </li>
            <?php endforeach; ?>
        </ul>
    <?php endif; ?>
</div>

<?php if (($user['role'] ?? '') === 'admin'): ?>
<div class="card" id="audit-log">
    <h3>Activity log <span class="muted small">(admin only · last 100 events)</span></h3>
    <?php if (!$auditRows): ?>
        <p class="muted">No actions logged yet.</p>
    <?php else: ?>
        <table class="data-table audit-table">
            <thead>
                <tr><th style="width:8.5rem;">When</th><th>Action</th><th>By</th><th>Details</th></tr>
            </thead>
            <tbody>
            <?php foreach ($auditRows as $a):
                $meta = $a['meta_json'] ? json_decode($a['meta_json'], true) : null;
                $metaText = '';
                if (is_array($meta)) {
                    $parts = [];
                    foreach ($meta as $k => $v) {
                        if (is_scalar($v)) $parts[] = e($k) . '=' . e((string)$v);
                    }
                    $metaText = implode(' · ', $parts);
                }
            ?>
                <tr>
                    <td>
                        <strong><?= e(date('j M', strtotime($a['created_at']))) ?></strong>
                        <span class="muted small"><?= e(date('H:i', strtotime($a['created_at']))) ?></span>
                    </td>
                    <td><span class="pill"><?= e(crm_audit_action_label($a['action'])) ?></span></td>
                    <td><?= e($a['by_name'] ?: '—') ?></td>
                    <td class="muted small"><?= $metaText ?: '—' ?></td>
                </tr>
            <?php endforeach; ?>
            </tbody>
        </table>
    <?php endif; ?>
</div>
<?php endif; ?>

<?php if ($waTemplates): ?>
<script id="wa-templates" type="application/json"><?= json_encode($waTemplates, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES) ?></script>
<script src="/assets/js/crm-wa-templates.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-wa-templates.js')) ?>"></script>
<?php endif; ?>
<script src="/assets/js/crm-phone-log.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/crm-phone-log.js')) ?>"></script>

<?php require __DIR__ . '/../includes/footer.php'; ?>
