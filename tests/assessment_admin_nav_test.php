<?php
/**
 * The assessment dashboard must show admins a direct link to the curriculum
 * indicator editor, and that link must stay inside the admin-only branch.
 */
declare(strict_types=1);

function check(bool $ok, string $name): void
{
    if (!$ok) {
        fwrite(STDERR, "FAIL $name\n");
        exit(1);
    }
    echo "ok $name\n";
}

$src = file_get_contents(__DIR__ . '/../assessment/index.php');
check(is_string($src) && $src !== '', 'dashboard source readable');

$marker = "if (\$user['role'] === 'admin'):";
$start = strpos($src, '<div class="head-actions">');
$end = strpos($src, '</div>', $start === false ? 0 : $start);
check($start !== false && $end !== false, 'dashboard head actions exist');
$head = substr($src, $start, $end - $start);

$adminAt = strpos($head, $marker);
check($adminAt !== false, 'head actions have an admin-only branch');
$adminBranch = substr($head, $adminAt);
$beforeAdmin = substr($head, 0, $adminAt);

$link = 'href="admin.php?tab=indicators"';
check(str_contains($adminBranch, $link), 'admin branch links to the indicator editor');
check(str_contains($adminBranch, 'Indicators'), 'admin branch names the Indicators destination');
check(str_contains($adminBranch, 'Assessment settings'), 'admin branch exposes an assessment settings entry');
check(!str_contains($beforeAdmin, $link), 'indicator editor link is not shown before the admin check');
check(!str_contains($beforeAdmin, 'Assessment settings'), 'assessment settings entry is not shown before the admin check');

$admin = file_get_contents(__DIR__ . '/../assessment/admin.php');
check(is_string($admin) && str_contains($admin, 'require_admin()'), 'indicator editor remains admin-gated');
check(str_contains($admin, "\$VALID_TABS = ['teachers', 'students', 'indicators', 'rating']"), 'indicators tab still exists');

echo "Assessment admin navigation checks passed\n";
