<?php
/**
 * mcp_admin.php — issue and revoke MCP API tokens, and read the audit trail.
 *
 * A token minted here gives an AI client full read/write access to this
 * school's database. That is the point, and it is also why this page shows the
 * token exactly once, keeps only its hash, and puts the log of every call the
 * API has made directly underneath the button that creates them.
 *
 *   POST op=mint   { label }  → generate a token, shown once
 *   POST op=revoke { id }     → kill a token immediately
 */
declare(strict_types=1);

require_once __DIR__ . '/includes/auth.php';
require_once __DIR__ . '/includes/functions.php';
require_once __DIR__ . '/includes/mcp.php';
require_once __DIR__ . '/includes/oauth.php';

$user = require_admin();

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    csrf_check();
    $op = (string)($_POST['op'] ?? '');
    try {
        if ($op === 'mint') {
            $label = trim((string)($_POST['label'] ?? ''));
            if ($label === '') {
                flash_set('error', 'Give the token a label — a list of unlabelled tokens is impossible to revoke safely.');
                redirect('/mcp_admin.php');
            }
            $token = mcp_token_mint($label, (int)$user['id']);
            // Carried in the session, not the URL: a token in a query string
            // lands in browser history and server logs.
            start_session_once();
            $_SESSION['mcp_fresh_token'] = $token;
            flash_set('ok', 'Token created. Copy it now — it cannot be shown again.');
            redirect('/mcp_admin.php');
        } elseif ($op === 'revoke') {
            mcp_token_revoke((int)($_POST['id'] ?? 0));
            flash_set('ok', 'Token revoked. Any client using it stops working immediately.');
            redirect('/mcp_admin.php');
        } elseif ($op === 'debug_on') {
            $until = mcp_debug_enable((int)($_POST['minutes'] ?? 15));
            flash_set('ok', 'Recording incoming requests until '
                          . date('H:i', $until) . '. Try connecting now, then reload this page.');
            redirect('/mcp_admin.php#debug');
        } elseif ($op === 'debug_off') {
            mcp_debug_disable();
            flash_set('ok', 'Recording stopped.');
            redirect('/mcp_admin.php#debug');
        } elseif ($op === 'debug_clear') {
            mcp_debug_clear();
            flash_set('ok', 'Recorded requests cleared.');
            redirect('/mcp_admin.php#debug');
        } elseif ($op === 'disconnect') {
            oauth_session_revoke((int)($_POST['id'] ?? 0));
            flash_set('ok', 'Disconnected. That client will have to sign in again.');
            redirect('/mcp_admin.php');
        } elseif ($op === 'client_add') {
            $name = trim((string)($_POST['client_name'] ?? ''));
            if ($name === '') {
                flash_set('error', 'Give the application a name, so the consent screen can say what is asking.');
                redirect('/mcp_admin.php#apps');
            }
            // One URI per line is the shape people actually paste.
            $uris = preg_split('/[\r\n]+/', (string)($_POST['redirect_uris'] ?? '')) ?: [];
            $c = oauth_client_register($name, $uris, ($_POST['confidential'] ?? '') === '1', null);
            start_session_once();
            $_SESSION['mcp_fresh_client'] = $c;   // shown once, like a token
            flash_set('ok', 'Application registered.');
            redirect('/mcp_admin.php#apps');
        } elseif ($op === 'client_disable') {
            oauth_client_disable((string)($_POST['client_id'] ?? ''));
            flash_set('ok', 'Application removed. Its client ID no longer works and its sessions are cut.');
            redirect('/mcp_admin.php#apps');
        } elseif ($op === 'open_reg') {
            $on = (string)($_POST['on'] ?? '') === '1';
            oauth_open_registration_set($on);
            flash_set('ok', $on
                ? 'Applications can register themselves again.'
                : 'Self-registration is off. Only the applications listed here can sign in — '
                . 'anything already connected keeps working.');
            redirect('/mcp_admin.php#apps');
        }
    } catch (Throwable $e) {
        flash_set('error', 'Could not do that: ' . $e->getMessage());
        redirect('/mcp_admin.php');
    }
    redirect('/mcp_admin.php');
}

