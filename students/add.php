<?php
/**
 * students/add.php — the ONE door for adding a child (Phase 0 of the UX
 * roadmap). Replaces the side-by-side "+ New student" and "+ New admission
 * (parent form)" toolbar buttons that confused even the owner.
 *
 * Two clearly explained paths:
 *   - Office fills the details  → /students/edit.php (classic form)
 *   - Parent fills the form     → /students/intake_new.php (draft row +
 *                                  shareable link; admin approves later)
 */
declare(strict_types=1);

require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/../includes/functions.php';

$user = require_login();
if ($user['role'] !== 'admin' && !user_has_module($user, 'students')) {
    http_response_code(403);
    echo 'Forbidden — you do not have access to the students module.';
    exit;
}
$isAdmin = $user['role'] === 'admin';

$pageTitle = 'Add a child';
require __DIR__ . '/../includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>Add a child</h1>
        <p class="muted">Pick how the details get filled in.</p>
    </div>
    <div class="actionbar">
        <a class="btn btn-ghost" href="/students/index.php">← Students</a>
    </div>
</div>

<div class="app-grid" style="max-width: 760px;">
    <a class="app-card" href="/students/edit.php">
        <div class="app-icon app-icon-students">
            <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                <path d="M12 13a4 4 0 1 0 0-8 4 4 0 0 0 0 8Z"/><path d="M4 21a8 8 0 0 1 16 0"/>
            </svg>
        </div>
        <div class="app-text">
            <div class="app-name">Office fills the details</div>
            <div class="app-subtitle">You type everything in now — name, parents, contacts. The child appears on the list immediately.</div>
        </div>
    </a>

    <?php if ($isAdmin): ?>
        <a class="app-card" href="/students/intake_new.php">
            <div class="app-icon app-icon-admissions">
                <svg viewBox="0 0 24 24" width="34" height="34" fill="none" stroke="currentColor" stroke-width="1.8" stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">
                    <path d="M4 4h16l-6 8v6l-4 2v-8L4 4Z"/>
                </svg>
            </div>
            <div class="app-text">
                <div class="app-name">Parent fills the form</div>
                <div class="app-subtitle">You enter just the name and class, then share a link. The parent fills the admission form (photos, IDs included); you review and approve. Can pre-fill from the admissions pipeline.</div>
            </div>
        </a>
    <?php else: ?>
        <div class="app-card" style="opacity:.55; pointer-events:none;">
            <div class="app-text">
                <div class="app-name">Parent fills the form</div>
                <div class="app-subtitle">Admins only — ask an admin to send the parent form link.</div>
            </div>
        </div>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/../includes/footer.php'; ?>
