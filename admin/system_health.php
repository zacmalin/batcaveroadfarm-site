<?php // admin/system_health.php
/*
 * System Health (Nov 2026). Administrators only.
 *   1. Scheduled jobs: last good run / failure for every cron (includes/cron_health.php)
 *   2. PHP errors: the site's error_log files, grouped so repeat errors show once with a count
 *   3. Uptime: the two URLs UptimeRobot should watch
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/cron_health.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

$jobs = bcrf_cron_status($pdo);

// ── PHP error logs ───────────────────────────────────────────────────────────
// cPanel writes an "error_log" file in the folder of the script that failed.
$root = realpath(dirname(__DIR__)) ?: dirname(__DIR__);
$days = max(1, min(60, (int)($_GET['days'] ?? 7)));
$logFiles = [];
$it = new RecursiveIteratorIterator(
    new RecursiveCallbackFilterIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        fn($f, $k, $iter) => !$iter->hasChildren() || !in_array($f->getFilename(), ['img', 'node_modules', 'vendor', '.git', 'uploads'], true)),
    RecursiveIteratorIterator::SELF_FIRST);
$it->setMaxDepth(4);
foreach ($it as $f) if ($f->isFile() && $f->getFilename() === 'error_log') $logFiles[] = $f->getPathname();
$ini = (string)ini_get('error_log');   // config.php points this at ~/logs/php_errors.log
foreach ([$ini . '.1', $ini] as $lf) {
    if ($ini !== '' && is_file($lf) && !in_array(realpath($lf), array_map('realpath', $logFiles), true)) $logFiles[] = $lf;
}

$cut = time() - $days * 86400;
$groups = []; $total = 0;
foreach ($logFiles as $lf) {
    $size = (int)@filesize($lf);
    $fh = @fopen($lf, 'r');
    if (!$fh) continue;
    if ($size > 2000000) fseek($fh, $size - 2000000);   // last ~2 MB is plenty
    while (($line = fgets($fh)) !== false) {
        if (!preg_match('/^\[([^\]]+)\]\s+(.*)$/', rtrim($line), $m)) continue;
        $ts = strtotime(preg_replace('/\s+[A-Za-z_]+\/[A-Za-z_\/]+$/', '', $m[1])) ?: 0;
        if ($ts < $cut) continue;
        $msg = str_replace($root . '/', '', $m[2]);
        $msg = preg_replace('/^PHP\s+/', '', $msg);
        // group on the message with ids/numbers/quoted values blanked out
        $key = preg_replace(['/\b\d+\b/', "/'[^']{0,80}'/", '/"[^"]{0,80}"/'], ['#', "'…'", '"…"'], $msg);
        $key = preg_replace('/ on line #$/', '', $key);
        $g = &$groups[md5($key)];
        if (!$g) $g = ['msg' => $msg, 'count' => 0, 'first' => $ts, 'last' => $ts, 'file' => str_replace([$root . '/', dirname($root, 2) . '/'], ['', '~/'], $lf)];
        $g['count']++; $g['last'] = max($g['last'], $ts); $g['first'] = min($g['first'], $ts); $g['msg'] = $msg;
        unset($g);
        $total++;
    }
    fclose($fh);
}
uasort($groups, fn($a, $b) => $b['count'] <=> $a['count'] ?: $b['last'] <=> $a['last']);
$groups = array_slice($groups, 0, 40);

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$badge = ['ok' => ['OK', '#2f5a22', '#e3f0dc'], 'late' => ['Late', '#7a5200', '#fbefd2'], 'failing' => ['Failing', '#8b2018', '#fbe3dc'], 'never' => ['No runs yet', '#555', '#eee']];
$local = function (?string $utc): string {
    if (!$utc) return '—';
    $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone('America/New_York'));
    return $d->format('M j, g:i A');
};
?><!doctype html>
<html lang="en"><head><meta charset="utf-8"><meta name="viewport" content="width=device-width,initial-scale=1">
<title>System Health · BCRF Admin</title>
<style>
body{margin:0;font-family:system-ui,-apple-system,Segoe UI,sans-serif;background:#f5f1ea;color:#2b2118}
.wrap{max-width:900px;margin:0 auto;padding:18px 14px 60px}a{color:#4a6741}
h1{font-size:1.35rem;margin:10px 0 4px}h2{font-size:1.05rem;margin:26px 0 8px}.sub{color:#6b5a48;font-size:.9rem;margin:0 0 12px}
.card{background:#fff;border:1px solid #e3d8c6;border-radius:12px;padding:12px 14px;margin:8px 0}
.job{display:flex;justify-content:space-between;gap:10px;flex-wrap:wrap}.name{font-weight:700}.meta{font-size:.82rem;color:#6b5a48;line-height:1.55}
.pill{font-size:.72rem;font-weight:700;padding:2px 9px;border-radius:20px;white-space:nowrap;align-self:flex-start}
.err{font-family:ui-monospace,Menlo,monospace;font-size:.78rem;word-break:break-word;line-height:1.45}
.count{font-weight:800;font-size:.95rem;min-width:44px;display:inline-block}code{background:#f1ebe0;padding:1px 5px;border-radius:4px;font-size:.85em}
.tabs a{margin-right:10px;font-size:.85rem}
</style></head><body><div class="wrap">
<p><a href="/admin/admin.php">← Admin</a></p>
<h1>System Health</h1>
<p class="sub">Scheduled jobs, PHP errors and uptime checks in one place.</p>

<h2>Scheduled jobs</h2>
<?php foreach ($jobs as $j): [$bl, $bc, $bg] = $badge[$j['state']]; ?>
  <div class="card job">
    <div>
      <div class="name"><?= $h($j['label']) ?> <code><?= $h($j['job']) ?>.php</code></div>
      <div class="meta">Expected <?= $h($j['schedule']) ?> · last OK <?= $h($local($j['last_ok'])) ?> (<?= $h(bcrf_ago($j['last_ok'])) ?>)
        <?php if ($j['last_fail']): ?> · last failure <?= $h($local($j['last_fail'])) ?><?php endif; ?>
        <?php if ($j['state'] === 'failing' && $j['message']): ?><br><span style="color:#8b2018"><?= $h($j['message']) ?></span><?php endif; ?>
        <?php if ($j['state'] === 'never'): ?><br>Hasn't reported since monitoring started. If it's not in cPanel → Cron Jobs, it isn't running.<?php endif; ?>
      </div>
    </div>
    <span class="pill" style="color:<?= $bc ?>;background:<?= $bg ?>"><?= $bl ?></span>
  </div>
<?php endforeach; ?>

<h2>PHP errors (last <?= $days ?> day<?= $days === 1 ? '' : 's' ?>)</h2>
<p class="sub tabs">Show: <?php foreach ([1, 7, 30] as $d): ?><a href="?days=<?= $d ?>"<?= $d === $days ? ' style="font-weight:800"' : '' ?>><?= $d ?> day<?= $d === 1 ? '' : 's' ?></a><?php endforeach; ?>
 · <?= $total ?> line<?= $total === 1 ? '' : 's' ?> from <?= count($logFiles) ?> log file<?= count($logFiles) === 1 ? '' : 's' ?>. Same error repeated = one row with a count.</p>
<?php if (!$groups): ?>
  <div class="card">No PHP errors logged in this period. 🎉</div>
<?php endif; ?>
<?php foreach ($groups as $g): ?>
  <div class="card"><div class="err"><span class="count">×<?= (int)$g['count'] ?></span><?= $h(mb_substr($g['msg'], 0, 600)) ?></div>
    <div class="meta">Last <?= $h(date('M j, g:i A', $g['last'])) ?> · first <?= $h(date('M j, g:i A', $g['first'])) ?> · <code><?= $h($g['file']) ?></code></div></div>
<?php endforeach; ?>

<h2>Uptime checks (UptimeRobot)</h2>
<div class="card meta" style="font-size:.88rem">
  Add these as <strong>Keyword</strong> monitors (keyword <code>BCRF-OK</code>, alert when missing), every 5 minutes, with email alerts:<br>
  1. <code>https://batcaveroadfarm.com/health.php</code>: site, PHP and database<br>
  2. <code>https://batcaveroadfarm.com/health.php?jobs=1</code>: also fails when a job above is Late or Failing<br>
  Plus <strong>HTTP</strong> monitors for <code>https://batcaveroadfarm.com/</code> and <code>https://batcaveroadfarm.com/booking/</code>.
</div>
</div></body></html>
