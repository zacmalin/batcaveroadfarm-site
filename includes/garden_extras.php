<?php
// includes/garden_extras.php — Garden: frost warning, low-seed reminder, seed packet photos, yield per square foot, and the
//   suggested dates on the planting form (Nov 19, 2026; migration 0075).
//   garden_frost_watch()     the next cold night from the saved weather forecast (includes/weather_forecast.php, refreshed every
//                            30 minutes) and what's growing that it would hurt: tender crops (and crops with no frost setting) at
//                            34°F or below, half-hardy ones at 28°F or below (frost tolerance is set in the crop editor).
//   garden_seed_low()        seed lots whose amount left is at or below their "remind me at", or used up.
//   garden_notify_sweep()    puts both in the bell (includes/notifications.php runs it every 15 minutes): one frost warning per
//                            cold night, one low-seed line per lot each time its count changes.
//   garden_seed_photo_save() packet photos (front and back), checked, turned upright, shrunk and saved as WebP.
//   garden_yield_per_area()  usable harvest per square foot of bed space, per planting and per bed and year.
//   garden_variety_plans()   each variety's suggested dates from its crop settings and the frost dates (the planting form).
// Load through includes/garden.php.

const GARDEN_FROST_TENDER_F = 34;     // °F: frost possible for tender crops
const GARDEN_FROST_HARDY_F = 28;      // °F: a hard frost that hurts half-hardy crops too
const GARDEN_SEED_PHOTO_BYTES = 15 * 1024 * 1024;

// ── Frost warning ──

/**
 * The next cold night in $periods (bcrf_wx_periods()) and the growing crops it would hurt, or null.
 * Returns ['date' => 'Y-m-d' (the evening it starts), 'name' => 'Tonight', 'low' => °F, 'crops' => ["Tomato in Bed 3", …]].
 */
function garden_frost_watch(PDO $pdo, int $org, array $periods, string $tz = 'America/New_York'): ?array {
    $night = null;
    foreach (array_slice($periods, 0, 4) as $p) {   // the next two days and nights
        if (!empty($p['day']) || !isset($p['temp']) || $p['temp'] === null) continue;
        if ((int)$p['temp'] <= GARDEN_FROST_TENDER_F) { $night = $p; break; }
    }
    if (!$night) return null;
    $low = (int)$night['temp'];
    $settings = garden_crop_settings_all($pdo, $org);
    $st = $pdo->prepare("SELECT p.id, COALESCE(c.common_name, oc.name) AS crop, l.name AS bed,
                                IF(v.id IS NOT NULL, CONCAT('s', v.crop_id), CONCAT('o', ov.crop_id)) AS crop_key
                         FROM garden_plantings p
                         JOIN garden_growing_locations l ON l.id = p.location_id AND l.organization_id = p.organization_id
                         LEFT JOIN garden_varieties v ON v.id = p.variety_id LEFT JOIN garden_crops c ON c.id = v.crop_id
                         LEFT JOIN garden_organization_varieties ov ON ov.id = p.organization_variety_id AND ov.organization_id = p.organization_id
                         LEFT JOIN garden_organization_crops oc ON oc.id = ov.crop_id AND oc.organization_id = p.organization_id
                         WHERE p.organization_id = ? AND p.status = 'active' AND l.kind <> 'greenhouse' ORDER BY l.name, crop");
    $st->execute([$org]);
    $crops = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $tol = $settings[$r['crop_key']]['frost_tolerance'] ?? null;
        if ($tol === 'hardy') continue;
        if ($tol === 'half_hardy' && $low > GARDEN_FROST_HARDY_F) continue;
        $crops[$r['crop'] . ' in ' . $r['bed']] = true;
    }
    if (!$crops) return null;
    $date = (new DateTimeImmutable((string)$night['start']))->setTimezone(new DateTimeZone($tz))->format('Y-m-d');
    return ['date' => $date, 'name' => (string)($night['name'] ?? 'Tonight'), 'low' => $low, 'crops' => array_keys($crops)];
}

// ── Low seed ──

