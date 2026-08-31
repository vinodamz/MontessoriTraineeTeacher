<?php
/**
 * includes/mcp.php — the Model Context Protocol server's brain.
 *
 * mcp.php (the endpoint) speaks JSON-RPC and delegates everything here:
 * authentication, the tool catalogue, argument validation, execution and
 * the audit trail.
 *
 * ---------------------------------------------------------------------------
 * Why generic tools rather than one tool per operation
 * ---------------------------------------------------------------------------
 * The schema has ~68 tables. A tool per operation would mean hundreds of
 * tools, and the whole catalogue is sent to the model on every single
 * request — clients degrade badly long before that. So the surface is six
 * general tools (schema / query / insert / upsert / update / delete) plus a
 * small number of domain tools for operations where writing rows directly
 * would skip business logic.
 *
 * ---------------------------------------------------------------------------
 * What stops this from being a loaded gun
 * ---------------------------------------------------------------------------
 * The server has full read/write by design — that was an explicit decision.
 * These rails do not take capability away; they stop an accident from
 * becoming unrecoverable:
 *
 *   · Reads are SELECT-only, single-statement, and row-capped.
 *   · Credential columns are redacted on the way out, so a read can never
 *     harvest password material.
 *   · Writes name a table and columns that are checked against
 *     information_schema, so a typo fails instead of doing something else.
 *   · UPDATE and DELETE must carry a WHERE, and the server counts the
 *     matching rows FIRST — over the cap, it refuses and says how many it
 *     would have hit. This is what stops "UPDATE students SET grade='LKG'"
 *     with a forgotten WHERE.
 *   · Every write records the BEFORE image of the rows it touched, so any
 *     change can be read back and reversed.
 *   · mcp_tokens and mcp_audit are not writable through the generic tools.
 *     A log the audited party can erase is not a log.
 *   · Credential columns cannot be SET through the generic tools either;
 *     admin.php hashes PINs properly, and a raw value written here would
 *     lock somebody out of the app.
 */
declare(strict_types=1);

require_once __DIR__ . '/db.php';        // db() — functions.php does not pull this in
require_once __DIR__ . '/functions.php';
require_once __DIR__ . '/surveys.php';
require_once __DIR__ . '/duties.php';

/**
 * MCP spec revision this server implements.
 *
 * The protocol is dated, and clients advertise the revision they want. A
 * server that has never heard of a newer revision negotiates the client
 * downwards, and a client that requires the newer one then disconnects —
 * which looks, from the far end, exactly like the server being unreachable.
 *
 * This list was written against the revision current at the time and went
 * stale: a client asking for 2025-11-25 was answered with 2025-06-18 and
 * gave up. Keep the newest first, and add to it rather than replacing.
 */
const MCP_PROTOCOL_VERSION = '2025-11-25';

/** Revisions we will accept from a client and echo back unchanged. */
const MCP_SUPPORTED_VERSIONS = ['2025-11-25', '2025-06-18', '2025-03-26', '2024-11-05'];

/** Default and maximum rows returned by a read. */
const MCP_ROWS_DEFAULT = 200;
const MCP_ROWS_MAX     = 2000;

/** Default and maximum rows a single write may touch. */
const MCP_WRITE_CAP_DEFAULT = 50;
const MCP_WRITE_CAP_MAX     = 5000;

/** Tables the generic write tools refuse to touch, and why. */
const MCP_WRITE_DENY_TABLES = [
    'mcp_audit'  => 'the audit log is append-only — a log the audited party can erase is not a log',
    'mcp_tokens' => 'API credentials are managed at /mcp_admin.php, not through the API itself',
    'survey_definitions' => 'do not use insert/update/delete here — call survey_spec_upsert (or upsert with spec={...}, then survey_publish). If those tools are missing from tools/list, reconnect the MCP server so it refreshes after deploy',
    'staff_duty_templates' => 'do not insert/update/delete here — this server will route those calls to staff_duty_template_upsert / staff_duty_template_delete (the generic upsert tool is also routed). If those tools are missing from tools/list, reconnect the MCP server',
    'staff_duty_template_users' => 'assignees are set via staff_duty_template_upsert (audience + user_ids)',
];

/**
 * Columns never returned by a read and never settable by a write.
 * Matched case-insensitively against the column name.
 */
const MCP_SECRET_COLUMNS = [
    'pin_hash', 'password', 'password_hash', 'secret', 'token_hash',
    'remember_hash', 'selector_hash', 'validator_hash',
];

/** A thrown MCP error carries a JSON-RPC code alongside the message. */
class McpError extends Exception
{
    public function __construct(string $message, int $code = -32602)
    {
        parent::__construct($message, $code);
    }
}

// ---------------------------------------------------------------- credentials

/** 32 bytes of hex — the token an admin copies into their MCP client config. */
function mcp_token_mint(string $label, ?int $userId): string
{
    $token = bin2hex(random_bytes(32));
    $s = db()->prepare(
        "INSERT INTO mcp_tokens (label, token_hash, created_by) VALUES (:l, :h, :u)"
    );
    $s->execute([
        ':l' => mb_substr(trim($label), 0, 80),
        ':h' => hash('sha256', $token),
        ':u' => $userId,
    ]);
    return $token;
}

/**
 * Resolve a presented bearer token to its row, or null.
 *
 * Looks the token up by hash rather than scanning and comparing, so this
 * stays O(1) as tokens accumulate. hash_equals still guards the final
 * comparison — the index lookup narrows, it does not authorise.
 */
function mcp_token_check(string $presented): ?array
{
    if ($presented === '' || !ctype_xdigit($presented) || strlen($presented) !== 64) {
        return null;
    }
    $s = db()->prepare(
        "SELECT * FROM mcp_tokens WHERE token_hash = :h AND revoked_at IS NULL LIMIT 1"
    );
    $s->execute([':h' => hash('sha256', $presented)]);
    $row = $s->fetch();
    if (!$row || !hash_equals((string)$row['token_hash'], hash('sha256', $presented))) {
        return null;
    }
    db()->prepare("UPDATE mcp_tokens SET last_used_at = NOW() WHERE id = :i")
        ->execute([':i' => (int)$row['id']]);
    return $row;
}

function mcp_token_revoke(int $id): void
{
    db()->prepare("UPDATE mcp_tokens SET revoked_at = NOW() WHERE id = :i AND revoked_at IS NULL")
        ->execute([':i' => $id]);
}

/** Live tokens first, then revoked ones. */
function mcp_tokens_all(): array
{
    return db()->query(
        "SELECT t.*, u.name AS creator_name
           FROM mcp_tokens t
           LEFT JOIN users u ON u.id = t.created_by
          ORDER BY t.revoked_at IS NOT NULL, t.created_at DESC"
    )->fetchAll();
}

// --------------------------------------------------------------------- audit

function mcp_audit_log(?int $tokenId, string $tool, array $args, bool $ok,
                       ?string $error, int $rowsAffected, ?array $beforeImage,
                       ?int $userId = null, ?int $oauthTokenId = null): void
{
    try {
        $s = db()->prepare(
            "INSERT INTO mcp_audit
                (token_id, user_id, oauth_token_id, tool, arguments, ok, error,
                 rows_affected, before_image, ip_hash)
             VALUES (:t, :u, :ot, :n, :a, :ok, :e, :r, :b, :ip)"
        );
        $s->execute([
            ':t'  => $tokenId,
            ':u'  => $userId,
            ':ot' => $oauthTokenId,
            ':n'  => mb_substr($tool, 0, 60),
            ':a'  => json_encode($args, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ok' => $ok ? 1 : 0,
            ':e'  => $error === null ? null : mb_substr($error, 0, 500),
            ':r'  => $rowsAffected,
            ':b'  => $beforeImage === null
                   ? null
                   : json_encode($beforeImage, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            ':ip' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')),
        ]);
    } catch (Throwable $e) {
        // Never let a logging failure take down the call it was logging.
    }
}

// ---- Request recorder ----------------------------------------------------
//
// mcp_audit only sees calls that got as far as running a tool. Everything
// that fails earlier — a preflight, a malformed body, a request that never
// authenticated — leaves no trace at either end, and the client says only
// "couldn't connect to the server".
//
// This records what actually arrives, for a window an admin opens
// deliberately and which closes on its own.

/** Is recording currently switched on? */
function mcp_debug_active(): bool
{
    $until = (int)app_setting('mcp_debug_until', '0');
    return $until > time();
}

/** Open the window. Minutes are clamped: this captures request bodies. */
function mcp_debug_enable(int $minutes = 15): int
{
    $minutes = max(1, min(60, $minutes));
    $until   = time() + ($minutes * 60);
    db()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('mcp_debug_until', :v)
         ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)"
    )->execute([':v' => (string)$until]);
    app_setting_clear_cache();
    return $until;
}

