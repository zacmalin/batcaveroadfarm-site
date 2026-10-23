<?php
// admin/views/social.php — read-only view of the social share queue.
// Included by admin.php at global scope ($pdo available). No writes here;
// the cron layer (added later) is what sends + updates these rows.

$social_ready = false;
try { $pdo->query("SELECT 1 FROM social_queue LIMIT 1"); $social_ready = true; }
catch (Throwable $e) { $social_ready = false; }

$rows = [];
if ($social_ready) {
    try {
        $rows = $pdo->query("SELECT * FROM social_queue ORDER BY created_at DESC, id DESC LIMIT 100")
                    ->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $rows = []; }
}

$badge = function (string $s): string {
    $map = [
        'pending' => 'bg-amber-100 text-amber-800 border-amber-200',
        'sent'    => 'bg-emerald-100 text-emerald-800 border-emerald-200',
        'failed'  => 'bg-red-100 text-red-800 border-red-200',
        'partial' => 'bg-orange-100 text-orange-800 border-orange-200',
        'skipped' => 'bg-stone-100 text-stone-600 border-stone-200',
    ];
    $cls = $map[$s] ?? 'bg-stone-100 text-stone-600 border-stone-200';
    return '<span class="text-[9px] font-black uppercase tracking-wider border px-1.5 py-0.5 rounded ' . $cls . '">' . htmlspecialchars($s) . '</span>';
};
?>
<div class="max-w-5xl">
  <div class="flex items-center justify-between mb-4">
    <h1 class="text-lg font-serif font-black text-stone-800">📣 Social Share Queue</h1>
    <span class="text-[10px] font-mono text-stone-400 uppercase tracking-widest">Facebook &amp; Instagram</span>
  </div>

  <?php if (!$social_ready): ?>
    <div class="bg-amber-50 border border-amber-200 rounded p-4 text-sm text-amber-800">
      The <code>social_queue</code> table doesn't exist yet. Run <code>sql/social_module.sql</code> to enable social sharing.
    </div>
  <?php elseif (empty($rows)): ?>
    <div class="bg-white border border-stone-200 rounded p-6 text-center text-sm text-stone-500">
      Nothing queued yet. When you publish a recipe or blog post for the first time and leave
      “Share to Facebook &amp; Instagram” checked, the approved post lands here.
    </div>
  <?php else: ?>
    <p class="text-[11px] text-stone-500 mb-3">
      These are the posts captured at publish time. Sending happens automatically once the
      Facebook/Instagram connection is configured — until then they stay <em>pending</em>.
    </p>
    <div class="space-y-2">
      <?php foreach ($rows as $r): ?>
      <div class="bg-white border border-stone-200 rounded p-3 shadow-xs">
        <div class="flex items-center justify-between gap-2 mb-1.5">
          <div class="flex items-center gap-2 text-xs">
            <span class="font-black uppercase tracking-wider text-stone-500 text-[10px]">
              <?= $r['content_type'] === 'recipe' ? '🍴 Recipe' : ($r['content_type'] === 'animal' ? '🐐 Animal' : '📝 Blog') ?> #<?= (int)$r['content_id'] ?>
            </span>
            <span class="text-stone-300">·</span>
            <span class="text-[10px] font-mono text-stone-400"><?= htmlspecialchars($r['platforms']) ?></span>
          </div>
          <div class="flex items-center gap-2">
            <?= $badge($r['status']) ?>
            <span class="text-[10px] font-mono text-stone-400"><?= htmlspecialchars(substr((string)$r['created_at'], 0, 16)) ?></span>
          </div>
        </div>
        <div class="grid md:grid-cols-2 gap-3 mt-2">
          <div>
            <div class="text-[9px] font-black uppercase tracking-wider text-blue-700 mb-1">Facebook</div>
            <div class="text-[11px] text-stone-700 whitespace-pre-line border border-stone-100 rounded bg-stone-50 p-2"><?= htmlspecialchars((string)$r['fb_caption']) ?></div>
          </div>
          <div>
            <div class="text-[9px] font-black uppercase tracking-wider text-pink-700 mb-1">Instagram</div>
            <div class="text-[11px] text-stone-700 whitespace-pre-line border border-stone-100 rounded bg-stone-50 p-2"><?= htmlspecialchars((string)$r['ig_caption']) ?></div>
            <?php if (!empty($r['ig_hashtags'])): ?>
              <div class="text-[10px] text-sky-700 mt-1 break-words"><?= htmlspecialchars((string)$r['ig_hashtags']) ?></div>
            <?php endif; ?>
          </div>
        </div>
        <?php if (!empty($r['last_error'])): ?>
          <div class="text-[10px] text-red-700 mt-2 font-mono">⚠ <?= htmlspecialchars((string)$r['last_error']) ?></div>
        <?php endif; ?>
        <?php if (!empty($r['fb_post_id']) || !empty($r['ig_media_id'])): ?>
          <div class="text-[9px] font-mono text-stone-400 mt-1">
            <?php if (!empty($r['fb_post_id'])): ?>fb:<?= htmlspecialchars((string)$r['fb_post_id']) ?> <?php endif; ?>
            <?php if (!empty($r['ig_media_id'])): ?>ig:<?= htmlspecialchars((string)$r['ig_media_id']) ?><?php endif; ?>
          </div>
        <?php endif; ?>
      </div>
      <?php endforeach; ?>
    </div>
  <?php endif; ?>
</div>
