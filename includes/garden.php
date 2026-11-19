<?php
// includes/garden.php — Garden in Farm OS (Nov 18, 2026): loads the garden classes and the helpers every garden page shares.
//   The garden & crop manager was built as a standalone app (repo zacmalin/garden-crop-manager) and merged here.
//   Classes (namespace Garden\Domain) live in includes/garden_areas.php, garden_crops.php, garden_seeds.php,
//   garden_plantings.php and garden_care.php. Tables are garden_* (admin/migrations/0072_garden.php).
//   Pages: admin/garden/*.php. Permissions: view 'garden' (see and log), 'garden_manage' (beds, crops, setup).
//   Stored metric (m, m², cm, °C) because the classes validate in metric; these helpers show feet, inches and °F.
if (!function_exists('garden_ready')) {

require_once __DIR__ . '/garden_areas.php';
require_once __DIR__ . '/garden_crops.php';
require_once __DIR__ . '/garden_seeds.php';
require_once __DIR__ . '/garden_plantings.php';
require_once __DIR__ . '/garden_care.php';
require_once __DIR__ . '/garden_planner.php';   // bed planner: details, time spans, rotation, map (Nov 19, 2026)
require_once __DIR__ . '/garden_delete.php';    // delete any garden record (Nov 19, 2026)
require_once __DIR__ . '/farm_helpers.php';

/** True once the garden migrations have run: 0072 (tables), 0073 (bed planner) and 0074 (bed colors, Nov 19, 2026). */
function garden_ready(PDO $pdo): bool {
    try { $pdo->query('SELECT 1 FROM garden_organizations LIMIT 1'); $pdo->query('SELECT ended_on FROM garden_plantings LIMIT 1'); $pdo->query('SELECT color FROM garden_growing_locations LIMIT 1'); return true; }
    catch (Throwable $e) { return false; }
}

/** The farm's garden organization id (one per Farm OS install; made by migration 0072). */
function garden_org(PDO $pdo): int {
    static $id = null;
    if ($id === null) {
        $id = (int)$pdo->query('SELECT MIN(id) FROM garden_organizations')->fetchColumn();
        if ($id < 1) {
            $st = $pdo->prepare('INSERT INTO garden_organizations (name) VALUES (?)');
            $st->execute([function_exists('bcrf_farm_name') ? mb_substr(bcrf_farm_name(), 0, 160) : 'Our farm']);
            $id = (int)$pdo->lastInsertId();
        }
    }
    return $id;
}

/** Can the signed-in person change beds, crops and setup? (Owners always.) */
function garden_can_manage(): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    if (function_exists('bcrf_is_owner') ? bcrf_is_owner($role) : $role === 'administrator') return true;
    return function_exists('hasViewAccess') && hasViewAccess($role, 'garden_manage');
}

/** The signed-in Farm OS user id, or null. */
function garden_uid(): ?int { $u = (int)($_SESSION['user_id'] ?? 0); return $u ?: null; }

// ── Units: stored metric, shown US ──
if (!defined('GARDEN_FT')) define('GARDEN_FT', 0.3048);

