<?php
// includes/kitchen_labels.php — Farm OS Phase 4 slice 4: label drafts and approval (Nov 13, 2026; 09 › Labels › Generated from
// the recipe, Approval; migration 0054, admin/kitchen_labels.php).
//   A label is drafted for a product from its recipe's current saved version: product name (editable), ingredient
//   statement heaviest first with sub-ingredients, "Contains:" line, net quantity from the product's size, "Made by"
//   from Site Info, and the required statement from Kitchen › Labels settings. Lot code and best-by are filled in per
//   batch at print time (slice 5).
//   Only people with the "Approve kitchen labels" permission (Owners always) approve or reject. An approved label stays
//   valid while the recipe's current version, the product's size, Site Info and the required statement all still match
//   it; when any changes, it is marked superseded and the product can't be batched or printed until a new label is
//   approved. Labels are never deleted; old ones stay as the record of what was printed.
// Used by admin/kitchen_labels.php, includes/kitchen_batches.php and tests/kitchen_labels_test.php.
if (!function_exists('bcrf_kitchen_labels_ready')) {

require_once __DIR__ . '/kitchen_versions.php';

/** Migration 0054 has run. */
function bcrf_kitchen_labels_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM kitchen_labels LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** The view permission that lets someone approve labels (Owners/administrators always can). */
function bcrf_kitchen_label_perm(): string { return 'kitchen_labels_approve'; }

/** The required statement printed on every label (Kitchen › Labels settings). */
function bcrf_kitchen_label_statement_setting(PDO $pdo): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = 'kitchen_label_statement'");
        $st->execute();
        return trim((string)$st->fetchColumn());
    } catch (Throwable $e) { return ''; }
}

function bcrf_kitchen_label_statement_save(PDO $pdo, string $text): bool {
    $text = trim(mb_substr(preg_replace('/[ \t]+/', ' ', str_replace("\r", '', $text)), 0, 500));
    $old = bcrf_kitchen_label_statement_setting($pdo);
    if ($old === $text) return false;
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES ('kitchen_label_statement', ?)
                   ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")->execute([$text]);
    return true;
}

/** "Made by: farm, address" from Site Info. */
function bcrf_kitchen_label_made_by(): string {
    return function_exists('bcrf_label_made_by') ? bcrf_label_made_by() : '';
}

/**
 * What the label for a product says right now, from its recipe's current saved version.
 * Returns ['product', 'state' (version state), 'blockers' (can't draft), 'issues' (can draft, can't approve), 'fields'].
 * fields: version_id, version_no, statement, contains_line, sulfites (from the version's snapshot), net_qty, made_by, extra.
 */
function bcrf_kitchen_label_build(PDO $pdo, int $inventoryId): array {
    $p = bcrf_kitchen_product($pdo, $inventoryId);
    if (!$p) throw new BcrfKitchenError('That product no longer exists.');
    $blockers = []; $issues = []; $vs = null; $fields = null;
    if ($p['recipe_id'] === null) {
        $blockers[] = 'Link this product to its recipe in Kitchen › Products first.';
    } else {
        $vs = bcrf_kitchen_version_state($pdo, (int)$p['recipe_id']);
        if (!$vs) $blockers[] = 'Its recipe no longer exists.';
        elseif (!$vs['res']['ready']) $blockers[] = 'The recipe isn\'t ready for a label yet (weights or allergens missing). Fix it in Recipes › Label check.';
        elseif (!$vs['latest']) $blockers[] = 'Save the recipe as a version first (Recipes › Label check).';
        elseif (!$vs['current']) $blockers[] = 'The recipe changed since version ' . (int)$vs['latest']['version_no'] . '. Save a new version first (Recipes › Label check).';
    }
    if (!$blockers) {
        $v = $vs['latest'];
        $fields = ['version_id' => (int)$v['id'], 'version_no' => (int)$v['version_no'], 'statement' => (string)$v['statement'],
                   'contains_line' => (string)$v['contains_line'], 'sulfites' => bcrf_kitchen_snapshot_sulfites((array)$v['snapshot']), 'net_qty' => (string)$p['net_qty'],
                   'made_by' => bcrf_kitchen_label_made_by(), 'extra' => bcrf_kitchen_label_statement_setting($pdo)];
        if ($fields['net_qty'] === '') $issues[] = 'No net quantity: set the product\'s size (e.g. 8 oz) in Storefront › Products.';
        if ($fields['made_by'] === '') $issues[] = 'No farm name and address: fill in Site Info.';
        if (!(int)$p['approved_type']) $issues[] = 'NCDA&CS hasn\'t confirmed this product type yet (Kitchen › Products). You can still approve the label text.';
    }
    return ['product' => $p, 'state' => $vs, 'blockers' => $blockers, 'issues' => $issues, 'fields' => $fields];
}

/** Issues that stop approval (the NCDA note is a warning only). */
function bcrf_kitchen_label_hard_issues(array $issues): array {
    return array_values(array_filter($issues, fn($i) => !str_starts_with($i, 'NCDA')));
}

