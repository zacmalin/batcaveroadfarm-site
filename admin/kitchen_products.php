<?php
// admin/kitchen_products.php — Kitchen › Products (Farm OS Phase 4 slice 2, Nov 12, 2026; includes/kitchen_versions.php, migration 0051).
//   GET  kitchen_products.php           kitchen goods (and anything already linked): which recipe makes each, units per batch,
//                                       net quantity, shelf life, NCDA&CS approved product type
//        kitchen_products.php?id=N      link or change one product (N = inventory id); shows whether a batch's weight covers
//                                       the units it should make
//   POST act=save | unlink
// The product itself (name, price, size, type) is edited in Storefront › Products. Permission: table 'recipes' (edit to change).
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
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'recipes', 'edit');
$canProducts = hasTableAccess($role, 'inventory', 'view');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo)) {
    bcrf_shell_open('Kitchen products');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Kitchen products</h1>', bcrf_shell_notice('warning', 'Run migrations 0050 and 0051 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $id = (int)($_POST['id'] ?? 0);
    $back = 'kitchen_products.php?id=' . $id;
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfKitchenError('You can see kitchen products but not change them.');
        $p = bcrf_kitchen_product($pdo, $id);
        if (!$p) throw new BcrfKitchenError('That product no longer exists.');
        $act = (string)($_POST['act'] ?? '');
        if ($act === 'save') {
            $changes = bcrf_kitchen_product_save($pdo, $id, $_POST, $uid);
            if ($changes && function_exists('erp_audit')) erp_audit($pdo, 'update', 'kitchen_products', $id, 'Recipe link for ' . $p['name'] . ': ' . implode(', ', $changes));
            $_SESSION['kp_flash'] = ['ok', $changes ? 'Saved ' . $p['name'] . '.' : 'Saved. Nothing changed.'];
        } elseif ($act === 'unlink') {
            if (bcrf_kitchen_product_unlink($pdo, $id) && function_exists('erp_audit')) erp_audit($pdo, 'delete', 'kitchen_products', $id, 'Recipe link removed from ' . $p['name']);
            $_SESSION['kp_flash'] = ['ok', 'Unlinked ' . $p['name'] . '.'];
            $back = 'kitchen_products.php';
        } else {
            throw new BcrfKitchenError('Unknown action.');
        }
    } catch (BcrfKitchenError $e) {
        $_SESSION['kp_flash'] = ['err', $e->getMessage()];
        $_SESSION['kp_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_products] ' . $id . ': ' . $e->getMessage());
        $_SESSION['kp_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['kp_flash'] ?? null; unset($_SESSION['kp_flash']);
$form = $_SESSION['kp_form'] ?? null; unset($_SESSION['kp_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chipBad = 'inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-900 mr-1 mb-1';
$chipOk  = 'inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 mr-1 mb-1';
$num = fn($v) => rtrim(rtrim(number_format((float)$v, 2, '.', ''), '0'), '.');

$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $p = bcrf_kitchen_product($pdo, $id);
    if (!$p) { $_SESSION['kp_flash'] = ['err', 'That product no longer exists.']; header('Location: kitchen_products.php'); exit; }
    $recipes = $pdo->query("SELECT id, title FROM recipes ORDER BY title")->fetchAll(PDO::FETCH_ASSOC);
    $v = $form ?: ['recipe_id' => $p['recipe_id'], 'units_per_batch' => $p['units_per_batch'] !== null ? $num($p['units_per_batch']) : '',
                   'shelf_life_days' => $p['shelf_life_days'] ?? '', 'approved_type' => $p['approved_type'], 'approved_note' => $p['approved_note'] ?? ''];
    $res = $p['recipe_id'] ? bcrf_kitchen_resolve($pdo, (int)$p['recipe_id']) : null;
    $fit = ($res && $res['problems'] === 0) ? bcrf_kitchen_batch_fit((float)$res['total_g'], $p['units_per_batch'], $p['size_value'], (string)$p['size_unit']) : null;
    bcrf_shell_open($p['name'] . ' — kitchen product', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_products.php">← All kitchen products</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($p['name']), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">', $h($p['sku'] ?? ''), $p['net_qty'] !== '' ? ' · ' . $h($p['net_qty']) : ' · <span class="text-amber-800">no size set</span>',
         $canProducts ? ' · <a class="underline text-amber-800" href="products.php?id=' . (int)$p['id'] . '">Edit the product</a>' : '', '</p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if ($p['net_qty'] === '') echo bcrf_shell_notice('warning', 'Set this product\'s size (for example 8 ounce) in Storefront › Products. The label\'s net quantity comes from it.');
    ?>
  <form method="POST" class="<?= $card ?>"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <fieldset class="grid grid-cols-1 sm:grid-cols-2 gap-3"<?= $canEdit ? '' : ' disabled' ?>>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="pr">Made from recipe</label><select id="pr" name="recipe_id" class="<?= $input ?>" required>
        <option value="">Pick a recipe</option>
        <?php foreach ($recipes as $r): ?><option value="<?= (int)$r['id'] ?>"<?= (int)$v['recipe_id'] === (int)$r['id'] ? ' selected' : '' ?>><?= $h($r['title']) ?></option><?php endforeach; ?></select></div>
      <div><label class="<?= $label ?>" for="pu">Units one batch makes</label><input id="pu" name="units_per_batch" inputmode="decimal" required value="<?= $h($v['units_per_batch']) ?>" class="<?= $input ?>" placeholder="e.g. 12"></div>
      <div><label class="<?= $label ?>" for="ps">Shelf life, days (for the best-by date)</label><input id="ps" name="shelf_life_days" inputmode="numeric" value="<?= $h($v['shelf_life_days']) ?>" class="<?= $input ?>" placeholder="e.g. 365"></div>
      <div class="sm:col-span-2"><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="approved_type" value="1"<?= !empty($v['approved_type']) ? ' checked' : '' ?>> NCDA&amp;CS confirmed this is an allowed home-processor product</label></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="pn">How it was confirmed (optional)</label><input id="pn" name="approved_note" maxlength="255" value="<?= $h($v['approved_note']) ?>" class="<?= $input ?>" placeholder="e.g. Kaye Snipes email, Nov 20"></div>
    </fieldset>
    <?php if ($canEdit): ?><div class="flex flex-wrap gap-2 mt-4"><button class="<?= $btnMain ?>">Save</button></div><?php endif; ?>
  </form>
  <?php if ($canEdit && $p['recipe_id']): ?>
  <form method="POST" class="mb-4"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="unlink"><input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
    <button class="<?= $btnSec ?>" data-confirm="Unlink <?= $h($p['name']) ?> from its recipe?">Unlink from the recipe</button></form>
  <?php endif; ?>

  <?php if ($res): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1"><a class="underline text-amber-800" href="kitchen_recipes.php?id=<?= (int)$p['recipe_id'] ?>"><?= $h($res['recipe']['title']) ?></a></h2>
    <p class="text-sm mb-2"><?= $res['ready'] ? '<span class="' . $chipOk . '">Ready for a label</span>' : '<span class="' . $chipBad . '">Not ready for a label</span> see the recipe\'s Label check' ?></p>
    <?php if ($fit): ?>
    <p class="text-sm">One batch weighs <?= $h(bcrf_kitchen_grams($fit['batch_g'])) ?> before cooking. <?= $h($num($p['units_per_batch'])) ?> × <?= $h(bcrf_kitchen_grams($fit['each_g'])) ?> = <?= $h(bcrf_kitchen_grams($fit['need_g'])) ?>.</p>
    <?php if ($fit['over']): ?><p class="text-sm text-amber-800 mt-1">That's more than the batch weighs (it fits about <?= $h($num($fit['fits'])) ?>). Check the units per batch or the product size.</p><?php endif; ?>
    <?php elseif ($res['problems'] === 0): ?><p class="text-xs text-stone-500">Batch weight is only compared for products sized by weight (oz, lb, g).</p><?php endif; ?>
  </div>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

$rows = bcrf_kitchen_products($pdo);
$linkable = $canEdit ? bcrf_kitchen_linkable_products($pdo) : [];
bcrf_shell_open('Kitchen products', ['toast' => $toast, 'width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Kitchen products</h1>';
echo '<p class="text-sm text-stone-500 mb-4">Products made in the kitchen and the recipe behind each: how many units a batch makes, how long it keeps, and whether NCDA&amp;CS has confirmed the product type. Products marked "Kitchen good (made here)" in Storefront › Products show here.</p>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <div class="<?= $card ?>">
    <?php if (!$rows): ?><p class="text-sm text-stone-500">No kitchen products yet. Set a product's type to "Kitchen good (made here)" in Storefront › Products, or link one below.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $r): ?>
      <li class="py-2 text-sm">
        <a class="font-semibold underline text-amber-800" href="kitchen_products.php?id=<?= (int)$r['id'] ?>"><?= $h($r['name']) ?></a><?= !$r['is_active'] ? ' <span class="text-xs text-stone-500">(off sale)</span>' : '' ?>
        <span class="block text-xs text-stone-500 mt-1"><?= $r['recipe_id'] ? 'From ' . $h($r['recipe_title']) . ' · ' . $h($num($r['units_per_batch'])) . ' per batch' : 'No recipe linked' ?><?= $r['net_qty'] !== '' ? ' · ' . $h($r['net_qty']) : '' ?><?= $r['shelf_life_days'] ? ' · keeps ' . (int)$r['shelf_life_days'] . ' days' : '' ?></span>
        <span class="block mt-1">
          <?php if (!$r['recipe_id']): ?><span class="<?= $chipBad ?>">Link a recipe</span><?php endif; ?>
          <?php if ($r['net_qty'] === ''): ?><span class="<?= $chipBad ?>">No size</span><?php endif; ?>
          <?php if ($r['recipe_id'] && !$r['shelf_life_days']): ?><span class="<?= $chipBad ?>">No shelf life</span><?php endif; ?>
          <?php if ($r['recipe_id'] && !$r['approved_type']): ?><span class="<?= $chipBad ?>">Product type not confirmed</span><?php endif; ?>
          <?php if ($r['recipe_id'] && $r['approved_type'] && $r['shelf_life_days'] && $r['net_qty'] !== ''): ?><span class="<?= $chipOk ?>">Set up</span><?php endif; ?>
        </span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <?php if ($linkable): ?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="pl">Link another product</label><select id="pl" name="id" class="<?= $input ?>">
      <?php foreach ($linkable as $l): ?><option value="<?= (int)$l['id'] ?>"><?= $h($l['name']) ?><?= $l['sku'] ? ' (' . $h($l['sku']) . ')' : '' ?></option><?php endforeach; ?></select></div>
    <button class="<?= $btnSec ?>">Open</button>
  </form>
  <?php endif; ?>
<?php
bcrf_shell_close();