/** Seed lots at or below their reminder amount, or used up: [id, name, left, unit, remind_at, last movement id]. */
function garden_seed_low(PDO $pdo, int $org): array {
    $st = $pdo->prepare("SELECT s.id, s.unit, s.remind_at, s.lot_code, COALESCE(SUM(m.delta), 0) AS left_qty, COUNT(m.id) AS moves, MAX(m.id) AS last_move,
                                COALESCE(c.common_name, oc.name) AS crop, COALESCE(pr.display_name, v.name, ov.name) AS variety
                         FROM garden_seed_lots s
                         JOIN garden_seed_stock_movements m ON m.seed_lot_id = s.id AND m.organization_id = s.organization_id
                         LEFT JOIN garden_varieties v ON v.id = s.variety_id LEFT JOIN garden_crops c ON c.id = v.crop_id
                         LEFT JOIN garden_organization_variety_preferences pr ON pr.variety_id = v.id AND pr.organization_id = s.organization_id
                         LEFT JOIN garden_organization_varieties ov ON ov.id = s.organization_variety_id AND ov.organization_id = s.organization_id
                         LEFT JOIN garden_organization_crops oc ON oc.id = ov.crop_id AND oc.organization_id = s.organization_id
                         WHERE s.organization_id = ?
                         GROUP BY s.id, s.unit, s.remind_at, s.lot_code, crop, variety
                         HAVING left_qty <= 0 OR (s.remind_at IS NOT NULL AND left_qty <= s.remind_at)
                         ORDER BY crop, variety");
    $st->execute([$org]);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $out[] = ['id' => (int)$r['id'], 'name' => $r['crop'] . ' — ' . $r['variety'] . ($r['lot_code'] ? ' (' . $r['lot_code'] . ')' : ''),
                  'left' => (float)$r['left_qty'], 'unit' => (string)$r['unit'], 'remind_at' => $r['remind_at'] !== null ? (float)$r['remind_at'] : null, 'last' => (int)$r['last_move']];
    }
    return $out;
}

/** Set (or clear, with '') a lot's reminder amount. */
function garden_seed_remind_save(PDO $pdo, int $org, int $lot, string $amount): void {
    $a = trim($amount);
    if ($a !== '' && (!is_numeric($a) || (float)$a < 0 || (float)$a > 999999)) throw new InvalidArgumentException('Remind me at: enter an amount, or leave it empty for no reminder.');
    $st = $pdo->prepare('UPDATE garden_seed_lots SET remind_at = ? WHERE id = ? AND organization_id = ?');
    $st->execute([$a === '' ? null : round((float)$a, 3), $lot, $org]);
}

/** Frost warning and low seed into the bell. Returns how many were added. Never throws. */
function garden_notify_sweep(PDO $pdo): int {
    if (!function_exists('bcrf_notify') || !garden_ready($pdo)) return 0;
    $added = 0;
    try {
        $org = garden_org($pdo);
        if (!function_exists('bcrf_wx_periods')) require_once __DIR__ . '/weather_forecast.php';
        $cache = function_exists('bcrf_wx_setting') ? bcrf_wx_setting($pdo, 'wx_cache') : '';
        $w = garden_frost_watch($pdo, $org, bcrf_wx_periods($cache), bcrf_tz()->getName());
        if ($w) {
            $list = $w['crops']; $more = count($list) > 6 ? ' and ' . (count($list) - 6) . ' more' : '';
            $added += bcrf_notify($pdo, ['kind' => 'garden_frost', 'dedupe' => 'garden_frost:' . $w['date'], 'link' => '/admin/garden/today.php',
                'title' => 'Frost possible ' . strtolower($w['name']) . ' (' . $w['low'] . '°F): cover or pick tender crops',
                'body' => implode(', ', array_slice($list, 0, 6)) . $more . '.']) ? 1 : 0;
        }
    } catch (Throwable $e) { error_log('[garden] frost sweep: ' . $e->getMessage()); }
    try {
        $org = garden_org($pdo);
        foreach (garden_seed_low($pdo, $org) as $l) {
            $added += bcrf_notify($pdo, ['kind' => 'garden_seed_low', 'dedupe' => 'garden_seed_low:' . $l['id'] . ':' . $l['last'], 'link' => '/admin/garden/seeds.php?lot=' . $l['id'],
                'title' => ($l['left'] <= 0 ? 'Out of seed: ' : 'Seed running low: ') . $l['name'],
                'body' => garden_num($l['left'], 3) . ' ' . $l['unit'] . ' left' . ($l['remind_at'] !== null ? ' (reminder at ' . garden_num($l['remind_at'], 3) . ')' : '') . '. Reorder before the next sowing.']) ? 1 : 0;
        }
    } catch (Throwable $e) { error_log('[garden] seed sweep: ' . $e->getMessage()); }
    return $added;
}

// ── Seed packet photos ──

/** Folder for packet photos: <site>/img/garden/seeds (BCRF_GARDEN_IMG_DIR overrides it for tests). */
function garden_seed_photo_dir(): string {
    $dir = getenv('BCRF_GARDEN_IMG_DIR') ?: dirname(__DIR__) . '/img/garden/seeds';
    if (!is_dir($dir)) @mkdir($dir, 0755, true);
    return rtrim($dir, '/');
}

/**
 * Save a packet photo ($side 'front' or 'back') for a seed lot, replacing the old one. Returns the new web path.
 * ($GLOBALS['garden_test_upload'] lets the tests pass a plain file.)
 */
