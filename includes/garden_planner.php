<?php
// includes/garden_planner.php — Garden bed planner (Nov 19, 2026; migration 0073, admin/garden/areas.php map + schedule tabs,
//   admin/assets/garden.js). Loaded by includes/garden.php.
//   - Bed details: which way it runs, sun, watering, ground cover, soil type (labels for forms and the map).
//   - Time spans: each planting is in its bed from planted_on to ended_on (Done/Failed), else expected_end_on, else
//     planted_on + days to maturity + a 3-week picking window. Perennials with no end stay until they are closed.
//   - Seasons: "now", a whole year, or spring/summer/fall/winter of a year; the map shows plantings whose span touches it.
//   - Rotation: the same crop family in the same bed within 3 years of an earlier planting is flagged (a warning, never a block).
//   - Occupancy: plantings in one bed at the same time; flagged when their bed space adds up past the bed, or when space
//     isn't set so the fit can't be checked.
//   - Lay out many: a grid of beds (rows × columns, path between) made and placed in one step.
// Stored metric (m, m²); shown and typed in feet.
if (!function_exists('garden_bed_choices')) {

/** Choice lists for the bed detail fields: field => [value => label]. */
function garden_bed_choices(): array {
    return [
        'orientation'  => ['n_s' => 'North–south', 'e_w' => 'East–west', 'ne_sw' => 'Northeast–southwest', 'nw_se' => 'Northwest–southeast'],
        'sun_exposure' => ['full_sun' => 'Full sun (6+ hours)', 'part_sun' => 'Part sun (4–6 hours)', 'part_shade' => 'Part shade (2–4 hours)', 'full_shade' => 'Shade (under 2 hours)'],
        'irrigation'   => ['drip' => 'Drip line', 'soaker' => 'Soaker hose', 'sprinkler' => 'Sprinkler', 'hand' => 'Hand watering', 'none' => 'Rain only', 'other' => 'Other'],
        'ground_cover' => ['bare' => 'Bare soil', 'landscape_fabric' => 'Landscape fabric', 'plastic_mulch' => 'Plastic mulch', 'straw' => 'Straw', 'wood_chips' => 'Wood chips', 'compost' => 'Compost', 'cover_crop' => 'Cover crop', 'other' => 'Other'],
        'soil_type'    => ['loam' => 'Loam', 'clay_loam' => 'Clay loam', 'clay' => 'Clay', 'sandy_loam' => 'Sandy loam', 'sandy' => 'Sandy', 'silt' => 'Silt', 'raised_bed_mix' => 'Raised bed mix', 'potting_mix' => 'Potting mix', 'other' => 'Other'],
    ];
}
/** Colors a bed can be given (migration 0074): #rrggbb => name. */
function garden_bed_colors(): array {
    return ['#8a3324' => 'Barn red', '#c0612b' => 'Pumpkin', '#d9a441' => 'Wheat', '#e8c547' => 'Sunflower', '#6aa84f' => 'Leaf green', '#3f7d3a' => 'Forest',
            '#4a9b8e' => 'Teal', '#4f7cac' => 'Sky blue', '#2f4f7f' => 'Navy', '#8e6bb3' => 'Lavender', '#c46b8f' => 'Rose', '#79523a' => 'Brown', '#7d7d7d' => 'Stone gray'];
}
/** Bed shapes: key => label. Length × width is the box the shape fits in. */
function garden_bed_shapes(): array {
    return ['rect' => 'Rectangle', 'round' => 'Round', 'triangle' => 'Triangle'];
}
/** How much of the length × width box a shape covers. */
function garden_shape_factor(string $shape): float {
    return $shape === 'round' ? M_PI / 4 : ($shape === 'triangle' ? 0.5 : 1.0);
}
/** Field => label for the bed detail fields. */
function garden_bed_fields(): array {
    return ['orientation' => 'Runs', 'sun_exposure' => 'Sun', 'irrigation' => 'Watering', 'ground_cover' => 'Ground cover', 'soil_type' => 'Soil'];
}

/** Bed details from the posted form, checked: field => value|null, plus notes. */
function garden_bed_details_in(): array {
    $out = [];
    foreach (garden_bed_choices() as $f => $opts) {
        $v = trim((string)($_POST[$f] ?? ''));
        if ($v !== '' && !isset($opts[$v])) throw new InvalidArgumentException('Choose from the list for ' . strtolower(garden_bed_fields()[$f]) . '.');
        $out[$f] = $v === '' ? null : $v;
    }
    $notes = trim((string)($_POST['bed_notes'] ?? ''));
    if (mb_strlen($notes) > 2000) throw new InvalidArgumentException('Bed notes must be 2000 characters or fewer.');
    $out['notes'] = $notes === '' ? null : $notes;
    if (array_key_exists('color', $_POST)) {   // forms without the color picker leave the color alone
        $c = strtolower(trim((string)$_POST['color']));
        if ($c !== '' && !isset(garden_bed_colors()[$c])) throw new InvalidArgumentException('Pick a bed color from the list.');
        $out['color'] = $c === '' ? null : $c;
    }
    if (array_key_exists('shape', $_POST)) {   // same: forms without the shape picker leave it alone
        $sh = trim((string)$_POST['shape']);
        if (!isset(garden_bed_shapes()[$sh])) throw new InvalidArgumentException('Pick a bed shape from the list.');
        $out['shape'] = $sh;
    }
    return $out;
}

/** Save a bed's details. Only the keys given are changed. */
function garden_bed_details_save(PDO $pdo, int $org, int $site, int $id, array $d): void {
    $ok = array_merge(array_keys(garden_bed_choices()), ['notes', 'color', 'shape']);
    $set = []; $args = [];
    foreach ($d as $k => $v) { if (!in_array($k, $ok, true)) continue; $set[] = "`$k` = ?"; $args[] = $v; }
    if (!$set) return;
    $args[] = $id; $args[] = $org; $args[] = $site;
    $pdo->prepare('UPDATE garden_growing_locations SET ' . implode(', ', $set) . ' WHERE id = ? AND organization_id = ? AND site_id = ? AND active = 1')->execute($args);
}

/** Which way a bed runs, worked out from its map turn when nobody set it (top of the map is north). '' when unknown. */
function garden_bed_runs(array $a): string {
    $c = garden_bed_choices()['orientation'];
    if (!empty($a['orientation'])) return $c[$a['orientation']] ?? '';
    if ($a['length_m'] === null || $a['layout_x_m'] === null) return '';
    $deg = (int)$a['rotation_degrees'];
    if ((float)$a['width_m'] > (float)$a['length_m']) $deg += 90;   // the long side is the width
    $deg = (($deg % 180) + 180) % 180;
    $k = $deg < 23 || $deg >= 158 ? 'e_w' : ($deg < 68 ? 'nw_se' : ($deg < 113 ? 'n_s' : 'ne_sw'));
    return $c[$k] . ' (from the map)';
}

// ── Crop family colors ──

/** Friendly names for the usual families. */
function garden_family_names(): array {
    return ['Solanaceae' => 'Nightshades', 'Brassicaceae' => 'Cabbage family', 'Cucurbitaceae' => 'Squash family', 'Fabaceae' => 'Beans & peas',
            'Apiaceae' => 'Carrot family', 'Amaranthaceae' => 'Beet & spinach', 'Amaryllidaceae' => 'Onion family', 'Alliaceae' => 'Onion family',
            'Asteraceae' => 'Lettuce & sunflower', 'Lamiaceae' => 'Mint & basil', 'Poaceae' => 'Corn & grains', 'Malvaceae' => 'Okra family',
            'Convolvulaceae' => 'Sweet potato', 'Rosaceae' => 'Rose family', 'Ericaceae' => 'Blueberry family'];
}
/** "Solanaceae (Nightshades)", or the family as typed. */
function garden_family_label(?string $f): string {
    $f = trim((string)$f);
    if ($f === '' || strcasecmp($f, 'Unspecified') === 0) return 'Family not set';
    $n = garden_family_names()[ucfirst(strtolower($f))] ?? '';
    return $n !== '' ? $f . ' (' . $n . ')' : $f;
}
/** A fill color for a crop family: fixed for the usual families, picked from the same palette for any other. */
function garden_family_color(?string $f): string {
    $f = ucfirst(strtolower(trim((string)$f)));
    if ($f === '' || $f === 'Unspecified') return '#b8b0a2';
    $fixed = ['Solanaceae' => '#d0584a', 'Brassicaceae' => '#4f8fb3', 'Cucurbitaceae' => '#e3b23c', 'Fabaceae' => '#8a6bbf',
              'Apiaceae' => '#ea8a3a', 'Amaranthaceae' => '#b8457a', 'Amaryllidaceae' => '#9fa4d8', 'Alliaceae' => '#9fa4d8',
              'Asteraceae' => '#8cc152', 'Lamiaceae' => '#3aa59a', 'Poaceae' => '#c9a86a', 'Malvaceae' => '#6fae6f',
              'Convolvulaceae' => '#a8664a', 'Rosaceae' => '#e57f9b', 'Ericaceae' => '#4c6fae'];
    if (isset($fixed[$f])) return $fixed[$f];
    $spare = ['#5aa36b', '#cc7a52', '#7a8fd6', '#c2a23a', '#a0559b', '#52a3c2', '#d36a7d', '#7e9c3c'];
    return $spare[crc32($f) % count($spare)];
}
/** Square meters as a plain number of square feet (no thousands comma), for the map data. */
function garden_sqft_num($m): float { return round((float)$m / (GARDEN_FT * GARDEN_FT), 1); }
/** Planting status => [label, fill color]. "ready" = growing and past its expected picking start. */
function garden_status_colors(): array {
    return ['planned' => ['Planned', '#f0d9a0'], 'active' => ['Growing', '#6aa84f'], 'ready' => ['Ready to pick', '#2f7d32'],
            'completed' => ['Done', '#c9b79c'], 'failed' => ['Failed', '#b06a5a']];
}

// ── Time spans ──

/** Days a crop keeps producing after it first matures (used when nobody typed an end date). */
if (!defined('GARDEN_PICKING_DAYS')) define('GARDEN_PICKING_DAYS', 21);

/** [start, end or null (open), estimated?] for a planting row (from GrowingRecords::plantings). */
function garden_planting_span(array $p): array {
    $start = (string)$p['planted_on'];
    if (!empty($p['ended_on'])) return [$start, max($start, (string)$p['ended_on']), false];
    if (in_array($p['status'] ?? '', ['completed', 'failed'], true)) return [$start, $start, true];
    if (!empty($p['expected_end_on'])) return [$start, max($start, (string)$p['expected_end_on']), false];
    $days = (int)($p['days_to_maturity'] ?? 0);
    if (($p['lifecycle'] ?? '') === 'perennial' || ($p['method'] ?? '') === 'perennial_installation') return [$start, null, true];
    $add = $days > 0 ? $days + GARDEN_PICKING_DAYS : 90;
    return [$start, date('Y-m-d', strtotime($start . ' +' . $add . ' days')), true];
}
/** The date a growing planting should start being ready, or null. */
function garden_planting_ready_on(array $p): ?string {
    $days = (int)($p['days_to_maturity'] ?? 0);
    if ($days < 1) return null;
    return date('Y-m-d', strtotime((string)$p['planted_on'] . ' +' . $days . ' days'));
}
/** Status for the map: the stored status, or "ready" for a growing planting past its days to maturity. */
function garden_planting_map_status(array $p, string $today): string {
    if ($p['status'] === 'active') { $r = garden_planting_ready_on($p); if ($r !== null && $r <= $today) return 'ready'; }
    return (string)$p['status'];
}
/** Do two spans [start, end|null] touch? */
function garden_spans_touch(string $a1, ?string $a2, string $b1, ?string $b2): bool {
    return ($a2 === null || $a2 >= $b1) && ($b2 === null || $b2 >= $a1);
}

/** Seasons for the map picker: key => label, newest first, from the years that have plantings (and this year). */
function garden_season_options(array $plantings, string $today): array {
    $years = [(int)substr($today, 0, 4) => true, (int)substr($today, 0, 4) + 1 => true];
    foreach ($plantings as $p) $years[(int)substr((string)$p['planted_on'], 0, 4)] = true;
    krsort($years);
    $out = ['now' => 'Today'];
    foreach (array_keys($years) as $y) {
        $out[(string)$y] = (string)$y . ' (whole year)';
        foreach (['fall' => 'Fall', 'summer' => 'Summer', 'spring' => 'Spring', 'winter' => 'Winter'] as $k => $l) $out[$y . '-' . $k] = $l . ' ' . $y;
    }
    return $out;
}
/** [from, to, label] for a season key. Winter YYYY = Dec of YYYY through Feb of YYYY+1. Unknown keys = today. */
function garden_season_window(string $key, string $today): array {
    if (preg_match('/^(\d{4})$/', $key, $m)) return [$m[1] . '-01-01', $m[1] . '-12-31', $m[1]];
    if (preg_match('/^(\d{4})-(spring|summer|fall|winter)$/', $key, $m)) {
        $y = (int)$m[1];
        $w = ['spring' => ["$y-03-01", "$y-05-31"], 'summer' => ["$y-06-01", "$y-08-31"], 'fall' => ["$y-09-01", "$y-11-30"],
              'winter' => ["$y-12-01", date('Y-m-t', strtotime(($y + 1) . '-02-01'))]][$m[2]];
        return [$w[0], $w[1], ucfirst($m[2]) . ' ' . $y];
    }
    return [$today, $today, 'Today'];
}

// ── Rotation ──

/**
 * Rotation warnings: planting id => list of [family, crop, year, earlier planting id]. A planting is flagged when the same
 * family grew in the same bed in a planting that started earlier, less than 3 years before, and wasn't just planned.
 * Plantings growing side by side at the same time are not a rotation problem (see garden_bed_overlaps).
 */
function garden_rotation_warnings(array $plantings): array {
    $byBed = [];
    foreach ($plantings as $p) {
        $f = trim((string)($p['crop_family'] ?? ''));
        if ($f === '' || strcasecmp($f, 'Unspecified') === 0) continue;
        $byBed[(int)$p['location_id']][] = $p;
    }
    $out = [];
    foreach ($byBed as $list) {
        foreach ($list as $p) {
            [$s1] = garden_planting_span($p);
            foreach ($list as $q) {
                if ((int)$q['id'] === (int)$p['id'] || $q['status'] === 'planned') continue;
                if (strcasecmp((string)$q['crop_family'], (string)$p['crop_family']) !== 0) continue;
                [$s2, $e2] = garden_planting_span($q);
                if ($s2 >= $s1 || ($e2 === null || $e2 >= $s1)) continue;            // later, or still in the bed together
                if (strtotime($s1) - strtotime($s2) >= 3 * 365 * 86400) continue;
                $out[(int)$p['id']][] = ['family' => (string)$p['crop_family'], 'crop' => (string)$q['crop_name'], 'year' => (int)substr($s2, 0, 4), 'planting_id' => (int)$q['id']];
            }
        }
    }
    return $out;
}
/** "Nightshades were here in 2025 (Tomato). Rotate if you can." */
function garden_rotation_text(array $warns): string {
    if (!$warns) return '';
    $w = $warns[0];
    $years = array_unique(array_map(fn($x) => $x['year'], $warns)); sort($years);
    $crops = array_unique(array_map(fn($x) => $x['crop'], $warns));
    $fam = garden_family_names()[ucfirst(strtolower($w['family']))] ?? $w['family'];
    return 'Same family as before: ' . $fam . ' grew in this bed in ' . implode(' and ', $years) . ' (' . implode(', ', $crops) . '). Rotate if you can.';
}

// ── Occupancy ──

/**
 * Plantings sharing a bed at the same time, per bed: bed id => list of [from, to (null = open), ids, used m² or null, over?].
 * $cap: bed id => capacity m² (null = size not set). Only planned and growing plantings count. Each list item
 * is one stretch of time when two or more plantings were in the bed together.
 */
function garden_bed_overlaps(array $plantings, array $cap): array {
    $byBed = [];
    foreach ($plantings as $p) if (in_array($p['status'], ['planned', 'active'], true)) $byBed[(int)$p['location_id']][] = $p;   // done/failed ones are history: nothing to fix
    $out = [];
    foreach ($byBed as $bed => $list) {
        if (count($list) < 2) continue;
        // Boundaries where the set of plantings in the bed changes.
        $pts = [];
        $spans = [];
        foreach ($list as $p) { [$s, $e] = garden_planting_span($p); $spans[(int)$p['id']] = [$s, $e, $p]; $pts[$s] = true; if ($e !== null) $pts[date('Y-m-d', strtotime($e . ' +1 day'))] = true; }
        ksort($pts); $pts = array_keys($pts);
        $runs = [];
        foreach ($pts as $i => $d) {
            $in = [];
            foreach ($spans as $id => [$s, $e]) if ($s <= $d && ($e === null || $e >= $d)) $in[] = $id;
            if (count($in) < 2) { $runs[] = null; continue; }
            sort($in);
            $areas = array_map(fn($id) => $spans[$id][2]['allocated_area_sq_m'], $in);
            $measured = !in_array(null, $areas, true);
            $used = $measured ? array_sum(array_map('floatval', $areas)) : null;
            $c = $cap[$bed] ?? null;
            $to = isset($pts[$i + 1]) ? date('Y-m-d', strtotime($pts[$i + 1] . ' -1 day')) : null;
            $runs[] = ['from' => $d, 'to' => $to, 'ids' => $in, 'used' => $used, 'over' => $used !== null && $c !== null && $used > (float)$c + 0.00001, 'measured' => $measured];
        }
        // Join neighbouring stretches with the same plantings.
        $merged = [];
        foreach ($runs as $r) {
            if ($r === null) continue;
            $last = $merged ? $merged[count($merged) - 1] : null;
            if ($last && $last['ids'] === $r['ids'] && $last['to'] !== null && date('Y-m-d', strtotime($last['to'] . ' +1 day')) === $r['from']) { $merged[count($merged) - 1]['to'] = $r['to']; continue; }
            $merged[] = $r;
        }
        // Fine when every planting has its bed space set and they fit; flag the rest.
        $merged = array_values(array_filter($merged, fn($r) => $r['over'] || !$r['measured']));
        if ($merged) $out[$bed] = $merged;
    }
    return $out;
}

/**
 * Most bed space in use at any one time by $spans (list of [start, end|null, m²]) — what a bed must hold.
 */
function garden_peak_area(array $spans): float {
    $peak = 0.0;
    foreach ($spans as [$s]) {
        $sum = 0.0;
        foreach ($spans as [$s2, $e2, $a2]) if ($s2 <= $s && ($e2 === null || $e2 >= $s)) $sum += (float)$a2;
        $peak = max($peak, $sum);
    }
    return $peak;
}

/** SQL columns a planting span needs, for queries that join p (plantings), v, ov, c, oc and pref. */
function garden_span_sql(): string {
    return 'p.planted_on, p.expected_end_on, p.ended_on, p.status, p.method, p.allocated_area_sq_m, '
         . 'COALESCE(pref.days_to_maturity, v.days_to_maturity, ov.days_to_maturity) AS days_to_maturity, COALESCE(c.lifecycle, oc.lifecycle) AS lifecycle';
}
/** The joins garden_span_sql() needs, after "FROM garden_plantings p". */
function garden_span_joins(): string {
    return ' LEFT JOIN garden_varieties v ON v.id = p.variety_id LEFT JOIN garden_crops c ON c.id = v.crop_id'
         . ' LEFT JOIN garden_organization_variety_preferences pref ON pref.organization_id = p.organization_id AND pref.variety_id = p.variety_id'
         . ' LEFT JOIN garden_organization_varieties ov ON ov.id = p.organization_variety_id AND ov.organization_id = p.organization_id'
         . ' LEFT JOIN garden_organization_crops oc ON oc.id = ov.crop_id AND oc.organization_id = p.organization_id';
}

/** Expected end date from the form: '' = work it out. Must not be before the planting date. */
function garden_end_date_in(string $v, string $start): ?string {
    $v = trim($v);
    if ($v === '') return null;
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $v);
    if (!$d || $d->format('Y-m-d') !== $v) throw new InvalidArgumentException('Enter a valid "out of the bed by" date.');
    if ($v < $start) throw new InvalidArgumentException('"Out of the bed by" can\'t be before the planting date.');
    return $v;
}
/** Set a planting's expected end (null = work it out). */
function garden_planting_set_end(PDO $pdo, int $org, int $site, int $planting, ?string $end): void {
    $q = $pdo->prepare('UPDATE garden_plantings p JOIN garden_growing_locations l ON l.id = p.location_id AND l.organization_id = p.organization_id SET p.expected_end_on = ? WHERE p.id = ? AND p.organization_id = ? AND l.site_id = ?');
    $q->execute([$end, $planting, $org, $site]);
}

