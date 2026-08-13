<?php
/**
 * mcp.php — Model Context Protocol endpoint (Streamable HTTP transport).
 *
 * Lets an MCP client (Claude Code, Claude Desktop, anything that speaks the
 * protocol) read and write this school's data in plain language.
 *
 * Wire it up once:
 *
 *   claude mcp add --transport http mtt https://<host>/mcp.php \
 *          --header "Authorization: Bearer <token from /mcp_admin.php>"
 *
 * The protocol is JSON-RPC 2.0 over POST. The spec allows a server to answer
 * with a single application/json body instead of an SSE stream when it has
 * nothing to stream, which is what this does — there is no long-running work
 * here, and a plain response keeps the whole server inside the PHP request
 * model that this shared host actually supports.
 *
 * Every request must carry a bearer token. Tokens are minted, listed and
 * revoked at /mcp_admin.php; only their SHA-256 is stored.
 *
 * All the real work — the tool catalogue, validation, execution, audit —
 * lives in includes/mcp.php.
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mcp.php';
require_once __DIR__ . '/includes/oauth.php';

header('Content-Type: application/json; charset=utf-8');
header('Cache-Control: no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');

/*
 * CORS.
 *
 * A browser-based client — claude.ai's connector, anything running in a page
 * rather than a terminal — cannot POST JSON with an Authorization header
 * cross-origin without a preflight succeeding first. Without these headers
 * the browser refuses before the request is ever sent, and the only thing the
 * user sees is "connection to server failed", with nothing in the server log
 * because nothing reached the server.
 *
 * The OAuth endpoints have had these since they were written. This one, which
 * every request actually goes through, did not.
 *
 * Origin '*' is correct here: authorization is a Bearer header, never a
 * cookie, so there is nothing for a hostile page to ride on. A token holder
 * can already call this from anywhere; a browser is not a new door.
 *
 * WWW-Authenticate must be exposed or a client cannot read the pointer to the
 * OAuth metadata off a 401 — which is how it discovers it needs to sign in.
 */
header('Access-Control-Allow-Origin: *');
header('Access-Control-Allow-Methods: POST, GET, DELETE, OPTIONS');
header('Access-Control-Allow-Headers: Authorization, Content-Type, Accept, '
     . 'Mcp-Session-Id, MCP-Protocol-Version, Last-Event-ID');
header('Access-Control-Expose-Headers: WWW-Authenticate, Mcp-Session-Id');
header('Access-Control-Max-Age: 86400');

/*
 * Request recorder.
 *
 * Read the body here, once, and hand it on: php://input can only be consumed
 * once, and this has to run before anything that might reject the request —
 * a preflight, a bad body, a missing token — because those are precisely the
 * failures that otherwise leave no trace anywhere.
 *
 * Off unless an admin opened a window at /mcp_admin.php, and that window
 * closes by itself.
 */
$MCP_RAW = '';
try {
    $MCP_RAW = (string)@file_get_contents('php://input');
    if ($MCP_RAW === '' && PHP_SAPI === 'cli') $MCP_RAW = (string)@file_get_contents('php://stdin');
} catch (Throwable $e) { $MCP_RAW = ''; }

$MCP_DEBUG = false;
try { $MCP_DEBUG = mcp_debug_active(); } catch (Throwable $e) { $MCP_DEBUG = false; }

/** Record and return the status, so call sites read as `exit(mcp_trace(...))`. */
function mcp_trace(int $status, string $note = ''): void
{
    if (!($GLOBALS['MCP_DEBUG'] ?? false)) return;
    mcp_debug_record(
        (string)($_SERVER['REQUEST_METHOD'] ?? '?'),
        (string)($_SERVER['REQUEST_URI'] ?? '/mcp.php'),
        mcp_request_headers(),
        (string)($GLOBALS['MCP_RAW'] ?? ''),
        $status,
        $note
    );
}

// Answered before authentication, deliberately: a preflight never carries the
// Authorization header, so demanding one here would fail every cross-origin
// client before it had a chance to send credentials at all.
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'OPTIONS') {
    http_response_code(204);
    mcp_trace(204, 'CORS preflight');
    exit;
}

// This endpoint must answer in JSON even when it dies. Without this a fatal
// gives the client an empty 200 or a page of HTML, and "the MCP server just
// stops responding" is a horrible thing to diagnose from the far end. The
// detail still goes to the error log, never to the caller.
ini_set('display_errors', '0');