start_session_once();
$freshToken = (string)($_SESSION['mcp_fresh_token'] ?? '');
unset($_SESSION['mcp_fresh_token']);   // shown once, then gone

$freshClient = is_array($_SESSION['mcp_fresh_client'] ?? null) ? $_SESSION['mcp_fresh_client'] : null;
unset($_SESSION['mcp_fresh_client']);  // the secret, likewise

$ready = true;
$tokens = $audit = $sessions = $clients = [];
try {
    $tokens = mcp_tokens_all();
    $audit  = mcp_audit_recent(100);
} catch (Throwable $e) {
    $ready = false;
}
// OAuth arrived a migration later than the token table, so an install that is
// mid-upgrade can have one without the other.
$oauthReady = true;
$openReg = true;
try {
    $sessions = oauth_sessions();
    $clients  = oauth_clients_all();
    $openReg  = oauth_open_registration();
} catch (Throwable $e) {
    $oauthReady = false;
}

/*
 * What to put in the "create one by hand" box.
 *
 * A redirect URL cannot be guessed — it belongs to the client, and a wrong one
 * is refused at the consent screen with nothing useful to show the person. But
 * any client that has ever registered itself has already told us its real one,
 * so the newest of those is by far the best default. Falls back to empty
 * rather than to something invented.
 */
$suggestedRedirect = '';
foreach ($clients as $c) {
    $u = json_decode((string)$c['redirect_uris'], true);
    if (is_array($u) && $u !== []) { $suggestedRedirect = implode("\n", $u); break; }
}

$baseUrl = app_base_url() . '/mcp.php';

$debugOn = false; $debugRows = []; $debugUntil = 0;
try {
    $debugOn    = mcp_debug_active();
    $debugUntil = (int)app_setting('mcp_debug_until', '0');
    $debugRows  = mcp_debug_recent(40);
} catch (Throwable $e) { /* migration 060 not applied yet */ }

$pageTitle  = 'MCP API';
$wideLayout = true;
require __DIR__ . '/includes/header.php';
?>

<div class="page-head">
    <div>
        <h1>MCP API</h1>
        <p class="muted">
            Connect an AI assistant to this school's data. A token issued here can read
            <strong>and write</strong> every module — students, attendance, fees, CRM,
            surveys, tasks, inventory.
        </p>
    </div>
</div>

<?php if (!$ready): ?>
    <div class="card">
        <p><strong>Not set up yet.</strong> The MCP tables aren't in place — run the
           migrations (<code>migrate_055_mcp_api.sql</code>) and reload this page.</p>
    </div>
<?php else: ?>

