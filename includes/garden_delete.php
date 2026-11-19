<?php
// includes/garden_delete.php — Garden: delete any garden record (Nov 19, 2026; migration 0074, every admin/garden/*.php page).
//   garden_delete_record()  removes one record and everything that hangs off it, in one transaction, and writes what was removed
//                           to the change log (before = the rows), so nothing goes without a trace:
//     site        a garden: its beds (and all below), bed jobs, repeat-sowing plans
//     area        a bed, row or area: the beds inside it, its plantings (and all below), soil notes, greenhouse readings,
//                 watering plans and their log, repeat-sowing plans; its bed jobs lose this bed (a job left with no beds goes)
//     planting    its steps, care, harvests (stock a harvest added is taken back off), pests and treatments, trays,
//                 orchard trees and their stages; seed it used goes back to its packet; a repeat sowing it filled opens again
//     crop / variety      the farm's own: refused while a planting or seed lot uses it (archive it instead)
//     shared_variety / shared_crop   a starter-list entry: taken off this farm's list (garden_variety_unhide brings it back)
//     seed_lot    its tests and count history; plantings that used it keep their record without the lot
//     and single lines: step, care, harvest, pest, treatment, bed_job, water_plan, water_log, soil, greenhouse,
//     seed_line, germination, tray, succession, tree, tree_stage
//   garden_delete_button()  the small "Delete" button with a confirm, for any page.
// Only Managers and Owners (garden_manage) delete; garden_post() routes act=garden_delete here for every garden page.

/** kind => [table, what it's called]. */
function garden_delete_kinds(): array {
    return [
        'site' => ['garden_growing_sites', 'Garden'], 'area' => ['garden_growing_locations', 'Bed'], 'planting' => ['garden_plantings', 'Planting'],
        'crop' => ['garden_organization_crops', 'Crop'], 'variety' => ['garden_organization_varieties', 'Variety'],
        'shared_variety' => ['garden_varieties', 'Variety'], 'shared_crop' => ['garden_crops', 'Crop'],
        'seed_lot' => ['garden_seed_lots', 'Seed lot'], 'step' => ['garden_planting_lifecycle_events', 'Step'], 'care' => ['garden_planting_care_events', 'Care'],
        'harvest' => ['garden_planting_harvest_events', 'Harvest'], 'pest' => ['garden_crop_health_incidents', 'Problem'],
        'treatment' => ['garden_crop_health_interventions', 'Treatment'], 'bed_job' => ['garden_care_applications', 'Bed job'],
        'water_plan' => ['garden_irrigation_plans', 'Watering plan'], 'water_log' => ['garden_irrigation_events', 'Watering'],
        'soil' => ['garden_soil_observations', 'Soil note'], 'greenhouse' => ['garden_greenhouse_readings', 'Reading'],
        'seed_line' => ['garden_seed_stock_movements', 'Seed line'], 'germination' => ['garden_seed_germination_tests', 'Germination test'],
        'tray' => ['garden_propagation_trays', 'Tray'], 'succession' => ['garden_succession_schedules', 'Repeat sowing'],
        'tree' => ['garden_perennial_records', 'Tree'], 'tree_stage' => ['garden_perennial_observations', 'Stage'],
    ];
}

/**
 * A small Delete button (its own form) that posts act=garden_delete back to $page and comes back to $back (query string) at
 * $anchor. $confirm: what the confirm box says. Managers only; returns '' for everyone else.
 */
function garden_delete_button(string $page, string $kind, int $id, string $confirm, string $back = '', string $anchor = '', string $label = 'Delete'): string {
    if (!garden_can_manage()) return '';
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<form method="POST" action="' . $h($page) . '" class="inline">' . garden_hidden('garden_delete', ['kind' => $kind, 'id' => $id, 'back_query' => $back, 'back_anchor' => $anchor])
         . '<button class="text-xs text-red-700 underline" data-confirm="' . $h($confirm) . '" data-no-icons>' . $h($label) . '</button></form>';
}

