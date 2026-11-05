<?php // admin/system_health.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
/*
 * System Health (Nov 2026). Administrators only.
 *   1. Scheduled jobs: last good run / failure for every cron (includes/cron_health.php)
 *   2. PHP errors: the site's error_log files, grouped so repeat errors show once with a count
 *   3. Uptime: the two URLs UptimeRobot should watch
 *   4. Farm OS runner + job queue (includes/queue.php, admin/cron/run.php): counts, jobs that gave up
 *      (retry / discard), recent events, and the switch that lets the runner start the scheduled jobs
 */
require_once '../config.php';
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/../includes/cron_health.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');   // farm time zone setting (Phase 0, C-03)

require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/includes/audit.php';

// ── Runner / queue actions (POST, same CSRF token as the rest of the admin) ─────────────────────────
$flash = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals((string)($_SESSION['csrf_token'] ?? ''), (string)($_POST['csrf_token'] ?? ''))) {
        $flash = 'Your session expired. Reload the page and try again.';
    } elseif (bcrf_queue_ready($pdo)) {
        $a  = (string)($_POST['qa'] ?? '');
        $id = (int)($_POST['id'] ?? 0);
        if ($a === 'schedule_on' || $a === 'schedule_off') {
            $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('runner_schedule', ?)
                           ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$a === 'schedule_on' ? '1' : '0']);
            if (function_exists('bcrf_audit_event')) bcrf_audit_event($pdo, ['actor_id' => $_SESSION['user_id'] ?? null, 'actor_label' => (string)($_SESSION['username'] ?? ''),
                'action' => 'update', 'object_type' => 'site_settings', 'object_id' => 'runner_schedule', 'object_label' => 'Runner starts scheduled jobs: ' . ($a === 'schedule_on' ? 'on' : 'off')]);
            $flash = $a === 'schedule_on' ? 'The runner now starts the scheduled jobs. Remove their other entries in cPanel → Cron Jobs (keep run.php).' : 'Switched off: cPanel starts the scheduled jobs again. Put their cron entries back if you removed them.';
        } elseif ($a === 'retry' && $id) {
            $pdo->prepare("UPDATE queue_jobs SET status = 'pending', attempts = 0, run_after = UTC_TIMESTAMP(), finished_at = NULL WHERE id = ? AND status = 'dead'")->execute([$id]);
            $flash = 'Job #' . $id . ' will run again within 5 minutes.';
        } elseif ($a === 'discard' && $id) {
            $pdo->prepare("DELETE FROM queue_jobs WHERE id = ? AND status = 'dead'")->execute([$id]);
            $flash = 'Job #' . $id . ' discarded.';
        }
    }
    if ($flash !== '') $_SESSION['sh_flash'] = $flash;
    header('Location: /admin/system_health.php#runner'); exit;
}
$flash = (string)($_SESSION['sh_flash'] ?? ''); unset($_SESSION['sh_flash']);
$csrfTok = (string)($_SESSION['csrf_token'] ?? '');

$q       = bcrf_queue_summary($pdo);
$schedOn = $q['ready'] && bcrf_runner_schedule_on($pdo);
$dead    = $q['ready'] ? $pdo->query("SELECT id, job, attempts, last_error, created_at, finished_at FROM queue_jobs WHERE status = 'dead' ORDER BY id DESC LIMIT 20")->fetchAll(PDO::FETCH_ASSOC) : [];
$recentEv = $q['ready'] ? $pdo->query("SELECT type, object_type, object_id, actor_type, occurred_at, processed_at FROM domain_events ORDER BY id DESC LIMIT 8")->fetchAll(PDO::FETCH_ASSOC) : [];

$jobs = bcrf_cron_status($pdo);
$runnerTasks = bcrf_runner_tasks();

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
    $d = new DateTime($utc, new DateTimeZone('UTC')); $d->setTimezone(new DateTimeZone(date_default_timezone_get()));   // farm time zone (set above)
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

<?php if ($flash !== ''): ?><div class="card" style="border-color:#4a6741;background:#eef5ea"><?= $h($flash) ?></div><?php endif; ?>

<h2 id="runner">Runner &amp; job queue</h2>
<?php if (!$q['ready']): ?>
  <div class="card meta" style="font-size:.88rem">Not set up yet. In <a href="/admin/migrations.php">Settings › Database</a>, run migration <code>0004_events_and_queue</code>, then add the runner in cPanel (below).</div>