/** Warnings about one planting, as sentences: rotation and sharing the bed. */
function garden_planting_warnings(array $plantings, int $id, array $cap, array $names = []): array {
    $out = [];
    $rot = garden_rotation_warnings($plantings)[$id] ?? [];
    if ($rot) $out[] = garden_rotation_text($rot);
    $p = null; foreach ($plantings as $x) if ((int)$x['id'] === $id) $p = $x;
    if (!$p || in_array($p['status'], ['completed', 'failed'], true)) return $out;
    $bed = (int)$p['location_id'];
    $by = []; foreach ($plantings as $x) $by[(int)$x['id']] = $x;
    foreach (garden_bed_overlaps(array_filter($plantings, fn($x) => (int)$x['location_id'] === $bed), $cap)[$bed] ?? [] as $r) {
        if (!in_array($id, $r['ids'], true)) continue;
        $others = array_map(fn($o) => $by[$o]['crop_name'], array_filter($r['ids'], fn($o) => $o !== $id));
        $when = garden_date($r['from']) . ($r['to'] === null ? ' on' : ($r['to'] !== $r['from'] ? '–' . garden_date($r['to']) : ''));
        $out[] = $r['over']
            ? 'Bed is over full ' . $when . ': ' . garden_sqm_to_sqft($r['used']) . ' of ' . garden_sqm_to_sqft($cap[$bed]) . ' sq ft with ' . implode(', ', array_unique($others)) . '.'
            : 'Shares the bed ' . $when . ' with ' . implode(', ', array_unique($others)) . '. Set each one\'s bed space to check they fit.';
        break;
    }
    return $out;
}

