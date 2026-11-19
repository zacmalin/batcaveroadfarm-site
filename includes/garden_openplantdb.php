<?php
// includes/garden_openplantdb.php — Garden: a local copy of OpenPlantDB (Nov 19, 2026; migration 0075 garden_reference_plants,
//   admin/garden/crops.php "Get the latest OpenPlantDB numbers").
//   OpenPlantDB (github.com/cwfrazier1/openplantdb) is a public-domain (CC0) table of about 300 garden plants: days to sprout,
//   days to maturity, spacing, sun, water, frost tolerance and weeks around frost. The server downloads data/plants.csv once
//   (or someone uploads plants.csv / plants.json) and keeps only the numbers Farm OS uses, so nothing depends on GitHub later.
//   garden_reference_match() (garden_crop_settings.php) uses this copy first and the embedded Growli table for what it lacks
//   (row spacing, and plant-out timing for crops started indoors).
// Load through includes/garden.php.

const GARDEN_OPDB_CSV_URL  = 'https://raw.githubusercontent.com/cwfrazier1/openplantdb/main/data/plants.csv';
const GARDEN_OPDB_SOURCE   = 'OpenPlantDB';
const GARDEN_OPDB_MAX_BYTES = 80 * 1024 * 1024;

/** How many plants the local copy holds and when it was last loaded: ['count' => n, 'at' => 'Y-m-d H:i:s' UTC|null]. */
function garden_opdb_status(PDO $pdo): array {
    try {
        $r = $pdo->query('SELECT COUNT(*) n, MAX(imported_at) at FROM garden_reference_plants')->fetch(PDO::FETCH_ASSOC);
        return ['count' => (int)$r['n'], 'at' => $r['at'] ?: null];
    } catch (PDOException) { return ['count' => 0, 'at' => null]; }
}

/** Download plants.csv from GitHub into a temp file and load it. Returns the number of plants loaded. */
function garden_opdb_download(PDO $pdo, string $url = GARDEN_OPDB_CSV_URL): int {
    $tmp = tempnam(sys_get_temp_dir(), 'opdb');
    try {
        $ok = false; $err = '';
        if (function_exists('curl_init')) {
            $fh = fopen($tmp, 'wb');
            $ch = curl_init($url);
            curl_setopt_array($ch, [CURLOPT_FILE => $fh, CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 5, CURLOPT_CONNECTTIMEOUT => 15,
                                    CURLOPT_TIMEOUT => 120, CURLOPT_USERAGENT => 'FarmOS-garden/1.0', CURLOPT_FAILONERROR => true]);
            $ok = curl_exec($ch) !== false;
            $err = $ok ? '' : curl_error($ch);
            curl_close($ch); fclose($fh);
        } else {
            $ok = @copy($url, $tmp);
        }
        if (!$ok || filesize($tmp) < 100) throw new DomainException('Could not download OpenPlantDB' . ($err ? ' (' . $err . ')' : '') . '. Try again later, or upload plants.csv from the OpenPlantDB page.');
        if (filesize($tmp) > GARDEN_OPDB_MAX_BYTES) throw new DomainException('The OpenPlantDB file is larger than expected. Nothing changed.');
        return garden_opdb_import_file($pdo, $tmp, 'plants.csv');
    } finally { @unlink($tmp); }
}

/**
 * Load a plants.csv or plants.json file into garden_reference_plants, replacing the old copy. $name: the original file name
 * (decides CSV or JSON). Throws, leaving the old copy, when fewer than 20 usable plants are found.
 */