function mcp_debug_disable(): void
{
    db()->prepare(
        "INSERT INTO app_settings (setting_key, setting_value) VALUES ('mcp_debug_until','0')
         ON DUPLICATE KEY UPDATE setting_value = '0'"
    )->execute();
    app_setting_clear_cache();
}

/**
 * Record one request.
 *
 * The Authorization value is never stored — only whether one arrived and how
 * long it was. A debug log holding live credentials would be a worse problem
 * than the one it was opened to investigate.
 */
function mcp_debug_record(string $method, string $path, array $headers,
                          string $body, int $status, string $note = '',
                          string $reply = ''): void
{
    try {
        $safe = [];
        foreach ($headers as $k => $v) {
            $lk = strtolower((string)$k);
            if ($lk === 'authorization' || $lk === 'cookie') {
                $safe[$k] = '[' . strlen((string)$v) . ' chars, not stored]';
            } else {
                $safe[$k] = mb_substr((string)$v, 0, 300);
            }
        }
        db()->prepare(
            "INSERT INTO mcp_debug_log (method, path, headers, body, status, reply, note, ip_hash)
             VALUES (:m, :p, :h, :b, :s, :r, :n, :ip)"
        )->execute([
            ':m'  => mb_substr($method, 0, 10),
            ':p'  => mb_substr($path, 0, 1000),
            ':h'  => json_encode($safe, JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT),
            ':b'  => mb_substr($body, 0, 4000),
            ':s'  => $status,
            ':r'  => $reply !== '' ? mb_substr($reply, 0, 1000) : null,
            ':n'  => $note !== '' ? mb_substr($note, 0, 255) : null,
            ':ip' => hash('sha256', (string)($_SERVER['REMOTE_ADDR'] ?? '')),
        ]);
    } catch (Throwable $e) {
        // Diagnostics must never be the reason a request fails.
    }
}

/** Strip anything secret out of a URL before it is written down. */
function mcp_debug_mask(string $url): string
{
    return (string)preg_replace(
        '/\b(code|token|client_secret|code_verifier|refresh_token|access_token)=[^&\s]*/i',
        '$1=[masked]', $url);
}

/**
 * Record this request whatever happens to it.
 *
 * The endpoints this is used from exit through many paths — reg_fail(),
 * token_fail(), a redirect, a fatal — so the recording is hung off a
 * shutdown function rather than repeated at each one. http_response_code()
 * at shutdown is the status that was actually sent.
 *
 * The body is deliberately NOT captured here. These endpoints carry
 * authorization codes, PKCE verifiers and client secrets, and they read
 * php://input themselves — a stream that can only be consumed once. Query
 * parameters are kept but the sensitive ones are masked.
 */
function mcp_debug_watch(string $label): void
{
    try { if (!mcp_debug_active()) return; } catch (Throwable $e) { return; }

    register_shutdown_function(function () use ($label) {
        // code / token / secret in a query string must not land in a log.
        $uri = mcp_debug_mask((string)($_SERVER['REQUEST_URI'] ?? ''));

        /*
         * Where a redirect actually went.
         *
         * A status code cannot tell an authorization from a refusal — both
         * leave as 302. Only the Location says which: `?code=…` means a code
         * was issued, `?error=…` means it was turned down and names why.
         * Without this the log reports that something was redirected and
         * nothing about where, which is the difference between a diagnosis
         * and a guess.
         */
        // Not guarded on headers_sent(): a redirect exits before any body, so
        // the headers may or may not have gone out by shutdown, and the list
        // is readable either way. Under CLI it is simply empty.
        $reply = '';
        if (function_exists('headers_list')) {
            foreach (headers_list() as $h) {
                if (stripos($h, 'location:') === 0) {
                    $reply = mcp_debug_mask(trim(substr($h, 9)));
                    break;
                }
                if (stripos($h, 'www-authenticate:') === 0 && $reply === '') {
                    $reply = trim($h);
                }
            }
        }

        mcp_debug_record(
            (string)($_SERVER['REQUEST_METHOD'] ?? '?'),
            $uri,
            mcp_request_headers(),
            '',                                  // never the body: it holds secrets
            (int)(http_response_code() ?: 0),
            $label,
            $reply
        );
    });
}

function mcp_debug_recent(int $limit = 50): array
{
    $limit = max(1, min(200, $limit));
    return db()->query("SELECT * FROM mcp_debug_log ORDER BY id DESC LIMIT $limit")->fetchAll();
}

function mcp_debug_clear(): void
{
    try { db()->exec("DELETE FROM mcp_debug_log"); } catch (Throwable $e) {}
}

/** Every request header, however the SAPI chooses to expose them. */
function mcp_request_headers(): array
{
    if (function_exists('getallheaders')) {
        $h = getallheaders();
        if (is_array($h) && $h) return $h;
    }
    $out = [];
    foreach ($_SERVER as $k => $v) {
        if (strpos($k, 'HTTP_') === 0) {
            $name = str_replace(' ', '-', ucwords(strtolower(str_replace('_', ' ', substr($k, 5)))));
            $out[$name] = (string)$v;
        }
    }
    foreach (['CONTENT_TYPE' => 'Content-Type', 'CONTENT_LENGTH' => 'Content-Length'] as $k => $n) {
        if (isset($_SERVER[$k])) $out[$n] = (string)$_SERVER[$k];
    }
    return $out;
}

/** Recent audit rows for the admin page. */
function mcp_audit_recent(int $limit = 200): array
{
    $limit = max(1, min(1000, $limit));
    // The actor is either a named person (OAuth) or a token label (machine).
    // Both are surfaced so the page can say which it was.
    return db()->query(
        "SELECT a.*, t.label AS token_label, u.name AS user_name
           FROM mcp_audit a
           LEFT JOIN mcp_tokens t ON t.id = a.token_id
           LEFT JOIN users u      ON u.id = a.user_id
          ORDER BY a.id DESC
          LIMIT $limit"
    )->fetchAll();
}

// ------------------------------------------------------------ schema helpers

/** Table name → [column => type], read once per request from information_schema. */
function mcp_schema_map(): array
{
    static $map = null;
    if ($map !== null) return $map;

    $rows = db()->query(
        "SELECT TABLE_NAME AS t, COLUMN_NAME AS c, COLUMN_TYPE AS ty,
                IS_NULLABLE AS nul, COLUMN_KEY AS ky, EXTRA AS ex
           FROM information_schema.COLUMNS
          WHERE TABLE_SCHEMA = DATABASE()
          ORDER BY TABLE_NAME, ORDINAL_POSITION"
    )->fetchAll();

    $map = [];
    foreach ($rows as $r) {
        $map[$r['t']][$r['c']] = [
            'type'     => $r['ty'],
            'nullable' => $r['nul'] === 'YES',
            'key'      => $r['ky'],
            'extra'    => $r['ex'],
        ];
    }
    return $map;
}

/**
 * Which column points at which, in both directions.
 *
 * This is the part of a schema a model cannot guess. `key: MUL` says a column
 * is indexed and nothing about what it means; `students.teacher_id` could
 * point at `users`, at a `teachers` table that no longer exists, or at
 * nothing. Without the answer the first few queries are guesses, and a join
 * onto the wrong table returns zero rows rather than an error — which reads
 * as "no data" instead of "wrong question".
 *
 * Returned as two maps: `out` for what a table's own columns reference, and
 * `in` for what references the table, because "what hangs off a student"
 * matters as much as "what does a student point at".
 */
function mcp_fk_map(): array
{
    static $fk = null;
    if ($fk !== null) return $fk;

    $fk = ['out' => [], 'in' => []];
    try {
        $rows = db()->query(
            "SELECT TABLE_NAME AS t, COLUMN_NAME AS c,
                    REFERENCED_TABLE_NAME AS rt, REFERENCED_COLUMN_NAME AS rc
               FROM information_schema.KEY_COLUMN_USAGE
              WHERE TABLE_SCHEMA = DATABASE() AND REFERENCED_TABLE_NAME IS NOT NULL
              ORDER BY TABLE_NAME, COLUMN_NAME"
        )->fetchAll();
    } catch (Throwable $e) {
        return $fk;      // no privileges on KEY_COLUMN_USAGE: degrade, don't fail
    }

    foreach ($rows as $r) {
        $fk['out'][$r['t']][$r['c']] = $r['rt'] . '.' . $r['rc'];
        $fk['in'][$r['rt']][] = $r['t'] . '.' . $r['c'];
    }
    return $fk;
}

