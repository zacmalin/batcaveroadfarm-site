<?php
// tests/brand_assets_test.php — Site Info → Logos & Icon (Nov 18, 2026; includes/brand.php) against a real, THROWAWAY
// MySQL 8 database and a temporary folder standing in for the site root.
//   DB_HOST=127.0.0.1 DB_NAME=bcrf_brand_test DB_USER=... DB_PASS=... php tests/brand_assets_test.php
// Refuses to run unless DB_NAME ends in "_test". Exit 0 = all passed.
if (PHP_SAPI !== 'cli') exit(1);
$db = getenv('DB_NAME') ?: '';
if (!preg_match('/_test$/', $db)) { fwrite(STDERR, "Refusing: DB_NAME must end in _test.\n"); exit(2); }
$dsn = 'mysql:host=' . (getenv('DB_HOST') ?: '127.0.0.1') . ';dbname=' . $db . ';charset=utf8mb4';
$opts = [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION, PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC, PDO::ATTR_EMULATE_PREPARES => false];
$pdo = new PDO($dsn, getenv('DB_USER') ?: 'root', getenv('DB_PASS') ?: '', $opts);
$root = dirname(__DIR__);
ini_set('log_errors', '0');

$pdo->exec('SET FOREIGN_KEY_CHECKS = 0');
foreach ($pdo->query("SELECT table_name AS t, table_type AS k FROM information_schema.tables WHERE table_schema = DATABASE()")->fetchAll() as $r) {
    $pdo->exec('DROP ' . ($r['k'] === 'VIEW' ? 'VIEW' : 'TABLE') . ' `' . str_replace('`', '``', $r['t']) . '`');
}
$pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
require $root . '/includes/migrations.php';
[$built, $log] = bcrf_migrations_run_pending($pdo, null);
if (!$built) { fwrite(STDERR, "Migrations failed:\n" . implode("\n", $log) . "\n"); exit(2); }
$pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('site_url', 'https://farm.example') ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute();

// A throwaway site root with this install's original files
$site = sys_get_temp_dir() . '/bcrf_brand_' . bin2hex(random_bytes(4));
mkdir($site . '/img/logos', 0755, true);
foreach (['/img/logo.png', '/img/logo.webp', '/img/favicon.ico', '/img/icon.svg', '/img/icon.png', '/img/logos/print_square_logo.png'] as $f) file_put_contents($site . $f, 'x');
$GLOBALS['bcrf_brand_root'] = $site;
$GLOBALS['pdo'] = $pdo;

require $root . '/includes/brand.php';

$fails = 0; $n = 0;
$ok = function (bool $c, string $what) use (&$fails, &$n) { $n++; if (!$c) { $fails++; echo "FAIL: $what\n"; } };

// ── Nothing uploaded: every use is this install's current file ──
$ok(bcrf_brand_url('logo_png') === '/img/logo.png', 'header PNG falls back to /img/logo.png');
$ok(bcrf_brand_url('logo_webp') === '/img/logo.webp', 'header WebP falls back to /img/logo.webp');
$ok(bcrf_brand_url('email_logo', true) === 'https://farm.example/img/logo.png', 'email logo is a full URL on the farm site');
$ok(bcrf_brand_url('schema_logo') === '/img/logo.png', 'schema logo falls back to the main logo');
$ok(bcrf_brand_file('print_logo') === $site . '/img/logos/print_square_logo.png', 'labels fall back to the square print logo');
$links = bcrf_brand_icon_links();
$ok(str_contains($links, 'href="/img/favicon.ico"') && str_contains($links, 'href="/img/icon.svg"') && str_contains($links, 'apple-touch-icon" href="/img/icon.png"'), 'tab icon links are the original ones');
$ok(bcrf_brand_logo_size() === [400, 299], 'original header logo size');
$ok(!bcrf_brand_has('logo') && !bcrf_brand_has('round') && !bcrf_brand_has('icon'), 'nothing uploaded yet');

