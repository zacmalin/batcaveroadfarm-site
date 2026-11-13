<?php
// admin/kitchen_labels.php — Kitchen › Labels (Farm OS Phase 4 slice 4, Nov 13, 2026; includes/kitchen_labels.php, migration 0054).
//   GET kitchen_labels.php                  every linked product with its label status; the required statement setting
//       kitchen_labels.php?product=N        the label as it would print now, the open draft and the approved label, history
//   POST act=draft | approve | reject | statement
// Permission: table 'recipes' (view to look, edit to draft). Approving, sending back and the statement setting need the
// "Approve kitchen labels" permission (kitchen_labels_approve; Owners always have it).
// Printing (sheet size, type size, lot and best-by filled in) is slice 5.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'recipes']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/kitchen_labels.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'recipes', 'edit');
$canApprove = hasViewAccess($role, bcrf_kitchen_label_perm());

if (!bcrf_kitchen_ready($pdo) || !bcrf_kitchen_versions_ready($pdo) || !bcrf_kitchen_labels_ready($pdo)) {
    bcrf_shell_open('Kitchen labels');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Labels</h1>', bcrf_shell_notice('warning', 'Run migrations 0050, 0051 and 0054 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $pid = (int)($_POST['product'] ?? 0);
    $back = $pid ? 'kitchen_labels.php?product=' . $pid : 'kitchen_labels.php';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfKitchenError('Security token expired; try again.');
        if ($act === 'draft') {
            if (!$canEdit) throw new BcrfKitchenError('You can see labels but not draft them.');
            $l = bcrf_kitchen_label_draft($pdo, $pid, (string)($_POST['product_name'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'kitchen_labels', (int)$l['id'], 'Label drafted for ' . $l['inventory_name'] . ' (recipe version ' . (int)$l['version_no'] . ')');
            $_SESSION['kl_flash'] = ['ok', 'Draft saved. An Owner approves it below.'];
        } elseif ($act === 'approve' || $act === 'reject') {
            if (!$canApprove) throw new BcrfKitchenError('Only Owners (or roles given "Approve kitchen labels") can approve labels.');
            $lid = (int)($_POST['label'] ?? 0);
            $l = $act === 'approve'
                ? bcrf_kitchen_label_approve($pdo, $lid, $uid, (string)($_POST['note'] ?? ''))
                : bcrf_kitchen_label_reject($pdo, $lid, $uid, (string)($_POST['note'] ?? ''));
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'kitchen_labels', $lid, 'Label for ' . $l['inventory_name'] . ($act === 'approve' ? ' approved' : ' sent back: ' . $l['decision_note']));
            $_SESSION['kl_flash'] = ['ok', $act === 'approve' ? 'Label approved. This product can now be batched (and printed, once printing is built).' : 'Sent back with your note.'];
        } elseif ($act === 'statement') {
            if (!$canApprove) throw new BcrfKitchenError('Only Owners (or roles given "Approve kitchen labels") can change the required statement.');
            if (bcrf_kitchen_label_statement_save($pdo, (string)($_POST['statement'] ?? ''))) {
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'site_settings', 'kitchen_label_statement', 'Kitchen label required statement changed');
                $_SESSION['kl_flash'] = ['ok', 'Saved. Approved labels that don\'t carry this statement now need a new approval.'];
            } else {
                $_SESSION['kl_flash'] = ['ok', 'Saved. Nothing changed.'];
            }
        } else {
            throw new BcrfKitchenError('Unknown action.');
        }
    } catch (BcrfKitchenError $e) {
        $_SESSION['kl_flash'] = ['err', $e->getMessage()];
        $_SESSION['kl_form'] = $_POST;
    } catch (Throwable $e) {
        error_log('[kitchen_labels] ' . $act . ': ' . $e->getMessage());
        $_SESSION['kl_flash'] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$flash = $_SESSION['kl_flash'] ?? null; unset($_SESSION['kl_flash']);
$form = $_SESSION['kl_form'] ?? null; unset($_SESSION['kl_form']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnOk   = 'px-4 py-2 rounded text-sm bg-green-700 hover:bg-green-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$chips = [
    'approved' => ['Approved', 'bg-green-100 text-green-800'], 'draft' => ['Waiting for approval', 'bg-amber-100 text-amber-900'],
    'needs' => ['Needs a label', 'bg-red-100 text-red-800'], 'blocked' => ['Recipe not ready', 'bg-stone-200 text-stone-700'],
    'rejected' => ['Sent back', 'bg-red-100 text-red-800'], 'superseded' => ['Superseded', 'bg-stone-200 text-stone-700'],
];
$chip = fn(string $s) => '<span class="inline-block px-2 py-0.5 rounded text-xs ' . ($chips[$s][1] ?? '') . '">' . htmlspecialchars($chips[$s][0] ?? $s) . '</span>';
$when = fn($utc) => $utc ? date('M j, Y g:i A', strtotime($utc . ' UTC')) : '';

// The label as text on a 3″ square, for review (printing with real type sizes is slice 5).
$preview = function (array $l) use ($h): string {
    $o = '<div class="border-2 border-stone-800 rounded-md bg-white p-3 w-full max-w-[18rem] aspect-square overflow-hidden text-[11px] leading-snug text-stone-900">';
    $o .= '<div class="text-base font-bold leading-tight">' . $h($l['product_name']) . '</div>';
    if ($l['net_qty'] !== '') $o .= '<div class="font-semibold mb-1">' . $h($l['net_qty']) . '</div>';
    $o .= '<div><strong>Ingredients:</strong> ' . $h($l['statement']) . '.</div>';
    if ($l['contains_line'] !== '') $o .= '<div><strong>Contains: ' . $h($l['contains_line']) . '.</strong></div>';
    if ($l['made_by'] !== '') $o .= '<div class="mt-1">' . $h($l['made_by']) . '</div>';
    if (trim((string)($l['extra'] ?? '')) !== '') $o .= '<div class="mt-1">' . nl2br($h($l['extra'])) . '</div>';
    $o .= '<div class="mt-1 text-stone-500">Lot: ____________ Best by: ____________</div></div>';
    return $o;
};

// ── One product ──
$pid = (int)($_GET['product'] ?? 0);
if ($pid) {
    try { $s = bcrf_kitchen_label_state($pdo, $pid); }
    catch (BcrfKitchenError $e) { $_SESSION['kl_flash'] = ['err', $e->getMessage()]; header('Location: kitchen_labels.php'); exit; }
    $b = $s['build'];
    $p = $b['product'];
    $history = bcrf_kitchen_labels_for($pdo, $pid);
    bcrf_shell_open($p['name'] . ' — label', ['toast' => $toast, 'width' => 'max-w-4xl']);
    echo '<p class="text-sm mb-2"><a class="underline text-amber-800" href="kitchen_labels.php">← All labels</a></p>';
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h($p['name']), '</h1>';
    echo '<p class="text-sm text-stone-500 mb-4">', $p['recipe_title'] ? 'Made from ' . $h($p['recipe_title']) . ' · <a class="underline text-amber-800" href="kitchen_recipes.php?id=' . (int)$p['recipe_id'] . '">Label check</a> · ' : '',
         '<a class="underline text-amber-800" href="kitchen_products.php?id=', $pid, '">Product link</a></p>';
    if ($error) echo bcrf_shell_notice('err', $error);
    foreach ($b['blockers'] as $m) echo bcrf_shell_notice('warning', $m);
    if ($s['superseded_now']) echo bcrf_shell_notice('warning', 'A label no longer matched the recipe, size or farm details, so it was marked superseded (see History). This product needs a new approved label before it can be batched.');
    ?>
  <div class="grid grid-cols-1 md:grid-cols-2 gap-4">
    <?php if ($s['approved']): $a = $s['approved']; ?>
    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-1">Approved label <?= $chip('approved') ?></h2>
      <p class="text-xs text-stone-500 mb-2">Recipe version <?= (int)$a['version_no'] ?> · approved <?= $h($when($a['decided_at'])) ?><?= trim((string)$a['decided_by_name']) !== '' ? ' by ' . $h($a['decided_by_name']) : '' ?><?= $a['decision_note'] ? ' · ' . $h($a['decision_note']) : '' ?></p>
      <?= $preview($a) ?>
    </div>
    <?php endif; ?>

    <?php if ($s['draft']): $d = $s['draft']; ?>
    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-1">Draft <?= $chip('draft') ?></h2>
      <p class="text-xs text-stone-500 mb-2">Recipe version <?= (int)$d['version_no'] ?> · drafted <?= $h($when($d['created_at'])) ?><?= trim((string)$d['created_by_name']) !== '' ? ' by ' . $h($d['created_by_name']) : '' ?></p>
      <?= $preview($d) ?>
      <?php foreach ($b['issues'] as $m): ?><p class="text-sm text-amber-800 mt-2">⚠ <?= $h($m) ?></p><?php endforeach; ?>
      <?php if ($canApprove): $hard = bcrf_kitchen_label_hard_issues($b['issues']); ?>
      <form method="POST" class="mt-3 space-y-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="product" value="<?= $pid ?>"><input type="hidden" name="label" value="<?= (int)$d['id'] ?>">
        <label class="<?= $label ?>" for="ln">Note (optional to approve, needed to send back)</label>
        <input id="ln" name="note" maxlength="255" class="<?= $input ?>" placeholder="e.g. checked against the jar">
        <div class="flex flex-wrap gap-2">
          <button name="act" value="approve" class="<?= $btnOk ?>"<?= $hard ? ' disabled title="' . $h($hard[0]) . '"' : '' ?>>Approve label</button>
          <button name="act" value="reject" class="<?= $btnSec ?>">Send back</button>
        </div>
        <p class="text-xs text-stone-500">Check every word against the recipe and the jar. Approving makes this the label batches are made and printed under.</p>
      </form>
      <?php else: ?>
      <p class="text-sm text-stone-600 mt-3">Waiting for an Owner to approve it.</p>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php if (!$b['blockers'] && $canEdit): $f = $b['fields'];
        $nameDefault = $form['product_name'] ?? ($s['draft']['product_name'] ?? ($s['approved']['product_name'] ?? (string)($p['recipe_title'] ?: $p['name'])));
        $now = ['product_name' => $nameDefault, 'statement' => $f['statement'], 'contains_line' => $f['contains_line'], 'net_qty' => $f['net_qty'], 'made_by' => $f['made_by'], 'extra' => $f['extra']]; ?>
    <div class="<?= $card ?>">
      <h2 class="text-base font-semibold text-stone-800 mb-1"><?= $s['draft'] ? 'Change the draft' : ($s['approved'] ? 'Rename it (makes a new draft)' : 'Draft a label') ?></h2>
      <p class="text-xs text-stone-500 mb-2">From recipe version <?= (int)$f['version_no'] ?>. Everything except the name comes from the recipe, the product's size, Site Info and the required statement.</p>
      <?php if (!$s['draft'] && !$s['approved']): echo $preview($now); ?>
        <?php foreach ($b['issues'] as $m): ?><p class="text-sm text-amber-800 mt-2">⚠ <?= $h($m) ?></p><?php endforeach; ?>
      <?php endif; ?>
      <form method="POST" class="mt-3 space-y-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="draft"><input type="hidden" name="product" value="<?= $pid ?>">
        <label class="<?= $label ?>" for="lp">Product name on the label</label>
        <input id="lp" name="product_name" maxlength="150" required value="<?= $h($nameDefault) ?>" class="<?= $input ?>" placeholder="The common name of the food, e.g. Strawberry Jam">
        <button class="<?= $btnMain ?>"><?= $s['draft'] ? 'Update the draft' : 'Save draft' ?></button>
      </form>
    </div>
    <?php endif; ?>
  </div>

  <?php if ($history): ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">History</h2>
    <ul class="divide-y divide-stone-100 text-sm">
      <?php foreach ($history as $l): ?>
      <li class="py-2">
        <div class="flex flex-wrap justify-between gap-2"><span>#<?= (int)$l['id'] ?> · <?= $h($l['product_name']) ?> · version <?= (int)$l['version_no'] ?></span><?= $chip($l['status']) ?></div>
        <div class="text-xs text-stone-500">Drafted <?= $h($when($l['created_at'])) ?><?= trim((string)$l['created_by_name']) !== '' ? ' by ' . $h($l['created_by_name']) : '' ?>
          <?= $l['decided_at'] ? ' · ' . ($l['status'] === 'rejected' ? 'sent back' : 'approved') . ' ' . $h($when($l['decided_at'])) . (trim((string)$l['decided_by_name']) !== '' ? ' by ' . $h($l['decided_by_name']) : '') : '' ?>
          <?= $l['decision_note'] ? ' · “' . $h($l['decision_note']) . '”' : '' ?>
          <?= $l['superseded_at'] ? ' · superseded ' . $h($when($l['superseded_at'])) . ': ' . $h($l['superseded_note']) : '' ?></div>
      </li>
      <?php endforeach; ?>
    </ul>
  </div>
  <?php endif; ?>
<?php
    bcrf_shell_close(); exit;
}

// ── The list ──
$list = bcrf_kitchen_label_overview($pdo);
$statement = bcrf_kitchen_label_statement_setting($pdo);
bcrf_shell_open('Kitchen labels', ['toast' => $toast, 'width' => 'max-w-4xl']);
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Labels</h1>
  <p class="text-sm text-stone-600 mb-4">Each product made in the kitchen needs an approved label before it can be batched. Labels are drafted from the recipe's saved version; an Owner checks and approves them. When the recipe, size or farm details change, the label has to be approved again.</p>
  <?php if ($error) echo bcrf_shell_notice('err', $error); ?>
  <?php if (!$list): ?>
    <div class="<?= $card ?> text-center"><p class="text-sm font-semibold text-stone-700">No kitchen products linked yet</p>
      <p class="text-xs text-stone-500 mt-1">Link a product to its recipe in Kitchen › Products first.</p></div>
  <?php else: ?>
  <ul class="bg-white border border-stone-200 rounded-lg divide-y divide-stone-100 shadow-sm mb-4">
    <?php foreach ($list as $r): ?>
    <li>
      <a class="flex flex-wrap items-center justify-between gap-2 px-4 py-3 hover:bg-stone-50" href="kitchen_labels.php?product=<?= $r['id'] ?>">
        <span><span class="font-semibold text-stone-800"><?= $h($r['name']) ?></span> <span class="text-xs text-stone-500"><?= $h($r['recipe_title']) ?></span></span>
        <span><?= $chip($r['status']) ?></span>
      </a>
    </li>
    <?php endforeach; ?>
  </ul>
  <?php endif; ?>
  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-1">Required statement</h2>
    <p class="text-xs text-stone-500 mb-2">Printed on every label, under "Made by". Use the exact wording NCDA&amp;CS requires for home processors once they confirm it. Changing it means every label needs approving again.</p>
    <?php if ($canApprove): ?>
    <form method="POST" class="space-y-2"><input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>"><input type="hidden" name="act" value="statement">
      <textarea name="statement" rows="2" maxlength="500" class="<?= $input ?>" placeholder="Blank until NCDA&amp;CS confirms the wording"><?= $h($form['statement'] ?? $statement) ?></textarea>
      <button class="<?= $btnSec ?>">Save statement</button>
    </form>
    <?php else: ?>
    <p class="text-sm"><?= $statement !== '' ? $h($statement) : '<span class="text-stone-500">None set.</span>' ?></p>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