/** Feet typed by a person -> meters for the classes. '' stays ''. Accepts 12, 12.5, 12' . */
function garden_ft_to_m($v): string {
    $v = trim(str_replace(["'", 'ft', ','], '', (string)$v));
    if ($v === '') return '';
    if (!is_numeric($v)) throw new InvalidArgumentException('Use a number of feet, like 12 or 4.5.');
    return rtrim(rtrim(number_format((float)$v * GARDEN_FT, 3, '.', ''), '0'), '.') ?: '0';
}
/** Square feet -> m². */
function garden_sqft_to_sqm($v): string {
    $v = trim(str_replace([',', 'sq ft', 'sqft'], '', (string)$v));
    if ($v === '') return '';
    if (!is_numeric($v)) throw new InvalidArgumentException('Use a number of square feet, like 32.');
    return rtrim(rtrim(number_format((float)$v * GARDEN_FT * GARDEN_FT, 3, '.', ''), '0'), '.') ?: '0';
}
/** Inches -> cm. */
function garden_in_to_cm($v): string {
    $v = trim(str_replace(['"', 'in', ','], '', (string)$v));
    if ($v === '') return '';
    if (!is_numeric($v)) throw new InvalidArgumentException('Use a number of inches, like 12 or 0.25.');
    return rtrim(rtrim(number_format((float)$v * 2.54, 2, '.', ''), '0'), '.') ?: '0';
}
/** °F -> °C. */
function garden_f_to_c($v): string {
    $v = trim(str_replace(['°', 'F', 'f'], '', (string)$v));
    if ($v === '') return '';
    if (!is_numeric($v)) throw new InvalidArgumentException('Use a temperature in °F, like 72.');
    return number_format(((float)$v - 32) * 5 / 9, 1, '.', '');
}
/** A number without trailing zeros: 12.50 -> 12.5. */
function garden_num($v, int $dp = 1): string {
    if ($v === null || $v === '') return '';
    $s = number_format((float)$v, $dp, '.', ',');
    return strpos($s, '.') === false ? $s : rtrim(rtrim($s, '0'), '.');
}
function garden_m_to_ft($m, int $dp = 1): string { return $m === null || $m === '' ? '' : garden_num((float)$m / GARDEN_FT, $dp); }
function garden_sqm_to_sqft($m): string { return $m === null || $m === '' ? '' : garden_num((float)$m / (GARDEN_FT * GARDEN_FT), 0); }
function garden_cm_to_in($cm): string { return $cm === null || $cm === '' ? '' : garden_num((float)$cm / 2.54, 2); }
function garden_c_to_f($c): string { return $c === null || $c === '' ? '' : garden_num((float)$c * 9 / 5 + 32, 0); }

/** "Raised bed", "Tree position" from an enum value. */
function garden_label(?string $v): string { return ucfirst(str_replace('_', ' ', (string)$v)); }

/** Kinds of growing area, in the order forms list them. */
function garden_area_kinds(): array {
    return ['raised_bed' => 'Raised bed', 'row' => 'Row', 'plot' => 'Plot', 'field' => 'Field', 'container' => 'Container',
            'greenhouse' => 'Greenhouse', 'zone' => 'Zone', 'orchard' => 'Orchard', 'block' => 'Block', 'tree_position' => 'Tree position'];
}

/** A date for people: 2026-11-18 -> Nov 18, 2026 (or Nov 18 this year). */
function garden_date(?string $d): string {
    if (!$d) return '';
    $t = strtotime($d);
    if (!$t) return (string)$d;
    return date('Y', $t) === date('Y') ? date('M j', $t) : date('M j, Y', $t);
}

/**
 * The garden sites, and the one this page shows: ?site=N, else the one picked last time (session), else the first.
 * Returns [sites, selected site row or null].
 */
function garden_site_pick(PDO $pdo, int $org): array {
    $sites = (new \Garden\Domain\GardenService())->sites($pdo, $org);
    $want = (int)($_GET['site'] ?? $_POST['site_id'] ?? $_SESSION['garden_site'] ?? 0);
    $pick = null;
    foreach ($sites as $s) if ((int)$s['id'] === $want) $pick = $s;
    if (!$pick && $sites) $pick = $sites[0];
    if ($pick) $_SESSION['garden_site'] = (int)$pick['id'];
    return [$sites, $pick];
}

/**
 * Varieties for a picker: shared reference ones (value = id) and the farm's own (value = custom:id), not archived.
 * Each: value, label ("Tomato — Cherokee Purple"), crop, family, days, basis.
 */
function garden_variety_options(PDO $pdo, int $org): array {
    $out = [];
    foreach ((new \Garden\Domain\CropCatalog())->varietiesForOrganization($pdo, $org) as $v) {
        if (!empty($v['hidden_at'])) continue;   // taken off this farm's list (Nov 19, 2026)
        $name = $v['local_name'] ?: $v['name'];
        $out[] = ['value' => (string)$v['id'], 'label' => $v['crop_name'] . ' — ' . $name, 'crop' => $v['crop_name'], 'family' => $v['crop_family'],
                  'days' => $v['local_days_to_maturity'] ?? $v['days_to_maturity'], 'basis' => $v['local_maturity_basis'] ?? $v['maturity_basis'], 'own' => false];
    }
    foreach ((new \Garden\Domain\CustomCatalog())->varieties($pdo, $org) as $v) {
        if ($v['archived_at'] || $v['crop_archived_at']) continue;
        $out[] = ['value' => 'custom:' . (int)$v['id'], 'label' => $v['crop_name'] . ' — ' . $v['name'], 'crop' => $v['crop_name'], 'family' => $v['crop_family'] ?? null,
                  'days' => $v['days_to_maturity'], 'basis' => $v['maturity_basis'], 'own' => true];
    }
    usort($out, fn($a, $b) => strcasecmp($a['label'], $b['label']));
    return $out;
}

