<?php
// admin/documents.php — Invoices & receipts (Nov 14, 2026; includes/documents.php, migration 0066).
//   GET  documents.php[?show=all|inbox|expense|missing|deleted][&q=][&from=&to=]   the list + "Add invoice or receipt"
//   GET  documents.php?id=N                                                      one document: pages, details, add pages
//   POST act=upload    new document (or doc_id=N: more pages on it); back= returns to a receive / order / expense page
//        act=save      change the details          act=delete | restore            owner only (nothing is ever removed)
//        act=remove_file | restore_file            owner only, one page
// Permission: inventory edit (people who receive stock) or the animal_expenses view; each document is checked again.
// Nov 14, 2026 (later; migration 0067): papers on vendors (insurance certificate, W-9, contract) and animals (vet record,
// registration) with an expiry date; Papers and "Running out" tabs; Download CSV (admin/export.php?what=documents).
// Animal papers can be opened by anyone who can open animals.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit'], ['view', 'animal_expenses'], ['table', 'animals']]);
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/documents.php';
require_once __DIR__ . '/../includes/record_tools.php';   // flash keys, CSV link (Nov 14, 2026)
if (is_file(__DIR__ . '/../includes/vendors.php')) require_once __DIR__ . '/../includes/vendors.php';
require_once __DIR__ . '/../includes/admin_shell.php';
require_once __DIR__ . '/../includes/list_sort.php';      // Sort box on the list (Nov 15, 2026)
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$uid = (int)($_SESSION['user_id'] ?? 0) ?: null;
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

if (!bcrf_docs_ready($pdo)) {
    bcrf_shell_open('Invoices & receipts');
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-4">Invoices &amp; receipts</h1>', bcrf_shell_notice('warning', 'Run migration 0066 in Settings › Database first.');
    bcrf_shell_close(); exit;
}

/** Only come back to our own admin pages. */
$safeBack = function ($b): string {
    $b = (string)$b;
    return preg_match('#^/admin/(receive|purchase_orders|animal_expenses|animals|vendors|documents)\.php(\?[A-Za-z0-9_=&%.\-]*)?$#', $b) ? $b : '';
};

