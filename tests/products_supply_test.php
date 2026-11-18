<?php
// tests/products_supply_test.php — Products fixes (Nov 15, 2026): the list shows off products by default,
// the "In-house supply" type (stocked, never sold), the "Animal feed" tie to Animal care, and the one vault folder list,
// against a real, THROWAWAY MySQL 8 database built from every migration.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_products_test DB_USER=... DB_PASS=... php tests/products_supply_test.php
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$pdo = new PDO('mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4', getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '',
               [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false]);
$root = dirname(__DIR__);
ini_set('log_errors', '0');
$_SESSION = ['user_id' => 1, 'user_role' => 'administrator'];
$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
require_once $root . '/includes/sales.php';
require_once $root . '/includes/stock.php';
require_once $root . '/includes/products.php';
require_once $root . '/includes/animal_care.php';
require_once $root . '/admin/includes/image_names.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };
$inList = fn(array $rows, int $id) => (bool)array_filter($rows, fn($r) => (int)$r['id'] === $id);

// 1. The type exists and can count stock
$ok(isset(bcrf_product_types()['supply']) && str_starts_with(bcrf_product_type_label('supply'), 'In-house supply'), 'In-house supply type is offered');
$feedId = bcrf_product_save($pdo, null, ['name' => 'Sweet Feed 50 lb', 'product_type' => 'supply', 'price' => '0', 'pricing_mode' => 'each', 'unit' => 'each',
                                          'track_inventory' => '1', 'starting_stock' => '4', 'is_active' => '1'], 1);
$p = bcrf_product_get($pdo, $feedId);
$ok($p['product_type'] === 'supply' && (int)$p['track_inventory'] === 1 && (float)$p['stock_quantity'] === 4.0 && (int)$p['is_active'] === 1, 'supply saves with stock counted and stays On');

// 2. The list shows a product saved with On turned off (the "doesn't show in the list" bug)
$offId = bcrf_product_save($pdo, null, ['name' => 'Old Feed', 'product_type' => 'supply', 'price' => '0', 'pricing_mode' => 'each', 'unit' => 'each'], 1);
$ok($inList(bcrf_product_list($pdo, []), $offId), 'default list includes a product with On turned off');
$ok($inList(bcrf_product_list($pdo, ['status' => 'off']), $offId) && !$inList(bcrf_product_list($pdo, ['status' => 'active']), $offId), 'On only / Turned off only filters still work');
$ok($inList(bcrf_product_list($pdo, ['type' => 'supply']), $feedId), 'type filter finds in-house supplies');
$src = file_get_contents($root . '/admin/products.php');
$ok(str_contains($src, "? \$_GET['status'] : 'all'"), 'products page defaults to all products');

// 3. Never sold: register list, sale check, public pages
$sellSql = "SELECT i.id FROM inventory i WHERE i.is_active = 1 AND i.product_type <> 'supply'";
$ok(!in_array($feedId, array_map('intval', $pdo->query($sellSql)->fetchAll(PDO::FETCH_COLUMN)), true), 'supply filter keeps it out');
foreach (['admin/register.php', 'farm-stand/index.php', 'farm-stand/items/item.php', 'farm-stand/inventory.php', 'sitemap.php',
          'includes/related.php', 'recipes/recipe_detail.php', 'includes/nav.php'] as $f) {
    $ok(str_contains(file_get_contents($root . '/' . $f), "product_type <> 'supply'"), "$f leaves in-house supplies out");
}
$ok(str_contains(file_get_contents($root . '/includes/sales.php'), "=== 'supply') throw new BcrfSaleError"), 'a sale with an in-house supply is refused');

// 4. Feeding can still draw it down (stock pages need is_active = 1, which a supply keeps)
$ok((int)$pdo->query("SELECT COUNT(*) FROM inventory WHERE id = $feedId AND track_inventory = 1 AND is_active = 1")->fetchColumn() === 1, 'supply shows on Stock / Receive / low-stock (active + counted)');

// 5. Animal feed box: adds/removes only this product
bcrf_care_set_feed_ids($pdo, [$offId]);
bcrf_care_update_feed_ids($pdo, [$feedId], [$feedId]);
$ok(bcrf_care_feed_ids($pdo) === [$offId, $feedId] || bcrf_care_feed_ids($pdo) === [$feedId, $offId], 'ticking Animal feed adds it and keeps the others');
bcrf_care_update_feed_ids($pdo, [$feedId], []);
$ok(bcrf_care_feed_ids($pdo) === [$offId], 'unticking removes only this product');

// 6. One vault folder list, with the In-house supplies folder, used by every upload box
$folders = bcrf_image_folders();
$ok(isset($folders['supplies'], $folders['inventory'], $folders['recipies'], $folders['social']), 'folder list has supplies, inventory, recipies, social');
foreach (['admin/views/media.php', 'admin/views/schema.php', 'admin/views/layout/vault_picker.php', 'admin/products.php'] as $f) {
    $ok(str_contains(file_get_contents($root . '/' . $f), 'bcrf_image_folders()'), "$f uses the one folder list");
}
$ok(is_file($root . '/admin/assets/product-photo.js') && str_contains($src, "'product-photo.js'"), 'product photo vault buttons load');

// 6. List thumbnails (Nov 15, 2026): photo or emoji; name words win, then the type
$ok(bcrf_product_emoji('produce', 'Farm Fresh Eggs (dozen)') === '🥚', 'eggs get the egg emoji');
$ok(bcrf_product_emoji('kitchen_good', 'Sourdough Loaf') === '🍞', 'bread gets the bread emoji');
$ok(bcrf_product_emoji('produce', 'Eggplant') === '🥬', 'eggplant is not an egg');
$ok(bcrf_product_emoji('supply', 'Sweet Feed 50 lb') === '🌾' && bcrf_product_emoji('supply', 'Pine shavings') === '🌾', 'in-house supplies get wheat');
$ok(bcrf_product_emoji('admission', 'Farm admission') === '🎟️' && bcrf_product_emoji('nope', 'Thing') === '📦', 'type fallback and unknown fallback');
$ok(str_contains($src, 'class="pthumb"') && str_contains($src, 'loading="lazy"') && str_contains($src, 'data-fallback') && str_contains($src, 'class="pside"'), 'list shows a lazy thumbnail with emoji fallback');

// Vault endpoints (Nov 16, 2026, outside review): the list can't walk out of /img/, uploads only go to known or existing folders.
$gv = file_get_contents($root . '/admin/handlers/get_vault_images.php');
$qu = file_get_contents($root . '/admin/handlers/quick_upload.php');
$ok(str_contains($gv, "\$folder_filter = preg_replace('/[^a-zA-Z0-9_\\-]/', ''") && !str_contains($gv, "\$folder_filter = trim(\$_GET['folder'] ?? '');"), 'vault list: folder is one plain name (no ../)');
$ok(str_contains($qu, 'isset(bcrf_image_folders()[$subfolder])') && strpos($qu, 'isset(bcrf_image_folders()[$subfolder])') < strpos($qu, 'mkdir('), 'upload: unknown folder refused before any folder is made');
$ok(str_contains($qu, "require_permission([['view', 'media'], ['view', 'site_info'], ['table', 'inventory', 'edit']], true)") && str_contains($gv, "['table', 'inventory', 'edit']"), 'vault: still needs Media, Site Info or product editing');

echo "$n checks, $fails failed\n";
exit($fails ? 1 : 0);