function garden_seed_photo_save(PDO $pdo, int $org, int $lot, array $file, string $side): string {
    if (!in_array($side, ['front', 'back'], true)) throw new InvalidArgumentException('Pick front or back.');
    $st = $pdo->prepare('SELECT photo_front, photo_back FROM garden_seed_lots WHERE id = ? AND organization_id = ?'); $st->execute([$lot, $org]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('That seed lot is gone.');
    $im = garden_upload_image($file, 1600);
    $dir = garden_seed_photo_dir();
    $name = 'seed-' . $lot . '-' . $side . '-' . bin2hex(random_bytes(6)) . '.webp';
    if (!is_dir($dir) || !@imagewebp($im, $dir . '/' . $name, 82)) throw new InvalidArgumentException('Could not save the photo.');
    @chmod($dir . '/' . $name, 0644);
    $path = '/img/garden/seeds/' . $name;
    $pdo->prepare('UPDATE garden_seed_lots SET photo_' . $side . ' = ? WHERE id = ? AND organization_id = ?')->execute([$path, $lot, $org]);
    garden_seed_photo_unlink((string)($row['photo_' . $side] ?? ''));
    return $path;
}

/**
 * Open an uploaded photo (JPEG, PNG, WebP, GIF), turn it upright, keep see-through parts, and shrink it to $max px on the long side.
 * ($GLOBALS['garden_test_upload'] lets the tests pass a plain file.)
 */
function garden_upload_image(array $file, int $max) {
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_NO_FILE) throw new InvalidArgumentException('Pick a photo first.');
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new InvalidArgumentException('That photo is too big to upload. Try a smaller one.');
    if ($err !== UPLOAD_ERR_OK) throw new InvalidArgumentException('The photo did not upload. Try again.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (empty($GLOBALS['garden_test_upload']) && !is_uploaded_file($tmp)) throw new InvalidArgumentException('The photo did not upload. Try again.');
    $size = (int)@filesize($tmp);
    if ($size <= 0 || $size > GARDEN_SEED_PHOTO_BYTES) throw new InvalidArgumentException('Photos can be up to 15 MB.');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new InvalidArgumentException('That file is not a photo (JPEG, PNG or WebP).');
    if ($info[0] * $info[1] > 50000000) throw new InvalidArgumentException('That photo is too large to open.');
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        default        => false,
    };
    if (!$im) throw new InvalidArgumentException('That photo could not be opened.');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, $max / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $c = imagecreatetruecolor($nw, $nh);
        imagealphablending($c, false); imagesavealpha($c, true); imagefill($c, 0, 0, imagecolorallocatealpha($c, 0, 0, 0, 127));
        imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = $c;
    }
    imagesavealpha($im, true);
    return $im;
}

/** Take a packet photo off a lot (and delete the file). */
function garden_seed_photo_remove(PDO $pdo, int $org, int $lot, string $side): void {
    if (!in_array($side, ['front', 'back'], true)) throw new InvalidArgumentException('Pick front or back.');
    $st = $pdo->prepare('SELECT photo_' . $side . ' FROM garden_seed_lots WHERE id = ? AND organization_id = ?'); $st->execute([$lot, $org]);
    $old = (string)$st->fetchColumn();
    $pdo->prepare('UPDATE garden_seed_lots SET photo_' . $side . ' = NULL WHERE id = ? AND organization_id = ?')->execute([$lot, $org]);
    garden_seed_photo_unlink($old);
}

/** Delete a packet photo file, only inside the packet photo folder. */
function garden_seed_photo_unlink(string $path): void {
    if (!preg_match('#^/img/garden/seeds/(seed-\d+-(?:front|back)-[0-9a-f]{12}\.webp)$#', $path, $m)) return;
    $f = garden_seed_photo_dir() . '/' . $m[1];
    if (is_file($f)) @unlink($f);
}

// ── Yield per square foot ──

/**
 * Usable harvest per square foot of bed space, for plantings with bed space set: ['plantings' => [crop, bed, year, usable,
 * unit, sqft, per_sqft], 'beds' => [bed, year, unit, usable, sqft, per_sqft]] (a bed's sqft = its plantings' space that year).
 */