function garden_opdb_import_file(PDO $pdo, string $path, string $name = 'plants.csv'): int {
    $rows = [];
    $json = str_ends_with(strtolower($name), '.json');
    if (!$json) {
        $fh = fopen($path, 'rb');
        if (!$fh) throw new DomainException('Could not read the file.');
        $first = fread($fh, 1); rewind($fh);
        if ($first === '[' || $first === '{') $json = true;
        else {
            $head = fgetcsv($fh, 0, ',', '"', '');
            if ($head) {
                $head = array_map(fn($k) => strtolower(trim(preg_replace('/^\xEF\xBB\xBF/', '', (string)$k))), $head);
                while (($line = fgetcsv($fh, 0, ',', '"', '')) !== false) {
                    if ($line === [null]) continue;
                    $p = garden_opdb_from_flat(array_combine($head, array_pad(array_slice($line, 0, count($head)), count($head), '')));
                    if ($p) $rows[$p['slug']] = $p;
                }
            }
        }
        fclose($fh);
    }
    if ($json) {
        if (filesize($path) > GARDEN_OPDB_MAX_BYTES) throw new DomainException('That file is too large.');
        $d = json_decode((string)file_get_contents($path), true);
        if (is_array($d) && !array_is_list($d)) $d = $d['plants'] ?? $d['data'] ?? array_values($d);
        foreach ((array)$d as $rec) {
            if (!is_array($rec)) continue;
            $p = garden_opdb_from_record($rec);
            if ($p) $rows[$p['slug']] = $p;
        }
    }
    if (count($rows) < 20) throw new DomainException('That file did not look like OpenPlantDB (found ' . count($rows) . ' usable plants). Nothing changed.');
    $pdo->beginTransaction();
    try {
        $pdo->exec('DELETE FROM garden_reference_plants');
        $ins = $pdo->prepare('INSERT INTO garden_reference_plants (slug, name, name_norm, category, data, imported_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())');
        foreach ($rows as $p) $ins->execute([$p['slug'], $p['name'], garden_reference_norm($p['name']), $p['category'], json_encode($p['fields'])]);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    garden_opdb_forget();
    return count($rows);
}

/** A range from a record value: {min,max} object, a number, or text like "5-10" / "5 to 10". Returns [min|null, max|null]. */
function garden_opdb_range($v): array {
    if (is_array($v)) {
        $a = $v['min'] ?? null; $b = $v['max'] ?? null;
        $a = is_numeric($a) ? (float)$a : null; $b = is_numeric($b) ? (float)$b : null;
        return [$a ?? $b, $b ?? $a];
    }
    if (is_int($v) || is_float($v)) return [(float)$v, (float)$v];
    $v = trim((string)$v);
    if ($v === '') return [null, null];
    if ($v[0] === '{') return garden_opdb_range(json_decode($v, true) ?: []);
    if (preg_match('/^(-?\d+(?:\.\d+)?)\s*(?:-|–|to|\.\.)\s*(-?\d+(?:\.\d+)?)$/i', $v, $m)) return [(float)$m[1], (float)$m[2]];
    if (is_numeric($v)) return [(float)$v, (float)$v];
    return [null, null];
}

/** One CSV row (header => value) to a plant (see garden_opdb_from_record), accepting flattened column names. */
function garden_opdb_from_flat(array $row): ?array {
    $get = function (array $names) use ($row) {
        foreach ($names as $n) if (isset($row[$n]) && trim((string)$row[$n]) !== '') return trim((string)$row[$n]);
        return null;
    };
    $range = function (string $base, array $alt = []) use ($row, $get) {
        foreach (array_merge([$base], $alt) as $b) {
            $lo = $get([$b . '_min', $b . '.min', 'min_' . $b]); $hi = $get([$b . '_max', $b . '.max', 'max_' . $b]);
            if ($lo !== null || $hi !== null) return ['min' => is_numeric($lo) ? (float)$lo : null, 'max' => is_numeric($hi) ? (float)$hi : null];
            if (($v = $get([$b])) !== null) { [$a, $z] = garden_opdb_range($v); return ['min' => $a, 'max' => $z]; }
        }
        return null;
    };
    $planting = $get(['planting']);
    $planting = $planting !== null && str_starts_with($planting, '{') ? (json_decode($planting, true) ?: []) : [];
    $rec = [
        'slug' => $get(['slug', 'id']),
        'common_name' => $get(['common_name', 'name']),
        'category' => $get(['category']),
        'season' => $get(['season']),
        'sow_method' => $get(['sow_method']),
        'frost_tolerance' => $get(['frost_tolerance']),
        'sun' => $get(['sun']),
        'water' => $get(['water']),
        'maturity_from' => $get(['maturity_from']),
        'days_to_germination' => $range('days_to_germination'),
        'germination_soil_temp_f' => $range('germination_soil_temp_f'),
        'days_to_maturity' => $range('days_to_maturity'),
        'spacing_in' => $range('spacing_in'),
        'planting' => [
            'anchor' => $planting['anchor'] ?? $get(['planting_anchor', 'planting.anchor', 'anchor']),
            'weeks_from_anchor' => $planting['weeks_from_anchor'] ?? $range('planting_weeks_from_anchor', ['planting.weeks_from_anchor', 'weeks_from_anchor']),
        ],
    ];
    return garden_opdb_from_record($rec);
}

/**
 * One OpenPlantDB record (SCHEMA.md shape) to ['slug', 'name', 'category', 'fields' => garden_crop_settings columns]. Null when
 * it has no name or no number Farm OS uses.
 */
function garden_opdb_from_record(array $p): ?array {
    $name = trim((string)($p['common_name'] ?? $p['name'] ?? ''));
    if ($name === '') return null;
    $slug = trim((string)($p['slug'] ?? '')) ?: preg_replace('/[^a-z0-9]+/', '-', strtolower($name));
    $mid = fn(array $r) => $r[0] === null ? null : ($r[0] + $r[1]) / 2;
    $germ = garden_opdb_range($p['days_to_germination'] ?? null);
    $temp = garden_opdb_range($p['germination_soil_temp_f'] ?? null);
    $mat  = garden_opdb_range($p['days_to_maturity'] ?? null);
    $spc  = garden_opdb_range($p['spacing_in'] ?? null);
    $pl   = (array)($p['planting'] ?? []);
    $wk   = garden_opdb_range($pl['weeks_from_anchor'] ?? null);
    $anchor = strtolower((string)($pl['anchor'] ?? ''));
    $sow  = strtolower((string)($p['sow_method'] ?? ''));
    $toC  = fn($f) => $f === null ? null : round(($f - 32) * 5 / 9, 1);
    $f = [
        'season' => in_array($s = strtolower((string)($p['season'] ?? '')), ['warm', 'cool'], true) ? $s : null,
        'start_indoors_weeks' => null, 'plant_out_days' => null, 'plant_out_from' => null,
        'days_to_harvest' => null,
        'germination_min_days' => $germ[0] === null ? null : max(1, (int)round($germ[0])),
        'germination_max_days' => $germ[1] === null ? null : max(1, (int)round($germ[1])),
        'germ_temp_min_c' => $toC($temp[0]), 'germ_temp_max_c' => $toC($temp[1]),
        'spacing_cm' => $mid($spc) === null ? null : round($mid($spc) * 2.54, 1),
        'sun_exposure' => ['full' => 'full_sun', 'partial' => 'partial_shade', 'shade' => 'shade'][strtolower((string)($p['sun'] ?? ''))] ?? null,
        'water_need' => in_array($w = strtolower((string)($p['water'] ?? '')), ['low', 'moderate', 'high'], true) ? $w : null,
        'frost_tolerance' => in_array($t = str_replace('-', '_', strtolower((string)($p['frost_tolerance'] ?? ''))), ['tender', 'half_hardy', 'hardy'], true) ? $t : null,
        'spacing_range_in' => $spc[0] === null ? '' : ($spc[0] == $spc[1] ? garden_opdb_num($spc[0]) : garden_opdb_num($spc[0]) . '–' . garden_opdb_num($spc[1])),
    ];
    $w = $mid($wk);
    if ($sow === 'indoor-start' && $w !== null && $w < 0 && $anchor === 'last_frost') {
        // Start indoors this many weeks before the last frost; plant-out timing comes from the other table (or is typed in).
        $f['start_indoors_weeks'] = max(1, min(20, (int)round(-$w)));
    } elseif ($w !== null && $anchor !== '') {
        // Sown or set out relative to a frost date. "Soil workable" and "spring" are taken as about 4 weeks before the last frost.
        $days = (int)round($w * 7) - (in_array($anchor, ['soil_workable', 'spring'], true) ? 28 : 0);
        $f['plant_out_days'] = max(-180, min(180, $days));
        $f['plant_out_from'] = in_array($anchor, ['first_frost', 'fall'], true) ? 'fall' : 'spring';
    }
    if (($d = $mid($mat)) !== null) {
        // Farm OS counts days to harvest from planting out; a from-seed count for an indoor start drops the weeks indoors.
        if (strtolower((string)($p['maturity_from'] ?? '')) === 'seed' && $f['start_indoors_weeks']) $d -= 7 * $f['start_indoors_weeks'];
        $f['days_to_harvest'] = max(1, min(999, (int)round($d)));
    }
    $useful = array_filter($f, fn($v, $k) => $v !== null && $v !== '' && $k !== 'season', ARRAY_FILTER_USE_BOTH);
    if (!$useful) return null;
    return ['slug' => mb_substr($slug, 0, 120), 'name' => mb_substr($name, 0, 160), 'category' => mb_substr((string)($p['category'] ?? ''), 0, 30), 'fields' => $f];
}

function garden_opdb_num(float $n): string { return rtrim(rtrim(number_format($n, 1, '.', ''), '0'), '.'); }

/** The local OpenPlantDB plant for a crop name (after aliases), or null. Vegetables and herbs win over flowers of the same name. */
function garden_opdb_match(PDO $pdo, string $norm): ?array {
    $cache = &$GLOBALS['garden_opdb_cache'];
    $id = spl_object_id($pdo);
    if (!isset($cache[$id])) {
        $cache[$id] = [];
        try {
            foreach ($pdo->query("SELECT slug, name, name_norm, data FROM garden_reference_plants ORDER BY FIELD(category, 'berry', 'fruit', 'herb', 'vegetable') DESC, slug")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                foreach ([$r['name_norm'], garden_reference_norm(str_replace('-', ' ', $r['slug']))] as $k) $cache[$id][$k] ??= $r;
            }
        } catch (PDOException) {}
    }
    $r = $cache[$id][$norm] ?? null;
    if (!$r) return null;
    $f = json_decode($r['data'], true) ?: [];
    $f['name'] = $r['name'];
    return $f;
}

/** Forget the per-request cache (after an import, and in tests). */
function garden_opdb_forget(): void { $GLOBALS['garden_opdb_cache'] = []; }
