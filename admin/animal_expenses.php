<?php
// admin/animal_expenses.php — Animals › Expenses (Farm OS Phase 5 slice 7, Nov 12, 2026; includes/animal_expenses.php, migration 0046).
//   GET  animal_expenses.php?from=&to=&species=     totals for a date range (default: this year so far) by month, species
//                                                   and animal; vet, feed and other; add an expense; recent other expenses
//        &csv=1                                     every line in the range (fixed columns; audited)
//   POST act=add | void                             (also from an animal's Expenses tab: return_animal=N returns there)
// Permission: table 'animals' view plus the 'animal_expenses' view (Owners always; Managers by default). Adding and
// voiding also need animals edit.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/animal_expenses.php';
require_once __DIR__ . '/../includes/admin_shell.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$role = (string)($_SESSION['user_role'] ?? '');
$canEdit = hasTableAccess($role, 'animals', 'edit');
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (!bcrf_expenses_ready($pdo)) {
    bcrf_shell_open('Animal expenses');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animal expenses</h1>', bcrf_shell_notice('warning', 'Run migration 0046 in Settings › Database first.');
    bcrf_shell_close(); exit;
}
if (!bcrf_expenses_can()) {
    bcrf_shell_open('Animal expenses');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Animal expenses</h1>', bcrf_shell_notice('warning', 'Animal costs are for owners and managers. Ask an owner if you need access.');
    bcrf_shell_close(); exit;
}

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $animal = (int)($_POST['return_animal'] ?? 0);
    $back = $animal ? 'animals.php?id=' . $animal . '&tab=expenses' : 'animal_expenses.php';
    $key = $animal ? 'an_flash' : 'ax_flash';
    try {
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfAnimalError('Security token expired; try again.');
        if (!$canEdit) throw new BcrfAnimalError('You can see animal costs but not add or change them.');
        if ($act === 'add') {
            $in = $_POST;
            if ($animal) { $in['for'] = 'animal'; $in['animal_id'] = $animal; }
            $id = bcrf_expense_add($pdo, $in, $uid);
            $e = bcrf_expense_get($pdo, $id);
            if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'animal_expenses', $id, 'Animal expense ' . bcrf_expense_fmt((int)$e['amount_cents']));
            $_SESSION[$key] = ['ok', 'Saved ' . bcrf_expense_fmt((int)$e['amount_cents']) . '.'];
        } elseif ($act === 'void') {
            $eid = (int)($_POST['expense_id'] ?? 0);
            bcrf_expense_void($pdo, $eid, (string)($_POST['reason'] ?? ''), $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'animal_expenses', $eid, 'Animal expense voided');
            $_SESSION[$key] = ['ok', 'Voided. It stays on file.'];
        } else {
            throw new BcrfAnimalError('Unknown action.');
        }
    } catch (BcrfAnimalError $e) {
        $_SESSION[$key] = ['err', $e->getMessage()];
    } catch (Throwable $e) {
        error_log('[animal_expenses] ' . $act . ': ' . $e->getMessage());
        $_SESSION[$key] = ['err', 'Could not save. Try again.'];
    }
    header('Location: ' . $back); exit;
}

$today = bcrf_expense_today();
$valid = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v;
$from = $valid($_GET['from'] ?? null) ? $_GET['from'] : substr($today, 0, 4) . '-01-01';
$to = $valid($_GET['to'] ?? null) ? $_GET['to'] : $today;
if ($to < $from) [$from, $to] = [$to, $from];
$showVet = function_exists('bcrf_health_can') ? bcrf_health_can() : (function_exists('hasViewAccess') && hasViewAccess($role, 'animal_health'));
$lines = bcrf_expense_lines($pdo, $from, $to, 0, $showVet);

if (($_GET['csv'] ?? '') !== '') {
    bcrf_expense_send_csv($pdo, "animal-expenses-$from-to-$to.csv", bcrf_expense_csv_rows($lines), "Animal expenses $from to $to");
}