/** Run a statement; returns the row count. */
function garden_del_q(PDO $pdo, string $sql, array $a): int { $st = $pdo->prepare($sql); $st->execute($a); return $st->rowCount(); }
/** Rows (for the change log) then delete them. */
function garden_del_rows(PDO $pdo, string $table, string $where, array $a, array &$gone): int {
    $st = $pdo->prepare("SELECT * FROM `$table` WHERE $where"); $st->execute($a);
    $rows = $st->fetchAll(PDO::FETCH_ASSOC);
    if (!$rows) return 0;
    foreach ($rows as $r) $gone[$table][] = $r;
    return garden_del_q($pdo, "DELETE FROM `$table` WHERE $where", $a);
}
/** One column of ids. */
function garden_del_ids(PDO $pdo, string $sql, array $a): array { $st = $pdo->prepare($sql); $st->execute($a); return array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN)); }

/** A harvest: take back the stock it added (as much as is still there), then delete it. */
function garden_del_harvest(PDO $pdo, int $org, int $id, array &$gone): void {
    $st = $pdo->prepare('SELECT product_id, stock_added FROM garden_planting_harvest_events WHERE id = ? AND organization_id = ?');
    $st->execute([$id, $org]); $h = $st->fetch(PDO::FETCH_ASSOC);
    if ($h && $h['product_id'] && (float)$h['stock_added'] > 0) {
        require_once __DIR__ . '/stock.php';
        $loc = 0;
        try { $loc = (int)garden_del_ids($pdo, "SELECT location_id FROM inventory_adjustments WHERE source_type = 'garden_harvest' AND source_id = ? ORDER BY id LIMIT 1", [$id])[0]; } catch (Throwable $e) { $loc = 0; }
        if (!$loc) $loc = (int)bcrf_location_default($pdo);
        bcrf_stock_move($pdo, (int)$h['product_id'], $loc, -(float)$h['stock_added'], 'harvest', 'garden_harvest', $id, 'Garden harvest #' . $id . ' deleted', garden_uid(), 'clamp');
    }
    garden_del_rows($pdo, 'garden_planting_harvest_events', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** Pest/disease problem and its treatments. */
function garden_del_pest(PDO $pdo, int $org, int $id, array &$gone): void {
    garden_del_rows($pdo, 'garden_crop_health_interventions', 'incident_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_crop_health_incidents', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** Orchard tree and its stages. */
function garden_del_tree(PDO $pdo, int $org, int $id, array &$gone): void {
    garden_del_rows($pdo, 'garden_perennial_observations', 'perennial_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_perennial_records', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** Seed count lines (undo lines first, they point at the line they undo). */
function garden_del_seed_lines(PDO $pdo, int $org, array $ids, array &$gone): void {
    if (!$ids) return;
    $in = implode(',', array_map('intval', $ids));
    garden_del_rows($pdo, 'garden_seed_stock_movements', "reverses_movement_id IN ($in) AND organization_id = ?", [$org], $gone);
    garden_del_rows($pdo, 'garden_seed_stock_movements', "id IN ($in) AND organization_id = ?", [$org], $gone);
}
/** A planting and everything under it. */
function garden_del_planting(PDO $pdo, int $org, int $id, array &$gone): void {
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_planting_harvest_events WHERE planting_id = ? AND organization_id = ?', [$id, $org]) as $h) garden_del_harvest($pdo, $org, $h, $gone);
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_crop_health_incidents WHERE planting_id = ? AND organization_id = ?', [$id, $org]) as $i) garden_del_pest($pdo, $org, $i, $gone);
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_perennial_records WHERE planting_id = ? AND organization_id = ?', [$id, $org]) as $t) garden_del_tree($pdo, $org, $t, $gone);
    garden_del_rows($pdo, 'garden_planting_lifecycle_events', 'planting_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_planting_care_events', 'planting_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_propagation_trays', 'planting_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_q($pdo, 'UPDATE garden_succession_rounds SET planting_id = NULL WHERE planting_id = ? AND organization_id = ?', [$id, $org]);
    // Seed taken off a packet when this was planted goes back.
    garden_del_seed_lines($pdo, $org, garden_del_ids($pdo, "SELECT id FROM garden_seed_stock_movements WHERE organization_id = ? AND kind = 'used' AND notes = ?", [$org, 'Planting #' . $id]), $gone);
    garden_del_rows($pdo, 'garden_plantings', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** A repeat-sowing plan and its rounds. */
function garden_del_succession(PDO $pdo, int $org, int $id, array &$gone): void {
    garden_del_rows($pdo, 'garden_succession_rounds', 'schedule_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_succession_schedules', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** A bed job across beds, with its bed list. */
function garden_del_bed_job(PDO $pdo, int $org, int $id, array &$gone): void {
    garden_del_rows($pdo, 'garden_care_application_locations', 'application_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_care_applications', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** A watering plan and its log. */
function garden_del_water_plan(PDO $pdo, int $org, int $id, array &$gone): void {
    garden_del_rows($pdo, 'garden_irrigation_events', 'plan_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_irrigation_plans', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** A bed (and the beds inside it) and everything in it. */
function garden_del_area(PDO $pdo, int $org, int $id, array &$gone): void {
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_growing_locations WHERE parent_id = ? AND organization_id = ?', [$id, $org]) as $k) garden_del_area($pdo, $org, $k, $gone);
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_plantings WHERE location_id = ? AND organization_id = ?', [$id, $org]) as $p) garden_del_planting($pdo, $org, $p, $gone);
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_succession_schedules WHERE location_id = ? AND organization_id = ?', [$id, $org]) as $s) garden_del_succession($pdo, $org, $s, $gone);
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_irrigation_plans WHERE location_id = ? AND organization_id = ?', [$id, $org]) as $w) garden_del_water_plan($pdo, $org, $w, $gone);
    garden_del_rows($pdo, 'garden_soil_observations', 'location_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_greenhouse_readings', 'location_id = ? AND organization_id = ?', [$id, $org], $gone);
    $jobs = garden_del_ids($pdo, 'SELECT application_id FROM garden_care_application_locations WHERE location_id = ? AND organization_id = ?', [$id, $org]);
    garden_del_rows($pdo, 'garden_care_application_locations', 'location_id = ? AND organization_id = ?', [$id, $org], $gone);
    foreach ($jobs as $j) if (!garden_del_ids($pdo, 'SELECT location_id FROM garden_care_application_locations WHERE application_id = ? AND organization_id = ?', [$j, $org])) garden_del_bed_job($pdo, $org, $j, $gone);
    garden_del_rows($pdo, 'garden_growing_locations', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** What still uses a farm variety (plantings, seed lots), as words; '' = nothing. */
function garden_variety_in_use(PDO $pdo, int $org, array $ids): string {
    if (!$ids) return '';
    $in = implode(',', array_map('intval', $ids));
    $p = (int)garden_del_ids($pdo, "SELECT COUNT(*) FROM garden_plantings WHERE organization_variety_id IN ($in) AND organization_id = ?", [$org])[0];
    $l = (int)garden_del_ids($pdo, "SELECT COUNT(*) FROM garden_seed_lots WHERE organization_variety_id IN ($in) AND organization_id = ?", [$org])[0];
    $bits = [];
    if ($p) $bits[] = $p . ' planting' . ($p > 1 ? 's' : '');
    if ($l) $bits[] = $l . ' seed lot' . ($l > 1 ? 's' : '');
    return implode(' and ', $bits);
}
/** A farm variety (nothing may use it). */
function garden_del_variety(PDO $pdo, int $org, int $id, array &$gone): void {
    foreach (garden_del_ids($pdo, 'SELECT id FROM garden_succession_schedules WHERE organization_variety_id = ? AND organization_id = ?', [$id, $org]) as $s) garden_del_succession($pdo, $org, $s, $gone);
    garden_del_rows($pdo, 'garden_crop_growing_profiles', 'organization_variety_id = ? AND organization_id = ?', [$id, $org], $gone);
    garden_del_rows($pdo, 'garden_organization_varieties', 'id = ? AND organization_id = ?', [$id, $org], $gone);
}
/** Take a starter-list variety off (or back onto) this farm's list. */
function garden_variety_hide(PDO $pdo, int $org, array $varietyIds, bool $hide): int {
    $n = 0;
    $st = $pdo->prepare('INSERT INTO garden_organization_variety_preferences (organization_id, variety_id, hidden_at) VALUES (?, ?, ' . ($hide ? 'UTC_TIMESTAMP()' : 'NULL') . ')
                         ON DUPLICATE KEY UPDATE hidden_at = ' . ($hide ? 'UTC_TIMESTAMP()' : 'NULL'));
    foreach ($varietyIds as $v) { $st->execute([$org, (int)$v]); $n++; }
    return $n;
}
/** Bring back every starter-list variety this farm took off. */
function garden_variety_unhide_all(PDO $pdo, int $org): int {
    return garden_del_q($pdo, 'UPDATE garden_organization_variety_preferences SET hidden_at = NULL WHERE organization_id = ? AND hidden_at IS NOT NULL', [$org]);
}

/**
 * Delete one garden record of $kind (see garden_delete_kinds) and everything under it. Returns the message to show.
 * Throws InvalidArgumentException when it isn't there, or for a farm crop/variety still in use.
 */
function garden_delete_record(PDO $pdo, int $org, string $kind, int $id): string {
    $kinds = garden_delete_kinds();
    if (!isset($kinds[$kind]) || $id < 1) throw new InvalidArgumentException('Pick something to delete.');
    [$table, $word] = $kinds[$kind];
    $shared = in_array($kind, ['shared_variety', 'shared_crop'], true);
    $st = $pdo->prepare($shared ? "SELECT * FROM `$table` WHERE id = ?" : "SELECT * FROM `$table` WHERE id = ? AND organization_id = ?");
    $st->execute($shared ? [$id] : [$id, $org]);
    $row = $st->fetch(PDO::FETCH_ASSOC);
    if (!$row) throw new InvalidArgumentException('That ' . strtolower($word) . ' is already gone.');
    $name = (string)($row['name'] ?? $row['label'] ?? $row['common_name'] ?? $row['identified_as'] ?? '');
    $gone = [];
    $msg = $word . ($name !== '' ? ' "' . $name . '"' : '') . ' deleted.';
    $pdo->beginTransaction();
    try {
        switch ($kind) {
            case 'site':
                $roots = garden_del_ids($pdo, 'SELECT id FROM garden_growing_locations WHERE site_id = ? AND organization_id = ? AND parent_id IS NULL', [$id, $org]);
                foreach ($roots as $a) garden_del_area($pdo, $org, $a, $gone);
                foreach (garden_del_ids($pdo, 'SELECT id FROM garden_growing_locations WHERE site_id = ? AND organization_id = ?', [$id, $org]) as $a) garden_del_area($pdo, $org, $a, $gone);   // anything left over
                foreach (garden_del_ids($pdo, 'SELECT id FROM garden_succession_schedules WHERE site_id = ? AND organization_id = ?', [$id, $org]) as $s) garden_del_succession($pdo, $org, $s, $gone);
                foreach (garden_del_ids($pdo, 'SELECT id FROM garden_care_applications WHERE site_id = ? AND organization_id = ?', [$id, $org]) as $j) garden_del_bed_job($pdo, $org, $j, $gone);
                garden_del_rows($pdo, 'garden_growing_sites', 'id = ? AND organization_id = ?', [$id, $org], $gone);
                if (isset($_SESSION['garden_site']) && (int)$_SESSION['garden_site'] === $id) unset($_SESSION['garden_site']);
                break;
            case 'area': garden_del_area($pdo, $org, $id, $gone); break;
            case 'planting': garden_del_planting($pdo, $org, $id, $gone); break;
            case 'crop':
                $vars = garden_del_ids($pdo, 'SELECT id FROM garden_organization_varieties WHERE crop_id = ? AND organization_id = ?', [$id, $org]);
                $use = garden_variety_in_use($pdo, $org, $vars);
                if ($use !== '') throw new InvalidArgumentException($name . ' is used by ' . $use . '. Delete those first, or archive the crop.');
                foreach ($vars as $v) garden_del_variety($pdo, $org, $v, $gone);
                garden_del_rows($pdo, 'garden_organization_crops', 'id = ? AND organization_id = ?', [$id, $org], $gone);
                break;
            case 'variety':
                $use = garden_variety_in_use($pdo, $org, [$id]);
                if ($use !== '') throw new InvalidArgumentException($name . ' is used by ' . $use . '. Delete those first, or archive the variety.');
                garden_del_variety($pdo, $org, $id, $gone);
                break;
            case 'shared_variety':
                garden_variety_hide($pdo, $org, [$id], true);
                $msg = $name . ' taken off your list. Records that use it keep it.';
                break;
            case 'shared_crop':
                $n = garden_variety_hide($pdo, $org, garden_del_ids($pdo, 'SELECT id FROM garden_varieties WHERE crop_id = ?', [$id]), true);
                $msg = $name . ' (' . $n . ' variet' . ($n === 1 ? 'y' : 'ies') . ') taken off your list. Records that use it keep it.';
                break;
            case 'seed_lot':
                garden_del_q($pdo, 'UPDATE garden_plantings SET seed_lot_id = NULL WHERE seed_lot_id = ? AND organization_id = ?', [$id, $org]);
                garden_del_rows($pdo, 'garden_seed_germination_tests', 'seed_lot_id = ? AND organization_id = ?', [$id, $org], $gone);
                garden_del_seed_lines($pdo, $org, garden_del_ids($pdo, 'SELECT id FROM garden_seed_stock_movements WHERE seed_lot_id = ? AND organization_id = ?', [$id, $org]), $gone);
                garden_del_rows($pdo, 'garden_seed_lots', 'id = ? AND organization_id = ?', [$id, $org], $gone);
                $msg = 'Seed lot deleted.';
                break;
            case 'step':
                garden_del_rows($pdo, $table, 'id = ? AND organization_id = ?', [$id, $org], $gone);
                // Deleting the Done/Failed step puts the planting back to Growing.
                if (in_array($row['event_type'], ['completed', 'failed'], true)) {
                    $left = garden_del_ids($pdo, "SELECT COUNT(*) FROM garden_planting_lifecycle_events WHERE planting_id = ? AND organization_id = ? AND event_type IN ('completed','failed')", [(int)$row['planting_id'], $org])[0];
                    if (!$left) garden_del_q($pdo, "UPDATE garden_plantings SET status = 'active', ended_on = NULL WHERE id = ? AND organization_id = ? AND status = ?", [(int)$row['planting_id'], $org, $row['event_type']]);
                }
                $msg = 'Step deleted.';
                break;
            case 'harvest': garden_del_harvest($pdo, $org, $id, $gone); $msg = 'Harvest deleted' . ($row['product_id'] && (float)$row['stock_added'] > 0 ? ' and its stock taken back off.' : '.'); break;
            case 'pest': garden_del_pest($pdo, $org, $id, $gone); break;
            case 'bed_job': garden_del_bed_job($pdo, $org, $id, $gone); $msg = 'Bed job deleted.'; break;
            case 'water_plan': garden_del_water_plan($pdo, $org, $id, $gone); $msg = 'Watering plan deleted.'; break;
            case 'seed_line': garden_del_seed_lines($pdo, $org, [$id], $gone); $msg = 'Line deleted.'; break;
            case 'tray':
                garden_del_q($pdo, 'UPDATE garden_planting_lifecycle_events SET source_tray_id = NULL WHERE source_tray_id = ? AND organization_id = ?', [$id, $org]);
                garden_del_rows($pdo, $table, 'id = ? AND organization_id = ?', [$id, $org], $gone);
                break;
            case 'succession': garden_del_succession($pdo, $org, $id, $gone); $msg = 'Repeat sowing plan deleted. Plantings it made stay.'; break;
            case 'tree': garden_del_tree($pdo, $org, $id, $gone); break;
            default:   // care, treatment, water_log, soil, greenhouse, germination, tree_stage: one line, nothing under it
                garden_del_rows($pdo, $table, 'id = ? AND organization_id = ?', [$id, $org], $gone);
                $msg = $word . ' deleted.';
        }
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    // The change log keeps what went, so it can be looked up (and typed back in) later.
    if ($gone && function_exists('bcrf_audit_event') && function_exists('bcrf_audit_actor')) {
        try {
            [$t, $aid, $who, $ip] = bcrf_audit_actor();
            bcrf_audit_event($pdo, ['actor_type' => $t, 'actor_id' => $aid, 'actor_label' => $who, 'ip' => $ip, 'action' => 'delete',
                'object_type' => $table, 'object_id' => (string)$id, 'object_label' => 'Garden: ' . $msg, 'before' => $gone, 'after' => null]);
        } catch (Throwable $e) { /* the log is best effort */ }
    }
    return $msg;
}
