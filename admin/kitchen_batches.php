<?php
// admin/kitchen_batches.php — Kitchen › Batches (Farm OS Phase 4 slice 3, Nov 13, 2026; includes/kitchen_batches.php, migration 0053).
//   GET kitchen_batches.php?q=                      batches, newest first (search lot code or product)
//       kitchen_batches.php?new=1                   pick the product made
//       kitchen_batches.php?new=1&product=N&n=2     record a batch: what it will add and take out of stock, then the form
//       kitchen_batches.php?id=N                    one batch: lot, dates, version, ingredients used; void
//   POST act=record | void
// Permission: table 'recipes' (view to look, edit to record or void).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_print.php';   // includes kitchen_batches.php; Print labels button (slice 5)
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'recipes', 'edit');

if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo) || !bcrf_kitchen_batches_ready($pdo)) {
    bcrf_shell_open('Kitchen batches');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Kitchen batches</h1>', bcrf_shell_notice('warning', 'Run migrations 0050, 0051 and 0053 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = 'kitchen_batches.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfKitchenError('You can see batches but not record them.');
        if ($act === 'record') {
            $pid = (int)($_POST['product'] ?? 0);
            $back = 'kitchen_batches.php?new=1&product=' . $pid . '&n=' . rawurlencode((string)($_POST['batches'] ?? '1'));
            $b = bcrf_kitchen_batch_record($pdo, $pid, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'kitchen_batches', (int)$b['id'], 'Batch ' . $b['lot_code'] . ': ' . bcrf_kitchen_q($b['units_made']) . ' × ' . $b['product_name']);
            $_SESSION['kb_flash'] = ['ok', 'Recorded lot ' . $b['lot_code'] . '.'];
            $back = 'kitchen_batches.php?id=' . (int)$b['id'];
        } elseif ($act === 'void') {
            $bid = (int)($_POST['id'] ?? 0);
            $back = 'kitchen_batches.php?id=' . $bid;
            $b = bcrf_kitchen_batch_void($pdo, $bid, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'delete', 'kitchen_batches', $bid, 'Batch ' . $b['lot_code'] . ' voided: ' . $b['void_reason']);
            $_SESSION['kb_flash'] = ['ok', 'Voided lot ' . $b['lot_code'] . '. Its stock changes were reversed.'];
        } else {
            throw new BcrfKitchenError('Unknown action.');
        }
    } catch (BcrfKitchenError | BcrfStockError $e) {
        $_SESSION['kb_flash'] = ['err', $e->getMessage()];
        $_SESSION['kb_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_batches] ' . $act . ': ' . $e->getMessage());
        $_SESSION['kb_flash'] = ['err', 'Could not save. Try again.'];
        $_SESSION['kb_form'] = $_POST;
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['kb_flash'] ?? null; unset($_SESSION['kb_flash']);
$form = $_SESSION['kb_form'] ?? null; unset($_SESSION['kb_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$btnBad  = 'px-3 py-2 rounded text-sm border border-red-300 text-red-700 hover:bg-red-50 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chipBad = 'inline-block px-2 py-0.5 rounded text-xs bg-amber-100 text-amber-900 mr-1 mb-1';
$chipOk  = 'inline-block px-2 py-0.5 rounded text-xs bg-green-100 text-green-800 mr-1 mb-1';
$chipOff = 'inline-block px-2 py-0.5 rounded text-xs bg-stone-200 text-stone-700 mr-1 mb-1';
$day = fn($d) => $d ? date('M j, Y', strtotime($d)) : '—';
$when = fn($utc) => date('M j, Y g:i A', strtotime($utc . ' UTC'));

// ── One batch ──
$id = (int)($_GET['id'] ?? 0);
if ($id) {
    $b = bcrf_kitchen_batch($pdo, $id);
    if (!$b) { $_SESSION['kb_flash'] = ['err', 'That batch doesn\'t exist.']; header('Location: kitchen_batches.php'); exit; }
    bcrf_shell_open('Lot ' . $b['lot_code'], ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_batches.php">← All batches</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Lot ', $h($b['lot_code']), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">', $h(bcrf_kitchen_q($b['units_made'])), ' × ', $h($b['product_name']), ' · recorded ', $h($when($b['created_at'])),
         trim((string)$b['by_name']) !== '' ? ' by ' . $h($b['by_name']) : '', '</p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    if ($b['voided_at']) echo bcrf_shell_notice('warning', 'Voided ' . $when($b['voided_at']) . (trim((string)$b['voided_by_name']) !== '' ? ' by ' . $b['voided_by_name'] : '') . ': ' . $b['void_reason'] . '. Its stock changes were reversed.');
    ?>
  <div class="<?= $card ?>">
    <dl class="grid grid-cols-2 gap-x-4 gap-y-2 text-sm">
      <dt class="text-stone-500">Made on</dt><dd class="font-semibold"><?= $h($day($b['made_on'])) ?></dd>
      <dt class="text-stone-500">Best by</dt><dd class="font-semibold"><?= $h($day($b['best_by'])) ?></dd>
      <dt class="text-stone-500">Batches</dt><dd><?= $h(bcrf_kitchen_q($b['batches'])) ?></dd>
      <dt class="text-stone-500">Units made</dt><dd><?= $h(bcrf_kitchen_q($b['units_made'])) ?></dd>
      <dt class="text-stone-500">Recipe</dt><dd><a class="underline text-amber-800" href="kitchen_recipes.php?id=<?= (int)$b['recipe_id'] ?>&amp;version=<?= (int)$b['recipe_version_id'] ?>"><?= $h($b['recipe_title']) ?>, version <?= (int)$b['version_no'] ?></a></dd>
      <dt class="text-stone-500">Finished goods</dt><dd><?= (int)$b['stock_added'] ? 'Added to stock at ' . $h($b['location_name']) : 'Not added to stock (the product doesn\'t count stock)' ?></dd>
      <?php if ($b['notes']): ?><dt class="text-stone-500">Notes</dt><dd><?= $h($b['notes']) ?></dd><?php endif; ?>
    </dl>
  </div>
  <?php if (!$b['voided_at'] && function_exists('bcrf_kitchen_print_ready') && bcrf_kitchen_print_ready($pdo)): $runs = bcrf_kitchen_prints_for($pdo, $id); ?>
  <div class="<?= $card ?> flex flex-wrap items-center justify-between gap-2">
    <p class="text-sm text-stone-600"><?= $runs ? array_sum(array_column($runs, 'copies')) . ' labels printed for this lot.' : 'No labels printed for this lot yet.' ?></p>
    <a class="<?= $btnMain ?>" href="kitchen_label_print.php?batch=<?= $id ?>">🖨️ Print labels</a>
  </div>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Ingredients used</h2>
    <ul class="divide-y divide-stone-100 text-sm">
      <?php foreach ($b['ingredients'] as $r): ?>
      <li class="py-2 flex flex-wrap justify-between gap-2">
        <span><?= $r['linked_recipe_id'] ? '📖 ' : '' ?><?= $h($r['name']) ?></span>
        <span class="text-right"><strong><?= $h(bcrf_kitchen_grams($r['grams'] !== null ? (float)$r['grams'] : null)) ?></strong>
          <?php if ($r['qty_taken'] !== null): ?><br><span class="text-xs text-stone-500"><?= $h(bcrf_kitchen_q($r['qty_taken'])) ?> × <?= $h($r['stock_name']) ?> taken from <?= $h($r['location_name']) ?></span><?php endif; ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <p class="text-xs text-stone-500 mt-2"><?= (int)$b['ingredients_taken'] ? 'Linked ingredients were taken out of stock.' : 'Ingredients were not taken out of stock.' ?></p>
  </div>
  <?php if ($canEdit && !$b['voided_at']): ?>
  <form method="POST" class="<?= $card ?>"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="void"><input type="hidden" name="id" value="<?= $id ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Void this batch</h2>
    <p class="text-xs text-stone-500 mb-2">For a batch recorded by mistake. Takes its finished goods back out of stock and puts its ingredients back. The lot stays on record. If some were already sold, fix the numbers on the Stock page instead.</p>
    <div class="flex flex-wrap gap-2 items-end">
      <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="vr">Why</label><input id="vr" name="reason" maxlength="255" required class="<?= $input ?>" placeholder="e.g. recorded twice"></div>
      <button class="<?= $btnBad ?>" data-confirm="Void lot <?= $h($b['lot_code']) ?>? Its stock changes are reversed.">Void batch</button>
    </div>
  </form>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── Record a batch ──
if (isset($_GET['new'])) {
    if (!$canEdit) { $_SESSION['kb_flash'] = ['err', 'You can see batches but not record them.']; header('Location: kitchen_batches.php'); exit; }
    $pid = (int)($_GET['product'] ?? ($form['product'] ?? 0));
    bcrf_shell_open('Record a batch', ['toast' => $toast, 'width' => 'max-w-3xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_batches.php">← All batches</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Record a batch</h1>';
    if ($error) echo bcrf_shell_notice('err', $error);
    $products = bcrf_kitchen_batchable($pdo);
    if (!$products) {
        echo bcrf_shell_notice('warning', 'No products are linked to a recipe yet. Link one in Kitchen › Products first.');
        bcrf_shell_close(); exit;
    }
    ?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-2 items-end"><input type="hidden" name="new" value="1">
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="bp">Product made</label>
      <select id="bp" name="product" class="<?= $input ?>" required><option value="">Pick a product</option>
        <?php foreach ($products as $p): ?><option value="<?= (int)$p['id'] ?>"<?= $pid === (int)$p['id'] ? ' selected' : '' ?>><?= $h($p['name']) ?> (<?= $h($p['recipe_title']) ?>)</option><?php endforeach; ?></select></div>
    <div class="w-28"><label class="<?= $label ?>" for="bn">Batches</label><input id="bn" name="n" inputmode="decimal" value="<?= $h($_GET['n'] ?? ($form['batches'] ?? '1')) ?>" class="<?= $input ?>"></div>
    <button class="<?= $btnSec ?>">Show</button>
  </form>
    <?php
    if ($pid) {
        $n = 1.0;
        try { $n = bcrf_kitchen_num($_GET['n'] ?? '1', 'Batches', 0.01, 1000) ?? 1.0; } catch (BcrfKitchenError $e) { echo bcrf_shell_notice('err', $e->getMessage()); }
        try { $plan = bcrf_kitchen_batch_plan($pdo, $pid, $n); } catch (BcrfKitchenError $e) { $plan = null; echo bcrf_shell_notice('err', $e->getMessage()); }
        if ($plan) {
            foreach ($plan['blockers'] as $m) echo bcrf_shell_notice('warning', $m);
            foreach ($plan['warnings'] as $m) echo '<p class="text-sm text-amber-800 mb-1">⚠ ', $h($m), '</p>';
            if (!$plan['blockers']) {
                $p = $plan['product'];
                $canTake = (bool)array_filter($plan['lines'], fn($l) => $l['stock_id']);
                $locs = bcrf_locations($pdo);
                $defLoc = (int)($form['location_id'] ?? bcrf_location_default($pdo));
                $bestBy = $p['shelf_life_days'] !== null ? date('M j, Y', strtotime('+' . (int)$p['shelf_life_days'] . ' days')) : '';
                ?>
  <div class="<?= $card ?> mt-3">
    <h2 class="text-base font-semibold text-stone-800 mb-1"><?= $h(bcrf_kitchen_q($n)) ?> batch<?= $n == 1 ? '' : 'es' ?> of <?= $h($p['recipe_title']) ?></h2>
    <p class="text-xs text-stone-500 mb-2">Made from version <?= (int)$plan['state']['latest']['version_no'] ?> of the recipe. The label will match it.</p>
    <ul class="divide-y divide-stone-100 text-sm">
      <?php foreach ($plan['lines'] as $l): ?>
      <li class="py-2 flex flex-wrap justify-between gap-2">
        <span><?= $l['linked_recipe_id'] ? '📖 ' : '' ?><?= $h($l['name']) ?> <strong><?= $h(bcrf_kitchen_grams($l['grams'])) ?></strong></span>
        <span class="text-xs <?= $l['stock_id'] ? 'text-stone-700' : 'text-stone-400' ?>"><?= $l['stock_id'] ? 'takes ' . $h(bcrf_kitchen_q($l['qty'])) . ' × ' . $h($l['stock_name']) : $h($l['why_not']) ?></span>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (!$canTake): ?><p class="text-xs text-stone-500 mt-2">To take ingredients out of stock, link each one to its stock product in Ingredients › Label details.</p><?php endif; ?>
  </div>
  <form method="POST" class="<?= $card ?>"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="record">
    <input type="hidden" name="product" value="<?= $pid ?>"><input type="hidden" name="batches" value="<?= $h(bcrf_kitchen_q($n)) ?>">
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div><label class="<?= $label ?>" for="bu">Units made</label><input id="bu" name="units_made" inputmode="decimal" value="<?= $h($form['units_made'] ?? '') ?>" class="<?= $input ?>" placeholder="<?= $h(bcrf_kitchen_q($plan['units_default'])) ?>">
        <p class="text-xs text-stone-500 mt-1">Blank = <?= $h(bcrf_kitchen_q($plan['units_default'])) ?> (<?= $h(bcrf_kitchen_q($p['units_per_batch'])) ?> a batch). Enter what you actually got.</p></div>
      <div><label class="<?= $label ?>" for="bd">Made on</label><input id="bd" type="date" name="made_on" value="<?= $h($form['made_on'] ?? date('Y-m-d')) ?>" max="<?= date('Y-m-d') ?>" class="<?= $input ?>" required>
        <p class="text-xs text-stone-500 mt-1"><?= $bestBy !== '' ? 'Best by ' . $h($bestBy) . ' if made today (' . (int)$p['shelf_life_days'] . ' days).' : 'No shelf life set, so no best-by date.' ?></p></div>
      <div><label class="<?= $label ?>" for="bl">Finished goods go to</label><select id="bl" name="location_id" class="<?= $input ?>">
        <?php foreach ($locs as $lc): ?><option value="<?= (int)$lc['id'] ?>"<?= (int)$lc['id'] === $defLoc ? ' selected' : '' ?>><?= $h($lc['name']) ?></option><?php endforeach; ?></select></div>
      <div class="flex items-end"><?php if ($canTake): ?><label class="flex items-center gap-2 text-sm"><input type="checkbox" name="take_ingredients" value="1"<?= !$form || !empty($form['take_ingredients']) ? ' checked' : '' ?>> Take linked ingredients out of stock</label><?php endif; ?></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="bnotes">Notes (optional)</label><textarea id="bnotes" name="notes" rows="2" maxlength="500" class="<?= $input ?>" placeholder="Anything unusual. A substitution needs a new recipe version first, so the label stays right."><?= $h($form['notes'] ?? '') ?></textarea></div>
    </div>
    <div class="mt-4"><button class="<?= $btnMain ?>">Record batch and make the lot code</button></div>
  </form>
                <?php
            }
        }
    }
    bcrf_shell_close(); exit;
}

// ── The list ──
$q = trim((string)($_GET['q'] ?? ''));
$list = bcrf_kitchen_batches($pdo, ['q' => $q]);
bcrf_shell_open('Kitchen batches', ['toast' => $toast, 'width' => 'max-w-4xl']);
?>
  <div class="flex flex-wrap items-center justify-between gap-2 mb-1">
    <h1 class="text-2xl font-bold text-stone-800">Batches</h1>
    <?php if ($canEdit): ?><a class="<?= $btnMain ?>" href="kitchen_batches.php?new=1">+ Record a batch</a><?php endif; ?>
  </div>
  <p class="text-sm text-stone-600 mb-4">Each batch gets a lot code and best-by date, adds the finished goods to stock, and can take its ingredients out of stock.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <form method="GET" class="flex gap-2 mb-4">
    <input name="q" value="<?= $h($q) ?>" class="<?= $input ?>" placeholder="Lot code or product" aria-label="Search batches">
    <button class="<?= $btnSec ?>">Search</button>
  </form>
  <?php if (!$list): ?>
    <div class="<?= $card ?> text-center"><p class="text-sm font-semibold text-stone-700"><?= $q !== '' ? 'No batches match.' : 'No batches yet' ?></p>
      <?php if ($q === ''): ?><p class="text-xs text-stone-500 mt-1">Link a product to its recipe in Kitchen › Products, save the recipe as a version, then record a batch.</p><?php endif; ?></div>
  <?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-lg divide-y divide-stone-100 shadow-sm">
    <?php foreach ($list as $r): ?>
    <li>
      <a class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-stone-50" href="kitchen_batches.php?id=<?= (int)$r['id'] ?>">
        <span><span class="font-mono font-semibold text-stone-800"><?= $h($r['lot_code']) ?></span> <span class="text-sm text-stone-600"><?= $h(bcrf_kitchen_q($r['units_made'])) ?> × <?= $h($r['product_name']) ?></span></span>
        <span class="text-xs text-stone-500"><?= $r['voided_at'] ? '<span class="' . $chipOff . '">Voided</span>' : '' ?>Made <?= $h($day($r['made_on'])) ?><?= $r['best_by'] ? ' · best by ' . $h($day($r['best_by'])) : '' ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
<?php
bcrf_shell_close();
