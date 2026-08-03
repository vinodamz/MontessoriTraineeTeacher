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

## Connecting — for a person (OAuth)

**This is the way to connect yourself or a colleague.** Nothing is copied,
pasted or stored in a file.

```bash
claude mcp add --transport http mtt https://mtt.thelittlegraduates.in/mcp.php
```

No token. The client discovers the server, registers itself, opens a browser,
you sign in with your PIN, and a consent screen tells you exactly what you are
about to grant. Access lasts an hour and renews silently; the refresh token
lives in your OS keychain.

Why this and not a token:

| | Bearer token | OAuth |
|---|---|---|
| Credential on disk | 64-char string, never expires | refresh token in the keychain |
| Can be pasted into a chat | **yes** | no — you never see one |
| Audit log says | a label you typed | **the actual person** |
| Leaked → | full access until noticed | access dies within the hour |

Only **admins** may consent. A teacher signing in is told so and gets no
Allow button.

See connected clients — and disconnect any of them — at **/mcp_admin.php**.

## Connecting — for a machine (bearer token)

Only for something that cannot open a browser: cron, n8n, a shell script.

1. Sign in as an admin and go to **/mcp_admin.php** (nav: *MCP API*).
2. Give the token a label naming the device that will hold it, and create it.
3. Copy the token — **this is the only time it is shown.** Only its SHA-256 is
   stored, so it cannot be recovered.
4. Register it with your client:

```bash
claude mcp add --transport http mtt https://mtt.thelittlegraduates.in/mcp.php \
  --header "Authorization: Bearer <token>"
```

Revoke from the same page. Revocation is immediate.

> A bearer token is as powerful as the admin password and never expires. If one
> is ever pasted somewhere it shouldn't be — a chat, a ticket, a screenshot —
> revoke it and mint another. That is a thirty-second fix; not doing it isn't.

## How the OAuth side works

Authorization code + PKCE (S256 only). No implicit flow, no password grant,
no client-credentials grant.

| Endpoint | Purpose |
|---|---|
| `/.well-known/oauth-authorization-server` | RFC 8414 discovery |
| `/.well-known/oauth-protected-resource` | RFC 9728 — points `/mcp.php` at the AS |
| `/oauth/register.php` | RFC 7591 dynamic client registration |
| `/oauth/authorize.php` | Sign-in bounce + consent screen |
| `/oauth/token.php` | Code → tokens, refresh → tokens |
| `/oauth/revoke.php` | RFC 7009 revocation |

The two well-known URLs have no file extension and are served by rewrite rules
in `.htaccess`, which also passes the `Authorization` header through to PHP —
Apache strips it otherwise, and every request then arrives unauthenticated.

**Registration is open**, because there is no way to pre-share an id with
software the school hasn't installed yet. A `client_id` alone grants nothing:
it can only *ask*, and the ask still ends at a consent screen behind a PIN.
What registration cannot do is nominate an arbitrary redirect — URIs must be
https, a literal loopback address, or a reverse-DNS private scheme.

**Things that are deliberately unforgiving:**

- An authorization code lives **60 seconds**, is single-use, and is bound to
  the client, the redirect URI and the PKCE challenge.
- **Replaying a code revokes every token it ever issued.** Replay means the
  code leaked; the safe assumption is that its tokens did too.
- **Refresh tokens rotate.** Presenting a retired one revokes the entire chain
  for that client — the signature of a stolen token being used alongside the
  real one.
- Deactivating someone in `/admin.php` **kills their API access immediately**.
  Tokens must not outlive the account.

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
| `includes/mcp.php` | Tool catalogue, validation, execution, audit |
| `includes/oauth.php` | Authorization server: clients, PKCE, codes, tokens |
| `oauth/meta.php` | The two discovery documents |
| `oauth/register.php` | Dynamic client registration |
| `oauth/authorize.php` | Sign-in bounce and consent screen |
| `oauth/token.php` | Token issue and refresh |
| `oauth/revoke.php` | Token revocation |
| `mcp_admin.php` | Admin UI: connections, tokens, audit trail |
| `sql/migrate_055_mcp_api.sql` | `mcp_tokens`, `mcp_audit` |
| `sql/migrate_056_oauth.sql` | `oauth_clients`, `oauth_auth_codes`, `oauth_tokens` |

## If a client cannot connect

Run these three against the live site — they separate the three failure modes
that look identical from inside a client:

```bash
curl -sS -o /dev/null -w "%{http_code}\n" \
  https://mtt.thelittlegraduates.in/.well-known/oauth-protected-resource
curl -sS https://mtt.thelittlegraduates.in/.well-known/oauth-authorization-server
curl -sS -i -X POST https://mtt.thelittlegraduates.in/mcp.php \
  -H 'Content-Type: application/json' -d '{"jsonrpc":"2.0","id":1,"method":"tools/list"}'
```

| Symptom | Cause |
|---|---|
| well-known 404 / 403 | The rewrite is not firing. There are rules in both the root `.htaccess` and `.well-known/.htaccess`; check `mod_rewrite` is enabled. |
| metadata lists `http://` URLs | Scheme detection is wrong for this host — set `site_base_url` (below). A client told to POST registration to an `http` URL hits the Force-HTTPS 301, and a redirect on POST drops the body. |
| `/mcp.php` returns 401 + `WWW-Authenticate` | The server is healthy. The problem is client-side — re-add the connector. |
| `/mcp.php` returns 500 or HTML | Something is throwing; the body says what, and the detail is in the PHP error log. |

### Forcing the public origin

`app_base_url()` looks at `HTTPS`, `X-Forwarded-Proto`, `X-Forwarded-SSL`,
`REQUEST_SCHEME` and the port, then assumes https for any non-loopback host.
If a host still gets it wrong, set the answer explicitly — it overrides
detection everywhere, including the OAuth discovery documents and the parent
survey links:

```sql
INSERT INTO app_settings (setting_key, setting_value)
VALUES ('site_base_url', 'https://mtt.thelittlegraduates.in')
ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value);
```
