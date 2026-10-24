<?php
// admin/jobs_install.php
// One-time database setup for Jobs & Applicant Tracking (administrators only).
// Open https://batcaveroadfarm.com/admin/jobs_install.php, tap "Preview", then "Run setup".
// Safe to run again: it skips anything already done and never deletes data.
// Delete this file from the server once the report says "Done".

ob_start();
require_once '../config.php';
check_admin();
require_once __DIR__ . '/../includes/auth.php';
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/jobs/schema.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

if (($_SESSION['user_role'] ?? '') !== 'administrator') {
    ob_end_clean();
    http_response_code(403);
    exit('Administrators only.');
}

$result = null;
$mode   = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $result = ['ok' => false, 'lines' => [['error', 'Your session expired. Reload the page and try again.']]];
    } else {
        $mode   = ($_POST['mode'] ?? '') === 'run' ? 'run' : 'preview';
        $result = jobs_install($pdo, $mode !== 'run');
        if ($mode === 'run') erp_audit($pdo, 'update', 'jobs_setup', null, $result['ok'] ? 'Jobs database setup ran OK' : 'Jobs database setup ran with errors');
    }
}
ob_end_clean();

$colors = ['ok' => '#166534', 'skip' => '#57534e', 'warn' => '#92400e', 'error' => '#b91c1c', 'info' => '#1e40af'];
$marks  = ['ok' => '✔', 'skip' => '–', 'warn' => '!', 'error' => '✖', 'info' => 'ℹ'];
?><!DOCTYPE html>
<html lang="en"><head><meta charset="UTF-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex, nofollow"><title>Jobs setup</title>
<style>
body{font-family:system-ui,-apple-system,sans-serif;background:#f5f5f4;color:#1c1917;margin:0;padding:16px;font-size:15px;line-height:1.5}
.card{background:#fff;border:1px solid #e7e5e4;border-radius:10px;padding:16px;max-width:720px;margin:0 auto 16px}
h1{font-size:20px;margin:0 0 8px}
button{font-size:16px;padding:12px 18px;border-radius:8px;border:0;font-weight:700;margin:6px 6px 0 0}
.pre{background:#e7e5e4;color:#1c1917}.run{background:#3b2010;color:#fff}
li{margin:6px 0;list-style:none}ul{padding:0;margin:0}
.m{display:inline-block;width:1.4em;font-weight:700}
</style></head><body>
<div class="card">
  <h1>Jobs &amp; Applicant Tracking — database setup</h1>
  <p>This adds the new Jobs tables, adds the <b>Staff</b> role, and seeds the 4 job categories and the default emails. It never deletes anything, and it's safe to run twice.</p>
  <p><b>Preview</b> shows what would change without touching anything. <b>Run setup</b> makes the changes.</p>
  <p style="font-size:13px;color:#57534e">Your nightly backup already covers the database. If you want an extra one first: cPanel → phpMyAdmin → Export.</p>
  <form method="post">
    <?= csrf_field() ?>
    <button class="pre" name="mode" value="preview">Preview</button>
    <button class="run" name="mode" value="run">Run setup</button>
  </form>
</div>
<?php if ($result): ?>
<div class="card">
  <h1><?= $mode === 'run' ? ($result['ok'] ? 'Setup report' : 'Setup report — needs attention') : 'Preview' ?></h1>
  <ul>
  <?php foreach ($result['lines'] as [$lvl, $msg]): ?>
    <li style="color:<?= $colors[$lvl] ?? '#1c1917' ?>"><span class="m"><?= $marks[$lvl] ?? '·' ?></span><?= htmlspecialchars($msg) ?></li>
  <?php endforeach; ?>
  </ul>
  <?php if ($mode === 'run' && $result['ok']): ?>
    <p style="margin-top:14px"><b>Next:</b> screenshot this report and send it to me, then delete <code>admin/jobs_install.php</code> in File Manager.</p>
  <?php endif; ?>
</div>
<?php endif; ?>
</body></html>
