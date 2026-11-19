<?php
// includes/garden_plant_art.php — Garden map plant pictures (Nov 19, 2026; includes/garden_planner.php garden_map_data, admin/assets/garden.js,
//   admin/garden/crops.php, migration 0076).
//   Built-in drawings (carrot, beet, potato; 96 px WebP with see-through backgrounds) live here as text so they deploy like code.
//   Any crop can get its own picture in the crop editor (garden_crop_settings.picture, /img/garden/plants/crop-*.webp).
//   The farm's picture set (Garden › Crops › Plant pictures: one .zip of WebP/PNG icons named like carrot.webp, optional
//   manifest.json) lives in /img/garden/plants/set/ and is matched to crops by name (Nov 19, 2026). Crops with no match get a
//   generic plant from the set for their family, or an emoji when no set is loaded.

/** Built-in plant drawings: key => data URI. */
function garden_plant_art_builtin(): array {
    return [
        'carrot' => 'data:image/webp;base64,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',
        'beet' => 'data:image/webp;base64,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',
        'potato' => 'data:image/webp;base64,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',
    ];
}

/** Which built-in drawing fits a crop name ('' when none). Sweet potatoes are not potatoes. */
function garden_plant_art_key(string $crop): string {
    $n = strtolower(trim($crop));
    if (str_contains($n, 'sweet potato')) return '';
    foreach (['carrot' => '/\bcarrots?\b/', 'beet' => '/\bbeet(s|root)?\b/', 'potato' => '/\bpotato(es)?\b/'] as $k => $re) if (preg_match($re, $n)) return $k;
    return '';
}

/** An emoji for a crop name, for crops without a picture. */
function garden_plant_emoji(string $crop): string {
    $n = strtolower(trim($crop));
    $map = [
        'sweet potato' => '🍠', 'watermelon' => '🍉', 'cantaloupe' => '🍈', 'melon' => '🍈', 'strawberr' => '🍓', 'blueberr' => '🫐', 'raspberr' => '🍇', 'blackberr' => '🍇',
        'grape' => '🍇', 'tomato' => '🍅', 'tomatillo' => '🍅', 'hot pepper' => '🌶️', 'jalape' => '🌶️', 'chili' => '🌶️', 'cayenne' => '🌶️', 'habanero' => '🌶️', 'pepper' => '🫑',
        'corn' => '🌽', 'cucumber' => '🥒', 'pickle' => '🥒', 'zucchini' => '🥒', 'eggplant' => '🍆', 'pumpkin' => '🎃', 'squash' => '🎃', 'gourd' => '🎃',
        'broccoli' => '🥦', 'cauliflower' => '🥦', 'lettuce' => '🥬', 'cabbage' => '🥬', 'kale' => '🥬', 'chard' => '🥬', 'spinach' => '🥬', 'collard' => '🥬', 'bok choy' => '🥬',
        'arugula' => '🥬', 'mustard' => '🥬', 'greens' => '🥬', 'onion' => '🧅', 'leek' => '🧅', 'shallot' => '🧅', 'scallion' => '🧅', 'garlic' => '🧄',
        'pea' => '🫛', 'bean' => '🫛', 'okra' => '🫛', 'radish' => '🌰', 'turnip' => '🌰', 'peanut' => '🥜', 'sunflower' => '🌻', 'zinnia' => '🌼', 'marigold' => '🌼',
        'flower' => '🌼', 'dahlia' => '🌸', 'cosmos' => '🌸', 'tulip' => '🌷', 'rose' => '🌹', 'basil' => '🌿', 'parsley' => '🌿', 'cilantro' => '🌿', 'dill' => '🌿',
        'mint' => '🌿', 'oregano' => '🌿', 'thyme' => '🌿', 'sage' => '🌿', 'rosemary' => '🌿', 'chive' => '🌿', 'herb' => '🌿', 'apple' => '🍎', 'pear' => '🍐',
        'peach' => '🍑', 'cherr' => '🍒', 'lemon' => '🍋', 'avocado' => '🥑', 'mushroom' => '🍄', 'wheat' => '🌾', 'oat' => '🌾', 'rye' => '🌾', 'clover' => '☘️', 'cover crop' => '🌾',
    ];
    foreach ($map as $k => $e) if (str_contains($n, $k)) return $e;
    return '🌱';
}

// ── A crop's own picture (crop editor) ──

/** Folder for crop pictures: <site>/img/garden/plants (BCRF_GARDEN_PLANT_DIR overrides it for tests). */
function garden_crop_picture_dir(): string {
    $dir = getenv('BCRF_GARDEN_PLANT_DIR') ?: dirname(__DIR__) . '/img/garden/plants';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return rtrim($dir, '/');
}

