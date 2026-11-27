<?php
declare(strict_types=1);
// Exercise the real guard in separate PHP requests; no database/config is used.
$auth = dirname(__DIR__) . '/includes/auth.php';
$cases = [
    ['missing both', null, null, false],
    ['missing posted token', 'session-token', null, false],
    ['missing session token', null, 'session-token', false],
    ['array token', 'session-token', ['session-token'], false],
    ['wrong token', 'session-token', 'other-token', false],
    ['matching token', 'session-token', 'session-token', true],
];
foreach ($cases as [$name, $expected, $sent, $valid]) {
    $script = 'session_start(); require ' . var_export($auth, true) . ';'
        . '$_SESSION = ' . var_export($expected === null ? [] : ['_csrf' => $expected], true) . ';'
        . '$_POST = ' . var_export($sent === null ? [] : ['_csrf' => $sent], true) . ';'
        . 'csrf_check(); echo "accepted";';
    $process = proc_open([PHP_BINARY, '-r', $script], [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    if (!is_resource($process)) throw new RuntimeException('Could not run CSRF check');
    fclose($pipes[0]);
    $output = stream_get_contents($pipes[1]); fclose($pipes[1]);
    $errors = stream_get_contents($pipes[2]); fclose($pipes[2]);
    $exit = proc_close($process);
    $wanted = $valid ? 'accepted' : 'Bad CSRF token.';
    if ($exit !== 0 || $errors !== '' || $output !== $wanted) throw new RuntimeException($name . ': ' . $output . $errors);
    echo "PASS $name\n";
}
