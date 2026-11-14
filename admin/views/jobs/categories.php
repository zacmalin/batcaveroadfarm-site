<?php
// admin/views/jobs/categories.php
// Hiring > Categories: Farm, Petting Zoo, Farmstand, Thrift Shop (and any you add).
// "Allows under 18" controls whether jobs in the category may set a minimum age below 18.
// Administrators only.
require_once __DIR__ . '/../../../includes/jobs/repo.php';
require_once __DIR__ . '/../../../includes/admin_switch.php';   // Active switch (Nov 14, 2026): saves on tap, not with Save

$h    = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$csrf = $h($_SESSION['csrf_token'] ?? '');
$input = 'p-2 border border-stone-300 rounded bg-stone-50 text-sm';

try {
    $cats = $pdo->query("SELECT c.*, (SELECT COUNT(*) FROM jobs j WHERE j.category_id = c.id) AS job_count
                           FROM job_categories c ORDER BY c.sort_order, c.name")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) {
    echo "<div class='p-4 bg-amber-50 border border-amber-200 rounded text-sm text-amber-800'>The Jobs tables aren't set up yet. Restore admin/jobs_install.php from the GitHub history and run it (it was removed after setup).</div>";
    return;
}

$row = function (?array $c) use ($h, $csrf, $input) {
    $id = (int)($c['id'] ?? 0);
    ob_start(); ?>
  <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm<?= $c && empty($c['is_active']) ? ' is-off' : '' ?>"<?= $c ? ' data-switch-row' : '' ?>>
  <form method="POST" action="/admin/admin.php?view=jobs_categories" class="ui-swmain">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_setup_action" value="cat_save">
    <input type="hidden" name="id" value="<?= $id ?>">
    <div class="flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-[10rem]">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Name</label>
        <input type="text" name="name" required maxlength="80" value="<?= $h($c['name'] ?? '') ?>" class="<?= $input ?> w-full" placeholder="New category">
      </div>
      <div class="w-20">
        <label class="block text-xs font-semibold text-stone-600 mb-1">Order</label>
        <input type="number" name="sort_order" value="<?= (int)($c['sort_order'] ?? 50) ?>" class="<?= $input ?> w-full">
      </div>
    </div>
    <div class="flex flex-wrap gap-4 mt-2 text-sm text-stone-600">
      <label class="flex items-center gap-1"><input type="checkbox" name="allows_minors" value="1" <?= !empty($c['allows_minors']) ? 'checked' : '' ?>> Allows under 18</label>
      <?php if (!$c): ?><label class="flex items-center gap-1"><input type="checkbox" name="is_active" value="1" checked> Active</label><?php endif; ?>
      <?php if ($c): ?><span class="text-xs text-stone-400 self-center"><?= (int)$c['job_count'] ?> job<?= (int)$c['job_count'] === 1 ? '' : 's' ?></span><?php endif; ?>
    </div>
    <div class="flex gap-2 mt-3">
      <button class="px-4 py-1.5 rounded text-sm <?= $c ? 'border border-stone-300 text-stone-700 hover:bg-stone-100' : 'bg-amber-700 hover:bg-amber-800 text-white font-semibold' ?>"><?= $c ? 'Save' : 'Add category' ?></button>
    </div>
  </form>
  <?php if ($c): ?>
  <div class="flex items-center gap-2 mt-1 text-sm text-stone-600">Active
    <?= bcrf_switch(['action' => '/admin/admin.php?view=jobs_categories', 'csrf' => $_SESSION['csrf_token'] ?? '', 'fields' => ['jobs_setup_action' => 'cat_active', 'id' => $id],
                     'on' => !empty($c['is_active']), 'label' => $c['name'] . ' active']) ?>
  </div>
  <?php endif; ?>
  </div>
  <?php if ($c && !(int)$c['job_count']): ?>
  <form method="POST" action="/admin/admin.php?view=jobs_categories" class="-mt-1 mb-1 text-right" onsubmit="return confirm('Delete this category?');">
    <input type="hidden" name="csrf_token" value="<?= $csrf ?>">
    <input type="hidden" name="jobs_setup_action" value="cat_delete">
    <input type="hidden" name="id" value="<?= $id ?>">
    <button class="text-xs text-red-600 hover:underline">Delete <?= $h($c['name']) ?></button>
  </form>
  <?php endif;
    return ob_get_clean();
};
?>
<div class="max-w-2xl">
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Hiring</h1>
  <p class="text-sm text-stone-500 mb-4">Categories group jobs on the public page and in job alerts. Farm policy: under-18s work only in non-farm areas (Thrift Shop, Farmstand), so only tick <strong>Allows under 18</strong> for those. Every minor needs an NC youth employment certificate before their first shift.</p>
  <?php require __DIR__ . '/_tabs.php'; ?>

  <div class="space-y-3">
    <?php foreach ($cats as $c) echo $row($c); ?>
    <h2 class="font-semibold text-stone-700 pt-3">Add a category</h2>
    <?= $row(null) ?>
  </div>
</div>