// ── POST ──────────────────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    $back = $safeBack($_POST['back'] ?? '');
    $go = $back ?: '/admin/documents.php';
    try {
        if (!$_POST && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
            throw new BcrfDocError('That was too much to upload at once (the server limit is ' . ini_get('post_max_size') . '). Send fewer or smaller files.');
        }
        if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) throw new BcrfDocError('Security token expired; try again.');
        $files = bcrf_doc_files_from($_FILES, ['doc_camera', 'doc_files']);
        if ($act === 'upload') {
            $docId = (int)($_POST['doc_id'] ?? 0);
            if ($docId) {
                $d = bcrf_doc_get($pdo, $docId);
                if (!bcrf_doc_can_see($d) || $d['deleted_at']) throw new BcrfDocError('You can\'t add pages to that document.');
                if (!$files) throw new BcrfDocError('Take a photo or choose a file first.');
                $r = bcrf_doc_add_files($pdo, $docId, $files, $uid);
                $r['id'] = $docId;
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'purchase_docs', $docId, 'Document: ' . $r['saved'] . ' page(s) added');
                $msg = $r['saved'] === 1 ? 'Page added.' : $r['saved'] . ' pages added.';
                $go = '/admin/documents.php?id=' . $docId;
            } else {
                $r = bcrf_doc_create($pdo, $_POST, $files, $uid);
                $d = bcrf_doc_get($pdo, $r['id']);
                if (function_exists('erp_audit')) erp_audit($pdo, 'create', 'purchase_docs', $r['id'], bcrf_doc_line($d));
                $msg = 'Saved ' . strtolower(BCRF_DOC_TYPES[$d['doc_type']] ?? 'document') . ($r['saved'] > 1 ? ' (' . $r['saved'] . ' pages)' : '') . ($d['link_type'] ? '.' : ' to the Inbox. Link it when you know what it\'s for.');
                if (!$back) $go = '/admin/documents.php?id=' . $r['id'];
            }
            if ($r['dupes']) {
                $_SESSION['doc_flash'] = ['warning', $msg . ' Heads up: the same file is already saved on document #' . implode(', #', $r['dupes']) . '. Delete one if it was uploaded twice.'];
            } else {
                $_SESSION['doc_flash'] = ['ok', $msg];
            }
        } elseif ($act === 'save') {
            $id = (int)($_POST['id'] ?? 0);
            bcrf_doc_update($pdo, $id, $_POST, $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'purchase_docs', $id, bcrf_doc_line(bcrf_doc_get($pdo, $id)));
            $_SESSION['doc_flash'] = ['ok', 'Details saved.'];
            $go = '/admin/documents.php?id=' . $id;
        } elseif ($act === 'delete' || $act === 'restore') {
            $id = (int)($_POST['id'] ?? 0);
            bcrf_doc_set_deleted($pdo, $id, $act === 'delete', $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, $act === 'delete' ? 'delete' : 'update', 'purchase_docs', $id, 'Document ' . ($act === 'delete' ? 'deleted' : 'restored'));
            $_SESSION['doc_flash'] = ['ok', $act === 'delete' ? 'Deleted. Owners can still find it under Deleted and restore it.' : 'Restored.'];
            $go = '/admin/documents.php?id=' . $id;
        } elseif ($act === 'remove_file' || $act === 'restore_file') {
            $docId = bcrf_doc_file_set_deleted($pdo, (int)($_POST['file_id'] ?? 0), $act === 'remove_file', $uid);
            if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'purchase_docs', $docId, 'Document page ' . ($act === 'remove_file' ? 'removed' : 'restored'));
            $_SESSION['doc_flash'] = ['ok', $act === 'remove_file' ? 'Page removed. It can be put back below.' : 'Page put back.'];
            $go = '/admin/documents.php?id=' . $docId;
        } else {
            throw new BcrfDocError('Unknown action.');
        }
    } catch (BcrfDocError $e) {
        $_SESSION['doc_flash'] = ['err', $e->getMessage()];
        $id = (int)($_POST['id'] ?? ($_POST['doc_id'] ?? 0));
        if (!$back && $id) $go = '/admin/documents.php?id=' . $id;
    } catch (Throwable $e) {
        error_log('[documents] ' . $act . ': ' . $e->getMessage());
        $_SESSION['doc_flash'] = ['err', 'Could not save. Try again.'];
    }
    // Pages that show their own message read it from their own flash key
    if ($back && !str_starts_with($back, '/admin/documents.php')) {
        $key = bcrf_record_flash_key($back) ?: 'ax_flash';
        $fl = $_SESSION['doc_flash'];
        if ($fl[0] === 'warning' && in_array($key, ['an_flash', 'vend_flash'], true)) $fl[0] = 'ok';   // those pages show ok / err only
        $_SESSION[$key] = $fl; unset($_SESSION['doc_flash']);
        if ($fl[0] === 'err') $_SESSION['doc_inline_flash'] = ['back' => $back, 'message' => $fl[1]];
        if (in_array($key, ['an_flash', 'vend_flash'], true) && !str_contains($go, '#')) $go .= '#papers';
    }
    header('Location: ' . $go); exit;
}

// ── Page ──────────────────────────────────────────────────────────────────────
$flash = $_SESSION['doc_flash'] ?? null; unset($_SESSION['doc_flash']);
$isOwner = bcrf_doc_is_owner();
$canStock = bcrf_doc_can_stock();
$canPapers = $canStock || bcrf_doc_can_animal_papers();
$hasExpiry = bcrf_docs_expiry_ready($pdo);
$vendors = ($canStock && function_exists('bcrf_vendor_options')) ? bcrf_vendor_options($pdo) : [];
$linkChoices = bcrf_doc_link_choices($pdo);
$input   = 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full';
$label   = 'block text-xs font-semibold text-stone-600 mb-1';
$btnMain = 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold';
$btnSec  = 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white';
$card    = 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4';
$hidden  = '<input type="hidden" name="csrf_token" value="' . $h($csrf) . '">';
$local   = fn($utc) => $utc ? (new DateTimeImmutable((string)$utc, new DateTimeZone('UTC')))->setTimezone(new DateTimeZone(date_default_timezone_get()))->format('M j, Y g:i A') : '';
$opt     = fn(string $v, string $text, string $cur) => '<option value="' . $h($v) . '"' . ($v === $cur ? ' selected' : '') . '>' . $h($text) . '</option>';