set_exception_handler(function (Throwable $e): void {
    error_log('mcp.php uncaught ' . get_class($e) . ': ' . $e->getMessage()
              . ' in ' . $e->getFile() . ':' . $e->getLine());
    mcp_fatal_json();
});

register_shutdown_function(function (): void {
    $err = error_get_last();
    if ($err !== null
        && in_array($err['type'], [E_ERROR, E_PARSE, E_CORE_ERROR, E_COMPILE_ERROR], true)) {
        mcp_fatal_json();
    }
});

function mcp_fatal_json(): void
{
    static $sent = false;               // handler and shutdown can both fire
    if ($sent || headers_sent()) return;
    $sent = true;
    http_response_code(500);
    header('Content-Type: application/json; charset=utf-8');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => ['code' => -32603,
                      'message' => 'Internal error — the server logged the detail.'],
    ]);
}

/** JSON-RPC error response. `id` is null for anything we could not parse. */
function rpc_error($id, int $code, string $message, int $http = 200): void
{
    http_response_code($http);
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'error'   => ['code' => $code, 'message' => $message],
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

function rpc_result($id, array $result): void
{
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => $id,
        'result'  => $result,
    ], JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    exit;
}

// ------------------------------------------------------------------- method

// A GET here would be the SSE stream the spec makes optional. We do not open
// one, and saying so plainly beats a bare 404 for anyone testing by hand.
if ($_SERVER['REQUEST_METHOD'] === 'GET') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode([
        'error' => 'This is an MCP endpoint. POST JSON-RPC to it with an '
                 . 'Authorization: Bearer <token> header.',
    ]);
    mcp_trace(405, 'GET — no SSE stream offered');
    exit;
}
if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    echo json_encode(['error' => 'POST only']);
    mcp_trace(405, 'method not allowed');
    exit;
}

// --------------------------------------------------------------------- auth

/**
 * Apache's mod_php does not always expose the Authorization header in
 * $_SERVER, so fall back through the places it can hide. The last resort is
 * a query parameter, which some clients still use for remote servers.
 */
function mcp_presented_token(): string
{
    $header = (string)($_SERVER['HTTP_AUTHORIZATION']
              ?? $_SERVER['REDIRECT_HTTP_AUTHORIZATION']
              ?? '');
    if ($header === '' && function_exists('apache_request_headers')) {
        foreach ((array)apache_request_headers() as $k => $v) {
            if (strcasecmp((string)$k, 'Authorization') === 0) { $header = (string)$v; break; }
        }
    }
    if (preg_match('/^\s*Bearer\s+([A-Za-z0-9]+)\s*$/i', $header, $m)) return $m[1];
    if (!empty($_SERVER['HTTP_X_MCP_TOKEN'])) return (string)$_SERVER['HTTP_X_MCP_TOKEN'];
    return (string)($_GET['token'] ?? '');
}

/**
 * Two ways in, and they are not equivalent.
 *
 *   OAuth  — a person signed in with their PIN and consented. The token lives
 *            an hour, never passes through a human's hands, and the audit log
 *            can name them. This is the one to prefer.
 *   Bearer — a long-lived token minted at /mcp_admin.php, for things that
 *            cannot open a browser: cron, n8n. Kept working deliberately.
 *
 * Both arrive as `Authorization: Bearer …`, which is what the OAuth spec
 * requires, so they are told apart by looking each up in turn.
 */
$presented  = mcp_presented_token();
$tokenId    = null;      // mcp_tokens.id
$oauthId    = null;      // oauth_tokens.id
$userId     = null;
$actor      = null;      // for the WWW-Authenticate hint and error text

try {
    $oauthRow = oauth_access_token_check($presented);
    if ($oauthRow !== null) {
        $oauthId = (int)$oauthRow['id'];
        $userId  = (int)$oauthRow['user_id'];
        $actor   = (string)$oauthRow['user_name'];
    } else {
        $tokenRow = mcp_token_check($presented);
        if ($tokenRow !== null) {
            $tokenId = (int)$tokenRow['id'];
            $actor   = (string)$tokenRow['label'];
        }
    }
} catch (PDOException $e) {
    // The mcp_* / oauth_* tables are not there yet (pre-migration). Treated as
    // "no valid credential" below. Deliberately catches PDOException and
    // nothing wider: a catch-all here once disguised a plain missing-function
    // fatal as an authentication failure, which is a miserable thing to debug.
}

