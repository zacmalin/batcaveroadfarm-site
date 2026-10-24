<?php
// admin/views/jobs/alerts.php
// Hiring > Alerts (Jobs pack P7): people signed up for job alerts on /jobs/alerts/.
// Sending happens from a job's editor (Hiring > Jobs > a job > Let people know).
// Administrators only (admin_only in the view registry).
require_once __DIR__ . '/../../../includes/jobs/alerts.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$d    = fn($v) => $v ? date('M j, Y', strtotime($v)) : '';

try { $counts = jobs_alert_counts($pdo); }
catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}

$show = in_array($_GET['show'] ?? '', ['active', 'stopped', 'all'], true) ? $_GET['show'] : 'active';
$q    = mb_substr(trim((string)($_GET['q'] ?? '')), 0, 100);
$pg   = max(1, (int)($_GET['p'] ?? 1));
$per  = 50;

$where = ['confirmed_at IS NOT NULL'];
$args  = [];
if ($show === 'active')  $where[] = 'unsubscribed_at IS NULL';
if ($show === 'stopped') $where[] = 'unsubscribed_at IS NOT NULL';
if ($q !== '') { $where[] = 'email LIKE ?'; $args[] = '%' . addcslashes($q, '%_\\') . '%'; }
$sqlW  = implode(' AND ', $where);
$st = $pdo->prepare("SELECT COUNT(*) FROM job_alert_subscribers WHERE $sqlW");
$st->execute($args);
$total = (int)$st->fetchColumn();
$pages = max(1, (int)ceil($total / $per));
$pg    = min($pg, $pages);
$st = $pdo->prepare("SELECT * FROM job_alert_subscribers WHERE $sqlW ORDER BY confirmed_at DESC, id DESC LIMIT $per OFFSET " . (($pg - 1) * $per));
$st->execute($args);
$rows = $st->fetchAll(PDO::FETCH_ASSOC);

$ready = jobs_alert_ready();
$base  = 'show=' . $show . ($q !== '' ? '&q=' . rawurlencode($q) : '');
?>
<div class="max-w-4xl pb-16">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">People who signed up on the Jobs page to get an email when you post a job.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <?php if (!$ready): ?>
  <div class="bg-amber-50 border border-amber-200 text-amber-900 rounded-lg p-3 mb-4 text-sm">Job alerts are off because the site's list secret (BCRF_LIST_SECRET) isn't set. The signup page shows "not available" until it is.</div>
  <?php endif; ?>

  <div class="grid grid-cols-2 gap-3 mb-4">
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-2xl font-bold text-green-800"><?= $counts['active'] ?></div><div class="text-xs text-stone-500">Getting alerts</div></div>
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-2xl font-bold text-stone-500"><?= $counts['stopped'] ?></div><div class="text-xs text-stone-500">Stopped</div></div>
  </div>

  <div class="bg-stone-50 border border-stone-200 rounded-lg p-3 mb-4 text-sm text-stone-600">
    To send alerts, open a job under <a class="text-amber-700 underline" href="/admin/admin.php?view=jobs_posts">Jobs</a> while hiring is open and tap <strong>Let people know</strong>. Signup page: <a class="text-amber-700 underline break-all" href="/jobs/alerts/" target="_blank" rel="noopener">batcaveroadfarm.com/jobs/alerts/</a>
  </div>

  <div class="flex flex-wrap items-center gap-1.5 mb-3">
    <?php foreach (['active' => 'Getting alerts', 'stopped' => 'Stopped', 'all' => 'All'] as $k => $l): $on = $show === $k; ?>
      <a href="/admin/admin.php?view=jobs_alerts&show=<?= $k ?>" class="text-sm px-3 py-1.5 rounded-full border whitespace-nowrap <?= $on ? 'bg-stone-800 text-white border-stone-800' : 'bg-white text-stone-700 border-stone-300 hover:bg-stone-50' ?>" <?= $on ? 'aria-current="page"' : '' ?>><?= $l ?></a>
    <?php endforeach; ?>
  </div>
  <form method="GET" action="/admin/admin.php" class="flex gap-2 mb-4">
    <input type="hidden" name="view" value="jobs_alerts"><input type="hidden" name="show" value="<?= $h($show) ?>">
    <input type="search" name="q" value="<?= $h($q) ?>" placeholder="Search email" class="flex-1 min-w-0 border border-stone-300 rounded px-3 py-2 text-sm">
    <button class="bg-stone-800 text-white text-sm font-semibold px-4 rounded">Find</button>
  </form>

  <?php if (!$rows): ?>
    <p class="text-sm text-stone-500 bg-white border border-stone-200 rounded-lg p-4"><?= $q !== '' ? 'Nobody matches that search.' : 'Nobody here yet.' ?></p>
  <?php else: ?>
  <div class="bg-white border border-stone-200 rounded-lg shadow-sm divide-y divide-stone-100">
    <?php foreach ($rows as $r):
        $c = array_map('intval', json_decode((string)$r['category_ids'], true) ?: []);
        $t = json_decode((string)$r['engagement_types'], true) ?: [];
        $stopped = $r['unsubscribed_at'] !== null; ?>
    <div class="p-4 flex items-start justify-between gap-3">
      <div class="min-w-0">
        <div class="font-semibold text-stone-800 break-all"><?= $h($r['email']) ?></div>
        <div class="text-sm text-stone-500"><?= $h(jobs_alert_choice_text($pdo, $c, $t)) ?></div>
        <div class="text-xs text-stone-400">Since <?= $h($d($r['confirmed_at'])) ?><?= $r['last_sent_at'] ? ' · last alert ' . $h($d($r['last_sent_at'])) : ' · no alerts yet' ?><?= $stopped ? ' · stopped ' . $h($d($r['unsubscribed_at'])) : '' ?></div>
      </div>
      <?php if (!$stopped): ?>
      <form method="POST" action="/admin/admin.php?view=jobs_alerts" class="shrink-0" onsubmit="return confirm('Stop job alerts for <?= $h(addslashes($r['email'])) ?>?');">
        <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
        <input type="hidden" name="jobs_alerts_action" value="sub_remove">
        <input type="hidden" name="email" value="<?= $h($r['email']) ?>">
        <input type="hidden" name="back" value="<?= $h($base . '&p=' . $pg) ?>">
        <button class="px-3 py-1.5 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100">Remove</button>
      </form>
      <?php else: ?>
      <span class="shrink-0 text-xs px-2 py-1 rounded-full bg-stone-100 text-stone-500">Stopped</span>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </div>
  <?php if ($pages > 1): ?>
  <div class="flex items-center justify-between mt-3 text-sm">
    <?php if ($pg > 1): ?><a class="px-3 py-1.5 rounded border border-stone-300 bg-white" href="/admin/admin.php?view=jobs_alerts&<?= $h($base) ?>&p=<?= $pg - 1 ?>">&larr; Newer</a><?php else: ?><span></span><?php endif; ?>
    <span class="text-stone-500">Page <?= $pg ?> of <?= $pages ?></span>
    <?php if ($pg < $pages): ?><a class="px-3 py-1.5 rounded border border-stone-300 bg-white" href="/admin/admin.php?view=jobs_alerts&<?= $h($base) ?>&p=<?= $pg + 1 ?>">Older &rarr;</a><?php else: ?><span></span><?php endif; ?>
  </div>
  <?php endif; ?>
  <?php endif; ?>
</div>