/** The details fields (new upload and edit). $d = current values. */
$fields = function (array $d) use ($h, $input, $label, $opt, $vendors, $linkChoices, $canStock, $hasExpiry): string {
    $cur = $d['link_type'] ? ($d['link_type'] === 'expense' ? 'expense' : $d['link_type'] . ':' . (int)$d['link_id']) : '';
    $o = '<div class="grid grid-cols-1 sm:grid-cols-3 gap-3 mb-3">';
    $o .= '<div><label class="' . $label . '" for="f_type">What is it</label><select id="f_type" name="doc_type" class="' . $input . '">';
    foreach (BCRF_DOC_TYPES as $k => $v) $o .= $opt($k, $v, (string)$d['doc_type']);
    $o .= '</select></div>';
    $o .= '<div class="sm:col-span-2"><label class="' . $label . '" for="f_link">What it\'s for</label><select id="f_link" name="link" class="' . $input . '">';
    if ($canStock) { $o .= $opt('', 'Not sure yet (Inbox)', $cur) . $opt('expense', 'Other expense (fuel, repairs, utilities…)', $cur); }
    $found = $cur === '' || $cur === 'expense';
    foreach ($linkChoices as $group => $items) {
        $o .= '<optgroup label="' . $h($group) . '">';
        foreach ($items as $v => $t) { $o .= $opt($v, $t, $cur); if ($v === $cur) $found = true; }
        $o .= '</optgroup>';
    }
    if (!$found) $o .= $opt($cur, bcrf_doc_link_label($d), $cur);   // older record not in the recent list
    $o .= '</select></div>';
    $o .= '<div><label class="' . $label . '" for="f_cat">Expense type (if other expense)</label><select id="f_cat" name="expense_category" class="' . $input . '">';
    foreach (BCRF_DOC_EXPENSE_CATS as $k => $v) $o .= $opt($k, $v, (string)($d['expense_category'] ?? 'other'));
    $o .= '</select></div>';
    if ($vendors) {
        $o .= '<div><label class="' . $label . '" for="f_vendor">Vendor</label><select id="f_vendor" name="vendor_id" class="' . $input . '">' . $opt('', '— not listed —', (string)($d['vendor_id'] ?? ''));
        foreach ($vendors as $k => $v) $o .= $opt((string)$k, $v, (string)($d['vendor_id'] ?? ''));
        $o .= '</select></div>';
    }
    $o .= '<div><label class="' . $label . '" for="f_vname">' . ($vendors ? 'Or store name' : 'Store / vendor') . '</label><input id="f_vname" name="vendor_name" maxlength="120" class="' . $input . '" value="' . $h($d['vendor_name'] ?? '') . '" placeholder="e.g. Tractor Supply"></div>';
    $o .= '<div><label class="' . $label . '" for="f_date">Date on it</label><input id="f_date" name="doc_date" type="date" class="' . $input . '" value="' . $h($d['doc_date'] ?? '') . '"></div>';
    $o .= '<div><label class="' . $label . '" for="f_total">Total ($)</label><input id="f_total" name="total" inputmode="decimal" class="' . $input . '" value="' . $h($d['total_cents'] !== null && $d['total_cents'] !== '' ? number_format((int)$d['total_cents'] / 100, 2, '.', '') : '') . '" placeholder="e.g. 212.40"></div>';
    $o .= '<div><label class="' . $label . '" for="f_ref">Invoice / receipt / policy #</label><input id="f_ref" name="ref" maxlength="80" class="' . $input . '" value="' . $h($d['ref'] ?? '') . '"></div>';
    if ($hasExpiry) $o .= '<div><label class="' . $label . '" for="f_exp">Expires <span class="font-normal text-stone-400">papers only</span></label><input id="f_exp" name="expires_on" type="date" class="' . $input . '" value="' . $h($d['expires_on'] ?? '') . '"></div>';
    $o .= '<div class="sm:col-span-3"><label class="' . $label . '" for="f_note">Note</label><input id="f_note" name="note" maxlength="255" class="' . $input . '" value="' . $h($d['note'] ?? '') . '"></div>';
    return $o . '</div>';
};

