<?php // admin/views/layout/cron_health_banner.php
// Dashboard warning when a scheduled job (cron) is late or failing (Nov 2026).
// Shown to administrators only, and only when something needs attention.
// Also counts queued jobs that gave up (includes/queue.php, Farm OS Phase 0).
require_once dirname(__DIR__, 3) . '/includes/cron_health.php';
require_once dirname(__DIR__, 3) . '/includes/queue.php';
$ch_bad = [];
try { $ch_bad = bcrf_cron_problems($pdo); } catch (Throwable $e) { $ch_bad = []; }
$ch_dead = 0;
try { $ch_dead = bcrf_queue_ready($pdo) ? (int)$pdo->query("SELECT COUNT(*) FROM queue_jobs WHERE status = 'dead'")->fetchColumn() : 0; } catch (Throwable $e) {}
if ($ch_bad || $ch_dead):
    $ch_fail = array_filter($ch_bad, fn($j) => $j['state'] === 'failing');
?>
<div class="border rounded p-3 mb-4 text-xs <?= ($ch_fail || $ch_dead) ? 'bg-red-50 border-red-300 text-red-900' : 'bg-amber-50 border-amber-200 text-amber-900' ?>">
  <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
    <span class="font-black"><?= ($ch_fail || $ch_dead) ? '⛔' : '⚠️' ?> Scheduled jobs</span>
    <?php if ($ch_bad): ?><span><?= count($ch_bad) ?> need<?= count($ch_bad) === 1 ? 's' : '' ?> attention</span><?php endif; ?>
    <?php if ($ch_dead): ?><span><?= $ch_dead ?> background job<?= $ch_dead === 1 ? '' : 's' ?> gave up</span><?php endif; ?>
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