/**
 * A guess at what a table is for, from its name.
 *
 * Deliberately a naming convention rather than a hand-written list: a list
 * goes stale the first time somebody adds a table and forgets to update it,
 * and a stale label is worse than none. Anything unrecognised is grouped as
 * "other", which is honest.
 */
function mcp_table_area(string $table): string
{
    static $rules = [
        'student'    => 'students',
        'attendance' => 'attendance',
        'grade'      => 'students',
        'fee'        => 'fees',
        'payment'    => 'fees',
        'expense'    => 'fees',
        'staff'      => 'staff',
        'payslip'    => 'staff',
        'shift'      => 'staff',
        'inquiry'    => 'crm',
        'lead'       => 'crm',
        'task'       => 'tasks',
        'inventory'  => 'inventory',
        'survey'     => 'surveys',
        'feedback'   => 'surveys',
        'montessori' => 'montessori',
        'mm_'        => 'montessori',
        'user'       => 'people',
        'auth'       => 'system',
        'oauth'      => 'system',
        'mcp_'       => 'system',
        'app_'       => 'system',
        'notif'      => 'system',
        'token'      => 'system',
        'schema_'    => 'system',
    ];
    $t = strtolower($table);
    foreach ($rules as $needle => $area) {
        if (strpos($t, $needle) !== false) return $area;
    }
    return 'other';
}

function mcp_table_exists(string $table): bool
{
    return isset(mcp_schema_map()[$table]);
}

/** Validate a table name and hand back its canonical form. */
function mcp_require_table(string $table): string
{
    $table = trim($table);
    if (!preg_match('/^[A-Za-z0-9_]+$/', $table) || !mcp_table_exists($table)) {
        throw new McpError("No such table: '$table'. Call the schema tool to list tables.");
    }
    return $table;
}

function mcp_is_secret_column(string $column): bool
{
    return in_array(strtolower($column), MCP_SECRET_COLUMNS, true);
}

/** Blank out credential material before any row leaves the server. */
function mcp_redact_rows(array $rows): array
{
    foreach ($rows as &$row) {
        if (!is_array($row)) continue;
        foreach ($row as $col => $_) {
            if (mcp_is_secret_column((string)$col)) $row[$col] = '[redacted]';
        }
    }
    return $rows;
}

// ------------------------------------------------------------- SQL guardrails

/**
 * A read must be exactly one SELECT (or a WITH…SELECT). Anything that writes,
 * touches the filesystem, or smuggles a second statement is refused.
 */
function mcp_assert_read_only(string $sql): void
{
    $trimmed = trim($sql);
    if ($trimmed === '') throw new McpError('sql is empty.');

    // A trailing semicolon is fine; one in the middle means a second statement.
    $body = rtrim($trimmed, "; \t\n\r");
    if (strpos($body, ';') !== false) {
        throw new McpError('Only a single statement is allowed — remove the ";".');
    }
    if (!preg_match('/^\s*(SELECT|WITH)\b/i', $body)) {
        throw new McpError('The query tool runs SELECT only. Use insert, upsert, update or delete to write.');
    }
    $banned = ['INTO OUTFILE', 'INTO DUMPFILE', 'LOAD_FILE', 'LOAD DATA',
               'BENCHMARK(', 'SLEEP(', 'GET_LOCK('];
    foreach ($banned as $needle) {
        if (stripos($body, $needle) !== false) {
            throw new McpError("Not allowed in a query: $needle");
        }
    }
}

/** A WHERE clause is caller-supplied SQL; it must not smuggle a statement. */
function mcp_assert_where(string $where): string
{
    $where = trim($where);
    if ($where === '') {
        throw new McpError('where is required — refusing to touch every row in the table.');
    }
    if (strpos(rtrim($where, "; \t\n\r"), ';') !== false) {
        throw new McpError('where must be a single expression — remove the ";".');
    }
    return $where;
}

/**
 * Named parameters, normalised to the ":name" form PDO wants.
 * Accepts {"id": 4} or {":id": 4} so a caller need not know the convention.
 */
function mcp_params(array $in): array
{
    $out = [];
    foreach ($in as $k => $v) {
        $k = (string)$k;
        if ($k === '') continue;
        if ($k[0] !== ':') $k = ':' . $k;
        if (!preg_match('/^:[A-Za-z_][A-Za-z0-9_]*$/', $k)) {
            throw new McpError("Bad parameter name: '$k'");
        }
        if (is_array($v) || is_object($v)) {
            throw new McpError("Parameter '$k' must be a scalar or null.");
        }
        $out[$k] = is_bool($v) ? (int)$v : $v;
    }
    return $out;
}

/** Drop parameters the statement never mentions — native prepares reject extras. */
function mcp_bind_used(string $sql, array $params): array
{
    $used = [];
    foreach ($params as $name => $value) {
        if (preg_match('/' . preg_quote($name, '/') . '\b/', $sql)) $used[$name] = $value;
    }
    return $used;
}

// ---------------------------------------------------------------- the toolbox

/**
 * The catalogue sent to the client on tools/list.
 *
 * Descriptions are written for the model, not for a developer: they say when
 * to reach for the tool and what will make it refuse, because a tool that
 * fails informatively is worth more than one that fails safely and silently.
 */