// ── One document ──────────────────────────────────────────────────────────────
if (!empty($_GET['id'])) {
    try { $d = bcrf_doc_get($pdo, (int)$_GET['id']); } catch (BcrfDocError $e) { $d = null; }
    if (!$d || !bcrf_doc_can_see($d)) {
        bcrf_shell_open('Invoices & receipts');
        echo '<p class="text-sm mb-3"><a class="underline text-amber-800" href="documents.php">← Invoices &amp; receipts</a></p>', bcrf_shell_notice('warning', 'That document was not found, or it isn\'t one you can see.');
        bcrf_shell_close(); exit;
    }
    $files = bcrf_doc_files($pdo, (int)$d['id'], $isOwner);
    $dupes = [];
    foreach ($files as $f) if (!$f['deleted_at']) $dupes = array_merge($dupes, bcrf_doc_dupes($pdo, (string)$f['sha256'], (int)$d['id']));
    $dupes = array_values(array_unique($dupes));
    $live = !$d['deleted_at'];
    bcrf_shell_open('Invoices & receipts', ['width' => 'max-w-4xl', 'toast' => null]);
    echo '<p class="text-sm mb-3"><a class="underline text-amber-800" href="documents.php">← Invoices &amp; receipts</a></p>';
    echo bcrf_shell_flash($flash);
    echo '<h1 class="text-2xl font-bold text-stone-800 mb-1">', $h(BCRF_DOC_TYPES[$d['doc_type']] ?? 'Document'), ' #', (int)$d['id'], '</h1>';
    $lurl = bcrf_doc_link_url($d['link_type'], $d['link_id'] !== null ? (int)$d['link_id'] : null);
    [$xText, $xSoon] = bcrf_doc_expiry($d);
    if ($xText !== '' && $xSoon) echo bcrf_shell_notice('warning', ucfirst($xText) . '. Ask for a new one and add it.');
    echo '<p class="text-sm text-stone-600 mb-4">', $h(bcrf_doc_line($d)), '<br>For: ', $lurl ? '<a class="underline text-amber-800" href="' . $h($lurl) . '">' . $h(bcrf_doc_link_label($d)) . '</a>' : $h(bcrf_doc_link_label($d)),
         '<br><span class="text-xs text-stone-500">Uploaded ', $h($local($d['created_at'])), $d['by_name'] ? ' by ' . $h($d['by_name']) : '', '</span></p>';
    if (!$live) echo bcrf_shell_notice('warning', 'This document is deleted. Only owners can see it.');
    if ($dupes) echo '<p class="text-sm p-3 mb-4 border rounded bg-amber-50 border-amber-200 text-amber-800">The same file is also saved on ',
                     implode(', ', array_map(fn($x) => '<a class="underline" href="documents.php?id=' . (int)$x . '">#' . (int)$x . '</a>', $dupes)), '.</p>';

    // Pages
    echo '<div class="', $card, '"><h2 class="text-base font-semibold text-stone-800 mb-3">Pages</h2>';
    if (!$files) echo '<p class="text-sm text-stone-500">No pages.</p>';
    echo '<div class="grid grid-cols-1 sm:grid-cols-2 gap-4">';
    $n = 0;
    foreach ($files as $f) {
        $gone = (bool)$f['deleted_at']; if (!$gone) $n++;
        $src = 'document_file.php?id=' . (int)$f['id'];
        echo '<div class="border border-stone-200 rounded p-2', $gone ? ' opacity-50' : '', '">';
        if ($f['mime'] === 'image/jpeg') echo '<a href="', $h($src), '" target="_blank" rel="noopener"><img class="w-full h-auto rounded" src="', $h($src), '" alt="Page ', $n, '" loading="lazy"></a>';
        else echo '<a class="flex items-center justify-center h-40 rounded bg-stone-50 text-2xl font-bold text-stone-500" href="', $h($src), $f['mime'] === 'image/heic' ? '&amp;dl=1' : '', '" target="_blank" rel="noopener">', $f['mime'] === 'application/pdf' ? 'PDF' : 'HEIC', '</a>';
        echo '<div class="flex flex-wrap gap-2 items-center justify-between mt-2 text-xs text-stone-500"><span>', $gone ? 'Removed · ' : 'Page ' . $n . ' · ', $h($f['original_name']), ' · ', $h(number_format((int)$f['bytes'] / 1024)), ' KB</span>',
             '<span class="flex gap-2"><a class="underline text-amber-800" href="', $h($src), '&amp;dl=1">Download</a>';
        if ($isOwner && $live) {
            echo '<form method="POST" class="inline">', $hidden, '<input type="hidden" name="act" value="', $gone ? 'restore_file' : 'remove_file', '"><input type="hidden" name="file_id" value="', (int)$f['id'], '">',
                 '<button class="underline text-amber-800"', $gone ? '' : ' data-confirm="Remove this page? It stays on the server and can be put back."', '>', $gone ? 'Put back' : 'Remove', '</button></form>';
        }
        echo '</span></div></div>';
    }
    echo '</div>';
    if ($live) {
        echo '<form method="POST" enctype="multipart/form-data" class="mt-4">', $hidden, '<input type="hidden" name="act" value="upload"><input type="hidden" name="doc_id" value="', (int)$d['id'], '">',
             bcrf_doc_inputs_html('doc'), '<button class="', $btnSec, '">Add page(s)</button></form>';
    }
    echo '</div>';

    if ($live) {
        echo '<details class="', $card, '"', ($d['link_type'] ? '' : ' open'), '><summary class="cursor-pointer text-base font-semibold text-stone-800">Details', $d['link_type'] ? '' : ' (link it to something)', '</summary>',
             '<form method="POST" class="mt-3">', $hidden, '<input type="hidden" name="act" value="save"><input type="hidden" name="id" value="', (int)$d['id'], '">',
             $fields($d), '<button class="', $btnMain, '">Save details</button></form></details>';
    }
    if ($isOwner) {
        echo '<form method="POST" class="mb-6">', $hidden, '<input type="hidden" name="id" value="', (int)$d['id'], '">';
        if ($live) echo '<input type="hidden" name="act" value="delete"><button class="', $btnSec, '" data-confirm="Delete this document? It is hidden, not erased; owners can restore it from Deleted.">Delete document</button>';
        else echo '<input type="hidden" name="act" value="restore"><button class="', $btnMain, '">Restore document</button>';
        echo '</form>';
    }
    bcrf_shell_close(); exit;
}