<?php if ($freshToken !== ''): ?>
    <div class="card" style="border-left:4px solid var(--ok, #2e7d32);">
        <h3 style="margin-top:0;">Your new token — copy it now</h3>
        <p class="muted small">
            This is the only time it will ever be shown. Only its hash is stored, so it
            cannot be recovered — if you lose it, revoke it and mint another.
        </p>
        <div class="sv-linkrow">
            <input id="fresh-token" class="sv-linkbox" type="text" readonly
                   value="<?= e($freshToken) ?>" onclick="this.select();">
            <button class="btn" type="button"
                    onclick="navigator.clipboard.writeText(<?= e(json_encode($freshToken)) ?>).then(()=>{this.textContent='Copied';setTimeout(()=>this.textContent='Copy',1600);});">Copy</button>
        </div>

        <p class="muted small" style="margin-bottom:.3rem;">Add it to Claude Code with:</p>
        <pre class="sv-linkbox" style="white-space:pre-wrap; padding:.6rem;"><code>claude mcp add --transport http mtt <?= e($baseUrl) ?> \
  --header "Authorization: Bearer <?= e($freshToken) ?>"</code></pre>
    </div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0;">Connected assistants</h3>
    <p class="muted small">
        Sign-ins through OAuth. <strong>This is the way to connect a person</strong> — nothing
        gets pasted anywhere, the access expires hourly, and everything below is recorded
        against the name of whoever signed in.
    </p>
    <?php if (!$oauthReady): ?>
        <p class="muted">Not available yet — run <code>migrate_056_oauth.sql</code>.</p>
    <?php elseif (!$sessions): ?>
        <p class="muted">
            Nobody connected yet. Point a client at <code><?= e($baseUrl) ?></code> and it will
            offer to sign in — no token needed.
        </p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr>
            <th>Who</th><th>Application</th><th>Connected</th><th>Last used</th><th>Expires</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($sessions as $s): ?>
            <tr>
                <td><strong><?= e((string)$s['user_name']) ?></strong></td>
                <td><?= e((string)($s['client_name'] ?: 'Unknown client')) ?></td>
                <td class="small"><?= e((string)$s['created_at']) ?></td>
                <td class="small"><?= $s['last_used_at'] ? e((string)$s['last_used_at'])
                        : '<span class="muted">never</span>' ?></td>
                <td class="small"><?= e((string)$s['expires_at']) ?></td>
                <td>
                    <form method="post" style="margin:0;"
                          onsubmit="return confirm('Disconnect this client? They will have to sign in again.');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="disconnect">
                        <input type="hidden" name="id" value="<?= (int)$s['id'] ?>">
                        <button class="btn btn-ghost" type="submit">Disconnect</button>
                    </form>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php if ($oauthReady): ?>
