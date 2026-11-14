<?php
// tests/purchase_docs_test.php — Invoices & receipts (includes/documents.php, migration 0066, Nov 14, 2026)
// against a real, THROWAWAY MySQL 8 database built from every migration: file checks (photo, PDF, HEIC, refused types,
// size), photos turned upright / shrunk / saved as JPEG, all-or-nothing uploads, linking to receives / orders / animal
// expenses / other expenses / Inbox with details copied over, same-file warning, who sees what, owner-only delete and
// restore, lists, "receives with no invoice", and search.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_docs_test DB_USER=... DB_PASS=... php tests/purchase_docs_test.php
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');
$upload = sys_get_temp_dir() . '/bcrf_docs_test_' . getmypid();
putenv('BCRF_UPLOAD_DIR=' . $upload);
$GLOBALS['bcrf_docs_test_upload'] = true;
define('BCRF_DOC_MAX_BYTES', 2 * 1024 * 1024);   // small limit so the test file stays small

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
(require $root . '/admin/migrations/0066_purchase_docs.php')($pdo);   // safe to repeat

// Roles: Shopkeep receives stock (inventory edit), Manager sees animal costs only, Staff neither.
function hasTableAccess($role, $table, $level = 'view') { return $role === 'administrator' || ($role === 'Shopkeep' && $table === 'inventory'); }
function hasViewAccess($role, $view) { return $role === 'administrator' || ($role === 'moderator' && $view === 'animal_expenses'); }
require_once $root . '/includes/vendors.php';
require_once $root . '/includes/documents.php';
require_once $root . '/includes/search.php';
require_once $root . '/includes/admin_nav.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfDocError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$as = function (string $role) { $_SESSION = ['user_id' => ['administrator' => 1, 'Shopkeep' => 2, 'moderator' => 3, 'Staff' => 4][$role], 'user_role' => $role]; };
$tmpdir = sys_get_temp_dir() . '/bcrf_docs_src_' . getmypid(); @mkdir($tmpdir);
$file = function (string $name, string $bytes) use ($tmpdir): array {
    $p = $tmpdir . '/' . bin2hex(random_bytes(4)) . '-' . $name; file_put_contents($p, $bytes);
    return ['name' => $name, 'type' => 'x', 'tmp_name' => $p, 'error' => UPLOAD_ERR_OK, 'size' => strlen($bytes)];
};
$img = function (int $w, int $h, string $kind = 'jpeg', int $seed = 0): string {
    $im = imagecreatetruecolor($w, $h); imagefill($im, 0, 0, imagecolorallocate($im, 200, 180 + $seed % 50, 150));
    ob_start(); $kind === 'png' ? imagepng($im) : imagejpeg($im, null, 80); return (string)ob_get_clean();
};
$pdfBytes = "%PDF-1.4\n1 0 obj<<>>endobj\ntrailer<<>>\n%%EOF\n";
$heicBytes = "\x00\x00\x00\x18ftypheic\x00\x00\x00\x00mif1heic" . str_repeat("\x00", 64);

$ok(bcrf_docs_ready($pdo), 'migration 0066 ran (twice)');

// -- Setup: people, vendor, location, product, receive, PO, animal expense --
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role, account_status) VALUES
            (1, 'Zac', 'Owner', 'z@x.test', 'administrator', 'active'), (2, 'Sue', 'Shop', 's@x.test', 'Shopkeep', 'active'),
            (3, 'Mo', 'Manager', 'm@x.test', 'moderator', 'active'), (4, 'Sam', 'Staff', 'st@x.test', 'Staff', 'active')");
