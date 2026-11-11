<?php
// admin/payment_settings.php — Settings › Payments & Register (Nov 6, 2026). Administrators only.
// Moved here from register.php?view=settings and ?view=tax (Notion: "Centralize All POS Payment Methods and Provider Settings").
// The register reads these server-side (includes/receipts.php bcrf_pos_settings(), includes/sales.php bcrf_tax_categories());
// this page is the only place they're edited. register.php?view=settings|tax now redirect here.
//   ?tab=methods   payment methods + the farm's PayPal/Venmo/Cash App handles
//   ?tab=register  discount buttons, receipt return policy
//   ?tab=tax       tax rates
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
require_once __DIR__ . '/../includes/admin_shell.php';   // shared Farm OS shell (Nov 11, 2026)
check_admin();
if (($_SESSION['user_role'] ?? '') !== 'administrator') { http_response_code(403); exit('Administrators only.'); }
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/sales.php';
require_once __DIR__ . '/../includes/receipts.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$h     = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$tabs  = ['methods' => 'Payment methods', 'register' => 'Register', 'tax' => 'Tax rates'];
$tab   = isset($tabs[$_GET['tab'] ?? '']) ? $_GET['tab'] : 'methods';
$_GET['tab'] = $tab;   // the Settings › Payments & Register section tabs mark this one (Nov 11, 2026)
$ready = bcrf_sales_ready($pdo);
$flash = $_SESSION['pay_flash'] ?? null; unset($_SESSION['pay_flash']);
$go    = function (string $type, string $msg) use ($tab) { $_SESSION['pay_flash'] = [$type, $msg]; header('Location: /admin/payment_settings.php?tab=' . $tab); exit; };

// ── Save ─────────────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) $go('err', 'Security token expired; reload and try again.');
    if ($tab === 'tax') {
        if (!$ready) $go('err', 'Run migration 0009 in Settings › Database first.');
        $cats = bcrf_tax_categories($pdo);
        $up = $pdo->prepare("UPDATE tax_categories SET rate_bp = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?");
        $changed = [];
        foreach ((array)($_POST['rate'] ?? []) as $id => $pct) {
            $id = (int)$id; $bp = (int)round((float)$pct * 100);
            if (!isset($cats[$id]) || $bp < 0 || $bp > 3000 || $bp === $cats[$id]['rate_bp']) continue;
            $up->execute([$bp, (int)$_SESSION['user_id'], $id]);
            bcrf_audit_event($pdo, ['actor_type' => 'staff', 'actor_id' => (int)$_SESSION['user_id'], 'actor_label' => bcrf_audit_actor()[2],
                'ip' => bcrf_audit_actor()[3], 'action' => 'update', 'object_type' => 'tax_categories', 'object_id' => $id,
                'object_label' => $cats[$id]['name'], 'before' => ['rate_bp' => $cats[$id]['rate_bp']], 'after' => ['rate_bp' => $bp]]);
            $changed[] = $cats[$id]['name'] . ' ' . ($bp / 100) . '%';
        }
        $go('ok', $changed ? 'Saved: ' . implode(', ', $changed) . '. New sales use these rates.' : 'No changes.');
    }
    if ($tab === 'methods' && ($_POST['form'] ?? '') === 'tenders') {
        try { bcrf_tenders_save($pdo, array_map('strval', (array)($_POST['on'] ?? [])), (array)($_POST['order'] ?? [])); }
        catch (InvalidArgumentException $e) { $go('err', $e->getMessage()); }
        $go('ok', 'Saved. Registers show the new payment buttons when they reload.');
    }
    // Methods and Register tabs each post only their own fields; keep the rest as they are.
    $cur = bcrf_pos_settings($pdo);
    $in = [
        'p2p_paypal'  => $cur['handles']['paypal'], 'p2p_venmo' => $cur['handles']['venmo'], 'p2p_cashapp' => $cur['handles']['cashapp'],
        'receipt_return_policy' => $cur['policy'], 'pos_discount_presets' => implode(',', $cur['presets']),
    ];
    $keys = $tab === 'methods' ? ['p2p_paypal', 'p2p_venmo', 'p2p_cashapp'] : ['receipt_return_policy', 'pos_discount_presets'];
    foreach ($keys as $k) $in[$k] = (string)($_POST[$k] ?? '');
    try { bcrf_pos_settings_save($pdo, $in, (int)$_SESSION['user_id']); }
    catch (InvalidArgumentException $e) { $go('err', $e->getMessage()); }
    $go('ok', 'Saved. Registers pick this up when they reload.');
}

