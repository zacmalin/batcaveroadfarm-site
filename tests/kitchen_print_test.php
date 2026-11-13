<?php
// tests/kitchen_print_test.php — Farm OS Phase 4 slice 5 (Nov 13, 2026): label printing (includes/kitchen_print.php,
// includes/pdf_lite.php, migration 0055) against a real, THROWAWAY MySQL 8 database.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_tasks_test DB_USER=... DB_PASS=... php tests/kitchen_print_test.php
//   PDF_OUT=/some/dir also writes the sample PDFs there, to look at.
// Refuses to run unless DB_NAME ends in "_test". Empties that database and builds it from every migration. Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$GLOBALS['pdo'] = $pdo;
$root = dirname(__DIR__);
ini_set('log_errors', '0');
date_default_timezone_set('America/New_York');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/kitchen_print.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$one = function (string $sql, array $a = []) use ($pdo) { $st = $pdo->prepare($sql); $st->execute($a); return $st->fetchColumn(); };
$throws = function (callable $f, string $what, string $needle = '') use ($ok) {
    try { $f(); $ok(false, $what . ' (no error)'); }
    catch (BcrfKitchenError $e) { $ok($needle === '' || stripos($e->getMessage(), $needle) !== false, $what . ' — got: ' . $e->getMessage()); }
};
$out = getenv('PDF_OUT') ?: '';

$ok(bcrf_kitchen_print_ready($pdo), 'migration 0055 ran');
(require $root . '/admin/migrations/0055_kitchen_label_prints.php')($pdo);
$ok(bcrf_kitchen_print_ready($pdo), 'migration repeats safely');

// -- PDF basics --
$ok(abs(bcrf_pdf_width('Hello', 10) - 22.78) < 0.001 && abs(bcrf_pdf_width('Hello', 10, true) - 24.45) < 0.001, 'Helvetica widths: ' . bcrf_pdf_width('Hello', 10) . ' / ' . bcrf_pdf_width('Hello', 10, true));
$ok(bcrf_pdf_width('Crème brûlée', 10) > 0 && bcrf_pdf_text('Crème') === "Cr\xE8me", 'accented text prints in WinAnsi');
$w = bcrf_pdf_wrap('strawberries, sugar, lemon juice, fruit pectin (citric acid)', 6, 60);
$ok(count($w) > 1 && max(array_map(fn($l) => bcrf_pdf_width($l, 6), $w)) <= 60, 'wrap keeps every line inside the width');
$ok(bcrf_pdf_wrap('Supercalifragilisticexpialidocious', 10, 40) !== [] && max(array_map(fn($l) => bcrf_pdf_width($l, 10), bcrf_pdf_wrap('Supercalifragilisticexpialidocious', 10, 40))) <= 40, 'long word broken by characters');
$m = bcrf_pdf_code128('JAM8');
// Code 128-B: start B (104) + J(42) A(33) M(45) 8(24) + check + stop; check = (104 + 42 + 2*33 + 3*45 + 4*24) % 103 = 443 % 103 = 31
$ok(count($m) === 6 * 6 + 7 && array_sum($m) === 6 * 11 + 13, 'Code 128 bars: 6 symbols + stop');
$ok(implode('', array_slice($m, 6 * 5, 6)) === '212321', 'Code 128 check digit (31) right');
$ok(bcrf_pdf_code128("bad\xC3\xA9") === null && bcrf_pdf_code128('') === null, 'Code 128 refuses non-ASCII / empty');

