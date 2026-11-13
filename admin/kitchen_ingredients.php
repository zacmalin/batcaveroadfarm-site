<?php
// admin/kitchen_ingredients.php — Kitchen › Ingredients › Label details (Farm OS Phase 4 slice 1, Nov 12, 2026; includes/kitchen.php, migration 0050).
//   GET  kitchen_ingredients.php?show=attention|unchecked|all&q=    list (default: used in a recipe and missing something for labels)
//        kitchen_ingredients.php?id=N                                 one ingredient: allergens, sub-ingredients, label name, grams per mL,
//                                                                     weight of one, and every recipe line that uses it with its weight
//   POST act=save                                                     (back=recipe:N returns to that recipe's Label check)
// Permission: table 'ingredients' (view to look, edit to save). Nutrition, names and USDA stay in the Full editor tab.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'ingredients']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'ingredients', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_kitchen_ready($pdo)) {
    bcrf_shell_open('Ingredient label details');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Ingredient label details</h1>', bcrf_shell_notice('warning', 'Run migration 0050 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $backRecipe = preg_match('/^recipe:(\d+)$/', (string)($_POST['back'] ?? ''), $m) ? (int)$m[1] : 0;
    $back = 'kitchen_ingredients.php?id=' . $id . ($backRecipe ? '&back=recipe:' . $backRecipe : '');
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfKitchenError('You can see ingredients but not change them.');
        if (($_POST['act'] ?? '') !== 'save') throw new BcrfKitchenError('Unknown action.');
        $in = $_POST;
        $in['allergen'] = (array)($_POST['allergen'] ?? []);
        $in['allergen_detail'] = (array)($_POST['allergen_detail'] ?? []);
        $changes = bcrf_kitchen_ingredient_save($pdo, $id, $in, $uid);
        $ing = bcrf_kitchen_ingredient($pdo, $id);
        if ($changes && function_exists('erp_audit')) erp_audit($pdo, 'update', 'ingredients', $id, 'Label details for ' . $ing['singular_name'] . ': ' . implode(', ', $changes));
        $_SESSION['ki_flash'] = ['ok', $changes ? 'Saved ' . $ing['singular_name'] . '.' : 'Saved. Nothing changed.'];
        if ($backRecipe) { $_SESSION['kr_flash'] = $_SESSION['ki_flash']; unset($_SESSION['ki_flash']); $back = 'kitchen_recipes.php?id=' . $backRecipe; }
    } catch (BcrfKitchenError $e) {
        $_SESSION['ki_flash'] = ['err', $e->getMessage()];
        $_SESSION['ki_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_ingredients] save ' . $id . ': ' . $e->getMessage());
        $_SESSION['ki_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['ki_flash'] ?? null; unset($_SESSION['ki_flash']);
$form = $_SESSION['ki_form'] ?? null; unset($_SESSION['ki_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chipBad = 'inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-900 mr-1 mb-1';
$chipOk  = 'inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 mr-1 mb-1';

$id = (int)($_GET['id'] ?? 0);

// ── One ingredient ──
if ($id) {
    $ing = bcrf_kitchen_ingredient($pdo, $id);
    if (!$ing) { $_SESSION['ki_flash'] = ['err', 'That ingredient no longer exists.']; header('Location: kitchen_ingredients.php'); exit; }
    $backRecipe = preg_match('/^recipe:(\d+)$/', (string)($_GET['back'] ?? ''), $m) ? (int)$m[1] : 0;
    $use = bcrf_kitchen_ingredient_use($pdo)[$id] ?? ['recipes' => [], 'needs_density' => false, 'needs_each' => false];
    $v = $form ?: [
        'label_name' => $ing['label_name'] ?? '', 'sub_ingredients' => $ing['sub_ingredients'] ?? '',
        'density_g_per_ml' => $ing['density_g_per_ml'] !== null ? rtrim(rtrim((string)$ing['density_g_per_ml'], '0'), '.') : '',
        'weight_per_unit_g' => $ing['weight_per_unit_g'] !== null ? rtrim(rtrim((string)$ing['weight_per_unit_g'], '0'), '.') : '',
    ];
    $parsed = bcrf_allergens_parse($ing['allergens']);
    $ticked = $form ? array_flip((array)($form['allergen'] ?? [])) : $parsed;
    $details = $form ? (array)($form['allergen_detail'] ?? []) : $parsed;
    $none = $form ? !empty($form['no_allergens']) : ($ing['allergens'] === '');
    $unknown = $form ? !empty($form['allergens_unknown']) : false;
    // Every recipe line using it, with its weight, so the effect of a change shows here.
    $lines = [];
    foreach (array_keys($use['recipes']) as $rid) {
        $res = bcrf_kitchen_resolve($pdo, $rid);
        foreach ($res['lines'] as $ln) if ($ln['ingredient_id'] === $id) $lines[] = ['recipe' => $res['recipe'], 'line' => $ln];
    }
    $title = (string)$ing['singular_name'];
    bcrf_shell_open($title . ' — label details', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="', $backRecipe ? 'kitchen_recipes.php?id=' . $backRecipe : 'kitchen_ingredients.php', '">← ', $backRecipe ? 'Back to the recipe' : 'All ingredients', '</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($title), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">On labels as <strong>', $h(bcrf_kitchen_label_name($ing)), '</strong>. Allergens: ', $h(bcrf_kitchen_allergen_text($ing['allergens'])),
         $ing['allergens_checked_at'] ? ' · checked ' . $h(date('M j, Y', strtotime($ing['allergens_checked_at'] . ' UTC'))) . (trim((string)$ing['checked_by_name']) !== '' ? ' by ' . $h($ing['checked_by_name']) : '') : '', '</p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    ?>
  <form method="POST" class="<?= $card ?>"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $id ?>">
    <?php if ($backRecipe): ?><input type="hidden" name="back" value="recipe:<?= $backRecipe ?>"><?php endif; ?>
    <fieldset class="mb-4"<?= $canEdit ? '' : ' disabled' ?>>
      <legend class="text-base font-semibold text-stone-800 mb-1">Allergens</legend>
      <p class="text-xs text-stone-500 mb-2">Tick each of the nine major allergens this ingredient contains. Check the package label of anything bought. For tree nuts, fish and shellfish, say which one; the label has to name it.</p>
      <div class="grid grid-cols-1 sm:grid-cols-2 gap-2 mb-2">
        <?php foreach (bcrf_allergen_list() as $k => [$name, $named]): ?>
        <div class="border border-stone-200 rounded p-2">
          <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="allergen[]" value="<?= $h($k) ?>"<?= array_key_exists($k, $ticked) ? ' checked' : '' ?>> <?= $h($name) ?></label>
          <?php if ($named): ?><input name="allergen_detail[<?= $h($k) ?>]" maxlength="60" value="<?= $h($details[$k] ?? '') ?>" class="<?= $input ?> mt-1" placeholder="<?= $k === 'tree_nuts' ? 'Which nut, e.g. pecans' : ($k === 'fish' ? 'Which fish, e.g. cod' : 'Which, e.g. shrimp') ?>" aria-label="Which <?= $h(strtolower($name)) ?>"><?php endif; ?>
        </div>
        <?php endforeach; ?>
      </div>
      <label class="flex items-center gap-2 text-sm mb-1"><input type="checkbox" name="no_allergens" value="1"<?= $none ? ' checked' : '' ?>> None of the nine (checked)</label>
      <label class="flex items-center gap-2 text-sm text-stone-600"><input type="checkbox" name="allergens_unknown" value="1"<?= $unknown ? ' checked' : '' ?>> Not checked yet (blocks labels for every recipe using it)</label>
    </fieldset>
    <fieldset class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4"<?= $canEdit ? '' : ' disabled' ?>>
      <legend class="text-base font-semibold text-stone-800 mb-1">On the label</legend>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="kl">Name on labels (optional)</label><input id="kl" name="label_name" maxlength="150" value="<?= $h($v['label_name']) ?>" class="<?= $input ?>" placeholder="<?= $h($ing['singular_name']) ?>">
        <p class="text-xs text-stone-500 mt-1">The common name the label should use, if it differs from the recipe, e.g. "enriched wheat flour" or "unsalted butter".</p></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="ks">Its own ingredients (bought products)</label><textarea id="ks" name="sub_ingredients" rows="3" maxlength="2000" class="<?= $input ?>" placeholder="e.g. sugar, chocolate, cocoa butter, milkfat, soy lecithin, vanilla"><?= $h($v['sub_ingredients']) ?></textarea>
        <p class="text-xs text-stone-500 mt-1">Copy the ingredient list from the package exactly. It prints in parentheses after the name. Leave blank for single foods like eggs or salt.</p></div>
    </fieldset>
    <fieldset class="grid grid-cols-1 sm:grid-cols-2 gap-3 mb-4"<?= $canEdit ? '' : ' disabled' ?>>
      <legend class="text-base font-semibold text-stone-800 mb-1">Weight</legend>
      <div><label class="<?= $label ?>" for="kd">Grams per mL<?= $use['needs_density'] ? ' <span class="text-amber-800">(needed)</span>' : '' ?></label><input id="kd" name="density_g_per_ml" inputmode="decimal" value="<?= $h($v['density_g_per_ml']) ?>" class="<?= $input ?>" placeholder="e.g. 0.53 for flour">
        <p class="text-xs text-stone-500 mt-1">Turns cups and spoons into weight. Weigh 1 cup in grams and divide by 237. Water is 1, flour about 0.53, sugar 0.85, honey 1.42.</p></div>
      <div><label class="<?= $label ?>" for="ke">Weight of one, in grams<?= $use['needs_each'] ? ' <span class="text-amber-800">(needed)</span>' : '' ?></label><input id="ke" name="weight_per_unit_g" inputmode="decimal" value="<?= $h($v['weight_per_unit_g']) ?>" class="<?= $input ?>" placeholder="e.g. 50 for one egg">
        <p class="text-xs text-stone-500 mt-1">For lines counted rather than measured: eggs, cloves, sticks, whole onions.</p></div>
    </fieldset>
    <?php if ($canEdit): ?><button class="<?= $btnMain ?>">Save</button><?php endif; ?>
  </form>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Used in</h2>
    <?php if (!$lines): ?><p class="text-sm text-stone-500">Not in any recipe yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($lines as $l): $ln = $l['line']; ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><a class="underline text-amber-800" href="kitchen_recipes.php?id=<?= (int)$l['recipe']['id'] ?>"><?= $h($l['recipe']['title']) ?></a>
        <span><?= $h(bcrf_kitchen_qty($ln['qty']) . ($ln['unit'] !== '' ? ' ' . $ln['unit'] : '')) ?> →
          <?= $ln['problem'] ? '<span class="' . $chipBad . '">' . $h($ln['problem_text']) . '</span>' : '<strong>' . $h(bcrf_kitchen_grams($ln['grams'])) . '</strong>' ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

// ── List ──
$show = in_array($_GET['show'] ?? '', ['attention', 'unchecked', 'all'], true) ? $_GET['show'] : 'attention';
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 60));
$rows = bcrf_kitchen_ingredient_list($pdo, $q !== '' ? 'all' : $show, $q);
$counts = ['attention' => count(bcrf_kitchen_ingredient_list($pdo, 'attention')), 'unchecked' => count(bcrf_kitchen_ingredient_list($pdo, 'unchecked'))];
bcrf_shell_open('Ingredient label details', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Ingredient label details</h1>';
echo '<p class="text-sm text-stone-500 mb-4">What a food label needs from each ingredient: its allergens, the ingredient list of anything bought, and enough to turn cups and counts into weight so the label can list ingredients heaviest first.</p>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <div><label class="<?= $label ?>" for="kshow">Show</label><select id="kshow" name="show" class="<?= $input ?>">
      <option value="attention"<?= $show === 'attention' ? ' selected' : '' ?>>Needs attention (<?= $counts['attention'] ?>)</option>
      <option value="unchecked"<?= $show === 'unchecked' ? ' selected' : '' ?>>Allergens not checked (<?= $counts['unchecked'] ?>)</option>
      <option value="all"<?= $show === 'all' ? ' selected' : '' ?>>All ingredients</option></select></div>
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="kq">Search</label><input id="kq" name="q" value="<?= $h($q) ?>" class="<?= $input ?>" placeholder="Name"></div>
    <button class="<?= $btnSec ?>">Show</button>
  </form>
  <?php if (!$rows): ?>
  <div class="<?= $card ?>"><p class="text-sm text-stone-500"><?= $show === 'attention' && $q === '' ? 'Nothing needs attention. Every ingredient used in a recipe has what labels need.' : 'No ingredients match.' ?></p></div>
  <?php else: ?>
  <div class="<?= $card ?>">
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): ?>
      <li class="py-2 text-sm">
        <a class="font-semibold underline text-amber-800" href="kitchen_ingredients.php?id=<?= (int)$r['id'] ?>"><?= $h($r['singular_name']) ?></a>
        <?php if (trim((string)$r['label_name']) !== ''): ?><span class="text-stone-500"> · on labels “<?= $h($r['label_name']) ?>”</span><?php endif; ?>
        <span class="block text-xs text-stone-500 mt-1"><?= $h(bcrf_kitchen_allergen_text($r['allergens'] === null ? null : (string)$r['allergens'])) ?><?= trim((string)$r['sub_ingredients']) !== '' ? ' · has its own ingredients' : '' ?> · in <?= count($r['used_in']) ?> recipe<?= count($r['used_in']) === 1 ? '' : 's' ?></span>
        <span class="block mt-1">
          <?php if ($r['needs_allergens']): ?><span class="<?= $chipBad ?>">Allergens not checked</span><?php endif; ?>
          <?php if ($r['needs_name']): ?><span class="<?= $chipBad ?>">Say which nut, fish or shellfish</span><?php endif; ?>
          <?php if ($r['needs_density']): ?><span class="<?= $chipBad ?>">Needs grams per mL</span><?php endif; ?>
          <?php if ($r['needs_each']): ?><span class="<?= $chipBad ?>">Needs weight of one</span><?php endif; ?>
          <?php if (!$r['needs_allergens'] && !$r['needs_name'] && !$r['needs_density'] && !$r['needs_each']): ?><span class="<?= $chipOk ?>">Label ready</span><?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