function mcp_tools(): array
{
    $tools = [
        [
            'name'        => 'schema',
            'description' => 'Describe the database. Call this FIRST when you do not already know the '
                           . 'table or column names — guessing them wastes a round trip, and a join onto '
                           . 'the wrong table returns no rows rather than an error, which looks like '
                           . '"no data" instead of "wrong question". With no arguments it lists every '
                           . 'table grouped by what it is for, with row counts. With table set, it returns '
                           . 'each column\'s type, whether it is nullable, and — the part you cannot '
                           . 'guess — which column it references and which columns reference it, so joins '
                           . 'can be written correctly the first time. Ask for several tables at once. '
                           . 'Credential columns are listed but marked redacted: their values are never '
                           . 'returned and cannot be written.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table' => ['description' => 'Omit to list every table, grouped by area. Give one name '
                                               . 'for its columns and relationships, or several — '
                                               . '"students,attendance,fees" or ["students","attendance"] — '
                                               . 'to learn a whole area in one call. Up to 25.',
                                'anyOf' => [
                                    ['type' => 'string'],
                                    ['type' => 'array', 'items' => ['type' => 'string']],
                                ]],
                ],
            ],
        ],
        [
            'name'        => 'query',
            'description' => 'Run one read-only SELECT and return the rows. Use named parameters '
                           . '(:name) with the params object rather than pasting values into the SQL — '
                           . 'the same placeholder may only appear ONCE per statement. Refuses anything '
                           . 'that is not a single SELECT.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['sql'],
                'properties' => [
                    'sql'    => ['type' => 'string',
                                 'description' => 'A single SELECT statement. No trailing semicolon needed.'],
                    'params' => ['type' => 'object',
                                 'description' => 'Named parameter values, e.g. {"grade": "LKG"}.'],
                    'limit'  => ['type' => 'integer',
                                 'description' => 'Max rows (default ' . MCP_ROWS_DEFAULT
                                                . ', ceiling ' . MCP_ROWS_MAX . ').'],
                ],
            ],
        ],
        [
            'name'        => 'insert',
            'description' => 'Insert one row and return its new id. Column names are checked against '
                           . 'the real table, so a typo fails loudly instead of being ignored. '
                           . 'For staff duty lists use staff_duty_template_upsert instead of inserting '
                           . 'into staff_duty_templates — but insert is also routed there automatically. '
                           . 'For parent surveys use survey_spec_upsert, not insert into survey_definitions.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['table', 'values'],
                'properties' => [
                    'table'  => ['type' => 'string'],
                    'values' => ['type' => 'object',
                                 'description' => 'column → value. Use null for SQL NULL.'],
                ],
            ],
        ],
        [
            'name'        => 'upsert',
            'description' => 'Create or update one row. Pass table + values. If values includes the '
                           . 'primary key and that row exists it is updated; otherwise a new row is '
                           . 'inserted. For duty lists this is the same as staff_duty_template_upsert '
                           . '(table staff_duty_templates, or omit table and pass title/frequency/'
                           . 'audience). For parent surveys use survey_spec_upsert.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'table'    => ['type' => 'string',
                                   'description' => 'Table name. Default staff_duty_templates when title '
                                                  . 'and frequency are present.'],
                    'values'   => ['type' => 'object',
                                   'description' => 'column → value. For duties you may also pass fields '
                                                  . 'at the top level (title, frequency, audience, …).'],
                    'title'      => ['type' => 'string'],
                    'frequency'  => ['type' => 'string'],
                    'audience'   => ['type' => 'string'],
                    'id'         => ['type' => 'integer'],
                    'notes'      => ['type' => 'string'],
                    'user_ids'   => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'starts_on'  => ['type' => 'string'],
                    'ends_on'    => ['type' => 'string'],
                    'weekdays'   => ['type' => 'array'],
                    'repeat_as'  => ['type' => 'string'],
                    'is_active'  => ['type' => 'boolean'],
                    'sort_order' => ['type' => 'integer'],
                    'where'    => ['type' => 'string',
                                   'description' => 'Optional. If set and rows match, update those '
                                                  . '(same caps as update). If none match, insert.'],
                    'params'   => ['type' => 'object'],
                    'max_rows' => ['type' => 'integer'],
                ],
            ],
        ],
        [
            'name'        => 'update',
            'description' => 'Update rows matching a WHERE clause. The WHERE is REQUIRED and the server '
                           . 'counts the matching rows before writing: if that count exceeds max_rows it '
                           . 'refuses and tells you the number, so a forgotten condition cannot rewrite a '
                           . 'whole table. The previous values of every row touched are recorded and can '
                           . 'be read back from mcp_audit.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['table', 'values', 'where'],
                'properties' => [
                    'table'    => ['type' => 'string'],
                    'values'   => ['type' => 'object', 'description' => 'column → new value.'],
                    'where'    => ['type' => 'string',
                                   'description' => 'SQL condition without the WHERE keyword, '
                                                  . 'e.g. "id = :id". Cannot be empty.'],
                    'params'   => ['type' => 'object', 'description' => 'Values for the WHERE placeholders.'],
                    'max_rows' => ['type' => 'integer',
                                   'description' => 'Refuse if more rows than this match (default '
                                                  . MCP_WRITE_CAP_DEFAULT . ', ceiling '
                                                  . MCP_WRITE_CAP_MAX . ').'],
                ],
            ],
        ],
        [
            'name'        => 'delete',
            'description' => 'Delete rows matching a WHERE clause. Same rules as update: WHERE required, '
                           . 'matching rows counted first and refused if over max_rows, and every deleted '
                           . 'row is recorded in mcp_audit beforehand so it can be reconstructed.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['table', 'where'],
                'properties' => [
                    'table'    => ['type' => 'string'],
                    'where'    => ['type' => 'string', 'description' => 'SQL condition, cannot be empty.'],
                    'params'   => ['type' => 'object'],
                    'max_rows' => ['type' => 'integer'],
                ],
            ],
        ],
        [
            'name'        => 'survey_spec_validate',
            'description' => 'Dry-run validate a parent-survey JSON definition without saving. Returns '
                           . 'ok/errors and a normalized preview. Use this before survey_spec_upsert. '
                           . 'Does NOT create or change any survey. Built-in PHP surveys '
                           . '(orientation_2026_27, field_trip) cannot be replaced — pick a new key.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['spec'],
                'properties' => [
                    'spec' => ['type' => 'object',
                               'description' => 'Full survey JSON: key, title, sections[].questions[]. '
                                              . 'Question types: text, textarea, radio, checkbox, matrix, '
                                              . 'student_picker, select. Dynamic options: classes, students, '
                                              . 'parents (students/parents are 3-letter typeahead on the '
                                              . 'public form, not a full roster dropdown). Optional '
                                              . 'options_filter and fills on student_picker.'],
                ],
            ],
        ],
        [
            'name'        => 'survey_spec_upsert',
            'description' => 'Create or update a JSON survey definition in the database. Refuses keys that '
                           . 'are already defined in PHP (orientation_2026_27, field_trip, …). After upsert, '
                           . 'call survey_publish to mint the shareable link. Existing built-in surveys are '
                           . 'never modified by this tool.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['spec'],
                'properties' => [
                    'spec' => ['type' => 'object',
                               'description' => 'Same shape as survey_spec_validate.spec.'],
                ],
            ],
        ],
        [
            'name'        => 'survey_spec_get',
            'description' => 'Fetch one survey definition by key (PHP or MCP/JSON) and whether a live '
                           . 'share link (surveys row) exists.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['key'],
                'properties' => [
                    'key' => ['type' => 'string', 'description' => 'spec_key, e.g. sports_day_2026'],
                ],
            ],
        ],
        [
            'name'        => 'survey_spec_list',
            'description' => 'List every questionnaire: built-in PHP specs (read-only) and MCP/JSON '
                           . 'definitions, with source and whether each is published.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
        ],
        [
            'name'        => 'survey_publish',
            'description' => 'Ensure a live shareable surveys row exists for a spec_key and return the '
                           . 'public URL. Optionally set active true/false. Works for both PHP and MCP specs.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['key'],
                'properties' => [
                    'key'    => ['type' => 'string'],
                    'active' => ['type' => 'boolean',
                                 'description' => 'If set, open (true) or close (false) the survey.'],
                ],
            ],
        ],
        [
            'name'        => 'survey_prefill_links',
            'description' => 'For a published survey, return signed per-student URLs (?pref=) that autofill '
                           . 'child/parent/class without showing the full school roster on the public form. '
                           . 'Pass student_ids, or omit them and filter with grades / enrollment_status.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['key'],
                'properties' => [
                    'key'               => ['type' => 'string'],
                    'student_ids'       => ['type' => 'array', 'items' => ['type' => 'integer']],
                    'grades'            => ['type' => 'array', 'items' => ['type' => 'string']],
                    'enrollment_status' => ['type' => 'array', 'items' => ['type' => 'string']],
                    'ttl_seconds'       => ['type' => 'integer',
                                            'description' => 'Prefill link lifetime (default ~30 days).'],
                ],
            ],
        ],
        [
            'name'        => 'staff_duty_people',
            'description' => 'List active staff who can be assigned a duty (id, name, role). Use this '
                           . 'before staff_duty_template_upsert with audience=users.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => (object)[],
            ],
        ],
        [
            'name'        => 'staff_duty_template_list',
            'description' => 'List duty templates (the configured tasks, not ticks).',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'active_only' => ['type' => 'boolean',
                                      'description' => 'If true, skip inactive templates.'],
                ],
            ],
        ],
        [
            'name'        => 'staff_duty_template_upsert',
            'description' => 'Create or update one duty task. Assign with audience all_teachers, '
                           . 'all_non_teaching, all_staff, or users. Frequency: daily, weekly, monthly, '
                           . 'or adhoc (needs starts_on). Optional ends_on, weekdays, and for adhoc '
                           . 'repeat_as once|daily|weekly|monthly. action_key materials_check opens '
                           . 'today\'s blank materials sheet. Do not insert into '
                           . 'staff_duty_templates by hand.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['title', 'frequency', 'audience'],
                'properties' => [
                    'id'         => ['type' => 'integer', 'description' => 'Set to update an existing template.'],
                    'title'      => ['type' => 'string'],
                    'notes'      => ['type' => 'string', 'description' => 'Short help shown under the task.'],
                    'action_key' => ['type' => 'string', 'enum' => ['', 'materials_check'],
                                     'description' => 'materials_check opens today\'s materials sheet from My duties.'],
                    'frequency'  => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'adhoc']],
                    'audience'   => ['type' => 'string',
                                     'enum' => ['all_teachers', 'all_non_teaching', 'all_staff', 'users']],
                    'user_ids'   => ['type' => 'array', 'items' => ['type' => 'integer'],
                                     'description' => 'Required when audience is users.'],
                    'starts_on'  => ['type' => 'string', 'description' => 'YYYY-MM-DD. Required for adhoc.'],
                    'ends_on'    => ['type' => 'string', 'description' => 'YYYY-MM-DD. Defaults to starts_on for adhoc.'],
                    'weekdays'   => ['type' => 'array',
                                     'items' => ['type' => 'string'],
                                     'description' => 'Sun..Sat or 0-6. Omit for every day.'],
                    'days_mask'  => ['type' => 'integer', 'description' => 'Bitmask Sun=1 .. Sat=64. Alternative to weekdays.'],
                    'repeat_as'  => ['type' => 'string', 'enum' => ['once', 'daily', 'weekly', 'monthly'],
                                     'description' => 'Adhoc only: once in the window, or each day/week/month.'],
                    'is_active'  => ['type' => 'boolean'],
                    'sort_order' => ['type' => 'integer'],
                ],
            ],
        ],
        [
            'name'        => 'staff_duty_template_delete',
            'description' => 'Delete a duty template. Past ticks remain; the task drops off future lists.',
            'inputSchema' => [
                'type'       => 'object',
                'required'   => ['id'],
                'properties' => [
                    'id' => ['type' => 'integer'],
                ],
            ],
        ],
        [
            'name'        => 'staff_duty_status',
            'description' => 'Who ticked what for a period. Default is today / this week / this month. '
                           . 'Returns each person\'s items (done, not_done + reason, pending) plus comments.',
            'inputSchema' => [
                'type'       => 'object',
                'properties' => [
                    'frequency' => ['type' => 'string', 'enum' => ['daily', 'weekly', 'monthly', 'adhoc'],
                                    'description' => 'Omit to return all three current periods.'],
                    'period'    => ['type' => 'string',
                                    'description' => 'daily YYYY-MM-DD, weekly YYYY-Www, monthly YYYY-MM.'],
                    'user_id'   => ['type' => 'integer'],
                ],
            ],
        ],
    ];

    $readonly = [
        'schema', 'query',
        'survey_spec_validate', 'survey_spec_get', 'survey_spec_list', 'survey_prefill_links',
        'staff_duty_people', 'staff_duty_template_list', 'staff_duty_status',
    ];
    $destructive = ['delete', 'staff_duty_template_delete'];
    foreach ($tools as &$t) {
        $ro = in_array($t['name'], $readonly, true);
        $t['annotations'] = [
            'readOnlyHint'    => $ro,
            'destructiveHint' => in_array($t['name'], $destructive, true),
            'openWorldHint'   => false,
        ];
    }
    unset($t);
    return $tools;
}

