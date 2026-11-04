<?php // admin/mcp_connections.php
/*
 * AI app connections (MCP) — Nov 2026. Administrators only.
 * Lists Claude / Perplexity / other apps connected to https://api.batcaveroadfarm.com/mcp
 * and lets you disconnect one or all of them. Server code: includes/mcp/. Setup: docs/MCP.md.
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/mcp/oauth.php';
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set('America/New_York');
mcp_oauth_schema($pdo);

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$flash = $_SESSION['mcp_flash'] ?? null; unset($_SESSION['mcp_flash']);
$go = function (string $msg) { $_SESSION['mcp_flash'] = $msg; header('Location: /admin/mcp_connections.php'); exit; };

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('Security token expired. Try again.');
    $now = mcp_now();
    if (($_POST['do'] ?? '') === 'revoke_client') {
        $cid = (string)($_POST['client_id'] ?? '');
        $st = $pdo->prepare("UPDATE mcp_oauth_tokens SET revoked_at = ? WHERE client_id = ? AND revoked_at IS NULL");
        $st->execute([$now, $cid]);
        $name = (string)($pdo->query("SELECT client_name FROM mcp_oauth_clients WHERE client_id = " . $pdo->quote($cid))->fetchColumn() ?: $cid);
        erp_audit($pdo, 'update', 'mcp_oauth_tokens', null, 'Disconnected AI app: ' . $name);
        $go('Disconnected ' . $name . '. It will need to sign in again.');
    }
    if (($_POST['do'] ?? '') === 'revoke_all') {
        $n = $pdo->prepare("UPDATE mcp_oauth_tokens SET revoked_at = ? WHERE revoked_at IS NULL");
        $n->execute([$now]);
        erp_audit($pdo, 'update', 'mcp_oauth_tokens', null, 'Disconnected all AI apps');
        $go('Disconnected every AI app.');
    }
}

// One row per app: newest token, whether any token is still usable.
$rows = $pdo->query("SELECT c.client_id, c.client_name, c.redirect_uris, c.created_at,
        MAX(t.created_at) AS last_signin, MAX(t.last_used_at) AS last_used,
        SUM(t.revoked_at IS NULL AND t.refresh_expires > UTC_TIMESTAMP()) AS live,
        MAX(u.first_name) AS by_name
      FROM mcp_oauth_clients c
      JOIN mcp_oauth_tokens t ON t.client_id = c.client_id
      LEFT JOIN users u ON u.id = t.user_id
     GROUP BY c.client_id, c.client_name, c.redirect_uris, c.created_at
     ORDER BY live DESC, last_used DESC")->fetchAll(PDO::FETCH_ASSOC);

$local = function (?string $utc): string {
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC'));
    $d->setTimezone(new DateTimeZone('America/New_York'));
    return $d->format('M j, g:i A');
};
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>AI app connections · BCRF Admin</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f1ea;color:#2b2118}
.wrap{max-width:760px;margin:0 auto;padding:18px 14px 60px}
a{color:#4a6741}h1{font-size:1.35rem;margin:10px 0 4px}.sub{color:#6b5a48;font-size:.92rem;margin:0 0 16px}
.card{background:#fff;border:1px solid #e3d8c6;border-radius:12px;padding:14px 16px;margin:12px 0}
.flash{background:#e8f2e3;border:1px solid #b7d1aa;border-radius:10px;padding:10px 14px}
.app{display:flex;justify-content:space-between;gap:12px;align-items:flex-start;flex-wrap:wrap}
.name{font-weight:700}.meta{font-size:.84rem;color:#6b5a48;line-height:1.6}
.pill{display:inline-block;font-size:.72rem;font-weight:700;padding:2px 8px;border-radius:20px;margin-left:6px}
.on{background:#e3f0dc;color:#2f5a22}.off{background:#eee;color:#666}
button{font:inherit;font-weight:700;padding:8px 14px;border-radius:8px;border:1px solid #8b2018;background:#fff;color:#8b2018;cursor:pointer}
button.all{background:#8b2018;color:#fff}code{background:#f1ebe0;padding:1px 5px;border-radius:4px}
</style></head><body><div class="wrap">
<p><a href="/admin/admin.php">← Admin</a></p>
<h1>AI app connections</h1>
<p class="sub">Apps signed in to the farm MCP server at <code>https://api.batcaveroadfarm.com/mcp</code>. Disconnecting signs the app out right away.</p>
<?php if ($flash): ?><div class="flash"><?= $h($flash) ?></div><?php endif; ?>

<?php if (!$rows): ?>
  <div class="card">No AI apps have connected yet. See <code>docs/MCP.md</code> for how to add the server in Claude or Perplexity.</div>
<?php endif; ?>
<?php foreach ($rows as $r): $live = (int)$r['live'] > 0; $hosts = array_unique(array_map(fn($u) => parse_url($u, PHP_URL_HOST), json_decode((string)$r['redirect_uris'], true) ?: [])); ?>
  <div class="card app">
    <div>
      <div class="name"><?= $h($r['client_name']) ?><span class="pill <?= $live ? 'on' : 'off' ?>"><?= $live ? 'Connected' : 'Signed out' ?></span></div>
      <div class="meta">Returns to <?= $h(implode(', ', $hosts)) ?><br>
        Signed in by <?= $h($r['by_name'] ?: '—') ?> · last sign-in <?= $h($local($r['last_signin'])) ?> · last used <?= $h($local($r['last_used'])) ?></div>
    </div>
    <?php if ($live): ?>
    <form method="post">
      <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="revoke_client">
      <input type="hidden" name="client_id" value="<?= $h($r['client_id']) ?>"><button>Disconnect</button>
    </form>
    <?php endif; ?>
  </div>
<?php endforeach; ?>

<?php if ($rows): ?>
<form method="post" style="margin-top:18px">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="do" value="revoke_all">
  <button class="all">Disconnect all</button>
</form>
<?php endif; ?>
<p class="sub" style="margin-top:22px">Every change an AI app makes is in the <a href="/admin/admin.php?view=audit_log">Audit Log</a>, labelled “(AI: app name)”. File edits are backed up in <code>bcrf-mcp-backups</code> in your home folder (outside the website).</p>
</div></body></html>