// ── List ──────────────────────────────────────────────────────────────────────
$show = in_array($_GET['show'] ?? '', ['inbox', 'expense', 'missing', 'papers', 'expiring', 'deleted'], true) ? (string)$_GET['show'] : 'all';
if ($show === 'deleted' && !$isOwner) $show = 'all';
if (!$canStock && in_array($show, ['inbox', 'expense', 'missing'], true)) $show = 'all';
if (!$canPapers && in_array($show, ['papers', 'expiring'], true)) $show = 'all';
$expiringN = $canPapers && $hasExpiry ? count(bcrf_doc_list($pdo, ['show' => 'expiring'], 500)) : 0;
$valid = fn($v) => is_string($v) && preg_match('/^\d{4}-\d{2}-\d{2}$/', $v) && DateTimeImmutable::createFromFormat('!Y-m-d', $v)?->format('Y-m-d') === $v;
$q = trim(mb_substr((string)($_GET['q'] ?? ''), 0, 100));
$from = $valid($_GET['from'] ?? null) ? (string)$_GET['from'] : '';
$to = $valid($_GET['to'] ?? null) ? (string)$_GET['to'] : '';
$missing = $canStock ? bcrf_doc_receives_missing($pdo, 50) : [];
$inboxN = $canStock ? count(bcrf_doc_list($pdo, ['show' => 'inbox'], 500)) : 0;
$docDate = 'COALESCE(d.doc_date, DATE(d.created_at))';
$sortOpts = ['default' => [$show === 'expiring' ? 'Runs out soonest' : 'Newest first'],
             'oldest' => ['Oldest first', null, 'asc', "$docDate ASC, d.id ASC"],
             'added' => ['Last added first', null, 'desc', 'd.id DESC'],
             'amount_hi' => ['Amount high to low', null, 'desc', 'd.total_cents IS NULL, d.total_cents DESC, d.id DESC'],
             'amount_lo' => ['Amount low to high', null, 'asc', 'd.total_cents IS NULL, d.total_cents ASC, d.id DESC'],
             'vendor' => ['Vendor A to Z', null, 'asc', "COALESCE(v.name, d.vendor_name, '') = '', COALESCE(v.name, d.vendor_name), $docDate DESC, d.id DESC"]];