$vid = bcrf_vendor_save($pdo, null, ['name' => 'Feed Barn', 'is_active' => 1], 1);
$loc = (int)$one("SELECT id FROM locations ORDER BY is_default DESC, id LIMIT 1");
if (!$loc) { $pdo->exec("INSERT INTO locations (code, name, is_default, created_at) VALUES ('main', 'Main', 1, UTC_TIMESTAMP())"); $loc = (int)$pdo->lastInsertId(); }
$pdo->exec("INSERT INTO inventory (name, price, stock_quantity, track_inventory, is_active) VALUES ('Goat feed', 20, 0, 1, 1)");
$prod = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO receives (vendor_id, location_id, invoice_ref, business_date, created_at, created_by) VALUES (?, ?, 'A-1009', '2026-11-10', UTC_TIMESTAMP(3), 2)")->execute([$vid, $loc]);
$rcv = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO receive_lines (receive_id, line_no, product_id, quantity, unit_cost_cents, line_cost_cents, created_at) VALUES (?, 1, ?, 10, 2124, 21240, UTC_TIMESTAMP(3))")->execute([$rcv, $prod]);
$pdo->prepare("INSERT INTO receives (vendor_id, location_id, business_date, created_at, created_by) VALUES (?, ?, '2026-11-12', UTC_TIMESTAMP(3), 2)")->execute([$vid, $loc]);
$rcv2 = (int)$pdo->lastInsertId();
$pdo->prepare("INSERT INTO purchase_orders (vendor_id, status, po_ref, created_at) VALUES (?, 'ordered', 'PO-77', UTC_TIMESTAMP(3))")->execute([$vid]);
$po = (int)$pdo->lastInsertId();
$pdo->exec("INSERT INTO animal_expenses (spent_on, category, description, vendor, amount_cents, created_at, created_by) VALUES ('2026-11-11', 'bedding', 'Pine shavings', 'Tractor Supply', 4250, UTC_TIMESTAMP(), 3)");
$ax = (int)$pdo->lastInsertId();

// -- File checks --
$as('Shopkeep');
$s = bcrf_doc_store_file($file('big.jpg', $img(3000, 1500)));
$ok($s['mime'] === 'image/jpeg' && (int)$s['width'] === 2400 && (int)$s['height'] === 1200 && preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.jpg$#', $s['file']), 'big photo shrunk to 2400 px, saved as .jpg under YYYY/MM');
$ok(is_file($upload . '/documents/' . $s['file']) && (fileperms($upload . '/documents/' . $s['file']) & 0777) === 0600 && is_file($upload . '/documents/.htaccess'), 'saved outside, 0600, deny-all backstop');
$s = bcrf_doc_store_file($file('scan.png', $img(800, 600, 'png')));
$ok($s['mime'] === 'image/jpeg' && (int)$s['width'] === 800 && str_ends_with($s['file'], '.jpg'), 'PNG kept its size, turned into JPEG');
$s = bcrf_doc_store_file($file('inv.pdf', $pdfBytes));
$ok($s['mime'] === 'application/pdf' && str_ends_with($s['file'], '.pdf') && file_get_contents($upload . '/documents/' . $s['file']) === $pdfBytes && $s['sha256'] === hash('sha256', $pdfBytes), 'PDF kept as-is');
$s = bcrf_doc_store_file($file('IMG_0001.HEIC', $heicBytes));
$ok(class_exists('Imagick') ? in_array($s['mime'], ['image/jpeg', 'image/heic'], true) : ($s['mime'] === 'image/heic' && str_ends_with($s['file'], '.heic')), 'HEIC accepted (kept as-is without Imagick)');
$throws(fn() => bcrf_doc_store_file($file('notes.txt', 'hello there')), 'text file refused', 'photo or PDF');
$throws(fn() => bcrf_doc_store_file($file('evil.php', "<?php echo 1;")), 'php refused', 'photo or PDF');
$throws(fn() => bcrf_doc_store_file($file('huge.pdf', '%PDF-' . str_repeat('x', 2 * 1024 * 1024))), 'over the size limit refused', 'too big');
$throws(fn() => bcrf_doc_store_file(['name' => 'x.jpg', 'tmp_name' => '', 'error' => UPLOAD_ERR_INI_SIZE]), 'server size limit message', 'too big');
$throws(fn() => bcrf_doc_store_file($file('empty.pdf', '')), 'empty file refused', 'empty');
$ok(bcrf_doc_file_path(['file' => '../../etc/passwd']) === '' && bcrf_doc_file_path(['file' => '2026/11/' . str_repeat('a', 24) . '.php']) === '', 'only stored names map to a path');
$ok(count(bcrf_doc_files_list(['name' => ['a.jpg', ''], 'type' => ['', ''], 'tmp_name' => ['/t', ''], 'error' => [0, UPLOAD_ERR_NO_FILE], 'size' => [1, 0]])) === 1, 'empty file slots skipped');

// -- Create: Inbox, receive (details copied over), all-or-nothing --
$r = bcrf_doc_create($pdo, ['doc_type' => 'receipt', 'link' => ''], [$file('r1.jpg', $img(400, 300, 'jpeg', 1))], 2);
$d = bcrf_doc_get($pdo, $r['id']);
$ok($d['link_type'] === null && $d['doc_date'] === bcrf_doc_today() && (int)$d['files_n'] === 1 && $r['dupes'] === [], 'Inbox document, today\'s date');
$inbox = $r['id'];
$r = bcrf_doc_create($pdo, ['doc_type' => 'invoice', 'link' => 'receive:' . $rcv], [$file('p1.jpg', $img(400, 300, 'jpeg', 2)), $file('p2.pdf', $pdfBytes)], 2);
$d = bcrf_doc_get($pdo, $r['id']);
$ok($r['saved'] === 2 && (int)$d['vendor_id'] === $vid && $d['ref'] === 'A-1009' && (int)$d['total_cents'] === 21240 && $d['doc_date'] === '2026-11-10' && $d['vendor'] === 'Feed Barn', 'receive invoice: two pages, vendor / # / total / date copied');
$recvDoc = $r['id'];
$ok(bcrf_doc_line($d) === 'Invoice · Feed Barn · Nov 10, 2026 · $212.40 · #A-1009' && bcrf_doc_link_label($d) === 'Receive #' . $rcv, 'list line and link label');
$before = (int)$one("SELECT COUNT(*) FROM purchase_docs");
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'po:' . $po], [$file('ok.jpg', $img(100, 100)), $file('bad.txt', 'nope')], 2), 'one bad file refuses the whole upload', 'photo or PDF');
$ok((int)$one("SELECT COUNT(*) FROM purchase_docs") === $before, 'nothing saved after a refused upload');
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'po:' . $po], [], 2), 'needs a file', 'photo');
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'receive:99999'], [$file('x.pdf', $pdfBytes)], 2), 'missing receive refused', 'not found');
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'bogus'], [$file('x.pdf', $pdfBytes)], 2), 'bad link refused', 'what this is for');
$throws(fn() => bcrf_doc_create($pdo, ['link' => '', 'total' => 'abc'], [$file('x.pdf', $pdfBytes)], 2), 'bad total refused', 'dollars');
$throws(fn() => bcrf_doc_create($pdo, ['link' => '', 'doc_date' => '2026-02-30'], [$file('x.pdf', $pdfBytes)], 2), 'bad date refused', 'real date');