/** <option>s for a list of [value, label] rows, with $sel selected. */
function garden_options(array $rows, $sel = '', string $blank = ''): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = $blank !== '' ? '<option value="">' . $h($blank) . '</option>' : '';
    foreach ($rows as $v => $l) $o .= '<option value="' . $h($v) . '"' . ((string)$sel === (string)$v ? ' selected' : '') . '>' . $h($l) . '</option>';
    return $o;
}

/** Areas of a site as value => "Parent › Name" labels, sorted. */
function garden_area_options(array $locations, ?string $kind = null): array {
    $by = [];
    foreach ($locations as $l) $by[(int)$l['id']] = $l;
    $out = [];
    foreach ($locations as $l) {
        if ($kind !== null && $l['kind'] !== $kind) continue;
        $name = $l['name']; $p = $l['parent_id'] ? ($by[(int)$l['parent_id']] ?? null) : null; $guard = 0;
        while ($p && $guard++ < 10) { $name = $p['name'] . ' › ' . $name; $p = $p['parent_id'] ? ($by[(int)$p['parent_id']] ?? null) : null; }
        $out[(int)$l['id']] = $name;
    }
    asort($out, SORT_NATURAL | SORT_FLAG_CASE);
    return $out;
}

/** Plantings as value => label for pickers ("Bed 3 · Tomato — Cherokee Purple · Apr 20"). $statuses limits which. */
function garden_planting_options(array $plantings, array $statuses = ['planned', 'active']): array {
    $out = [];
    foreach ($plantings as $p) {
        if ($statuses && !in_array($p['status'], $statuses, true)) continue;
        $out[(int)$p['id']] = $p['location_name'] . ' · ' . $p['crop_name'] . ' — ' . $p['variety_name'] . ' · ' . garden_date($p['planted_on']);
    }
    return $out;
}

/** Save a typed form back to the page after an error (kept one request). */
function garden_keep_form(string $act): void { $_SESSION['garden_form'] = ['act' => $act, 'post' => $_POST]; }
/** The form typed before an error, if it was this action. */
function garden_typed(string $act): array {
    static $f = null;
    if ($f === null) { $f = $_SESSION['garden_form'] ?? []; unset($_SESSION['garden_form']); }
    return (($f['act'] ?? '') === $act) ? (array)($f['post'] ?? []) : [];
}