// -- Settings --
$cfg = bcrf_kitchen_print_settings($pdo);
$ok($cfg['stock'] === 'PLS451' && $cfg['label_w'] == 3 && bcrf_kitchen_print_per_sheet($cfg) === 6 && $cfg['body_pt'] == 6 && $cfg['min_body_pt'] == 4.5 && $cfg['net_pt'] == 10 && $cfg['min_net_pt'] == 9, 'defaults: PLS451, 6 per sheet, 6/4.5 pt, 10/9 pt');
$ok(bcrf_kitchen_print_pos($cfg, 0) === [54.0, 36.0] && bcrf_kitchen_print_pos($cfg, 5) === [342.0, 540.0], 'PLS451 positions: 1 at (0.75″, 0.5″), 6 at (4.75″, 7.5″)');
$throws(fn() => bcrf_kitchen_print_settings_save($pdo, ['stock' => 'PLS451', 'body_pt' => '4']), 'body below minimum refused', 'minimum');
$throws(fn() => bcrf_kitchen_print_settings_save($pdo, ['stock' => 'PLS451', 'net_pt' => '8']), 'net qty below minimum refused', 'minimum');
$throws(fn() => bcrf_kitchen_print_settings_save($pdo, ['stock' => 'PLS451', 'nudge_x' => 'abc']), 'nudge must be a number', 'number');
$throws(fn() => bcrf_kitchen_print_settings_save($pdo, ['stock' => 'custom', 'page_w' => 8.5, 'page_h' => 11, 'label_w' => 4, 'label_h' => 2, 'cols' => 3, 'rows' => 5, 'left' => 0.2, 'top' => 0.5, 'col_pitch' => 4, 'row_pitch' => 2]), 'custom stock that overflows the sheet refused', 'fit on the sheet');
$c2 = bcrf_kitchen_print_settings_save($pdo, ['stock' => 'custom', 'name' => 'Avery 2x4', 'page_w' => 8.5, 'page_h' => 11, 'label_w' => 4, 'label_h' => 2, 'cols' => 2, 'rows' => 5, 'left' => 0.15, 'top' => 0.5, 'col_pitch' => 4.19, 'row_pitch' => 2, 'body_pt' => 7, 'logo' => '1']);
$ok($c2['stock'] === 'custom' && $c2['label_w'] == 4 && bcrf_kitchen_print_per_sheet($c2) === 10 && $c2['body_pt'] == 7 && $c2['logo'] === 1, 'custom stock saved');
$c3 = bcrf_kitchen_print_settings_save($pdo, ['stock' => 'PLS451', 'nudge_y' => '0.05']);
$ok($c3['stock'] === 'PLS451' && $c3['label_w'] == 3 && $c3['nudge_y'] == 0.05 && $c3['logo'] === 0 && bcrf_kitchen_print_pos($c3, 0) === [54.0, 39.6], 'back to PLS451 with a nudge; logo off when unticked');
bcrf_kitchen_print_settings_save($pdo, ['stock' => 'PLS451', 'logo' => '1']);

// -- Layout --
$cfg = bcrf_kitchen_print_settings($pdo);
$lab = ['product_name' => 'Strawberry Jam', 'statement' => 'strawberries, sugar, lemon juice, fruit pectin (citric acid)', 'contains_line' => '',
        'net_qty' => 'Net Wt. 8 oz (227 g)', 'made_by' => 'Made by: Test Farm, 1 Road, Old Fort, NC 28762', 'extra' => 'Made in a home kitchen not inspected by the state.'];
$lay = bcrf_kitchen_label_layout($lab, '261113-JAM8-1', 'Nov 13, 2027', 'JAM8', $cfg, true);
$ok($lay['fits'] && !$lay['problems'] && $lay['w'] == 216 && $lay['h'] == 216, 'jam label fits a 3″ label');
$texts = array_values(array_map(fn($o) => $o[3], array_filter($lay['ops'], fn($o) => $o[0] === 't')));
$ok(in_array('Lot 261113-JAM8-1 · Best by Nov 13, 2027', $texts, true) && in_array('Net Wt. 8 oz (227 g)', $texts, true) && in_array('Ingredients:', $texts, true), 'lot, best by, net qty and ingredients on the label');
$sizes = []; foreach ($lay['ops'] as $o) if ($o[0] === 't') $sizes[$o[3]] = $o[4];
$ok($sizes['Net Wt. 8 oz (227 g)'] == 10 && $sizes['Ingredients:'] == 6, 'net qty at 10 pt, body at 6 pt');
$inside = true;
foreach ($lay['ops'] as $o) {
    if ($o[0] === 't') { $wd = bcrf_pdf_width($o[3], $o[4], $o[5]); if ($o[1] < 0 || $o[1] + $wd > 216.01 || $o[2] > 216 || $o[2] - $o[4] < 0) $inside = false; }
}
$ok($inside, 'every line inside the label');
$ok(count(array_filter($lay['ops'], fn($o) => $o[0] === 'bar')) === 1 && count(array_filter($lay['ops'], fn($o) => $o[0] === 'img')) === 1, 'barcode and logo placed');
$long = $lab; $long['statement'] = str_repeat('organic cane sugar (sugar cane juice), ', 60);
$lay2 = bcrf_kitchen_label_layout($long, '261113-JAM8-1', 'Nov 13, 2027', 'JAM8', $cfg, true);
$ok(!$lay2['fits'] && str_contains($lay2['problems'][0], 'too long') && str_contains($lay2['problems'][0], 'never shrunk'), 'overflow flagged: ' . ($lay2['problems'][0] ?? ''));
$ok(min(array_map(fn($o) => $o[4], array_filter($lay2['ops'], fn($o) => $o[0] === 't' && $o[3] !== 'JAM8'))) >= 6, 'overflowing text not shrunk');
$bad = $cfg; $bad['body_pt'] = 4.0;
$ok(!bcrf_kitchen_label_layout($lab, 'L', '', '', $bad, false)['fits'], 'body below minimum flagged even if set by hand');
$sul = bcrf_kitchen_label_layout($lab + ['sulfites' => true], 'L', '', '', $cfg, false);
$ok(in_array('Contains sulfites.', array_map(fn($o) => $o[3], array_filter($sul['ops'], fn($o) => $o[0] === 't')), true) && $sul['fits'], 'sulfites print on their own when there are no allergens');
$sul2 = bcrf_kitchen_label_layout(['contains_line' => 'Milk', 'sulfites' => true] + $lab, 'L', '', '', $cfg, false);
$ok(str_contains(implode(' ', array_map(fn($o) => $o[3], array_filter($sul2['ops'], fn($o) => $o[0] === 't'))), 'Contains: Milk. Contains sulfites.'), 'sulfites right after the Contains line');
$ok(min(array_map(fn($o) => $o[4], array_filter($sul2['ops'], fn($o) => $o[0] === 't' && str_contains($o[3], 'sulfites')))) >= 4.5, 'sulfites line at least 4.5 pt');
$nolot = bcrf_kitchen_label_layout($lab, '', '', '', $cfg, false);
$ok($nolot['fits'] && !array_filter($nolot['ops'], fn($o) => in_array($o[0], ['bar', 'img'], true)), 'no barcode or logo when not given');

