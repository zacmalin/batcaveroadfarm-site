<?php
// admin/kitchen_bible.php — Kitchen › Recipe Bible (Farm OS Phase 4, Nov 13, 2026; includes/kitchen_bible.php, migration 0052).
//   GET kitchen_bible.php?q=          the house recipes (recipes.in_house = 1), by title. View only.
//       kitchen_bible.php?id=N&x=2    one recipe for cooking: ingredients in the order you need them (as entered, with
//                                     group headings), scaled by the batch size, then the method. Prints cleanly.
// A recipe goes in the Bible with the "Keep in the house Recipe Bible" box in the recipe editor (Full editor). That is
// separate from Publish, which puts a recipe on the website. Labels list ingredients heaviest first; this page doesn't.
// Permission: table 'recipes' (view).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/../includes/kitchen_bible.php';
require_once __DIR__ . '/../includes/admin_shell.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canEditRecipe = hasTableAccess($role, 'recipes', 'edit');
$card  = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$input = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$btnSec = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';

if (!bcrf_bible_ready($pdo)) {
    bcrf_shell_open('Recipe Bible');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Recipe Bible</h1>', bcrf_shell_notice('warning', 'Run migration 0052 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

$id = (int)($_GET['id'] ?? 0);

// ── One recipe, for cooking ──
if ($id) {
    $mult = bcrf_bible_multiplier($_GET['x'] ?? '1');
    $r = bcrf_bible_recipe($pdo, $id, $mult);
    if (!$r) {
        bcrf_shell_open('Recipe Bible');
        echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_bible.php">← Recipe Bible</a></p>';
        echo bcrf_shell_notice('warning', 'That recipe isn\'t in the Recipe Bible. Tick "Keep in the house Recipe Bible" in the recipe editor to add it.');
        bcrf_shell_close(); exit;
    }
    $mins = fn($m) => (int)$m > 0 ? ((int)$m >= 60 ? intdiv((int)$m, 60) . ' hr' . ((int)$m % 60 ? ' ' . ((int)$m % 60) . ' min' : '') : (int)$m . ' min') : '';
    bcrf_shell_open($r['title'] . ' — Recipe Bible', ['width' => 'max-w-3xl']);
    ?>
  <p class="text-sm mb-2 print:hidden"><a class="underline text-amber-800" href="kitchen_bible.php">← Recipe Bible</a></p>
  <h1 class="text-2xl font-bold text-stone-800 mb-1"><?= $h($r['title']) ?></h1>
  <p class="text-sm text-stone-500 mb-3">
    <?php
    $meta = [];
    if (trim((string)$r['recipe_yield']) !== '') $meta[] = 'Makes ' . $r['recipe_yield'] . ($mult != 1.0 ? ' (at 1×)' : '');
    if ($mins($r['prep_time']) !== '') $meta[] = 'Prep ' . $mins($r['prep_time']);
    if ($mins($r['cook_time']) !== '') $meta[] = 'Cook ' . $mins($r['cook_time']);
    $meta[] = $r['status'] === 'published' ? 'Also on the website' : 'House only';
    echo $h(implode(' · ', $meta));
    ?>
  </p>
  <div class="flex flex-wrap gap-2 items-center mb-4 print:hidden">
    <span class="text-xs font-semibold text-stone-600">Batch size</span>
    <?php foreach (bcrf_bible_multipliers() as $k => $lbl): $on = (float)$k === $mult; ?>
      <a href="kitchen_bible.php?id=<?= $r['id'] ?>&amp;x=<?= $h($k) ?>" class="px-3 py-1.5 rounded text-sm border <?= $on ? 'bg-amber-700 border-amber-700 text-white font-semibold' : 'border-stone-300 text-stone-700 bg-white hover:bg-stone-100' ?>"<?= $on ? ' aria-current="true"' : '' ?>><?= $h($lbl) ?></a>
    <?php endforeach; ?>
    <button type="button" onclick="window.print()" class="<?= $btnSec ?> ml-auto">🖨️ Print</button>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Ingredients<?= $mult != 1.0 ? ' · ' . $h(bcrf_bible_multipliers()[rtrim(rtrim(number_format($mult, 1, '.', ''), '0'), '.')] ?? '') : '' ?></h2>
    <p class="text-xs text-stone-500 mb-2 print:hidden">In the order you need them.</p>
    <?php if (!$r['lines']): ?>
      <p class="text-sm text-stone-500">No ingredient lines yet.</p>
    <?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php $last = null; foreach ($r['lines'] as $ln):
        if ($ln['group'] !== '' && $ln['group'] !== $last): $last = $ln['group']; ?>
        <li class="pt-3 pb-1 text-xs font-bold uppercase tracking-wide text-amber-900"><?= $h($ln['group']) ?></li>
      <?php endif; ?>
        <li class="py-2 text-sm flex gap-3">
          <span class="w-24 shrink-0 font-semibold text-stone-800"><?= $h(trim($ln['qty_text'] . ' ' . $ln['unit'])) ?></span>
          <span class="flex-1">
            <?php if ($ln['linked_recipe_id'] && $ln['linked_in_bible']): ?>
              <a class="underline text-amber-800" href="kitchen_bible.php?id=<?= (int)$ln['linked_recipe_id'] ?>&amp;x=<?= $h((string)$mult) ?>">📖 <?= $h($ln['name']) ?></a><?php else: ?><?= $ln['linked_recipe_id'] ? '📖 ' : '' ?><?= $h($ln['name']) ?><?php endif; ?><?php if ($ln['notes'] !== ''): ?><span class="text-stone-500 italic">, <?= $h($ln['notes']) ?></span><?php endif; ?>
          </span>
        </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Method</h2>
    <?php if (trim(strip_tags($r['method_html'])) === ''): ?>
      <p class="text-sm text-stone-500">No method written yet.</p>
    <?php else: ?>
      <div class="text-sm text-stone-800 leading-relaxed space-y-2 [&_ol]:list-decimal [&_ol]:pl-5 [&_ul]:list-disc [&_ul]:pl-5 [&_li]:mb-1 [&_h2]:font-semibold [&_h3]:font-semibold"><?= $r['method_html'] ?></div>
    <?php endif; ?>
  </div>

  <?php if ($canEditRecipe): ?>
  <p class="text-sm text-stone-500 print:hidden">
    <a class="underline text-amber-800" href="/admin/admin.php?view=schema&amp;table=recipes&amp;edit_id=<?= $r['id'] ?>">Edit this recipe</a>
    · <a class="underline text-amber-800" href="kitchen_recipes.php?id=<?= $r['id'] ?>">Label check</a>
  </p>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── The list ──
$q = trim((string)($_GET['q'] ?? ''));
$list = bcrf_bible_list($pdo, $q);
bcrf_shell_open('Recipe Bible', ['width' => 'max-w-3xl']);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Recipe Bible</h1>
  <p class="text-sm text-stone-600 mb-4">The house recipes, for cooking. Staff only; nothing here is on the website unless it's also published there.
    To add a recipe, open it in the recipe editor and tick <strong>Keep in the house Recipe Bible</strong>.</p>
  <form method="GET" class="flex gap-2 mb-4">
    <input name="q" value="<?= $h($q) ?>" class="<?= $input ?>" placeholder="Search recipes" aria-label="Search recipes">
    <button class="<?= $btnSec ?>">Search</button>
  </form>
  <?php if (!$list): ?>
    <div class="<?= $card ?> text-center">
      <p class="text-sm font-semibold text-stone-700"><?= $q !== '' ? 'No house recipes match.' : 'No house recipes yet' ?></p>
      <?php if ($q === ''): ?><p class="text-xs text-stone-500 mt-1">Tick "Keep in the house Recipe Bible" on a recipe in the recipe editor and it shows up here.</p><?php endif; ?>
    </div>
  <?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-lg divide-y divide-stone-100 shadow-sm">
    <?php foreach ($list as $r): ?>
    <li>
      <a class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-stone-50" href="kitchen_bible.php?id=<?= $r['id'] ?>">
        <span class="font-semibold text-stone-800">📖 <?= $h($r['title']) ?></span>
        <span class="text-xs text-stone-500"><?= $r['lines'] ?> ingredient<?= $r['lines'] === 1 ? '' : 's' ?><?= $r['recipe_yield'] !== '' ? ' · ' . $h($r['recipe_yield']) : '' ?> · <?= $r['status'] === 'published' ? 'also on the website' : 'house only' ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php
bcrf_shell_close();