// --------------------------------------------------------------- tool bodies

function mcp_tool_schema(array $a): array
{
    $map = mcp_schema_map();
    $fk  = mcp_fk_map();

    // `table` accepts one name, a comma-separated list, or an array. Related
    // tables are almost always needed together — a student, its attendance and
    // its fees — and asking for them one at a time is three round trips to
    // learn one thing.
    $wanted = $a['table'] ?? ($a['tables'] ?? null);
    if (is_string($wanted)) {
        $wanted = array_values(array_filter(array_map('trim', explode(',', $wanted)), 'strlen'));
    }
    if (!is_array($wanted)) $wanted = [];

    if ($wanted === []) {
        $areas = [];
        foreach (array_keys($map) as $t) {
            try {
                $n = (int)db()->query("SELECT COUNT(*) FROM `$t`")->fetchColumn();
            } catch (Throwable $e) {
                $n = -1;   // a view, or no permission — still worth listing
            }
            $areas[mcp_table_area($t)][] = [
                'table'   => $t,
                'columns' => count($map[$t]),
                'rows'    => $n,
            ];
        }
        ksort($areas);
        return [
            'areas' => $areas,
            'count' => count($map),
            'next'  => 'Call schema again with table set to a name, or to several '
                     . 'comma-separated names, for their columns and how they join.',
        ];
    }

    if (count($wanted) > 25) {
        throw new McpError('Ask for at most 25 tables at once.');
    }

    $out = [];
    foreach ($wanted as $name) {
        $table = mcp_require_table((string)$name);
        $cols  = [];
        foreach ($map[$table] as $col => $meta) {
            $entry = [
                'column'   => $col,
                'type'     => $meta['type'],
                'nullable' => $meta['nullable'],
                'key'      => $meta['key'],
                'extra'    => $meta['extra'],
            ];
            // Only present when true or known — a column list where every row
            // carries "references": null is harder to read, not easier.
            if (isset($fk['out'][$table][$col])) {
                $entry['references'] = $fk['out'][$table][$col];
            }
            if (mcp_is_secret_column($col)) {
                $entry['redacted'] = true;
                $entry['note']     = 'Always returned as [redacted]. It cannot be read or written.';
            }
            $cols[] = $entry;
        }

        $one = ['table' => $table, 'area' => mcp_table_area($table), 'columns' => $cols];
        if (!empty($fk['in'][$table])) {
            $one['referenced_by'] = array_values(array_unique($fk['in'][$table]));
        }
        $out[] = $one;
    }

    // One table asked for, one table returned — a client that asked a simple
    // question should not have to unwrap a list to read the answer.
    return count($out) === 1 ? $out[0] : ['tables' => $out];
}

function mcp_tool_query(array $a): array
{
    $sql = (string)($a['sql'] ?? '');
    mcp_assert_read_only($sql);

    $limit = isset($a['limit']) ? (int)$a['limit'] : MCP_ROWS_DEFAULT;
    $limit = max(1, min(MCP_ROWS_MAX, $limit));

    $body = rtrim(trim($sql), "; \t\n\r");
    // Only add a LIMIT when the caller has not written one, so their own
    // LIMIT/OFFSET paging is not silently broken.
    if (!preg_match('/\bLIMIT\s+\d+/i', $body)) {
        $body .= " LIMIT $limit";
    }

    $params = mcp_params(is_array($a['params'] ?? null) ? $a['params'] : []);
    $stmt   = db()->prepare($body);
    $stmt->execute(mcp_bind_used($body, $params));
    $rows = $stmt->fetchAll();

    return [
        'rows'      => mcp_redact_rows($rows),
        'row_count' => count($rows),
        'truncated' => count($rows) >= $limit,
    ];
}

function mcp_duty_id_from_write_args(array $a): int
{
    if (isset($a['values']['id'])) return (int)$a['values']['id'];
    $where = (string)($a['where'] ?? '');
    $params = mcp_params(is_array($a['params'] ?? null) ? $a['params'] : []);
    if (preg_match('/\bid\s*=\s*:([A-Za-z0-9_]+)/', $where, $m)) {
        $k = ':' . $m[1];
        if (array_key_exists($k, $params)) return (int)$params[$k];
    }
    if (preg_match('/\bid\s*=\s*(\d+)/', $where, $m)) return (int)$m[1];
    throw new McpError(
        'Need the template id. Prefer staff_duty_template_upsert (with id) or staff_duty_template_delete.'
    );
}

/**
 * Generic insert/update/delete against duty config tables is routed to the
 * domain tools so a client that only knows "write a row" still succeeds.
 */
function mcp_route_duty_write(string $verb, string $table, array $a, ?int $userId): ?array
{
    if ($table === 'staff_duty_template_users') {
        throw new McpError(
            'Do not write staff_duty_template_users directly. '
            . 'Call staff_duty_template_upsert (or upsert) with audience="users" and user_ids=[...].'
        );
    }
    if ($table !== 'staff_duty_templates') return null;

    if ($verb === 'delete') {
        return mcp_tool_staff_duty_template_delete(['id' => mcp_duty_id_from_write_args($a)]);
    }
    $values = is_array($a['values'] ?? null) ? $a['values'] : [];
    if ($verb === 'update') {
        $values['id'] = mcp_duty_id_from_write_args($a);
    }
    if (isset($values['user_ids']) && is_string($values['user_ids'])) {
        $decoded = json_decode($values['user_ids'], true);
        if (is_array($decoded)) $values['user_ids'] = $decoded;
    }
    $out = mcp_tool_staff_duty_template_upsert($values, $userId);
    $out['routed'] = 'staff_duty_template_upsert';
    return $out;
}

