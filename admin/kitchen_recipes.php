<?php
// admin/kitchen_recipes.php — Kitchen › Recipes › Label check (Farm OS Phase 4 slice 1, Nov 12, 2026; includes/kitchen.php, migration 0050).
//   GET kitchen_recipes.php?q=      every recipe: ready for a label, or how many lines/ingredients still need something
//       kitchen_recipes.php?id=N    one recipe: each line turned into grams (or what's missing, with a link to fix it),
//                                   the ingredient list heaviest first with sub-ingredients, and the "Contains:" line;
//                                   its versions (slice 2) and the products it makes
//       kitchen_recipes.php?id=N&version=V   one saved version as it was
//   POST act=save_version           save the recipe as it is now as a new version (slice 2, migration 0051)
// Recipes are still edited in the Full editor tab; ingredient details in Ingredients › Label details; links in Kitchen › Products.
// Labels are saved, approved and printed in later Phase 4 slices.
// Permission: table 'recipes' (edit to save a version).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_versions.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canIng = hasTableAccess($role, 'ingredients', 'view');
$canEditRecipe = hasTableAccess($role, 'recipes', 'edit');

if (!bcrf_kitchen_ready($pdo)) {
    bcrf_shell_open('Recipe label check');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Recipe label check</h1>', bcrf_shell_notice('warning', 'Run migration 0050 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

$versionsReady = bcrf_kitchen_versions_ready($pdo);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $rid = (int)($_POST['id'] ?? 0);
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if (!$canEditRecipe) throw new BcrfKitchenError('You can see recipes but not change them.');
        if (!$versionsReady) throw new BcrfKitchenError('Run migration 0051 in Settings › Database first.');
        if (($_POST['act'] ?? '') !== 'save_version') throw new BcrfKitchenError('Unknown action.');
        $r = bcrf_kitchen_save_version($pdo, $rid, $uid, (string)($_POST['note'] ?? ''));
        $no = (int)$r['version']['version_no'];
        if ($r['created'] && function_exists('erp_audit')) erp_audit($pdo, 'create', 'recipe_versions', (int)$r['version']['id'], 'Recipe version ' . $no . ' of ' . $r['version']['snapshot']['title']);
        $_SESSION['kr_flash'] = ['ok', $r['created'] ? 'Saved as version ' . $no . '.' : 'Nothing changed since version ' . $no . '.'];
    } catch (BcrfKitchenError $e) {
        $_SESSION['kr_flash'] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[kitchen_recipes] save_version ' . $rid . ': ' . $e->getMessage());
        $_SESSION['kr_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: kitchen_recipes.php?id=' . $rid); exit;
}