/** One label row with names. */
function bcrf_kitchen_label(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT l.*, v.version_no, v.snapshot AS version_snapshot, i.name AS inventory_name,
                                TRIM(CONCAT(COALESCE(c.first_name, ''), ' ', COALESCE(c.last_name, ''))) AS created_by_name,
                                TRIM(CONCAT(COALESCE(d.first_name, ''), ' ', COALESCE(d.last_name, ''))) AS decided_by_name
                         FROM kitchen_labels l
                         LEFT JOIN recipe_versions v ON v.id = l.recipe_version_id
                         LEFT JOIN inventory i ON i.id = l.inventory_id
                         LEFT JOIN users c ON c.id = l.created_by
                         LEFT JOIN users d ON d.id = l.decided_by
                         WHERE l.id = ?");
    $st->execute([$id]);
    $l = $st->fetch(PDO::FETCH_ASSOC);
    if (!$l) return null;
    // Sulfites come from the recipe version the label was made from (a version never changes, so neither does this).
    $l['sulfites'] = bcrf_kitchen_snapshot_sulfites(json_decode((string)($l['version_snapshot'] ?? ''), true) ?: []);
    unset($l['version_snapshot']);
    return $l;
}

/** Every label for a product, newest first. */
function bcrf_kitchen_labels_for(PDO $pdo, int $inventoryId): array {
    $st = $pdo->prepare("SELECT id FROM kitchen_labels WHERE inventory_id = ? ORDER BY id DESC");
    $st->execute([$inventoryId]);
    return array_map(fn($id) => bcrf_kitchen_label($pdo, (int)$id), $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Why a label no longer matches what the product's label would say now ('' = it still matches). */
function bcrf_kitchen_label_mismatch(array $label, ?array $build): string {
    if (!$build || $build['blockers'] || !$build['fields']) return $build && $build['blockers'] ? $build['blockers'][0] : 'The product can\'t make a label now.';
    $f = $build['fields'];
    if ((int)$label['recipe_version_id'] !== $f['version_id']) return 'The recipe is now version ' . $f['version_no'] . '.';
    if ((string)$label['statement'] !== $f['statement'] || (string)$label['contains_line'] !== $f['contains_line']) return 'The ingredient list or allergens changed.';
    if ((string)$label['net_qty'] !== $f['net_qty']) return 'The product\'s size changed.';
    if ((string)$label['made_by'] !== $f['made_by']) return 'The farm name or address in Site Info changed.';
    if ((string)($label['extra'] ?? '') !== $f['extra']) return 'The required statement changed.';
    return '';
}

/**
 * Where a product's label stands. Marks an approved label superseded when it no longer matches (and an open draft
 * likewise), so the record shows why. Returns ['build', 'approved' (valid approved label or null), 'draft' (open draft
 * or null), 'superseded_now' (labels just marked)].
 */
function bcrf_kitchen_label_state(PDO $pdo, int $inventoryId): array {
    $build = bcrf_kitchen_label_build($pdo, $inventoryId);
    $approved = null; $draft = null; $marked = [];
    $st = $pdo->prepare("SELECT id FROM kitchen_labels WHERE inventory_id = ? AND status IN ('approved', 'draft') ORDER BY id DESC");
    $st->execute([$inventoryId]);
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $lid) {
        $l = bcrf_kitchen_label($pdo, (int)$lid);
        $why = bcrf_kitchen_label_mismatch($l, $build);
        if ($why !== '') {
            $pdo->prepare("UPDATE kitchen_labels SET status = 'superseded', superseded_at = UTC_TIMESTAMP(), superseded_note = ? WHERE id = ? AND status IN ('approved', 'draft')")
                ->execute([mb_substr($why, 0, 255), (int)$lid]);
            $marked[] = (int)$lid;
            continue;
        }
        if ($l['status'] === 'approved' && !$approved) $approved = $l;
        if ($l['status'] === 'draft' && !$draft) $draft = $l;
    }
    return ['build' => $build, 'approved' => $approved, 'draft' => $draft, 'superseded_now' => $marked];
}

/** Draft (or redraft) a label for a product. Returns the label. */
function bcrf_kitchen_label_draft(PDO $pdo, int $inventoryId, string $productName, ?int $uid): array {
    $s = bcrf_kitchen_label_state($pdo, $inventoryId);
    $b = $s['build'];
    if ($b['blockers']) throw new BcrfKitchenError($b['blockers'][0]);
    $name = trim(mb_substr(preg_replace('/\s+/', ' ', $productName), 0, 150));
    if ($name === '') throw new BcrfKitchenError('Give the label a product name (the common name of the food, e.g. Strawberry Jam).');
    $f = $b['fields'];
    if ($s['approved'] && (string)$s['approved']['product_name'] === $name) throw new BcrfKitchenError('The approved label already says exactly this.');
    if ($s['draft']) {
        $pdo->prepare("UPDATE kitchen_labels SET product_name = ?, created_at = UTC_TIMESTAMP(), created_by = ? WHERE id = ?")->execute([$name, $uid, (int)$s['draft']['id']]);
        return bcrf_kitchen_label($pdo, (int)$s['draft']['id']);
    }
    $pdo->prepare("INSERT INTO kitchen_labels (inventory_id, recipe_version_id, product_name, statement, contains_line, net_qty, made_by, extra, status, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, 'draft', UTC_TIMESTAMP(), ?)")
        ->execute([$inventoryId, $f['version_id'], $name, $f['statement'], $f['contains_line'], $f['net_qty'], $f['made_by'], $f['extra'] !== '' ? $f['extra'] : null, $uid]);
    return bcrf_kitchen_label($pdo, (int)$pdo->lastInsertId());
}

/** Approve a draft. The caller checks the permission. The previously approved label becomes superseded. */
function bcrf_kitchen_label_approve(PDO $pdo, int $labelId, ?int $uid, string $note = ''): array {
    $l = bcrf_kitchen_label($pdo, $labelId);
    if (!$l) throw new BcrfKitchenError('That label no longer exists.');
    if ($l['status'] !== 'draft') throw new BcrfKitchenError('Only a draft can be approved (this one is ' . $l['status'] . ').');
    $s = bcrf_kitchen_label_state($pdo, (int)$l['inventory_id']);
    if (!$s['draft'] || (int)$s['draft']['id'] !== $labelId) throw new BcrfKitchenError('This draft is out of date (the recipe, size or farm details changed). Draft it again.');
    if ($hard = bcrf_kitchen_label_hard_issues($s['build']['issues'])) throw new BcrfKitchenError($hard[0]);
    $note = trim(mb_substr(preg_replace('/\s+/', ' ', $note), 0, 255));
    $pdo->beginTransaction();
    try {
        $pdo->prepare("UPDATE kitchen_labels SET status = 'superseded', superseded_at = UTC_TIMESTAMP(), superseded_note = ? WHERE inventory_id = ? AND status = 'approved'")
            ->execute(['Replaced by label #' . $labelId . '.', (int)$l['inventory_id']]);
        $st = $pdo->prepare("UPDATE kitchen_labels SET status = 'approved', decided_at = UTC_TIMESTAMP(), decided_by = ?, decision_note = ? WHERE id = ? AND status = 'draft'");
        $st->execute([$uid, $note !== '' ? $note : null, $labelId]);
        if ($st->rowCount() !== 1) throw new BcrfKitchenError('Someone else changed this label; reload.');
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return bcrf_kitchen_label($pdo, $labelId);
}

/** Send a draft back with a reason. */
function bcrf_kitchen_label_reject(PDO $pdo, int $labelId, ?int $uid, string $note): array {
    $note = trim(mb_substr(preg_replace('/\s+/', ' ', $note), 0, 255));
    if ($note === '') throw new BcrfKitchenError('Say what needs to change.');
    $st = $pdo->prepare("UPDATE kitchen_labels SET status = 'rejected', decided_at = UTC_TIMESTAMP(), decided_by = ?, decision_note = ? WHERE id = ? AND status = 'draft'");
    $st->execute([$uid, $note, $labelId]);
    if ($st->rowCount() !== 1) throw new BcrfKitchenError('Only a draft can be sent back.');
    return bcrf_kitchen_label($pdo, $labelId);
}

/** Linked products with their label status, for the list: 'approved', 'draft', 'needs' (none or out of date), 'blocked'. */
function bcrf_kitchen_label_overview(PDO $pdo): array {
    $out = [];
    foreach ($pdo->query("SELECT inventory_id FROM kitchen_products ORDER BY inventory_id")->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        $s = bcrf_kitchen_label_state($pdo, (int)$pid);
        $status = $s['approved'] ? 'approved' : ($s['draft'] ? 'draft' : ($s['build']['blockers'] ? 'blocked' : 'needs'));
        $out[] = ['id' => (int)$pid, 'name' => (string)$s['build']['product']['name'], 'recipe_title' => (string)($s['build']['product']['recipe_title'] ?? ''),
                  'status' => $status, 'approved' => $s['approved'], 'draft' => $s['draft'],
                  'why' => $s['build']['blockers'][0] ?? ''];
    }
    usort($out, fn($a, $b) => strcasecmp($a['name'], $b['name']));
    return $out;
}

/**
 * Register check (Nov 13, 2026; Phase 4 exit "every kitchen product sold has an approved label"): of these inventory ids,
 * the kitchen-linked ones without a valid approved label. Returns [id => name]. Empty when migration 0054 hasn't run.
 */
function bcrf_kitchen_unlabeled(PDO $pdo, array $inventoryIds): array {
    $ids = array_values(array_unique(array_filter(array_map('intval', $inventoryIds))));
    if (!$ids || !bcrf_kitchen_labels_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT inventory_id FROM kitchen_products WHERE inventory_id IN (" . implode(',', array_fill(0, count($ids), '?')) . ")");
    $st->execute($ids);
    $out = [];
    foreach ($st->fetchAll(PDO::FETCH_COLUMN) as $pid) {
        try { $s = bcrf_kitchen_label_state($pdo, (int)$pid); }
        catch (Throwable $e) { $out[(int)$pid] = 'product #' . (int)$pid; continue; }
        if (!$s['approved']) $out[(int)$pid] = (string)$s['build']['product']['name'];
    }
    return $out;
}

}
