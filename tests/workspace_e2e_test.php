<?php
declare(strict_types=1);

// HTTP tests run the real bridge in a disposable server with fixture authentication.
// No production database/config is loaded. Node tests execute the real bridge JS.
$root = dirname(__DIR__);
$tmp = sys_get_temp_dir() . '/lg-workspace-e2e-' . bin2hex(random_bytes(6));
foreach (['app', 'includes', 'sessions', 'wacrm', 'n8n'] as $dir) mkdir($tmp . '/' . $dir, 0700, true);
copy($root . '/app/open.php', $tmp . '/app/open.php');
copy($root . '/includes/app_workspace.php', $tmp . '/includes/app_workspace.php');
copy($root . '/includes/api_cors.php', $tmp . '/includes/api_cors.php');
foreach (['app/public.php', 'app/external.php', 'app/external_view.php', 'app/external.js', 'wacrm/index.php', 'n8n/index.php',
    'includes/auth.php', 'includes/functions.php', 'includes/grades.php', 'includes/errors.php', 'includes/external_app_view.php'] as $file) {
    copy($root . '/' . $file, $tmp . '/' . $file);
}
file_put_contents($tmp . '/includes/db.php', <<<'FIXTURE'
<?php
function db(): PDO {
    static $pdo;
    return $pdo ??= new PDO('sqlite:' . __DIR__ . '/../fixture.sqlite', null, null,
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC]);
}
FIXTURE);
file_put_contents($tmp . '/includes/config.php', '<?php return ' . var_export([
    'app' => ['session_name' => 'LG_WORKSPACE_TEST', 'timezone' => 'UTC'],
], true) . ';');
// The shared production header reads unrelated tables. Keep the view shell local.
file_put_contents($tmp . '/includes/header.php', '<?php ?><!doctype html><html><head><title><?= e($pageTitle) ?></title></head><body><main>');
file_put_contents($tmp . '/includes/footer.php', '</main></body></html>');
file_put_contents($tmp . '/router.php', <<<'FIXTURE'
<?php
$path = parse_url($_SERVER['REQUEST_URI'], PHP_URL_PATH);
if ($path === '/fake-crm/api/sso/mtt') {
    $token = $_GET['token'] ?? '';
    $parts = explode('.', $token);
    $payload = count($parts) === 2 ? json_decode(base64_decode(strtr($parts[0], '-_', '+/')), true) : null;
    $signature = count($parts) === 2 ? rtrim(strtr(base64_encode(hash_hmac('sha256', $parts[0], 'fixture-secret', true)), '+/', '-_'), '=') : '';
    if (!$payload || !hash_equals($signature, $parts[1]) || ($payload['exp'] ?? 0) < time()) {
        http_response_code(401); echo 'Invalid fixture SSO'; return true;
    }
    setcookie('FIXTURE_CRM_USER', (string)$payload['uid'], ['httponly' => true, 'samesite' => 'Lax']);
    header('Location: /fake-crm/inbox', true, 303);
    return true;
}
if ($path === '/fake-crm/inbox') {
    header('Content-Type: application/json');
    echo json_encode(['user' => $_COOKIE['FIXTURE_CRM_USER'] ?? null]);
    return true;
}
if ($path === '/fake-n8n') { echo 'Fixture n8n sign-in'; return true; }
return false;
FIXTURE);

file_put_contents($tmp . '/includes/api.php', <<<'FIXTURE'
<?php
require_once __DIR__ . '/api_cors.php';
function api_user_for_token(string $token): ?array {
    return $token === str_repeat('a', 64)
        ? ['id' => 7, 'name' => 'Fixture', 'role' => 'teacher', 'modules' => ['fees'], 'token_id' => 21]
        : null;
}
function start_session_once(): void {
    if (session_status() === PHP_SESSION_ACTIVE) return;
    session_save_path(__DIR__ . '/../sessions');
    session_name('LG_WORKSPACE_TEST');
    session_start();
}
FIXTURE);
file_put_contents($tmp . '/session.php', <<<'FIXTURE'
<?php
require __DIR__ . '/includes/api.php';
start_session_once();
if (isset($_GET['prime'])) $_SESSION = ['user_id' => 99, 'csrf_token' => 'old-form'];
if (isset($_GET['mode'])) {
    $mode = $_GET['mode'];
    $_SESSION = ['user_id' => $mode === 'restricted' ? 8 : 7, 'user_name' => 'Fixture', 'user_role' => 'teacher'];
    if ($mode !== 'desktop') {
        $_SESSION['_app_workspace'] = true;
        $_SESSION['_app_token_id'] = $mode === 'revoked' ? 22 : ($mode === 'restricted' ? 23 : 21);
    }
}
header('Content-Type: application/json');
echo json_encode(['id' => session_id(), 'session' => $_SESSION]);
FIXTURE);

