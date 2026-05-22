<?php
/**
 * recruitment/index.php — hiring pipeline kanban board.
 *
 * Kanban grouped by recruit_candidates.status, with at-a-glance tiles
 * (open / due interviews / hires this month / avg time-to-hire). Cards link
 * through to view.php. Drag-and-drop posts to /recruitment/api.php op=move.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/recruitment.php';

$user = require_module('recruitment');

$rows = db()->query("
    SELECT c.*,
           (SELECT COUNT(*) FROM recruit_evaluations e WHERE e.candidate_id = c.id) AS eval_count,
           (SELECT MIN(i.occurred_at) FROM recruit_interviews i
             WHERE i.candidate_id = c.id AND i.occurred_at >= NOW())               AS next_interview
    FROM recruit_candidates c
    ORDER BY FIELD(c.priority,'urgent','high','normal','low'), c.updated_at DESC
")->fetchAll();

$byStatus = [];
foreach (array_keys(recruit_statuses()) as $code) $byStatus[$code] = [];
foreach ($rows as $r) $byStatus[$r['status']][] = $r;

// Dashboard tiles.
$openCount  = 0;
foreach (recruit_open_statuses() as $s) $openCount += count($byStatus[$s] ?? []);
$hiredThisMonth = (int)db()->query("
    SELECT COUNT(*) FROM recruit_candidates
    WHERE status = 'hired'
      AND hired_at >= DATE_FORMAT(NOW(), '%Y-%m-01')
")->fetchColumn();

$upcoming = db()->query("
    SELECT i.id, i.candidate_id, i.occurred_at, i.stage, i.location,
           c.first_name, c.last_name
    FROM recruit_interviews i
    JOIN recruit_candidates c ON c.id = i.candidate_id
    WHERE i.occurred_at >= NOW()
      AND i.occurred_at <= DATE_ADD(NOW(), INTERVAL 7 DAY)
    ORDER BY i.occurred_at
    LIMIT 10
")->fetchAll();

$pageTitle  = 'Recruitment';
$wideLayout = true;
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Recruitment</h1>
        <p class="muted"><?= count($rows) ?> candidate<?= count($rows) === 1 ? '' : 's' ?>
            · <?= $openCount ?> open in pipeline</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-primary" href="/recruitment/edit.php">+ New candidate</a>
    </div>
</div>

<ul class="admin-tiles" role="list" style="grid-template-columns: repeat(auto-fill, minmax(220px, 1fr));">
    <li>
        <div class="admin-tile">
            <span class="tile-label">Open candidates</span>
            <span class="tile-value"><?= (int)$openCount ?></span>
            <span class="tile-sub">Across all open stages</span>
        </div>
    </li>
    <li>
        <div class="admin-tile <?= $upcoming ? 'tile-warn' : 'tile-ok' ?>">
            <span class="tile-label">Interviews (7d)</span>
            <span class="tile-value"><?= count($upcoming) ?></span>
            <span class="tile-sub">Scheduled in next week</span>
        </div>
    </li>
    <li>
        <div class="admin-tile tile-ok">
            <span class="tile-label">Hired this month</span>
            <span class="tile-value"><?= (int)$hiredThisMonth ?></span>
            <span class="tile-sub">Since the 1st</span>
        </div>
    </li>
    <li>
        <div class="admin-tile">
            <span class="tile-label">In demo / observation</span>
            <span class="tile-value"><?= count($byStatus['demo'] ?? []) ?></span>
            <span class="tile-sub">Awaiting practical evaluation</span>
        </div>
    </li>
</ul>

<?php if ($upcoming): ?>
    <div class="card">
        <h3 style="margin-bottom:.6rem;">Upcoming interviews</h3>
        <ul class="followup-list" role="list">
            <?php foreach ($upcoming as $u): ?>
                <li>
                    <a href="/recruitment/view.php?id=<?= (int)$u['candidate_id'] ?>">
                        <?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?>
                    </a>
                    <span class="muted small">
                        · <?= e(recruit_interview_stages()[$u['stage']] ?? $u['stage']) ?>
                        · <?= e(date('D, j M · H:i', strtotime($u['occurred_at']))) ?>
                        <?php if ($u['location']): ?> · <?= e($u['location']) ?><?php endif; ?>
                    </span>
                </li>
            <?php endforeach; ?>
        </ul>
    </div>
<?php endif; ?>

<?php if (!$rows): ?>
    <div class="empty">
        <p>No candidates yet. <a href="/recruitment/edit.php">Add your first candidate</a> to start the hiring pipeline.</p>
    </div>
<?php else: ?>
    <div class="crm-board" data-csrf="<?= e(csrf_token()) ?>">
        <?php foreach (recruit_statuses() as $code => $meta):
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
                        $full = trim(($r['first_name'] ?? '') . ' ' . ($r['last_name'] ?? ''));
                    ?>
                        <li class="crm-card-li" data-candidate-id="<?= (int)$r['id'] ?>">
                            <a class="crm-card" href="/recruitment/view.php?id=<?= (int)$r['id'] ?>">
                                <div class="crm-card-name"><?= e($full) ?></div>
                                <div class="crm-card-meta">
                                    <?php if (($r['priority'] ?? 'normal') !== 'normal'): ?>
                                        <span class="pill pill-prio-<?= e($r['priority']) ?>"><?= e(recruit_priorities()[$r['priority']] ?? $r['priority']) ?></span>
                                    <?php endif; ?>
                                    <span class="pill"><?= e(recruit_position_label($r['position_applied'])) ?></span>
                                    <?php if ((int)$r['eval_count'] > 0): ?>
                                        <span class="muted small">· <?= (int)$r['eval_count'] ?> eval<?= (int)$r['eval_count'] === 1 ? '' : 's' ?></span>
                                    <?php endif; ?>
                                </div>
                                <?php if ($r['source'] || $r['years_experience'] !== null): ?>
                                    <div class="crm-card-meta muted small">
                                        <?php if ($r['years_experience'] !== null): ?>
                                            <?= (int)$r['years_experience'] ?> yr exp
                                        <?php endif; ?>
                                        <?php if ($r['source']): ?>
                                            <?= $r['years_experience'] !== null ? ' · ' : '' ?><?= e($r['source']) ?>
                                        <?php endif; ?>
                                    </div>
                                <?php endif; ?>
                                <?php if ($r['next_interview']): ?>
                                    <div class="crm-card-meta muted small">
                                        ↻ <?= e(date('j M H:i', strtotime($r['next_interview']))) ?>
                                    </div>
                                <?php endif; ?>
                            </a>
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
    <script src="/assets/js/recruit-board.js?v=<?= e((string)@filemtime(__DIR__ . '/../assets/js/recruit-board.js')) ?>"></script>
<?php endif; ?>

<?php require __DIR__ . '/../includes/footer.php'; ?>
