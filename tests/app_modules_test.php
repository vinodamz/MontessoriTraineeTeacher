<?php
declare(strict_types=1);

require_once __DIR__ . '/../includes/api.php';

$_SERVER['HTTP_HOST'] = 'school.example';
$_SERVER['HTTPS'] = 'on';

set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
try {
    $admin = api_app_modules(['id' => 1, 'role' => 'admin', 'modules' => []]);
} finally {
    restore_error_handler();
}
$byKey = array_column($admin, null, 'key');
set_error_handler(static function (int $severity, string $message): never {
    throw new ErrorException($message, 0, $severity);
});
foreach (['wacrm', 'n8n'] as $key) {
    if (($byKey[$key]['web_url'] ?? '') !== "https://school.example/$key/index.php") {
        throw new RuntimeException('Missing workspace route for ' . $key);
    }
    if ($byKey[$key]['native'] !== false) {
        throw new RuntimeException('External workspace incorrectly marked native');
    }
}
$restricted = api_app_modules(['id' => 2, 'role' => 'staff', 'modules' => ['materials']]);
if (array_intersect(['wacrm', 'n8n'], array_column($restricted, 'key'))) {
    throw new RuntimeException('Unauthorized external module exposed');
}
$allowed = api_app_modules(['id' => 2, 'role' => 'staff', 'modules' => ['materials', 'wacrm']]);
if (!in_array('wacrm', array_column($allowed, 'key'), true)) {
    throw new RuntimeException('Authorized CRM missing');
}
echo "PASS: external app routes and module permissions\n";

require_once __DIR__ . '/../includes/app_workspace.php';

function module_keys(array $user, bool $materials = false, bool $plans = false): array
{
    return array_column(api_app_modules_for_access($user, 'https://school.example', $materials, $plans), 'key');
}

function expect_keys(array $actual, array $expected, string $scenario): void
{
    sort($actual);
    sort($expected);
    if ($actual !== $expected) throw new RuntimeException($scenario . ': ' . json_encode($actual));
}

$basic = ['id' => 2, 'role' => 'teacher', 'modules' => []];
$personal = ['duties', 'my_leave', 'notifications'];
expect_keys(module_keys($basic), $personal, 'No module grants');
expect_keys(module_keys($basic, true), [...$personal, 'materials'], 'Materials duty only');
expect_keys(module_keys($basic, false, true), [...$personal, 'plans'], 'Weekly plan duty only');
expect_keys(module_keys($basic, true, true), [...$personal, 'materials', 'plans'], 'Both duties');
expect_keys(module_keys($basic), $personal, 'Duty access removed');
$direct = api_app_modules(['id' => 2, 'role' => 'teacher', 'modules' => ['materials', 'plans']]);
expect_keys(array_column($direct, 'key'), [...$personal, 'materials', 'plans'], 'Shared access helpers retain explicit module grants');
$personalEntries = api_app_modules_for_access($basic, 'https://school.example', false, false);
$leave = array_column($personalEntries, null, 'key')['my_leave'];
if ($leave['native'] !== false || $leave['web_url'] !== 'https://school.example/staff/leave.php') {
    throw new RuntimeException('Personal leave must bypass the Staff module screen');
}
foreach (['teacher', 'non_teaching', 'staff'] as $role) {
    $user = ['id' => 2, 'role' => $role, 'modules' => ['admin', 'duties_admin', 'feedback', 'surveys', 'grades', 'mcp_admin']];
    expect_keys(module_keys($user), $personal, 'Admin tools cannot be granted through module names');
}
expect_keys(module_keys(['id' => 2, 'role' => 'teacher', 'modules' => ['staff']]), ['duties', 'notifications', 'staff'], 'Staff reaches leave through native Staff');
foreach (['fees', 'expenses', 'students'] as $grant) {
    $expected = [...$personal, $grant, 'money'];
    if ($grant === 'students') $expected[] = 'my_day';
    expect_keys(module_keys(['id' => 2, 'role' => 'teacher', 'modules' => [$grant]]), $expected, 'Money access: ' . $grant);
}
expect_keys(module_keys(['id' => 2, 'role' => 'teacher', 'modules' => ['montessori']]), [...$personal, 'montessori', 'my_day'], 'Assessment grants My Day');
foreach (['duties', 'duties_admin', 'notifications', 'money', 'my_day', 'feedback', 'surveys', 'grades', 'mcp_admin', 'admin'] as $key) {
    if (!isset($byKey[$key])) throw new RuntimeException('Admin missing ' . $key);
}
foreach ([...$admin, ...$personalEntries] as $module) {
    $path = parse_url($module['web_url'], PHP_URL_PATH);
    if (app_workspace_path($path) === null || !is_file(__DIR__ . '/..' . $path)) {
        throw new RuntimeException('Unreachable workspace destination: ' . $path);
    }
}
echo "PASS: personal, admin, duty-derived, money and classroom access; all admin destinations exist and are allowed\n";
restore_error_handler();