<?php
// Clients register themselves, so this list grows on its own — and a client
// stuck in a retry loop shows up here as a burst of rows within one hour,
// which is the fastest way to recognise it. The registration endpoint
// rate-limits per caller; that limit is what this makes visible.
$lastHour = 0;
foreach ($clients as $c) {
    if (strtotime((string)$c['created_at']) > time() - 3600) $lastHour++;
}
?>
<div class="card" id="apps">
    <h3 style="margin-top:0;">Registered applications</h3>

    <?php if ($freshClient): ?>
        <div class="card" style="background:#fff5fa;border:2px solid #ad1457;">
            <p style="margin:0 0 .5rem;"><strong>Copy these into the application's settings now.</strong>
               <?php if ($freshClient['client_secret'] !== null): ?>
                   The secret is shown once and is not stored in a form that can show it again.
               <?php endif; ?></p>
            <p class="small" style="margin:.4rem 0;">Client ID<br>
               <code style="font-size:1rem;word-break:break-all;"><?= e((string)$freshClient['client_id']) ?></code></p>
            <?php if ($freshClient['client_secret'] !== null): ?>
                <p class="small" style="margin:.4rem 0;">Client secret<br>
                   <code style="font-size:1rem;word-break:break-all;"><?= e((string)$freshClient['client_secret']) ?></code></p>
            <?php endif; ?>
            <p class="muted small" style="margin:.4rem 0 0;">
                Redirect
                <?= count($freshClient['redirect_uris']) === 1 ? 'URL' : 'URLs' ?>:
                <?= e(implode(', ', $freshClient['redirect_uris'])) ?>
            </p>
        </div>
    <?php endif; ?>

    <?php if ($openReg): ?>
        <p class="muted small">
            <strong>Any application may register itself.</strong> That is how MCP is meant to
            work — a client asks for an ID the first time it connects, and the ID grants
            nothing on its own until somebody signs in at the consent screen. It also means
            you never have to hand out an ID by hand.
            <?php if ($lastHour > 5): ?>
                <br><strong style="color:#c62828;"><?= (int)$lastHour ?> registered in the last hour.</strong>
                That usually means a client is retrying in a loop and failing somewhere after
                registration — check <em>Recent activity</em> and the PHP error log.
            <?php endif; ?>
        </p>
    <?php else: ?>
        <p class="small" style="color:#ad1457;">
            <strong>Self-registration is off.</strong> Only the applications listed below can
            start a sign-in. Anything else is turned away before the consent screen, so a new
            assistant needs an ID and secret created here and typed into its settings.
        </p>
    <?php endif; ?>

    <form method="post" style="margin:0 0 1rem;">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="open_reg">
        <input type="hidden" name="on" value="<?= $openReg ? '0' : '1' ?>">
        <button class="btn <?= $openReg ? 'btn-ghost' : 'btn-primary' ?>" type="submit">
            <?= $openReg ? 'Only allow applications I create here' : 'Let applications register themselves again' ?>
        </button>
    </form>

    <?php if ($clients): ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr>
            <th>Application</th><th>Client ID</th><th>Redirect</th><th>Type</th>
            <th>Registered</th><th>Last used</th><th>Sessions</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach (array_slice($clients, 0, 25) as $c): ?>
            <?php $uris = json_decode((string)$c['redirect_uris'], true) ?: []; ?>
            <tr<?= $c['disabled_at'] !== null ? ' style="opacity:.5;"' : '' ?>>
                <td><?= e((string)($c['client_name'] ?: 'Unnamed')) ?>
                    <?php if ($c['disabled_at'] !== null): ?>
                        <br><span class="muted small">removed</span>
                    <?php endif; ?></td>
                <td class="small"><code style="word-break:break-all;"><?= e((string)$c['client_id']) ?></code></td>
                <td class="small" style="word-break:break-all;max-width:18rem;"><?= e(implode(' ', $uris)) ?></td>
                <td class="small"><?= $c['secret_hash'] !== null ? 'ID + secret' : 'ID only (PKCE)' ?></td>
                <td class="small"><?= e((string)$c['created_at']) ?></td>
                <td class="small"><?= $c['last_used_at'] ? e((string)$c['last_used_at'])
                        : '<span class="muted">never</span>' ?></td>
                <td><?= (int)$c['live_sessions'] ?></td>
                <td>
                    <?php if ($c['disabled_at'] === null): ?>
                    <form method="post" onsubmit="return confirm('Remove this application? Anything using it stops working immediately.');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="client_disable">
                        <input type="hidden" name="client_id" value="<?= e((string)$c['client_id']) ?>">
                        <button class="btn btn-ghost btn-small" type="submit">Remove</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php if (count($clients) > 25): ?>
        <p class="muted small"><?= count($clients) - 25 ?> older ones not shown.</p>
    <?php endif; ?>
    <?php else: ?>
        <p class="muted">No applications yet.</p>
    <?php endif; ?>

    <h4 style="margin:1.4rem 0 .4rem;">Create one by hand</h4>
    <p class="muted small" style="margin-top:0;">
        Use this when the assistant asks you for a Client ID, or when self-registration is
        off. <strong>The redirect URL has to match what the application actually uses</strong>,
        character for character — it is the address the sign-in sends the person back to, and
        a mismatch is refused on purpose. If the application has connected before, copy the
        redirect from its row above rather than typing one from memory.
    </p>
    <form method="post">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="client_add">
        <p style="margin:.4rem 0;">
            <label class="small">Application name<br>
            <input type="text" name="client_name" maxlength="120" required
                   style="width:100%;max-width:26rem;" placeholder="e.g. Claude"></label>
        </p>
        <p style="margin:.4rem 0;">
            <label class="small">Redirect URL — one per line<br>
            <textarea name="redirect_uris" rows="3" required
                      style="width:100%;max-width:34rem;font-family:monospace;"
                      placeholder="https://…"><?= e($suggestedRedirect) ?></textarea></label>
        </p>
        <p class="small" style="margin:.4rem 0;">
            <label><input type="checkbox" name="confidential" value="1" checked>
            Also issue a client secret</label>
            <span class="muted">— leave ticked unless the application only asks for an ID.</span>
        </p>
        <button class="btn btn-primary" type="submit">Create application</button>
    </form>
</div>
<?php endif; ?>

