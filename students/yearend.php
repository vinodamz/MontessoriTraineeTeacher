<?php
/**
 * students/yearend.php — bulk year-end transition tool.
 *
 *   GET ?from=YYYY-YY   → roster for the source academic year, grouped by grade
 *                         with per-student action picker.
 *   POST                → for each student: take the picked action (promote /
 *                         repeat / graduate / withdraw / on_break / skip) in a
 *                         single transaction.
 *
 * Designed for the June-rollover use-case: open this in March/April/May, walk
 * through each grade, pick actions in bulk, commit. Anyone marked Promote /
 * Repeat keeps their student row but gets bumped to the next academic year;
 * withdrawal / graduation captures a structured reason for the analytics page.
 *
 * Admins only — bulk changes blast across many rows.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_admin();

const YEAREND_ACTIONS = [
    'skip'       => 'Skip',
    'promote'    => 'Promote to next grade',
    'repeat'     => 'Repeat same grade',
    'graduate'   => 'Graduate',
    'withdraw'   => 'Withdraw',
    'on_break'   => 'On break',
];

$VALID_GRADES = ['Playgroup', 'Nursery', 'LKG', 'UKG'];

$fromYear = $_REQUEST['from'] ?? current_academic_year();
$toYear   = next_academic_year($fromYear);
if (!preg_match('/^\d{4}-\d{2}$/', $fromYear)) $fromYear = current_academic_year();

// ---------- POST: commit picked actions ---------------------------------
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $picks = $_POST['action'] ?? [];

    $promoted = 0; $repeated = 0; $graduated = 0; $withdrawn = 0; $onBreak = 0; $skipped = 0; $errs = 0;
    $pdo = db();
    $pdo->beginTransaction();
    try {
        $upd = $pdo->prepare("
            UPDATE students SET
                grade               = :g,
                academic_year       = :ay,
                enrollment_status   = :es,
                withdrawal_date     = :wd,
                withdrawal_reason   = :wr,
                withdrawal_notes    = :wn
            WHERE id = :id
        ");
        foreach ($picks as $sid => $action) {
            $sid    = (int)$sid;
            if ($sid <= 0 || !array_key_exists($action, YEAREND_ACTIONS) || $action === 'skip') {
                $skipped++; continue;
            }

            // Load the current row so we can compute next grade safely.
            $cur = $pdo->prepare("SELECT id, grade, academic_year FROM students WHERE id = :id");
            $cur->execute([':id' => $sid]);
            $row = $cur->fetch();
            if (!$row) { $errs++; continue; }

            $bind = [
                ':id' => $sid,
                ':g'  => $row['grade'],
                ':ay' => $row['academic_year'] ?? $fromYear,
                ':es' => 'enrolled',
                ':wd' => null, ':wr' => null, ':wn' => null,
            ];

            if ($action === 'promote') {
                $next = next_grade($row['grade']);
                if ($next === null) {
                    // UKG can't promote — auto-graduate instead, recording reason.
                    $bind[':es'] = 'graduated';
                    $bind[':wd'] = date('Y-m-d');
                    $bind[':wr'] = 'completed';
                    $graduated++;
                } else {
                    $bind[':g']  = $next;
                    $bind[':ay'] = $toYear;
                    $bind[':es'] = 'enrolled';
                    $promoted++;
                }
            } elseif ($action === 'repeat') {
                $bind[':ay'] = $toYear;
                $bind[':es'] = 'enrolled';
                $repeated++;
            } elseif ($action === 'graduate') {
                $bind[':es'] = 'graduated';
                $bind[':wd'] = $_POST['date'][$sid] ?? date('Y-m-d');
                $bind[':wr'] = $_POST['reason'][$sid] ?? 'completed';
                $bind[':wn'] = trim($_POST['notes'][$sid] ?? '') ?: null;
                if (!array_key_exists($bind[':wr'], WITHDRAWAL_REASONS)) $bind[':wr'] = 'completed';
                $graduated++;
            } elseif ($action === 'withdraw') {
                $reason = $_POST['reason'][$sid] ?? '';
                $notes  = trim($_POST['notes'][$sid] ?? '');
                if (!array_key_exists($reason, WITHDRAWAL_REASONS)) $reason = 'other';
                if ($reason === 'other' && $notes === '') {
                    $errs++; continue;     // require notes when "other"
                }
                $bind[':es'] = 'withdrawn';
                $bind[':wd'] = $_POST['date'][$sid] ?? date('Y-m-d');
                $bind[':wr'] = $reason;
                $bind[':wn'] = $notes ?: null;
                $withdrawn++;
            } elseif ($action === 'on_break') {
                $notes  = trim($_POST['notes'][$sid] ?? '');
                $bind[':es'] = 'on_break';
                $bind[':wd'] = $_POST['date'][$sid] ?? date('Y-m-d');
                $bind[':wr'] = 'other';
                $bind[':wn'] = $notes ?: null;
                $onBreak++;
            }

            $upd->execute($bind);
        }
        $pdo->commit();
        $summary = [];
        if ($promoted)  $summary[] = "$promoted promoted";
        if ($repeated)  $summary[] = "$repeated repeated";
        if ($graduated) $summary[] = "$graduated graduated";
        if ($withdrawn) $summary[] = "$withdrawn withdrawn";
        if ($onBreak)   $summary[] = "$onBreak on break";
        if ($skipped)   $summary[] = "$skipped skipped";
        if ($errs)      $summary[] = "$errs error" . ($errs === 1 ? '' : 's');
        flash_set('ok', 'Class promotion committed: ' . ($summary ? implode(' · ', $summary) : 'no changes'));

        // Notify other admins about the bulk transition (skip self).
        if ($promoted + $repeated + $graduated + $withdrawn + $onBreak > 0) {
            require_once __DIR__ . '/../includes/notify.php';
            $admIds = $pdo->query("
                SELECT id FROM users
                WHERE active = 1 AND role = 'admin' AND id <> " . (int)$user['id']
            )->fetchAll(PDO::FETCH_COLUMN);
            if ($admIds) {
                notify(
                    $admIds, 'students', 'yearend_committed',
                    "Classes promoted: $fromYear → $toYear",
                    implode(' · ', $summary) . "\nBy " . $user['name'],
                    '/students/yearend.php?from=' . $fromYear
                );
            }
        }
    } catch (Throwable $e) {
        $pdo->rollBack();
        flash_set('error', 'Class promotion failed: ' . $e->getMessage());
    }
    redirect('/students/yearend.php?from=' . $fromYear);
}

// ---------- GET: render roster grouped by grade -------------------------
$stmt = db()->prepare("
    SELECT s.*, u.name AS teacher_name
    FROM students s
    LEFT JOIN users u ON u.id = s.teacher_id
    WHERE s.academic_year = :ay
      AND COALESCE(s.enrollment_status, 'enrolled') = 'enrolled'
    ORDER BY FIELD(s.grade,'Playgroup','Nursery','LKG','UKG'), s.first_name, s.last_name
");
$stmt->execute([':ay' => $fromYear]);
$students = $stmt->fetchAll();

// Group by grade.
$byGrade = ['Playgroup' => [], 'Nursery' => [], 'LKG' => [], 'UKG' => []];
foreach ($students as $s) {
    $byGrade[$s['grade']][] = $s;
}

$years = academic_years_in_use();

$pageTitle = "Promote classes · $fromYear → $toYear";
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Promote classes</h1>
        <p class="muted">
            <strong><?= e($fromYear) ?></strong> → <strong><?= e($toYear) ?></strong>
            · <?= count($students) ?> currently-enrolled student<?= count($students) === 1 ? '' : 's' ?>
            in <?= e($fromYear) ?>
        </p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<form method="get" class="filter-row card">
    <div class="field">
        <label for="from">Source academic year</label>
        <select id="from" name="from">
            <?php foreach ($years as $y): ?>
                <option value="<?= e($y) ?>" <?= $fromYear === $y ? 'selected' : '' ?>><?= e($y) ?></option>
            <?php endforeach; ?>
        </select>
    </div>
    <div class="actions">
        <button class="btn btn-primary" type="submit">Load</button>
    </div>
</form>

<?php if (!$students): ?>
    <div class="empty">
        <p>No enrolled students in <?= e($fromYear) ?>. Pick a different source year or add new admissions for <?= e($toYear) ?> via the
            <a href="/students/edit.php">+ New student</a> page.</p>
    </div>
<?php else: ?>
<form method="post" id="yearendForm">
    <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
    <input type="hidden" name="from"  value="<?= e($fromYear) ?>">

    <div class="ye-help card">
        <strong>How this works:</strong> for each student pick an action.
        <strong>Promote</strong> = move to the next grade and bump to <?= e($toYear) ?>.
        <strong>Repeat</strong> = keep the same grade, still bump to <?= e($toYear) ?>.
        <strong>Graduate</strong> = finished UKG (auto-set when you Promote a UKG student).
        <strong>Withdraw</strong> reveals a reason picker + notes.
        <strong>Skip</strong> leaves the student as-is in <?= e($fromYear) ?>.
    </div>

    <?php foreach ($byGrade as $grade => $rows): if (!$rows) continue;
        $nextG = next_grade($grade);
        $defaultAction = $nextG === null ? 'graduate' : 'promote';
    ?>
        <details class="card ye-grade" open>
            <summary>
                <span class="<?= e(grade_badge_class($grade)) ?>"><?= e($grade) ?></span>
                <strong><?= count($rows) ?></strong> student<?= count($rows) === 1 ? '' : 's' ?>
                <?php if ($nextG): ?>· will normally go to <strong><?= e($nextG) ?></strong><?php else: ?>· UKG: defaults to <strong>Graduate</strong><?php endif; ?>
                <button class="btn btn-ghost small" type="button" data-bulk-grade="<?= e($grade) ?>" data-bulk-action="<?= e($defaultAction) ?>">
                    Set everyone to default
                </button>
            </summary>

            <ul class="ye-list">
                <?php foreach ($rows as $s):
                    $sid  = (int)$s['id'];
                    $full = trim($s['first_name'] . ' ' . $s['last_name']);
                ?>
                    <li class="ye-row" data-ye-grade="<?= e($grade) ?>">
                        <div class="ye-who">
                            <span class="student-avatar" style="--card: <?= e(user_color($sid)) ?>;"><?= e(user_initials($full)) ?></span>
                            <div>
                                <div class="ye-name"><a href="/students/view.php?id=<?= $sid ?>" target="_blank"><?= e($full) ?></a></div>
                                <div class="muted small">
                                    <?php if (!empty($s['teacher_name'])): ?><?= e($s['teacher_name']) ?><?php endif; ?>
                                    <?php if (!empty($s['admission_number'])): ?> · Adm #<?= e($s['admission_number']) ?><?php endif; ?>
                                </div>
                            </div>
                        </div>

                        <div class="ye-action">
                            <select name="action[<?= $sid ?>]" class="ye-select">
                                <?php foreach (YEAREND_ACTIONS as $code => $label):
                                    // For UKG: hide "Promote" since there's no next grade; pre-select "graduate".
                                    if ($nextG === null && $code === 'promote') continue;
                                ?>
                                    <option value="<?= e($code) ?>" <?= $code === 'skip' ? 'selected' : '' ?>><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                        </div>

                        <div class="ye-detail" hidden>
                            <input type="date" name="date[<?= $sid ?>]" value="<?= e(date('Y-m-d')) ?>" aria-label="Date">
                            <select name="reason[<?= $sid ?>]" aria-label="Reason">
                                <option value="">— reason —</option>
                                <?php foreach (WITHDRAWAL_REASONS as $code => $label): ?>
                                    <option value="<?= e($code) ?>"><?= e($label) ?></option>
                                <?php endforeach; ?>
                            </select>
                            <input type="text" name="notes[<?= $sid ?>]" maxlength="500" placeholder="Notes (required if reason = Other)">
                        </div>
                    </li>
                <?php endforeach; ?>
            </ul>
        </details>
    <?php endforeach; ?>

    <div class="actions section-h-spaced">
        <button class="btn btn-primary" type="submit"
                onclick="return confirm('Commit the picked actions? This will update every student\'s academic_year / grade / status in one transaction.')">
            Commit year-end
        </button>
        <a class="btn btn-ghost" href="/students/yearend.php?from=<?= e($fromYear) ?>">Reset</a>
    </div>
</form>

<script>
(() => {
    // Show withdrawal/graduate/on_break detail row only when the action needs it.
    const needsDetail = a => ['withdraw','graduate','on_break'].includes(a);
    document.querySelectorAll('.ye-row').forEach(row => {
        const sel = row.querySelector('.ye-select');
        const det = row.querySelector('.ye-detail');
        const sync = () => { det.hidden = !needsDetail(sel.value); };
        sel.addEventListener('change', sync);
        sync();
    });
    // Bulk-set for a grade.
    document.querySelectorAll('[data-bulk-action]').forEach(btn => {
        btn.addEventListener('click', () => {
            const g = btn.dataset.bulkGrade;
            const a = btn.dataset.bulkAction;
            document.querySelectorAll('.ye-row[data-ye-grade="' + g + '"] .ye-select').forEach(sel => {
                // For UKG with no "promote" option, fall through to "graduate".
                const has = Array.from(sel.options).some(o => o.value === a);
                sel.value = has ? a : 'graduate';
                sel.dispatchEvent(new Event('change', { bubbles: true }));
            });
        });
    });
})();
</script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
