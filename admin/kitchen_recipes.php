<?php
// admin/kitchen_recipes.php — Kitchen › Recipes › Label check (Farm OS Phase 4 slice 1, Nov 12, 2026; includes/kitchen.php, migration 0050).
//   GET kitchen_recipes.php?q=      every recipe: ready for a label, or how many lines/ingredients still need something
//       kitchen_recipes.php?id=N    one recipe: each line turned into grams (or what's missing, with a link to fix it),
//                                   the ingredient list heaviest first with sub-ingredients, and the "Contains:" line
// Read-only: recipes are still edited in the Full editor tab; ingredient details in Ingredients › Label details.
// A draft only. Labels are saved, approved and printed in later Phase 4 slices.
// Permission: table 'recipes'.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/../includes/kitchen.php';
require_once __DIR__ . '/../includes/admin_shell.php';

$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canIng = hasTableAccess($role, 'ingredients', 'view');
$canEditRecipe = hasTableAccess($role, 'recipes', 'edit');

if (!bcrf_kitchen_ready($pdo)) {
    bcrf_shell_open('Recipe label check');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Recipe label check</h1>', bcrf_shell_notice('warning', 'Run migration 0050 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

$flash = $_SESSION['kr_flash'] ?? null; unset($_SESSION['kr_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chipBad = 'inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-900 mr-1 mb-1';
$chipOk  = 'inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 mr-1 mb-1';
$ingLink = fn(int $iid, int $rid, string $text) => $canIng
    ? '<a class="underline text-amber-800" href="kitchen_ingredients.php?id=' . $iid . '&amp;back=recipe:' . $rid . '">' . $text . '</a>' : $text;

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $res = bcrf_kitchen_resolve($pdo, $id);
    if (!$res) { $_SESSION['kr_flash'] = ['err', 'That recipe no longer exists.']; header('Location: kitchen_recipes.php'); exit; }
    // Ingredient id by its label name, to link unchecked names back to the ingredient
    $byName = [];
    foreach ($pdo->query("SELECT id, singular_name, label_name FROM ingredients")->fetchAll(PDO::FETCH_ASSOC) as $r) $byName[bcrf_kitchen_label_name($r)] = (int)$r['id'];
    bcrf_shell_open($res['recipe']['title'] . ' — label check', ['toast' => $toast, 'width' => 'max-w-4xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_recipes.php">← All recipes</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($res['recipe']['title']), '</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if ($res['ready']) {
        echo bcrf_shell_notice('ok', 'Every line has a weight and every allergen is checked. This recipe can make a label.');
    } elseif (!$res['lines']) {
        echo bcrf_shell_notice('warning', 'This recipe has no ingredient lines yet.');
    } else {
        $bits = [];
        if ($res['problems']) $bits[] = $res['problems'] . ' line' . ($res['problems'] === 1 ? '' : 's') . ' without a weight';
        if ($res['unchecked']) $bits[] = count($res['unchecked']) . ' ingredient' . (count($res['unchecked']) === 1 ? '' : 's') . ' with allergens not checked';
        if ($res['needs_name']) $bits[] = 'a nut, fish or shellfish not named';
        echo bcrf_shell_notice('warning', 'Not ready for a label: ' . implode(', ', $bits) . '. Fix them below.');
    }
    ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Label draft</h2>
    <p class="text-xs text-stone-500 mb-3">Built from the recipe as it is now. Heaviest ingredient first; bought products show their own ingredients in parentheses.<?= $res['problems'] ? ' The order isn\'t final until every line has a weight.' : '' ?></p>
    <p class="text-sm mb-2"><strong>Ingredients:</strong> <?= $res['statement'] !== '' ? $h($res['statement']) : '<span class="text-stone-500">none yet</span>' ?>.</p>
    <p class="text-sm"><strong>Contains:</strong> <?= $res['contains'] !== '' ? $h($res['contains']) . '.' : ($res['unchecked'] ? '<span class="text-amber-800">can\'t say until every allergen is checked</span>' : 'none of the nine major allergens') ?></p>
    <?php if ($res['unchecked']): ?>
    <p class="text-xs mt-3">Allergens not checked:
      <?php foreach ($res['unchecked'] as $nm): ?><span class="<?= $chipBad ?>"><?= isset($byName[$nm]) ? $ingLink($byName[$nm], $id, $h($nm)) : $h($nm) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
    <?php if ($res['needs_name']): ?>
    <p class="text-xs mt-2">Say which nut, fish or shellfish:
      <?php foreach ($res['needs_name'] as $nm): ?><span class="<?= $chipBad ?>"><?= isset($byName[$nm]) ? $ingLink($byName[$nm], $id, $h($nm)) : $h($nm) ?></span><?php endforeach; ?></p>
    <?php endif; ?>
  </div>

  <div class="<?= $card ?> overflow-x-auto">
    <h2 class="text-base font-semibold text-stone-800 mb-2">By weight</h2>
    <table class="w-full text-sm">
      <thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200"><th class="py-2 pr-2">On the label</th><th class="py-2 px-2 text-right">Weight</th><th class="py-2 pl-2 text-right">Share</th></tr></thead>
      <tbody>
      <?php foreach ($res['items'] as $it): ?>
        <tr class="border-b border-stone-100"><td class="py-2 pr-2"><?= $it['type'] === 'ingredient' ? $ingLink((int)$it['id'], $id, $h($it['name'])) : '<a class="underline text-amber-800" href="kitchen_recipes.php?id=' . (int)$it['id'] . '">' . $h($it['name']) . '</a> <span class="text-xs text-stone-500">(recipe)</span>' ?>
          <?= !$it['known'] ? '<span class="block text-xs text-amber-800">part of it has no weight yet</span>' : '' ?></td>
          <td class="py-2 px-2 text-right"><?= $h(bcrf_kitchen_grams($it['grams'])) ?></td><td class="py-2 pl-2 text-right"><?= $h(rtrim(rtrim(number_format($it['pct'], 1), '0'), '.')) ?>%</td></tr>
      <?php endforeach; ?>
      </tbody>
      <tfoot><tr><td class="py-2 pr-2 font-semibold">Batch total</td><td class="py-2 px-2 text-right font-semibold"><?= $h(bcrf_kitchen_grams($res['total_g'])) ?></td><td></td></tr></tfoot>
    </table>
  </div>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Recipe lines</h2>
    <ul class="divide-y divide-stone-100">
      <?php $grp = null; foreach ($res['lines'] as $ln): ?>
      <?php if ($ln['group'] !== '' && $ln['group'] !== $grp): $grp = $ln['group']; ?><li class="pt-3 pb-1 text-xs font-semibold uppercase tracking-wide text-stone-500"><?= $h($grp) ?></li><?php endif; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2">
        <span><?= $h(bcrf_kitchen_qty($ln['qty']) . ($ln['unit'] !== '' ? ' ' . $ln['unit'] : '')) ?>
          <?= $ln['type'] === 'recipe' ? '<a class="underline text-amber-800" href="kitchen_recipes.php?id=' . (int)$ln['linked_recipe_id'] . '">' . $h($ln['name']) . '</a>' : $ingLink((int)$ln['ingredient_id'], $id, $h($ln['name'])) ?></span>
        <span><?= $ln['problem'] ? '<span class="' . $chipBad . '">' . $h($ln['problem_text']) . '</span>' : '<strong>' . $h(bcrf_kitchen_grams($ln['grams'])) . '</strong>' ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if ($canEditRecipe): ?><p class="text-xs text-stone-500 mt-3">To change quantities or units, open the recipe in the <a class="underline text-amber-800" href="admin.php?view=schema&amp;table=recipes&amp;edit_id=<?= $id ?>">Full editor</a>.</p><?php endif; ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$list = bcrf_kitchen_recipe_list($pdo, $q);
$ready = count(array_filter($list, fn($r) => $r['ready']));
bcrf_shell_open('Recipe label check', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Recipe label check</h1>';
echo '<p class="text-sm text-stone-500 mb-4">Whether each recipe has what a food label needs: a weight for every line and checked allergens. ', $ready, ' of ', count($list), ' ready.</p>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="rq">Search</label><input id="rq" name="q" value="<?= $h($q) ?>" class="<?= $input ?>" placeholder="Recipe name"></div>
    <button class="<?= $btnSec ?>">Show</button>
  </form>
  <div class="<?= $card ?>">
    <?php if (!$list): ?><p class="text-sm text-stone-500">No recipes match.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($list as $r): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2">
        <a class="font-semibold underline text-amber-800" href="kitchen_recipes.php?id=<?= (int)$r['id'] ?>"><?= $h($r['title']) ?></a>
        <span><?php if ($r['ready']): ?><span class="<?= $chipOk ?>">Ready</span>
          <?php elseif (!$r['lines']): ?><span class="<?= $chipBad ?>">No lines</span>
          <?php else: ?>
            <?php if ($r['problems']): ?><span class="<?= $chipBad ?>"><?= (int)$r['problems'] ?> without weight</span><?php endif; ?>
            <?php if ($r['unchecked']): ?><span class="<?= $chipBad ?>"><?= (int)$r['unchecked'] ?> allergens not checked</span><?php endif; ?>
            <?php if ($r['needs_name']): ?><span class="<?= $chipBad ?>">name the nut/fish</span><?php endif; ?>
          <?php endif; ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