// Same file twice → saved, with a warning
$r = bcrf_doc_create($pdo, ['link' => 'po:' . $po, 'doc_type' => 'other', 'note' => 'quote for winter hay'], [$file('again.pdf', $pdfBytes)], 2);
$ok($r['dupes'] === [$recvDoc] && (int)bcrf_doc_get($pdo, $r['id'])['vendor_id'] === $vid && bcrf_doc_get($pdo, $r['id'])['ref'] === 'PO-77', 'same file warns (names the other document); PO vendor and ref copied');
$poDoc = $r['id'];

// Other expense
$r = bcrf_doc_create($pdo, ['link' => 'expense', 'expense_category' => 'fuel', 'vendor_name' => '  Ingles   Gas ', 'total' => '$61.20', 'doc_date' => '2026-11-13'], [$file('gas.jpg', $img(300, 500, 'jpeg', 3))], 2);
$d = bcrf_doc_get($pdo, $r['id']);
$ok($d['link_type'] === 'expense' && $d['link_id'] === null && $d['expense_category'] === 'fuel' && $d['vendor_name'] === 'Ingles Gas' && (int)$d['total_cents'] === 6120
    && bcrf_doc_link_label($d) === 'Other expense · Fuel', 'other expense: category, typed store name, total');
$gasDoc = $r['id'];
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'expense', 'expense_category' => 'yachts'], [$file('x.pdf', $pdfBytes)], 2), 'unknown expense type refused', 'expense');

