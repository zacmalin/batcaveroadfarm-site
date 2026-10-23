<?php
// Fix 1: whitelist $target_table before ANY query touches it
if (empty($target_table) || !in_array($target_table, $system_tables)) return;

// ── FRACTION DISPLAY HELPER ───────────────────────────────────────────────────
// Shows stored decimals as clean numbers (0.25 → "0.25", 1.0 → "1", 0.5 → "0.5")
if (!function_exists('formatQtyForDisplay')) {
    function formatQtyForDisplay(float $qty): string {
        if ($qty == 0) return '';
        // Strip trailing zeros — 1.50 → 1.5, 1.00 → 1, 0.2500 → 0.25
        return rtrim(rtrim(number_format($qty, 4, '.', ''), '0'), '.');
    }
}

// Fix 9: only run DESCRIBE when the form is actually needed
$columns_schema = [];
$show_form      = true; // always show — list + form coexist
$describe_error = false;
try {
    $describe_stmt  = $pdo->query("DESCRIBE {$target_table}");
    $columns_schema = $describe_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    error_log('[ERP] Schema describe failed for ' . $target_table . ': ' . $e->getMessage());
    $describe_error = true;
}

// Reorder columns for breeds — status_badge, classification_id, is_active float up just before description
if ($target_table === 'breeds' && !empty($columns_schema)) {
    $pin_before_desc = ['status_badge', 'classification_id', '_classification_picker', 'is_active'];
    $pinned  = [];
    $rest    = [];
    $desc_col = null;

    foreach ($columns_schema as $col) {
        if ($col['Field'] === 'description') {
            $desc_col = $col;
        } elseif (in_array($col['Field'], $pin_before_desc)) {
            // preserve the desired sub-order: status_badge, classification_id, is_active
            $pinned[$col['Field']] = $col;
        } else {
            $rest[] = $col;
        }
    }

    // Inject synthetic column for the classification junction-table picker
    $pinned['_classification_picker'] = ['Field' => '_classification_picker', 'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];

    // Re-assemble: everything else → status_badge → classification_id → is_active → description → (remaining rich text handled by loop)
    $ordered = $rest;
    foreach ($pin_before_desc as $fname) {
        if (isset($pinned[$fname])) $ordered[] = $pinned[$fname];
    }
    if ($desc_col) $ordered[] = $desc_col;

    $columns_schema = $ordered;
}

// Reorder columns for ingredients — skip USDA nutrition columns from auto-render, inject nutrition panel
if ($target_table === 'ingredients' && !empty($columns_schema)) {
    $nutrition_skip = ['calories_per_100g','protein_per_100g','fat_per_100g','carbs_per_100g',
                       'fiber_per_100g','sugar_per_100g','sodium_per_100g','density_g_per_ml',
                       'weight_per_unit_g','usda_fdc_id','nutrition_verified','pairs_with_recipe_id'];
    $rest = [];
    foreach ($columns_schema as $col) {
        if (in_array($col['Field'], $nutrition_skip)) continue;
        $rest[] = $col;
    }
    $synth_nutr = ['Field' => '_ingredient_nutrition_panel', 'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
    $rest[] = $synth_nutr;
    $columns_schema = $rest;
}

// Reorder columns for recipes — featured_image → cuisine_id → categories → rest → ingredients → instructions
if ($target_table === 'recipes' && !empty($columns_schema)) {
    $pin_top   = ['title', 'featured_image', 'cuisine_id'];
    $instr_col = null;
    $pinned    = [];
    $rest      = [];

    $skip_recipe_fields = ['cuisine', 'protein_g', 'fat_g', 'carbs_g', 'fiber_g',
                           'sugar_g', 'sodium_mg', 'nutrition_source', 'nutrition_updated_at',
                           'pairs_with_recipe_id'];

    foreach ($columns_schema as $col) {
        if ($col['Field'] === 'cuisine')       continue;
        if (in_array($col['Field'], $skip_recipe_fields)) continue; // dropped column safety skip
        if ($col['Field'] === 'instructions') { $instr_col = $col; continue; }
        if (in_array($col['Field'], $pin_top))  { $pinned[$col['Field']] = $col; continue; }
        $rest[] = $col;
    }

    // Synthetic panels
    $synthetic_cats     = ['Field' => '_recipe_categories_panel', 'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
    $synthetic_ing      = ['Field' => '_recipe_ingredients_panel', 'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
    $synthetic_pairings = ['Field' => '_recipe_pairings_panel',    'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
    $synthetic_nutr     = ['Field' => '_recipe_nutrition_panel',   'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];

    // title → featured_image → cuisine_id → categories → rest → ingredients → instructions → pairings → nutrition
    $ordered = [];
    foreach ($pin_top as $fname) {
        if (isset($pinned[$fname])) $ordered[] = $pinned[$fname];
    }
    $ordered[] = $synthetic_cats;
    $ordered   = array_merge($ordered, $rest);
    $ordered[] = $synthetic_ing;
    if ($instr_col) $ordered[] = $instr_col;
    $ordered[] = $synthetic_pairings;
    $ordered[] = $synthetic_nutr;

    $columns_schema = $ordered;
}

// Reorder columns for blog_posts — skip category_id FK (handled by junction panel),
// inject _blog_categories_panel and _linked_recipe_picker before the rich text content field
if ($target_table === 'blog_posts' && !empty($columns_schema)) {
    $content_col = null;
    $rest        = [];

    foreach ($columns_schema as $col) {
        // Skip the raw FK — replaced by the junction panel below
        if ($col['Field'] === 'category_id') continue;
        // Skip linked_recipe_id — replaced by the custom picker below
        if ($col['Field'] === 'linked_recipe_id') continue;
        // Skip status — controlled by the Save Draft / Publish buttons, not a dropdown
        if ($col['Field'] === 'status') continue;
        // Skip social_shared_at — internal first-publish marker, not editable
        if ($col['Field'] === 'social_shared_at') continue;
        // Skip image_url — blog posts use featured_image instead
        if ($col['Field'] === 'image_url') continue;

        if (in_array($col['Field'], ['content', 'article', 'body'])) {
            $content_col = $col; // hold the rich-text field to place last
        } else {
            $rest[] = $col;
        }
    }

    $synthetic_cats   = ['Field' => '_blog_categories_panel',   'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];
    $synthetic_recipe = ['Field' => '_linked_recipe_picker',     'Type' => '_synthetic', 'Null' => 'YES', 'Default' => null, 'Extra' => ''];

    $ordered   = $rest;
    $ordered[] = $synthetic_cats;
    $ordered[] = $synthetic_recipe;
    if ($content_col) $ordered[] = $content_col;

    $columns_schema = $ordered;
}

// Reorder columns for inventory — category first, key operational fields above description
// Recipes: status + social_shared_at are internal markers (buttons/auto), not form fields
if ($target_table === 'recipes' && !empty($columns_schema)) {
    $columns_schema = array_values(array_filter($columns_schema, fn($c) => !in_array($c['Field'], ['status', 'social_shared_at'], true)));
}

if ($target_table === 'inventory' && !empty($columns_schema)) {
    $pin_first       = ['category_id'];
    $pin_before_desc = ['price', 'stock_quantity', 'size_value', 'size_unit_id', 'min_stock_level', 'is_alert_enabled'];
    $pinned          = [];
    $rest            = [];
    $desc_col        = null;

    foreach ($columns_schema as $col) {
        // Hide old category text column — managed via category_id FK dropdown
        if ($col['Field'] === 'category') continue;
        if ($col['Field'] === 'description') {
            $desc_col = $col;
        } elseif (in_array($col['Field'], $pin_first) || in_array($col['Field'], $pin_before_desc)) {
            $pinned[$col['Field']] = $col;
        } else {
            $rest[] = $col;
        }
    }

    $ordered = [];
    foreach ($pin_first as $fname) {
        if (isset($pinned[$fname])) $ordered[] = $pinned[$fname];
    }
    $ordered = array_merge($ordered, $rest);
    foreach ($pin_before_desc as $fname) {
        if (isset($pinned[$fname])) $ordered[] = $pinned[$fname];
    }
    if ($desc_col) $ordered[] = $desc_col;

    $columns_schema = $ordered;
}

// Fix 2: relation sub-panel queries use whitelisted $target_table (already guaranteed above)
// and parameterized values — pulled here so view stays clean
$active_c_ids   = [];
$active_i_ids   = [];
$active_cat_ids = [];
$ri_data        = [];

if ($edit_data) {
    if ($target_table === 'breeds') {
        try {
            $active_c_ids = $pdo->prepare("SELECT classification_id FROM breed_classifications WHERE breed_id = ?");
            $active_c_ids->execute([(int)$edit_data['id']]);
            $active_c_ids = $active_c_ids->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { error_log('[ERP] Breed classifications fetch failed: ' . $e->getMessage()); }
    }

    if (in_array($target_table, ['fruits_vegetables', 'baked_goods', 'pantry'])) {
        try {
            // Fix 2: product_type uses a parameter, not string interpolation
            $stmt = $pdo->prepare("SELECT ingredient_id FROM product_ingredients WHERE product_type = ? AND product_id = ?");
            $stmt->execute([$target_table, (int)$edit_data['id']]);
            $active_i_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { error_log('[ERP] Product ingredients fetch failed: ' . $e->getMessage()); }
    }

    // unit_conversions — preload unit names for meaningful display in record list
    $units_map = [];
    if ($target_table === 'unit_conversions') {
        try {
            $rows = $pdo->query("SELECT id, name FROM measurement_units ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $u) $units_map[(int)$u['id']] = $u['name'];
        } catch (Throwable $e) { error_log('[ERP] units_map fetch failed: ' . $e->getMessage()); }
    }

    if ($target_table === 'blog_posts') {
        try {
            $stmt = $pdo->prepare("SELECT category_id FROM blog_post_categories WHERE post_id = ?");
            $stmt->execute([(int)$edit_data['id']]);
            $active_cat_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { error_log('[ERP] Blog post categories fetch failed: ' . $e->getMessage()); }
    }

    if ($target_table === 'recipes') {
        try {
            $stmt = $pdo->prepare("SELECT category_id FROM recipe_categories_junction WHERE recipe_id = ?");
            $stmt->execute([(int)$edit_data['id']]);
            $active_cat_ids = $stmt->fetchAll(PDO::FETCH_COLUMN);
        } catch (Throwable $e) { error_log('[ERP] Recipe categories fetch failed: ' . $e->getMessage()); }

        try {
            // Fix 7: select only columns we need, not SELECT *
            $stmt = $pdo->prepare("SELECT ingredient_id, linked_recipe_id, quantity, unit_id, COALESCE(notes, '') AS notes, COALESCE(ingredient_group, '') AS ingredient_group FROM recipe_ingredients WHERE recipe_id = ?");
            $stmt->execute([(int)$edit_data['id']]);
            $ri_data = $stmt->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) { error_log('[ERP] Recipe ingredients fetch failed: ' . $e->getMessage()); }
    }
}

$ri_idx = count($ri_data); // starting index for JS new-row counter

// ── Combined autocomplete dataset for recipe ingredients (raw ingredients + recipes) ──
$ri_rec_map = [];
if ($target_table === 'recipes') {
    $cur_rid = (int)($edit_data['id'] ?? 0);
    try {
        foreach ($pdo->query("SELECT id, title FROM recipes ORDER BY title ASC") as $r) {
            if ((int)$r['id'] !== $cur_rid) $ri_rec_map[(int)$r['id']] = $r['title'];
        }
    } catch (Throwable $e) { error_log('[ERP] RI recipe list failed: ' . $e->getMessage()); }
}
$ri_ing_map = [];
foreach (($all_ingredients ?? []) as $__i) { $ri_ing_map[(int)$__i['id']] = $__i['singular_name']; }
$ri_resolve = function ($row) use ($ri_ing_map, $ri_rec_map) {
    $lr = isset($row['linked_recipe_id']) ? (int)$row['linked_recipe_id'] : 0;
    if ($lr > 0) return ['ref' => 'rec:' . $lr, 'label' => '📖 ' . ($ri_rec_map[$lr] ?? ('Recipe #' . $lr))];
    $ii = (int)($row['ingredient_id'] ?? 0);
    return ['ref' => $ii ? 'ing:' . $ii : '', 'label' => $ri_ing_map[$ii] ?? ''];
};
?>

<div class="grid grid-cols-1 md:grid-cols-3 gap-8">

    <!-- ── RECORD LIST ── -->
    <div class="bg-white rounded border border-stone-200 p-4 shadow-xs h-fit print:hidden <?= !empty($edit_data) ? 'hidden md:block' : '' ?>">
        <div class="flex justify-between items-center mb-3">
            <h2 class="text-xs uppercase font-bold text-stone-400 capitalize">
                <?= htmlspecialchars(str_replace('_', ' ', $target_table)) ?>
                <span class="text-stone-300 font-normal">(<?= count($active_records) ?>)</span>
            </h2>
            <a href="/admin/admin.php?view=schema&table=<?= urlencode($target_table) ?>"
                class="bg-stone-900 text-white font-bold text-[10px] px-2 py-1 rounded uppercase tracking-wider">
                + New
            </a>
        </div>

        <!-- live filter + sort on record list -->
        <?php if (count($active_records) > 1): ?>
        <div class="mb-2 space-y-1.5">
            <input type="text" id="recordListFilter" oninput="filterRecordList(this.value)"
                placeholder="Filter records..."
                class="w-full p-1.5 border rounded bg-stone-50 text-[11px] font-medium outline-none">
            <select id="recordListSort" onchange="sortRecordList(this.value)"
                class="w-full p-1.5 border rounded bg-stone-50 text-[11px] font-medium outline-none">
                <option value="">Sort…</option>
                <option value="name-asc">Name A–Z</option>
                <option value="name-desc">Name Z–A</option>
                <option value="date-desc">Newest first</option>
                <option value="date-asc">Oldest first</option>
            </select>
        </div>
        <?php endif; ?>

        <div id="recordList" class="divide-y max-h-[65vh] overflow-y-auto text-[11px] font-medium tracking-tight">
            <?php if (empty($active_records)): ?>
                <p class="text-stone-400 italic py-4 pl-1">No records yet.</p>
            <?php endif; ?>

            <?php foreach ($active_records as $rec):
                // unit_conversions — build "from_id → to_id (factor×)" until we can join unit names
                if ($target_table === 'unit_conversions') {
                    $from_name = $units_map[$rec['from_unit_id']] ?? ('Unit #' . $rec['from_unit_id']);
                    $to_name   = $units_map[$rec['to_unit_id']]   ?? ('Unit #' . $rec['to_unit_id']);
                    $factor    = isset($rec['conversion_factor']) ? (float)$rec['conversion_factor'] : '?';
                    $display_name = "{$from_name} → {$to_name} ({$factor}×)";
                } else {
                    $display_name = $rec['breed_name'] ?? $rec['class_name'] ?? $rec['category_name'] ??
                                    $rec['singular_name'] ?? $rec['name'] ?? $rec['title'] ?? $rec['email'] ?? 'Row #' . $rec['id'];
                }
                $is_editing   = isset($_GET['edit_id']) && (int)$_GET['edit_id'] === (int)$rec['id'];
                $is_active_row = isset($rec['is_active']) && (int)$rec['is_active'] === 1;

                // Blog posts & recipes use status field for colour — green = published, yellow = draft
                $post_status = in_array($target_table, ['blog_posts', 'recipes'], true) ? ($rec['status'] ?? 'draft') : null;

                if ($is_editing) {
                    $row_bg = 'bg-amber-50 -mx-1 px-1 rounded'; $name_cls = 'text-amber-800';
                } elseif ($post_status === 'published') {
                    $row_bg = 'bg-emerald-50'; $name_cls = 'text-emerald-800';
                } elseif ($post_status === 'draft') {
                    $row_bg = 'bg-yellow-50';  $name_cls = 'text-yellow-800';
                } elseif ($is_active_row) {
                    $row_bg = 'bg-emerald-50'; $name_cls = 'text-emerald-900';
                } else {
                    $row_bg = ''; $name_cls = 'text-stone-800';
                }
            ?>
                <div class="record-row py-2.5 flex justify-between items-center <?= $row_bg ?>"
                    data-name="<?= htmlspecialchars(strtolower($display_name), ENT_QUOTES) ?>"
                    data-date="<?= (int)strtotime($rec['updated_at'] ?? $rec['created_at'] ?? '@0') ?>">
                    <?php if ($target_table === 'recipes' && !empty($rec['featured_image'])): ?>
                    <img src="<?= htmlspecialchars($rec['featured_image']) ?>"
                         alt="" class="w-8 h-8 rounded object-cover shrink-0 mr-2 border border-stone-200">
                    <?php endif; ?>
                    <span class="truncate pr-2 font-bold <?= $name_cls ?>">
                        <?= $is_editing ? '✏️ ' : '' ?><?= htmlspecialchars($display_name) ?>
                        <?php if ($target_table === 'recipes' && isset($ratings_map[$rec['id']])): $rdata = $ratings_map[$rec['id']]; ?>
                            <span class="ml-1 text-[9px] font-mono text-amber-600">★ <?= $rdata['avg_r'] ?> (<?= $rdata['cnt'] ?>)</span>
                        <?php endif; ?>
                        <?php if ($post_status === 'published'): ?>
                            <span class="ml-1 text-[8px] font-black uppercase tracking-wider text-emerald-700 bg-emerald-100 border border-emerald-200 px-1 py-0.5 rounded">Live</span>
                        <?php elseif ($post_status === 'draft'): ?>
                            <span class="ml-1 text-[8px] font-black uppercase tracking-wider text-yellow-700 bg-yellow-100 border border-yellow-200 px-1 py-0.5 rounded">Draft</span>
                        <?php endif; ?>
                    </span>
                    <?php
                    $ts_display = $rec['updated_at'] ?? $rec['created_at'] ?? null;
                    if ($ts_display): ?>
                    <span class="text-[9px] font-mono text-stone-400 shrink-0 ml-2">
                        <?= date('M j', strtotime($ts_display)) ?>
                    </span>
                    <?php endif; ?>
                    <div class="space-x-3 shrink-0 flex items-center">
                        <a href="/admin/admin.php?view=schema&table=<?= urlencode($target_table) ?>&edit_id=<?= (int)$rec['id'] ?>"
                            class="text-stone-600 hover:text-stone-950 underline font-semibold">Edit</a>
                        <a href="/admin/admin.php?view=schema&table=<?= urlencode($target_table) ?>&delete_id=<?= (int)$rec['id'] ?>"
                            onclick="return sysDeleteConfirm('<?= htmlspecialchars(addslashes($display_name), ENT_QUOTES) ?>', this);"
                            class="text-red-600 hover:text-red-900 font-bold uppercase text-[9px] tracking-wider border border-red-100 bg-red-50/40 px-1.5 py-0.5 rounded">
                            Drop
                        </a>
                    </div>
                </div>
            <?php endforeach; ?>
        </div>
    </div>

    <!-- ── EDIT / ADD FORM ── -->
    <div class="md:col-span-2 bg-white rounded border border-stone-200 p-6 shadow-xs">
        <?php
        // Resolve a friendly label for the edit header — mirrors the list's logic
        // so classifications, ingredients, categories, unit conversions, subscribers
        // and users show their name instead of "Record #id".
        $edit_label = '';
        if ($edit_data) {
            if ($target_table === 'unit_conversions') {
                $__f = $units_map[$edit_data['from_unit_id']] ?? ('Unit #' . ($edit_data['from_unit_id'] ?? '?'));
                $__t = $units_map[$edit_data['to_unit_id']]   ?? ('Unit #' . ($edit_data['to_unit_id'] ?? '?'));
                $__x = isset($edit_data['conversion_factor']) ? (float)$edit_data['conversion_factor'] : '?';
                $edit_label = "{$__f} → {$__t} ({$__x}×)";
            } else {
                $__person = trim(($edit_data['first_name'] ?? '') . ' ' . ($edit_data['last_name'] ?? ''));
                $edit_label = $edit_data['breed_name'] ?? $edit_data['class_name'] ?? $edit_data['category_name']
                           ?? $edit_data['singular_name'] ?? $edit_data['name'] ?? $edit_data['title']
                           ?? $edit_data['username'] ?? $edit_data['user_name']
                           ?? ($__person !== '' ? $__person : null)
                           ?? $edit_data['email'] ?? ('Record #' . $edit_data['id']);
            }
        }
        ?>
        <h2 class="text-base font-bold font-serif text-stone-800 mb-4">
            <?= $edit_data
                ? 'Edit: <span class="text-stone-500 font-medium">' . htmlspecialchars($edit_label) . '</span>'
                : 'Add New ' . htmlspecialchars(ucwords(str_replace('_', ' ', $target_table))) ?>
        </h2>

        <?php if ($describe_error): ?>
            <p class="text-red-600 font-mono text-xs p-4 bg-red-50 border border-red-200 rounded">
                Could not load table structure. Check error log.
            </p>
        <?php else: ?>

        <!-- Fix 10: dirty-form unsaved changes warning -->
        <form method="POST" class="space-y-4 text-xs" id="schemaEditForm" data-dirty="false">
            <input type="hidden" name="csrf_token"        value="<?= $_SESSION['csrf_token'] ?>">
            <input type="hidden" name="save_schema_record" value="1">
            <input type="hidden" name="id"                value="<?= (int)($edit_data['id'] ?? 0) ?>">

            <div class="grid grid-cols-1 md:grid-cols-2 gap-4" id="schemaFieldGrid">
            <?php
            $rich_text_fields = ['description', 'instructions', 'article', 'content', 'notes'];
            $quill_inited     = false;

            foreach ($columns_schema as $col):
                $name = $col['Field'];
                $type = $col['Type'];

                // Skip system-managed columns
                if (in_array($name, ['id', 'created_at', 'updated_at', 'slug'])) continue;

                $val         = htmlspecialchars($edit_data[$name] ?? '', ENT_QUOTES);
                $raw_val     = $edit_data[$name] ?? '';
                // Fix 4: respect NULL-ability for all field types consistently
                $is_required = ($col['Null'] === 'NO' && $col['Default'] === null && $col['Extra'] !== 'auto_increment') ? 'required' : '';
            ?>

                <?php if ($name === '_classification_picker'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Classification</label>
                        <select name="classifications_matrix[]" class="w-full p-2 border bg-white rounded font-medium">
                            <option value="">-- None --</option>
                            <?php foreach ($all_classes as $cl): ?>
                                <option value="<?= (int)$cl['id'] ?>"
                                    <?= in_array($cl['id'], $active_c_ids) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cl['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif ($name === '_recipe_categories_panel'): ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block font-bold text-stone-700 text-xs">Categories</label>
                            <button type="button" onclick="toggleAddCategoryForm('recipes')"
                                class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 hover:text-emerald-900 border border-emerald-200 bg-emerald-50 px-2 py-0.5 rounded">
                                + Add New
                            </button>
                        </div>
                        <div id="addCategoryForm_recipes" class="hidden mb-2 flex gap-2">
                            <input type="text" id="newCategoryInput_recipes" maxlength="80"
                                placeholder="New category name..."
                                class="flex-1 p-1.5 border rounded bg-white text-xs font-medium outline-none">
                            <button type="button" onclick="submitNewCategory('recipes')"
                                class="bg-stone-900 text-white text-[10px] font-bold uppercase px-3 py-1 rounded hover:bg-stone-700">Save</button>
                            <button type="button" onclick="toggleAddCategoryForm('recipes')"
                                class="border text-stone-500 text-[10px] font-bold px-2 py-1 rounded hover:bg-stone-100">Cancel</button>
                        </div>
                        <div id="categoriesCheckboxList_recipes" class="grid grid-cols-4 gap-2 p-3 border rounded bg-stone-50 max-h-24 overflow-y-auto">
                            <?php foreach ($all_categories as $ct): ?>
                            <label class="flex items-center space-x-2 text-xxs font-medium cursor-pointer">
                                <input type="checkbox" name="categories_matrix[]"
                                    value="<?= (int)$ct['id'] ?>"
                                    <?= in_array($ct['id'], $active_cat_ids ?? []) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($ct['category_name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif ($name === '_blog_categories_panel'): ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block font-bold text-stone-700 text-xs">Categories</label>
                            <button type="button" onclick="toggleAddCategoryForm('blog')"
                                class="text-[10px] font-bold uppercase tracking-wider text-emerald-700 hover:text-emerald-900 border border-emerald-200 bg-emerald-50 px-2 py-0.5 rounded">
                                + Add New
                            </button>
                        </div>
                        <div id="addCategoryForm_blog" class="hidden mb-2 flex gap-2">
                            <input type="text" id="newCategoryInput_blog" maxlength="80"
                                placeholder="New category name..."
                                class="flex-1 p-1.5 border rounded bg-white text-xs font-medium outline-none">
                            <button type="button" onclick="submitNewCategory('blog')"
                                class="bg-stone-900 text-white text-[10px] font-bold uppercase px-3 py-1 rounded hover:bg-stone-700">Save</button>
                            <button type="button" onclick="toggleAddCategoryForm('blog')"
                                class="border text-stone-500 text-[10px] font-bold px-2 py-1 rounded hover:bg-stone-100">Cancel</button>
                        </div>
                        <div id="categoriesCheckboxList_blog" class="grid grid-cols-4 gap-2 p-3 border rounded bg-stone-50 max-h-28 overflow-y-auto">
                            <?php foreach (($all_blog_categories ?? []) as $ct): ?>
                            <label class="flex items-center space-x-2 text-xxs font-medium cursor-pointer">
                                <input type="checkbox" name="categories_matrix[]"
                                    value="<?= (int)$ct['id'] ?>"
                                    <?= in_array($ct['id'], $active_cat_ids ?? []) ? 'checked' : '' ?>>
                                <span><?= htmlspecialchars($ct['name']) ?></span>
                            </label>
                            <?php endforeach; ?>
                        </div>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif ($name === '_linked_recipe_picker'): ?>
                    <div class="space-y-1 col-span-2">
                        <label class="block font-bold text-stone-600">Linked Recipe</label>
                        <select name="fields[linked_recipe_id]" id="linkedRecipeSelect"
                            class="w-full p-2 border bg-white rounded font-medium text-xs">
                            <option value="">— None —</option>
                            <option value="0"  <?= isset($edit_data['linked_recipe_id']) && (string)$edit_data['linked_recipe_id'] === '0'  ? 'selected' : '' ?>>⬆ Always show newest recipe</option>
                            <option value="-1" <?= isset($edit_data['linked_recipe_id']) && (string)$edit_data['linked_recipe_id'] === '-1' ? 'selected' : '' ?>>🔀 Show a random recipe</option>
                            <optgroup label="── Specific Recipe ──">
                                <?php foreach ($all_recipes as $recipe): ?>
                                    <option value="<?= (int)$recipe['id'] ?>"
                                        <?= (isset($edit_data['linked_recipe_id']) && $edit_data['linked_recipe_id'] !== null && (int)$edit_data['linked_recipe_id'] === (int)$recipe['id'] && (int)$recipe['id'] > 0) ? 'selected' : '' ?>>
                                        <?= htmlspecialchars($recipe['title']) ?>
                                    </option>
                                <?php endforeach; ?>
                            </optgroup>
                        </select>
                        <p class="text-[10px] text-stone-400 font-mono mt-0.5">
                            0 = newest &bull; -1 = random &bull; positive ID = specific recipe
                        </p>
                    </div>

                <?php elseif ($name === '_ingredient_nutrition_panel'): ?>
                    <?php
                    $ing_id        = (int)($edit_data['id'] ?? 0);
                    $has_ing_nutr  = !empty($edit_data['calories_per_100g']);
                    $ing_name_for_usda = htmlspecialchars($edit_data['singular_name'] ?? '', ENT_QUOTES);
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-5 w-full border rounded-lg overflow-hidden">

                        <!-- Header -->
                        <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100 border-b">
                            <span class="font-black text-xs uppercase tracking-wider text-stone-700 font-mono">🔬 Nutrition Data (per 100g)</span>
                            <?php if ($ing_id): ?>
                            <button type="button"
                                id="usdaLookupBtn_<?= $ing_id ?>"
                                class="flex items-center gap-1.5 bg-blue-700 hover:bg-blue-900 text-white text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded transition font-mono">
                                🔍 Lookup via USDA
                            </button>
                            <?php endif; ?>
                        </div>

                        <!-- USDA results panel (hidden until lookup) -->
                        <div id="usdaResultsPanel_<?= $ing_id ?>" class="hidden border-b bg-blue-50 p-3">
                            <p class="text-[10px] font-bold uppercase tracking-wider text-blue-800 mb-2">Select best match:</p>
                            <div id="usdaResultsList_<?= $ing_id ?>" class="space-y-1"></div>
                        </div>

                        <!-- Nutrition fields -->
                        <div class="p-4 grid grid-cols-2 md:grid-cols-4 gap-3">
                            <?php
                            $nutr_fields = [
                                ['key' => 'calories_per_100g', 'label' => 'Calories',  'unit' => 'kcal'],
                                ['key' => 'protein_per_100g',  'label' => 'Protein',   'unit' => 'g'],
                                ['key' => 'fat_per_100g',      'label' => 'Fat',       'unit' => 'g'],
                                ['key' => 'carbs_per_100g',    'label' => 'Carbs',     'unit' => 'g'],
                                ['key' => 'fiber_per_100g',    'label' => 'Fiber',     'unit' => 'g'],
                                ['key' => 'sugar_per_100g',    'label' => 'Sugar',     'unit' => 'g'],
                                ['key' => 'sodium_per_100g',   'label' => 'Sodium',    'unit' => 'mg'],
                            ];
                            foreach ($nutr_fields as $nf): ?>
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    <?= $nf['label'] ?> <span class="font-normal">(<?= $nf['unit'] ?>)</span>
                                </label>
                                <input type="number" step="0.01" min="0"
                                    id="ing_<?= $nf['key'] ?>_<?= $ing_id ?>"
                                    name="fields[<?= $nf['key'] ?>]"
                                    value="<?= htmlspecialchars($edit_data[$nf['key']] ?? '', ENT_QUOTES) ?>"
                                    placeholder="—"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-sm outline-none">
                            </div>
                            <?php endforeach; ?>
                        </div>

                        <!-- Density + count weight -->
                        <div class="px-4 pb-4 grid grid-cols-2 gap-3 border-t pt-3">
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    Density <span class="font-normal">(g/mL — for volume units)</span>
                                </label>
                                <input type="number" step="0.0001" min="0"
                                    id="ing_density_<?= $ing_id ?>"
                                    name="fields[density_g_per_ml]"
                                    value="<?= htmlspecialchars($edit_data['density_g_per_ml'] ?? '', ENT_QUOTES) ?>"
                                    placeholder="e.g. water=1.0, flour=0.53, honey=1.42"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-xs outline-none">
                            </div>
                            <div>
                                <label class="block text-[10px] font-bold text-stone-500 uppercase tracking-wider mb-0.5">
                                    Weight/unit <span class="font-normal">(g — for count units)</span>
                                </label>
                                <input type="number" step="0.1" min="0"
                                    id="ing_weight_<?= $ing_id ?>"
                                    name="fields[weight_per_unit_g]"
                                    value="<?= htmlspecialchars($edit_data['weight_per_unit_g'] ?? '', ENT_QUOTES) ?>"
                                    placeholder="e.g. egg=50, garlic clove=5"
                                    class="w-full p-1.5 border bg-stone-50 rounded font-mono text-xs outline-none">
                            </div>
                        </div>

                        <!-- Save confirmation message -->
                        <div id="usdaSaveMsg_<?= $ing_id ?>" class="hidden mx-4 mb-3 p-2 rounded text-xs font-mono"></div>
                    </div>

                    <?php if ($ing_id): ?>
                    <script>
                    /* Self-contained USDA lookup — no dependency on main script block */
                    (function() {
                        var ING_ID   = <?= $ing_id ?>;
                        var CSRF     = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

                        var btn     = document.getElementById('usdaLookupBtn_' + ING_ID);
                        var panel   = document.getElementById('usdaResultsPanel_' + ING_ID);
                        var list    = document.getElementById('usdaResultsList_' + ING_ID);
                        var saveMsg = document.getElementById('usdaSaveMsg_' + ING_ID);

                        console.log('[USDA] Self-contained script loaded, btn:', btn);

                        if (!btn) {
                            console.error('[USDA] Button not found: usdaLookupBtn_' + ING_ID);
                            return;
                        }

                        btn.addEventListener('click', function() {
                            console.log('[USDA] Button clicked');

                            var nameInput = document.querySelector('input[name="fields[singular_name]"]');
                            var ingName   = nameInput ? nameInput.value.trim() : '';

                            if (!ingName) {
                                showMsg('amber', '⚠️ Enter a singular name first.');
                                return;
                            }

                            btn.disabled    = true;
                            btn.textContent = '⏳ Searching…';
                            hideMsg();

                            var body = new URLSearchParams({
                                csrf_token: CSRF,
                                action:     'search',
                                query:      ingName
                            });

                            fetch('/admin/handlers/lookup_ingredient_nutrition.php', {
                                method:  'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body:    body.toString()
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (!data.success) {
                                    showMsg('red', '⚠️ ' + data.message);
                                    return;
                                }
                                renderResults(data.results);
                                if (panel) panel.classList.remove('hidden');
                            })
                            .catch(function(err) {
                                console.error('[USDA] fetch error:', err);
                                showMsg('red', '⚠️ Network error — ' + err.message);
                            })
                            .finally(function() {
                                btn.disabled    = false;
                                btn.innerHTML   = '🔍 Lookup via USDA';
                            });
                        });

                        function renderResults(results) {
                            if (!list) return;
                            list.innerHTML = '';
                            results.forEach(function(food) {
                                var row  = document.createElement('div');
                                row.className = 'flex items-start justify-between gap-2 bg-white border rounded p-2 text-[10px]';

                                var cals = food.nutrients && food.nutrients.calories_per_100g !== null
                                    ? food.nutrients.calories_per_100g + ' kcal/100g' : '';

                                var info = document.createElement('div');
                                info.innerHTML = '<span class="font-bold text-stone-800">'
                                    + escHtml(food.description) + '</span>'
                                    + '<span class="ml-1 text-stone-400">' + escHtml(food.data_type) + '</span>'
                                    + (cals ? '<span class="ml-2 font-mono text-emerald-700">' + cals + '</span>' : '');

                                var useBtn = document.createElement('button');
                                useBtn.type        = 'button';
                                useBtn.textContent = 'Use this';
                                useBtn.className   = 'shrink-0 bg-blue-700 text-white px-2 py-0.5 rounded font-bold hover:bg-blue-900';
                                useBtn.addEventListener('click', (function(f) {
                                    return function() { applyResult(f); };
                                })(food));

                                row.append(info, useBtn);
                                list.appendChild(row);
                            });
                        }

                        function applyResult(food) {
                            var n = food.nutrients || {};
                            var map = {
                                'calories_per_100g': n.calories_per_100g,
                                'protein_per_100g':  n.protein_per_100g,
                                'fat_per_100g':      n.fat_per_100g,
                                'carbs_per_100g':    n.carbs_per_100g,
                                'fiber_per_100g':    n.fiber_per_100g,
                                'sugar_per_100g':    n.sugar_per_100g,
                                'sodium_per_100g':   n.sodium_per_100g
                            };
                            Object.keys(map).forEach(function(key) {
                                var el = document.getElementById('ing_' + key + '_' + ING_ID);
                                if (el && map[key] !== null && map[key] !== undefined) el.value = map[key];
                            });
                            var fdcEl = document.querySelector('input[name="fields[usda_fdc_id]"]');
                            if (fdcEl) fdcEl.value = food.fdc_id || '';

                            if (panel) panel.classList.add('hidden');
                            showMsg('emerald', '✅ Filled from: "' + food.description + '" — click Save Record to store.');
                        }

                        function showMsg(color, text) {
                            if (!saveMsg) return;
                            saveMsg.textContent = text;
                            saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono '
                                + 'bg-' + color + '-50 text-' + color + '-800 border border-' + color + '-200';
                            saveMsg.classList.remove('hidden');
                        }
                        function hideMsg() {
                            if (saveMsg) saveMsg.className = 'hidden';
                        }
                        function escHtml(s) {
                            return String(s).replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
                        }
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif ($name === '_recipe_pairings_panel'): ?>
                    <?php
                    $cur_recipe_id   = (int)($edit_data['id'] ?? 0);
                    $active_pair_ids = [];
                    if ($cur_recipe_id) {
                        try {
                            // Canonical storage: lower id is recipe_id. Query both directions.
                            $ps = $pdo->prepare("
                                SELECT CASE WHEN recipe_id = ? THEN paired_recipe_id ELSE recipe_id END
                                FROM recipe_pairings WHERE recipe_id = ? OR paired_recipe_id = ?
                            ");
                            $ps->execute([$cur_recipe_id, $cur_recipe_id, $cur_recipe_id]);
                            $active_pair_ids = $ps->fetchAll(PDO::FETCH_COLUMN);
                        } catch (Throwable $e) {}
                    }
                    try {
                        $all_pair_recipes = $pdo->query("SELECT id, title FROM recipes ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
                    } catch (Throwable $e) { $all_pair_recipes = []; }
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <div class="flex justify-between items-center mb-2">
                            <label class="block font-bold text-stone-700 text-xs">🍽 Pairs Well With</label>
                            <span class="text-[10px] text-stone-400">Add recipes that pair well with this one — works both ways automatically</span>
                        </div>
                        <div id="pairingsWrapper" class="space-y-2 border p-3 rounded bg-stone-50">
                            <?php foreach ($active_pair_ids as $apid): ?>
                            <div class="pairing-row flex items-center gap-2">
                                <span class="text-stone-400 shrink-0 text-xs">🍽</span>
                                <select name="pairings_matrix[]" class="p-1 border bg-white rounded flex-1 text-xs">
                                    <option value="">— Select a recipe —</option>
                                    <?php foreach ($all_pair_recipes as $pr): if ($cur_recipe_id && (int)$pr['id'] === $cur_recipe_id) continue; ?>
                                        <option value="<?= (int)$pr['id'] ?>" <?= (int)$pr['id'] === (int)$apid ? 'selected' : '' ?>><?= htmlspecialchars($pr['title']) ?></option>
                                    <?php endforeach; ?>
                                </select>
                                <button type="button" onclick="this.closest('.pairing-row').remove()" class="text-red-600 font-bold px-1 hover:text-red-900 shrink-0">✕</button>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" id="addPairingBtn"
                            class="mt-2 text-xxs font-bold uppercase tracking-wider bg-stone-800 text-white px-2.5 py-1.5 rounded hover:bg-stone-950 transition">
                            Add Pairs Well With +
                        </button>
                    </div>

                    <script>
                    /* Self-contained: "Add Pairs Well With" spawns a recipe dropdown row (mirrors the ingredients UI). */
                    (function () {
                        var PAIR_RECIPES = <?= json_encode(array_values(array_filter(array_map(function ($pr) use ($cur_recipe_id) {
                            return ($cur_recipe_id && (int)$pr['id'] === $cur_recipe_id) ? null : ['id' => (int)$pr['id'], 'title' => $pr['title']];
                        }, $all_pair_recipes))), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
                        var wrap = document.getElementById('pairingsWrapper');
                        var btn  = document.getElementById('addPairingBtn');
                        if (!wrap || !btn) return;
                        btn.addEventListener('click', function () {
                            var row = document.createElement('div');
                            row.className = 'pairing-row flex items-center gap-2';

                            var icon = document.createElement('span');
                            icon.className = 'text-stone-400 shrink-0 text-xs';
                            icon.textContent = '\uD83C\uDF7D';

                            var sel = document.createElement('select');
                            sel.name = 'pairings_matrix[]';
                            sel.className = 'p-1 border bg-white rounded flex-1 text-xs';
                            var none = document.createElement('option');
                            none.value = ''; none.textContent = '\u2014 Select a recipe \u2014';
                            sel.appendChild(none);
                            PAIR_RECIPES.forEach(function (r) {
                                var op = document.createElement('option');
                                op.value = r.id; op.textContent = r.title;
                                sel.appendChild(op);
                            });

                            var rm = document.createElement('button');
                            rm.type = 'button'; rm.textContent = '\u2715';
                            rm.className = 'text-red-600 font-bold px-1 hover:text-red-900 shrink-0';
                            rm.onclick = function () { row.remove(); };

                            row.append(icon, sel, rm);
                            wrap.appendChild(row);
                        });
                    })();
                    </script>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif ($name === '_recipe_nutrition_panel'): ?>
                    <?php
                    $has_nutrition = !empty($edit_data['calories']);
                    $nutr_source   = $edit_data['nutrition_source']     ?? null;
                    $nutr_updated  = $edit_data['nutrition_updated_at'] ?? null;
                    $rec_id_for_nutr = (int)($edit_data['id'] ?? 0);
                    ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-6 w-full border rounded-lg overflow-hidden" id="nutritionPanel">

                        <!-- Header bar -->
                        <div class="flex items-center justify-between px-4 py-2.5 bg-stone-100 border-b">
                            <div>
                                <span class="font-black text-xs uppercase tracking-wider text-stone-700 font-mono">🧮 Nutrition Facts</span>
                                <?php if ($has_nutrition && $nutr_updated): ?>
                                <span class="ml-2 text-[10px] text-stone-400 font-mono">
                                    via <?= htmlspecialchars($nutr_source ?? 'edamam') ?>
                                    · <?= date('M j, Y', strtotime($nutr_updated)) ?>
                                </span>
                                <?php endif; ?>
                            </div>
                            <?php if ($rec_id_for_nutr): ?>
                            <button type="button"
                                id="calcNutrBtn"
                                class="flex items-center gap-1.5 bg-emerald-700 hover:bg-emerald-900 text-white text-[10px] font-black uppercase tracking-wider px-3 py-1.5 rounded transition font-mono">
                                <span id="calcNutrBtnIcon">⚡</span>
                                <span id="calcNutrBtnLabel"><?= $has_nutrition ? 'Recalculate' : 'Calculate' ?></span>
                            </button>
                            <?php else: ?>
                            <span class="text-[10px] text-stone-400 font-mono italic">Save the recipe first to enable calculation</span>
                            <?php endif; ?>
                        </div>

                        <!-- Nutrition label -->
                        <div class="p-4">
                            <?php if (!$has_nutrition): ?>
                            <p class="text-stone-400 text-xs italic text-center py-4" id="nutritionEmptyMsg">
                                No nutrition data yet. Add ingredients and click Calculate.
                            </p>
                            <?php endif; ?>

                            <div id="nutritionDataGrid" class="<?= $has_nutrition ? '' : 'hidden' ?>">
                                <!-- Per-serving label -->
                                <p class="text-[10px] text-stone-500 font-mono uppercase tracking-wider mb-3">Per serving</p>

                                <!-- Big calorie display -->
                                <div class="flex items-baseline gap-2 mb-4 pb-3 border-b-4 border-stone-900">
                                    <span class="text-4xl font-black text-stone-900" id="nutrCalories"><?= $edit_data['calories'] ?? '—' ?></span>
                                    <span class="text-sm font-bold text-stone-600">Calories</span>
                                </div>

                                <!-- Macro grid -->
                                <div class="grid grid-cols-3 gap-3 mb-3">
                                    <?php
                                    $macros = [
                                        ['label' => 'Protein',    'id' => 'nutrProtein', 'val' => $edit_data['protein_g'] ?? null, 'unit' => 'g',  'color' => 'text-blue-700'],
                                        ['label' => 'Fat',        'id' => 'nutrFat',     'val' => $edit_data['fat_g']     ?? null, 'unit' => 'g',  'color' => 'text-amber-700'],
                                        ['label' => 'Carbs',      'id' => 'nutrCarbs',   'val' => $edit_data['carbs_g']   ?? null, 'unit' => 'g',  'color' => 'text-emerald-700'],
                                    ];
                                    foreach ($macros as $m): ?>
                                    <div class="bg-stone-50 border rounded p-2.5 text-center">
                                        <div class="text-xl font-black <?= $m['color'] ?>" id="<?= $m['id'] ?>">
                                            <?= $m['val'] !== null ? $m['val'] . $m['unit'] : '—' ?>
                                        </div>
                                        <div class="text-[10px] text-stone-500 font-bold uppercase tracking-wide mt-0.5"><?= $m['label'] ?></div>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Minor nutrients -->
                                <div class="space-y-1 border-t pt-2 text-xs font-mono">
                                    <?php
                                    $minors = [
                                        ['label' => 'Dietary Fiber', 'id' => 'nutrFiber',  'val' => $edit_data['fiber_g']  ?? null, 'unit' => 'g'],
                                        ['label' => 'Sugar',         'id' => 'nutrSugar',  'val' => $edit_data['sugar_g']  ?? null, 'unit' => 'g'],
                                        ['label' => 'Sodium',        'id' => 'nutrSodium', 'val' => $edit_data['sodium_mg'] ?? null, 'unit' => 'mg'],
                                    ];
                                    foreach ($minors as $mn): ?>
                                    <div class="flex justify-between text-stone-600 py-0.5 border-b border-stone-100">
                                        <span><?= $mn['label'] ?></span>
                                        <span class="font-bold" id="<?= $mn['id'] ?>">
                                            <?= $mn['val'] !== null ? $mn['val'] . $mn['unit'] : '—' ?>
                                        </span>
                                    </div>
                                    <?php endforeach; ?>
                                </div>

                                <!-- Disclaimer -->
                                <p class="text-[9px] text-stone-400 mt-3 leading-relaxed">
                                    Nutritional values calculated via Edamam and are estimates only. Actual values may vary based on preparation, ingredient brands, and portion sizes.
                                </p>
                            </div>

                            <!-- Error/status message -->
                            <div id="nutritionMsg" class="hidden mt-2 p-2 rounded text-xs font-mono"></div>
                        </div>
                    </div>

                    <?php if ($rec_id_for_nutr): ?>
                    <script>
                    /* Self-contained nutrition calculator — no dependency on main script block */
                    (function() {
                        var REC_ID = <?= $rec_id_for_nutr ?>;
                        var CSRF   = <?= json_encode($_SESSION['csrf_token'] ?? '') ?>;

                        var btn   = document.getElementById('calcNutrBtn');
                        var icon  = document.getElementById('calcNutrBtnIcon');
                        var label = document.getElementById('calcNutrBtnLabel');
                        var msg   = document.getElementById('nutritionMsg');
                        var grid  = document.getElementById('nutritionDataGrid');
                        var empty = document.getElementById('nutritionEmptyMsg');

                        if (!btn) return;

                        btn.addEventListener('click', function() {
                            btn.disabled     = true;
                            icon.textContent = '⏳';
                            label.textContent = 'Calculating…';
                            if (msg) msg.className = 'hidden';

                            fetch('/admin/handlers/calculate_nutrition.php', {
                                method:  'POST',
                                headers: {'Content-Type': 'application/x-www-form-urlencoded'},
                                body:    new URLSearchParams({csrf_token: CSRF, recipe_id: REC_ID}).toString()
                            })
                            .then(function(r) { return r.json(); })
                            .then(function(data) {
                                if (data.success) {
                                    var d = data;
                                    var setEl = function(id, val) {
                                        var el = document.getElementById(id);
                                        if (el) el.textContent = val;
                                    };
                                    setEl('nutrCalories', d.calories);
                                    setEl('nutrProtein',  d.protein  + 'g');
                                    setEl('nutrFat',      d.fat      + 'g');
                                    setEl('nutrCarbs',    d.carbs    + 'g');
                                    setEl('nutrFiber',    d.fiber    + 'g');
                                    setEl('nutrSugar',    d.sugar    + 'g');
                                    setEl('nutrSodium',   d.sodium   + 'mg');

                                    // Sync calories into the form field so Save Record preserves it
                                    var calField = document.querySelector('input[name="fields[calories]"], input[name="fields[calories]"]');
                                    if (calField) calField.value = d.calories;

                                    if (grid)  grid.classList.remove('hidden');
                                    if (empty) empty.classList.add('hidden');

                                    if (msg) {
                                        msg.textContent = '✅ ' + data.message;
                                        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';
                                    }
                                    icon.textContent  = '✅';
                                    label.textContent = 'Recalculate';
                                } else {
                                    if (msg) {
                                        msg.textContent = '⚠️ ' + data.message;
                                        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
                                    }
                                    icon.textContent  = '⚡';
                                    label.textContent = 'Try Again';
                                }
                                if (msg) msg.classList.remove('hidden');
                            })
                            .catch(function(err) {
                                if (msg) {
                                    msg.textContent = '⚠️ Network error — ' + err.message;
                                    msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
                                    msg.classList.remove('hidden');
                                }
                                icon.textContent  = '⚡';
                                label.textContent = 'Try Again';
                            })
                            .finally(function() {
                                btn.disabled = false;
                            });
                        });
                    })();
                    </script>
                    <?php endif; ?>

                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif ($name === '_recipe_ingredients_panel'): ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <label class="block font-bold text-stone-800 mb-2 text-xs">Ingredients</label>
                        <p class="text-[11px] text-stone-500 mb-2 -mt-1">Type to search — pick a raw ingredient or another recipe (recipes are marked 📖) to use it as a sub-recipe. Use the amber <strong>Group heading</strong> box to split ingredients into sections (e.g. "Pie Crust", "Filling") — new rows inherit the group above, so you only type it once per section. Leave blank for a single ungrouped list.</p>
                        <style>
                          .ri-combo{position:relative}
                          .ri-dropdown{position:absolute;z-index:60;left:0;right:0;top:100%;margin-top:2px;max-height:240px;overflow:auto;background:#fff;border:1px solid #d6d3d1;border-radius:6px;box-shadow:0 8px 20px rgba(0,0,0,.15)}
                          .ri-item{padding:6px 10px;font-size:12px;cursor:pointer;white-space:nowrap;overflow:hidden;text-overflow:ellipsis}
                          .ri-item:hover,.ri-item.ri-active{background:#f5f5f4}
                          .ri-item-recipe{color:#4a6741;font-weight:600}
                          .ri-empty{padding:6px 10px;font-size:12px;color:#a8a29e}
                        </style>
                        <div id="ingredientsDynamicWrapper" class="space-y-2 border p-3 rounded bg-stone-50">
                            <?php foreach ($ri_data as $idx => $ri_row): ?>
                            <div class="dynamic-ingredient-row text-xs space-y-1.5 pb-2 border-b border-stone-200 last:border-0">
                                <div class="flex items-center space-x-2">
                                    <span class="text-[10px] font-black text-stone-400 w-5 shrink-0 text-right"><?= $idx + 1 ?>.</span>
                                    <?php $ri_pick = $ri_resolve($ri_row); ?>
                                    <div class="ri-combo relative flex-1">
                                        <input type="hidden" name="recipe_ingredients_block[<?= $idx ?>][ref]" value="<?= htmlspecialchars($ri_pick['ref'], ENT_QUOTES) ?>">
                                        <input type="text" class="ri-search p-1 border bg-white rounded w-full" autocomplete="off" placeholder="Type an ingredient or recipe…" value="<?= htmlspecialchars($ri_pick['label'], ENT_QUOTES) ?>">
                                        <div class="ri-dropdown" style="display:none"></div>
                                    </div>
                                    <input type="text" inputmode="decimal"
                                        name="recipe_ingredients_block[<?= $idx ?>][quantity]"
                                        value="<?= htmlspecialchars(formatQtyForDisplay((float)$ri_row['quantity']), ENT_QUOTES) ?>"
                                        placeholder="e.g. 1/4"
                                        onblur="this.value = parseFractionJS(this.value)"
                                        class="w-20 p-1 border bg-white rounded font-mono text-center">
                                    <select name="recipe_ingredients_block[<?= $idx ?>][unit_id]" class="p-1 border bg-white rounded w-28">
                                        <?php foreach ($all_units as $u): ?>
                                            <option value="<?= (int)$u['id'] ?>" <?= $ri_row['unit_id'] == $u['id'] ? 'selected' : '' ?>>
                                                <?= htmlspecialchars($u['name']) ?>
                                            </option>
                                        <?php endforeach; ?>
                                    </select>
                                    <button type="button" onclick="this.closest('.dynamic-ingredient-row').remove()"
                                        class="text-red-600 font-bold px-1 hover:text-red-900 shrink-0">✕</button>
                                </div>
                                <div class="flex items-center space-x-2 pl-7">
                                    <input type="text" maxlength="80"
                                        name="recipe_ingredients_block[<?= $idx ?>][ingredient_group]"
                                        value="<?= htmlspecialchars($ri_row['ingredient_group'] ?? '', ENT_QUOTES) ?>"
                                        placeholder="Group heading e.g. Pie Crust (optional)"
                                        class="ri-group w-44 shrink-0 p-1 border bg-amber-50 border-amber-200 rounded text-[11px] font-semibold text-amber-900">
                                    <input type="text" maxlength="120"
                                        name="recipe_ingredients_block[<?= $idx ?>][notes]"
                                        value="<?= htmlspecialchars($ri_row['notes'] ?? '', ENT_QUOTES) ?>"
                                        placeholder="Preparation note e.g. finely chopped (optional)"
                                        class="w-full p-1 border bg-white rounded text-stone-500 italic text-[11px]">
                                </div>
                            </div>
                            <?php endforeach; ?>
                        </div>
                        <button type="button" onclick="addNewIngredientRow()"
                            class="mt-2 text-xxs font-bold uppercase tracking-wider bg-stone-800 text-white px-2.5 py-1.5 rounded hover:bg-stone-950 transition">
                            Add Ingredient +
                        </button>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php elseif (in_array($name, $rich_text_fields)): ?>
                    <?php // Fix 6: close and reopen grid cleanly using a full-width wrapper outside the grid ?>
                    </div><!-- /schemaFieldGrid -->
                    <div class="mt-4 w-full">
                        <label class="block font-bold text-stone-600 capitalize mb-2">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <?php if (!$quill_inited): ?>
                        <link href="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.snow.css" rel="stylesheet">
                        <script src="https://cdn.jsdelivr.net/npm/quill@2.0.2/dist/quill.min.js"></script>


<!-- ── BLOG POST PREVIEW MODAL ── -->
<div id="blogPreviewModal"
    class="fixed inset-0 bg-stone-900/70 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg shadow-2xl w-full max-w-3xl flex flex-col" style="max-height:90vh;">
        <div class="flex justify-between items-center px-5 py-3 border-b bg-stone-50 rounded-t-lg shrink-0">
            <span id="blogPreviewTitle" class="font-bold text-stone-800 text-sm font-serif truncate max-w-lg"></span>
            <button type="button" onclick="closeBlogPreview()"
                class="text-[10px] font-bold uppercase tracking-wider font-mono border px-3 py-1.5 rounded hover:bg-stone-900 hover:text-white transition shrink-0">
                Close ✕
            </button>
        </div>
        <div class="flex-1 overflow-hidden rounded-b-lg">
            <iframe id="blogPreviewFrame"
                srcdoc=""
                class="w-full h-full border-0"
                style="min-height:520px;"
                sandbox="allow-same-origin">
            </iframe>
        </div>
    </div>
</div>
                        <?php $quill_inited = true; endif; ?>
                        <!-- Fix 3: raw_val goes through htmlspecialchars before echoing into the div -->
                        <div id="quill_<?= $name ?>" class="bg-white w-full border border-stone-300 rounded"
                            style="height:400px;"><?= htmlspecialchars($raw_val) ?></div>
                        <input type="hidden" name="fields[<?= $name ?>]" id="hidden_<?= $name ?>"
                            value="<?= $val ?>">
                        <script>
                            (function() {
                                const q = new Quill('#quill_<?= $name ?>', {
                                    theme: 'snow',
                                    modules: { toolbar: [
                                        [{ header: [1,2,3,false] }],
                                        ['bold','italic','underline','strike'],
                                        ['link','image','blockquote','code-block'],
                                        [{ list:'ordered' }, { list:'bullet' }],
                                        ['clean']
                                    ]}
                                });
                                // Seed with existing HTML (Quill expects raw HTML, not escaped)
                                q.root.innerHTML = <?= json_encode($raw_val) ?>;
                                q.on('text-change', () => {
                                    document.getElementById('hidden_<?= $name ?>').value = q.root.innerHTML;
                                    document.getElementById('schemaEditForm').dataset.dirty = 'true';
                                });
                            })();
                        </script>
                    </div>
                    <div class="grid grid-cols-1 md:grid-cols-2 gap-4 mt-4" id="schemaFieldGrid">

                <?php // Fix 5: anchor is_ check to start of field name to avoid false matches on e.g. artist_id
                elseif (strpos($type, 'tinyint(1)') !== false || $type === 'boolean' || str_starts_with($name, 'is_')): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <label class="flex items-center space-x-2 py-1 select-none font-medium cursor-pointer">
                            <input type="checkbox" name="fields[<?= $name ?>]" value="1"
                                <?= $raw_val ? 'checked' : '' ?>>
                            <span>Enabled</span>
                        </label>
                    </div>

                <?php elseif (str_starts_with($type, 'enum(') || str_starts_with($type, 'set(')):
                    // Parse allowed values from e.g. enum('active','retired','pending')
                    preg_match_all("/'([^']+)'/", $type, $enum_matches);
                    $enum_values = $enum_matches[1] ?? [];
                    $is_set      = str_starts_with($type, 'set(');
                ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <?php if ($is_set): ?>
                            <?php // SET columns — multi-select checkboxes ?>
                            <div class="grid grid-cols-2 gap-1 p-2 border rounded bg-stone-50">
                                <?php
                                $set_active = array_map('trim', explode(',', $raw_val));
                                foreach ($enum_values as $ev): ?>
                                <label class="flex items-center space-x-2 text-xxs font-medium cursor-pointer">
                                    <input type="checkbox" name="fields[<?= $name ?>][]"
                                        value="<?= htmlspecialchars($ev, ENT_QUOTES) ?>"
                                        <?= in_array($ev, $set_active) ? 'checked' : '' ?>>
                                    <span><?= htmlspecialchars($ev) ?></span>
                                </label>
                                <?php endforeach; ?>
                            </div>
                        <?php else: ?>
                            <?php // ENUM columns — single select dropdown ?>
                            <select name="fields[<?= $name ?>]" <?= $is_required ?>
                                class="w-full p-2 border bg-white rounded font-medium capitalize">
                                <?php if (!$is_required): ?>
                                    <option value="">-- None --</option>
                                <?php endif; ?>
                                <?php foreach ($enum_values as $ev): ?>
                                    <option value="<?= htmlspecialchars($ev, ENT_QUOTES) ?>"
                                        <?= $raw_val === $ev ? 'selected' : '' ?>>
                                        <?= htmlspecialchars(ucwords(str_replace('_', ' ', $ev))) ?>
                                    </option>
                                <?php endforeach; ?>
                            </select>
                        <?php endif; ?>
                    </div>

                <?php elseif ($name === 'breed_id'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Breed</label>
                        <select name="fields[breed_id]" required class="w-full p-2 border bg-white rounded">
                            <option value="">-- Select Breed --</option>
                            <?php foreach ($all_breeds as $b): ?>
                                <option value="<?= (int)$b['id'] ?>" <?= $raw_val == $b['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($b['breed_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif ($name === 'classification_id'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Classification</label>
                        <select name="fields[classification_id]" required class="w-full p-2 border bg-white rounded">
                            <option value="">-- Select Classification --</option>
                            <?php foreach ($all_classes as $c): ?>
                                <option value="<?= (int)$c['id'] ?>" <?= $raw_val == $c['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($c['class_name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif (strpos($name, 'unit_id') !== false || $name === 'size_unit_id'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <select name="fields[<?= $name ?>]" class="w-full p-2 border bg-white rounded">
                            <option value="">-- Choose Unit --</option>
                            <?php foreach ($all_units as $u): ?>
                                <option value="<?= (int)$u['id'] ?>" <?= $raw_val == $u['id'] ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['name']) ?>
                                </option>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif ($type === 'date'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <input type="date" name="fields[<?= $name ?>]" <?= $is_required ?>
                            value="<?= $val ?>"
                            class="w-full p-2 border bg-stone-50 rounded font-mono">
                    </div>

                <?php elseif ($type === 'datetime' || $type === 'timestamp'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <?php
                            // datetime-local needs YYYY-MM-DDTHH:MM format; DB stores YYYY-MM-DD HH:MM:SS
                            $dt_input_val = $val ? date('Y-m-d\TH:i', strtotime($val)) : '';
                        ?>
                        <input type="datetime-local"
                            name="fields[<?= htmlspecialchars($name, ENT_QUOTES) ?>]"
                            value="<?= htmlspecialchars($dt_input_val, ENT_QUOTES) ?>"
                            class="w-full p-2 border bg-stone-50 rounded font-mono">
                        <p class="text-[10px] text-stone-400 mt-0.5">
                            <?php if ($name === 'publish_at'): ?>
                            Leave blank to publish immediately. Set a future date/time to schedule.
                            <?php else: ?>
                            Date and time.
                            <?php endif; ?>
                        </p>
                    </div>

                <?php elseif ($name === 'conversion_factor'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">Conversion Factor</label>
                        <input type="number" step="any" min="0" name="fields[conversion_factor]" <?= $is_required ?>
                            value="<?= $val ?>"
                            class="w-full p-2 border bg-stone-50 font-mono rounded">
                        <p class="text-[10px] text-stone-400 leading-snug mt-1">
                            How many <strong>To</strong> units equal 1 <strong>From</strong> unit.<br>
                            e.g. cup → tablespoon: enter <code class="bg-stone-100 px-1 rounded font-mono">16</code> &nbsp;|&nbsp;
                            kg → g: enter <code class="bg-stone-100 px-1 rounded font-mono">1000</code> &nbsp;|&nbsp;
                            oz → g: enter <code class="bg-stone-100 px-1 rounded font-mono">28.35</code>
                        </p>
                    </div>

                <?php elseif (in_array($name, ['from_unit_id','to_unit_id']) && $target_table === 'unit_conversions'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">
                            <?= $name === 'from_unit_id' ? 'From Unit' : 'To Unit' ?>
                        </label>
                        <select name="fields[<?= $name ?>]"
                            class="w-full p-2 border bg-stone-50 rounded text-sm outline-none">
                            <option value="">— Select unit —</option>
                            <?php
                            $unit_groups = [];
                            foreach ($all_units ?? [] as $u) {
                                $sys = $u['system_type'] ?? 'Other';
                                $unit_groups[$sys][] = $u;
                            }
                            foreach ($unit_groups as $sys => $units):
                            ?>
                            <optgroup label="<?= htmlspecialchars($sys) ?>">
                                <?php foreach ($units as $u): ?>
                                <option value="<?= (int)$u['id'] ?>"
                                    <?= (isset($edit_data[$name]) && (int)$edit_data[$name] === (int)$u['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($u['name']) ?><?= !empty($u['abbreviation']) ? ' (' . htmlspecialchars($u['abbreviation']) . ')' : '' ?>
                                </option>
                                <?php endforeach; ?>
                            </optgroup>
                            <?php endforeach; ?>
                        </select>
                    </div>

                <?php elseif ($name === 'cuisine_id' && $target_table === 'recipes'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Cuisine</label>
                        <div class="flex gap-2">
                            <select name="fields[cuisine_id]"
                                class="flex-1 p-2 border bg-stone-50 rounded text-sm outline-none">
                                <option value="">— Select cuisine —</option>
                                <?php foreach ($all_cuisines ?? [] as $cu): ?>
                                <option value="<?= (int)$cu['id'] ?>"
                                    <?= (isset($edit_data['cuisine_id']) && (int)$edit_data['cuisine_id'] === (int)$cu['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($cu['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <button type="button"
                                onclick="openAddCuisineModal()"
                                class="px-3 py-2 border rounded bg-stone-50 hover:bg-stone-100 text-[10px] font-bold uppercase tracking-wider text-stone-600 whitespace-nowrap">
                                + Add
                            </button>
                        </div>
                    </div>

                <?php elseif ($name === 'pairs_with_recipe_id'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Pairs Well With Recipe</label>
                        <select name="fields[pairs_with_recipe_id]"
                            class="w-full p-2 border bg-stone-50 rounded text-sm outline-none">
                            <option value="">— None —</option>
                            <?php
                            try {
                                // Use simple query in case status column doesn't exist yet
                                $recipe_opts = $pdo->query("SELECT id, title FROM recipes ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
                            } catch (Throwable $e) {
                                $recipe_opts = [];
                            }
                            foreach ($recipe_opts as $ro) {
                                $sel = (isset($edit_data['pairs_with_recipe_id']) && (int)$edit_data['pairs_with_recipe_id'] === (int)$ro['id']) ? 'selected' : '';
                                echo '<option value="' . (int)$ro['id'] . '" ' . $sel . '>' . htmlspecialchars($ro['title']) . '</option>';
                            }
                            ?>
                        </select>
                        <p class="text-[10px] text-stone-400 mt-0.5">Optional — links this ingredient to a recipe it pairs well with.</p>
                    </div>

                <?php elseif ($name === 'category_id' && $target_table === 'inventory'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Category</label>
                        <div class="flex gap-2">
                            <select name="fields[category_id]"
                                class="flex-1 p-2 border bg-stone-50 rounded text-sm outline-none">
                                <option value="">— Uncategorized —</option>
                                <?php foreach ($all_inventory_categories ?? [] as $ic): ?>
                                <option value="<?= (int)$ic['id'] ?>"
                                    <?= (isset($edit_data['category_id']) && (int)$edit_data['category_id'] === (int)$ic['id']) ? 'selected' : '' ?>>
                                    <?= htmlspecialchars($ic['icon'] ? $ic['icon'] . ' ' . $ic['name'] : $ic['name']) ?>
                                </option>
                                <?php endforeach; ?>
                            </select>
                            <a href="/admin/admin.php?view=schema&table=inventory_categories"
                                class="px-3 py-2 border rounded bg-stone-50 hover:bg-stone-100 text-[10px] font-bold uppercase tracking-wider text-stone-600 whitespace-nowrap flex items-center">
                                Manage
                            </a>
                        </div>
                    </div>

                <?php elseif (strpos($type, 'decimal') !== false || strpos($type, 'int') !== false): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <input type="number" step="0.01" name="fields[<?= $name ?>]" <?= $is_required ?>
                            value="<?= $val ?>"
                            class="w-full p-2 border bg-stone-50 font-mono rounded">
                    </div>

                <?php elseif (in_array($name, [
                    'image','image_url','image_path','image_src',
                    'photo','photo_url','photo_path',
                    'thumbnail','thumbnail_url',
                    'avatar','avatar_url',
                    'banner','banner_url',
                    'logo','logo_url',
                    'cover','cover_url','cover_image',
                    'featured_image','hero_image','post_image','blog_image',
                    'profile_image','profile_photo',
                    'product_image','item_image','animal_image',
                ]) || (
                    // also catch any field ending in _image or _photo that isn't an alt/caption
                    (str_ends_with($name, '_image') || str_ends_with($name, '_photo') || str_ends_with($name, '_image_url'))
                    && !str_ends_with($name, '_alt') && !str_ends_with($name, '_text') && !str_ends_with($name, '_caption')
                )): ?>
                    <div class="space-y-2 col-span-2" id="imgWidget_<?= htmlspecialchars($name, ENT_QUOTES) ?>">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>

                        <!-- Current image preview -->
                        <?php if (!empty($val)): ?>
                        <div id="imgPreview_<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                            class="relative w-32 h-32 border rounded overflow-hidden bg-stone-100">
                            <img src="<?= htmlspecialchars($val, ENT_QUOTES) ?>" alt="Current image"
                                class="w-full h-full object-cover"
                                onerror="this.closest('div').style.display='none'">
                        </div>
                        <?php else: ?>
                        <div id="imgPreview_<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                            class="hidden w-32 h-32 border rounded overflow-hidden bg-stone-100">
                            <img alt="Preview" class="w-full h-full object-cover">
                        </div>
                        <?php endif; ?>

                        <!-- URL input + action buttons -->
                        <div class="flex gap-2 items-center">
                            <input type="text"
                                name="fields[<?= htmlspecialchars($name, ENT_QUOTES) ?>]"
                                id="imgUrl_<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                                value="<?= $val ?>"
                                class="flex-1 p-2 border bg-stone-50 rounded font-mono text-xs outline-none"
                                placeholder="/img/inventory/filename.webp"
                                oninput="updateImgPreview('<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
                            <button type="button"
                                onclick="openVaultPicker('<?= htmlspecialchars($name, ENT_QUOTES) ?>')"
                                class="shrink-0 border px-3 py-2 rounded text-xs font-mono font-bold uppercase tracking-wider bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap">
                                📁 Vault
                            </button>
                            <label class="shrink-0 border px-3 py-2 rounded text-xs font-mono font-bold uppercase tracking-wider bg-stone-50 hover:bg-stone-900 hover:text-white transition whitespace-nowrap cursor-pointer">
                                ⬆ Upload
                                <input type="file" accept="image/*" class="hidden"
                                    onchange="quickUploadImage(this, '<?= htmlspecialchars($name, ENT_QUOTES) ?>')">
                            </label>
                        </div>

                        <!-- Subfolder selector (shown during upload) -->
                        <div id="imgSubfolderRow_<?= htmlspecialchars($name, ENT_QUOTES) ?>" class="hidden">
                            <select id="imgSubfolder_<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                                class="text-xs border rounded p-1.5 bg-stone-50 font-mono">
                                <option value="inventory">inventory</option>
                                <option value="zoo">zoo</option>
                                <option value="stand">stand</option>
                                <option value="blog">blog</option>
                                <option value="thrift">thrift</option>
                            </select>
                        </div>

                        <!-- Upload progress -->
                        <div id="imgProgress_<?= htmlspecialchars($name, ENT_QUOTES) ?>"
                            class="hidden text-xs font-mono text-stone-500 animate-pulse">
                            Uploading...
                        </div>
                    </div>

                <?php elseif ($name === 'sku' && $target_table === 'inventory'): ?>
                    <div class="space-y-2">
                        <label class="block font-bold text-stone-600">SKU / Barcode</label>
                        <input type="text"
                            name="fields[sku]"
                            id="skuField_<?= (int)($edit_data['id'] ?? 0) ?>"
                            value="<?= htmlspecialchars($val, ENT_QUOTES) ?>"
                            placeholder="Auto-generated on save (e.g. BCF-001)"
                            oninput="updateBarcodePreview(this.value)"
                            class="w-full p-2 border bg-stone-50 rounded font-mono outline-none text-sm">
                        <p class="text-[10px] text-stone-400">Leave blank to auto-generate. SKU is used for barcode scanning at the POS.</p>
                        <div id="barcodePreviewWrap" class="<?= empty($val) ? 'hidden' : '' ?> bg-white border rounded p-3 text-center">
                            <svg id="barcodePreviewSvg"></svg>
                            <p class="text-[9px] text-stone-400 font-mono mt-1">Code 128 — scan preview</p>
                        </div>
                    </div>

                <?php elseif (in_array($name, ['password','password_hash','pass_hash','hashed_password'])): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <input type="password"
                            name="fields[<?= htmlspecialchars($name, ENT_QUOTES) ?>]"
                            autocomplete="new-password"
                            value=""
                            placeholder="Leave blank to keep current password"
                            class="w-full p-2 border bg-stone-50 rounded font-mono outline-none">
                        <p class="text-[10px] text-stone-400 mt-1">Leave blank to keep the existing password unchanged.</p>
                    </div>

                <?php elseif ($name === 'icon'): ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600">Icon</label>
                        <div class="flex gap-2 w-fit">
                            <input type="text"
                                name="fields[icon]"
                                id="iconField_<?= (int)($edit_data['id'] ?? 0) ?>"
                                value="<?= $val ?>"
                                maxlength="10"
                                class="w-16 p-2 border bg-stone-50 rounded font-mono text-xl outline-none text-center"
                                placeholder="📦"
                                autocomplete="off">
                            <button type="button"
                                data-icon-target="iconField_<?= (int)($edit_data['id'] ?? 0) ?>"
                                onclick="toggleEmojiPicker(this)"
                                class="emoji-trigger p-2 border rounded bg-white hover:bg-stone-100 text-xl transition select-none leading-none"
                                title="Pick an emoji">
                                <?= !empty($val) ? htmlspecialchars($val, ENT_QUOTES) : '😊' ?>
                            </button>
                        </div>
                        <div class="emoji-picker-wrap hidden mt-1"></div>
                    </div>

                <?php else: ?>
                    <div class="space-y-1">
                        <label class="block font-bold text-stone-600 capitalize">
                            <?= htmlspecialchars(str_replace('_', ' ', $name)) ?>
                        </label>
                        <input type="text" name="fields[<?= $name ?>]" <?= $is_required ?>
                            value="<?= $val ?>"
                            class="w-full p-2 border bg-stone-50 rounded">
                    </div>
                <?php endif; ?>

            <?php endforeach; ?>
            </div><!-- /schemaFieldGrid -->

            <!-- ── RELATION SUB-PANELS ── -->
            <?php if (in_array($target_table, ['fruits_vegetables', 'baked_goods', 'pantry'])): ?>
                <div class="border-t pt-4 mt-2">
                    <label class="block font-bold text-stone-700 mb-2 text-xs">Ingredients</label>
                    <div class="grid grid-cols-3 gap-2 p-3 border rounded bg-stone-50 max-h-32 overflow-y-auto">
                        <?php foreach ($all_ingredients as $ing): ?>
                        <label class="flex items-center space-x-2 text-xxs font-medium cursor-pointer">
                            <input type="checkbox" name="standalone_ingredients[]"
                                value="<?= (int)$ing['id'] ?>"
                                <?= in_array($ing['id'], $active_i_ids) ? 'checked' : '' ?>>
                            <span><?= htmlspecialchars($ing['singular_name']) ?></span>
                        </label>
                        <?php endforeach; ?>
                    </div>
                </div>

            <?php endif; ?>

            <?php
            // ── SOCIAL COMPOSER (recipes + blog) — approve caption at publish time ──────
            if (in_array($target_table, ['blog_posts', 'recipes'], true)):
                $__social_file = dirname(__DIR__, 2) . '/includes/social.php';
                if (file_exists($__social_file)) require_once $__social_file;
                $__social_type   = $target_table === 'recipes' ? 'recipe' : 'blog';
                $__already_shared = !empty($edit_data['social_shared_at']);
                $__can_compose    = !empty($edit_data) && function_exists('social_compose');
            ?>
                <?php if ($__already_shared): ?>
                    <div class="pt-4 border-t print:hidden text-[11px] text-stone-500">
                        📣 Already shared to Facebook &amp; Instagram on <?= htmlspecialchars(substr((string)$edit_data['social_shared_at'], 0, 16)) ?>.
                    </div>
                <?php elseif ($__can_compose): $__sc = social_compose($pdo, $__social_type, $edit_data); ?>
                    <div class="pt-4 border-t print:hidden">
                      <details class="bg-stone-50 border border-stone-200 rounded p-3" open>
                        <summary class="cursor-pointer text-[11px] font-black uppercase tracking-wider text-stone-600">
                          📣 Share to Facebook &amp; Instagram on publish
                        </summary>
                        <label class="flex items-center gap-2 mt-3 text-xs text-stone-700">
                          <input type="checkbox" name="social_share" value="1" checked class="rounded">
                          Post this when I click <strong>Publish</strong> (first time only)
                        </label>
                        <div class="grid md:grid-cols-2 gap-3 mt-3">
                          <div>
                            <div class="text-[9px] font-black uppercase tracking-wider text-blue-700 mb-1">Facebook caption</div>
                            <textarea name="social_fb" rows="5" class="w-full p-2 border rounded text-[11px] bg-white outline-none"><?= htmlspecialchars($__sc['fb']) ?></textarea>
                          </div>
                          <div>
                            <div class="text-[9px] font-black uppercase tracking-wider text-pink-700 mb-1">Instagram caption</div>
                            <textarea name="social_ig" rows="5" class="w-full p-2 border rounded text-[11px] bg-white outline-none"><?= htmlspecialchars($__sc['ig']) ?></textarea>
                          </div>
                        </div>
                        <div class="mt-2">
                          <div class="text-[9px] font-black uppercase tracking-wider text-sky-700 mb-1">Instagram hashtags</div>
                          <input type="text" name="social_tags" value="<?= htmlspecialchars($__sc['hashtags']) ?>"
                                 class="w-full p-2 border rounded text-[11px] bg-white outline-none">
                        </div>
                        <p class="text-[10px] text-stone-400 mt-2">
                          Edit freely — exactly this text is queued when you publish. Facebook shows the link's preview card, so it skips the hashtags.
                        </p>
                      </details>
                    </div>
                <?php else: ?>
                    <div class="pt-4 border-t print:hidden text-[11px] text-stone-500">
                        💡 Save as a draft first — then the Facebook/Instagram composer appears here to approve before you publish.
                    </div>
                <?php endif; ?>
            <?php endif; ?>

            <div class="pt-4 border-t flex justify-between items-center print:hidden">
                <?php if (in_array($target_table, ['blog_posts', 'recipes'], true)): ?>
                    <?php
                    $current_status = $edit_data['status'] ?? 'draft';
                    $status_label   = $current_status === 'published' ? '🟢 Published' : '🟡 Draft';
                    ?>
                    <span class="text-xxs font-mono text-stone-400 font-bold uppercase tracking-wider">
                        Current: <?= $status_label ?>
                    </span>
                    <div class="flex gap-2">
                        <?php if ($target_table === 'blog_posts'): ?>
                        <button type="button" onclick="openBlogPreview()"
                            class="border font-mono font-bold uppercase tracking-wider py-2 px-4 rounded text-stone-600 hover:bg-stone-100 transition text-xs">
                            👁 Preview
                        </button>
                        <?php endif; ?>
                        <!-- name="post_status" means the clicked button's value is submitted -->
                        <button type="submit" name="post_status" value="draft"
                            class="border-2 border-yellow-400 text-yellow-800 bg-yellow-50 font-mono font-bold uppercase tracking-wider py-2 px-5 rounded hover:bg-yellow-100 transition text-xs">
                            💾 Save Draft
                        </button>
                        <button type="submit" name="post_status" value="published"
                            class="bg-emerald-700 text-white font-mono font-bold uppercase tracking-wider py-2 px-5 rounded hover:bg-emerald-900 transition shadow-xs text-xs">
                            🚀 Publish
                        </button>
                    </div>
                <?php else: ?>
                    <span></span>
                    <button type="submit"
                        class="bg-stone-900 text-white font-mono font-bold uppercase tracking-wider py-2.5 px-6 rounded shadow-xs hover:bg-stone-800 transition">
                        Save Record →
                    </button>
                <?php endif; ?>
            </div>
        </form>
        <?php endif; ?>
    </div>
</div>

<!-- ── ADD CUISINE MODAL ── -->
<div id="addCuisineModal" style="display:none;"
    class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-xs w-full p-6 shadow-xl space-y-3">
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 tracking-wide">Add Cuisine</h4>
        <input type="text" id="newCuisineInput" placeholder="e.g. Korean"
            class="w-full p-2 border bg-stone-50 rounded text-sm outline-none font-mono"
            onkeydown="if(event.key==='Enter') submitNewCuisine()">
        <p id="cuisineModalMsg" class="text-red-600 text-xs font-mono min-h-[1rem]"></p>
        <div class="flex gap-2">
            <button onclick="submitNewCuisine()"
                class="flex-1 bg-stone-900 text-white py-2 rounded font-bold text-xs uppercase tracking-wider hover:bg-stone-700">
                Save
            </button>
            <button onclick="closeAddCuisineModal()"
                class="flex-1 border py-2 rounded text-stone-500 text-xs uppercase font-bold hover:bg-stone-50">
                Cancel
            </button>
        </div>
    </div>
</div>

<?php require_once __DIR__ . '/layout/vault_picker.php'; // shared Media Vault picker (modal + JS) ?>

<script>
    // ── STARTUP PROBE ─────────────────────────────────────────────────────────
    console.log('[BCRF] schema.php script block executing');
    window._bcrf_usda_ready = false;
    function filterRecordList(val) {
        const q = val.toLowerCase().trim();
        document.querySelectorAll('.record-row').forEach(row => {
            row.style.display = row.dataset.name.includes(q) ? '' : 'none';
        });
    }

    function sortRecordList(mode) {
        if (!mode) return;
        const list = document.getElementById('recordList');
        if (!list) return;
        const rows = Array.from(list.querySelectorAll('.record-row'));
        rows.sort((a, b) => {
            if (mode === 'name-asc')  return a.dataset.name.localeCompare(b.dataset.name);
            if (mode === 'name-desc') return b.dataset.name.localeCompare(a.dataset.name);
            const da = parseInt(a.dataset.date || '0', 10), db = parseInt(b.dataset.date || '0', 10);
            return mode === 'date-asc' ? da - db : db - da;
        });
        rows.forEach(r => list.appendChild(r));
    }

    // Fix 10: dirty form — warn before navigating away with unsaved changes
    const schemaForm = document.getElementById('schemaEditForm');
    if (schemaForm) {
        schemaForm.addEventListener('input', () => schemaForm.dataset.dirty = 'true');
        schemaForm.addEventListener('change', () => schemaForm.dataset.dirty = 'true');
        schemaForm.addEventListener('submit', () => schemaForm.dataset.dirty = 'false');

        window.addEventListener('beforeunload', e => {
            if (schemaForm.dataset.dirty === 'true') {
                e.preventDefault();
                e.returnValue = '';
            }
        });
    }

    // Fix 11: ingredient rows built with createElement instead of innerHTML
    // Options are seeded from PHP-encoded JSON arrays to avoid XSS via ingredient names
    let ingredientRowCount = <?= (int)($ri_idx ?? 0) ?>;

    const ingredientOptions = <?= json_encode(array_map(fn($i) => ['id' => $i['id'], 'label' => mb_convert_encoding($i['singular_name'], 'UTF-8', 'UTF-8')], $all_ingredients ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    // Combined ingredient+recipe options for the autocomplete (recipes tagged with a book icon)
    const RI_OPTIONS = <?= json_encode(array_merge(
        array_map(fn($id, $lbl) => ['ref' => 'ing:' . $id, 'label' => mb_convert_encoding($lbl, 'UTF-8', 'UTF-8')], array_keys($ri_ing_map ?? []), array_values($ri_ing_map ?? [])),
        array_map(fn($id, $lbl) => ['ref' => 'rec:' . $id, 'label' => '📖 ' . mb_convert_encoding($lbl, 'UTF-8', 'UTF-8')], array_keys($ri_rec_map ?? []), array_values($ri_rec_map ?? []))
    ), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;
    const unitOptions       = <?= json_encode(array_map(fn($u) => ['id' => $u['id'], 'label' => mb_convert_encoding($u['name'], 'UTF-8', 'UTF-8')], $all_units ?? []), JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE) ?>;

    function buildSelect(name, options) {
        const sel = document.createElement('select');
        sel.name      = name;
        sel.className = 'p-1 border bg-white rounded flex-1';
        options.forEach(opt => {
            const o = document.createElement('option');
            o.value       = opt.id;
            o.textContent = opt.label; // textContent is XSS-safe
            sel.appendChild(o);
        });
        return sel;
    }

    // ── ADD NEW CATEGORY (shared by recipes and blog_posts) ─────────────────────
    const ADD_CAT_CSRF = <?= json_encode($_SESSION['csrf_token']) ?>;

    function toggleAddCategoryForm(context) {
        const form = document.getElementById('addCategoryForm_' + context);
        form.classList.toggle('hidden');
        if (!form.classList.contains('hidden')) {
            document.getElementById('newCategoryInput_' + context).focus();
        }
    }

    async function submitNewCategory(context) {
        const input  = document.getElementById('newCategoryInput_' + context);
        const name   = input.value.trim();
        if (!name) return;

        try {
            const res  = await fetch('/admin/handlers/add_category.php', {
                method: 'POST',
                headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
                body: new URLSearchParams({ csrf_token: ADD_CAT_CSRF, category_name: name, context: context }),
            });

            // Read body as text first so we can diagnose non-JSON responses
            const rawText = await res.text();
            let data;
            try {
                data = JSON.parse(rawText);
            } catch (parseErr) {
                // Server returned non-JSON — show first 200 chars to diagnose
                console.error('add_category non-JSON response:', rawText.substring(0, 200));
                sysAlert('Server error saving category. Check the browser console for details.', 'Server Error', '⚠️');
                return;
            }

            if (data.ok) {
                // Append new checkbox to both lists (it might be open on either context)
                ['recipes', 'blog'].forEach(ctx => {
                    const list = document.getElementById('categoriesCheckboxList_' + ctx);
                    if (!list) return;
                    const label = document.createElement('label');
                    label.className = 'flex items-center space-x-2 text-xxs font-medium cursor-pointer';
                    const cb = document.createElement('input');
                    cb.type    = 'checkbox';
                    cb.name    = 'categories_matrix[]';
                    cb.value   = data.id;
                    cb.checked = true;
                    const span = document.createElement('span');
                    span.textContent = data.name;
                    label.append(cb, span);
                    list.appendChild(label);
                });

                input.value = '';
                toggleAddCategoryForm(context);
            } else {
                sysAlert('Could not save category: ' + (data.message || 'Unknown error'), 'Save Failed', '⚠️');
            }
        } catch (err) {
            console.error('add_category fetch error:', err);
            sysAlert('Network error saving category: ' + err.message, 'Network Error', '⚠️');
        }
    }

    // ── INGREDIENT / SUB-RECIPE AUTOCOMPLETE ─────────────────────────────────
function riFilter(q){
    q = (q||'').trim().toLowerCase();
    const out = [];
    for (const o of RI_OPTIONS){
        if (!q || o.label.toLowerCase().includes(q)){ out.push(o); if(out.length>=60) break; }
    }
    return out;
}
function initRiCombo(combo){
    if (combo.dataset.riReady === '1') return;
    combo.dataset.riReady = '1';
    const hidden = combo.querySelector('input[type=hidden]');
    const search = combo.querySelector('.ri-search');
    const dd     = combo.querySelector('.ri-dropdown');
    let items = [], active = -1;
    function close(){ dd.style.display='none'; active=-1; }
    function render(q){
        items = riFilter(q); active = -1; dd.innerHTML='';
        if(!items.length){ const e=document.createElement('div'); e.className='ri-empty'; e.textContent='No match — pick from the list'; dd.appendChild(e); return; }
        items.forEach((o)=>{
            const d=document.createElement('div');
            d.className='ri-item'+(o.ref.indexOf('rec:')===0?' ri-item-recipe':'');
            d.textContent=o.label;
            d.addEventListener('mousedown', ev=>{ ev.preventDefault(); pick(o); });
            dd.appendChild(d);
        });
    }
    function open(){ render(search.value); dd.style.display='block'; }
    function pick(o){ hidden.value=o.ref; search.value=o.label; close(); }
    function hi(){ [...dd.children].forEach((c,i)=>c.classList.toggle('ri-active',i===active)); }
    search.addEventListener('focus', open);
    search.addEventListener('input', ()=>{ hidden.value=''; open(); });
    search.addEventListener('keydown', e=>{
        if(dd.style.display==='none') return;
        if(e.key==='ArrowDown'){ e.preventDefault(); active=Math.min(active+1,items.length-1); hi(); }
        else if(e.key==='ArrowUp'){ e.preventDefault(); active=Math.max(active-1,0); hi(); }
        else if(e.key==='Enter'){ if(active>=0&&items[active]){ e.preventDefault(); pick(items[active]); } }
        else if(e.key==='Escape'){ close(); }
    });
    search.addEventListener('blur', ()=>{ setTimeout(()=>{ close(); if(!hidden.value) search.value=''; }, 160); });
}
function makeRiCombo(idx, ref, label){
    const wrap=document.createElement('div'); wrap.className='ri-combo relative flex-1';
    const h=document.createElement('input'); h.type='hidden'; h.name=`recipe_ingredients_block[${idx}][ref]`; h.value=ref||'';
    const s=document.createElement('input'); s.type='text'; s.className='ri-search p-1 border bg-white rounded w-full'; s.autocomplete='off'; s.placeholder='Type an ingredient or recipe…'; s.value=label||'';
    const d=document.createElement('div'); d.className='ri-dropdown'; d.style.display='none';
    wrap.append(h,s,d);
    return wrap;
}
(function(){ const go=()=>document.querySelectorAll('.ri-combo').forEach(initRiCombo); if(document.readyState==='loading') document.addEventListener('DOMContentLoaded',go); else go(); })();

    function addNewIngredientRow() {
        const container = document.getElementById('ingredientsDynamicWrapper');
        if (!container) return;

        const rowNum  = container.querySelectorAll('.dynamic-ingredient-row').length + 1;
        const n       = ingredientRowCount;

        const row = document.createElement('div');
        row.className = 'dynamic-ingredient-row text-xs space-y-1.5 pb-2 border-b border-stone-200';

        // Top line: row number + ingredient + qty + unit + remove
        const topLine = document.createElement('div');
        topLine.className = 'flex items-center space-x-2';

        const numLabel = document.createElement('span');
        numLabel.className   = 'text-[10px] font-black text-stone-400 w-5 shrink-0 text-right';
        numLabel.textContent = rowNum + '.';

        const ingSelect = makeRiCombo(n, '', '');

        const qtyInput = document.createElement('input');
        qtyInput.type        = 'text';
        qtyInput.inputMode   = 'decimal';
        qtyInput.name        = `recipe_ingredients_block[${n}][quantity]`;
        qtyInput.placeholder = 'e.g. 1/4';
        qtyInput.className   = 'w-20 p-1 border bg-white rounded font-mono text-center';
        qtyInput.addEventListener('blur', function() {
            this.value = parseFractionJS(this.value);
        });

        const unitSelect = buildSelect(`recipe_ingredients_block[${n}][unit_id]`, unitOptions);
        unitSelect.className = 'p-1 border bg-white rounded w-28';

        const removeBtn = document.createElement('button');
        removeBtn.type        = 'button';
        removeBtn.textContent = '✕';
        removeBtn.className   = 'text-red-600 font-bold px-1 hover:text-red-900 shrink-0';
        removeBtn.onclick     = () => row.remove();

        topLine.append(numLabel, ingSelect, qtyInput, unitSelect, removeBtn);

        // Notes line — group heading + preparation note
        const notesLine = document.createElement('div');
        notesLine.className = 'flex items-center space-x-2 pl-7';

        // Group input inherits the last non-empty group above it
        const groups = container.querySelectorAll('.ri-group');
        let inherited = '';
        for (let i = groups.length - 1; i >= 0; i--) {
            if (groups[i].value.trim() !== '') { inherited = groups[i].value.trim(); break; }
        }

        const groupInput = document.createElement('input');
        groupInput.type        = 'text';
        groupInput.maxLength   = 80;
        groupInput.name        = `recipe_ingredients_block[${n}][ingredient_group]`;
        groupInput.value       = inherited;
        groupInput.placeholder = 'Group heading e.g. Pie Crust (optional)';
        groupInput.className   = 'ri-group w-44 shrink-0 p-1 border bg-amber-50 border-amber-200 rounded text-[11px] font-semibold text-amber-900';

        const notesInput = document.createElement('input');
        notesInput.type        = 'text';
        notesInput.maxLength   = 120;
        notesInput.name        = `recipe_ingredients_block[${n}][notes]`;
        notesInput.placeholder = 'Preparation note e.g. finely chopped (optional)';
        notesInput.className   = 'w-full p-1 border bg-white rounded text-stone-500 italic text-[11px]';
        notesLine.append(groupInput, notesInput);

        row.append(topLine, notesLine);
        container.appendChild(row);
        initRiCombo(ingSelect);
        ingredientRowCount++;
    }

    // ── BLOG POST PREVIEW ────────────────────────────────────────────────────────
    function openBlogPreview() {
        const titleInput = document.querySelector('[name="fields[title]"]');
        const title = titleInput ? titleInput.value.trim() : '(No title)';

        let bodyHtml = '';
        for (const name of ['content', 'article', 'body', 'post_content', 'description']) {
            const hidden = document.getElementById('hidden_' + name);
            if (hidden && hidden.value.trim() && hidden.value.trim() !== '<p><br></p>') {
                bodyHtml = hidden.value;
                break;
            }
        }
        if (!bodyHtml) bodyHtml = '<p style="color:#999;font-style:italic;">No content written yet.</p>';

        const safeTitle = title.replace(/&/g,'&amp;').replace(/</g,'&lt;').replace(/>/g,'&gt;');
        const preview = `<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <meta name="viewport" content="width=device-width, initial-scale=1">
    <title>Preview: ${safeTitle}</title>
    <link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&family=Playfair+Display:wght@700&display=swap" rel="stylesheet">
    <style>
        * { box-sizing: border-box; }
        body { margin: 0; padding: 0; background: #e8d5b0; font-family: Lora, Georgia, serif; color: #3b2010; }
        .preview-bar { background: #3b2010; padding: 12px 24px; display: flex; align-items: center; gap: 12px; }
        .preview-badge { font-size: 10px; font-family: monospace; font-weight: bold; text-transform: uppercase;
            letter-spacing: .1em; background: #e8b84b; color: #3b2010; border-radius: 4px; padding: 3px 8px; }
        .preview-note { font-size: 11px; color: rgba(245,234,214,.5); font-family: monospace; }
        .article-wrap { max-width: 760px; margin: 36px auto; padding: 0 24px 60px; }
        h1.post-title { font-family: 'Playfair Display', Georgia, serif; font-size: clamp(1.6rem,4vw,2.4rem);
            color: #3b2010; line-height: 1.25; margin: 0 0 8px; }
        .post-meta { font-size: .8rem; color: #8b5c2a; margin-bottom: 28px; padding-bottom: 16px;
            border-bottom: 2px solid #c9923a; font-family: monospace; }
        .post-body { font-size: 1.05rem; line-height: 1.85; }
        .post-body h1,.post-body h2,.post-body h3 { font-family: 'Playfair Display', Georgia, serif; }
        .post-body a { color: #4a6741; }
        .post-body img { max-width: 100%; border-radius: 8px; border: 2px solid #c9923a; }
        .post-body blockquote { border-left: 4px solid #c9923a; margin: 20px 0; padding: 12px 20px;
            background: #f5ead6; border-radius: 0 6px 6px 0; font-style: italic; color: #5c3317; }
    </style>
</head>
<body>
    <div class="preview-bar">
        <span class="preview-badge">Preview</span>
        <span class="preview-note">Draft preview — not the live page</span>
    </div>
    <div class="article-wrap">
        <h1 class="post-title">${safeTitle}</h1>
        <div class="post-meta">Bat Cave Road Farm &bull; Journal</div>
        <div class="post-body">${bodyHtml}</div>
    </div>
</body>
</html>`;

        document.getElementById('blogPreviewTitle').textContent = title || '(No title)';
        document.getElementById('blogPreviewFrame').srcdoc      = preview;
        document.getElementById('blogPreviewModal').classList.remove('hidden');
        document.body.style.overflow = 'hidden';
    }

    function closeBlogPreview() {
        document.getElementById('blogPreviewModal').classList.add('hidden');
        document.getElementById('blogPreviewFrame').srcdoc = '';
        document.body.style.overflow = '';
    }

    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape' && !document.getElementById('blogPreviewModal')?.classList.contains('hidden')) {
            closeBlogPreview();
        }
    });

    document.getElementById('blogPreviewModal')?.addEventListener('click', function(e) {
        if (e.target === this) closeBlogPreview();
    });


// ── USDA INGREDIENT NUTRITION LOOKUP ─────────────────────────────────────────
async function lookupUSDA(ingId) {
    console.log('[USDA] lookupUSDA called, ingId:', ingId);
    const btn          = document.getElementById('usdaLookupBtn_' + ingId);
    const resultsPanel = document.getElementById('usdaResultsPanel_' + ingId);
    const resultsList  = document.getElementById('usdaResultsList_'  + ingId);
    const saveMsg      = document.getElementById('usdaSaveMsg_' + ingId);

    // Read ingredient name directly from the form field — no encoding issues
    const nameInput = document.querySelector('input[name="fields[singular_name]"]');
    const ingName   = nameInput ? nameInput.value.trim() : '';

    if (!ingName) {
        if (saveMsg) {
            saveMsg.textContent = '⚠️ Enter a singular name for this ingredient first.';
            saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-amber-50 text-amber-700 border border-amber-200';
            saveMsg.classList.remove('hidden');
        }
        return;
    }

    if (btn) { btn.disabled = true; btn.textContent = '⏳ Searching…'; }
    if (saveMsg) saveMsg.className = 'hidden';

    try {
        const res  = await fetch('/admin/handlers/lookup_ingredient_nutrition.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>',
                action:     'search',
                query:      ingName
            })
        });
        const data = await res.json();

        if (!data.success) {
            if (saveMsg) { saveMsg.textContent = '⚠️ ' + data.message; saveMsg.className = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200'; }
            return;
        }

        // Render results
        resultsList.innerHTML = '';
        data.results.forEach(food => {
            const row = document.createElement('div');
            row.className = 'flex items-start justify-between gap-2 bg-white border rounded p-2 text-[10px]';

            const info = document.createElement('div');
            const cals = food.nutrients.calories_per_100g;
            info.innerHTML = `<span class="font-bold text-stone-800">${food.description}</span>
                <span class="ml-1 text-stone-400">${food.data_type}</span>
                ${cals !== null ? `<span class="ml-2 font-mono text-emerald-700">${cals} kcal/100g</span>` : ''}`;

            const useBtn = document.createElement('button');
            useBtn.type      = 'button';
            useBtn.textContent = 'Use this';
            useBtn.className = 'shrink-0 bg-blue-700 text-white px-2 py-0.5 rounded font-bold hover:bg-blue-900 transition';
            useBtn.onclick   = () => applyUSDAResult(ingId, food);

            row.append(info, useBtn);
            resultsList.appendChild(row);
        });

        resultsPanel.classList.remove('hidden');
    } catch (err) {
        if (saveMsg) { saveMsg.textContent = '⚠️ Network error — try again.'; saveMsg.className = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200'; }
    } finally {
        if (btn) { btn.disabled = false; btn.innerHTML = '🔍 Lookup via USDA'; }
    }
}

function applyUSDAResult(ingId, food) {
    const n  = food.nutrients;
    const fields = {
        'calories_per_100g': n.calories_per_100g,
        'protein_per_100g':  n.protein_per_100g,
        'fat_per_100g':      n.fat_per_100g,
        'carbs_per_100g':    n.carbs_per_100g,
        'fiber_per_100g':    n.fiber_per_100g,
        'sugar_per_100g':    n.sugar_per_100g,
        'sodium_per_100g':   n.sodium_per_100g,
    };
    // Fill inputs
    Object.entries(fields).forEach(([key, val]) => {
        const el = document.getElementById('ing_' + key + '_' + ingId);
        if (el && val !== null) el.value = val;
    });
    // Fill hidden fdc_id field if present
    const fdcField = document.querySelector('input[name="fields[usda_fdc_id]"]');
    if (fdcField) fdcField.value = food.fdc_id;

    // Hide results panel
    document.getElementById('usdaResultsPanel_' + ingId)?.classList.add('hidden');

    const saveMsg = document.getElementById('usdaSaveMsg_' + ingId);
    if (saveMsg) {
        saveMsg.textContent = `✅ Filled from USDA: "${food.description}" — click Save Record to store.`;
        saveMsg.className   = 'mx-4 mb-3 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';
    }
}

// ── NUTRITION CALCULATOR ──────────────────────────────────────────────────────
async function calculateNutrition(recipeId) {
    const btn      = document.getElementById('calcNutrBtn');
    const icon     = document.getElementById('calcNutrBtnIcon');
    const label    = document.getElementById('calcNutrBtnLabel');
    const msg      = document.getElementById('nutritionMsg');
    const grid     = document.getElementById('nutritionDataGrid');
    const emptyMsg = document.getElementById('nutritionEmptyMsg');

    // Loading state
    btn.disabled  = true;
    icon.textContent  = '⏳';
    label.textContent = 'Calculating…';
    msg.className = 'hidden';

    try {
        const res  = await fetch('/admin/handlers/calculate_nutrition.php', {
            method: 'POST',
            headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
            body: new URLSearchParams({
                csrf_token: '<?= htmlspecialchars($_SESSION['csrf_token'] ?? '', ENT_QUOTES) ?>',
                recipe_id:  recipeId
            })
        });
        const data = await res.json();

        if (data.success) {
            const d = data;
            // Update display
            document.getElementById('nutrCalories').textContent = d.calories;
            document.getElementById('nutrProtein').textContent  = d.protein  + 'g';
            document.getElementById('nutrFat').textContent      = d.fat      + 'g';
            document.getElementById('nutrCarbs').textContent    = d.carbs    + 'g';
            document.getElementById('nutrFiber').textContent    = d.fiber    + 'g';
            document.getElementById('nutrSugar').textContent    = d.sugar    + 'g';
            document.getElementById('nutrSodium').textContent   = d.sodium   + 'mg';

            if (grid)     grid.classList.remove('hidden');
            if (emptyMsg) emptyMsg.classList.add('hidden');

            msg.textContent = '✅ ' + data.message;
            msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-emerald-50 text-emerald-800 border border-emerald-200';

            icon.textContent  = '✅';
            label.textContent = 'Recalculate';
        } else {
            msg.textContent = '⚠️ ' + data.message;
            msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
            icon.textContent  = '⚡';
            label.textContent = 'Try Again';
        }
    } catch (err) {
        msg.textContent = '⚠️ Network error — please try again.';
        msg.className   = 'mt-2 p-2 rounded text-xs font-mono bg-red-50 text-red-700 border border-red-200';
        icon.textContent  = '⚡';
        label.textContent = 'Try Again';
    }

    btn.disabled = false;
    msg.classList.remove('hidden');
    msg.scrollIntoView({ behavior: 'smooth', block: 'nearest' });
}

    console.log('[BCRF] lookupUSDA defined:', typeof lookupUSDA);
    window._bcrf_usda_ready = true;

// ── FRACTION INPUT HELPERS ────────────────────────────────────────────────────
function parseFractionJS(val) {
    val = (val + '').trim();
    if (!val) return '';

    // Unicode fractions
    const u = {'¼':0.25,'½':0.5,'¾':0.75,'⅓':0.3333,'⅔':0.6667,
               '⅛':0.125,'⅜':0.375,'⅝':0.625,'⅞':0.875};
    if (u[val] !== undefined) return u[val];

    // Whole + unicode: "1¾"
    const mu = val.match(/^(\d+)([¼½¾⅓⅔⅛⅜⅝⅞])$/);
    if (mu) return (parseFloat(mu[1]) + (u[mu[2]] || 0)).toString();

    // Mixed number: "1 1/4" or "1-1/4"
    const mm = val.match(/^(\d+)[\s\-]+(\d+)\s*\/\s*(\d+)$/);
    if (mm) {
        const denom = parseFloat(mm[3]);
        if (!denom) return mm[1];
        const result = parseFloat(mm[1]) + parseFloat(mm[2]) / denom;
        return formatDecimal(result);
    }

    // Simple fraction: "1/4"
    const mf = val.match(/^(\d+)\s*\/\s*(\d+)$/);
    if (mf) {
        const denom = parseFloat(mf[2]);
        if (!denom) return '0';
        return formatDecimal(parseFloat(mf[1]) / denom);
    }

    // Already a number — clean it up
    const n = parseFloat(val);
    return isNaN(n) ? val : formatDecimal(n);
}

function formatDecimal(n) {
    // Show up to 4 decimal places, strip trailing zeros
    return parseFloat(n.toFixed(4)).toString();
}

// ── EMOJI PICKER ─────────────────────────────────────────────────────────────
var _emojiPickerLoaded = false;
var _activePickerWrap  = null;

async function toggleEmojiPicker(btn) {

    const wrap      = btn.closest('.space-y-1').querySelector('.emoji-picker-wrap');
    const targetId  = btn.dataset.iconTarget;
    const targetInput = document.getElementById(targetId);

    // Close if already open for this button
    if (_activePickerWrap === wrap) {
        wrap.classList.add('hidden');
        _activePickerWrap = null;
        return;
    }

    // Close any other open picker
    if (_activePickerWrap) {
        _activePickerWrap.classList.add('hidden');
        _activePickerWrap = null;
    }

    // Lazy-load emoji-picker-element (ES module from CDN)
    if (!_emojiPickerLoaded) {
        await import('https://cdn.jsdelivr.net/npm/emoji-picker-element@1/index.js');
        _emojiPickerLoaded = true;
    }

    // Build picker if not already in this wrap
    if (!wrap.querySelector('emoji-picker')) {
        const picker = document.createElement('emoji-picker');
        picker.addEventListener('emoji-click', e => {
            if (targetInput) targetInput.value = e.detail.unicode;
            // Update the trigger button to show the chosen emoji
            btn.textContent = e.detail.unicode;
            wrap.classList.add('hidden');
            _activePickerWrap = null;
        });
        wrap.appendChild(picker);
    }

    wrap.classList.remove('hidden');
    _activePickerWrap = wrap;

    // Focus the search inside the picker after a tick
    setTimeout(() => {
        wrap.querySelector('emoji-picker')?.shadowRoot?.querySelector('input[type=search]')?.focus();
    }, 80);
}

// Close picker when clicking outside
document.addEventListener('click', function(e) {
    if (!_activePickerWrap) return;
    if (!e.target.closest('.emoji-picker-wrap') && !e.target.closest('.emoji-trigger')) {
        _activePickerWrap.classList.add('hidden');
        _activePickerWrap = null;
    }
}, true);

// ── EMOJI PICKER STYLES ───────────────────────────────────────────────────────
(function() {
    const s = document.createElement('style');
    s.textContent = `
        .emoji-picker-wrap emoji-picker {
            --background:                #fff;
            --border-color:              #e7e5e4;
            --button-active-background:  #f5f5f4;
            --button-hover-background:   #f5f5f4;
            --emoji-padding:             .3rem;
            --emoji-size:                1.35rem;
            --indicator-color:           #1c1917;
            --input-border-color:        #d6d3d1;
            --input-font-color:          #1c1917;
            --input-placeholder-color:   #a8a29e;
            --input-font-size:           .8rem;
            --num-columns:               8;
            --outline-color:             #78716c;
            border-radius: .5rem;
            box-shadow: 0 10px 30px rgba(0,0,0,.12);
            width: 100%;
            max-width: 340px;
        }
    `;
    document.head.appendChild(s);
})();


// Delete confirm helper (can't await inside inline onclick attrs)
function sysDeleteConfirm(name, triggerEl) {
    sysConfirm(
        'Permanently delete <strong>' + name + '</strong>?<br><br>This cannot be undone.',
        'Delete Record', '🗑️', 'Delete', true
    ).then(function(confirmed) {
        if (confirmed) window.location.href = triggerEl.href;
    });
    return false;
}

// ── ADD CUISINE MODAL ─────────────────────────────────────────────────────────
function openAddCuisineModal() {
    document.getElementById('addCuisineModal').style.display = 'flex';
    document.getElementById('newCuisineInput').value = '';
    document.getElementById('newCuisineInput').focus();
    document.getElementById('cuisineModalMsg').textContent = '';
}
function closeAddCuisineModal() {
    document.getElementById('addCuisineModal').style.display = 'none';
}
async function submitNewCuisine() {
    const input = document.getElementById('newCuisineInput');
    const msg   = document.getElementById('cuisineModalMsg');
    const name  = input.value.trim();
    if (!name) { msg.textContent = 'Please enter a cuisine name.'; return; }

    const res  = await fetch('/admin/handlers/add_cuisine.php', {
        method: 'POST',
        headers: { 'Content-Type': 'application/x-www-form-urlencoded' },
        body: new URLSearchParams({ csrf_token: '<?= $_SESSION['csrf_token'] ?>', name: name })
    });
    const data = await res.json();
    if (data.success) {
        // Add to dropdown and select it
        const sel = document.querySelector('select[name="fields[cuisine_id]"]');
        if (sel) {
            const opt = document.createElement('option');
            opt.value    = data.id;
            opt.textContent = data.name;
            opt.selected = true;
            sel.appendChild(opt);
        }
        closeAddCuisineModal();
    } else {
        msg.textContent = data.message || 'Error saving cuisine.';
    }
}

// ── BARCODE PREVIEW (SKU field on inventory table) ────────────────────────────
function updateBarcodePreview(val) {
    const wrap = document.getElementById('barcodePreviewWrap');
    const svg  = document.getElementById('barcodePreviewSvg');
    if (!wrap || !svg) return;
    const v = (val || '').trim();
    if (!v) { wrap.classList.add('hidden'); return; }
    wrap.classList.remove('hidden');
    if (typeof JsBarcode === 'undefined') {
        // Load JsBarcode on first use
        const s = document.createElement('script');
        s.src = 'https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js';
        s.onload = () => renderBarcode(v);
        document.head.appendChild(s);
    } else {
        renderBarcode(v);
    }
}
function renderBarcode(val) {
    try {
        JsBarcode('#barcodePreviewSvg', val, {
            format:      'CODE128',
            lineColor:   '#000',
            width:       1.8,
            height:      50,
            displayValue: true,
            fontSize:    12,
            margin:      6,
            background:  '#ffffff'
        });
    } catch(e) {
        document.getElementById('barcodePreviewSvg').innerHTML =
            '<text fill="red" font-size="11">Invalid barcode value</text>';
    }
}

// Auto-render on page load if SKU field already has a value
document.addEventListener('DOMContentLoaded', function() {
    const skuField = document.querySelector('[id^="skuField_"]');
    if (skuField && skuField.value) updateBarcodePreview(skuField.value);

    // Delegated listener for USDA lookup button — avoids any inline onclick issues
    document.addEventListener('click', function(e) {
        const usdaBtn = e.target.closest('.usda-lookup-trigger');
        if (usdaBtn) {
            e.preventDefault();
            const ingId = parseInt(usdaBtn.dataset.ingId, 10);
            if (ingId) lookupUSDA(ingId);
        }
    });
});
</script>