function mcp_tool_insert(array $a, ?int $userId = null): array
{
    $table = mcp_require_table((string)($a['table'] ?? ''));
    $routed = mcp_route_duty_write('insert', $table, $a, $userId);
    if ($routed !== null) return $routed;
    mcp_assert_writable($table);

    $values = $a['values'] ?? null;
    if (!is_array($values) || $values === []) {
        throw new McpError('values must be a non-empty object of column → value.');
    }
    $cols = mcp_check_columns($table, array_keys($values));

    $names  = [];
    $binds  = [];
    $params = [];
    foreach ($cols as $i => $col) {
        $ph          = ':v' . $i;
        $names[]     = "`$col`";
        $binds[]     = $ph;
        $params[$ph] = mcp_scalar($values[$col], $col);
    }

    $sql  = "INSERT INTO `$table` (" . implode(', ', $names) . ") VALUES (" . implode(', ', $binds) . ")";
    $stmt = db()->prepare($sql);
    $stmt->execute($params);

    return [
        'table'         => $table,
        'inserted_id'   => (int)db()->lastInsertId(),
        'rows_affected' => $stmt->rowCount(),
    ];
}

function mcp_duty_upsert_field_names(): array
{
    return [
        'id', 'title', 'notes', 'frequency', 'audience', 'user_ids',
        'starts_on', 'ends_on', 'weekdays', 'days_mask', 'repeat_as',
        'is_active', 'sort_order',
    ];
}

function mcp_merge_upsert_values(array $a, string $table = ''): array
{
    $values = is_array($a['values'] ?? null) ? $a['values'] : [];
    if (array_key_exists('id', $a) && !array_key_exists('id', $values)) {
        $values['id'] = $a['id'];
    }
    $duty = ($table === '' || $table === 'staff_duty_templates');
    if ($duty) {
        foreach (mcp_duty_upsert_field_names() as $k) {
            if ($k === 'id') continue;
            if (array_key_exists($k, $a) && !array_key_exists($k, $values)) {
                $values[$k] = $a[$k];
            }
        }
    }
    return $values;
}

function mcp_looks_like_duty_upsert(array $a): bool
{
    $values = mcp_merge_upsert_values($a, 'staff_duty_templates');
    $title = trim((string)($values['title'] ?? ''));
    $freq  = trim((string)($values['frequency'] ?? ''));
    return $title !== '' && $freq !== '';
}

function mcp_primary_key_columns(string $table): array
{
    $cols = [];
    foreach (mcp_schema_map()[$table] as $name => $meta) {
        if (($meta['key'] ?? '') === 'PRI') $cols[] = $name;
    }
    return $cols;
}

function mcp_pk_values_present(array $pkCols, array $values): bool
{
    if ($pkCols === []) return false;
    foreach ($pkCols as $col) {
        if (!array_key_exists($col, $values) || $values[$col] === null || $values[$col] === '') {
            return false;
        }
        if ($col === 'id' && (int)$values[$col] <= 0) return false;
    }
    return true;
}

/**
 * Create or update. Duty tables route to staff_duty_template_upsert;
 * survey_definitions with a spec object routes to survey_spec_upsert.
 */
function mcp_tool_upsert(array $a, ?int $userId = null): array
{
    $tableRaw = trim((string)($a['table'] ?? ''));
    if ($tableRaw === '' && mcp_looks_like_duty_upsert($a)) {
        $tableRaw = 'staff_duty_templates';
    }
    if ($tableRaw === '') {
        throw new McpError(
            'table is required — or pass title and frequency to upsert a staff duty.'
        );
    }
    $table = mcp_require_table($tableRaw);
    $a['values'] = mcp_merge_upsert_values($a, $table);

    if ($table === 'survey_definitions') {
        $spec = $a['spec'] ?? ($a['values']['spec'] ?? null);
        if (is_array($spec)) {
            $out = mcp_tool_survey_spec_upsert(['spec' => $spec], $userId);
            $out['routed'] = 'survey_spec_upsert';
            return $out;
        }
        throw new McpError(
            'Do not use generic upsert on survey_definitions — call survey_spec_upsert with spec={...}.'
        );
    }

    $routed = mcp_route_duty_write('upsert', $table, $a, $userId);
    if ($routed !== null) return $routed;
    mcp_assert_writable($table);

    $values = $a['values'];
    if (!is_array($values) || $values === []) {
        throw new McpError('values must be a non-empty object of column → value.');
    }

    $where = trim((string)($a['where'] ?? ''));
    if ($where !== '') {
        $params = mcp_params(is_array($a['params'] ?? null) ? $a['params'] : []);
        $cap    = mcp_cap($a);
        $before = mcp_rows_matching($table, mcp_assert_where($where), $params, $cap, 'upsert', false);
        if ($before !== []) {
            $upd = mcp_tool_update($a, $userId);
            $upd['action'] = 'updated';
            return $upd;
        }
        return mcp_tool_insert(['table' => $table, 'values' => $values], $userId)
            + ['action' => 'inserted'];
    }

    $pkCols = mcp_primary_key_columns($table);
    if (mcp_pk_values_present($pkCols, $values)) {
        $clauses = [];
        $params  = [];
        foreach ($pkCols as $i => $col) {
            $ph = ':pk' . $i;
            $clauses[] = "`$col` = $ph";
            $params[$ph] = mcp_scalar($values[$col], $col);
        }
        $pkWhere = implode(' AND ', $clauses);
        $before  = mcp_rows_matching($table, $pkWhere, $params, 1, 'upsert', false);
        if ($before !== []) {
            $upd = mcp_tool_update([
                'table'    => $table,
                'values'   => $values,
                'where'    => $pkWhere,
                'params'   => $params,
                'max_rows' => 1,
            ], $userId);
            $upd['action'] = 'updated';
            return $upd;
        }
    }

    $insertValues = $values;
    $schema = mcp_schema_map()[$table];
    foreach ($pkCols as $col) {
        $extra = strtolower((string)($schema[$col]['extra'] ?? ''));
        if (strpos($extra, 'auto_increment') === false) continue;
        if (!array_key_exists($col, $insertValues)) continue;
        $v = $insertValues[$col];
        if ($v === null || $v === '' || (is_numeric($v) && (int)$v <= 0)) {
            unset($insertValues[$col]);
        }
    }

    $ins = mcp_tool_insert(['table' => $table, 'values' => $insertValues], $userId);
    $ins['action'] = 'inserted';
    return $ins;
}

function mcp_tool_update(array $a, ?int $userId = null): array
{
    $table = mcp_require_table((string)($a['table'] ?? ''));
    $routed = mcp_route_duty_write('update', $table, $a, $userId);
    if ($routed !== null) return $routed;
    mcp_assert_writable($table);

    $values = $a['values'] ?? null;
    if (!is_array($values) || $values === []) {
        throw new McpError('values must be a non-empty object of column → new value.');
    }
    $cols   = mcp_check_columns($table, array_keys($values));
    $where  = mcp_assert_where((string)($a['where'] ?? ''));
    $params = mcp_params(is_array($a['params'] ?? null) ? $a['params'] : []);
    $cap    = mcp_cap($a);

    // Count and capture BEFORE touching anything: this is the guard that turns
    // a forgotten WHERE into a refusal instead of a rewritten table.
    $before = mcp_rows_matching($table, $where, $params, $cap, 'update');

    $sets = [];
    $bind = $params;
    foreach ($cols as $i => $col) {
        $ph        = ':s' . $i;
        $sets[]    = "`$col` = $ph";
        $bind[$ph] = mcp_scalar($values[$col], $col);
    }

    $sql  = "UPDATE `$table` SET " . implode(', ', $sets) . " WHERE $where";
    $stmt = db()->prepare($sql);
    $stmt->execute(mcp_bind_used($sql, $bind));

    return [
        'table'         => $table,
        'rows_affected' => $stmt->rowCount(),
        'rows_matched'  => count($before),
        '_before'       => $before,
    ];
}

function mcp_tool_delete(array $a, ?int $userId = null): array
{
    $table = mcp_require_table((string)($a['table'] ?? ''));
    $routed = mcp_route_duty_write('delete', $table, $a, $userId);
    if ($routed !== null) return $routed;
    mcp_assert_writable($table);

    $where  = mcp_assert_where((string)($a['where'] ?? ''));
    $params = mcp_params(is_array($a['params'] ?? null) ? $a['params'] : []);
    $cap    = mcp_cap($a);

    $before = mcp_rows_matching($table, $where, $params, $cap, 'delete');

    $sql  = "DELETE FROM `$table` WHERE $where";
    $stmt = db()->prepare($sql);
    $stmt->execute(mcp_bind_used($sql, $params));

    return [
        'table'         => $table,
        'rows_affected' => $stmt->rowCount(),
        'rows_matched'  => count($before),
        '_before'       => $before,
    ];
}

// --------------------------------------------------------- survey domain tools

