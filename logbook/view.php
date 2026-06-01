<?php
/**
 * logbook/view.php — single log entry detail.
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';
require_once __DIR__ . '/../includes/logbook.php';

$user = require_module('logbook');
$id   = (int)($_GET['id'] ?? 0);

$stmt = db()->prepare("
    SELECT e.*, s.first_name, s.last_name, s.grade, u.name AS by_name
    FROM logbook_entries e
    LEFT JOIN students s ON s.id = e.student_id
    LEFT JOIN users u    ON u.id = e.logged_by
    WHERE e.id = :id
");
$stmt->execute([':id' => $id]);
$e = $stmt->fetch();
if (!$e) { http_response_code(404); echo 'Entry not found.'; exit; }

$def  = logbook_type($e['log_type']);
$meta = logbook_meta($e['meta_json']);
$studentName = trim(($e['first_name'] ?? '') . ' ' . ($e['last_name'] ?? ''));

$pageTitle = logbook_type_label($e['log_type']) . ' — Logbook';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1><?= logbook_type_icon($e['log_type']) ?> <?= e(logbook_type_label($e['log_type'])) ?></h1>
        <p class="muted"><?= e(date('l, j F Y · H:i', strtotime($e['occurred_at']))) ?></p>
    </div>
    <div class="actionbar">
        <a class="btn" href="/logbook/index.php">← Logbook</a>
        <button class="btn" onclick="window.print()">Print</button>
    </div>
</div>

<div class="card">
    <dl class="dl-grid">
        <?php if ($studentName !== ''): ?>
            <dt>Student</dt><dd><strong><?= e($studentName) ?></strong><?= $e['grade'] ? ' · ' . e($e['grade']) : '' ?></dd>
        <?php endif; ?>
        <?php if ($e['title']): ?><dt>Title</dt><dd><?= e($e['title']) ?></dd><?php endif; ?>
        <?php foreach (($def['fields'] ?? []) as $key => $f):
            if (empty($meta[$key])) continue; ?>
            <dt><?= e($f['label']) ?></dt><dd><?= nl2br(e((string)$meta[$key])) ?></dd>
        <?php endforeach; ?>
        <?php if ($e['details']): ?>
            <dt>Notes</dt><dd style="white-space:pre-wrap;"><?= e($e['details']) ?></dd>
        <?php endif; ?>
        <?php if (($def['notify'] ?? false)): ?>
            <dt>Parent notified</dt>
            <dd>
                <?php if ($e['parent_notified']): ?>
                    <span class="pill pill-ok">Yes</span>
                    <?php if ($e['notified_at']): ?> · <?= e(date('j M · H:i', strtotime($e['notified_at']))) ?><?php endif; ?>
                <?php else: ?>
                    <span class="pill pill-warn">Not yet</span>
                <?php endif; ?>
            </dd>
        <?php endif; ?>
        <dt>Logged by</dt><dd><?= e($e['by_name'] ?: '—') ?></dd>
    </dl>

    <?php if ($e['photo_path']): ?>
        <div style="margin-top:1rem;">
            <a href="/logbook/photo.php?id=<?= (int)$e['id'] ?>" target="_blank">
                <?php $ext = strtolower(pathinfo($e['photo_path'], PATHINFO_EXTENSION)); ?>
                <?php if ($ext === 'pdf'): ?>
                    <span class="btn">📎 View attachment (PDF)</span>
                <?php else: ?>
                    <img src="/logbook/photo.php?id=<?= (int)$e['id'] ?>" alt="Attachment" style="max-width:100%; border-radius:8px; border:1px solid var(--line);">
                <?php endif; ?>
            </a>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
