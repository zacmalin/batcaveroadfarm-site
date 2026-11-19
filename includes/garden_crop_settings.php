<?php
// includes/garden_crop_settings.php — Garden: the farm's frost dates (set once) and how each crop grows (Nov 19, 2026;
//   migration 0075, admin/garden/crops.php crop editor, admin/garden/areas.php settings, admin/garden/plantings.php).
//   Frost dates  garden_farm_frost() / garden_farm_frost_save(): one pair for the whole farm, month and day only, so they carry
//                over every year. A garden can keep its own pair (garden_growing_sites) when it differs. garden_frost_for() gives
//                the pair a garden uses for a given year.
//   Crop settings  one row per crop (starter-list crop "s12" or the farm's own crop "o5"), stored metric, shown in US units.
//                garden_crop_plan() turns them plus the frost dates into start-indoors, plant-out and ready dates.
//   Reference    garden_reference_match(): the farm's OpenPlantDB copy (garden_openplantdb.php, CC0, ~300 plants) first, then
//                garden_reference_rows(): about 50 common vegetables and herbs (timing around frost, days to harvest, sprouting
//                temperature, spacing, row spacing, frost tolerance) from the Growli sowing and spacing table, CC-BY 4.0, which
//                is drawn from US university extension planting guides. "Fill from reference" copies them in; anything typed wins.
// Load through includes/garden.php.

/** Credit line for the reference numbers (CC-BY 4.0 asks for it wherever they show). */
const GARDEN_REFERENCE_CREDIT = 'Reference numbers: Growli crop sowing & spacing table (getgrowli.app), CC-BY 4.0, from US university extension planting guides.';
const GARDEN_REFERENCE_SOURCE = 'Growli reference';

/**
 * name => [season, start indoors weeks before last frost|null, plant-out days from frost (negative = before),
 *          from fall frost?, days to harvest, sprouting soil temp °C min, max, spacing in min, max, row spacing in min, max, frost].
 */
