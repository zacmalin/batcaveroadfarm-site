<?php // admin/views/layout/cron_health_banner.php
// Dashboard warning when a scheduled job (cron) is late or failing (Nov 2026).
// Shown to administrators only, and only when something needs attention.
require_once dirname(__DIR__, 3) . '/includes/cron_health.php';
$ch_bad = [];
try { $ch_bad = bcrf_cron_problems($pdo); } catch (Throwable $e) { $ch_bad = []; }
if ($ch_bad):
    $ch_fail = array_filter($ch_bad, fn($j) => $j['state'] === 'failing');
?>
<div class="border rounded p-3 mb-4 text-xs <?= $ch_fail ? 'bg-red-50 border-red-300 text-red-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
  <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
    <span class="font-black"><?= $ch_fail ? '⛔' : '⚠️' ?> Scheduled jobs</span>
    <span><?= count($ch_bad) ?> need<?= count($ch_bad) === 1 ? 's' : '' ?> attention</span>
    <a class="underline font-bold" href="/admin/system_health.php">System Health</a>
  </div>
  <ul class="mt-1 list-disc pl-5">
    <?php foreach ($ch_bad as $j): ?>
      <li><strong><?= htmlspecialchars($j['label']) ?></strong> —
        <?php if ($j['state'] === 'failing'): ?>failed <?= htmlspecialchars(bcrf_ago($j['last_fail'])) ?><?= $j['message'] ? ': ' . htmlspecialchars(mb_substr($j['message'], 0, 140)) : '' ?>
        <?php else: ?>last ran OK <?= htmlspecialchars(bcrf_ago($j['last_ok'])) ?> (expected <?= htmlspecialchars($j['schedule']) ?>)<?php endif; ?>
      </li>
    <?php endforeach; ?>
  </ul>
</div>
<?php endif; ?>