<?php else: $rj = $jobs['run'] ?? null; ?>
  <div class="card">
    <div class="job">
      <div class="meta" style="font-size:.88rem">
        <strong>Waiting:</strong> <?= $q['pending'] ?><?= $q['retrying'] ? ' (' . $q['retrying'] . ' retrying)' : '' ?>
        · <strong>Running:</strong> <?= $q['running'] ?> · <strong>Done today:</strong> <?= $q['done_24h'] ?>
        · <strong>Gave up:</strong> <span style="<?= $q['dead'] ? 'color:#8b2018;font-weight:700' : '' ?>"><?= $q['dead'] ?></span><br>
        Events in the last day: <?= $q['events_24h'] ?><?= $q['events_waiting'] ? ' · ' . $q['events_waiting'] . ' not handled yet' : '' ?>
        <?php if ($q['oldest_pending']): ?> · oldest waiting job since <?= $h($local($q['oldest_pending'])) ?><?php endif; ?>
      </div>
    </div>
    <?php if (!$rj || $rj['state'] === 'never'): ?>
      <p class="meta" style="font-size:.88rem;margin:.6em 0 0">The runner hasn't run yet. Add it in <strong>cPanel → Cron Jobs</strong>, every 5 minutes (Common Settings: "Once Per Five Minutes"):<br>
      <code style="word-break:break-all">/usr/local/bin/php <?= $h(dirname(__DIR__)) ?>/admin/cron/run.php &gt;/dev/null 2&gt;&amp;1</code></p>
    <?php endif; ?>
  </div>

  <div class="card">
    <div class="job">
      <div>
        <div class="name">The runner starts the scheduled jobs</div>
        <div class="meta"><?php if ($schedOn): ?>On. cPanel only needs the <code>run.php</code> entry; remove the others so nothing runs twice.
          <?php else: ?>Off. cPanel starts each job below itself, and the runner only works the job queue. Turn this on after the runner has shown OK for a week, then delete the other cPanel entries (keep <code>run.php</code>).<?php endif; ?>
          <?php if (!bcrf_runner_can_spawn()): ?><br><span style="color:#8b2018">This server doesn't allow the runner to start other scripts (proc_open is disabled), so leave this off.</span><?php endif; ?>
        </div>
      </div>
      <form method="post" style="align-self:center">
        <input type="hidden" name="csrf_token" value="<?= $h($csrfTok) ?>">
        <input type="hidden" name="qa" value="<?= $schedOn ? 'schedule_off' : 'schedule_on' ?>">
        <button type="submit" style="padding:8px 14px;border-radius:8px;border:1px solid #4a6741;background:<?= $schedOn ? '#fff' : '#4a6741' ?>;color:<?= $schedOn ? '#4a6741' : '#fff' ?>;font-weight:700"
          <?= (!$schedOn && (!bcrf_runner_can_spawn() || !$rj || $rj['state'] !== 'ok')) ? 'disabled title="Needs the runner showing OK first"' : '' ?>><?= $schedOn ? 'Turn off' : 'Turn on' ?></button>
      </form>
    </div>
  </div>

  <?php foreach ($dead as $d): ?>
    <div class="card">
      <div class="job">
        <div>
          <div class="name">Gave up: <code><?= $h($d['job']) ?></code> #<?= (int)$d['id'] ?></div>
          <div class="meta">Queued <?= $h($local($d['created_at'])) ?> · tried <?= (int)$d['attempts'] ?> times · last try <?= $h($local($d['finished_at'])) ?><br>
            <span style="color:#8b2018"><?= $h(mb_substr((string)$d['last_error'], 0, 300)) ?></span></div>
        </div>
        <div style="display:flex;gap:6px;align-self:center">
          <?php foreach (['retry' => 'Retry', 'discard' => 'Discard'] as $qa => $lbl): ?>
            <form method="post"><input type="hidden" name="csrf_token" value="<?= $h($csrfTok) ?>"><input type="hidden" name="qa" value="<?= $qa ?>"><input type="hidden" name="id" value="<?= (int)$d['id'] ?>">
              <button type="submit" style="padding:6px 10px;border-radius:8px;border:1px solid #c9b89c;background:#fff;font-weight:700"><?= $lbl ?></button></form>
          <?php endforeach; ?>
        </div>
      </div>
    </div>
  <?php endforeach; ?>

  <?php if ($recentEv): ?>
    <div class="card meta"><strong>Recent events</strong><br>
      <?php foreach ($recentEv as $ev): ?>
        <?= $h($local($ev['occurred_at'])) ?> · <code><?= $h($ev['type']) ?></code> <?= $h(trim($ev['object_type'] . ' ' . $ev['object_id'])) ?> · <?= $h($ev['actor_type']) ?><?= $ev['processed_at'] ? '' : ' · <em>waiting</em>' ?><br>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
<?php endif; ?>

<h2>Scheduled jobs</h2>
<?php foreach ($jobs as $j): [$bl, $bc, $bg] = $badge[$j['state']]; ?>
  <div class="card job">
    <div>
      <div class="name"><?= $h($j['label']) ?> <code><?= $h($j['job']) ?>.php</code></div>
      <div class="meta">Expected <?= $h($j['schedule']) ?> · last OK <?= $h($local($j['last_ok'])) ?> (<?= $h(bcrf_ago($j['last_ok'])) ?>)
        <?php if ($j['last_fail']): ?> · last failure <?= $h($local($j['last_fail'])) ?><?php endif; ?>
        <?php if ($j['state'] === 'failing' && $j['message']): ?><br><span style="color:#8b2018"><?= $h($j['message']) ?></span><?php endif; ?>
        <?php if ($j['state'] === 'never'): ?><br>Hasn't reported since monitoring started. If it's not in cPanel → Cron Jobs, it isn't running.<?php endif; ?>
        <?php if ($schedOn && isset($runnerTasks[$j['job']])): $nx = bcrf_runner_next($runnerTasks[$j['job']][1], $j['last_start']); ?><br>Started by the runner · next <?= $h($nx <= time() ? 'on its next turn' : date('M j, g:i A', $nx)) ?><?php endif; ?>
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
  1. <code><?= bcrf_farm_url() ?>/health.php</code>: site, PHP and database<br>
  2. <code><?= bcrf_farm_url() ?>/health.php?jobs=1</code>: also fails when a job above is Late or Failing<br>
  Plus <strong>HTTP</strong> monitors for <code><?= bcrf_farm_url() ?>/</code> and <code><?= bcrf_farm_url() ?>/booking/</code>.
</div>
</div></body></html>