$t = bcrf_expense_totals($lines);
$recent = bcrf_expense_recent($pdo, 0, 30);
$species = $pdo->query("SELECT id, class_name FROM classifications ORDER BY class_name")->fetchAll(PDO::FETCH_ASSOC);
$animals = bcrf_animals_list($pdo, 'all');
$flash = $_SESSION['ax_flash'] ?? null; unset($_SESSION['ax_flash']);
$toast = ($flash && $flash[0] === 'ok') ? $flash : null;
$error = ($flash && $flash[0] === 'err') ? $flash[1] : '';
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$m = fn($c) => $h(bcrf_expense_fmt((int)$c));
$cols = function (array $r) use ($m): string {
    return '<td class="py-2 px-2 text-right">' . $m($r['vet']) . '</td><td class="py-2 px-2 text-right">' . $m($r['feed']) . '</td><td class="py-2 px-2 text-right">'
         . $m($r['other']) . '</td><td class="py-2 pl-2 text-right font-semibold">' . $m($r['all']) . '</td>';
};
$thead = fn(string $first) => '<thead><tr class="text-left text-xs text-stone-500 border-b border-stone-200"><th class="py-2 pr-2">' . $first
    . '</th><th class="py-2 px-2 text-right">Vet</th><th class="py-2 px-2 text-right">Feed</th><th class="py-2 px-2 text-right">Other</th><th class="py-2 pl-2 text-right">Total</th></tr></thead>';