// ICO writer (pure PHP): header says icon, 2 images, PNG data where the directory points
$ico = bcrf_brand_ico([32 => "\x89PNG-b", 16 => "\x89PNG-a"]);
$hd = unpack('vres/vtype/vcount', substr($ico, 0, 6));
$e1 = unpack('Cw/Ch/Cc/Cr/vplanes/vbpp/Vlen/Voff', substr($ico, 6, 16));
$ok($hd['type'] === 1 && $hd['count'] === 2, '.ico header');
$ok($e1['w'] === 16 && $e1['bpp'] === 32 && substr($ico, $e1['off'], $e1['len']) === "\x89PNG-a", '.ico directory points at the 16 px PNG');

if (!function_exists('imagecreatetruecolor')) {
    echo "SKIP: no GD here, upload checks not run\n";
} else {
    // A 1000 × 600 logo with a see-through background and a red box
    $mk = function (int $w, int $h) use ($site): string {
        $im = imagecreatetruecolor($w, $h);
        imagealphablending($im, false); imagesavealpha($im, true);
        imagefilledrectangle($im, 0, 0, $w - 1, $h - 1, imagecolorallocatealpha($im, 0, 0, 0, 127));
        imagefilledrectangle($im, (int)($w * .2), (int)($h * .2), (int)($w * .8), (int)($h * .8), imagecolorallocate($im, 138, 51, 36));
        $f = $site . '/up_' . $w . 'x' . $h . '.png'; imagepng($im, $f); return $f;
    };

    // Main logo
    $v1 = bcrf_brand_upload($pdo, 'logo', $mk(1000, 600), 'farm logo.png', 'Tester');
    $dir = $site . bcrf_brand_dir('logo', $v1);
    $ok(bcrf_brand_has('logo') && bcrf_brand_current('logo') === $v1, 'logo upload is in use');
    $ok(is_file($dir . '/source.png') && is_file($dir . '/logo-400.png') && is_file($dir . '/logo-200.png'), 'logo PNGs and the original kept');
    $sz = getimagesize($dir . '/logo-400.png');
    $ok($sz[0] === 400 && $sz[1] === 240 && $sz[2] === IMAGETYPE_PNG, 'logo-400.png is 400 × 240 PNG (shape kept)');
    $ok(getimagesize($dir . '/logo-200.png')[0] === 200, 'email logo is 200 px wide');
    if (function_exists('imagewebp')) {
        $ok(is_file($dir . '/logo-800.webp') && getimagesize($dir . '/logo-300.webp')[2] === IMAGETYPE_WEBP, 'website copies are WebP');
        $ok(bcrf_brand_url('logo_webp') === bcrf_brand_dir('logo', $v1) . '/logo-400.webp', 'header WebP points at the upload');
    }
    $ok(bcrf_brand_url('email_logo') === bcrf_brand_dir('logo', $v1) . '/logo-200.png', 'emails use the PNG');
    $ok(bcrf_brand_logo_size() === [400, 240], 'header logo size from the upload');
    $ok(bcrf_brand_url('schema_logo') === bcrf_brand_dir('logo', $v1) . '/logo-400.png', 'schema logo uses the main logo until a round one is uploaded');
    $px = imagecolorat(imagecreatefrompng($dir . '/logo-400.png'), 2, 2);
    $ok((($px >> 24) & 0x7F) === 127, 'transparent background kept');

    // Round logo (not square: padded, never cropped)
    $r1 = bcrf_brand_upload($pdo, 'round', $mk(800, 700), 'round.png', 'Tester');
    $rd = $site . bcrf_brand_dir('round', $r1);
    $ok(getimagesize($rd . '/round-512.png')[0] === 512 && getimagesize($rd . '/round-512.png')[1] === 512, 'round logo made square at 512');
    $ok(getimagesize($rd . '/round-1024.png')[0] === 800, 'print copy not blown up past the upload (800)');
    $ok(bcrf_brand_url('schema_logo', true) === 'https://farm.example' . bcrf_brand_dir('round', $r1) . '/round-512.png', 'schema logo now the round logo');
    $ok(bcrf_brand_file('print_logo') === $rd . '/round-1024.png', 'labels use the round logo');

    // Icon
    $i1 = bcrf_brand_upload($pdo, 'icon', $mk(600, 600), 'icon.png', 'Tester');
    $id = $site . bcrf_brand_dir('icon', $i1);
    $ico = (string)file_get_contents($id . '/favicon.ico');
    $hd = unpack('vres/vtype/vcount', substr($ico, 0, 6));
    $ok($hd['type'] === 1 && $hd['count'] === 3, 'favicon.ico holds 3 sizes');
    $sizes = [];
    for ($i = 0; $i < 3; $i++) {
        $e = unpack('Cw/Ch/Cc/Cr/vplanes/vbpp/Vlen/Voff', substr($ico, 6 + 16 * $i, 16));
        $img = @imagecreatefromstring(substr($ico, $e['off'], $e['len']));
        $sizes[] = $img ? imagesx($img) : 0;
    }
    $ok($sizes === [16, 32, 48], '.ico images are real 16/32/48 PNGs');
    foreach (['icon-32.png' => 32, 'icon-180.png' => 180, 'icon-192.png' => 192, 'icon-512.png' => 512] as $f => $px) {
        $s = @getimagesize($id . '/' . $f);
        $ok($s && $s[0] === $px && $s[1] === $px, "$f is {$px} px square");
    }
    $c = imagecolorat(imagecreatefrompng($id . '/icon-180.png'), 1, 1);
    $ok((($c >> 24) & 0x7F) === 0 && ($c & 0xFFFFFF) === 0xFFFFFF, 'phone home-screen icon sits on white');
    $links = bcrf_brand_icon_links('https://farm.example');
    $ok(str_contains($links, 'https://farm.example' . bcrf_brand_dir('icon', $i1) . '/favicon.ico') && !str_contains($links, 'icon.svg') && str_contains($links, '/icon-180.png'), 'tab icon links follow the upload (old SVG dropped)');
    $mi = bcrf_brand_manifest_icons();
    $ok(count($mi) === 2 && $mi[1]['sizes'] === '512x512', 'manifest icons 192 + 512');

    // Too small, not a picture
    try { bcrf_brand_upload($pdo, 'round', $mk(100, 100), 'tiny.png', 'T'); $ok(false, 'tiny round logo refused'); }
    catch (RuntimeException $e) { $ok(str_contains($e->getMessage(), 'at least 192'), 'tiny round logo refused in plain words'); }
    file_put_contents($site . '/not.png', 'hello');
    try { bcrf_brand_upload($pdo, 'logo', $site . '/not.png', 'not.png', 'T'); $ok(false, 'non-picture refused'); }
    catch (RuntimeException $e) { $ok(str_contains($e->getMessage(), 'PNG, JPEG or WebP'), 'non-picture refused in plain words'); }
    $ok(bcrf_brand_current('round') === $r1, 'a refused upload changes nothing');

    // Undo and reset
    sleep(1);
    $v2 = bcrf_brand_upload($pdo, 'logo', $mk(500, 500), 'second.png', 'Tester');
    $ok($v2 !== $v1 && bcrf_brand_current('logo') === $v2, 'second logo upload in use');
    $ok(bcrf_brand_undo($pdo, 'logo') === $v1 && bcrf_brand_current('logo') === $v1, 'undo goes back to the first upload');
    $ok(is_dir($site . bcrf_brand_dir('logo', $v2)), 'undone upload still on disk');
    $ok(bcrf_brand_undo($pdo, 'logo') === '' && bcrf_brand_url('logo_png') === '/img/logo.png', 'undo again: original files');
    bcrf_brand_reset($pdo, 'icon');
    $ok(!bcrf_brand_has('icon') && str_contains(bcrf_brand_icon_links(), '/img/icon.svg'), 'reset icon: original links back');
    $st = bcrf_brand_state(true);
    $ok(count($st['logo']['hist']) === 2 && $st['logo']['hist'][0]['name'] === 'second.png' && $st['logo']['hist'][0]['by'] === 'Tester', 'upload list kept with names');

    // A missing version folder falls back instead of pointing at nothing
    $st['round']['cur'] = '20990101000000-abcdef';
    bcrf_brand_save_state($pdo, $st);
    $ok(bcrf_brand_url('round_png') === '/img/logo.png', 'missing folder: original file');
}

// clean up the throwaway root
$it = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($site, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
foreach ($it as $f) $f->isDir() ? @rmdir($f->getPathname()) : @unlink($f->getPathname());
@rmdir($site);

echo $fails ? "$fails of $n checks failed\n" : "All $n brand checks passed\n";
exit($fails ? 1 : 0);