// ── Lay out many beds ──

/**
 * Make rows × columns beds of one size with a path between, numbered left to right then top to bottom, and place them.
 * $o: prefix, first (number), rows, cols, length_ft, width_ft, path_ft, x_ft, y_ft, kind, parent_id, upright (bool: the
 * long side runs up and down the map), details (garden_bed_details_in()). Returns the new ids. One transaction.
 */
function garden_lay_out_grid(PDO $pdo, int $org, int $site, array $o): array {
    $rows = (int)$o['rows']; $cols = (int)$o['cols']; $first = (int)$o['first'];
    if ($rows < 1 || $cols < 1 || $rows * $cols > 200) throw new InvalidArgumentException('Make between 1 and 200 beds at a time.');
    if ($first < 0 || $first > 99999) throw new InvalidArgumentException('Start numbering at 0 to 99999.');
    $prefix = trim((string)$o['prefix']);
    if ($prefix === '' || mb_strlen($prefix) > 120) throw new InvalidArgumentException('Give the beds a name, like Bed or Row.');
    $len = (float)garden_ft_to_m($o['length_ft']); $wid = (float)garden_ft_to_m($o['width_ft']);
    $path = $o['path_ft'] === '' ? 0.0 : (float)garden_ft_to_m($o['path_ft']);
    $x0 = $o['x_ft'] === '' ? 0.0 : (float)garden_ft_to_m($o['x_ft']); $y0 = $o['y_ft'] === '' ? 0.0 : (float)garden_ft_to_m($o['y_ft']);
    if ($len <= 0 || $wid <= 0) throw new InvalidArgumentException('Enter each bed\'s length and width in feet.');
    if ($path < 0) throw new InvalidArgumentException('The path can\'t be less than 0 feet.');
    $upright = !empty($o['upright']);
    $footW = $upright ? $wid : $len; $footH = $upright ? $len : $wid;   // the bed's footprint on the map
    $svc = new \Garden\Domain\GardenService(); $lay = new \Garden\Domain\GrowingAreaLayout();
    $parent = (int)($o['parent_id'] ?? 0);
    $f = fn(float $m) => number_format($m, 3, '.', '');
    $ids = [];
    $pdo->beginTransaction();
    try {
        $taken = $pdo->prepare('SELECT COUNT(*) FROM garden_growing_locations WHERE organization_id = ? AND site_id = ? AND active = 1 AND name = ?');
        for ($r = 0; $r < $rows; $r++) for ($c = 0; $c < $cols; $c++) {
            $name = $prefix . ' ' . ($first + $r * $cols + $c);
            $taken->execute([$org, $site, $name]);
            if ((int)$taken->fetchColumn()) throw new InvalidArgumentException('There\'s already a bed called ' . $name . '. Start the numbers higher or use another name.');
            $area = $f($len * $wid * garden_shape_factor((string)($o['details']['shape'] ?? 'rect')));
            $id = $parent > 0 ? $svc->addChildLocation($pdo, $org, $site, $parent, $name, (string)$o['kind'], $area)
                              : $svc->createLocation($pdo, $org, $site, $name, (string)$o['kind'], $area);
            // Footprint top-left corner; a turned bed is saved by its unturned corner around the same center.
            $fx = $x0 + $c * ($footW + $path); $fy = $y0 + $r * ($footH + $path);
            $cx = $fx + $footW / 2; $cy = $fy + $footH / 2;
            $lay->save($pdo, $org, $site, $id, $f($len), $f($wid), $f($cx - $len / 2), $f($cy - $wid / 2), $upright ? '90' : '0');
            if (!empty($o['details'])) garden_bed_details_save($pdo, $org, $site, $id, array_filter($o['details'], fn($v) => $v !== null));
            $ids[] = $id;
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $ids;
}

// ── Map data for admin/assets/garden.js ──

/**
 * Everything the map draws, as plain arrays (feet): beds with their details and the plantings in the season window,
 * colors, warnings and the legend. $window = [from, to, label].
 */
function garden_map_data(PDO $pdo, int $org, array $site, array $areas, array $plantings, array $window, string $today): array {
    [$from, $to] = $window;
    $names = garden_area_options($areas);
    $cap = []; foreach ($areas as $a) $cap[(int)$a['id']] = $a['area_sq_m'] !== null ? (float)$a['area_sq_m'] : ($a['length_m'] !== null ? (float)$a['length_m'] * (float)$a['width_m'] : null);
    $rot = garden_rotation_warnings($plantings);
    $over = garden_bed_overlaps($plantings, $cap);
    $soil = [];
    try {
        $st = $pdo->prepare('SELECT s.location_id, s.ph, s.observed_on FROM garden_soil_observations s JOIN garden_growing_locations l ON l.id = s.location_id AND l.organization_id = s.organization_id
                             WHERE s.organization_id = ? AND l.site_id = ? AND s.ph IS NOT NULL ORDER BY s.observed_on, s.id');
        $st->execute([$org, (int)$site['id']]);
        foreach ($st as $r) $soil[(int)$r['location_id']] = ['ph' => garden_num($r['ph']), 'on' => garden_date($r['observed_on'])];
    } catch (Throwable $e) { /* soil notes are optional */ }
    $choices = garden_bed_choices(); $fields = garden_bed_fields(); $kinds = garden_area_kinds(); $sc = garden_status_colors();
    $beds = []; $families = []; $statuses = []; $bedColors = [];
    $byId = []; foreach ($plantings as $p) $byId[(int)$p['id']] = $p;
    $cropSet = garden_crop_settings_all($pdo, $org); $looks = []; $art = []; $builtin = garden_plant_art_builtin(); $shapes = garden_bed_shapes();
    foreach ($areas as $a) {
        $id = (int)$a['id'];
        $facts = [];
        $shape = isset($shapes[$a['shape'] ?? '']) ? (string)$a['shape'] : 'rect';
        if ($a['length_m'] !== null) $facts[] = ['Size', ($shape !== 'rect' ? $shapes[$shape] . ', ' : '') . garden_m_to_ft($a['length_m']) . ' × ' . garden_m_to_ft($a['width_m']) . ' ft'];
        elseif ($a['area_sq_m'] !== null) $facts[] = ['Size', garden_sqm_to_sqft($a['area_sq_m']) . ' sq ft'];
        foreach ($fields as $f => $label) {
            $v = $f === 'orientation' ? garden_bed_runs($a) : ($a[$f] !== null ? ($choices[$f][$a[$f]] ?? '') : '');
            if ($v !== '') $facts[] = [$label, $v];
        }
        if (isset($soil[$id])) $facts[] = ['pH', $soil[$id]['ph'] . ' (' . $soil[$id]['on'] . ')'];
        $bc = garden_bed_colors();
        $color = !empty($a['color']) && isset($bc[$a['color']]) ? $a['color'] : '';
        if ($color !== '') { $facts[] = ['Color', $bc[$color]]; $bedColors[$color] = $bc[$color]; }
        $here = [];
        foreach ($plantings as $p) {
            if ((int)$p['location_id'] !== $id) continue;
            [$s, $e, $est] = garden_planting_span($p);
            if (!garden_spans_touch($s, $e, $from, $to)) continue;
            $ms = garden_planting_map_status($p, $today);
            $fam = trim((string)$p['crop_family']);
            $families[$fam === '' ? 'Unspecified' : $fam] = true; $statuses[$ms] = true;
            $ck = !empty($p['std_crop_id']) ? 's' . (int)$p['std_crop_id'] : (!empty($p['own_crop_id']) ? 'o' . (int)$p['own_crop_id'] : '');
            $lk = $ck !== '' ? $ck : 'n:' . strtolower((string)$p['crop_name']);
            $looks[$lk] ??= garden_plant_look($pdo, $org, $ck, (string)$p['crop_name'], $cropSet);
            $look = $looks[$lk];
            if ($look['art'] !== '' && isset($builtin[$look['art']])) $art[$look['art']] = $builtin[$look['art']];
            $here[] = ['id' => (int)$p['id'], 'crop' => (string)$p['crop_name'], 'variety' => (string)$p['variety_name'], 'family' => garden_family_label($fam),
                       'familyColor' => garden_family_color($fam), 'status' => $sc[$ms][0], 'statusColor' => $sc[$ms][1], 'statusKey' => $ms,
                       'from' => garden_date($s), 'to' => $e === null ? '' : garden_date($e), 'estimated' => $est,
                       'sqft' => $p['allocated_area_sq_m'] !== null ? garden_sqft_num($p['allocated_area_sq_m']) : null,
                       'rotation' => isset($rot[(int)$p['id']]) ? garden_rotation_text($rot[(int)$p['id']]) : '',
                       'pic' => $look['pic'], 'art' => $look['art'], 'emoji' => $look['emoji'], 'gap' => $look['gap'], 'row' => $look['row']];
        }
        $warn = [];
        foreach ($here as $h) if ($h['rotation'] !== '') $warn[] = $h['crop'] . ': ' . $h['rotation'];
        foreach ($over[$id] ?? [] as $r) {
            if (!garden_spans_touch($r['from'], $r['to'], $from, $to)) continue;
            $crops = implode(', ', array_unique(array_map(fn($x) => $byId[$x]['crop_name'], $r['ids'])));
            $when = garden_date($r['from']) . ($r['to'] === null ? ' on' : '–' . garden_date($r['to']));
            $warn[] = $r['over'] ? 'Over full ' . $when . ': ' . $crops . ' need ' . garden_sqm_to_sqft($r['used']) . ' sq ft, the bed has ' . garden_sqm_to_sqft($cap[$id]) . '.'
                                 : 'Sharing ' . $when . ': ' . $crops . '. Set bed space on each to check they fit.';
        }
        $beds[] = ['id' => $id, 'name' => (string)$a['name'], 'path' => $names[$id] ?? (string)$a['name'], 'kind' => $kinds[$a['kind']] ?? garden_label($a['kind']),
                   'parent' => $a['parent_id'] !== null ? (int)$a['parent_id'] : null,
                   'placed' => $a['length_m'] !== null && $a['layout_x_m'] !== null, 'sized' => $a['length_m'] !== null,
                   'x' => $a['layout_x_m'] !== null ? round((float)$a['layout_x_m'] / GARDEN_FT, 2) : null, 'y' => $a['layout_y_m'] !== null ? round((float)$a['layout_y_m'] / GARDEN_FT, 2) : null,
                   'len' => $a['length_m'] !== null ? round((float)$a['length_m'] / GARDEN_FT, 2) : null, 'wid' => $a['width_m'] !== null ? round((float)$a['width_m'] / GARDEN_FT, 2) : null,
                   'rot' => (int)$a['rotation_degrees'], 'sqft' => $cap[$id] !== null ? garden_sqft_num($cap[$id]) : null,
                   'cover' => (string)($a['ground_cover'] ?? ''), 'color' => $color, 'shape' => $shape, 'facts' => $facts, 'notes' => (string)($a['notes'] ?? ''), 'plantings' => $here, 'warnings' => $warn];
    }
    $legendF = [];
    foreach (array_keys($families) as $f) $legendF[] = ['label' => garden_family_label($f), 'color' => garden_family_color($f)];
    usort($legendF, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    $legendS = [];
    foreach ($sc as $k => [$l, $c]) if (isset($statuses[$k])) $legendS[] = ['label' => $l, 'color' => $c];
    return ['site' => (int)$site['id'], 'siteName' => (string)$site['name'], 'season' => $window[2], 'beds' => $beds, 'art' => (object)$art,
            'legend' => ['family' => $legendF, 'status' => $legendS, 'bed' => array_map(fn($c, $l) => ['label' => $l, 'color' => $c], array_keys($bedColors), array_values($bedColors))]];
}

}