$sort = bcrf_list_sort_pick($pdo, 'documents', $sortOpts);
$rows = $show === 'missing' ? [] : bcrf_doc_list($pdo, ['show' => $show, 'q' => $q, 'from' => $from, 'to' => $to, 'order' => bcrf_list_sort_sql($sortOpts, $sort, '')], 100);

bcrf_shell_open('Invoices & receipts', ['width' => 'max-w-4xl']);
echo '<h1 class="text-2xl font-bold text-stone-800 mb-3">Invoices &amp; receipts</h1>';
echo bcrf_shell_flash($flash);
?>
  <form method="POST" enctype="multipart/form-data" class="<?= $card ?>"><?= $hidden ?><input type="hidden" name="act" value="upload">
    <h2 class="text-base font-semibold text-stone-800 mb-3">Add an invoice or receipt</h2>
    <?= bcrf_doc_inputs_html('doc') ?>
    <details class="mb-3"><summary class="cursor-pointer text-sm text-amber-800 underline">Document details (optional, you can add them later)</summary>
      <div class="mt-3"><?= $fields(['doc_type' => $canStock ? 'invoice' : (bcrf_doc_can_animal() ? 'receipt' : 'vet'), 'link_type' => null, 'link_id' => null, 'expense_category' => 'other', 'vendor_id' => null, 'vendor_name' => '', 'doc_date' => '', 'total_cents' => null, 'ref' => '', 'note' => '']) ?></div>
    </details>
    <button class="<?= $btnMain ?>">Upload document</button>
    <p class="text-xs text-stone-500 mt-2">Photos (JPEG, PNG, WebP, HEIC) or PDFs, up to <?= (int)(BCRF_DOC_MAX_BYTES / 1048576) ?> MB each. Several files at once become pages of one document. Saved outside the website and kept in the weekly files backup.</p>
  </form>

  <nav class="flex flex-wrap gap-2 mb-3 text-sm">
    <?php
    $tabs = ['all' => 'All'];
    if ($canStock) { $tabs['inbox'] = 'Inbox' . ($inboxN ? " ($inboxN)" : ''); $tabs['expense'] = 'Other expenses'; $tabs['missing'] = 'Receives with no invoice' . ($missing ? ' (' . count($missing) . ')' : ''); }
    if ($canPapers) { $tabs['papers'] = 'Papers'; if ($hasExpiry) $tabs['expiring'] = 'Running out' . ($expiringN ? " ($expiringN)" : ''); }
    if ($isOwner) $tabs['deleted'] = 'Deleted';
    foreach ($tabs as $k => $t): ?>
    <a class="px-3 py-1 rounded-full border <?= $show === $k ? 'bg-amber-700 border-amber-700 text-white' : 'bg-white border-stone-300 text-stone-700 hover:bg-stone-100' ?>" href="documents.php<?= $k === 'all' ? '' : '?show=' . $k ?>"><?= $h($t) ?></a>
    <?php endforeach; ?>
  </nav>

  <?php if ($show === 'missing'): ?>
  <div class="<?= $card ?>">
    <p class="text-sm text-stone-600 mb-2">Open a receive to add its invoice photo.</p>
    <?php if (!$missing): ?><p class="text-sm text-stone-500">Every receive has an invoice or receipt. 🎉</p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($missing as $r): ?>
      <li class="py-2 text-sm"><a class="underline text-amber-800" href="receive.php?id=<?= (int)$r['id'] ?>">Receive #<?= (int)$r['id'] ?></a> · <?= $h(date('M j, Y', strtotime((string)$r['business_date']))) ?><?= $r['vendor_name'] ? ' · ' . $h($r['vendor_name']) : '' ?><?= $r['invoice_ref'] ? ' · #' . $h($r['invoice_ref']) : '' ?> · <?= $h(bcrf_doc_fmt((int)$r['total_cents'])) ?></li>
      <?php endforeach; ?>
    </ul>
    <?php endif; ?>
  </div>
  <?php else: ?>
  <form method="GET" class="<?= $card ?> flex flex-wrap gap-3 items-end">
    <?php if ($show !== 'all'): ?><input type="hidden" name="show" value="<?= $h($show) ?>"><?php endif; ?>
    <div class="flex-1 min-w-48"><label class="<?= $label ?>" for="dq">Find</label><input id="dq" name="q" value="<?= $h($q) ?>" class="<?= $input ?>" placeholder="Vendor, invoice #, note or amount"></div>
    <div><label class="<?= $label ?>" for="df">From</label><input id="df" name="from" type="date" value="<?= $h($from) ?>" class="<?= $input ?>"></div>
    <div><label class="<?= $label ?>" for="dt">To</label><input id="dt" name="to" type="date" value="<?= $h($to) ?>" class="<?= $input ?>"></div>
    <div><label class="<?= $label ?>" for="dsort">Sort</label><?= bcrf_list_sort_select($sortOpts, $sort, ['id' => 'dsort', 'class' => $input]) ?></div>
    <button class="<?= $btnSec ?>">Apply filters</button>
    <?php if ($q !== '' || $from !== '' || $to !== '' || $sort !== 'default'): ?><a class="<?= $btnSec ?>" href="documents.php<?= $show === 'all' ? '' : '?show=' . urlencode($show) ?>">Reset</a><?php endif; ?>
    <?= bcrf_csv_link_html('documents', ['show' => $show === 'all' ? '' : $show, 'q' => $q, 'from' => $from, 'to' => $to]) ?>
  </form>
  <div class="<?= $card ?>">
    <?php if (!$rows): ?><p class="text-sm text-stone-500"><?= $q !== '' || $from || $to ? 'Nothing matches.' : ($show === 'inbox' ? 'The Inbox is empty.' : ($show === 'expiring' ? 'Nothing runs out in the next ' . BCRF_DOC_EXPIRY_WARN_DAYS . ' days.' : 'Nothing saved yet.')) ?></p><?php else: ?>
    <ul class="divide-y divide-stone-100">
      <?php foreach ($rows as $d): ?>
      <li class="py-2 flex gap-3 items-center">
        <?= bcrf_doc_thumb_html($d) ?>
        <div class="min-w-0">
          <a class="text-sm font-semibold underline text-amber-800" href="documents.php?id=<?= (int)$d['id'] ?>"><?= $h(bcrf_doc_line($d)) ?></a>
          <?php [$xText, $xSoon] = bcrf_doc_expiry($d); if ($xText !== '' && $xSoon): ?><span class="block text-xs font-semibold text-red-700"><?= $h(ucfirst($xText)) ?></span><?php endif; ?>
          <span class="block text-xs text-stone-500"><?= $h(bcrf_doc_link_label($d)) ?> · <?= (int)$d['files_n'] ?> page<?= (int)$d['files_n'] === 1 ? '' : 's' ?><?= $d['by_name'] ? ' · ' . $h($d['by_name']) : '' ?><?= $d['note'] ? ' · ' . $h(mb_strimwidth((string)$d['note'], 0, 60, '…')) : '' ?></span>
        </div>
      </li>
      <?php endforeach; ?>
    </ul>
    <?php if (count($rows) >= 100): ?><p class="text-xs text-stone-500 mt-2">Showing the first 100 (<?= $h(strtolower($sortOpts[$sort][0])) ?>). Use Find or the dates to narrow it down.</p><?php endif; ?>
    <?php endif; ?>
  </div>
  <?php endif; ?>
<?php
bcrf_shell_close();