function garden_reference_rows(): array {
    return [
        ['Arugula', 'cool', null, -28, false, 40, 7, 18, 3, 6, null, null, 'hardy'],
        ['Asparagus', '', null, -21, false, 730, 10, 18, 12, 18, null, null, 'hardy'],
        ['Basil', 'warm', 6, 7, false, 60, 20, 25, 8, 12, null, null, 'tender'],
        ['Beets', 'cool', null, -21, false, 58, 10, 29, 3, 4, null, null, 'half-hardy'],
        ['Broccoli', 'cool', 6, -14, false, 80, 7, 29, 18, 24, null, null, 'hardy'],
        ['Brussels Sprouts', 'cool', 12, -14, false, 90, 7, 29, 18, 24, null, null, 'hardy'],
        ['Bush beans', 'warm', null, 10, false, 55, 18, 29, 4, 6, null, null, 'tender'],
        ['Cabbage', 'cool', 6, -21, false, 80, 7, 29, 12, 24, null, null, 'hardy'],
        ['Cantaloupe', 'warm', 3, 14, false, 80, 24, 32, 36, 48, null, null, 'tender'],
        ['Carrots', 'cool', null, -17, false, 70, 10, 29, 2, 3, null, null, 'half-hardy'],
        ['Cauliflower', 'cool', 6, -14, false, 85, 7, 24, 18, 24, null, null, 'half-hardy'],
        ['Celery', 'cool', 10, -14, false, 115, 15, 21, 8, 12, null, null, 'half-hardy'],
        ['Chives', '', 6, -14, false, 60, 15, 21, 6, 12, null, null, 'hardy'],
        ['Cilantro', 'cool', null, -21, false, 50, 10, 29, 4, 6, null, null, 'half-hardy'],
        ['Collard Greens', 'cool', 4, -21, false, 70, 7, 29, 18, 24, null, null, 'hardy'],
        ['Cucumbers', 'warm', 3, 14, false, 60, 21, 30, 12, 18, null, null, 'tender'],
        ['Dill', 'cool', null, -21, false, 60, 10, 27, 9, 12, null, null, 'hardy'],
        ['Edamame', 'warm', null, 14, false, 80, 16, 35, 6, 6, 18, 24, 'tender'],
        ['Eggplant', 'warm', 9, 14, false, 80, 24, 30, 18, 24, null, null, 'tender'],
        ['Fava Beans', 'cool', null, -42, false, 90, 7, 21, 6, 9, 18, 24, 'half-hardy'],
        ['Garlic', '', null, -35, true, 240, 10, 15, 4, 6, null, null, 'hardy'],
        ['Kale', 'cool', 6, -28, false, 60, 7, 29, 12, 18, null, null, 'hardy'],
        ['Kohlrabi', 'cool', 5, -21, false, 55, 7, 29, 6, 9, null, null, 'half-hardy'],
        ['Leeks', 'cool', 10, -28, false, 120, 7, 25, 6, 6, null, null, 'hardy'],
        ['Lettuce', 'cool', 4, -21, false, 50, 10, 21, 6, 12, null, null, 'hardy'],
        ['Okra', 'warm', 4, 14, false, 60, 21, 35, 12, 18, null, null, 'tender'],
        ['Onions', 'cool', 10, -28, false, 110, 10, 35, 4, 6, null, null, 'hardy'],
        ['Oregano', '', 6, 0, false, 80, 13, 21, 8, 12, null, null, 'hardy'],
        ['Parsley', 'cool', 10, -21, false, 75, 13, 24, 6, 9, null, null, 'half-hardy'],
        ['Parsnips', 'cool', null, -21, false, 110, 7, 18, 3, 6, null, null, 'hardy'],
        ['Peas', 'cool', null, -35, false, 65, 7, 24, 2, 3, null, null, 'hardy'],
        ['Peppers', 'warm', 9, 14, false, 80, 24, 29, 18, 24, null, null, 'tender'],
        ['Pole Beans', 'warm', null, 7, false, 65, 16, 35, 4, 6, 24, 36, 'tender'],
        ['Potatoes', 'cool', null, -21, false, 85, 7, 18, 10, 15, null, null, 'half-hardy'],
        ['Pumpkins', 'warm', 3, 14, false, 100, 21, 32, 36, 84, null, null, 'tender'],
        ['Radishes', 'cool', null, -21, false, 28, 7, 29, 2, 3, null, null, 'half-hardy'],
        ['Rhubarb', '', null, -21, false, 547, 4, 10, 36, 48, null, null, 'hardy'],
        ['Rosemary', '', 10, 14, false, 90, 18, 21, 18, 36, null, null, 'half-hardy'],
        ['Sage', '', 6, 0, false, 75, 21, 24, 18, 24, null, null, 'hardy'],
        ['Spinach', 'cool', null, -35, false, 45, 4, 21, 3, 6, null, null, 'hardy'],
        ['Summer squash', 'warm', 3, 14, false, 55, 21, 29, 24, 36, null, null, 'tender'],
        ['Sweet corn', 'warm', null, 10, false, 75, 16, 34, 9, 12, null, null, 'tender'],
        ['Sweet potatoes', 'warm', 6, 21, false, 105, 18, 24, 12, 18, null, null, 'tender'],
        ['Swiss Chard', 'cool', 4, -7, false, 55, 10, 29, 9, 12, null, null, 'half-hardy'],
        ['Thyme', '', 8, 0, false, 85, 18, 21, 6, 8, null, null, 'hardy'],
        ['Tomatoes', 'warm', 6, 10, false, 75, 21, 27, 24, 36, null, null, 'tender'],
        ['Turnips', 'cool', null, -28, false, 50, 7, 29, 4, 6, null, null, 'hardy'],
        ['Watermelon', 'warm', 4, 14, false, 80, 21, 35, 36, 48, 72, 96, 'tender'],
        ['Winter squash', 'warm', 3, 14, false, 95, 21, 29, 24, 72, null, null, 'tender'],
        ['Zucchini', 'warm', 3, 14, false, 55, 21, 29, 24, 36, null, null, 'tender'],
    ];
}