$flash = $_SESSION['kr_flash'] ?? null; unset($_SESSION['kr_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$when = fn($utc) => date('M j, Y g:i A', strtotime($utc . ' UTC'));
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chipBad = 'inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-900 mr-1 mb-1';
$chipOk  = 'inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 mr-1 mb-1';
$ingLink = fn(int $iid, int $rid, string $text) => $canIng
    ? '<a class="underline text-amber-800" href="kitchen_ingredients.php?id=' . $iid . '&amp;back=recipe:' . $rid . '">' . $text . '</a>' : $text;

$id = (int)($_GET['id'] ?? 0);
$vid = (int)($_GET['version'] ?? 0);

// ── One saved version, as it was ──
if ($id && $vid && $versionsReady) {
    $v = bcrf_kitchen_version($pdo, $vid);
    if (!$v || (int)$v['recipe_id'] !== $id) { $_SESSION['kr_flash'] = ['err', 'That version doesn\'t exist.']; header('Location: kitchen_recipes.php?id=' . $id); exit; }
    $s = $v['snapshot'];
    bcrf_shell_open(($s['title'] ?? 'Recipe') . ' — version ' . (int)$v['version_no'], ['width' => 'max-w-4xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_recipes.php?id=', $id, '">← Back to the recipe</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($s['title'] ?? ''), ' · version ', (int)$v['version_no'], '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">Saved ', $h($when($v['created_at'])), trim((string)$v['by_name']) !== '' ? ' by ' . $h($v['by_name']) : '', $v['note'] ? ' · ' . $h($v['note']) : '', '. Kept as a record; it never changes.</p>';
    ?>
  <div class="<?= $card ?>">
    <p class="text-sm mb-2"><strong>Ingredients:</strong> <?= $h($v['statement']) ?>.</p>
    <p class="text-sm"><strong>Contains:</strong> <?= $v['contains_line'] !== '' ? $h($v['contains_line']) . '.' : 'none of the nine major allergens' ?></p>
    <?php if (bcrf_kitchen_snapshot_sulfites($s)): ?><p class="text-sm font-semibold mt-1"><?= $h(bcrf_label_sulfites_text()) ?></p><?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Lines</h2>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($s['lines'] ?? [] as $ln): ?>
      <li class="py-2 text-sm flex flex-wrap justify-between gap-2"><span><?= $h(bcrf_kitchen_qty((float)$ln['qty']) . ($ln['unit'] !== '' ? ' ' . $ln['unit'] : '') . ' ' . $ln['name']) ?></span><strong><?= $h(bcrf_kitchen_grams($ln['grams'])) ?></strong></li>
      <?php endforeach; ?>
    </ul>
    <p class="text-sm font-semibold mt-2">Batch total <?= $h(bcrf_kitchen_grams((float)$v['total_g'])) ?></p>
  </div>
<?php
    bcrf_shell_close(); exit;
}

if ($id) {
    $vs = $versionsReady ? bcrf_kitchen_version_state($pdo, $id) : null;
    $res = $vs ? $vs['res'] : bcrf_kitchen_resolve($pdo, $id);
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
    $versions = $versionsReady ? bcrf_kitchen_versions($pdo, $id) : [];
    $makes = $versionsReady ? bcrf_kitchen_products_for_recipe($pdo, $id) : [];
    ?>
  <?php if ($versionsReady): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Version</h2>
    <?php if (!$vs['latest']): ?>
      <p class="text-sm text-stone-600 mb-2">No version saved yet. A version is a record of exactly what the label says; batches and labels point at one.</p>
    <?php elseif ($vs['current']): ?>
      <p class="text-sm mb-2"><span class="<?= $chipOk ?>">Version <?= (int)$vs['latest']['version_no'] ?></span> matches the recipe as it is now. Saved <?= $h($when($vs['latest']['created_at'])) ?><?= trim((string)$vs['latest']['by_name']) !== '' ? ' by ' . $h($vs['latest']['by_name']) : '' ?>.</p>
    <?php else: ?>
      <p class="text-sm mb-1"><span class="<?= $chipBad ?>">Changed since version <?= (int)$vs['latest']['version_no'] ?></span> Labels and batches will need a new version.</p>
      <ul class="list-disc pl-5 text-sm text-stone-700 mb-2"><?php foreach ($vs['changes'] as $c): ?><li><?= $h($c) ?></li><?php endforeach; ?></ul>
    <?php endif; ?>
    <?php if ($canEditRecipe && (!$vs['latest'] || !$vs['current'])): ?>
      <?php if ($res['ready']): ?>
      <form method="POST" class="flex flex-wrap gap-2 items-end"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="save_version"><input type="hidden" name="id" value="<?= $id ?>">
        <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="vn">Note (optional)</label><input id="vn" name="note" maxlength="255" class="<?= $input ?>" placeholder="e.g. less sugar"></div>
        <button class="<?= $btnMain ?>">Save as version <?= $vs['latest'] ? (int)$vs['latest']['version_no'] + 1 : 1 ?></button></form>
      <?php else: ?><p class="text-xs text-stone-500">Fix what's listed below, then save a version.</p><?php endif; ?>
    <?php endif; ?>
    <?php if ($versions): ?>
    <details class="mt-3"><summary class="cursor-pointer text-sm text-amber-800 underline">All versions (<?= count($versions) ?>)</summary>
      <ul class="divide-y divide-stone-100 mt-2"><?php foreach ($versions as $v): ?>
        <li class="py-2 text-sm"><a class="underline text-amber-800" href="kitchen_recipes.php?id=<?= $id ?>&amp;version=<?= (int)$v['id'] ?>">Version <?= (int)$v['version_no'] ?></a>
          <span class="text-xs text-stone-500"> · <?= $h($when($v['created_at'])) ?><?= trim((string)$v['by_name']) !== '' ? ' · ' . $h($v['by_name']) : '' ?><?= $v['note'] ? ' · ' . $h($v['note']) : '' ?></span></li>
      <?php endforeach; ?></ul></details>
    <?php endif; ?>
  </div>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Makes</h2>
    <?php if (!$makes): ?><p class="text-sm text-stone-500">Not linked to a product yet. Link one in <a class="underline text-amber-800" href="kitchen_products.php">Kitchen › Products</a>.</p>
    <?php else: ?><ul class="divide-y divide-stone-100"><?php foreach ($makes as $p): ?>
      <li class="py-2 text-sm"><a class="underline text-amber-800" href="kitchen_products.php?id=<?= (int)$p['id'] ?>"><?= $h($p['name']) ?></a>
        <span class="text-xs text-stone-500"> · <?= $h(rtrim(rtrim(number_format((float)$p['units_per_batch'], 2), '0'), '.')) ?> per batch<?= $p['net_qty'] !== '' ? ' · ' . $h($p['net_qty']) : '' ?><?= $p['shelf_life_days'] ? ' · best within ' . (int)$p['shelf_life_days'] . ' days' : '' ?></span></li>
    <?php endforeach; ?></ul><?php endif; ?>
  </div>
  <?php else: ?>
  <?= bcrf_shell_notice('info', 'Run migration 0051 in Settings › Database to save versions and link products.') ?>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Label draft</h2>
    <p class="text-xs text-stone-500 mb-3">Built from the recipe as it is now. Heaviest ingredient first; bought products show their own ingredients in parentheses.<?= $res['problems'] ? ' The order isn\'t final until every line has a weight.' : '' ?></p>
    <p class="text-sm mb-2"><strong>Ingredients:</strong> <?= $res['statement'] !== '' ? $h($res['statement']) : '<span class="text-stone-500">none yet</span>' ?>.</p>
    <p class="text-sm"><strong>Contains:</strong> <?= $res['contains'] !== '' ? $h($res['contains']) . '.' : ($res['unchecked'] ? '<span class="text-amber-800">can\'t say until every allergen is checked</span>' : 'none of the nine major allergens') ?></p>
    <?php if ($res['sulfites']): ?><p class="text-sm font-semibold mt-1"><?= $h(bcrf_label_sulfites_text()) ?> <span class="font-normal text-xs text-stone-500">(printed next to the Contains line; not one of the nine)</span></p><?php endif; ?>
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
