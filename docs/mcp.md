# MCP server

Lets an AI assistant read and write this school's data in plain language —
"how many Nursery children haven't paid this term", "summarise the survey
responses that mention food", "add a task for Ayesha to reorder bead chains".

It is a **Model Context Protocol** server implemented directly in PHP, so it
deploys through the existing pipeline and needs no extra process, host or
service.

```
Claude ──HTTPS + bearer token──▶ /mcp.php ──▶ includes/mcp.php ──▶ database
```

## Setting it up

1. Sign in as an admin and go to **/mcp_admin.php** (nav: *MCP API*).
2. Give the token a label naming the device that will hold it, and create it.
3. Copy the token — **this is the only time it is shown.** Only its SHA-256 is
   stored, so it cannot be recovered.
4. Register it with your client:

```bash
claude mcp add --transport http mtt https://mtt.thelittlegraduates.in/mcp.php \
  --header "Authorization: Bearer <token>"
```

Revoke a token from the same page. Revocation is immediate.

## The tools

| Tool | What it does |
|---|---|
| `schema` | Lists tables, or the columns of one table. Call it before guessing names. |
| `query` | One read-only `SELECT`. Named parameters, row-capped. |
| `insert` | One row. Column names checked against the real table. |
| `update` | Rows matching a `WHERE`. Refuses if more rows match than `max_rows`. |
| `delete` | Same rules as `update`. |

Five tools rather than one per operation: the schema has ~68 tables, the whole
catalogue is sent to the model on **every** request, and clients degrade badly
long before a few hundred tools.

## What the rails do and don't do

The server has **full read/write** by design. The rails below do not reduce
what it can do — they stop an accident from being unrecoverable.

- **Reads are `SELECT`-only**, single-statement, row-capped, and refuse
  `INTO OUTFILE`, `LOAD_FILE` and friends.
- **Credential columns are blanked on the way out** (`pin_hash`, `password`,
  `*_hash` secrets), so no token can harvest password material — and they
  cannot be *set* through the API either. PINs are hashed properly by
  `/admin.php`; a raw value written into the column would lock someone out.
- **`UPDATE` and `DELETE` require a `WHERE`**, and the server counts matching
  rows *first*. Over `max_rows` (default 50) it refuses and reports the count.
  This is what stops a forgotten condition rewriting a table.
- **Every write records the previous values** of the rows it touched, in
  `mcp_audit.before_image` — so a mistaken change can be read back and undone.
- **Writes are transactional.** A failed insert leaves nothing behind.
- **`mcp_tokens` and `mcp_audit` are not writable through the tools.** A log
  the audited party can erase is not a log, and the API cannot mint itself a
  credential.

## Things worth knowing

- **A token is as powerful as the admin password.** Treat it the same way.
- **Share-link tokens stay readable** — `student_form_tokens.token`,
  `surveys.token`. These are links an admin can already generate and see in the
  UI, so reading them grants nothing new. Session credentials are a different
  matter and are redacted: `auth_tokens` only ever stores `validator_hash`.
- **Every call is logged** to `mcp_audit` with the tool, arguments, outcome and
  a hashed IP, and shown under *Recent activity* on the admin page.
- The endpoint answers in JSON even when it dies, so a failure reaches the
  client as an error it can read rather than silence.

## Diagnosing it from a shell

The endpoint reads its body from stdin under CLI, so you can drive it directly
without a client:

```bash
echo '{"jsonrpc":"2.0","id":1,"method":"tools/list"}' \
  | HTTP_AUTHORIZATION="Bearer <token>" REQUEST_METHOD=POST php mcp.php
```

## Files

| Path | Role |
|---|---|
| `mcp.php` | JSON-RPC endpoint (Streamable HTTP transport) |
| `includes/mcp.php` | Auth, tool catalogue, validation, execution, audit |
| `mcp_admin.php` | Admin UI: mint, revoke, read the audit trail |
| `sql/migrate_055_mcp_api.sql` | `mcp_tokens`, `mcp_audit` |
