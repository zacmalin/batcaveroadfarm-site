<?php
// admin/handlers/schema_record.php
// Handles INSERT and UPDATE operations for schema table records.

// ── AUDIT LOG HELPER (shared) ───────────────────────────────────────────────
require_once __DIR__ . '/../includes/audit.php';

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired. Please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=schema');
        exit;
    }

    // Whitelist $target_table against known system tables
    if (!in_array($target_table, $system_tables)) {
        throw new RuntimeException("Unknown table: " . htmlspecialchars($target_table));
    }

    $fields = $_POST['fields'] ?? [];
    $id     = filter_input(INPUT_POST, 'id', FILTER_VALIDATE_INT);

    if (empty($fields)) {
        header("Location: /admin/admin.php?view=dashboard");
        exit;
    }

    // Whitelist field names against actual table columns — prevents mass-assignment.
    // Keep the full column metadata (type, nullability) for type-aware normalization.
    $col_meta = [];
    foreach ($pdo->query("DESCRIBE `{$target_table}`")->fetchAll(PDO::FETCH_ASSOC) as $__c) {
        $col_meta[$__c['Field']] = $__c;
    }
    $allowed_cols = array_keys($col_meta);
    $fields       = array_intersect_key($fields, array_flip($allowed_cols));

    if (empty($fields)) {
        throw new RuntimeException("No valid fields submitted.");
    }

    // Auto-generate slugs
    if (!function_exists('createSlug')) {
        function createSlug(string $str): string {
            return strtolower(trim(preg_replace('/[^a-zA-Z0-9]+/', '-', $str), '-'));
        }
    }
    if (isset($fields['breed_name']))                           $fields['slug'] = createSlug($fields['breed_name']);
    if (isset($fields['name']) && !in_array($target_table, ['animals', 'inventory', 'ingredients', 'measurement_units', 'categories'])) $fields['slug'] = createSlug($fields['name']);

    // ── INVENTORY: Auto-generate SKU if blank ────────────────────────────────
    if ($target_table === 'inventory' && empty($fields['sku'])) {
        try {
            $max_n = $pdo->query("SELECT MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)) FROM inventory WHERE sku LIKE 'BCF-%'")->fetchColumn();
            $fields['sku'] = 'BCF-' . str_pad((int)$max_n + 1, 3, '0', STR_PAD_LEFT);
        } catch (Throwable $sku_e) {
            error_log('[ERP] SKU generation failed: ' . $sku_e->getMessage());
        }
    }
    if (isset($fields['title']))                                $fields['slug'] = createSlug($fields['title']);

    // Normalize boolean flag columns
    $boolean_tables = ['breeds', 'animals', 'fruits_vegetables', 'baked_goods', 'pantry', 'thrift_finds', 'petting_zoo_products', 'inventory'];
    if (in_array($target_table, $boolean_tables)) {
        $fields['is_active'] = isset($fields['is_active']) ? 1 : 0;
    }

    // ── NULLABLE INT FIELDS — convert empty string to NULL before hitting DB ────
    // Handles any optional FK fields submitted as "" from a select's blank option
    $nullable_int_fields = ['linked_recipe_id', 'category_id', 'from_unit_id', 'to_unit_id', 'cuisine_id', 'calories', 'usda_fdc_id', 'servings', 'pairs_with_recipe_id'];
    foreach ($nullable_int_fields as $nif) {
        if (isset($fields[$nif])) {
            $v = trim((string)$fields[$nif]);
            $fields[$nif] = ($v === '') ? null : (int)$v;
        }
    }

    // ── NULLABLE DECIMAL FIELDS — nutrition per-100g values ───────────────────
    $nullable_decimal_fields = ['calories_per_100g','protein_per_100g','fat_per_100g',
                                'carbs_per_100g','fiber_per_100g','sugar_per_100g',
                                'sodium_per_100g','density_g_per_ml','weight_per_unit_g',
                                'protein_g','fat_g','carbs_g','fiber_g','sugar_g','sodium_mg'];
    foreach ($nullable_decimal_fields as $ndf) {
        if (isset($fields[$ndf])) {
            $v = trim((string)$fields[$ndf]);
            $fields[$ndf] = ($v === '') ? null : (float)$v;
        }
    }

    // ── NORMALIZE datetime-local inputs ──────────────────────────────────────────
    // HTML datetime-local submits "YYYY-MM-DDTHH:MM"; MySQL needs "YYYY-MM-DD HH:MM:SS"
    $nullable_dt_fields = ['publish_at', 'newsletter_sent_at']; // blank input → NULL (MySQL rejects '' for DATETIME)
    foreach ($fields as $_dk => $_dv) {
        if (is_string($_dv) && preg_match('/^\d{4}-\d{2}-\d{2}T\d{2}:\d{2}$/', $_dv)) {
            $fields[$_dk] = str_replace('T', ' ', $_dv) . ':00';
        } elseif ($_dv === '' && in_array($_dk, $nullable_dt_fields, true)) {
            $fields[$_dk] = null; // blank = clear it (publish_at: no schedule; newsletter_sent_at: re-queue)
        }
    }

    // ── SKIP BLANK PASSWORD FIELDS — never overwrite with empty string ───────────
    foreach (['password','password_hash','pass_hash','hashed_password'] as $_pw) {
        if (array_key_exists($_pw, $fields) && $fields[$_pw] === '') {
            unset($fields[$_pw]);
        }
    }

    // ── GENERIC EMPTY-VALUE NORMALIZATION (type-aware) ───────────────────────────
    // Strict SQL mode rejects '' for INT/DECIMAL/DATE/DATETIME/etc. columns. For ANY
    // table (this is what lets optional fields be left blank without a SQL error):
    //   • blank value on a NULLABLE column           → NULL
    //   • blank value on a NOT NULL numeric/temporal  → drop it, so the column's
    //     default applies (and on UPDATE the column is simply left unchanged)
    //   • blank value on a NOT NULL string column     → keep '' (a valid value)
    // Fields already converted to int/float/null by the specific blocks above are
    // non-strings and are skipped here.
    $__typed_non_string = function (string $type): bool {
        return (bool)preg_match('/^(tinyint|smallint|mediumint|int|bigint|decimal|numeric|float|double|real|bit|date|datetime|timestamp|time|year)/i', $type);
    };
    foreach ($fields as $__col => $__val) {
        if (!is_string($__val) || $__val !== '') continue;     // only blank strings
        $__m = $col_meta[$__col] ?? null;
        if (!$__m) continue;
        if (strtoupper($__m['Null'] ?? '') === 'YES') {
            $fields[$__col] = null;                            // nullable: '' → NULL
        } elseif ($__typed_non_string($__m['Type'] ?? '')) {
            unset($fields[$__col]);                            // NOT NULL numeric/date: let DB default apply
        }
        // NOT NULL string column: leave '' as-is
    }

    // ── FRACTION PARSER — converts "1/4", "1 1/2", "¾" etc. to decimals ─────────
    if (!function_exists('parseFraction')) {
        function parseFraction(string $input): float {
            $input = trim($input);
            if ($input === '') return 0.0;

            // Unicode fraction characters
            $unicode = ['¼'=>0.25,'½'=>0.5,'¾'=>0.75,'⅓'=>0.3333,'⅔'=>0.6667,
                        '⅛'=>0.125,'⅜'=>0.375,'⅝'=>0.625,'⅞'=>0.875,'⅙'=>0.1667,'⅚'=>0.8333];
            if (isset($unicode[$input])) return $unicode[$input];

            // Mixed number with unicode fraction: "1¾"
            if (preg_match('/^(\d+)([¼½¾⅓⅔⅛⅜⅝⅞⅙⅚])$/', $input, $m)) {
                return (float)$m[1] + ($unicode[$m[2]] ?? 0);
            }

            // Mixed number: "1 1/4" or "1-1/4"
            if (preg_match('/^(\d+)[\s\-]+(\d+)\s*\/\s*(\d+)$/', $input, $m)) {
                if ((float)$m[3] == 0) return (float)$m[1];
                return (float)$m[1] + (float)$m[2] / (float)$m[3];
            }

            // Simple fraction: "1/4" or "1 / 4"
            if (preg_match('/^(\d+)\s*\/\s*(\d+)$/', $input, $m)) {
                if ((float)$m[2] == 0) return 0.0;
                return (float)$m[1] / (float)$m[2];
            }

            // Plain decimal or integer
            return (float)$input;
        }
    }

    // ── RECIPE INGREDIENTS: parse fraction quantities before saving ───────────
    if ($target_table === 'recipes' && !empty($_POST['recipe_ingredients_block'])) {
        foreach ($_POST['recipe_ingredients_block'] as &$ing_row) {
            if (isset($ing_row['quantity']) && is_string($ing_row['quantity'])) {
                $ing_row['quantity'] = parseFraction($ing_row['quantity']);
            }
        }
        unset($ing_row);
    }

    // ── WRAP EVERYTHING IN A TRANSACTION ─────────────────────────────────────
    // Fix 5: atomic save — if ingredients or categories fail, main row rolls back too
    $pdo->beginTransaction();

    if ($id) {
        $sets = [];
        $vals = [];
        foreach ($fields as $col => $val) {
            $sets[] = "`{$col}` = ?";
            $vals[] = $val;
        }
        if (!empty($sets)) {
            $vals[] = $id;
            $pdo->prepare("UPDATE {$target_table} SET " . implode(', ', $sets) . " WHERE id = ?")->execute($vals);
        }
        $record_id = $id;
    } else {
        $cols         = array_keys($fields);
        $placeholders = array_fill(0, count($fields), '?');
        $pdo->prepare(
            "INSERT INTO {$target_table} (`" . implode('`, `', $cols) . "`) VALUES (" . implode(', ', $placeholders) . ")"
        )->execute(array_values($fields));
        $record_id = (int)$pdo->lastInsertId();
    }

    // ── RECIPE: PAIRINGS ─────────────────────────────────────────────────────
    if ($target_table === 'recipes') {
        // Delete all existing pairings for this recipe (both directions)
        $pdo->prepare("DELETE FROM recipe_pairings WHERE recipe_id = ? OR paired_recipe_id = ?")->execute([$record_id, $record_id]);

        $pair_ids = array_filter(array_map('intval', $_POST['pairings_matrix'] ?? []));
        if (!empty($pair_ids)) {
            $ins_pair = $pdo->prepare("INSERT IGNORE INTO recipe_pairings (recipe_id, paired_recipe_id) VALUES (?, ?)");
            foreach (array_unique($pair_ids) as $pair_id) {
                if ($pair_id === $record_id) continue; // can't pair with itself
                // Canonical storage: lower id first so bidirectionality is automatic
                $r1 = min($record_id, $pair_id);
                $r2 = max($record_id, $pair_id);
                $ins_pair->execute([$r1, $r2]);
            }
        }
    }

    // ── RECIPE: CATEGORIES ────────────────────────────────────────────────────
    // Fix 1+2+6: delete existing, insert fresh — fully replaces the junction rows
    if ($target_table === 'recipes') {

        $pdo->prepare("DELETE FROM recipe_categories_junction WHERE recipe_id = ?")->execute([$record_id]);

        $cat_ids = array_filter(array_map('intval', $_POST['categories_matrix'] ?? []));
        if (!empty($cat_ids)) {
            $ins_cat = $pdo->prepare("INSERT INTO recipe_categories_junction (recipe_id, category_id) VALUES (?, ?)");
            foreach (array_unique($cat_ids) as $cat_id) { // Fix 7: array_unique deduplicates
                $ins_cat->execute([$record_id, $cat_id]);
            }
        }

        // ── RECIPE: INGREDIENTS ───────────────────────────────────────────────
        // Fix 1+2+6: delete existing, validate, insert fresh
        $pdo->prepare("DELETE FROM recipe_ingredients WHERE recipe_id = ?")->execute([$record_id]);

        $ing_block = $_POST['recipe_ingredients_block'] ?? [];
        if (!empty($ing_block)) {
            $seen_ids = []; // Fix 7: track for duplicate detection

            $ins_ing = $pdo->prepare("
                INSERT INTO recipe_ingredients (recipe_id, ingredient_id, linked_recipe_id, quantity, unit_id, notes, ingredient_group)
                VALUES (?, ?, ?, ?, ?, ?, ?)
            ");

            foreach ($ing_block as $row) {
                $unit_id = filter_var($row['unit_id']  ?? 0, FILTER_VALIDATE_INT);
                $qty     = filter_var($row['quantity'] ?? 0, FILTER_VALIDATE_FLOAT);
                $notes   = trim(substr($row['notes'] ?? '', 0, 120));
                $ingGrp  = trim(substr($row['ingredient_group'] ?? '', 0, 80));

                // Resolve the picked reference: "ing:ID" (raw ingredient) or "rec:ID" (sub-recipe).
                // Legacy fallback: a plain ingredient_id field from older form posts.
                $ing_id = null;
                $lr_id  = null;
                $ref    = trim((string)($row['ref'] ?? ''));
                if ($ref !== '' && strpos($ref, ':') !== false) {
                    [$kind, $rid] = explode(':', $ref, 2);
                    $rid = (int)$rid;
                    if ($rid > 0) {
                        if ($kind === 'rec')      $lr_id  = $rid;
                        elseif ($kind === 'ing')  $ing_id = $rid;
                    }
                } elseif (!empty($row['ingredient_id'])) {
                    $ing_id = (int)$row['ingredient_id'];
                }

                // Skip blank rows / invalid quantity
                if ((!$ing_id && !$lr_id) || $qty === false) continue;

                // Reject negative quantities
                if ($qty < 0) continue;

                // A recipe can't be a sub-recipe of itself
                if ($lr_id === $record_id) continue;

                // Skip exact duplicates (same picked item + unit)
                $dedup_key = ($lr_id ? "r{$lr_id}" : "i{$ing_id}") . "_{$unit_id}";
                if (in_array($dedup_key, $seen_ids)) continue;
                $seen_ids[] = $dedup_key;

                $ins_ing->execute([$record_id, $ing_id ?: null, $lr_id ?: null, $qty, $unit_id ?: null, $notes ?: null, $ingGrp ?: null]);
            }
        }
    }

    // ── BLOG POSTS / RECIPES: STATUS (Save Draft vs Publish) ─────────────────────
    if (in_array($target_table, ['blog_posts', 'recipes'], true) && isset($_POST['post_status'])) {
        $status = $_POST['post_status'] === 'published' ? 'published' : 'draft';
        // $target_table is already whitelisted against $system_tables above.
        $pdo->prepare("UPDATE {$target_table} SET status = ? WHERE id = ?")->execute([$status, $record_id]);
    }

    // ── FIRST-PUBLISH SOCIAL SHARE (recipes + blog) — queue the admin-approved post ─
    if (in_array($target_table, ['blog_posts', 'recipes'], true)
        && ($_POST['post_status'] ?? '') === 'published'
        && !empty($_POST['social_share'])) {
        $__sf = dirname(__DIR__, 2) . '/includes/social.php';
        if (file_exists($__sf)) require_once $__sf;
        if (function_exists('social_enqueue') && social_tables_ready($pdo)) {
            $__shared = false;
            try {
                $__c = $pdo->prepare("SELECT social_shared_at FROM {$target_table} WHERE id = ?");
                $__c->execute([$record_id]);
                $__shared = (bool)$__c->fetchColumn();
            } catch (Throwable $e) { /* marker column missing -> treat as not yet shared */ }
            if (!$__shared) {
                social_enqueue($pdo, ($target_table === 'recipes' ? 'recipe' : 'blog'), (int)$record_id, [
                    'fb'       => trim((string)($_POST['social_fb']   ?? '')),
                    'ig'       => trim((string)($_POST['social_ig']   ?? '')),
                    'hashtags' => trim((string)($_POST['social_tags'] ?? '')),
                ]);
                try { $pdo->prepare("UPDATE {$target_table} SET social_shared_at = NOW() WHERE id = ?")->execute([$record_id]); } catch (Throwable $e) {}
            }
        }
    }

    // ── BLOG POSTS: CATEGORIES ───────────────────────────────────────────────────
    if ($target_table === 'blog_posts') {
        $pdo->prepare("DELETE FROM blog_post_categories WHERE post_id = ?")->execute([$record_id]);

        $cat_ids = array_filter(array_map('intval', $_POST['categories_matrix'] ?? []));
        if (!empty($cat_ids)) {
            $ins_cat = $pdo->prepare("INSERT INTO blog_post_categories (post_id, category_id) VALUES (?, ?)");
            foreach (array_unique($cat_ids) as $cat_id) {
                $ins_cat->execute([$record_id, $cat_id]);
            }
        }
    }

    // ── BREEDS: CLASSIFICATION ────────────────────────────────────────────────
    if ($target_table === 'breeds') {
        $pdo->prepare("DELETE FROM breed_classifications WHERE breed_id = ?")->execute([$record_id]);

        $class_ids = array_filter(array_map('intval', $_POST['classifications_matrix'] ?? []));
        if (!empty($class_ids)) {
            $ins_cl = $pdo->prepare("INSERT INTO breed_classifications (breed_id, classification_id) VALUES (?, ?)");
            foreach (array_unique($class_ids) as $class_id) {
                $ins_cl->execute([$record_id, $class_id]);
            }
        }
    }

    // ── PRODUCE / BAKERY / PANTRY: INGREDIENTS ────────────────────────────────
    if (in_array($target_table, ['fruits_vegetables', 'baked_goods', 'pantry'])) {
        $pdo->prepare("DELETE FROM product_ingredients WHERE product_type = ? AND product_id = ?")->execute([$target_table, $record_id]);

        $ing_ids = array_filter(array_map('intval', $_POST['standalone_ingredients'] ?? []));
        if (!empty($ing_ids)) {
            $ins_pi = $pdo->prepare("INSERT INTO product_ingredients (product_type, product_id, ingredient_id) VALUES (?, ?, ?)");
            foreach (array_unique($ing_ids) as $ing_id) {
                $ins_pi->execute([$target_table, $record_id, $ing_id]);
            }
        }
    }

    $pdo->commit();

    // Audit trail (after commit, so only successful saves are logged)
    erp_audit($pdo, $id ? 'update' : 'create', (string)$target_table, (int)$record_id, erp_audit_label($fields));

    $_SESSION['toast_msg']  = "Record saved successfully.";
    $_SESSION['toast_type'] = 'success';

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ERP] Schema record save failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = "Save error: " . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
}

header("Location: /admin/admin.php?view=schema&table=" . urlencode($target_table) . "&edit_id=" . $record_id);
exit;