<div class="card">
    <h3 style="margin-top:0;">Issue a token</h3>
    <p class="muted small">
        <strong>Only needed for something that can't open a browser</strong> — a cron job, an
        n8n workflow. For a person, use the OAuth sign-in above instead: a token here is a
        long-lived secret in a file, and it can only ever be labelled, never attributed.
    </p>
    <p class="muted small">
        Name it after the device or person that will hold it, so revoking the right one
        later is obvious.
    </p>
    <form method="post" class="sv-linkrow">
        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
        <input type="hidden" name="op" value="mint">
        <input class="sv-linkbox" type="text" name="label" maxlength="80" required
               placeholder="e.g. Vinod's laptop">
        <button class="btn btn-primary" type="submit">Create token</button>
    </form>
</div>

<div class="card">
    <h3 style="margin-top:0;">Tokens</h3>
    <?php if (!$tokens): ?>
        <p class="muted">No tokens yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr>
            <th>Label</th><th>Created</th><th>Created by</th><th>Last used</th><th>Status</th><th></th>
        </tr></thead>
        <tbody>
        <?php foreach ($tokens as $t): $revoked = $t['revoked_at'] !== null; ?>
            <tr<?= $revoked ? ' class="muted"' : '' ?>>
                <td><?= e((string)$t['label']) ?></td>
                <td><?= e((string)$t['created_at']) ?></td>
                <td><?= e((string)($t['creator_name'] ?? '—')) ?></td>
                <td><?= $t['last_used_at'] ? e((string)$t['last_used_at'])
                        : '<span class="muted">never</span>' ?></td>
                <td><?= $revoked ? 'Revoked ' . e((string)$t['revoked_at'])
                                 : '<strong>Live</strong>' ?></td>
                <td>
                    <?php if (!$revoked): ?>
                    <form method="post" style="margin:0;"
                          onsubmit="return confirm('Revoke this token? Any client using it stops working immediately.');">
                        <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
                        <input type="hidden" name="op" value="revoke">
                        <input type="hidden" name="id" value="<?= (int)$t['id'] ?>">
                        <button class="btn btn-ghost" type="submit">Revoke</button>
                    </form>
                    <?php endif; ?>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<div class="card">
    <h3 style="margin-top:0;">Recent activity</h3>
    <p class="muted small">
        Every call the API has made. Writes record the previous values of the rows they
        touched, so a mistaken change can be read back and reversed.
    </p>
    <?php if (!$audit): ?>
        <p class="muted">Nothing yet.</p>
    <?php else: ?>
    <div style="overflow-x:auto;">
    <table class="table">
        <thead><tr>
            <th>When</th><th>Who</th><th>Tool</th><th>Rows</th><th>Result</th><th>Arguments</th>
        </tr></thead>
        <tbody>
        <?php foreach ($audit as $a): ?>
            <tr>
                <td class="small"><?= e((string)$a['created_at']) ?></td>
                <td class="small">
                    <?php if (!empty($a['user_name'])): ?>
                        <strong><?= e((string)$a['user_name']) ?></strong>
                    <?php elseif (!empty($a['token_label'])): ?>
                        <?= e((string)$a['token_label']) ?> <span class="muted">(token)</span>
                    <?php else: ?>
                        <span class="muted">—</span>
                    <?php endif; ?>
                </td>
                <td><code><?= e((string)$a['tool']) ?></code></td>
                <td><?= (int)$a['rows_affected'] ?></td>
                <td class="small">
                    <?= $a['ok'] ? 'ok' : '<strong>failed</strong> — ' . e((string)$a['error']) ?>
                </td>
                <td class="small" style="max-width:32rem;">
                    <code style="word-break:break-all;">
                        <?= e(mb_strimwidth((string)$a['arguments'], 0, 220, '…')) ?>
                    </code>
                </td>
            </tr>
        <?php endforeach; ?>
        </tbody>
    </table>
    </div>
    <?php endif; ?>
</div>

<?php endif; ?>