/** A crop name made comparable: lower case, plural dropped ("Tomatoes" = "tomato", "Collard Greens" = "collard green"). */
function garden_reference_norm(string $name): string {
    $n = strtolower(trim(preg_replace('/\s+/', ' ', preg_replace('/\(.*?\)|[^a-z ]/i', ' ', $name))));
    $words = array_map(function ($w) {
        if (strlen($w) > 4 && preg_match('/(oes|shes|ches)$/', $w)) return substr($w, 0, -2);
        if (strlen($w) > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') return substr($w, 0, -1);
        return $w;
    }, explode(' ', $n));
    return trim(implode(' ', $words));
}

/** The reference row for a crop name, as named fields; null when the table has nothing close. */
/**
 * Reference numbers for a crop name: the farm's OpenPlantDB copy first (garden_openplantdb.php, when $pdo is given and it has
 * been loaded), then the embedded Growli table for anything still blank. Null when neither knows the crop. 'source' names
 * which table(s) gave the numbers.
 */
function garden_reference_match(string $cropName, ?PDO $pdo = null): ?array {
    $aliases = ['bean' => 'bush bean', 'green bean' => 'bush bean', 'snap bean' => 'bush bean', 'runner bean' => 'pole bean', 'pea' => 'pea', 'snap pea' => 'pea', 'sugar snap pea' => 'pea',
                'chard' => 'swiss chard', 'collard' => 'collard green', 'corn' => 'sweet corn', 'squash' => 'summer squash', 'yellow squash' => 'summer squash',
                'melon' => 'cantaloupe', 'muskmelon' => 'cantaloupe', 'pepper' => 'pepper', 'bell pepper' => 'pepper', 'hot pepper' => 'pepper', 'chili pepper' => 'pepper',
                'cherry tomato' => 'tomato', 'onion' => 'onion', 'scallion' => 'onion', 'green onion' => 'onion', 'leek' => 'leek', 'coriander' => 'cilantro',
                'irish potato' => 'potato', 'sweet potato' => 'sweet potato', 'soybean' => 'edamame', 'broad bean' => 'fava bean', 'butternut squash' => 'winter squash', 'acorn squash' => 'winter squash',
                'courgette' => 'zucchini', 'beetroot' => 'beet', 'rocket' => 'arugula', 'eggplant' => 'eggplant', 'aubergine' => 'eggplant', 'okra' => 'okra'];
    $want = garden_reference_norm($cropName);
    if ($want === '') return null;
    $alias = $aliases[$want] ?? $want;
    $growli = null;
    foreach (garden_reference_rows() as $r) {
        if (garden_reference_norm($r[0]) === $alias) { $growli = garden_reference_fields($r); break; }
    }
    $opdb = $pdo ? (garden_opdb_match($pdo, $want) ?? garden_opdb_match($pdo, $alias)) : null;
    if (!$opdb) return $growli ? $growli + ['source' => GARDEN_REFERENCE_SOURCE, 'germination_min_days' => null, 'germination_max_days' => null, 'water_need' => null] : null;
    $out = $opdb + ['row_spacing_cm' => null, 'row_range_in' => ''];
    $out['source'] = GARDEN_OPDB_SOURCE;
    if ($growli) {
        $used = false;
        foreach ($growli as $k => $v) {
            if ($k === 'name' || $v === null || $v === '') continue;
            if (($out[$k] ?? null) !== null && ($out[$k] ?? '') !== '') continue;
            if ($k === 'plant_out_from' && $out['plant_out_days'] !== null) continue;
            if ($k === 'plant_out_days' || $k === 'start_indoors_weeks') {
                // Timing travels as a set: only borrow Growli's plant-out when OpenPlantDB had none.
                if ($out['plant_out_days'] !== null) continue;
                $out['plant_out_from'] = $growli['plant_out_from'];
            }
            $out[$k] = $v; $used = true;
        }
        if ($used) $out['source'] = GARDEN_OPDB_SOURCE . ' + ' . GARDEN_REFERENCE_SOURCE;
    }
    return $out;
}

/** The credit line for a reference match (Growli's CC-BY 4.0 asks for credit; OpenPlantDB is public domain but gets a thank-you). */
function garden_reference_credit(?array $ref): string {
    $src = $ref['source'] ?? GARDEN_REFERENCE_SOURCE;
    $o = 'OpenPlantDB (github.com/cwfrazier1/openplantdb), public domain (CC0).';
    if ($src === GARDEN_OPDB_SOURCE) return 'Reference numbers: ' . $o;
    if (str_starts_with($src, GARDEN_OPDB_SOURCE)) return 'Reference numbers: ' . $o . ' Row spacing and missing timing: ' . substr(GARDEN_REFERENCE_CREDIT, strlen('Reference numbers: '));
    return GARDEN_REFERENCE_CREDIT;
}

/** One reference row as crop-settings fields (metric, like the table). */
function garden_reference_fields(array $r): array {
    [$name, $season, $weeks, $offset, $fromFall, $days, $tMin, $tMax, $sMin, $sMax, $rMin, $rMax, $frost] = $r;
    $mid = fn($a, $b) => $a === null ? null : round(((float)$a + (float)$b) / 2 * 2.54, 1);   // a range's middle, inches -> cm
    return [
        'name' => $name,
        'season' => $season ?: null,
        'start_indoors_weeks' => $weeks,
        'plant_out_days' => $offset,
        'plant_out_from' => $fromFall ? 'fall' : 'spring',
        'days_to_harvest' => $days,
        'germ_temp_min_c' => $tMin, 'germ_temp_max_c' => $tMax,
        'spacing_cm' => $mid($sMin, $sMax),
        'row_spacing_cm' => $mid($rMin, $rMax),
        'sun_exposure' => 'full_sun',
        'frost_tolerance' => str_replace('-', '_', $frost),
        'spacing_range_in' => $sMin === null ? '' : ($sMin == $sMax ? (string)$sMin : $sMin . '–' . $sMax),
        'row_range_in' => $rMin === null ? '' : ($rMin == $rMax ? (string)$rMin : $rMin . '–' . $rMax),
    ];
}

// ── Frost dates ──

/** Month and day of a date as "MM-DD" ('' when empty). */
function garden_md(?string $d): string { return $d && preg_match('/^\d{4}-(\d{2}-\d{2})$/', $d, $m) ? $m[1] : ''; }

/** A frost date typed in (YYYY-MM-DD from a date box); only month and day are kept (stored in year 2000). */
function garden_frost_in(string $v, string $what): ?string {
    $v = trim($v);
    if ($v === '') return null;
    if (!preg_match('/^\d{4}-(\d{2})-(\d{2})$/', $v, $m) || !checkdate((int)$m[1], (int)$m[2], 2000)) throw new InvalidArgumentException('Enter a real date for the ' . $what . '.');
    return '2000-' . $m[1] . '-' . $m[2];
}

/** The farm's own frost dates: [spring "2000-MM-DD"|null, fall|null]. */
function garden_farm_frost(PDO $pdo, int $org): array {
    try {
        $st = $pdo->prepare('SELECT last_spring_frost, first_fall_frost FROM garden_organizations WHERE id = ?');
        $st->execute([$org]);
        $r = $st->fetch(PDO::FETCH_ASSOC) ?: [];
        return [$r['last_spring_frost'] ?? null, $r['first_fall_frost'] ?? null];
    } catch (Throwable $e) { return [null, null]; }
}

/** Save the farm's frost dates (date boxes, any year). Spring must come before fall. */
function garden_farm_frost_save(PDO $pdo, int $org, string $spring, string $fall): void {
    $s = garden_frost_in($spring, 'last spring frost'); $f = garden_frost_in($fall, 'first fall frost');
    if ($s && $f && $s >= $f) throw new InvalidArgumentException('The first fall frost must come after the last spring frost.');
    $pdo->prepare('UPDATE garden_organizations SET last_spring_frost = ?, first_fall_frost = ? WHERE id = ?')->execute([$s, $f, $org]);
}

/** Save (or clear, with two blanks) one garden's own frost dates. */
function garden_site_frost_save(PDO $pdo, int $org, int $site, string $spring, string $fall): void {
    $s = garden_frost_in($spring, 'last spring frost'); $f = garden_frost_in($fall, 'first fall frost');
    if (($s === null) !== ($f === null)) throw new InvalidArgumentException('Give this garden both frost dates, or leave both empty to use the farm\'s.');
    if ($s && $s >= $f) throw new InvalidArgumentException('The first fall frost must come after the last spring frost.');
    $pdo->prepare('UPDATE garden_growing_sites SET last_spring_frost = ?, first_fall_frost = ? WHERE id = ? AND organization_id = ?')->execute([$s, $f, $site, $org]);
}

/** The growing year to plan: this year, or next year once this year's fall frost has passed. */
function garden_plan_year(?string $fallMd = null, ?string $today = null): int {
    $today ??= bcrf_today();
    $y = (int)substr($today, 0, 4);
    if ($fallMd && substr($today, 5) > $fallMd) $y++;
    return $y;
}

/**
 * The frost dates a garden uses in $year: ['spring' => 'YYYY-MM-DD'|'', 'fall' => …, 'from' => 'garden'|'farm'|'', 'year' => int].
 * A garden's own pair wins; else the farm's. $year null = garden_plan_year().
 */
function garden_frost_for(PDO $pdo, int $org, ?array $site, ?int $year = null): array {
    $s = garden_md($site['last_spring_frost'] ?? null); $f = garden_md($site['first_fall_frost'] ?? null); $from = 'garden';
    if ($s === '' || $f === '') {
        [$fs, $ff] = garden_farm_frost($pdo, $org);
        $s = garden_md($fs); $f = garden_md($ff); $from = 'farm';
    }
    if ($s === '' || $f === '') return ['spring' => '', 'fall' => '', 'from' => '', 'year' => $year ?? garden_plan_year()];
    $year ??= garden_plan_year($f);
    $fix = fn($md) => $md === '02-29' && !checkdate(2, 29, $year) ? $year . '-02-28' : $year . '-' . $md;
    return ['spring' => $fix($s), 'fall' => $fix($f), 'from' => $from, 'year' => $year];
}

// ── Crop settings ──

/** "s12" (starter-list crop) or "o5" (farm crop) -> [crop_id|null, organization_crop_id|null]; throws when it isn't one. */
function garden_crop_key_parts(string $key): array {
    if (preg_match('/^s([1-9]\d*)$/D', $key, $m)) return [(int)$m[1], null];
    if (preg_match('/^o([1-9]\d*)$/D', $key, $m)) return [null, (int)$m[1]];
    throw new InvalidArgumentException('Pick a crop.');
}

/** The crop key a variety picker value belongs to ("12" -> "s<crop>", "custom:5" -> "o<crop>"); '' when unknown. */
function garden_variety_crop_key(PDO $pdo, int $org, string $variety): string {
    if (preg_match('/^custom:([1-9]\d*)$/D', $variety, $m)) {
        $st = $pdo->prepare('SELECT crop_id FROM garden_organization_varieties WHERE id = ? AND organization_id = ?'); $st->execute([(int)$m[1], $org]);
        $c = (int)$st->fetchColumn(); return $c ? 'o' . $c : '';
    }
    if (preg_match('/^[1-9]\d*$/D', $variety)) {
        $st = $pdo->prepare('SELECT crop_id FROM garden_varieties WHERE id = ?'); $st->execute([(int)$variety]);
        $c = (int)$st->fetchColumn(); return $c ? 's' . $c : '';
    }
    return '';
}

/** Every crop's settings for this farm: key ("s12" / "o5") => row. */
function garden_crop_settings_all(PDO $pdo, int $org): array {
    $out = [];
    try {
        $st = $pdo->prepare('SELECT * FROM garden_crop_settings WHERE organization_id = ?'); $st->execute([$org]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) $out[$r['crop_id'] ? 's' . $r['crop_id'] : 'o' . $r['organization_crop_id']] = $r;
    } catch (Throwable $e) { /* before migration 0075 */ }
    return $out;
}

/** One crop's settings ([] when none saved yet). */
function garden_crop_settings_get(PDO $pdo, int $org, string $key): array {
    [$c, $o] = garden_crop_key_parts($key);
    $st = $pdo->prepare('SELECT * FROM garden_crop_settings WHERE organization_id = ? AND ' . ($c ? 'crop_id = ?' : 'organization_crop_id = ?'));
    $st->execute([$org, $c ?: $o]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: [];
}

/** The settings columns a save may set. */
function garden_crop_settings_fields(): array {
    return ['season', 'start_indoors_weeks', 'plant_out_days', 'plant_out_from', 'days_to_harvest', 'germination_min_days', 'germination_max_days',
            'germ_temp_min_c', 'germ_temp_max_c', 'spacing_cm', 'row_spacing_cm', 'sow_depth_cm', 'sun_exposure', 'water_need', 'soil_ph_min', 'soil_ph_max',
            'frost_tolerance', 'notes'];
}

/**
 * The crop editor's form, checked and turned metric. Inches for spacing and depth, °F for sprouting temperature,
 * plant-out as a number of days + before/after + which frost.
 */
function garden_crop_settings_in(array $in): array {
    $g = fn($k) => trim((string)($in[$k] ?? ''));
    $int = function (string $k, string $what, int $min, int $max) use ($g): ?int {
        $v = $g($k);
        if ($v === '') return null;
        if (!preg_match('/^\d+$/', $v) || (int)$v < $min || (int)$v > $max) throw new InvalidArgumentException($what . ' must be a whole number from ' . $min . ' to ' . $max . '.');
        return (int)$v;
    };
    $num = function (string $k, string $what, float $min, float $max) use ($g): ?float {
        $v = $g($k);
        if ($v === '') return null;
        if (!is_numeric($v) || (float)$v < $min || (float)$v > $max) throw new InvalidArgumentException($what . ' must be a number from ' . garden_num($min) . ' to ' . garden_num($max) . '.');
        return (float)$v;
    };
    $pick = function (string $k, array $ok, $def) use ($g) { $v = $g($k); return in_array($v, $ok, true) ? $v : $def; };
    $out = [];
    $out['season'] = $pick('season', ['warm', 'cool'], null);
    $out['start_indoors_weeks'] = $int('start_indoors_weeks', 'Weeks to start indoors', 1, 20);
    $days = $int('plant_out_days', 'Days from frost', 0, 180);
    $out['plant_out_days'] = $days === null ? null : ($g('plant_out_when') === 'before' ? -$days : $days);
    $out['plant_out_from'] = $pick('plant_out_from', ['spring', 'fall'], 'spring');
    $out['days_to_harvest'] = $int('days_to_harvest', 'Days to harvest', 1, 999);
    $gMin = $int('germination_min_days', 'Sprouts in (days)', 1, 365); $gMax = $int('germination_max_days', 'Sprouts in (days)', 1, 365);
    if ($gMin !== null && $gMax === null) $gMax = $gMin;
    if ($gMax !== null && $gMin === null) $gMin = $gMax;
    if ($gMin !== null && $gMin > $gMax) throw new InvalidArgumentException('Sprouts in: the first number must be the smaller one.');
    $out['germination_min_days'] = $gMin; $out['germination_max_days'] = $gMax;
    $tMin = $num('germ_temp_min_f', 'Soil temperature', 25, 110); $tMax = $num('germ_temp_max_f', 'Soil temperature', 25, 110);
    if ($tMin !== null && $tMax !== null && $tMin > $tMax) throw new InvalidArgumentException('Soil temperature: the first number must be the lower one.');
    $c = fn($f) => $f === null ? null : round(($f - 32) * 5 / 9, 1);
    $out['germ_temp_min_c'] = $c($tMin); $out['germ_temp_max_c'] = $c($tMax);
    foreach (['spacing' => 'Plant spacing', 'row_spacing' => 'Row spacing'] as $k => $what) { $v = $num($k . '_in', $what, 0.1, 240); $out[$k . '_cm'] = $v === null ? null : round($v * 2.54, 1); }
    $d = $num('sow_depth_in', 'Sow depth', 0, 12); $out['sow_depth_cm'] = $d === null ? null : round($d * 2.54, 1);
    $out['sun_exposure'] = $pick('sun_exposure', ['unknown', 'full_sun', 'partial_shade', 'shade'], 'unknown');
    $out['water_need'] = $pick('water_need', ['unknown', 'low', 'moderate', 'high'], 'unknown');
    $p1 = $num('soil_ph_min', 'Soil pH', 3, 10); $p2 = $num('soil_ph_max', 'Soil pH', 3, 10);
    if (($p1 === null) !== ($p2 === null) || ($p1 !== null && $p1 > $p2)) throw new InvalidArgumentException('Give soil pH as a low and a high number, or leave both empty.');
    $out['soil_ph_min'] = $p1; $out['soil_ph_max'] = $p2;
    $out['frost_tolerance'] = $pick('frost_tolerance', ['tender', 'half_hardy', 'hardy'], null);
    $n = $g('notes');
    if (mb_strlen($n) > 4000) throw new InvalidArgumentException('Notes must be 4000 characters or fewer.');
    $out['notes'] = $n === '' ? null : $n;
    return $out;
}

/** Save a crop's settings (only the keys given; others stay). $source: where they came from, null = typed in. */
function garden_crop_settings_save(PDO $pdo, int $org, string $key, array $data, ?string $source = null): void {
    [$c, $o] = garden_crop_key_parts($key);
    if ($c) { $st = $pdo->prepare('SELECT 1 FROM garden_crops WHERE id = ?'); $st->execute([$c]); }
    else { $st = $pdo->prepare('SELECT 1 FROM garden_organization_crops WHERE id = ? AND organization_id = ?'); $st->execute([$o, $org]); }
    if (!$st->fetchColumn()) throw new InvalidArgumentException('That crop is gone.');
    $ok = garden_crop_settings_fields();
    $cols = []; $vals = [];
    foreach ($data as $k => $v) if (in_array($k, $ok, true)) { $cols[] = $k; $vals[] = $v; }
    $cols[] = 'source'; $vals[] = $source;
    $ins = implode(', ', $cols); $ph = implode(', ', array_fill(0, count($cols), '?'));
    $upd = implode(', ', array_map(fn($k) => "`$k` = VALUES(`$k`)", $cols)) . ', updated_at = UTC_TIMESTAMP()';
    $pdo->prepare("INSERT INTO garden_crop_settings (organization_id, crop_id, organization_crop_id, $ins) VALUES (?, ?, ?, $ph) ON DUPLICATE KEY UPDATE $upd")
        ->execute(array_merge([$org, $c, $o], $vals));
}

/**
 * Copy the reference numbers for $cropName into the crop's settings. $onlyBlanks: keep anything already filled in.
 * Returns how many fields changed (0 = nothing to add); throws when the reference has no match.
 */
function garden_crop_reference_fill(PDO $pdo, int $org, string $key, string $cropName, bool $onlyBlanks = true): int {
    $ref = garden_reference_match($cropName, $pdo);
    if (!$ref) throw new InvalidArgumentException('The reference table has nothing for ' . $cropName . '. Type the numbers from the seed packet.');
    $cur = garden_crop_settings_get($pdo, $org, $key);
    $set = [];
    foreach (['season', 'start_indoors_weeks', 'plant_out_days', 'plant_out_from', 'days_to_harvest', 'germination_min_days', 'germination_max_days', 'germ_temp_min_c', 'germ_temp_max_c', 'spacing_cm', 'row_spacing_cm', 'sun_exposure', 'water_need', 'frost_tolerance'] as $k) {
        $v = $ref[$k] ?? null;
        if ($v === null) continue;
        $timing = in_array($k, ['start_indoors_weeks', 'plant_out_days', 'plant_out_from'], true);
        // Timing is one set: once plant-out is filled in, an empty "start indoors" means sown outside, not missing.
        $blank = $timing ? ($cur['plant_out_days'] ?? null) === null
                         : (!isset($cur[$k]) || $cur[$k] === null || $cur[$k] === '' || $cur[$k] === 'unknown');
        if ($onlyBlanks && !$blank) continue;
        if (isset($cur[$k]) && (string)$cur[$k] === (string)$v) continue;
        $set[$k] = $v;
    }
    if (!$set) return 0;
    garden_crop_settings_save($pdo, $org, $key, $set, $cur ? (($cur['source'] ?? null) ?: $ref['source']) : $ref['source']);
    return count($set);
}

/**
 * Dates for a crop from its settings and the frost dates (see garden_frost_for): ['indoors' => date|null, 'out' => date|null,
 * 'ready' => date|null, 'how' => 'transplant'|'direct'|'', 'late' => bool (ready after fall frost)]. $days: days to harvest to use
 * (the variety's, else the crop's).
 */
function garden_crop_plan(array $s, array $frost, ?int $days = null): array {
    $out = ['indoors' => null, 'out' => null, 'ready' => null, 'how' => '', 'late' => false];
    if (!$s || ($s['plant_out_days'] ?? null) === null || $frost['spring'] === '') return $out;
    $base = ($s['plant_out_from'] ?? 'spring') === 'fall' ? $frost['fall'] : $frost['spring'];
    $add = fn(string $d, int $n) => (new DateTimeImmutable($d))->modify(($n >= 0 ? '+' : '') . $n . ' days')->format('Y-m-d');
    $out['out'] = $add($base, (int)$s['plant_out_days']);
    $out['how'] = $s['start_indoors_weeks'] ? 'transplant' : 'direct';
    if ($s['start_indoors_weeks']) $out['indoors'] = $add($frost['spring'], -7 * (int)$s['start_indoors_weeks']);
    $days ??= $s['days_to_harvest'] !== null ? (int)$s['days_to_harvest'] : null;
    if ($days) {
        $out['ready'] = $add($out['out'], $days);
        $out['late'] = ($s['plant_out_from'] ?? 'spring') === 'spring' && $out['ready'] > $frost['fall'] && ($s['frost_tolerance'] ?? '') === 'tender';
    }
    return $out;
}

/** "10 days after the last spring frost" from settings ('' when not set). */
function garden_crop_timing_words(array $s): string {
    if (($s['plant_out_days'] ?? null) === null) return '';
    $n = (int)$s['plant_out_days'];
    $frost = ($s['plant_out_from'] ?? 'spring') === 'fall' ? 'the first fall frost' : 'the last spring frost';
    $when = $n === 0 ? 'around ' . $frost : ($n % 7 === 0 && abs($n) >= 14 ? abs($n) / 7 . ' weeks ' : abs($n) . ' day' . (abs($n) === 1 ? ' ' : 's ')) . ($n < 0 ? 'before ' : 'after ') . $frost;
    return ($s['start_indoors_weeks'] ? 'Start indoors ' . (int)$s['start_indoors_weeks'] . ' weeks before the last spring frost; plant out ' : 'Sow outside ') . $when;
}

/** Where a crop is used: ['planned' => n, 'active' => n, 'done' => n (completed or failed), 'lots' => n seed lots]. */
function garden_crop_usage(PDO $pdo, int $org, string $key): array {
    [$c, $o] = garden_crop_key_parts($key);
    $join = $c ? 'JOIN garden_varieties v ON v.id = x.variety_id AND v.crop_id = ?' : 'JOIN garden_organization_varieties v ON v.id = x.organization_variety_id AND v.organization_id = x.organization_id AND v.crop_id = ?';
    $out = ['planned' => 0, 'active' => 0, 'done' => 0, 'lots' => 0];
    $st = $pdo->prepare("SELECT x.status, COUNT(*) FROM garden_plantings x $join WHERE x.organization_id = ? GROUP BY x.status");
    $st->execute([$c ?: $o, $org]);
    foreach ($st->fetchAll(PDO::FETCH_NUM) as [$s, $n]) { $k = in_array($s, ['completed', 'failed'], true) ? 'done' : $s; $out[$k] = ($out[$k] ?? 0) + (int)$n; }
    $st = $pdo->prepare("SELECT COUNT(*) FROM garden_seed_lots x $join WHERE x.organization_id = ?");
    $st->execute([$c ?: $o, $org]);
    $out['lots'] = (int)$st->fetchColumn();
    return $out;
}
