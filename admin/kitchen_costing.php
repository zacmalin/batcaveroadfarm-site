<?php
// admin/kitchen_costing.php — Kitchen › Costing (Farm OS Phase 4 slice 6, Nov 13, 2026; includes/kitchen_costing.php, migration 0056).
//   GET kitchen_costing.php              every kitchen product: cost per unit, price, margin; lowest margin first; the alert
//       kitchen_costing.php?product=N    one product: each recipe line's cost and where it comes from, packaging, totals
//   POST act=ingredient_cost | packaging_add | packaging_remove | alert
// Prices are never changed here; the price comes from the product (Storefront › Products).
// Permission: table 'recipes' (view to look, edit to change costs, packaging and the alert).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_batches.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'recipes', 'edit');

if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo) || !bcrf_kitchen_costing_ready($pdo)) {
    bcrf_shell_open('Kitchen costing');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Costing</h1>', bcrf_shell_notice('warning', 'Run migrations 0050, 0051 and 0056 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $pid = (int)($_POST['product'] ?? 0);
    $back = $pid ? 'kitchen_costing.php?product=' . $pid : 'kitchen_costing.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfKitchenError('You can see costs but not change them.');
        if ($act === 'ingredient_cost') {
            $iid = (int)($_POST['ingredient'] ?? 0);
            bcrf_kitchen_ingredient_cost_save($pdo, $iid, $_POST['price'] ?? '', $_POST['qty'] ?? '', $_POST['unit'] ?? '', $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'ingredients', $iid, trim((string)($_POST['price'] ?? '')) === '' ? 'Manual cost cleared' : 'Manual cost set: $' . trim((string)$_POST['price']) . ' for ' . trim((string)($_POST['qty'] ?? '')));
            $_SESSION['kc_flash'] = ['ok', 'Cost saved.'];
        } elseif ($act === 'packaging_add') {
            $kid = bcrf_kitchen_packaging_add($pdo, $pid, $_POST);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'kitchen_packaging', $kid, 'Packaging added to product #' . $pid);
            $_SESSION['kc_flash'] = ['ok', 'Packaging added.'];
        } elseif ($act === 'packaging_remove') {
            $kid = (int)($_POST['item'] ?? 0);
            if (!bcrf_kitchen_packaging_remove($pdo, $kid, $pid)) throw new BcrfKitchenError('That packaging was already removed.');
            if (function_exists('erp_audit')) erp_audit($pdo, 'delete', 'kitchen_packaging', $kid, 'Packaging removed from product #' . $pid);
            $_SESSION['kc_flash'] = ['ok', 'Packaging removed.'];
        } elseif ($act === 'alert') {
            $v = bcrf_kitchen_margin_alert_save($pdo, $_POST['alert'] ?? '');
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 'kitchen_margin_alert', 'Kitchen margin alert set to ' . $v . '%');
            $_SESSION['kc_flash'] = ['ok', 'Margin alert set to ' . rtrim(rtrim(number_format($v, 1), '0'), '.') . '%.'];
        } else {
            throw new BcrfKitchenError('Unknown action.');
        }
    } catch (BcrfKitchenError $e) {
        $_SESSION['kc_flash'] = ['err', $e->getMessage()];
        $_SESSION['kc_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_costing] ' . $act . ': ' . $e->getMessage());
        $_SESSION['kc_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['kc_flash'] ?? null; unset($_SESSION['kc_flash']);
$form = $_SESSION['kc_form'] ?? null; unset($_SESSION['kc_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$m = fn(?float $c) => bcrf_kitchen_money($c);
$pct = fn(?float $p) => $p === null ? '—' : number_format($p, 1) . '%';
$alert = bcrf_kitchen_margin_alert($pdo);
$alertTxt = rtrim(rtrim(number_format($alert, 1), '0'), '.') . '%';
$chips = [
    'ok' => ['OK', 'bg-green-100 text-green-800'], 'low' => ['Below ' . $alertTxt, 'bg-red-100 text-red-800'],
    'incomplete' => ['Missing costs', 'bg-amber-100 text-amber-900'], 'no_units' => ['No batch size', 'bg-stone-200 text-stone-700'],
    'no_price' => ['No price', 'bg-stone-200 text-stone-700'], 'no_recipe' => ['No recipe', 'bg-stone-200 text-stone-700'],
];
$chip = fn(string $s) => '<span class="inline-block px-2 py-0.5 rounded text-xs ' . ($chips[$s][1] ?? '') . '">' . htmlspecialchars($chips[$s][0] ?? $s) . '</span>';
$srcTxt = ['stock' => 'from stock', 'manual' => 'manual', 'recipe' => 'its recipe'];

// ── One product ──
$pid = (int)($_GET['product'] ?? 0);
if ($pid) {
    try { $c = bcrf_kitchen_product_cost($pdo, $pid, $alert); }
    catch (BcrfKitchenError $e) { $_SESSION['kc_flash'] = ['err', $e->getMessage()]; header('Location: kitchen_costing.php'); exit; }
    $p = $c['product']; $rc = $c['recipe'];
    $units = bcrf_kitchen_units($pdo);
    $ingCosts = $rc ? bcrf_kitchen_ingredient_costs($pdo, array_column($rc['lines'], 'ingredient_id')) : [];
    $manualRows = [];
    if ($ingCosts) {
        $in = implode(',', array_map('intval', array_keys($ingCosts)));
        foreach ($pdo->query("SELECT id, cost_cents, cost_qty, cost_unit_id FROM ingredients WHERE id IN ($in)")->fetchAll(PDO::FETCH_ASSOC) as $r) $manualRows[(int)$r['id']] = $r;
    }
    $unitOpts = array_filter($units, fn($u) => in_array($u['kind'], ['mass', 'volume', 'count'], true));
    bcrf_shell_open($p['name'] . ' — cost', ['toast' => $toast, 'width' => 'max-w-4xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_costing.php">← All products</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($p['name']), ' ', $chip($c['status']), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">', $p['recipe_title'] ? 'Made from ' . $h($p['recipe_title']) . ' · ' : '', $c['units'] > 0 ? $h(bcrf_kitchen_q($c['units'])) . ' per batch · ' : '',
         '<a class="underline text-amber-800" href="kitchen_products.php?id=', $pid, '">Product link</a></p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if ($c['why'] !== '') echo bcrf_shell_notice($c['status'] === 'low' ? 'err' : 'warning', $c['why']);
    ?>
  <div class="<?= $card ?>">
    <dl class="grid grid-cols-2 sm:grid-cols-4 gap-3 text-sm">
      <div><dt class="text-stone-500 text-xs">Ingredients per unit</dt><dd class="font-semibold"><?= $h($m($c['recipe_per_unit'])) ?><?= $rc && !$rc['complete'] ? '+' : '' ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Packaging per unit</dt><dd class="font-semibold"><?= $h($m($c['pack_per_unit'])) ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Cost per unit</dt><dd class="font-semibold"><?= $h($m($c['unit_cost'])) ?></dd></div>
      <div><dt class="text-stone-500 text-xs">Price · margin</dt><dd class="font-semibold"><?= $h($m((float)$c['price'])) ?> · <span class="<?= $c['status'] === 'low' ? 'text-red-700' : '' ?>"><?= $h($pct($c['margin'])) ?></span></dd></div>
    </dl>
    <p class="text-xs text-stone-500 mt-2">Margin = (price − cost) ÷ price. The alert is <?= $h($alertTxt) ?>. Prices are changed in Storefront › Products, not here.</p>
  </div>

  <?php if ($rc): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Recipe, one batch: <?= $h($m($rc['total'])) ?><?= $rc['complete'] ? '' : ' so far' ?></h2>
    <p class="text-xs text-stone-500 mb-2">An ingredient linked to a stock product costs that product's average cost (it updates when you receive stock). Otherwise enter what you pay.</p>
    <ul class="divide-y divide-stone-100 text-sm">
      <?php foreach ($rc['lines'] as $i => $l):
        $iid = $l['ingredient_id']; $mr = $iid ? ($manualRows[$iid] ?? null) : null; $ic = $iid ? ($ingCosts[$iid] ?? null) : null; ?>
      <li class="py-2">
        <div class="flex flex-wrap justify-between gap-2">
          <span><?= $l['linked_recipe_id'] ? '📖 ' : '' ?><?= $h($l['name']) ?> <span class="text-xs text-stone-500"><?= $h(bcrf_kitchen_grams($l['grams'] !== null ? (float)$l['grams'] : null)) ?></span></span>
          <span class="text-right"><?php if ($l['cost'] !== null): ?><strong><?= $h($m($l['cost'])) ?></strong> <span class="text-xs text-stone-500"><?= $h($m($l['cpg'] * 1000)) ?>/kg · <?= $h($srcTxt[$l['source']] ?? '') ?><?= $l['source'] === 'stock' && $ic ? ' (' . $h($ic['stock_name']) . ')' : '' ?></span><?php else: ?><span class="text-amber-800 text-xs">⚠ <?= $h($l['why']) ?></span><?php endif; ?></span>
        </div>
        <?php if ($iid && $canEdit && ($l['source'] !== 'stock')):
          $f = ($form && (int)($form['ingredient'] ?? 0) === $iid) ? $form : null; ?>
        <details class="mt-1"<?= $l['cost'] === null || $f ? ' open' : '' ?>>
          <summary class="text-xs text-amber-800 underline cursor-pointer"><?= $ic && $ic['manual'] !== '' ? 'Manual cost: ' . $h($ic['manual']) . ' (change)' : 'Enter what you pay' ?></summary>
          <form method="POST" class="flex flex-wrap gap-2 items-end mt-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="ingredient_cost"><input type="hidden" name="product" value="<?= $pid ?>"><input type="hidden" name="ingredient" value="<?= (int)$iid ?>">
            <div class="w-24"><label class="<?= $label ?>" for="cp<?= $i ?>">Price $</label><input id="cp<?= $i ?>" name="price" inputmode="decimal" value="<?= $h($f['price'] ?? ($mr && $mr['cost_cents'] !== null ? number_format((int)$mr['cost_cents'] / 100, 2, '.', '') : '')) ?>" class="<?= $input ?>" placeholder="4.29"></div>
            <div class="w-20"><label class="<?= $label ?>" for="cq<?= $i ?>">for</label><input id="cq<?= $i ?>" name="qty" inputmode="decimal" value="<?= $h($f['qty'] ?? ($mr && $mr['cost_qty'] !== null ? bcrf_kitchen_q((float)$mr['cost_qty']) : '')) ?>" class="<?= $input ?>" placeholder="5"></div>
            <div class="w-32"><label class="<?= $label ?>" for="cu<?= $i ?>">unit</label><select id="cu<?= $i ?>" name="unit" class="<?= $input ?>"><option value="">each</option>
              <?php $sel = (int)($f['unit'] ?? ($mr['cost_unit_id'] ?? 0)); foreach ($unitOpts as $uidx => $u): ?><option value="<?= (int)$uidx ?>"<?= $sel === (int)$uidx ? ' selected' : '' ?>><?= $h($u['name']) ?></option><?php endforeach; ?></select></div>
            <button class="<?= $btnSec ?>">Save</button>
          </form>
          <p class="text-xs text-stone-500 mt-1">Leave the price blank and save to clear it. Or link it to a stock product in Kitchen › Ingredients to use its average cost.</p>
        </details>
        <?php endif; ?>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Packaging, per unit: <?= $h($m($c['pack_per_unit'])) ?></h2>
    <p class="text-xs text-stone-500 mb-2">Jars, lids, labels, ribbon: anything that goes on every unit.</p>
    <?php if (!$c['packaging']): ?><p class="text-sm text-stone-500 mb-2">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100 text-sm mb-3">
      <?php foreach ($c['packaging'] as $k): ?>
      <li class="py-2 flex flex-wrap items-center justify-between gap-2">
        <span><?= $h($k['name']) ?><?= (float)$k['qty_per_unit'] != 1 ? ' × ' . $h(bcrf_kitchen_q((float)$k['qty_per_unit'])) : '' ?> <span class="text-xs text-stone-500"><?= $k['each'] !== null ? $h($m($k['each'])) . ' each · ' . $h($srcTxt[$k['source']] ?? '') : '' ?></span></span>
        <span class="flex items-center gap-2"><?= $k['per_unit'] !== null ? '<strong>' . $h($m($k['per_unit'])) . '</strong>' : '<span class="text-amber-800 text-xs">⚠ ' . $h($k['why']) . '</span>' ?>
          <?php if ($canEdit): ?><form method="POST"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="packaging_remove"><input type="hidden" name="product" value="<?= $pid ?>"><input type="hidden" name="item" value="<?= (int)$k['id'] ?>"><button class="text-xs underline text-stone-500" aria-label="Remove <?= $h($k['name']) ?>">Remove</button></form><?php endif; ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
    <?php if ($canEdit): $pf = ($form && ($form['act'] ?? '') === 'packaging_add') ? $form : []; ?>
    <form method="POST" class="grid grid-cols-2 sm:grid-cols-4 gap-2 items-end"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="packaging_add"><input type="hidden" name="product" value="<?= $pid ?>">
      <div class="col-span-2"><label class="<?= $label ?>" for="ks">From a stock product (uses its average cost)</label><select id="ks" name="packaging_inventory_id" class="<?= $input ?>"><option value="">— none, enter a cost —</option>
        <?php foreach (bcrf_kitchen_stock_products($pdo) as $sp): if ((int)$sp['id'] === $pid) continue; ?><option value="<?= (int)$sp['id'] ?>"<?= (int)($pf['packaging_inventory_id'] ?? 0) === (int)$sp['id'] ? ' selected' : '' ?>><?= $h($sp['name']) ?></option><?php endforeach; ?></select></div>
      <div class="col-span-2"><label class="<?= $label ?>" for="kn">Or a name</label><input id="kn" name="name" maxlength="100" value="<?= $h($pf['name'] ?? '') ?>" class="<?= $input ?>" placeholder="e.g. 8 oz jar"></div>
      <div><label class="<?= $label ?>" for="kc">Cost each $</label><input id="kc" name="cost" inputmode="decimal" value="<?= $h($pf['cost'] ?? '') ?>" class="<?= $input ?>" placeholder="0.80"></div>
      <div><label class="<?= $label ?>" for="kq">Per unit</label><input id="kq" name="qty_per_unit" inputmode="decimal" value="<?= $h($pf['qty_per_unit'] ?? '1') ?>" class="<?= $input ?>"></div>
      <div class="col-span-2"><button class="<?= $btnMain ?>">Add packaging</button></div>
    </form>
    <?php endif; ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

// ── The list ──
$list = bcrf_kitchen_cost_overview($pdo);
$low = count(array_filter($list, fn($c) => $c['status'] === 'low'));
bcrf_shell_open('Kitchen costing', ['toast' => $toast, 'width' => 'max-w-4xl']);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Costing</h1>
  <p class="text-sm text-stone-600 mb-4">What each kitchen product costs to make (ingredients plus packaging) against its price. Costs from stock update when you receive stock, so a price rise shows up here. Products below the <?= $h($alertTxt) ?> margin alert are flagged here and on the dashboard.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if ($low) echo bcrf_shell_notice('err', $low . ' product' . ($low === 1 ? ' is' : 's are') . ' below the ' . $alertTxt . ' margin alert.'); ?>
  <?php if (!$list): ?>
    <div class="<?= $card ?> text-center"><p class="text-sm font-semibold text-stone-700">No kitchen products linked yet</p>
      <p class="text-xs text-stone-500 mt-1">Link a product to its recipe in Kitchen › Products first.</p></div>
  <?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-lg divide-y divide-stone-100 shadow-sm mb-4">
    <?php foreach ($list as $c): ?>
    <li>
      <a class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-stone-50" href="kitchen_costing.php?product=<?= (int)$c['product']['id'] ?>">
        <span><span class="font-semibold text-stone-800"><?= $h($c['product']['name']) ?></span>
          <span class="block text-xs text-stone-500"><?= $c['unit_cost'] !== null ? 'Costs ' . $h($m($c['unit_cost'])) . ' · sells ' . $h($m((float)$c['price'])) : $h($c['why']) ?></span></span>
        <span class="flex items-center gap-2"><?= $c['margin'] !== null ? '<span class="text-sm font-semibold ' . ($c['status'] === 'low' ? 'text-red-700' : 'text-stone-800') . '">' . $h($pct($c['margin'])) . '</span>' : '' ?><?= $chip($c['status']) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Margin alert</h2>
    <?php if ($canEdit): ?>
    <form method="POST" class="flex flex-wrap gap-2 items-end"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="alert">
      <div class="w-28"><label class="<?= $label ?>" for="ma">Flag below (%)</label><input id="ma" name="alert" inputmode="decimal" value="<?= $h($form['alert'] ?? rtrim(rtrim(number_format($alert, 1), '0'), '.')) ?>" class="<?= $input ?>"></div>
      <button class="<?= $btnSec ?>">Save</button>
    </form>
    <?php else: ?>
    <p class="text-sm">Products below <?= $h($alertTxt) ?> margin are flagged.</p>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