$checks = 0;
function check(bool $condition, string $message): void {
    global $checks;
    if (!$condition) throw new RuntimeException($message);
    $checks++;
}
function request(string $base, string $path, string $method = 'GET', array $fields = [], array $headers = []): array {
    $options = ['http' => [
        'method' => $method, 'ignore_errors' => true, 'follow_location' => 0,
        'timeout' => 5, 'header' => implode("\r\n", $headers),
    ]];
    if ($method === 'POST') {
        $options['http']['header'] .= "\r\nContent-Type: application/x-www-form-urlencoded";
        $options['http']['content'] = http_build_query($fields);
    }
    $body = file_get_contents($base . $path, false, stream_context_create($options));
    $responseHeaders = $http_response_header ?? [];
    preg_match('/\s(\d{3})\s/', $responseHeaders[0] ?? '', $match);
    return [(int)($match[1] ?? 0), $body, $responseHeaders];
}
function cookie(array $headers): string {
    foreach ($headers as $header) if (preg_match('/^Set-Cookie:\s*([^;]+)/i', $header, $m)) return $m[1];
    return '';
}
function removeTree(string $path): void {
    foreach (scandir($path) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') continue;
        $child = $path . '/' . $entry;
        if (is_dir($child)) removeTree($child); else unlink($child);
    }
    rmdir($path);
}
$server = null;
try {
    $socket = stream_socket_server('tcp://127.0.0.1:0', $errno, $error);
    if (!$socket) throw new RuntimeException('Local HTTP test listener unavailable: ' . $error);
    $address = stream_socket_get_name($socket, false);
    fclose($socket);
    $base = 'http://' . $address;
    $db = new PDO('sqlite:' . $tmp . '/fixture.sqlite');
    $db->exec('CREATE TABLE users (id INTEGER PRIMARY KEY, name TEXT, role TEXT, modules TEXT, active INTEGER)');
    $db->exec("INSERT INTO users VALUES (7, 'Fixture', 'teacher', 'wacrm,n8n', 1), (8, 'Restricted', 'teacher', 'n8n', 1)");
    $db->exec('CREATE TABLE api_tokens (id INTEGER PRIMARY KEY, user_id INTEGER, revoked_at TEXT)');
    $db->exec("INSERT INTO api_tokens VALUES (21, 7, NULL), (22, 7, '2026-01-01'), (23, 8, NULL)");
    $db->exec('CREATE TABLE app_settings (setting_key TEXT PRIMARY KEY, setting_value TEXT)');
    $setting = $db->prepare('INSERT INTO app_settings VALUES (?, ?)');
    foreach (['wacrm_url' => $base . '/fake-crm', 'n8n_url' => $base . '/fake-n8n', 'wacrm_sso_secret' => 'fixture-secret'] as $key => $value) $setting->execute([$key, $value]);
    $server = proc_open([PHP_BINARY, '-d', 'display_errors=1', '-d', 'session.save_path=' . $tmp . '/sessions', '-S', $address, '-t', $tmp, $tmp . '/router.php'],
        [0 => ['pipe', 'r'], 1 => ['file', $tmp . '/server.log', 'a'], 2 => ['file', $tmp . '/server.log', 'a']], $pipes);
    if (!is_resource($server)) throw new RuntimeException('Cannot start fixture HTTP server');
    fclose($pipes[0]);
    for ($attempt = 0; $attempt < 100; $attempt++) {
        $probe = @stream_socket_client('tcp://' . $address, $errno, $error, 0.1);
        if ($probe) { fclose($probe); break; }
        usleep(20000);
    }
    $valid = ['token' => str_repeat('a', 64), 'path' => '/fees/index.php'];
    foreach ([
        ['GET', [], [], 405, 'POST-only bridge'],
        ['POST', ['token' => $valid['token'], 'path' => '//evil.test/index.php'], [], 400, 'foreign path'],
        ['POST', ['token' => $valid['token'], 'path' => ['bad']], [], 400, 'array path'],
        ['POST', ['token' => ['bad'], 'path' => $valid['path']], [], 401, 'array token'],
        ['POST', ['token' => str_repeat('b', 64), 'path' => $valid['path']], [], 401, 'invalid token'],
        ['POST', $valid, ['Origin: https://evil.test'], 403, 'foreign origin'],
        ['POST', $valid, ['Origin: null'], 403, 'opaque origin'],
    ] as [$method, $fields, $headers, $expected, $name]) {
        [$status, $body, $responseHeaders] = request($base, '/app/open.php', $method, $fields, $headers);
        check($status === $expected, "$name returned $status");
        check(str_contains($body, 'lg-workspace') && str_contains($body, '"event":"error"'), "$name did not return bridge error");
        check(!str_contains($body, 'Warning') && !str_contains($body, 'Array to string'), "$name emitted a PHP warning");
        check((bool)preg_grep('/^Cache-Control: no-store$/i', $responseHeaders), "$name was cacheable");
    }
    [$status, $body, $headers] = request($base, '/session.php?prime=1');
    $old = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    $oldCookie = cookie($headers);
    check($status === 200 && $oldCookie !== '', 'Could not prime isolated session');
    [$status, $body, $headers] = request($base, '/app/open.php', 'POST', $valid,
        ['Cookie: ' . $oldCookie, 'Origin: https://app.thelittlegraduates.in']);
    check($status === 303, 'Valid bridge did not redirect');
    check(in_array('Location: /fees/index.php', $headers, true), 'Wrong redirect destination');
    check(!str_contains(implode('\n', $headers) . $body, $valid['token']), 'Token leaked into response');
    $newCookie = cookie($headers);
    check($newCookie !== '' && $newCookie !== $oldCookie, 'Session ID was not rotated');
    [$status, $body] = request($base, '/session.php', 'GET', [], ['Cookie: ' . $newCookie]);
    $new = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
    check($status === 200 && $new['session']['user_id'] === 7, 'Wrong identity after bridge');
    check($new['session']['_app_token_id'] === 21 && $new['session']['_app_workspace'] === true, 'Missing workspace token binding');
    check(!isset($new['session']['csrf_token']), 'Previous account CSRF state survived');
    check(!str_contains($body, $valid['token']), 'Raw token stored in session');
    echo "PASS: $checks isolated HTTP bridge assertions (fixture auth, real PHP sessions; no DB integration)\n";

    $publicStart = $checks;
    foreach (['/fees_calculator.php?grade=lkg', '/receipt.php?t=fixture-receipt'] as $publicPath) {
        [$status, $body, $headers] = request($base, '/app/open.php', 'POST', ['token' => $valid['token'], 'path' => $publicPath]);
        $entry = '/app/public.php?path=' . rawurlencode($publicPath);
        check($status === 303 && in_array('Location: ' . $entry, $headers, true), 'Public bootstrap bypassed the authenticated entry');
        [$status, $body] = request($base, $entry, 'GET', [], ['Cookie: ' . cookie($headers)]);
        check($status === 200 && str_contains($body, 'in browser'), 'Public entry did not render a deliberate action');
        check(str_contains($body, 'target="_blank"') && !str_contains($body, '<iframe'), 'Public entry embedded unverified content');
        check(!str_contains($body, $valid['token']), 'Public action leaked the API bearer');
    }
    echo 'PASS: ' . ($checks - $publicStart) . " public-page handoff HTTP assertions (no calculator or receipt page requests)\n";

    $externalStart = $checks;
    [$status, $body, $headers] = request($base, '/session.php?mode=external');
    $appCookie = cookie($headers);
    $csrf = '';
    foreach (['/wacrm/index.php', '/wacrm/index.php?launch=1', '/n8n/index.php'] as $path) {
        [$status, $body, $headers] = request($base, $path, 'GET', [], ['Cookie: ' . $appCookie]);
        check($status === 200, "$path did not render the integration entry page");
        check(!str_contains($body, '<iframe') && !str_contains($body, '/api/sso/mtt?token='), "$path eagerly embedded or prepared SSO");
        check(!preg_grep('/^Location:/i', $headers), "$path redirected the workspace");
        check(str_contains($body, 'data-workspace-external') && str_contains($body, 'in browser'), "$path lacks an explicit action");
        preg_match('/name="_csrf" value="([^"]+)"/', $body, $match);
        $csrf = $match[1] ?? '';
        check(strlen($csrf) === 64, "$path missing CSRF state");
    }
    foreach ([
        ['GET', [], [], 405],
        ['POST', ['app' => 'wacrm'], [], 400],
        ['POST', ['app' => ['wacrm'], '_csrf' => $csrf], [], 400],
        ['POST', ['app' => 'wacrm', '_csrf' => [$csrf]], [], 400],
        ['POST', ['app' => 'unknown', '_csrf' => $csrf], [], 400],
        ['POST', ['app' => 'wacrm', '_csrf' => $csrf], ['Origin: https://evil.test'], 403],
    ] as [$method, $fields, $moreHeaders, $expected]) {
        [$status, $body, $headers] = request($base, '/app/external.php', $method, $fields, array_merge(['Cookie: ' . $appCookie], $moreHeaders));
        check($status === $expected, 'External action did not reject an invalid request');
        check(json_decode($body, true)['ok'] === false && !str_contains($body, 'token='), 'Invalid request minted SSO or returned non-JSON');
        check(!preg_grep('/^Location:/i', $headers), 'Invalid action navigated instead of returning a recoverable error');
    }
    foreach (['wacrm', 'n8n'] as $key) {
        [$status, $body, $headers] = request($base, '/app/external.php', 'POST', ['app' => $key, '_csrf' => $csrf], ['Cookie: ' . $appCookie, 'Origin: ' . $base]);
        $result = json_decode($body, true, 512, JSON_THROW_ON_ERROR);
        check($status === 200 && $result['ok'] === true, 'Explicit handoff failed');
        check((bool)preg_grep('/^Cache-Control: no-store$/i', $headers), 'SSO response was cacheable');
        check(!preg_grep('/^Location:/i', $headers), 'Handoff replaced the current workspace');
        check(str_starts_with($result['url'], $base . '/fake-') && !str_contains($body, $valid['token']), 'Bearer leaked or URL left the local fixture');
        $target = substr($result['url'], strlen($base));
        [$externalStatus, $externalBody, $externalHeaders] = request($base, $target);
        if ($key === 'wacrm') {
            check($externalStatus === 303 && in_array('Location: /fake-crm/inbox', $externalHeaders, true), 'Local WACRM fixture rejected the real signed token');
            [$inboxStatus, $inboxBody] = request($base, '/fake-crm/inbox', 'GET', [], ['Cookie: ' . cookie($externalHeaders)]);
            check($inboxStatus === 200 && json_decode($inboxBody, true)['user'] === '7', 'Fake external session has the wrong identity');
        } else {
            check($externalStatus === 200 && $externalBody === 'Fixture n8n sign-in', 'n8n URL handoff changed its configured endpoint');
        }
    }
    [$status, $body] = request($base, '/session.php', 'GET', [], ['Cookie: ' . $appCookie]);
    check(json_decode($body, true)['session']['user_id'] === 7, 'External handoff changed the school account');
    foreach (['restricted' => 403, 'revoked' => 401, 'desktop' => 401] as $mode => $expected) {
        [$status, $body, $headers] = request($base, '/session.php?mode=' . $mode);
        $otherCookie = cookie($headers);
        [$pageStatus, $pageBody] = request($base, '/n8n/index.php', 'GET', [], ['Cookie: ' . $otherCookie]);
        preg_match('/name="_csrf" value="([^"]+)"/', $pageBody, $match);
        [$status, $body] = request($base, '/app/external.php', 'POST', ['app' => 'wacrm', '_csrf' => $match[1] ?? ''], ['Cookie: ' . $otherCookie]);
        check($status === $expected && json_decode($body, true)['ok'] === false, "$mode access was not enforced");
        if ($mode === 'desktop') {
            [$status, $body] = request($base, '/wacrm/index.php', 'GET', [], ['Cookie: ' . $otherCookie]);
            check($status === 200 && str_contains($body, '<iframe'), 'Normal desktop embed behavior changed');
        }
    }
    $update = $db->prepare('UPDATE app_settings SET setting_value = ? WHERE setting_key = ?');
    foreach (['', 'javascript:alert(1)', 'http://outside.test', 'https://user:password@outside.test', 'https://outside.test/?redirect=x', 'https://outside.test/#x'] as $badUrl) {
        $update->execute([$badUrl, 'wacrm_url']);
        [$status, $body] = request($base, '/app/external.php', 'POST', ['app' => 'wacrm', '_csrf' => $csrf], ['Cookie: ' . $appCookie]);
        check($status === 409 && !str_contains($body, 'token='), 'Unsupported WACRM configuration minted a token');
    }
    $update->execute([$base . '/fake-crm', 'wacrm_url']);
    $update->execute(['', 'wacrm_sso_secret']);
    [$status, $body] = request($base, '/app/external.php', 'POST', ['app' => 'wacrm', '_csrf' => $csrf], ['Cookie: ' . $appCookie]);
    check($status === 200 && json_decode($body, true)['url'] === $base . '/fake-crm', 'Missing SSO secret should use the normal integration login');
    echo 'PASS: ' . ($checks - $externalStart) . " external-handoff HTTP assertions (real auth/SSO helpers and SQLite fixture DB; local external endpoints only)\n";

    $js = <<<'JS'
const fs = require('node:fs');
const vm = require('node:vm');
const assert = require('node:assert/strict');
const script = fs.readFileSync(process.argv[2], 'utf8');
const parentOrigin = 'https://app.thelittlegraduates.in';
function page({tables = [], embedded = true, native = false} = {}) {
  const events = {}, clicks = {}, sent = [];
  const window = {name: '', addEventListener: (name, fn) => {events[name] = fn;}};
  const parent = {postMessage: message => sent.push(message)};
  window.parent = embedded ? parent : window;
  if (native) window.LGWorkspace = {postMessage() {}};
  const history = {state: null, backCalls: 0, replaceState(state) {this.state = state;}, back() {this.backCalls++;}};
  const regions = [], resizeCallbacks = [];
  const document = {
    querySelectorAll: () => tables,
    getElementById: () => null,
    createElement: () => {
      const attributes = {};
      const region = {
        attributes, clientWidth: 390, scrollWidth: 390,
        setAttribute: (name, value) => {attributes[name] = value;},
        removeAttribute: name => {delete attributes[name];},
        appendChild(table) {table.parentElement = this; this.scrollWidth = table.width; this.table = table;},
      };
      regions.push(region); return region;
    },
    documentElement: {classList: {add() {}}}, title: 'Fixture',
    currentScript: {dataset: {user: '7'}},
    addEventListener: (name, fn) => {clicks[name] = fn;},
  };
  const context = {window, parent, history, document, URL, getComputedStyle: parent => ({overflowX: parent.overflowX || 'visible'}), ResizeObserver: class {constructor(fn) {resizeCallbacks.push(fn);} observe() {}}, location: {href: 'https://mtt.thelittlegraduates.in/fees/index.php', origin: 'https://mtt.thelittlegraduates.in'}};
  vm.runInNewContext(script, context);
  events.pageshow?.({persisted: false});
  const click = (href, attrs = {}) => {
    const anchor = {href, hasAttribute: name => name in attrs, setAttribute: (name, value) => {attrs[name] = value;}, removeAttribute: name => delete attrs[name]};
    const event = {target: {closest: () => anchor}, defaultPrevented: false, preventDefault() {this.defaultPrevented = true;}};
    clicks.click(event);
    return {event, attrs};
  };
  return {events, sent, history, window, parent, click, clicks, regions, resizeCallbacks, location: context.location};
}
let failures = 0;
function test(name, fn) {
  try {fn(); console.log('PASS: ' + name);} catch(error) {failures++; console.error('FAIL: ' + name + ': ' + error.message);}
}
test('internal links remain embedded and external links are handed to the app', () => {
  const p = page();
  assert.equal(p.click('/fees/student.php?id=7', {target: '_blank'}).attrs.target, undefined);
  assert.equal(p.click('https://example.test/help').event.defaultPrevented, true);
  assert.equal(p.sent.at(-1).event, 'external');
});
test('external download-like URLs cannot bypass app routing', () => {
  const p = page();
  assert.equal(p.click('https://example.test/file.php').event.defaultPrevented, true);
  assert.equal(p.sent.at(-1).event, 'external');
});
test('cached pages restore the current history marker', () => {
  const p = page();
  p.window.name = 'lg-workspace-index:3';
  p.events.pageshow({persisted: true});
  assert.equal(p.window.name, 'lg-workspace-index:0');
  assert.equal(p.sent.at(-1).canGoBack, false);
});
test('hash navigation updates back availability and returning to root disables it', () => {
  const p = page();
  assert.equal(typeof p.events.hashchange, 'function');
  const root = {...p.history.state};
  p.location.href += '#details';
  p.events.hashchange({});
  assert.equal(p.sent.at(-1).canGoBack, true);
  p.history.state = root;
  p.location.href = root.lgWorkspaceUrl;
  p.events.popstate({state: p.history.state});
  assert.equal(p.sent.at(-1).canGoBack, false);
});
test('back is ignored at root and for untrusted message sources', () => {
  const p = page();
  p.events.message({origin: parentOrigin, source: p.parent, data: {type: 'lg-workspace-back'}});
  assert.equal(p.history.backCalls, 0);
  p.events.message({origin: 'https://evil.test', source: p.parent, data: {type: 'lg-workspace-back'}});
  assert.equal(p.history.backCalls, 0);
});
test('public calculator and receipt links stay out of the web workspace', () => {
  const p = page();
  for (const href of ['/fees_calculator.php?grade=lkg', '/receipt.php?t=fixture-receipt']) {
    const result = p.click(href);
    assert.equal(result.event.defaultPrevented, false);
    assert.equal(result.attrs.target, '_blank');
    assert.equal(result.attrs.rel, 'noopener noreferrer');
    assert.equal(p.sent.at(-1).event, 'ready');
    assert.equal(p.location.href, 'https://mtt.thelittlegraduates.in/fees/index.php');
  }
});
test('native public-page links emit external action without loading', () => {
  const p = page({native: true});
  const result = p.click('/receipt.php?t=fixture-receipt');
  assert.equal(result.event.defaultPrevented, true);
  assert.equal(p.sent.at(-1).event, 'external');
  assert.equal(p.sent.at(-1).url, 'https://mtt.thelittlegraduates.in/receipt.php?t=fixture-receipt');
  assert.equal(p.sent.some(message => message.event === 'loading'), false);
});
function tableFixture(width, {caption = '', label = '', overflowX = 'visible'} = {}) {
  const parent = {overflowX, insertBefore(region, table) {region.originalParent = this;}};
  return {
    width, parentElement: parent, caption: caption ? {textContent: caption} : null,
    getAttribute: name => name === 'aria-label' ? label : null,
    closest(selector) {return this.parentElement.className === 'lg-table-scroll' ? this.parentElement : null;},
  };
}
test('wide tables gain a labelled keyboard region without changing the table', () => {
  const table = tableFixture(743, {caption: 'Grade levels'});
  const p = page({tables: [table]});
  assert.equal(p.regions.length, 1); assert.equal(p.regions[0].table, table);
  assert.deepEqual(p.regions[0].attributes, {role: 'region', 'aria-label': 'Grade levels (scroll horizontally)', tabindex: '0'});
});
test('fitting tables do not add tab stops and overflow state follows resize', () => {
  const p = page({tables: [tableFixture(300, {label: 'Staff'})]});
  const region = p.regions[0]; assert.deepEqual(region.attributes, {});
  region.scrollWidth = 527; p.resizeCallbacks[0]();
  assert.equal(region.attributes['aria-label'], 'Staff (scroll horizontally)');
  region.clientWidth = 800; p.events.resize(); assert.deepEqual(region.attributes, {});
});
test('existing scroll containers and normal desktop tables stay untouched', () => {
  assert.equal(page({tables: [tableFixture(700, {overflowX: 'auto'})]}).regions.length, 0);
  assert.equal(page({tables: [tableFixture(700)], embedded: false}).regions.length, 0);
});
const externalScript = fs.readFileSync(process.argv[3], 'utf8');
function externalPage({native = false, blocked = false, reply, failure, deferred = false, closed = false} = {}) {
  const order = [], messages = [], requests = [], tabs = [];
  const button = {disabled: false}, status = {textContent: ''};
  const location = Object.freeze({href: 'https://mtt.thelittlegraduates.in/wacrm/index.php'});
  let submit, finish, expire;
  const form = {
    action: 'https://mtt.thelittlegraduates.in/app/external.php', dataset: {user: '7'},
    querySelector: selector => selector.startsWith('button') ? button : status,
    addEventListener: (name, handler) => {assert.equal(name, 'submit'); submit = handler;},
  };
  const window = {
    location,
    open(url, target) {
      order.push('popup');
      assert.equal(url, 'about:blank'); assert.equal(target, '_blank');
      if (blocked) return null;
      const tab = {
        opener: {}, closed,
        document: {createElement: () => ({}), head: {appendChild(meta) {assert.equal(meta.content, 'no-referrer');}}, body: {}},
        location: {replace(url) {order.push('navigate'); tab.url = url;}},
        close() {tab.closed = true;},
      };
      tabs.push(tab); return tab;
    },
  };
  if (native) window.LGWorkspace = {postMessage: raw => messages.push(JSON.parse(raw))};
  const response = reply || {ok: true, data: {ok: true, url: 'https://fixture-crm.test/api/sso/mtt?token=fixture-sso'}};
  const fetch = (url, options) => {
    order.push('fetch'); requests.push({url, options});
    if (failure) return Promise.reject(new Error('Fixture connection failed'));
    if (deferred) return new Promise((resolve, reject) => {
      finish = () => resolve({ok: response.ok, json: async () => response.data});
      options.signal.addEventListener('abort', () => reject(new DOMException('Timed out', 'AbortError')));
    });
    return Promise.resolve({ok: response.ok, json: async () => response.data});
  };
  vm.runInNewContext(externalScript, {
    window, location, document: {querySelectorAll: () => [form]}, fetch, URL, URLSearchParams,
    FormData: class { constructor() {return [['app', 'wacrm'], ['_csrf', 'fixture-csrf']];} },
    AbortController, Error,
    setTimeout: fn => {expire = fn; return 1;}, clearTimeout() {},
  });
  return {
    button, status, order, requests, messages, tabs, window,
    submit: () => submit({preventDefault() {}}), finish: () => finish(), expire: () => expire(),
  };
}
async function externalTest(name, fn) {
  try {await fn(); console.log('PASS: ' + name);} catch(error) {failures++; console.error('FAIL: ' + name + ': ' + error.message);}
}
(async () => {
  await externalTest('web click reserves one detached tab before session POST and preserves workspace', async () => {
    const p = externalPage();
    assert.equal(p.requests.length, 0, 'Page load must not prepare SSO');
    await p.submit();
    assert.deepEqual(p.order, ['popup', 'fetch', 'navigate']);
    assert.equal(p.tabs[0].opener, null);
    assert.equal(p.tabs[0].url, 'https://fixture-crm.test/api/sso/mtt?token=fixture-sso');
    assert.equal(p.window.location.href, 'https://mtt.thelittlegraduates.in/wacrm/index.php');
    assert.equal(p.requests[0].url, 'https://mtt.thelittlegraduates.in/app/external.php');
    const options = p.requests[0].options;
    assert.equal(options.credentials, 'same-origin'); assert.equal(options.redirect, 'error');
    assert.equal(options.body.toString(), 'app=wacrm&_csrf=fixture-csrf');
    assert.equal(options.headers.Authorization, undefined);
    assert.equal(p.button.disabled, false);
  });
  await externalTest('native click emits external action without popup or workspace navigation', async () => {
    const p = externalPage({native: true}); await p.submit();
    assert.deepEqual(p.order, ['fetch']); assert.equal(p.messages.length, 1);
    assert.deepEqual(p.messages[0], {type: 'lg-workspace', event: 'external', userId: 7, url: 'https://fixture-crm.test/api/sso/mtt?token=fixture-sso'});
    assert.equal(p.window.location.href, 'https://mtt.thelittlegraduates.in/wacrm/index.php');
  });
  await externalTest('blocked popup issues no token request and leaves a retryable button', async () => {
    const p = externalPage({blocked: true}); await p.submit();
    assert.equal(p.requests.length, 0); assert.match(p.status.textContent, /Allow pop-ups/);
    assert.equal(p.button.disabled, false);
  });
  await externalTest('duplicate clicks prepare just one handoff', async () => {
    const p = externalPage({deferred: true}); const first = p.submit();
    assert.equal(p.button.disabled, true); await p.submit();
    assert.equal(p.requests.length, 1); assert.equal(p.tabs.length, 1);
    p.finish(); await first; assert.equal(p.button.disabled, false);
  });
  await externalTest('expired session closes the blank tab and preserves inline recovery', async () => {
    const p = externalPage({reply: {ok: false, data: {ok: false, error: 'Your session ended.'}}});
    await p.submit(); assert.equal(p.tabs[0].closed, true); assert.equal(p.tabs[0].url, undefined);
    assert.equal(p.status.textContent, 'Your session ended.'); assert.equal(p.button.disabled, false);
  });
  await externalTest('network failure and timeout close the placeholder and permit retry', async () => {
    const p = externalPage({failure: true}); await p.submit();
    assert.equal(p.tabs[0].closed, true); assert.equal(p.button.disabled, false);
    const slow = externalPage({deferred: true}); const pending = slow.submit();
    slow.expire(); await pending;
    assert.equal(slow.tabs[0].closed, true); assert.match(slow.status.textContent, /too long/);
    assert.equal(slow.button.disabled, false);
  });
  await externalTest('closed tab and unsafe destinations never navigate the current workspace', async () => {
    const p = externalPage({closed: true}); await p.submit();
    assert.equal(p.tabs[0].url, undefined); assert.match(p.status.textContent, /tab was closed/);
    for (const url of ['javascript:alert(1)', 'https://user:password@outside.test/']) {
      const unsafe = externalPage({reply: {ok: true, data: {ok: true, url}}});
      await unsafe.submit(); assert.equal(unsafe.tabs[0].closed, true); assert.equal(unsafe.tabs[0].url, undefined);
      assert.match(unsafe.status.textContent, /unsupported address/);
    }
  });
  process.exitCode = failures ? 1 : 0;
})().catch(error => { console.error(error); process.exitCode = 1; });

JS;
    file_put_contents($tmp . '/bridge-test.cjs', $js);
    $node = proc_open(['node', $tmp . '/bridge-test.cjs', $root . '/assets/js/app-workspace.js', $root . '/app/external.js'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $nodePipes);
    if (!is_resource($node)) throw new RuntimeException('Node.js is required for bridge JS checks');
    fclose($nodePipes[0]);
    echo stream_get_contents($nodePipes[1]);
    $nodeErrors = stream_get_contents($nodePipes[2]);
    fclose($nodePipes[1]);
    fclose($nodePipes[2]);
    if ($nodeErrors !== '') fwrite(STDERR, $nodeErrors);
    check(proc_close($node) === 0, 'Workspace JavaScript behavior checks failed');
} finally {
    if (is_resource($server)) { proc_terminate($server); proc_close($server); }
    removeTree($tmp);
}