$ps   = bcrf_pos_settings($pdo);
$cats = ($tab === 'tax' && $ready) ? bcrf_tax_categories($pdo) : [];
$methodNotes = [
    'cash'    => 'Counted in the drawer. Change worked out on the register.',
    'paypal'  => 'Manual: customer pays the farm\'s PayPal; staff check the farm phone, then match it in Sales › P2P matching.',
    'venmo'   => 'Manual: same as PayPal.',
    'cashapp' => 'Manual: same as PayPal.',
    'check'   => 'Manual: recorded on the sale, deposited later.',
];
?><?php bcrf_shell_open('Payments & Register', ['strict' => false, 'css' => ['admin-settings.css'], 'width' => 'max-w-3xl fset fs-pay']); ?>
<h1>💳 Payments &amp; Register</h1>
<p class="muted" style="margin:0">Everything the register uses for payments, discounts, receipts and tax. Changes are saved to the audit log.</p>
<?php if ($flash): ?><div class="flash <?= $flash[0] === 'ok' ? 'ok' : 'err' ?>"><?= $h($flash[1]) ?></div><?php endif; ?>

<?php if ($tab === 'methods'): ?>
<form method="POST" class="card">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
  <h2>The farm's payment apps</h2>
  <p class="muted" style="margin:0">Shown with a QR code when staff take a PayPal, Venmo or Cash App payment, so the customer can scan it. Leave blank to hide.</p>
  <label>PayPal.me name <small class="muted">(paypal.me/<b>name</b>)</small><input type="text" name="p2p_paypal" value="<?= $h($ps['handles']['paypal']) ?>" maxlength="41" autocomplete="off"></label>
  <label>Venmo username <small class="muted">(@<b>name</b>)</small><input type="text" name="p2p_venmo" value="<?= $h($ps['handles']['venmo']) ?>" maxlength="41" autocomplete="off"></label>
  <label>Cash App $cashtag <small class="muted">($<b>name</b>)</small><input type="text" name="p2p_cashapp" value="<?= $h($ps['handles']['cashapp']) ?>" maxlength="41" autocomplete="off"></label>
  <button type="submit">Save payment apps</button>
</form>
<form method="POST" class="card">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
  <input type="hidden" name="form" value="tenders">
  <h2>Payment types on the register</h2>
  <p class="muted" style="margin:0">Tick the ones staff can take. The number sets the button order (1 first). Turning one off only hides it for new sales; past sales and refunds still work.<br>None of these are connected to a payment company: the register records them, it doesn't verify them.</p>
  <?php $act = bcrf_tenders_active($pdo); $pos = array_flip(array_keys($act)); $n = count($act);
        foreach (bcrf_tenders() as $code => $label): $isOn = isset($act[$code]); ?>
    <div class="mrow trow">
      <label class="tchk"><input type="checkbox" name="on[]" value="<?= $h($code) ?>"<?= $isOn ? ' checked' : '' ?>> <b><?= $h($label) ?></b></label>
      <label class="tord">Order <input type="number" name="order[<?= $h($code) ?>]" value="<?= $isOn ? $pos[$code] + 1 : ++$n ?>" min="1" max="20" inputmode="numeric"></label>
      <small class="muted"><?= $h($methodNotes[$code] ?? '') ?></small>
    </div>
  <?php endforeach; ?>
  <button type="submit">Save payment types</button>
</form>

<?php elseif ($tab === 'register'): ?>
<form method="POST" class="card">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
  <h2>Discount buttons</h2>
  <p class="muted" style="margin:0">The % buttons anyone on the register can use, for the whole sale or one item. Any other %, or making an item free, needs an Owner or Manager (or "POS Discount Override" in the Permissions Matrix).</p>
  <label>Percents, separated by commas<input type="text" name="pos_discount_presets" value="<?= $h(implode(', ', $ps['presets'])) ?>" maxlength="60" autocomplete="off" inputmode="numeric"></label>
  <h2 style="margin-top:18px">Receipt</h2>
  <label>Return policy <small class="muted">(printed and emailed at the bottom of every receipt)</small><textarea name="receipt_return_policy" rows="3" maxlength="500"><?= $h($ps['policy']) ?></textarea></label>
  <button type="submit">Save</button>
</form>

<?php else: ?>
<p class="muted">Each product has a tax category (set on the product in <a href="/admin/products.php">Products</a>). Sales work out tax per line from these rates. Past sales keep the rate they were sold at.</p>
<?php if (!$ready): ?><div class="flash err">Run migration 0009 in Settings › Database first.</div><?php else: ?>
<form method="POST" class="card">
  <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
  <?php foreach ($cats as $c): ?>
    <label class="taxrow"><span><b><?= $h($c['name']) ?></b><?php if ($c['notes']): ?><br><small class="muted"><?= $h($c['notes']) ?></small><?php endif; ?></span>
      <span class="pct"><input type="number" inputmode="decimal" step="0.01" min="0" max="30" name="rate[<?= (int)$c['id'] ?>]" value="<?= $h(rtrim(rtrim(number_format($c['rate_bp'] / 100, 2, '.', ''), '0'), '.')) ?>"> %</span></label>
  <?php endforeach; ?>
  <button type="submit">Save rates</button>
</form>
<p class="muted">Tax on food, admission and thrift differs by state and item. Confirm categories and rates with the NC Department of Revenue or an accountant.</p>
<?php endif; ?>
<?php endif; ?>
<?php bcrf_shell_close(); ?>