bcrf_shell_open('Animal expenses', ['toast' => $toast, 'width' => 'max-w-4xl', 'js' => ['quotes.js']]);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Animal expenses</h1>';
if ($error) echo bcrf_shell_notice('err', $error);
?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <div><label class="<?= $label ?>" for="xf">From</label><input id="xf" name="from" type="date" value="<?= $h($from) ?>" class="<?= $input ?>"></div>
    <div><label class="<?= $label ?>" for="xt">To</label><input id="xt" name="to" type="date" value="<?= $h($to) ?>" class="<?= $input ?>"></div>
    <button class="<?= $btnSec ?>">Show</button>
    <a class="<?= $btnSec ?>" href="animal_expenses.php?from=<?= $h($from) ?>&amp;to=<?= $h($to) ?>&amp;csv=1">Download CSV</a>
  </form>

  <div class="grid grid-cols-2 sm:grid-cols-4 gap-3 mb-4">
    <?php foreach (['all' => 'Total', 'vet' => 'Vet', 'feed' => 'Feed', 'other' => 'Other'] as $k => $lbl): ?>
    <div class="bg-white border border-stone-200 rounded-lg p-3 shadow-sm"><div class="text-xs text-stone-500"><?= $h($lbl) ?></div><div class="text-xl font-bold text-stone-800"><?= $m($t['total'][$k]) ?></div></div>
    <?php endforeach; ?>
  </div>
  <p class="text-xs text-stone-500 mb-4">Vet is the cost on medical records. Feed is each feeding's cost (amount × the product's average cost), split across the animals fed. Other is what's added below.</p>

  <?php if ($canEdit): ?>
  <details class="<?= $card ?>" <?= $lines ? '' : 'open' ?>><summary class="cursor-pointer text-base font-semibold text-stone-800">Add an expense</summary>
    <form method="POST" class="mt-3"><?= $hidden ?><input type="hidden" name="act" value="add">
      <div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">
        <div><label class="<?= $label ?>" for="ed">Date</label><input id="ed" name="spent_on" type="date" required max="<?= $h($today) ?>" value="<?= $h($today) ?>" class="<?= $input ?>"></div>
        <div><label class="<?= $label ?>" for="ea">Amount ($)</label><input id="ea" name="amount" inputmode="decimal" required class="<?= $input ?>" placeholder="e.g. 42.50"></div>
        <div><label class="<?= $label ?>" for="ec">What for</label><select id="ec" name="category" class="<?= $input ?>"><?php foreach (BCRF_EXPENSE_CATEGORIES as $k => $v): ?><option value="<?= $k ?>"><?= $h($v) ?></option><?php endforeach; ?></select></div>
        <div class="sm:col-span-2"><label class="<?= $label ?>" for="es">Description</label><input id="es" name="description" maxlength="200" required class="<?= $input ?>" placeholder="e.g. Pine shavings, 10 bags"></div>
        <div><label class="<?= $label ?>" for="ev">Where from (optional)</label><input id="ev" name="vendor" maxlength="120" class="<?= $input ?>" placeholder="e.g. Tractor Supply"></div>
        <div><label class="<?= $label ?>" for="ew">For</label><select id="ew" name="for" class="<?= $input ?>"><option value="all">All animals</option><option value="species">A whole species</option><option value="animal">One animal</option></select></div>
        <div><label class="<?= $label ?>" for="esp">Species (if a whole species)</label><select id="esp" name="classification_id" class="<?= $input ?>"><option value="0">—</option><?php foreach ($species as $s): ?><option value="<?= (int)$s['id'] ?>"><?= $h($s['class_name']) ?></option><?php endforeach; ?></select></div>
        <div><label class="<?= $label ?>" for="ean">Animal (if one animal)</label><select id="ean" name="animal_id" class="<?= $input ?>"><option value="0">—</option><?php foreach ($animals as $a): ?><option value="<?= (int)$a['id'] ?>"><?= $h($a['name']) ?><?= bcrf_animal_is_leaving((string)$a['status']) ? ' (left)' : '' ?></option><?php endforeach; ?></select></div>
      </div>
      <button class="<?= $btnMain ?>">Save expense</button>
    </form>
  </details>
  <?php endif; ?>

  <?php if (!$lines): ?>
  <div class="<?= $card ?>"><p class="text-sm text-stone-500">No costs from <?= $h(date('M j, Y', strtotime($from))) ?> to <?= $h(date('M j, Y', strtotime($to))) ?>.</p></div>
  <?php else: ?>
  <div class="<?= $card ?> overflow-x-auto">
    <h2 class="text-base font-semibold text-stone-800 mb-2">By month</h2>
    <table class="w-full text-sm"><?= $thead('Month') ?><tbody>
      <?php foreach ($t['month'] as $mo => $r): ?><tr class="border-b border-stone-100"><td class="py-2 pr-2"><?= $h(date('M Y', strtotime($mo . '-01'))) ?></td><?= $cols($r) ?></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <div class="<?= $card ?> overflow-x-auto">
    <h2 class="text-base font-semibold text-stone-800 mb-2">By species</h2>
    <table class="w-full text-sm"><?= $thead('Species') ?><tbody>
      <?php foreach ($t['species'] as $sp => $r): ?><tr class="border-b border-stone-100"><td class="py-2 pr-2"><?= $h($sp) ?></td><?= $cols($r) ?></tr><?php endforeach; ?>
    </tbody></table>
    <p class="text-xs text-stone-500 mt-2">Costs for a whole species count under that species; costs for all animals count under "All animals".</p>
  </div>
  <?php if ($t['animal']): ?>
  <div class="<?= $card ?> overflow-x-auto">
    <h2 class="text-base font-semibold text-stone-800 mb-2">By animal</h2>
    <table class="w-full text-sm"><?= $thead('Animal') ?><tbody>
      <?php foreach ($t['animal'] as $aid => $r): ?><tr class="border-b border-stone-100"><td class="py-2 pr-2"><a class="underline text-amber-800" href="animals.php?id=<?= (int)$aid ?>&amp;tab=expenses"><?= $h($r['name']) ?></a><span class="block text-xs text-stone-500"><?= $h($r['species']) ?></span></td><?= $cols($r) ?></tr><?php endforeach; ?>
    </tbody></table>
  </div>
  <?php endif; ?>
  <?php endif; ?>

  <div class="<?= $card ?>">
    <h2 class="text-base font-semibold text-stone-800 mb-2">Other expenses added</h2>
    <?php if (!$recent): ?><p class="text-sm text-stone-500">None yet.</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($recent as $e): ?>
      <li class="py-2 text-sm"><span class="<?= $e['voided_at'] ? 'line-through text-stone-500' : '' ?>"><strong><?= $m($e['amount_cents']) ?></strong> · <?= $h(date('M j, Y', strtotime($e['spent_on']))) ?> · <?= $h(BCRF_EXPENSE_CATEGORIES[$e['category']] ?? $e['category']) ?> · <?= $h($e['description']) ?></span>
        <span class="block text-xs text-stone-500"><?= $h(bcrf_expense_for_text($e)) ?><?= $e['vendor'] ? ' · ' . $h($e['vendor']) : '' ?><?= trim((string)$e['by_name']) !== '' ? ' · ' . $h($e['by_name']) : '' ?><?= $e['voided_at'] ? ' · voided: ' . $h($e['void_reason']) : '' ?></span>
        <?php if ($canEdit && !$e['voided_at']): ?>
        <details class="mt-1"><summary class="cursor-pointer text-xs text-amber-800 underline">Void</summary>
          <form method="POST" class="flex flex-wrap gap-2 items-end mt-2"><?= $hidden ?><input type="hidden" name="act" value="void"><input type="hidden" name="expense_id" value="<?= (int)$e['id'] ?>">
            <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="xv<?= (int)$e['id'] ?>">Why</label><input id="xv<?= (int)$e['id'] ?>" name="reason" maxlength="255" required class="<?= $input ?>" placeholder="e.g. entered twice"></div>
            <button class="<?= $btnSec ?>" data-confirm="Void this expense? It stays on file.">Void</button></form></details>
        <?php endif; ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
<?php
bcrf_shell_close();