/** Save a crop's map picture (shrunk to 256 px, see-through kept), replacing the old one. Returns the web path. */
function garden_crop_picture_save(PDO $pdo, int $org, string $key, array $file): string {
    [$c, $o] = garden_crop_key_parts($key);
    if ($c) { $st = $pdo->prepare('SELECT 1 FROM garden_crops WHERE id = ?'); $st->execute([$c]); }
    else { $st = $pdo->prepare('SELECT 1 FROM garden_organization_crops WHERE id = ? AND organization_id = ?'); $st->execute([$o, $org]); }
    if (!$st->fetchColumn()) throw new InvalidArgumentException('That crop is gone.');
    $im = garden_upload_image($file, 256);
    $dir = garden_crop_picture_dir();
    $name = 'crop-' . $key . '-' . bin2hex(random_bytes(6)) . '.webp';
    if (!is_dir($dir) || !@imagewebp($im, $dir . '/' . $name, 85)) throw new InvalidArgumentException('Could not save the picture.');
    @chmod($dir . '/' . $name, 0644);
    $path = '/img/garden/plants/' . $name;
    $old = (string)(garden_crop_settings_get($pdo, $org, $key)['picture'] ?? '');
    $pdo->prepare('INSERT INTO garden_crop_settings (organization_id, crop_id, organization_crop_id, picture) VALUES (?, ?, ?, ?)
                   ON DUPLICATE KEY UPDATE picture = VALUES(picture), updated_at = UTC_TIMESTAMP()')->execute([$org, $c, $o, $path]);
    garden_crop_picture_unlink($old);
    return $path;
}

/** Take a crop's own picture off (the map goes back to the drawing or emoji). */
function garden_crop_picture_remove(PDO $pdo, int $org, string $key): void {
    [$c, $o] = garden_crop_key_parts($key);
    $old = (string)(garden_crop_settings_get($pdo, $org, $key)['picture'] ?? '');
    $pdo->prepare('UPDATE garden_crop_settings SET picture = NULL WHERE organization_id = ? AND ' . ($c ? 'crop_id = ?' : 'organization_crop_id = ?'))->execute([$org, $c ?: $o]);
    garden_crop_picture_unlink($old);
}

/** Delete a crop picture file, only inside the crop picture folder. */
function garden_crop_picture_unlink(string $path): void {
    if (!preg_match('#^/img/garden/plants/(crop-[so]\d+-[0-9a-f]{12}\.webp)$#', $path, $m)) return;
    $f = garden_crop_picture_dir() . '/' . $m[1];
    if (is_file($f)) @unlink($f);
}

// ── The farm's picture set (one zip) ──

const GARDEN_PLANT_SET_MAX = 600;          // icons in one zip
const GARDEN_PLANT_SET_ICON = 160;         // px, long side, as saved

/** Folder for the picture set: <site>/img/garden/plants/set (BCRF_GARDEN_PLANT_DIR/set for tests). */
function garden_plant_set_dir(): string {
    $base = getenv('BCRF_GARDEN_PLANT_DIR') ?: dirname(__DIR__) . '/img/garden/plants';
    $dir = rtrim($base, '/') . '/set';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return $dir;
}

/** The loaded set: slug => web path (with a version so phones reload after a new zip). Cached per request. */
function garden_plant_set(bool $fresh = false): array {
    static $set = null;
    if ($set !== null && !$fresh) return $set;
    $set = [];
    $dir = garden_plant_set_dir();
    foreach (glob($dir . '/*.webp') ?: [] as $f) {
        $slug = basename($f, '.webp');
        if (preg_match('/^[a-z0-9-]{1,80}$/', $slug)) $set[$slug] = '/img/garden/plants/set/' . $slug . '.webp?v=' . (int)@filemtime($f);
    }
    return $set;
}

/** Names from the set's manifest.json: slug => name ([] when none). */
function garden_plant_set_names(): array {
    $j = @file_get_contents(garden_plant_set_dir() . '/names.json');
    $d = $j ? json_decode($j, true) : null;
    return is_array($d) ? $d : [];
}

/** A crop or icon name as an icon slug ("Cherry Tomatoes" -> "cherry-tomatoes"). */
function garden_plant_slug(string $s): string {
    return trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');
}

/**
 * The set icon for a crop name: exact, singular, a known other name, then the last words ("Danvers carrot" -> carrot),
 * then a generic plant for the family. '' when nothing fits or no set is loaded.
 */
function garden_plant_set_match(string $crop, string $family = '', ?array $set = null): string {
    $set ??= garden_plant_set();
    if (!$set) return '';
    $alias = ['bean' => 'green-bean', 'bush-bean' => 'green-bean', 'pole-bean' => 'green-bean', 'snap-bean' => 'green-bean', 'string-bean' => 'green-bean',
              'pea' => 'garden-pea', 'english-pea' => 'garden-pea', 'shelling-pea' => 'garden-pea', 'sugar-snap-pea' => 'snow-pea', 'snap-pea' => 'snow-pea',
              'pepper' => 'bell-pepper', 'sweet-pepper' => 'bell-pepper', 'hot-pepper' => 'cayenne-pepper', 'chili-pepper' => 'cayenne-pepper', 'chili' => 'cayenne-pepper',
              'squash' => 'yellow-squash', 'summer-squash' => 'yellow-squash', 'winter-squash' => 'butternut-squash', 'corn' => 'sweet-corn',
              'collard' => 'collard-greens', 'collards' => 'collard-greens', 'mustard' => 'mustard-greens', 'chard' => 'swiss-chard', 'melon' => 'cantaloupe',
              'muskmelon' => 'cantaloupe', 'beetroot' => 'beet', 'scallion' => 'onion', 'green-onion' => 'onion', 'coriander' => 'cilantro', 'courgette' => 'zucchini',
              'aubergine' => 'eggplant', 'rocket' => 'arugula', 'soybean' => 'edamame', 'chive' => 'chives', 'pak-choi' => 'bok-choy', 'bok-choi' => 'bok-choy',
              'irish-potato' => 'potato', 'english-cucumber' => 'cucumber', 'pickling-cucumber' => 'cucumber', 'kiwifruit' => 'kiwi', 'chinese-cabbage' => 'napa-cabbage',
              'lima-bean' => 'cranberry-bean', 'butter-bean' => 'cranberry-bean', 'gourd' => 'luffa-gourd', 'mache' => 'mache', 'corn-salad' => 'mache',
              'echinacea' => 'echinacea', 'coneflower' => 'echinacea', 'sweet-pea' => 'sweet-pea-flower', 'snapdragon' => 'generic-grassy-flower'];
    $try = function (string $sl) use ($set, $alias): string {
        if ($sl === '') return '';
        foreach ([$sl, preg_replace('/ies$/', 'y', $sl), preg_replace('/(?<=[^aeiou]o|[sxz]|ch|sh)es$/', '', $sl), preg_replace('/(?<!s)s$/', '', $sl)] as $c) {
            if (isset($set[$c])) return $c;
            if (isset($alias[$c]) && isset($set[$alias[$c]])) return $alias[$c];
        }
        return '';
    };
    $sl = garden_plant_slug($crop);
    if (($m = $try($sl)) !== '') return $m;
    $parts = explode('-', $sl);
    for ($i = 1; $i < count($parts); $i++) if (($m = $try(implode('-', array_slice($parts, $i)))) !== '') return $m;
    $f = strtolower($family);
    $generic = str_contains($f, 'brassic') ? 'generic-curly-leaf' : (str_contains($f, 'amaryllid') || str_contains($f, 'allium') ? 'generic-allium-stalks'
             : (str_contains($f, 'apiac') ? 'generic-feathery-bulb' : (str_contains($f, 'poac') ? 'generic-grassy-flower' : (str_contains($f, 'cucurbit') ? 'generic-climbing-vine' : 'generic-leafy-plant'))));
    return isset($set[$generic]) ? $generic : '';
}

/** Load a zip of plant icons into the set, replacing the old set. Returns how many icons were saved. */
function garden_plant_set_import(string $zip): int {
    $files = [];   // slug => [ext, bytes]
    $names = [];
    $take = function (string $path, string $bytes) use (&$files, &$names) {
        if (strlen($bytes) > 8 * 1024 * 1024) return;
        $base = basename(str_replace('\\', '/', $path));
        if ($base === 'manifest.json') {
            $d = json_decode($bytes, true);
            foreach ((array)($d['icons'] ?? []) as $ic) if (isset($ic['id'], $ic['name']) && is_string($ic['id'])) $names[garden_plant_slug($ic['id'])] = mb_substr((string)$ic['name'], 0, 80);
            return;
        }
        if (!preg_match('/^([A-Za-z0-9 _.-]{1,80})\.(webp|png)$/i', $base, $m) || str_starts_with($base, '.')) return;
        $slug = garden_plant_slug($m[1]); $ext = strtolower($m[2]);
        if ($slug === '' || (isset($files[$slug]) && $files[$slug][0] === 'webp')) return;   // WebP wins over PNG
        $files[$slug] = [$ext, $bytes];
    };
    if (class_exists('ZipArchive')) {
        $z = new ZipArchive();
        if ($z->open($zip) !== true) throw new InvalidArgumentException('That file is not a zip.');
        if ($z->numFiles > GARDEN_PLANT_SET_MAX * 3) { $z->close(); throw new InvalidArgumentException('That zip has too many files.'); }
        for ($i = 0; $i < $z->numFiles; $i++) {
            $st = $z->statIndex($i);
            if (!$st || str_ends_with($st['name'], '/') || $st['size'] > 8 * 1024 * 1024 || str_contains($st['name'], '__MACOSX')) continue;
            $b = $z->getFromIndex($i);
            if ($b !== false) $take($st['name'], $b);
        }
        $z->close();
    } else {
        throw new InvalidArgumentException('This server cannot open zip files.');
    }
    if (!$files) throw new InvalidArgumentException('No .webp or .png pictures found in that zip.');
    if (count($files) > GARDEN_PLANT_SET_MAX) throw new InvalidArgumentException('That zip has more than ' . GARDEN_PLANT_SET_MAX . ' pictures.');
    $dir = garden_plant_set_dir();
    $tmp = $dir . '/.new-' . bin2hex(random_bytes(4));
    if (!@mkdir($tmp, 0755)) throw new InvalidArgumentException('Could not save the pictures.');
    $n = 0;
    foreach ($files as $slug => [$ext, $bytes]) {
        $info = @getimagesizefromstring($bytes);
        if (!$info || !in_array($info[2], [IMAGETYPE_PNG, IMAGETYPE_WEBP], true) || $info[0] * $info[1] > 25000000) continue;
        $im = @imagecreatefromstring($bytes);
        if (!$im) continue;
        if (!imageistruecolor($im)) imagepalettetotruecolor($im);
        $w = imagesx($im); $h = imagesy($im); $sc = min(1, GARDEN_PLANT_SET_ICON / max($w, $h));
        $nw = max(1, (int)round($w * $sc)); $nh = max(1, (int)round($h * $sc));
        $c = imagecreatetruecolor($nw, $nh);
        imagealphablending($c, false); imagesavealpha($c, true); imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));
        imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        if (@imagewebp($c, $tmp . '/' . $slug . '.webp', 82)) { @chmod($tmp . '/' . $slug . '.webp', 0644); $n++; }
    }
    if (!$n) { array_map('unlink', glob($tmp . '/*') ?: []); @rmdir($tmp); throw new InvalidArgumentException('None of the pictures in that zip could be opened.'); }
    if ($names) @file_put_contents($tmp . '/names.json', json_encode($names, JSON_UNESCAPED_UNICODE));
    // Swap: the old set goes, the new one moves in.
    foreach (glob($dir . '/*.webp') ?: [] as $f) @unlink($f);
    @unlink($dir . '/names.json');
    foreach (glob($tmp . '/*') ?: [] as $f) @rename($f, $dir . '/' . basename($f));
    @rmdir($tmp);
    garden_plant_set(true);
    return $n;
}