if ($actor === null) {
    // RFC 9728: point an unauthenticated client at the metadata that tells it
    // where to go and log in. This is what turns a 401 into a login prompt in
    // the client rather than a dead end.
    $meta = oauth_base_url() . '/.well-known/oauth-protected-resource';
    http_response_code(401);
    header('WWW-Authenticate: Bearer resource_metadata="' . $meta . '"');
    echo json_encode([
        'jsonrpc' => '2.0',
        'id'      => null,
        'error'   => ['code' => -32001,
                      'message' => 'Unauthorized — sign in, or present a valid API token.'],
    ]);
    mcp_trace(401, $presented === '' ? 'no credential presented'
                                     : 'credential presented but not recognised');
    exit;
}

// --------------------------------------------------------------------- body

/**
 * The JSON-RPC request body.
 *
 * php://input is the request body under a web SAPI. Under CLI there is no
 * request body and the stream does not exist — stdin is its equivalent — so
 * fall through to it. That is what makes the endpoint testable, and lets you
 * diagnose a misbehaving server straight from the shell:
 *
 *   echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
 *     | HTTP_AUTHORIZATION="Bearer <token>" REQUEST_METHOD=POST php mcp.php
 */
function mcp_request_body(): string
{
    $raw = (string)@file_get_contents('php://input');
    if ($raw === '' && PHP_SAPI === 'cli') {
        $raw = (string)@file_get_contents('php://stdin');
    }
    return $raw;
}

$raw = $MCP_RAW !== '' ? $MCP_RAW : mcp_request_body();
$req = json_decode($raw, true);
if (!is_array($req)) {
    mcp_trace(400, 'body was not valid JSON');
    rpc_error(null, -32700, 'Parse error — body was not valid JSON.', 400);
}

// A batch is a JSON array of requests. Handle it by dispatching each in turn;
// notifications inside a batch produce no entry, and an all-notification
// batch gets 202 with an empty body, as the spec requires.
$isBatch  = array_is_list($req) && $req !== [];
$messages = $isBatch ? $req : [$req];
$out      = [];

foreach ($messages as $msg) {
    if (!is_array($msg)) {
        $out[] = ['jsonrpc' => '2.0', 'id' => null,
                  'error' => ['code' => -32600, 'message' => 'Invalid request.']];
        continue;
    }
    $resp = mcp_dispatch($msg, $tokenId, $userId, $oauthId);
    if ($resp !== null) $out[] = $resp;
}