function garden_yield_per_area(PDO $pdo, int $org, int $site): array {
    $st = $pdo->prepare("SELECT p.id, YEAR(p.planted_on) AS yr, l.name AS bed, p.allocated_area_sq_m AS area, h.unit,
                                SUM(h.quantity - COALESCE(h.loss_quantity, 0)) AS usable,
                                CONCAT(COALESCE(c.common_name, oc.name), ' — ', COALESCE(pr.display_name, v.name, ov.name)) AS crop
                         FROM garden_plantings p
                         JOIN garden_growing_locations l ON l.id = p.location_id AND l.organization_id = p.organization_id AND l.site_id = ?
                         JOIN garden_planting_harvest_events h ON h.planting_id = p.id AND h.organization_id = p.organization_id
                         LEFT JOIN garden_varieties v ON v.id = p.variety_id LEFT JOIN garden_crops c ON c.id = v.crop_id
                         LEFT JOIN garden_organization_variety_preferences pr ON pr.variety_id = v.id AND pr.organization_id = p.organization_id
                         LEFT JOIN garden_organization_varieties ov ON ov.id = p.organization_variety_id AND ov.organization_id = p.organization_id
                         LEFT JOIN garden_organization_crops oc ON oc.id = ov.crop_id AND oc.organization_id = p.organization_id
                         WHERE p.organization_id = ? AND p.allocated_area_sq_m > 0
                         GROUP BY p.id, yr, bed, area, h.unit, crop
                         ORDER BY yr DESC, bed, crop");
    $st->execute([$site, $org]);
    $plantings = []; $beds = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
        $sqft = (float)$r['area'] / (GARDEN_FT * GARDEN_FT);
        if ($sqft <= 0) continue;
        $plantings[] = ['crop' => $r['crop'], 'bed' => $r['bed'], 'year' => (int)$r['yr'], 'usable' => (float)$r['usable'], 'unit' => $r['unit'], 'sqft' => $sqft, 'per_sqft' => (float)$r['usable'] / $sqft];
        $k = $r['bed'] . '|' . $r['yr'] . '|' . $r['unit'];
        $beds[$k] ??= ['bed' => $r['bed'], 'year' => (int)$r['yr'], 'unit' => $r['unit'], 'usable' => 0.0, 'sqft' => 0.0, 'ids' => []];
        $beds[$k]['usable'] += (float)$r['usable'];
        if (!isset($beds[$k]['ids'][$r['id']])) { $beds[$k]['sqft'] += $sqft; $beds[$k]['ids'][$r['id']] = true; }
    }
    foreach ($beds as &$b) { $b['per_sqft'] = $b['sqft'] > 0 ? $b['usable'] / $b['sqft'] : 0; unset($b['ids']); }
    unset($b);
    return ['plantings' => $plantings, 'beds' => array_values($beds)];
}

// ── Suggested dates on the planting form ──

/**
 * Each variety's suggested dates for a garden: picker value ("12" / "custom:5") => ['out' => date, 'indoors' => date|null,
 * 'ready' => date|null, 'how' => 'transplant'|'direct', 'words' => "…"]. Only varieties whose crop has timing set.
 */
function garden_variety_plans(PDO $pdo, int $org, ?array $site, ?string $today = null): array {
    $frost = garden_frost_for($pdo, $org, $site);
    if ($frost['spring'] === '') return [];
    $settings = garden_crop_settings_all($pdo, $org);
    if (!$settings) return [];
    $today ??= bcrf_today();
    $frost = garden_frost_for($pdo, $org, $site, garden_plan_year(substr($frost['fall'], 5), $today));
    $out = [];
    $add = function (string $value, string $key, $days) use (&$out, $settings, $frost, $pdo, $org, $site, $today) {
        $s = $settings[$key] ?? null;
        if (!$s || $s['plant_out_days'] === null) return;
        $plan = garden_crop_plan($s, $frost, $days ? (int)$days : null);
        // Already past this year's date: show next year's.
        if ($plan['out'] && $plan['out'] < $today) $plan = garden_crop_plan($s, garden_frost_for($pdo, $org, $site, $frost['year'] + 1), $days ? (int)$days : null);
        if (!$plan['out']) return;
        $out[$value] = ['out' => $plan['out'], 'indoors' => $plan['indoors'], 'ready' => $plan['ready'], 'how' => $plan['how'],
                        'words' => ($plan['indoors'] ? 'Start indoors ' . garden_date($plan['indoors']) . ', plant out ' : 'Sow outside ') . garden_date($plan['out'])
                                 . ($plan['ready'] ? ', ready about ' . garden_date($plan['ready']) : '') . '.'];
    };
    foreach ((new \Garden\Domain\CropCatalog())->varietiesForOrganization($pdo, $org) as $v) {
        if (!empty($v['hidden_at'])) continue;
        $add((string)$v['id'], 's' . $v['crop_id'], $v['local_days_to_maturity'] ?? $v['days_to_maturity']);
    }
    foreach ((new \Garden\Domain\CustomCatalog())->varieties($pdo, $org) as $v) {
        if ($v['archived_at'] || $v['crop_archived_at']) continue;
        $add('custom:' . (int)$v['id'], 'o' . $v['crop_id'], $v['days_to_maturity']);
    }
    return $out;
}