function mcp_tool_survey_spec_validate(array $a): array
{
    $spec = $a['spec'] ?? null;
    if (!is_array($spec)) {
        throw new McpError('spec must be a JSON object.');
    }
    $v = survey_definition_validate($spec);
    $phpReserved = false;
    $key = is_array($v['spec'] ?? null) ? (string)$v['spec']['key'] : trim((string)($spec['key'] ?? ''));
    if ($key !== '' && survey_spec_is_php($key)) {
        $phpReserved = true;
        $v['errors'][] = "Key '$key' is a built-in PHP survey and cannot be upserted via MCP.";
        $v['ok'] = false;
    }
    return [
        'ok'           => (bool)$v['ok'],
        'errors'       => $v['errors'],
        'spec'         => $v['spec'],
        'php_reserved' => $phpReserved,
    ];
}

function mcp_tool_survey_spec_upsert(array $a, ?int $userId): array
{
    $spec = $a['spec'] ?? null;
    if (!is_array($spec)) {
        throw new McpError('spec must be a JSON object.');
    }
    try {
        $saved = survey_definition_upsert($spec, $userId);
    } catch (InvalidArgumentException $e) {
        throw new McpError($e->getMessage());
    } catch (PDOException $e) {
        throw new McpError('Could not save survey definition (is migration 063 applied?): ' . $e->getMessage());
    }
    return [
        'ok'      => true,
        'key'     => (string)$saved['key'],
        'title'   => (string)$saved['title'],
        'source'  => 'mcp',
        'spec'    => $saved,
        'next'    => 'Call survey_publish with this key to mint the shareable URL.',
        'rows_affected' => 1,
    ];
}

function mcp_tool_survey_spec_get(array $a): array
{
    $key = trim((string)($a['key'] ?? ''));
    if ($key === '') throw new McpError('key is required.');
    $source = survey_spec_is_php($key) ? 'code' : 'mcp';
    $spec = survey_spec($key);
    if ($spec === null) {
        throw new McpError("No survey definition for key '$key'.");
    }
    $live = null;
    try {
        $st = db()->prepare("SELECT id, token, active, created_at FROM surveys WHERE spec_key = :k ORDER BY id LIMIT 1");
        $st->execute([':k' => $key]);
        $live = $st->fetch() ?: null;
    } catch (Throwable $e) {
        $live = null;
    }
    return [
        'key'     => $key,
        'source'  => $source,
        'spec'    => $spec,
        'published' => $live ? [
            'survey_id' => (int)$live['id'],
            'active'    => (int)$live['active'] === 1,
            'url'       => survey_url((string)$live['token']),
            'created_at'=> (string)$live['created_at'],
        ] : null,
    ];
}

function mcp_tool_survey_spec_list(array $a): array
{
    $items = [];
    foreach (survey_all_specs() as $key => $entry) {
        $live = null;
        try {
            $st = db()->prepare("SELECT id, token, active FROM surveys WHERE spec_key = :k ORDER BY id LIMIT 1");
            $st->execute([':k' => $key]);
            $live = $st->fetch() ?: null;
        } catch (Throwable $e) {
            $live = null;
        }
        $items[] = [
            'key'       => $key,
            'title'     => (string)($entry['spec']['title'] ?? $key),
            'source'    => $entry['source'],
            'questions' => count(survey_questions($entry['spec'])),
            'published' => $live ? [
                'survey_id' => (int)$live['id'],
                'active'    => (int)$live['active'] === 1,
                'url'       => survey_url((string)$live['token']),
            ] : null,
        ];
    }
    return ['surveys' => $items, 'count' => count($items)];
}

function mcp_tool_survey_publish(array $a, ?int $userId): array
{
    $key = trim((string)($a['key'] ?? ''));
    if ($key === '') throw new McpError('key is required.');
    if (!survey_spec($key)) {
        throw new McpError("No survey definition for key '$key'. Upsert a JSON spec first, or use a built-in key.");
    }
    $row = survey_ensure($key, $userId);
    if (!$row) {
        throw new McpError('Could not create the live survey row (tables missing?). Apply migrations through 063.');
    }
    if (array_key_exists('active', $a)) {
        survey_set_active((int)$row['id'], !empty($a['active']));
        $st = db()->prepare("SELECT * FROM surveys WHERE id = :id LIMIT 1");
        $st->execute([':id' => (int)$row['id']]);
        $row = $st->fetch() ?: $row;
    }
    return [
        'key'           => $key,
        'survey_id'     => (int)$row['id'],
        'active'        => (int)$row['active'] === 1,
        'url'           => survey_url((string)$row['token']),
        'source'        => survey_spec_is_php($key) ? 'code' : 'mcp',
        'rows_affected' => 1,
    ];
}

function mcp_tool_survey_prefill_links(array $a): array
{
    $key = trim((string)($a['key'] ?? ''));
    if ($key === '') throw new McpError('key is required.');
    $spec = survey_spec($key);
    if ($spec === null) throw new McpError("No survey definition for key '$key'.");

    $st = db()->prepare("SELECT * FROM surveys WHERE spec_key = :k ORDER BY id LIMIT 1");
    $st->execute([':k' => $key]);
    $survey = $st->fetch();
    if (!$survey) {
        throw new McpError("Survey '$key' is not published yet. Call survey_publish first.");
    }
    if ((int)$survey['active'] !== 1) {
        throw new McpError("Survey '$key' is closed. Re-open with survey_publish active=true.");
    }

    $filter = [];
    if (!empty($a['grades']) && is_array($a['grades'])) {
        $filter['grades'] = $a['grades'];
    }
    if (!empty($a['enrollment_status']) && is_array($a['enrollment_status'])) {
        $filter['enrollment_status'] = $a['enrollment_status'];
    }
    $ids = null;
    if (isset($a['student_ids']) && is_array($a['student_ids'])) {
        $ids = array_map('intval', $a['student_ids']);
    }
    $ttl = isset($a['ttl_seconds']) ? (int)$a['ttl_seconds'] : null;
    $links = survey_prefill_links($survey, $filter, $ids, $ttl);
    return [
        'key'       => $key,
        'survey_id' => (int)$survey['id'],
        'count'     => count($links),
        'links'     => $links,
    ];
}

// --------------------------------------------------------- staff duty tools

function mcp_tool_staff_duty_people(array $a): array
{
    if (!duty_tables_ready()) {
        throw new McpError('Duty tables are missing — apply migrate_064_staff_duties.sql.');
    }
    $people = [];
    foreach (duty_people() as $p) {
        $people[] = [
            'id'   => (int)$p['id'],
            'name' => (string)$p['name'],
            'role' => (string)$p['role'],
        ];
    }
    return ['count' => count($people), 'people' => $people];
}

function mcp_tool_staff_duty_template_list(array $a): array
{
    if (!duty_tables_ready()) {
        throw new McpError('Duty tables are missing — apply migrate_064_staff_duties.sql.');
    }
    $rows = duty_templates(!empty($a['active_only']));
    $out = [];
    foreach ($rows as $t) {
        $out[] = [
            'id'             => (int)$t['id'],
            'title'          => (string)$t['title'],
            'notes'          => $t['notes'],
            'action_key'     => (string)($t['action_key'] ?? ''),
            'frequency'      => (string)$t['frequency'],
            'repeat_as'      => (string)($t['repeat_as'] ?? 'once'),
            'starts_on'      => $t['starts_on'] ?? null,
            'ends_on'        => $t['ends_on'] ?? null,
            'days_mask'      => (int)($t['days_mask'] ?? 127),
            'schedule'       => duty_schedule_label($t),
            'audience'       => (string)$t['audience'],
            'user_ids'       => $t['user_ids'],
            'assignee_count' => (int)$t['assignee_count'],
            'is_active'      => (int)$t['is_active'] === 1,
            'sort_order'     => (int)$t['sort_order'],
        ];
    }
    return ['count' => count($out), 'templates' => $out];
}