/**
 * How each crop shows on the map: crop key => ['pic' => url|'', 'art' => built-in key|'', 'emoji' => …, 'gap' => ft between plants, 'row' => ft between rows].
 * Spacing: the crop editor, else the reference numbers, else 1 ft.
 */
function garden_plant_look(PDO $pdo, int $org, string $key, string $cropName, array $settings, string $family = ''): array {
    $s = $key !== '' ? ($settings[$key] ?? []) : [];
    $pic = (string)($s['picture'] ?? ''); $from = $pic !== '' ? 'own' : '';
    if ($pic === '' && ($m = garden_plant_set_match($cropName, $family)) !== '') { $pic = garden_plant_set()[$m]; $from = str_starts_with($m, 'generic-') ? 'generic' : 'set'; }
    $gapCm = isset($s['spacing_cm']) && $s['spacing_cm'] !== null ? (float)$s['spacing_cm'] : null;
    $rowCm = isset($s['row_spacing_cm']) && $s['row_spacing_cm'] !== null ? (float)$s['row_spacing_cm'] : null;
    if ($gapCm === null || $rowCm === null) {
        $ref = null;
        try { $ref = garden_reference_match($cropName, $pdo); } catch (Throwable $e) { $ref = null; }
        if ($ref) {
            if ($gapCm === null && isset($ref['spacing_cm']) && $ref['spacing_cm'] !== null && $ref['spacing_cm'] !== '') $gapCm = (float)$ref['spacing_cm'];
            if ($rowCm === null && isset($ref['row_spacing_cm']) && $ref['row_spacing_cm'] !== null && $ref['row_spacing_cm'] !== '') $rowCm = (float)$ref['row_spacing_cm'];
        }
    }
    $gap = $gapCm !== null && $gapCm > 0 ? $gapCm / 30.48 : 1.0;
    $row = $rowCm !== null && $rowCm > 0 ? $rowCm / 30.48 : $gap;
    return ['pic' => $pic, 'from' => $from, 'art' => $pic === '' ? garden_plant_art_key($cropName) : '', 'emoji' => garden_plant_emoji($cropName),
            'gap' => round(min(max($gap, 0.15), 6), 2), 'row' => round(min(max($row, 0.15), 8), 2)];
}
