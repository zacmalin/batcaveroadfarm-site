<?php // admin/views/layout/social_health_banner.php
// Facebook posting health (Nov 2026): token state from the daily check + failed posts in the
// last 14 days. Set $sh_always = true to show even when all is well (Social Queue screen);
// the Dashboard shows it only when something needs attention.
require_once dirname(__DIR__, 3) . '/includes/social_token.php';
$sh_status = bcrf_social_token_status();
[$sh_level, $sh_msg] = bcrf_social_token_summary($sh_status);
$sh_failed = 0; $sh_last_err = '';
try {
    $st = $pdo->query("SELECT COUNT(*) FROM social_queue WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY");
    $sh_failed = (int)$st->fetchColumn();
    if ($sh_failed) {
        $sh_last_err = (string)$pdo->query("SELECT last_error FROM social_queue WHERE status IN ('failed','partial') AND processed_at >= NOW() - INTERVAL 14 DAY ORDER BY processed_at DESC LIMIT 1")->fetchColumn();
    }
} catch (Throwable $e) { /* social_queue may not exist */ }
if ($sh_failed && $sh_level === 'ok') $sh_level = 'warn';
$sh_show = !empty($sh_always) || $sh_level !== 'ok' || $sh_failed > 0;
if ($sh_show):
    $sh_cls = ['ok' => 'bg-emerald-50 border-emerald-200 text-emerald-900', 'warn' => 'bg-amber-50 border-amber-200 text-amber-900',
               'bad' => 'bg-red-50 border-red-300 text-red-900', 'none' => 'bg-stone-50 border-stone-200 text-stone-700'][$sh_level] ?? '';
    $sh_from = ($target_view ?? '') === 'dashboard' ? 'dashboard' : 'social';
?>
<div class="border rounded p-3 mb-4 text-xs <?= $sh_cls ?>">
  <div class="flex flex-wrap items-center gap-x-3 gap-y-1">
    <span class="font-black"><?= $sh_level === 'bad' ? '⛔' : ($sh_level === 'ok' ? '✅' : '⚠️') ?> Facebook &amp; Instagram</span>
    <span><?= htmlspecialchars($sh_msg) ?></span>
    <a class="underline font-bold" href="/admin/social_token_check.php?from=<?= $sh_from ?>">Check now</a>
    <?php if ($sh_from === 'dashboard'): ?><a class="underline" href="/admin/admin.php?view=social">Social Queue</a><?php endif; ?>
  </div>
  <?php if ($sh_failed): ?>
    <div class="mt-1"><?= $sh_failed ?> post<?= $sh_failed === 1 ? '' : 's' ?> failed in the last 14 days<?= $sh_last_err !== '' ? ' — latest: ' . htmlspecialchars(mb_substr($sh_last_err, 0, 160)) : '' ?></div>
  <?php endif; ?>
  <?php if ($sh_level === 'bad'): ?>
    <div class="mt-1">Fix: make a new Page token (steps in <code>docs/FACEBOOK_TOKEN.md</code>), paste it into <code>bcrf-secrets.php</code> as <code>FB_PAGE_TOKEN</code>, then tap Check now.</div>
  <?php endif; ?>
</div>
<?php endif; ?>