/** Recent Farm OS tasks linked to garden areas of a site (open first). */
function garden_tasks(PDO $pdo, array $areaIds, bool $open = true, int $limit = 50): array {
    if (!$areaIds) return [];
    try {
        $st = $pdo->query("SELECT id, title, status, due_date, priority, related_id FROM tasks
                           WHERE related_type = 'garden_area' AND related_id IN ('" . implode("','", array_map('intval', $areaIds)) . "')"
                         . ($open ? " AND status IN ('open', 'in_progress')" : '') . "
                           ORDER BY due_date IS NULL, due_date, id LIMIT " . (int)$limit);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

// ── Harvest into farmstand stock (Nov 18, 2026) ──

/** Products a harvest can go into: active and stock-tracked, produce first (supplies too, e.g. greens for the animals). id => "Name (unit)". */
function garden_harvest_products(PDO $pdo): array {
    try {
        $rows = $pdo->query("SELECT id, name, unit, product_type FROM inventory
                             WHERE is_active = 1 AND track_inventory = 1 AND product_type IN ('produce', 'retail', 'kitchen_good', 'supply')
                             ORDER BY product_type <> 'produce', name LIMIT 1000")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
    $out = [];
    foreach ($rows as $r) $out[(int)$r['id']] = $r['name'] . ($r['unit'] && $r['unit'] !== 'each' ? ' (' . $r['unit'] . ')' : '');
    return $out;
}

/** Log a harvest; when a product is picked, add the usable amount to its stock at the default location. One transaction. */
function garden_log_harvest(PDO $pdo, int $org, int $site, int $plantingId, string $date, string $qty, string $unit, string $dest, string $notes,
                            ?int $productId, string $stockQty): array {
    require_once __DIR__ . '/stock.php';
    $stockQty = trim($stockQty);
    if ($productId) {
        if (!bcrf_stock_ready($pdo)) throw new DomainException('Stock locations are not set up yet, so the harvest can\'t go into stock.');
        if (!isset(garden_harvest_products($pdo)[$productId])) throw new InvalidArgumentException('Pick a stocked product, or leave Product empty.');
        if ($stockQty === '') $stockQty = trim($qty);
        if (!is_numeric($stockQty) || (float)$stockQty <= 0) throw new InvalidArgumentException('Enter how many go into stock, in the product\'s unit.');
    }
    $pdo->beginTransaction();
    try {
        $locked = $productId ? bcrf_stock_lock($pdo, $productId, garden_uid()) : null;
        $id = (new Garden\Domain\PlantingJournal())->logHarvest($pdo, $org, $site, $plantingId, $date, $qty, $unit, $dest, $notes);
        $added = null;
        if ($productId) {
            $loc = bcrf_location_default($pdo);
            bcrf_stock_move($pdo, $productId, $loc, (float)$stockQty, 'harvest', 'garden_harvest', $id, 'Garden harvest #' . $id, garden_uid(), 'strict', $locked);
            $added = number_format((float)$stockQty, 3, '.', '');
            $pdo->prepare('UPDATE garden_planting_harvest_events SET product_id = ?, stock_added = ? WHERE id = ? AND organization_id = ?')->execute([$productId, $added, $id, $org]);
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return [$id, $added];
}

// ── Page plumbing shared by admin/garden/*.php ──

/** Tailwind class strings, the same Hiring-screen look as the animal pages. */
function garden_ui(): array {
    return [
        'input'   => 'p-2 border border-stone-300 rounded bg-stone-50 text-base md:text-sm w-full',
        'label'   => 'block text-xs font-semibold text-stone-600 mb-1',
        'btnMain' => 'px-4 py-2 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold',
        'btnSec'  => 'px-3 py-2 rounded text-sm border border-stone-300 text-stone-700 hover:bg-stone-100 bg-white',
        'card'    => 'bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4',
        'h1'      => 'text-2xl font-bold text-stone-800 mb-3',
        'h2'      => 'text-base font-semibold text-stone-800 mb-2',
        'muted'   => 'text-sm text-stone-500',
        'grid'    => 'grid grid-cols-1 sm:grid-cols-2 gap-3 mb-3',
        'sec'     => 'site-info-section bg-white border border-stone-200 rounded-lg shadow-sm mb-4 overflow-hidden',
        'sum'     => 'cursor-pointer font-semibold text-stone-800 flex justify-between items-center',
        'link'    => 'underline text-amber-800',
        'pill'    => 'care-filter-pill',
    ];
}

/** CSRF token for garden forms (the console's session token). */
function garden_csrf(): string {
    if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    return (string)$_SESSION['csrf_token'];
}
/** Hidden CSRF + action fields for a form. */
function garden_hidden(string $act, array $extra = []): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<input type="hidden" name="csrf_token" value="' . $h(garden_csrf()) . '"><input type="hidden" name="act" value="' . $h($act) . '">';
    foreach ($extra as $k => $v) $o .= '<input type="hidden" name="' . $h($k) . '" value="' . $h($v) . '">';
    return $o;
}

/** A whole number id from the form, or throws with $what. */
function garden_id(string $key, string $what): int {
    $v = filter_var($_POST[$key] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    if (!$v) throw new InvalidArgumentException($what);
    return (int)$v;
}
/** A text field from the form. */
function garden_in(string $key, string $default = ''): string { $v = $_POST[$key] ?? $default; return is_string($v) ? $v : $default; }

/**
 * Handle a garden page POST and redirect (never returns on POST).
 * $actions: act => [needs manage (bool), callable(): string|array]. The callable returns the success message, or
 * [message, extra query string like 'tab=water', '#anchor', 'warning' (optional: show the message as a warning)].
 * Errors (InvalidArgumentException, or any save error) go back to the page with the typed form kept.
 */
function garden_post(PDO $pdo, string $page, array $actions): void {
    if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') return;
    $act = (string)($_POST['act'] ?? '');
    $back = $page; $query = (string)($_POST['back_query'] ?? ''); $anchor = (string)($_POST['back_anchor'] ?? '');
    try {
        if (!hash_equals(garden_csrf(), (string)($_POST['csrf_token'] ?? ''))) throw new InvalidArgumentException('Security token expired; try again.');
        // Every garden page can delete its records (includes/garden_delete.php); Managers and Owners only.
        $actions['garden_delete'] ??= [true, fn() => garden_delete_record($pdo, garden_org($pdo), (string)($_POST['kind'] ?? ''), (int)($_POST['id'] ?? 0))];
        if (!isset($actions[$act])) throw new InvalidArgumentException('Unknown action.');
        [$manage, $fn] = $actions[$act];
        if ($manage && !garden_can_manage()) throw new InvalidArgumentException('Ask a manager to change garden setup.');
        $r = $fn();
        $msg = is_array($r) ? (string)$r[0] : (string)$r;
        if (is_array($r)) { $query = (string)($r[1] ?? $query); $anchor = (string)($r[2] ?? $anchor); }
        $_SESSION['garden_flash'] = [is_array($r) && ($r[3] ?? '') === 'warning' ? 'warning' : 'ok', $msg];
        if ($act !== 'garden_delete' && function_exists('erp_audit')) { try { erp_audit($pdo, 'update', 'garden', null, 'Garden: ' . $msg); } catch (Throwable $e) { /* audit is best effort */ } }
    } catch (InvalidArgumentException | DomainException $e) {
        $_SESSION['garden_flash'] = ['err', $e->getMessage()];
        garden_keep_form($act);
    } catch (Throwable $e) {
        if ($e instanceof BcrfStockError) { $_SESSION['garden_flash'] = ['err', $e->getMessage()]; garden_keep_form($act); }
        else {
            error_log('[garden] ' . $page . ' ' . $act . ': ' . $e->getMessage());
            $_SESSION['garden_flash'] = ['err', 'Could not save. Check the choices and try again.'];
            garden_keep_form($act);
        }
    }
    if (preg_match('/[^a-z0-9_=&-]/i', $query)) $query = '';
    if (!preg_match('/^#?[a-z0-9_-]*$/i', $anchor)) $anchor = '';
    if ($anchor !== '' && $anchor[0] !== '#') $anchor = '#' . $anchor;
    if (($_SESSION['garden_flash'][0] ?? '') === 'err' && $anchor === '') $anchor = '#form-' . preg_replace('/[^a-z0-9_]/', '', $act);
    header('Location: ' . $back . ($query !== '' ? '?' . $query : '') . $anchor, true, 303);
    exit;
}

/** Open a garden page in the Farm OS shell, with the flash toast ($opt: more bcrf_shell_open options, e.g. css/js); returns [error text, the action it was for]. */
function garden_open(string $title, array $opt = []): array {
    $flash = $_SESSION['garden_flash'] ?? null; unset($_SESSION['garden_flash']);
    $toast = ($flash && in_array($flash[0], ['ok', 'warning'], true)) ? $flash : null;
    bcrf_shell_open($title . ' — Garden', ['toast' => $toast] + $opt);
    $err = ($flash && $flash[0] === 'err') ? (string)$flash[1] : '';
    $f = $_SESSION['garden_form'] ?? [];
    return [$err, (string)($f['act'] ?? '')];
}

/** Site picker pills (only when there is more than one garden). */
function garden_site_pills(array $sites, ?array $site, string $page, string $tab = ''): string {
    if (count($sites) < 2) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    $o = '<nav class="flex flex-wrap gap-2 mb-4" aria-label="Gardens">';
    foreach ($sites as $s) {
        $on = $site && (int)$s['id'] === (int)$site['id'];
        $o .= '<a class="care-filter-pill' . ($on ? ' is-active' : '') . '"' . ($on ? ' aria-current="page"' : '') . ' href="' . $h($page . '?site=' . (int)$s['id'] . ($tab !== '' ? '&tab=' . $tab : '')) . '">' . $h($s['name']) . '</a>';
    }
    return $o . '</nav>';
}

/** "Add a garden first" box for pages that need one. */
function garden_need_site(): string {
    return '<div class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm mb-4"><p class="text-sm text-stone-600">No garden yet. '
         . (garden_can_manage() ? '<a class="underline text-amber-800" href="/admin/garden/areas.php">Add your garden and beds</a> first.' : 'Ask a manager to add the garden and its beds.') . '</p></div>';
}

/** Error box when this form was the one that failed. */
function garden_form_error(string $err, string $errAct, string $act): string {
    return ($err !== '' && $errAct === $act && function_exists('bcrf_shell_notice')) ? bcrf_shell_notice('err', $err) : '';
}

}