// Animal expense: only people who see animal costs
$throws(fn() => bcrf_doc_create($pdo, ['link' => 'animal_expense:' . $ax], [$file('ax.jpg', $img(200, 200))], 2), 'Shopkeep cannot attach to animal expenses', 'can\'t attach');
$as('moderator');
$r = bcrf_doc_create($pdo, ['doc_type' => 'receipt', 'link' => 'animal_expense:' . $ax], [$file('ax.jpg', $img(200, 200, 'jpeg', 4))], 3);
$d = bcrf_doc_get($pdo, $r['id']);
$ok($d['vendor_name'] === 'Tractor Supply' && (int)$d['total_cents'] === 4250 && $d['doc_date'] === '2026-11-11', 'animal receipt: store, amount, date copied');
$axDoc = $r['id'];
$throws(fn() => bcrf_doc_create($pdo, ['link' => ''], [$file('x.pdf', $pdfBytes)], 3), 'animal-only manager cannot use the Inbox', 'animal expense');
$ok(bcrf_doc_map($pdo, 'animal_expense', [$ax, 999]) === [$ax => $axDoc], 'doc map for the expense list');

// -- Who sees what --
$ok(bcrf_doc_can_see(bcrf_doc_get($pdo, $axDoc)) && !bcrf_doc_can_see(bcrf_doc_get($pdo, $recvDoc)), 'manager (animal costs only) sees animal receipts, not stock invoices');
$ok(array_column(bcrf_doc_list($pdo), 'id') === [$axDoc], 'manager list: animal receipts only');
$as('Shopkeep');
$ok(!bcrf_doc_can_see(bcrf_doc_get($pdo, $axDoc)) && bcrf_doc_can_see(bcrf_doc_get($pdo, $recvDoc)), 'Shopkeep sees stock invoices, not animal receipts');
$ids = array_map('intval', array_column(bcrf_doc_list($pdo), 'id'));
$ok(!in_array($axDoc, $ids, true) && in_array($recvDoc, $ids, true) && in_array($inbox, $ids, true), 'Shopkeep list hides animal receipts');
$ok(bcrf_doc_for($pdo, 'animal_expense', $ax) === [], 'Shopkeep gets nothing for an animal expense box');
$as('Staff');
$ok(!bcrf_doc_can_any() && bcrf_doc_list($pdo) === [] && bcrf_doc_receives_missing($pdo) === [], 'Staff sees nothing');
$as('administrator');
$ok(count(bcrf_doc_list($pdo)) === 5, 'owner sees all five');

// -- Lists and filters --
$as('Shopkeep');
$ok(array_column(bcrf_doc_list($pdo, ['show' => 'inbox']), 'id') === [$inbox], 'Inbox tab');
$ok(array_column(bcrf_doc_list($pdo, ['show' => 'expense']), 'id') === [$gasDoc], 'Other expenses tab');
$ok(array_column(bcrf_doc_list($pdo, ['q' => 'ingles']), 'id') === [$gasDoc], 'find by typed store name');
$ok(array_column(bcrf_doc_list($pdo, ['q' => '212.40']), 'id') === [$recvDoc], 'find by amount');
$ok(array_column(bcrf_doc_list($pdo, ['q' => 'A-1009']), 'id') === [$recvDoc], 'find by invoice #');
$ok(array_column(bcrf_doc_list($pdo, ['q' => 'winter hay']), 'id') === [$poDoc], 'find by note');
$ok(array_column(bcrf_doc_list($pdo, ['q' => 'gas.jpg']), 'id') === [$gasDoc], 'find by file name');
$ok(array_column(bcrf_doc_list($pdo, ['from' => '2026-11-13', 'to' => '2026-11-13']), 'id') === [$gasDoc], 'date range');
$ok(array_column(bcrf_doc_receives_missing($pdo), 'id') === [$rcv2], 'receives with no invoice');
$ok(count(bcrf_doc_for($pdo, 'receive', $rcv)) === 1 && (int)bcrf_doc_for($pdo, 'receive', $rcv)[0]['files_n'] === 2, 'receive box shows its invoice');
$ok(isset(bcrf_doc_link_choices($pdo)['Receives']['receive:' . $rcv]) && !isset(bcrf_doc_link_choices($pdo)['Animal expenses']), 'link choices follow permissions');

