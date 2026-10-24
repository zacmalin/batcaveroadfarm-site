<?php
// admin/views/jobs/records.php
// Hiring > Records (Jobs pack P8): setup checks + clearing old applicant records.
// Administrators only (admin_only in the view registry).
require_once __DIR__ . '/../../../includes/jobs/retention.php';

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$d    = fn($v) => $v ? date('M j, Y', strtotime($v)) : '';

$months = jobs_retention_months($_GET['months'] ?? 12);
$pool   = !empty($_GET['pool']);
try {
    $sum    = jobs_retention_summary($pdo, $months, $pool);
    $checks = jobs_health_checks($pdo);
} catch (Throwable $e) {
    error_log('[jobs records view] ' . $e->getMessage());
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Run /admin/jobs_install.php first.</div>";
    return;
}
$SL  = jobs_status_labels();
$MO  = jobs_retention_months_options();
$hid = '<input type="hidden" name="csrf_token" value="' . $csrf . '"><input type="hidden" name="months" value="' . $months . '">' . ($pool ? '<input type="hidden" name="pool" value="1">' : '');
$sure = '<label class="flex items-start gap-2 text-sm text-stone-700 mb-3"><input type="checkbox" name="sure" value="1" required class="mt-1"> I understand this can\'t be undone</label>';
$btn  = 'px-4 py-2 rounded text-sm font-semibold text-white';
?>
<div class="max-w-3xl pb-16">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Setup checks, and clearing applicant records once you no longer have to keep them.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <!-- ── Checks ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-5 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-2">Setup checks</h2>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($checks as [$label, $ok, $detail]):
        [$mark, $cls] = $ok === true ? ['OK', 'bg-green-100 text-green-800'] : ($ok === false ? ['Fix', 'bg-red-100 text-red-800'] : ['Note', 'bg-amber-100 text-amber-800']); ?>
      <li class="py-2 flex items-start gap-3">
        <span class="shrink-0 text-xs font-semibold px-2 py-0.5 rounded-full <?= $cls ?>"><?= $mark ?></span>
        <div class="min-w-0"><div class="text-sm font-semibold text-stone-800"><?= $h($label) ?></div><div class="text-xs text-stone-500 break-words"><?= $h($detail) ?></div></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </section>

  <!-- ── Keep period ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-5 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-1">How long to keep applications</h2>
    <p class="text-xs text-stone-500 mb-3">Federal rules say at least 1 year after the decision, and for people you hired, 1 year after their job ends. Keep longer if you're dealing with a complaint or claim.</p>
    <form method="GET" action="/admin/admin.php" class="space-y-3">
      <input type="hidden" name="view" value="jobs_records">
      <div class="flex gap-2">
        <select name="months" class="flex-1 min-w-0 p-2 border border-stone-300 rounded bg-stone-50 text-sm">
          <?php foreach ($MO as $m => $l): ?><option value="<?= $m ?>" <?= $m === $months ? 'selected' : '' ?>>Keep <?= $l ?></option><?php endforeach; ?>
        </select>
        <button class="bg-stone-800 text-white text-sm font-semibold px-4 rounded">Show</button>
      </div>
      <label class="flex items-start gap-2 text-sm text-stone-700"><input type="checkbox" name="pool" value="1" class="mt-1" <?= $pool ? 'checked' : '' ?>> Include Talent Pool (<?= $sum['pool_total'] ?>). Leave this off to keep people you want to contact again.</label>
    </form>
  </section>

  <!-- ── Clear ── -->
  <section id="clear" class="bg-white border <?= $sum['due'] ? 'border-amber-300' : 'border-stone-200' ?> rounded-lg p-4 mb-5 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-1">Clear old applications</h2>
    <?php if (!$sum['due']): ?>
      <p class="text-sm text-stone-500">Nothing is past the <?= $h(strtolower($MO[$months])) ?> mark yet.<?= $sum['cleared'] ? ' ' . $sum['cleared'] . ' cleared so far.' : '' ?></p>
    <?php else: ?>
      <p class="text-sm text-stone-700 mb-2"><strong><?= $sum['due'] ?></strong> application<?= $sum['due'] === 1 ? ' is' : 's are' ?> past <?= $h(strtolower($MO[$months])) ?> (oldest from <?= $h($d($sum['oldest'])) ?>), with <?= $sum['files'] ?> résumé<?= $sum['files'] === 1 ? '' : 's' ?>.</p>
      <div class="flex flex-wrap gap-1 mb-3">
        <?php foreach ($sum['by_status'] as $s => $n): ?><span class="text-xs px-2 py-0.5 rounded-full bg-stone-100 text-stone-700"><?= $h($SL[$s] ?? $s) ?>: <?= $n ?></span><?php endforeach; ?>
      </div>
      <div class="text-xs text-stone-600 bg-stone-50 border border-stone-200 rounded p-3 mb-3 space-y-1">
        <p><strong>Removed:</strong> résumé files, name, email, phone, guardian details, cover note, answers, private notes, held emails, and the address on sent emails.</p>
        <p><strong>Kept:</strong> job, round, status, age group and dates, so your counts per round still add up. Staff accounts and job records of people you hired aren't touched.</p>
      </div>
      <form method="POST" action="/admin/admin.php?view=jobs_records" onsubmit="return confirm('Clear personal details on up to <?= JOBS_PURGE_BATCH ?> applications now? This can\'t be undone.');">
        <?= $hid ?><input type="hidden" name="jobs_records_action" value="purge">
        <?= $sure ?>
        <button class="<?= $btn ?> bg-red-700 hover:bg-red-800">Clear <?= min($sum['due'], JOBS_PURGE_BATCH) ?> now</button>
        <?php if ($sum['due'] > JOBS_PURGE_BATCH): ?><p class="text-xs text-stone-400 mt-1"><?= JOBS_PURGE_BATCH ?> per tap; tap again for the rest.</p><?php endif; ?>
      </form>
      <?php if ($sum['cleared']): ?><p class="text-xs text-stone-400 mt-2"><?= $sum['cleared'] ?> cleared so far.</p><?php endif; ?>
    <?php endif; ?>
  </section>

  <!-- ── Other clean-up ── -->
  <section class="bg-white border border-stone-200 rounded-lg p-4 mb-5 shadow-sm">
    <h2 class="font-semibold text-stone-800 mb-3">Other clean-up</h2>
    <?php foreach ([
        ['spam',    $sum['spam'],    'Spam older than ' . JOBS_SPAM_DAYS . ' days', 'Deleted completely, including any file.'],
        ['alerts',  $sum['alerts'],  'Job-alert signups stopped ' . JOBS_ALERT_STOP_DAYS . '+ days ago', 'Their email address is deleted.'],
        ['orphans', $sum['orphans'], 'Leftover résumé files', 'Files on the server that belong to no application (for example, a form that didn\'t finish saving).'],
      ] as [$act, $n, $label, $note]): ?>
    <div class="py-3 border-t border-stone-100 first:border-t-0 first:pt-0">
      <div class="flex items-start justify-between gap-3">
        <div class="min-w-0"><div class="text-sm font-semibold text-stone-800"><?= $h($label) ?>: <?= $n ?></div><div class="text-xs text-stone-500"><?= $h($note) ?></div></div>
      </div>
      <?php if ($n): ?>
      <form method="POST" action="/admin/admin.php?view=jobs_records" class="mt-2">
        <?= $hid ?><input type="hidden" name="jobs_records_action" value="<?= $act ?>">
        <?= $sure ?>
        <button class="<?= $btn ?> bg-stone-700 hover:bg-stone-800">Delete <?= $n ?></button>
      </form>
      <?php endif; ?>
    </div>
    <?php endforeach; ?>
  </section>
  <p class="text-xs text-stone-400">Nothing on this page runs by itself. A good habit: check it once a year, after your busy season.</p>
</div>