// -- Print a batch --
$pdo->exec("INSERT INTO site_settings (setting_key, setting_value) VALUES ('business_name', 'Test Farm'), ('address_street', '1 Road'), ('address_city', 'Old Fort'), ('address_state', 'NC'), ('address_zip', '28762')
            ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)");
$pdo->exec("INSERT INTO measurement_units (id, name, system_type, abbreviation, unit_type) VALUES (2, 'Ounce', 'US', 'oz', 'Weight'), (4, 'Cup', 'US', 'c', 'Volume'), (9, 'Whole', 'US', NULL, 'Count')");
$pdo->exec("INSERT INTO users (id, first_name, last_name, email, role) VALUES (7, 'Zac', 'M', 'z@example.com', 'administrator')");
$ins = $pdo->prepare("INSERT INTO ingredients (singular_name, plural_name, density_g_per_ml, weight_per_unit_g, allergens) VALUES (?, ?, ?, ?, ?)");
foreach ([['strawberry', 'strawberries', null, 15, ''], ['sugar', 'sugar', 0.85, null, ''], ['butter', 'butter', 0.95, null, 'milk']] as $row) $ins->execute($row);
$ing = array_column($pdo->query("SELECT id, singular_name FROM ingredients")->fetchAll(), 'id', 'singular_name');
$pdo->exec("INSERT INTO recipes (id, title, slug, date_published, status) VALUES (1, 'Strawberry Jam', 'strawberry-jam', '2026-11-12', 'published')");
$ri = $pdo->prepare("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (?, ?, ?, ?)");
$ri->execute([1, $ing['strawberry'], 100, 9]);
$ri->execute([1, $ing['sugar'], 4, 4]);
$pdo->exec("INSERT INTO inventory (id, category, name, price, stock_quantity, size_value, size_unit_id, sku, product_type, track_inventory) VALUES
  (21, 'Pantry', 'Strawberry Jam 8 oz', 8.00, 0, 8, 2, 'JAM8', 'kitchen_good', 1)");
bcrf_kitchen_product_save($pdo, 21, ['recipe_id' => 1, 'units_per_batch' => 12, 'shelf_life_days' => 365], 7);
bcrf_kitchen_save_version($pdo, 1, 7);
$label = bcrf_kitchen_label_approve($pdo, (int)bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7)['id'], 7);
$b = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => '2026-11-10'], 7);
$plan = bcrf_kitchen_print_plan($pdo, (int)$b['id']);
$ok(!$plan['blockers'] && $plan['layout']['fits'] && (int)$plan['label']['id'] === (int)$label['id'] && $plan['copies_default'] === 12, 'batch plan: approved label, fits, 12 copies by default');
$ok(in_array('Lot ' . $b['lot_code'] . ' · Best by Nov 10, 2027', array_map(fn($o) => $o[3], array_filter($plan['layout']['ops'], fn($o) => $o[0] === 't')), true), 'lot and best-by from the batch');
$throws(fn() => bcrf_kitchen_print_record($pdo, $plan, 0, 1, 7), 'zero copies refused', 'between 1 and 500');
$throws(fn() => bcrf_kitchen_print_record($pdo, $plan, 8, 7, 7), 'start past the sheet refused', '1 to 6');
$pid = bcrf_kitchen_print_record($pdo, $plan, 8, 5, 7);
$pr = bcrf_kitchen_prints_for($pdo, (int)$b['id']);
$ok($pid > 0 && count($pr) === 1 && (int)$pr[0]['copies'] === 8 && (int)$pr[0]['start_pos'] === 5 && (int)$pr[0]['label_id'] === (int)$label['id'] && $pr[0]['by_name'] === 'Zac M', 'print run recorded');
$pdf = bcrf_kitchen_print_pdf($plan['layout'], $plan['cfg'], 8, 5, null);
$ok(str_starts_with($pdf, '%PDF-1.4') && str_ends_with($pdf, "%%EOF\n") && str_contains($pdf, '/Count 2 ') && str_contains($pdf, '/MediaBox [0 0 612 792]'), '8 labels from position 5 = 2 letter pages');
$ok(preg_match('/startxref\n(\d+)\n/', $pdf, $mm) && substr($pdf, (int)$mm[1], 4) === 'xref', 'xref offset right');
$okOffsets = true;
if (preg_match_all('/^(\d{10}) 00000 n $/m', $pdf, $offs)) foreach ($offs[1] as $i => $o) if (!preg_match('/^\d+ 0 obj/', substr($pdf, (int)$o, 12))) $okOffsets = false;
$ok($okOffsets, 'every object offset points at its object');
$jpg = null;
if (function_exists('imagecreatetruecolor')) { $im = imagecreatetruecolor(20, 20); ob_start(); imagejpeg($im); $jpg = ob_get_clean(); }
$pdfImg = bcrf_kitchen_print_pdf(bcrf_kitchen_print_plan($pdo, (int)$b['id'], $jpg)['layout'], $plan['cfg'], 2, 1, $jpg);
$ok($jpg === null || (str_contains($pdfImg, '/Subtype /Image') && substr_count($pdfImg, '/Subtype /Image') === 1), 'logo embedded once, used on every label');
$test = bcrf_kitchen_print_test_pdf($plan['cfg']);
$ok(str_contains($test, '/Count 1 '), 'test sheet is one page');
$svg = bcrf_kitchen_label_svg($plan['layout'], null);
$ok(str_starts_with($svg, '<svg') && str_contains($svg, 'width="3in"') && str_contains($svg, htmlspecialchars('Lot ' . $b['lot_code'], ENT_QUOTES)), 'on-screen preview at true size');
if ($out !== '') { file_put_contents($out . '/labels.pdf', $pdfImg); file_put_contents($out . '/labels8.pdf', $pdf); file_put_contents($out . '/test.pdf', $test); }

