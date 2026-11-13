<?php
// admin/kitchen_label_print.php — Kitchen › Labels › Print (Farm OS Phase 4 slice 5, Nov 13, 2026; includes/kitchen_print.php, migration 0055).
//   GET  kitchen_label_print.php?batch=N    a batch's label at true size with its lot and best-by, how many, where to start; history
//        kitchen_label_print.php            printing settings: label stock, type sizes, nudge, logo; test sheet
//        kitchen_label_print.php?test=1     the test sheet PDF (every position outlined)
//   POST act=pdf (batch, copies, start)     records the run and sends the PDF; refused if the label isn't approved for the
//                                           batch's recipe version or the text doesn't fit
//        act=settings                       saves the printing settings
// Permission: table 'recipes' (view to look, edit to print). Settings need "Approve kitchen labels" (Owners always).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_print.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'recipes', 'edit');
$canSettings = hasViewAccess($role, bcrf_kitchen_label_perm());

if (!bcrf_kitchen_batches_ready($pdo) || !bcrf_kitchen_labels_ready($pdo) || !bcrf_kitchen_print_ready($pdo)) {
    bcrf_shell_open('Print labels');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Print labels</h1>', bcrf_shell_notice('warning', 'Run migrations 0053, 0054 and 0055 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

$sendPdf = function (string $pdf, string $name): void {
    header('Content-Type: application/pdf');
    header('Content-Disposition: inline; filename="' . preg_replace('/[^A-Za-z0-9._-]/', '-', $name) . '"');
    header('Content-Length: ' . strlen($pdf));
    header('Cache-Control: private, no-store');
    echo $pdf; exit;
};

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $bid = (int)($_POST['batch'] ?? 0);
    $back = $bid ? 'kitchen_label_print.php?batch=' . $bid : 'kitchen_label_print.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if ($act === 'pdf') {
            if (!$canEdit) throw new BcrfKitchenError('You can see labels but not print them.');
            $logo = bcrf_kitchen_print_logo();
            $plan = bcrf_kitchen_print_plan($pdo, $bid, $logo);
            $copies = (int)($_POST['copies'] ?? 0); $start = (int)($_POST['start'] ?? 1);
            $pid = bcrf_kitchen_print_record($pdo, $plan, $copies, $start, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'kitchen_label_prints', $pid, 'Printed ' . $copies . ' labels for lot ' . $plan['batch']['lot_code']);
            $sendPdf(bcrf_kitchen_print_pdf($plan['layout'], $plan['cfg'], $copies, $start, $plan['cfg']['logo'] ? $logo : null), 'labels-' . $plan['batch']['lot_code'] . '.pdf');
        } elseif ($act === 'settings') {
            if (!$canSettings) throw new BcrfKitchenError('Only Owners (or roles given "Approve kitchen labels") can change printing settings.');
            bcrf_kitchen_print_settings_save($pdo, $_POST);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 'kitchen_label_print', 'Kitchen label printing settings changed');
            $_SESSION['kp_flash'] = ['ok', 'Printing settings saved. Print a test sheet to check the line-up.'];
        } else {
            throw new BcrfKitchenError('Unknown action.');
        }
    } catch (BcrfKitchenError $e) {
        $_SESSION['kp_flash'] = ['err', $e->getMessage()];
        $_SESSION['kp_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_label_print] ' . $act . ': ' . $e->getMessage());
        $_SESSION['kp_flash'] = ['err', 'Could not do that. Try again.'];
    }
    header('Location: ' . $back); exit;
}

if (!empty($_GET['test'])) $sendPdf(bcrf_kitchen_print_test_pdf(bcrf_kitchen_print_settings($pdo)), 'label-test-sheet.pdf');