if ($out === []) {
    http_response_code(202);   // notifications only — nothing to say back
    mcp_trace(202, 'notification only');
    exit;
}
echo json_encode($isBatch ? $out : $out[0],
                 JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
mcp_trace(200, 'handled: ' . implode(', ', array_map(
    fn($m) => (string)($m['method'] ?? '?'), $messages)));
exit;

// ----------------------------------------------------------------- dispatch

/**
 * Handle one JSON-RPC message. Returns the response array, or null when the
 * message was a notification (no `id`) and so must not be answered.
 */
function mcp_dispatch(array $msg, ?int $tokenId, ?int $userId, ?int $oauthId): ?array
{
    $method = (string)($msg['method'] ?? '');
    $params = is_array($msg['params'] ?? null) ? $msg['params'] : [];
    $hasId  = array_key_exists('id', $msg);
    $id     = $msg['id'] ?? null;

    $ok  = fn(array $r) => ['jsonrpc' => '2.0', 'id' => $id, 'result' => $r];
    $err = fn(int $c, string $m) => ['jsonrpc' => '2.0', 'id' => $id,
                                     'error' => ['code' => $c, 'message' => $m]];

    switch ($method) {

        case 'initialize': {
            $wanted = (string)($params['protocolVersion'] ?? MCP_PROTOCOL_VERSION);
            $agreed = in_array($wanted, MCP_SUPPORTED_VERSIONS, true)
                    ? $wanted : MCP_PROTOCOL_VERSION;
            return $ok([
                'protocolVersion' => $agreed,
                'capabilities'    => ['tools' => ['listChanged' => false]],
                'serverInfo'      => [
                    // app_setting() rather than app_name(): the latter reaches
                    // for app_config() in auth.php, and auth.php drags in the
                    // branded HTML error page, which would hand a JSON-RPC
                    // client a page of markup instead of an error it can read.
                    'name'    => (string)app_setting('app_name', 'Little Graduates') . ' MCP',
                    'version' => '1.0.0',
                ],
                'instructions'    => "This server reads and writes the school's live database — students, "
                                   . "attendance, fees, expenses, staff, payroll and leave, CRM leads, "
                                   . "tasks, inventory, parent surveys and feedback.\n\n"
                                   . "Start with `schema`. Called with no arguments it lists every table "
                                   . "grouped by area, with row counts. Called with one or more table names "
                                   . "it returns each column's type and, importantly, which column it "
                                   . "references and which columns reference it — so a join can be written "
                                   . "correctly the first time. Ask for a whole area at once, e.g. "
                                   . "table: \"students,attendance,fees\". Do not guess table or column "
                                   . "names: a join onto the wrong table returns no rows rather than an "
                                   . "error, which reads as \"no data\" instead of \"wrong question\".\n\n"
                                   . "`query` runs a single read-only SELECT. Use named parameters (:name) "
                                   . "with the params object; the same placeholder may appear only ONCE per "
                                   . "statement.\n"
                                   . "`insert`, `update` and `delete` write. update and delete REQUIRE a "
                                   . "where clause and refuse when more rows match than max_rows, so check "
                                   . "the count with `query` before a broad change. Previous values are "
                                   . "recorded, so a mistake can be undone.\n\n"
                                   . "Parent surveys: do NOT insert into `surveys` by hand to create a form. "
                                   . "Use `survey_spec_validate` → `survey_spec_upsert` → `survey_publish` "
                                   . "for new JSON questionnaires. Built-in keys orientation_2026_27 and "
                                   . "field_trip are PHP-owned and cannot be overwritten. "
                                   . "`survey_prefill_links` returns per-child URLs that autofill identity "
                                   . "fields; `student_picker` / options students|parents use a 3-letter "
                                   . "typeahead (never a full child dropdown on the public form).\n\n"
                                   . "Staff duty lists (daily/weekly/monthly ticks): use "
                                   . "`staff_duty_template_upsert` with audience all_teachers, "
                                   . "all_non_teaching, all_staff or users, then `staff_duty_status` "
                                   . "to see who ticked. Do not insert into "
                                   . "staff_duty_templates by hand.\n\n"
                                   . "Password and PIN columns always read as [redacted] and cannot be "
                                   . "written — do not try to set or verify anyone's credentials here.\n\n"
                                   . "This is real data about real children and families. Prefer reading "
                                   . "over writing, and when a write is ambiguous, ask the user before "
                                   . "making it.",
            ]);
        }

        // Notifications carry no id and get no reply.
        case 'notifications/initialized':
        case 'notifications/cancelled':
            return null;

        case 'ping':
            return $hasId ? $ok([]) : null;

        case 'tools/list':
            return $ok(['tools' => mcp_tools()]);

        case 'tools/call': {
            $name = (string)($params['name'] ?? '');
            $args = is_array($params['arguments'] ?? null) ? $params['arguments'] : [];
            try {
                $result = mcp_call_tool($name, $args, $tokenId, $userId, $oauthId);
                return $ok([
                    'content' => [[
                        'type' => 'text',
                        'text' => json_encode($result,
                                    JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
                    ]],
                    'isError' => false,
                ]);
            } catch (McpError $e) {
                // A tool-level failure the model can act on: report it as a
                // successful call carrying isError, per the spec, so the model
                // sees the message and can correct itself.
                return $ok([
                    'content' => [['type' => 'text', 'text' => $e->getMessage()]],
                    'isError' => true,
                ]);
            } catch (Throwable $e) {
                return $ok([
                    'content' => [['type' => 'text', 'text' => 'Failed: ' . $e->getMessage()]],
                    'isError' => true,
                ]);
            }
        }

        // Declared unsupported in capabilities, but answer politely rather
        // than erroring — some clients probe for them regardless.
        case 'resources/list':  return $ok(['resources' => []]);
        case 'prompts/list':    return $ok(['prompts' => []]);

        default:
            if (!$hasId) return null;          // unknown notification — stay quiet
            return $err(-32601, "Method not found: '$method'");
    }
}