// Blockers: version mismatch, no label, voided
$pdo->exec("INSERT INTO recipe_ingredients (recipe_id, ingredient_id, quantity, unit_id) VALUES (1, " . (int)$ing['butter'] . ", 0.25, 4)");
bcrf_kitchen_save_version($pdo, 1, 7);
$p2 = bcrf_kitchen_print_plan($pdo, (int)$b['id']);
$ok($p2['blockers'] && str_contains($p2['blockers'][0], 'no approved label'), 'recipe changed: no approved label, can\'t print');
bcrf_kitchen_label_approve($pdo, (int)bcrf_kitchen_label_draft($pdo, 21, 'Strawberry Jam', 7)['id'], 7);
$p3 = bcrf_kitchen_print_plan($pdo, (int)$b['id']);
$ok($p3['blockers'] && str_contains($p3['blockers'][0], 'version 1') && str_contains($p3['blockers'][0], 'version 2'), 'old batch vs new label: blocked');
$throws(fn() => bcrf_kitchen_print_record($pdo, $p3, 6, 1, 7), 'blocked plan can\'t be recorded', 'version');
$b2 = bcrf_kitchen_batch_record($pdo, 21, ['batches' => 1, 'made_on' => '2026-11-10'], 7);
$ok(!bcrf_kitchen_print_plan($pdo, (int)$b2['id'])['blockers'], 'new batch under the new label prints');
bcrf_kitchen_batch_void($pdo, (int)$b2['id'], 'test', 7);
$ok(str_contains(bcrf_kitchen_print_plan($pdo, (int)$b2['id'])['blockers'][0] ?? '', 'voided'), 'voided batch can\'t print');
$throws(fn() => bcrf_kitchen_print_plan($pdo, 9999), 'missing batch', 'doesn\'t exist');

echo $fails ? "$fails of $n checks failed\n" : "All $n checks passed\n";
exit($fails ? 1 : 0);
