<?php
// admin/party_packages.php — party packages: what each includes, its price and length (Farm OS Phase 3, Nov 12, 2026;
// includes/parties.php; 07 › Private events: "Packages (configurable)").
//   GET  party_packages.php[?edit=N|new=1]
//   POST act=save (id blank = new) | act=toggle, on=1|0 (the Offered switch; JSON when ajax=1, Nov 14, 2026)
// Anyone with Private Events can look. Changing a package needs an Owner or Manager, because it changes what parties cost.
// Quotes already made keep their price.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'event_inquiries']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/booking.php';
require_once __DIR__ . '/../includes/event_quotes.php';
require_once __DIR__ . '/../includes/parties.php';
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/admin_switch.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$mgr = bcrf_quote_is_manager();
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$ready = bcrf_party_ready($pdo);
$dollars = fn(int $c) => $c ? ($c % 100 ? number_format($c / 100, 2, '.', '') : (string)intdiv($c, 100)) : '';

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST' && $ready) {
    $act = (string)($_POST['act'] ?? '');
    $id = (int)($_POST['id'] ?? 0) ?: null;
    $back = 'party_packages.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfPartyError('Security token expired; try again.');
        if (!$mgr) throw new BcrfPartyError('Only an Owner or Manager can change packages.');
        if ($act === 'save') {
            $before = bcrf_party_package($pdo, $id);
            $newId = bcrf_party_package_save($pdo, $id, $_POST);
            $after = bcrf_party_package($pdo, $newId);
            if (function_exists('erp_audit')) erp_audit($pdo, $id ? 'update' : 'create', 'party_packages', $newId,
                ($id ? 'Party package changed: ' : 'Party package added: ') . $after['name'] . ' · ' . bcrf_party_price_text($after)
                . ($before && bcrf_party_price_text($before) !== bcrf_party_price_text($after) ? ' (was ' . bcrf_party_price_text($before) . ')' : ''));
            $_SESSION['pkg_flash'] = ['ok', $id ? 'Package saved. Quotes already made keep their price.' : 'Package added.'];
        } elseif ($act === 'toggle') {
            $p = bcrf_party_package($pdo, $id);
            if (!$p) throw new BcrfPartyError('That package is gone.');
            $want = isset($_POST['on']) ? bcrf_switch_wanted() : !$p['active'];   // the switch says which state it wants
            if ((bool)$p['active'] !== $want) {
                $pdo->prepare("UPDATE party_packages SET active = ?, updated_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$want ? 1 : 0, $p['id']]);
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'party_packages', $p['id'], 'Party package ' . ($want ? 'turned on' : 'turned off') . ': ' . $p['name']);
            }
            $msg = $want ? $p['name'] . ' is offered again.' : $p['name'] . ' is turned off. Parties that already have it keep it.';
            if (bcrf_switch_ajax()) bcrf_switch_json(true, $msg, $want);
            $_SESSION['pkg_flash'] = ['ok', $msg];
        } else {
            throw new BcrfPartyError('Unknown action.');
        }
    } catch (BcrfPartyError $e) {
        if (bcrf_switch_ajax()) bcrf_switch_json(false, $e->getMessage());
        $_SESSION['pkg_flash'] = ['err', $e->getMessage()];
        if ($act === 'save') { $_SESSION['pkg_form'] = $_POST; $back .= $id ? '?edit=' . $id : '?new=1'; }
    } catch (Throwable $e) {
        error_log('[party packages] ' . $act . ': ' . $e->getMessage());
        if (bcrf_switch_ajax()) bcrf_switch_json(false, 'Could not save. Try again.');
        $_SESSION['pkg_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['pkg_flash'] ?? null; unset($_SESSION['pkg_flash']);
$typed = $_SESSION['pkg_form'] ?? null; unset($_SESSION['pkg_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';

$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';

bcrf_shell_open('Party packages', ['toast' => $toast, 'width' => 'max-w-3xl', 'js' => ['quotes.js']]);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Party packages</h1>',
     '<p class="text-sm text-stone-500 mb-4">What each party package includes and costs. A party\'s quote starts from its package\'s price, and the gate holds space for the package\'s length.</p>';
if (!$ready) {
    echo bcrf_shell_notice('warning', 'Run migration 0035 in Settings › Database first.'), '<a href="/admin/migrations.php" class="text-sm text-amber-800 underline">Open Settings › Database</a>';
    bcrf_shell_close(); exit;
}
if ($error) echo bcrf_shell_notice('err', $error);

$editId = (int)($_GET['edit'] ?? 0);
$editing = $mgr && ($editId || isset($_GET['new'])) ? ($editId ? bcrf_party_package($pdo, $editId) : []) : null;
if ($editing !== null && $editId && !$editing) $editing = null;

if ($editing !== null):
    $v = $typed ?? ($editing ? ['name' => $editing['name'], 'inclusions' => $editing['inclusions'], 'base' => $dollars($editing['base_cents']),
          'included_guests' => $editing['included_guests'] ?: '', 'extra' => $dollars($editing['extra_guest_cents']), 'duration' => $editing['duration_minutes'],
          'active' => $editing['active'], 'sort_order' => $editing['sort_order']]
        : ['name' => '', 'inclusions' => '', 'base' => '', 'included_guests' => '', 'extra' => '', 'duration' => 120, 'active' => 1, 'sort_order' => 0]);
?>
  <form method="POST" action="party_packages.php" class="<?= $card ?>">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="save"><input type="hidden" name="id" value="<?= $editing ? (int)$editing['id'] : '' ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-3"><?= $editing ? 'Edit ' . $h($editing['name']) : 'New package' ?></h2>
    <div class="grid grid-cols-1 sm:grid-cols-2 gap-3">
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="pn">Name</label><input id="pn" name="name" maxlength="80" required value="<?= $h($v['name']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="pb">Package price ($, blank for per guest only)</label><input id="pb" name="base" inputmode="decimal" value="<?= $h($v['base']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="pi">Guests included in that price</label><input id="pi" name="included_guests" inputmode="numeric" value="<?= $h($v['included_guests']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="pe">Price per guest ($, or per extra guest)</label><input id="pe" name="extra" inputmode="decimal" value="<?= $h($v['extra']) ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="pd">Length (minutes)</label><input id="pd" name="duration" inputmode="numeric" value="<?= $h($v['duration']) ?>" class="<?= $input ?>"></div>
      <div class="sm:col-span-2"><label class="<?= $label ?>" for="pin">What's included (one per line, shown to staff)</label><textarea id="pin" name="inclusions" rows="4" class="<?= $input ?>"><?= $h($v['inclusions']) ?></textarea></div>
      <div><label class="<?= $label ?>" for="ps">Order in lists</label><input id="ps" name="sort_order" inputmode="numeric" value="<?= $h($v['sort_order']) ?>" class="<?= $input ?>"></div>
      <label class="flex items-center gap-2 text-sm text-stone-700 pt-5"><input type="checkbox" name="active" value="1" <?= !empty($v['active']) ? 'checked' : '' ?>> Offered (shows in the list)</label>
    </div>
    <div class="flex gap-2 mt-4"><button class="<?= $btnMain ?>">Save package</button><a href="party_packages.php" class="<?= $btnSec ?>">Cancel</a></div>
  </form>
<?php endif;

$all = bcrf_party_packages($pdo, true);
if ($mgr && $editing === null) echo '<a href="party_packages.php?new=1" class="', $btnMain, ' inline-block mb-4">Add a package</a>';
if (!$all) echo '<p class="text-sm text-stone-500">No packages yet.</p>';
foreach ($all as $p): ?>
  <div class="<?= $card ?><?= $p['active'] ? '' : ' is-off' ?>" data-switch-row>
    <div class="ui-swmain flex flex-wrap items-baseline justify-between gap-2">
      <h2 class="text-base font-semibold text-stone-800"><?= $h($p['name']) ?></h2>
      <span class="text-sm text-stone-700"><?= $h(bcrf_party_price_text($p)) ?> · <?= $p['duration_minutes'] ?> min</span>
    </div>
    <?php if ($p['items']): ?><ul class="ui-swmain text-sm text-stone-600 mt-1"><?php foreach ($p['items'] as $it): ?><li>• <?= $h($it) ?></li><?php endforeach; ?></ul><?php endif; ?>
    <?php if ($mgr): ?>
    <div class="flex items-center gap-2 mt-3">
      <a href="party_packages.php?edit=<?= $p['id'] ?>" class="<?= $btnSec ?>">Edit</a>
      <?= bcrf_switch(['action' => 'party_packages.php', 'csrf' => $csrf, 'fields' => ['act' => 'toggle', 'id' => (int)$p['id']],
                       'on' => (bool)$p['active'], 'label' => 'Offer ' . $p['name'], 'on_text' => 'Offered', 'off_text' => 'Off']) ?>
    </div>
    <?php elseif (!$p['active']): ?><p class="text-xs text-stone-500 mt-2">Turned off</p>
    <?php endif; ?>
  </div>
<?php endforeach;
bcrf_shell_close();