<div class="card">
    <h3>What a token can do, and what it can't</h3>
    <ul class="muted small" style="margin:0; padding-left:1.1rem;">
        <li><strong>Full read and write</strong> across every table, by design. Treat a token
            like the admin password — anyone holding it has the same reach.</li>
        <li><strong>Reads are SELECT-only and row-capped</strong>, and password material is
            blanked out on the way out: no token can harvest PINs.</li>
        <li><strong>Updates and deletes must carry a condition</strong>, and the server counts
            the matching rows first. Too many, and it refuses and says how many it would have
            hit — a forgotten condition can't quietly rewrite a whole table.</li>
        <li><strong>PINs can't be set through the API.</strong> They're hashed properly by
            <a href="/admin.php">Users</a>; a raw value written straight into the column would
            lock someone out.</li>
        <li><strong>This page's own tables are off limits to the API.</strong> A log the audited
            party can erase isn't a log.</li>
    </ul>
</div>

<div class="card" id="debug">
    <h3 style="margin-top:0;">Record incoming requests</h3>
    <p class="muted small">
        When a client says it cannot connect and everything here looks healthy, the question
        that matters is whether the request reaches this server at all — and if it does, what
        it looks like. The activity log above only shows calls that got as far as running a
        tool, so anything failing earlier leaves no trace at either end.
        <br><br>
        Switch this on, try connecting, then reload. <strong>The Authorization value is never
        stored</strong> — only whether one arrived. Recording stops on its own.
    </p>

    <?php if ($debugOn): ?>
        <p><strong style="color:#c62828;">Recording until <?= e(date('H:i', $debugUntil)) ?>.</strong></p>
        <form method="post" style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="debug_off">
            <button class="btn btn-ghost" type="submit">Stop recording</button>
        </form>
    <?php else: ?>
        <form method="post" style="display:inline;">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="debug_on">
            <input type="hidden" name="minutes" value="15">
            <button class="btn btn-primary" type="submit">Record for 15 minutes</button>
        </form>
    <?php endif; ?>
    <?php if ($debugRows): ?>
        <form method="post" style="display:inline;"
              onsubmit="return confirm('Clear the recorded requests?');">
            <input type="hidden" name="_csrf" value="<?= e(csrf_token()) ?>">
            <input type="hidden" name="op" value="debug_clear">
            <button class="btn btn-ghost" type="submit">Clear</button>
        </form>
    <?php endif; ?>

    <?php if ($debugRows): ?>
        <h4 style="margin-bottom:.3rem;">What arrived</h4>
        <?php foreach ($debugRows as $d): ?>
            <details style="margin:.4rem 0; border:1px solid #eee; border-radius:6px; padding:.5rem;">
                <summary>
                    <code><?= e((string)$d['method']) ?></code>
                    <?= e((string)$d['path']) ?>
                    → <strong><?= (int)$d['status'] ?></strong>
                    <span class="muted small">
                        <?= e((string)$d['created_at']) ?>
                        <?= $d['note'] ? '· ' . e((string)$d['note']) : '' ?>
                    </span>
                </summary>
                <p class="small" style="margin:.4rem 0 .2rem;"><strong>Headers</strong></p>
                <pre class="small" style="white-space:pre-wrap;word-break:break-all;background:#fafafa;padding:.4rem;"><?= e((string)$d['headers']) ?></pre>
                <?php if (trim((string)$d['body']) !== ''): ?>
                    <p class="small" style="margin:.4rem 0 .2rem;"><strong>Body</strong></p>
                    <pre class="small" style="white-space:pre-wrap;word-break:break-all;background:#fafafa;padding:.4rem;"><?= e((string)$d['body']) ?></pre>
                <?php endif; ?>
            </details>
        <?php endforeach; ?>
    <?php elseif ($debugOn): ?>
        <p class="muted">Nothing recorded yet. Try connecting, then reload this page.</p>
        <p class="muted small">
            If you try to connect and <em>still</em> nothing appears here, the request is not
            reaching this server at all — the problem is between the client and the host, not
            in this application.
        </p>
    <?php endif; ?>
</div>

<?php require __DIR__ . '/includes/footer.php'; ?>