$flash = $_SESSION['kp_flash'] ?? null; unset($_SESSION['kp_flash']);
$form = $_SESSION['kp_form'] ?? null; unset($_SESSION['kp_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$when = fn($utc) => $utc ? date('M j, Y g:i A', strtotime($utc . ' UTC')) : '';

// ── One batch ──
$bid = (int)($_GET['batch'] ?? 0);
if ($bid) {
    $logo = bcrf_kitchen_print_logo();
    try { $plan = bcrf_kitchen_print_plan($pdo, $bid, $logo); }
    catch (BcrfKitchenError $e) { $_SESSION['kb_flash'] = ['err', $e->getMessage()]; header('Location: kitchen_batches.php'); exit; }
    $b = $plan['batch']; $cfg = $plan['cfg']; $lay = $plan['layout'];
    $per = bcrf_kitchen_print_per_sheet($cfg);
    $prints = bcrf_kitchen_prints_for($pdo, $bid);
    bcrf_shell_open('Print labels · lot ' . $b['lot_code'], ['toast' => $toast, 'width' => 'max-w-4xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_batches.php?id=', $bid, '">← Lot ', $h($b['lot_code']), '</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">Print labels</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">Lot <span class="font-mono">', $h($b['lot_code']), '</span> · ', $h(bcrf_kitchen_q($b['units_made'])), ' × ', $h($b['product_name']),
         ' · <a class="underline text-amber-800" href="kitchen_label_print.php">', $h($cfg['name']), '</a></p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    foreach ($plan['blockers'] as $m) echo bcrf_shell_notice('warning', $m);
    foreach ($plan['warnings'] as $m) echo bcrf_shell_notice('warning', $m);
    if ($lay) foreach ($lay['problems'] as $m) echo bcrf_shell_notice('err', $m);
    ?>
  <?php if ($lay): ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-1">The label</h2>
      <p class="text-xs text-stone-500 mb-2">Approved label #<?= (int)$plan['label']['id'] ?> (recipe version <?= (int)$plan['label']['version_no'] ?>). Shown at true size on most screens; the PDF is exact.</p>
      <?= bcrf_kitchen_label_svg($lay, $cfg['logo'] ? $logo : null) ?>
      <p class="text-xs text-stone-500 mt-2">Body text <?= $h(bcrf_kitchen_q((float)$cfg['body_pt'])) ?> pt, net quantity <?= $h(bcrf_kitchen_q((float)$cfg['net_pt'])) ?> pt.<?= $lay['fits'] ? ' Space left for text: about ' . (int)max(0, floor(($lay['room'] - $lay['used']) / ((float)$cfg['body_pt'] * 1.22))) . ' lines.' : '' ?></p>
    </div>
    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-2">Print</h2>
      <?php if (!$canEdit): ?>
        <p class="text-sm text-stone-600">You can see this label but not print it.</p>
      <?php elseif (!$lay['fits']): ?>
        <p class="text-sm text-stone-600">Fix the problem above first. Type is never shrunk to make text fit.</p>
      <?php else: ?>
      <form method="POST" target="_blank" class="space-y-3"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="pdf"><input type="hidden" name="batch" value="<?= $bid ?>">
        <div><label class="<?= $label ?>" for="pc">How many labels</label><input id="pc" name="copies" type="number" min="1" max="500" required value="<?= $h($form['copies'] ?? $plan['copies_default']) ?>" class="<?= $input ?>"></div>
        <div><label class="<?= $label ?>" for="ps">Start at position (for a part-used sheet)</label>
          <select id="ps" name="start" class="<?= $input ?>"><?php for ($i = 1; $i <= $per; $i++): ?><option value="<?= $i ?>"<?= (int)($form['start'] ?? 1) === $i ? ' selected' : '' ?>><?= $i ?><?= $i === 1 ? ' (top left)' : '' ?></option><?php endfor; ?></select>
          <p class="text-xs text-stone-500 mt-1">Positions run across, then down: <?= (int)$cfg['cols'] ?> across, <?= (int)$cfg['rows'] ?> down.</p></div>
        <button class="<?= $btnMain ?>">🖨️ Make the PDF</button>
        <p class="text-xs text-stone-500">Opens in a new tab. Print at <strong>100% / Actual size</strong>, not "Fit to page". Each run is recorded below.</p>
      </form>
      <?php endif; ?>
    </div>
  </div>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Printed</h2>
    <?php if (!$prints): ?>
      <p class="text-sm text-stone-500">No labels printed for this lot yet.</p>
    <?php else: ?>
    <ul class="divide-y divide-stone-100 text-sm">
      <?php foreach ($prints as $p): ?>
      <li class="py-2 flex flex-wrap justify-between gap-2"><span><?= (int)$p['copies'] ?> label<?= (int)$p['copies'] === 1 ? '' : 's' ?> from position <?= (int)$p['start_pos'] ?> · label #<?= (int)$p['label_id'] ?></span>
        <span class="text-xs text-stone-500"><?= $h($when($p['created_at'])) ?><?= trim((string)$p['by_name']) !== '' ? ' by ' . $h($p['by_name']) : '' ?> · <?= $h($p['stock']) ?></span></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
    bcrf_shell_close(); exit;
}

// ── Settings ──
$cfg = bcrf_kitchen_print_settings($pdo);
$v = fn(string $k) => $h($form[$k] ?? (is_float($cfg[$k]) ? bcrf_kitchen_q($cfg[$k]) : $cfg[$k]));
$stocks = bcrf_kitchen_print_stocks();
$isCustom = ($form['stock'] ?? $cfg['stock']) === 'custom';
bcrf_shell_open('Label printing', ['toast' => $toast, 'width' => 'max-w-3xl']);
?>
  <p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_labels.php">← Labels</a></p>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Label printing</h1>
  <p class="text-sm text-stone-600 mb-4">Labels print from a batch (Kitchen › Batches › a lot › Print labels), with its lot code and best-by date filled in. These settings say what label sheets you use and how big the type is.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Test sheet</h2>
    <p class="text-sm text-stone-600 mb-2">Prints every label position outlined on plain paper. Lay it on a label sheet and hold it to the light. If it's off, nudge it below.</p>
    <a class="<?= $btnSec ?> inline-block" href="kitchen_label_print.php?test=1" target="_blank">🖨️ Test sheet (<?= $h($cfg['name']) ?>)</a>
  </div>
  <?php if (!$canSettings): ?>
  <div class="<?= $card ?>"><p class="text-sm">Label stock: <strong><?= $h($cfg['name']) ?></strong> · body text <?= $h(bcrf_kitchen_q((float)$cfg['body_pt'])) ?> pt · net quantity <?= $h(bcrf_kitchen_q((float)$cfg['net_pt'])) ?> pt. Only Owners change these.</p></div>
  <?php else: ?>
  <form method="POST" class="<?= $card ?> space-y-4"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="settings">
    <h2 class="text-base font-semibold text-stone-800">Settings</h2>
    <fieldset>
      <legend class="<?= $label ?>">Label sheets</legend>
      <?php foreach ($stocks as $k => $s): ?>
      <label class="flex items-center gap-2 text-sm mb-1"><input type="radio" name="stock" value="<?= $h($k) ?>"<?= !$isCustom && ($form['stock'] ?? $cfg['stock']) === $k ? ' checked' : '' ?>> <?= $h($s['name']) ?></label>
      <?php endforeach; ?>
      <label class="flex items-center gap-2 text-sm"><input type="radio" name="stock" value="custom"<?= $isCustom ? ' checked' : '' ?>> Other (enter the sizes below, in inches)</label>
    </fieldset>
    <details<?= $isCustom ? ' open' : '' ?> class="border border-stone-200 rounded p-3">
      <summary class="text-sm font-semibold text-stone-700 cursor-pointer">Sizes for "Other" (inches)</summary>
      <p class="text-xs text-stone-500 my-2">Measure from the sheet's edges. Spacing is from one label's left (or top) edge to the next one's.</p>
      <div class="grid grid-cols-2 sm:grid-cols-3 gap-3">
        <div class="col-span-2 sm:col-span-3"><label class="<?= $label ?>" for="sn">Name</label><input id="sn" name="name" maxlength="80" value="<?= $isCustom ? $v('name') : '' ?>" class="<?= $input ?>" placeholder="e.g. Avery 22806"></div>
        <?php foreach (['page_w' => 'Sheet width', 'page_h' => 'Sheet height', 'label_w' => 'Label width', 'label_h' => 'Label height', 'cols' => 'Across', 'rows' => 'Down',
                        'left' => 'Left margin', 'top' => 'Top margin', 'col_pitch' => 'Column spacing', 'row_pitch' => 'Row spacing'] as $k => $t): ?>
        <div><label class="<?= $label ?>" for="s_<?= $k ?>"><?= $t ?></label><input id="s_<?= $k ?>" name="<?= $k ?>" inputmode="decimal" value="<?= $v($k) ?>" class="<?= $input ?>"></div>
        <?php endforeach; ?>
      </div>
    </details>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="<?= $label ?>" for="s_body">Body text (pt)</label><input id="s_body" name="body_pt" inputmode="decimal" value="<?= $v('body_pt') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="s_minb">Smallest allowed (pt)</label><input id="s_minb" name="min_body_pt" inputmode="decimal" value="<?= $v('min_body_pt') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="s_net">Net quantity (pt)</label><input id="s_net" name="net_pt" inputmode="decimal" value="<?= $v('net_pt') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="s_minn">Smallest allowed (pt)</label><input id="s_minn" name="min_net_pt" inputmode="decimal" value="<?= $v('min_net_pt') ?>" class="<?= $input ?>"></div>
    </div>
    <p class="text-xs text-stone-500 -mt-2">The smallest sizes are the rule: 4.5 pt body and 9 pt net quantity until NCDA&amp;CS tells us otherwise. Text that doesn't fit is flagged, never shrunk.</p>
    <div class="grid grid-cols-2 gap-3">
      <div><label class="<?= $label ?>" for="s_nx">Nudge right (inches, minus = left)</label><input id="s_nx" name="nudge_x" inputmode="decimal" value="<?= $v('nudge_x') ?>" class="<?= $input ?>"></div>
      <div><label class="<?= $label ?>" for="s_ny">Nudge down (inches, minus = up)</label><input id="s_ny" name="nudge_y" inputmode="decimal" value="<?= $v('nudge_y') ?>" class="<?= $input ?>"></div>
    </div>
    <label class="flex items-center gap-2 text-sm"><input type="checkbox" name="logo" value="1"<?= ($form ? !empty($form['logo']) : (int)$cfg['logo']) ? ' checked' : '' ?>> Print the farm logo (labels 2″ wide or more)</label>
    <button class="<?= $btnMain ?>">Save settings</button>
  </form>
  <?php endif; ?>
<?php
bcrf_shell_close();
