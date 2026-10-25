<?php
declare(strict_types=1);
require_once __DIR__ . '/../includes/app_workspace.php';
$cases = [
    '/students/index.php' => true,
    '/fees/student.php?id=42&year=2026' => true,
    '/materials/history.php?search=Pink%20Tower' => true,
    '/duties/index.php' => true,
    '/admin.php' => true,
    '//attacker.test/fees/index.php' => false,
    'https://attacker.test/students/index.php' => false,
    '/students/../login.php' => false,
    '/students/%2e%2e/login.php' => false,
    '/students/index.php?x=%0d%0aLocation:evil' => false,
    '/students/index.php#fragment' => false,
    '/students/index.php\\evil' => false,
    '/api/v1/logout.php' => false,
    '/logout.php' => false,
    '/login.php' => false,
    '/includes/config.php' => false,
    '/migrate.php' => false,
    '/oauth/authorize.php' => false,
    '' => false,
];
foreach ($cases as $path => $allowed) {
    if ((app_workspace_path($path) !== null) !== $allowed) throw new RuntimeException('Unexpected destination policy: ' . $path);
}
$pdo = new PDO('sqlite::memory:');
$pdo->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, active INTEGER)');
$pdo->exec('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, revoked_at TEXT)');
$pdo->exec('INSERT INTO users VALUES (1, 1), (2, 0)');
$pdo->exec("INSERT INTO api_tokens VALUES (10, 1, NULL), (11, 1, '2026-01-01'), (12, 2, NULL)");
if (!app_workspace_token_valid($pdo, 10, 1)) throw new RuntimeException('Active login rejected');
foreach ([[10,2], [11,1], [12,2], [999,1], [0,0]] as [$token, $user]) {
    if (app_workspace_token_valid($pdo, $token, $user)) throw new RuntimeException('Invalid session accepted');
}
$pdo->exec("UPDATE api_tokens SET revoked_at = '2026-10-25' WHERE id = 10");
if (app_workspace_token_valid($pdo, 10, 1)) throw new RuntimeException('Logout did not end workspace access');
echo "PASS: workspace destinations, active/deactivated users, identity binding, revocation\n";