// -- Link an Inbox document later; more pages --
bcrf_doc_update($pdo, $inbox, ['doc_type' => 'invoice', 'link' => 'receive:' . $rcv2, 'note' => 'found it'], 2);
$d = bcrf_doc_get($pdo, $inbox);
$ok($d['link_type'] === 'receive' && (int)$d['link_id'] === $rcv2 && (int)$d['vendor_id'] === $vid && $d['note'] === 'found it' && (int)$d['updated_by'] === 2, 'Inbox document linked to a receive later');
$ok(bcrf_doc_receives_missing($pdo) === [], 'no receive left without an invoice');
$r = bcrf_doc_add_files($pdo, $inbox, [$file('p2.jpg', $img(300, 300, 'jpeg', 5))], 2);
$ok($r['saved'] === 1 && count(bcrf_doc_files($pdo, $inbox)) === 2, 'page added');

// -- Delete / restore: owner only, nothing erased --
$throws(fn() => bcrf_doc_set_deleted($pdo, $poDoc, true, 2), 'Shopkeep cannot delete', 'owner');
$fid = (int)bcrf_doc_files($pdo, $inbox)[0]['id'];
$throws(fn() => bcrf_doc_file_set_deleted($pdo, $fid, true, 2), 'Shopkeep cannot remove a page', 'owner');
$as('administrator');
bcrf_doc_set_deleted($pdo, $poDoc, true, 1);
$ok(bcrf_doc_get($pdo, $poDoc)['deleted_at'] !== null && is_file(bcrf_doc_file_path(bcrf_doc_files($pdo, $poDoc)[0])), 'deleted = hidden, file still there');
$ok(array_column(bcrf_doc_list($pdo, ['show' => 'deleted']), 'id') === [$poDoc] && !in_array($poDoc, array_column(bcrf_doc_list($pdo), 'id')), 'owner finds it under Deleted only');
$as('Shopkeep');
$ok(!bcrf_doc_can_see(bcrf_doc_get($pdo, $poDoc)) && bcrf_doc_list($pdo, ['show' => 'deleted']) === [], 'others never see deleted documents');
$ok(bcrf_doc_dupes($pdo, hash('sha256', $pdfBytes), $recvDoc) === [], 'deleted documents don\'t count as duplicates');
$as('administrator');
bcrf_doc_set_deleted($pdo, $poDoc, false, 1);
$ok(bcrf_doc_get($pdo, $poDoc)['deleted_at'] === null, 'restored');
bcrf_doc_file_set_deleted($pdo, $fid, true, 1);
$ok(count(bcrf_doc_files($pdo, $inbox)) === 1 && count(bcrf_doc_files($pdo, $inbox, true)) === 2, 'page removed, still listed for owners');
bcrf_doc_file_set_deleted($pdo, $fid, false, 1);
$ok(count(bcrf_doc_files($pdo, $inbox)) === 2, 'page put back');

// -- Search --
$as('Shopkeep');
$g = function (array $res) { foreach ($res['groups'] as $x) if ($x['key'] === 'documents') return $x; return null; };
$res = $g(bcrf_search($pdo, 'ingles', bcrf_search_can_for('Shopkeep')));
$ok($res && $res['rows'][0]['url'] === '/admin/documents.php?id=' . $gasDoc && str_contains($res['rows'][0]['sub'], '$61.20'), 'search finds a receipt by store');
$ok($g(bcrf_search($pdo, '42.50', bcrf_search_can_for('Shopkeep'))) === null, 'search hides animal receipts from Shopkeep');
$res = $g(bcrf_search($pdo, '42.50', bcrf_search_can_for('moderator')));
$ok($res && $res['rows'][0]['url'] === '/admin/documents.php?id=' . $axDoc, 'manager finds the animal receipt by amount');
$ok($g(bcrf_search($pdo, 'ingles', bcrf_search_can_for('Staff'))) === null, 'Staff search finds no documents');
$hrefs = [];
foreach (bcrf_admin_nav_sections() as $sec) foreach ($sec['items'] as $it) foreach ($it['tabs'] as $t) $hrefs[$t[1]] = true;
$ok(isset($hrefs['/admin/documents.php']) && isset(bcrf_admin_nav_keywords()['/admin/documents.php']), 'menu tab and search words');

// Clean up the throwaway files
foreach ([$upload, $tmpdir] as $dir) if (is_dir($dir)) {
    $it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($dir, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
    @rmdir($dir);
}
echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
