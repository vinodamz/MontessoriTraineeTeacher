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
        } elseif ($op === 'disconnect') {
            oauth_session_revoke((int)($_POST['id'] ?? 0));
            flash_set('ok', 'Disconnected. That client will have to sign in again.');
            redirect('/mcp_admin.php');
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
try {
    $sessions = oauth_sessions();
    $clients  = oauth_clients_all();
} catch (Throwable $e) {
    $oauthReady = false;
}

$scheme  = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
$baseUrl = $scheme . '://' . (string)($_SERVER['HTTP_HOST'] ?? '') . '/mcp.php';

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

<?php require __DIR__ . '/includes/footer.php'; ?>