function mcp_tool_staff_duty_template_upsert(array $a, ?int $userId): array
{
    if (!duty_tables_ready()) {
        throw new McpError('Duty tables are missing — apply migrate_064_staff_duties.sql.');
    }
    try {
        $tpl = duty_template_upsert($a, $userId);
    } catch (InvalidArgumentException $e) {
        throw new McpError($e->getMessage());
    }
    return [
        'id'             => (int)$tpl['id'],
        'title'          => (string)$tpl['title'],
        'action_key'     => (string)($tpl['action_key'] ?? ''),
        'frequency'      => (string)$tpl['frequency'],
        'repeat_as'      => (string)($tpl['repeat_as'] ?? 'once'),
        'starts_on'      => $tpl['starts_on'] ?? null,
        'ends_on'        => $tpl['ends_on'] ?? null,
        'schedule'       => duty_schedule_label($tpl),
        'audience'       => (string)$tpl['audience'],
        'user_ids'       => $tpl['user_ids'] ?? [],
        'assignee_count' => count(duty_assignee_ids($tpl)),
        'is_active'      => (int)$tpl['is_active'] === 1,
        'rows_affected'  => 1,
    ];
}

function mcp_tool_staff_duty_template_delete(array $a): array
{
    $id = (int)($a['id'] ?? 0);
    if ($id <= 0) throw new McpError('id is required.');
    if (!duty_template($id)) throw new McpError("No template #$id.");
    duty_template_delete($id);
    return ['id' => $id, 'deleted' => true, 'rows_affected' => 1];
}

function mcp_tool_staff_duty_status(array $a): array
{
    if (!duty_tables_ready()) {
        throw new McpError('Duty tables are missing — apply migrate_064_staff_duties.sql.');
    }
    $freqs = DUTY_FREQUENCIES;
    if (!empty($a['frequency'])) {
        $f = (string)$a['frequency'];
        if (!in_array($f, DUTY_FREQUENCIES, true)) {
            throw new McpError('frequency must be daily, weekly, monthly or adhoc.');
        }
        $freqs = [$f];
    }
    $filterUser = isset($a['user_id']) ? (int)$a['user_id'] : 0;
    $periods = [];
    foreach ($freqs as $freq) {
        $key = isset($a['period']) && $a['period'] !== '' && count($freqs) === 1
            ? (string)$a['period']
            : duty_period_key($freq);
        $rows = duty_review($freq, $key);
        $items = [];
        foreach ($rows as $r) {
            if ($filterUser > 0 && (int)$r['user_id'] !== $filterUser) continue;
            $items[] = [
                'id'         => (int)$r['id'],
                'user_id'    => (int)$r['user_id'],
                'user_name'  => (string)$r['user_name'],
                'role'       => (string)$r['user_role'],
                'title'      => (string)$r['title'],
                'source'     => (string)$r['source'],
                'status'     => (string)$r['status'],
                'reason'     => $r['reason'],
                'comment'    => $r['comment'],
                'extra_work' => $r['extra_work'],
            ];
        }
        $pending = 0; $done = 0; $not = 0;
        foreach ($items as $it) {
            if ($it['status'] === 'done') $done++;
            elseif ($it['status'] === 'not_done') $not++;
            else $pending++;
        }
        $periods[] = [
            'frequency' => $freq,
            'period'    => $key,
            'label'     => duty_period_label($freq, $key),
            'counts'    => ['pending' => $pending, 'done' => $done, 'not_done' => $not],
            'items'     => $items,
        ];
    }
    return ['periods' => $periods];
}

// --------------------------------------------------------- write-side helpers

function mcp_assert_writable(string $table): void
{
    if (isset(MCP_WRITE_DENY_TABLES[$table])) {
        throw new McpError("Refusing to write to `$table` — " . MCP_WRITE_DENY_TABLES[$table] . '.');
    }
}

/** Validate column names against the real table, and reject credential columns. */
function mcp_check_columns(string $table, array $columns): array
{
    $known = mcp_schema_map()[$table];
    $out   = [];
    foreach ($columns as $col) {
        $col = (string)$col;
        if (!isset($known[$col])) {
            throw new McpError(
                "`$table` has no column '$col'. Known columns: " . implode(', ', array_keys($known))
            );
        }
        if (mcp_is_secret_column($col)) {
            throw new McpError(
                "Refusing to set `$table`.`$col` — credential columns must be set through the app "
                . '(admin.php hashes them correctly); a raw value written here would lock someone out.'
            );
        }
        $out[] = $col;
    }
    return $out;
}

function mcp_scalar($v, string $col)
{
    if (is_array($v) || is_object($v)) {
        throw new McpError("Value for '$col' must be a scalar or null.");
    }
    return is_bool($v) ? (int)$v : $v;
}

function mcp_cap(array $a): int
{
    $cap = isset($a['max_rows']) ? (int)$a['max_rows'] : MCP_WRITE_CAP_DEFAULT;
    return max(1, min(MCP_WRITE_CAP_MAX, $cap));
}

/**
 * The rows a write is about to touch — both the count check and the before
 * image in one pass. Fetches cap+1 so "more than the cap" is detectable
 * without a second round trip.
 */
function mcp_rows_matching(string $table, string $where, array $params, int $cap, string $verb, bool $requireMatch = true): array
{
    $sql  = "SELECT * FROM `$table` WHERE $where LIMIT " . ($cap + 1);
    try {
        $stmt = db()->prepare($sql);
        $stmt->execute(mcp_bind_used($sql, $params));
        $rows = $stmt->fetchAll();
    } catch (PDOException $e) {
        throw new McpError('The where clause did not run: ' . $e->getMessage());
    }

    if (count($rows) > $cap) {
        throw new McpError(
            "Refusing to $verb: more than $cap rows match that where clause. "
            . 'Narrow the condition, or raise max_rows deliberately if you really mean to '
            . "$verb them all."
        );
    }
    if ($requireMatch && $rows === []) {
        throw new McpError("Nothing matches that where clause — no rows to $verb.");
    }
    return $rows;
}

// ------------------------------------------------------------------ dispatch

/**
 * Run one tool call. Returns the tool's result array.
 * Throws McpError for anything the caller can fix.
 */
function mcp_call_tool(string $name, array $args, ?int $tokenId,
                       ?int $userId = null, ?int $oauthTokenId = null): array
{
    $writes  = ['insert', 'upsert', 'update', 'delete', 'survey_spec_upsert', 'survey_publish',
                'staff_duty_template_upsert', 'staff_duty_template_delete'];
    $isWrite = in_array($name, $writes, true);
    $pdo     = db();
    $began   = false;

    try {
        if ($isWrite) { $pdo->beginTransaction(); $began = true; }

        switch ($name) {
            case 'schema': $result = mcp_tool_schema($args); break;
            case 'query':  $result = mcp_tool_query($args);  break;
            case 'insert': $result = mcp_tool_insert($args, $userId); break;
            case 'upsert': $result = mcp_tool_upsert($args, $userId); break;
            case 'update': $result = mcp_tool_update($args, $userId); break;
            case 'delete': $result = mcp_tool_delete($args, $userId); break;
            case 'survey_spec_validate': $result = mcp_tool_survey_spec_validate($args); break;
            case 'survey_spec_upsert':   $result = mcp_tool_survey_spec_upsert($args, $userId); break;
            case 'survey_spec_get':      $result = mcp_tool_survey_spec_get($args); break;
            case 'survey_spec_list':     $result = mcp_tool_survey_spec_list($args); break;
            case 'survey_publish':       $result = mcp_tool_survey_publish($args, $userId); break;
            case 'survey_prefill_links': $result = mcp_tool_survey_prefill_links($args); break;
            case 'staff_duty_people':            $result = mcp_tool_staff_duty_people($args); break;
            case 'staff_duty_template_list':     $result = mcp_tool_staff_duty_template_list($args); break;
            case 'staff_duty_template_upsert':   $result = mcp_tool_staff_duty_template_upsert($args, $userId); break;
            case 'staff_duty_template_delete':   $result = mcp_tool_staff_duty_template_delete($args); break;
            case 'staff_duty_status':            $result = mcp_tool_staff_duty_status($args); break;
            default:
                throw new McpError("No such tool: '$name'.", -32601);
        }

        if ($began) { $pdo->commit(); $began = false; }

        // The before image is for the audit log, not for the model.
        $before = $result['_before'] ?? null;
        unset($result['_before']);

        mcp_audit_log($tokenId, $name, $args, true, null,
                      (int)($result['rows_affected'] ?? 0),
                      is_array($before) ? mcp_redact_rows($before) : null,
                      $userId, $oauthTokenId);
        return $result;

    } catch (Throwable $e) {
        if ($began && $pdo->inTransaction()) $pdo->rollBack();
        mcp_audit_log($tokenId, $name, $args, false, $e->getMessage(), 0, null,
                      $userId, $oauthTokenId);
        throw $e;
    }
}
