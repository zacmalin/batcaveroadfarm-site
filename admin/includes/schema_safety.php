<?php
// admin/includes/schema_safety.php
// Automated schema structural integrity safety buffer.
// Runs silently; errors are logged but never surfaced to the user.

// ── PANTRY TABLE + COLUMNS ────────────────────────────────────────────────────
try {
    // Create table if it doesn't exist yet
    $pdo->exec("CREATE TABLE IF NOT EXISTS pantry (
        id          INT AUTO_INCREMENT PRIMARY KEY,
        name        VARCHAR(255) NOT NULL,
        price       DECIMAL(10,2) NOT NULL DEFAULT 0.00,
        size        DECIMAL(10,2) NULL DEFAULT NULL,
        size_unit_id INT NULL DEFAULT NULL,
        is_active   TINYINT(1) NOT NULL DEFAULT 1,
        created_at  TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Add any missing columns for existing installs
    $existing_pantry_cols = $pdo->query("DESCRIBE pantry")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('price',       $existing_pantry_cols)) $pdo->exec("ALTER TABLE pantry ADD COLUMN price       DECIMAL(10,2) NOT NULL DEFAULT 0.00 AFTER name");
    if (!in_array('size',        $existing_pantry_cols)) $pdo->exec("ALTER TABLE pantry ADD COLUMN size        DECIMAL(10,2) NULL DEFAULT NULL AFTER price");
    if (!in_array('size_unit_id',$existing_pantry_cols)) $pdo->exec("ALTER TABLE pantry ADD COLUMN size_unit_id INT NULL DEFAULT NULL AFTER size");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: pantry failed: ' . $e->getMessage());
}


// ── RECIPE PAIRINGS JUNCTION TABLE ───────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_pairings (
        id               INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id        INT NOT NULL,
        paired_recipe_id INT NOT NULL,
        UNIQUE KEY unique_pairing (recipe_id, paired_recipe_id)
    )");
    // Drop the old single-FK column from recipes if it exists
    $rcp_cols = $pdo->query("DESCRIBE recipes")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('pairs_with_recipe_id', $rcp_cols))
        $pdo->exec("ALTER TABLE recipes DROP COLUMN pairs_with_recipe_id");
    // Drop the wrongly-added column from ingredients if it exists
    $ing_chk = $pdo->query("DESCRIBE ingredients")->fetchAll(PDO::FETCH_COLUMN);
    if (in_array('pairs_with_recipe_id', $ing_chk))
        $pdo->exec("ALTER TABLE ingredients DROP COLUMN pairs_with_recipe_id");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipe_pairings failed: ' . $e->getMessage());
}

// ── RECIPE_INGREDIENTS NOTES COLUMN ──────────────────────────────────────────
// Adds optional per-ingredient preparation note (e.g. "finely chopped")
try {
    $existing_ri_cols = $pdo->query("DESCRIBE recipe_ingredients")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('notes', $existing_ri_cols)) {
        $pdo->exec("ALTER TABLE recipe_ingredients ADD COLUMN notes VARCHAR(120) NULL DEFAULT NULL AFTER unit_id");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipe_ingredients notes check failed: ' . $e->getMessage());
}

// ── INGREDIENTS: USDA NUTRITION COLUMNS ──────────────────────────────────────
try {
    $ing_cols = $pdo->query("DESCRIBE ingredients")->fetchAll(PDO::FETCH_COLUMN);
    $ing_add = [
        'calories_per_100g'    => "DECIMAL(8,2) NULL DEFAULT NULL",
        'protein_per_100g'     => "DECIMAL(8,2) NULL DEFAULT NULL",
        'fat_per_100g'         => "DECIMAL(8,2) NULL DEFAULT NULL",
        'carbs_per_100g'       => "DECIMAL(8,2) NULL DEFAULT NULL",
        'fiber_per_100g'       => "DECIMAL(8,2) NULL DEFAULT NULL",
        'sugar_per_100g'       => "DECIMAL(8,2) NULL DEFAULT NULL",
        'sodium_per_100g'      => "DECIMAL(8,2) NULL DEFAULT NULL",
        'density_g_per_ml'     => "DECIMAL(8,4) NULL DEFAULT NULL",
        'weight_per_unit_g'    => "DECIMAL(8,2) NULL DEFAULT NULL",
        'usda_fdc_id'          => "INT NULL DEFAULT NULL",
        'nutrition_verified'   => "TINYINT(1) NOT NULL DEFAULT 0",
        'pairs_with_recipe_id' => "INT NULL DEFAULT NULL COMMENT 'Optional: recipe this ingredient pairs well with'",
    ];
    foreach ($ing_add as $col => $def) {
        if (!in_array($col, $ing_cols))
            $pdo->exec("ALTER TABLE ingredients ADD COLUMN {$col} {$def}");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: ingredients nutrition columns failed: ' . $e->getMessage());
}

// ── BLOG_POST_CATEGORIES JUNCTION TABLE ───────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS blog_post_categories (
        id       INT AUTO_INCREMENT PRIMARY KEY,
        post_id  INT NOT NULL,
        category_id INT NOT NULL,
        UNIQUE KEY unique_post_category (post_id, category_id)
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: blog_post_categories table check failed: ' . $e->getMessage());
}

// ── BLOG_POSTS STATUS COLUMN ─────────────────────────────────────────────────
try {
    $existing_bp_cols = $pdo->query("DESCRIBE blog_posts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('status', $existing_bp_cols)) {
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN status ENUM('draft','published') NOT NULL DEFAULT 'draft' AFTER title");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: blog_posts status column check failed: ' . $e->getMessage());
}

// ── BLOG_POSTS LINKED_RECIPE_ID — ensure column exists and allows NULL ────────
try {
    $existing_bp_cols = $pdo->query("DESCRIBE blog_posts")->fetchAll(PDO::FETCH_ASSOC);
    $bp_col_map = array_column($existing_bp_cols, null, 'Field');

    if (!isset($bp_col_map['linked_recipe_id'])) {
        // Column doesn't exist yet — create it as nullable INT
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN linked_recipe_id INT NULL DEFAULT NULL");
    } elseif (stripos($bp_col_map['linked_recipe_id']['Null'], 'NO') !== false) {
        // Column exists but is NOT NULL — change it to allow NULL
        $pdo->exec("ALTER TABLE blog_posts MODIFY COLUMN linked_recipe_id INT NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: blog_posts linked_recipe_id check failed: ' . $e->getMessage());
}

// ── CAMPAIGNS LOG TABLE ───────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS campaigns (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        subject        VARCHAR(255)  NOT NULL,
        segment        VARCHAR(50)   NOT NULL DEFAULT 'all',
        from_name      VARCHAR(100)  DEFAULT NULL,
        reply_to       VARCHAR(150)  DEFAULT NULL,
        body           LONGTEXT      NOT NULL,
        recipient_count INT          NOT NULL DEFAULT 0,
        is_test        TINYINT(1)    NOT NULL DEFAULT 0,
        status         ENUM('sent','partial','failed') NOT NULL DEFAULT 'sent',
        created_at     TIMESTAMP     DEFAULT CURRENT_TIMESTAMP
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: campaigns table check failed: ' . $e->getMessage());
}

// ── CAMPAIGNS TABLE — add missing columns if table pre-existed ────────────────
try {
    $existing_camp_cols = $pdo->query("DESCRIBE campaigns")->fetchAll(PDO::FETCH_COLUMN);

    // Each ALTER uses no AFTER clause — appends to end, avoids dependency on other columns existing
    if (!in_array('segment',         $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN segment        VARCHAR(50)  NOT NULL DEFAULT 'all'");
    if (!in_array('from_name',       $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN from_name      VARCHAR(100) DEFAULT NULL");
    if (!in_array('reply_to',        $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN reply_to       VARCHAR(150) DEFAULT NULL");
    if (!in_array('body',            $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN body            LONGTEXT     NULL DEFAULT NULL");
    if (!in_array('recipient_count', $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN recipient_count INT          NOT NULL DEFAULT 0");
    if (!in_array('is_test',         $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN is_test        TINYINT(1)   NOT NULL DEFAULT 0");
    if (!in_array('status',          $existing_camp_cols)) $pdo->exec("ALTER TABLE campaigns ADD COLUMN status         ENUM('sent','partial','failed') NOT NULL DEFAULT 'sent'");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: campaigns column check failed: ' . $e->getMessage());
}

// ── VIEW_PERMISSIONS TABLE ────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS view_permissions (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        role       VARCHAR(50)  NOT NULL,
        view_name  VARCHAR(50)  NOT NULL,
        can_access TINYINT(1)   NOT NULL DEFAULT 0,
        UNIQUE KEY unique_role_view (role, view_name)
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: view_permissions table check failed: ' . $e->getMessage());
}

// ── USERS TABLE — employment columns ─────────────────────────────────────────
try {
    $existing_user_cols = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);

    if (!in_array('salary',                  $existing_user_cols))
        $pdo->exec("ALTER TABLE users ADD COLUMN salary                   DECIMAL(10,2) NULL DEFAULT NULL");
    if (!in_array('last_salary_change',      $existing_user_cols))
        $pdo->exec("ALTER TABLE users ADD COLUMN last_salary_change       DATE          NULL DEFAULT NULL");
    if (!in_array('date_hired',              $existing_user_cols))
        $pdo->exec("ALTER TABLE users ADD COLUMN date_hired               DATE          NULL DEFAULT NULL");
    if (!in_array('date_terminated',         $existing_user_cols))
        $pdo->exec("ALTER TABLE users ADD COLUMN date_terminated          DATE          NULL DEFAULT NULL");
    if (!in_array('termination_reason',      $existing_user_cols))
        $pdo->exec("ALTER TABLE users ADD COLUMN termination_reason       VARCHAR(500)  NULL DEFAULT NULL");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: users employment columns failed: ' . $e->getMessage());
}

// ── RECIPE RATINGS TABLE ──────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_ratings (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id  INT NOT NULL,
        user_id    INT NOT NULL,
        rating     TINYINT NOT NULL,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        UNIQUE KEY unique_user_recipe (recipe_id, user_id),
        INDEX idx_recipe (recipe_id)
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipe_ratings failed: ' . $e->getMessage());
}

// ── RECIPE COMMENTS TABLE ─────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS recipe_comments (
        id                INT AUTO_INCREMENT PRIMARY KEY,
        recipe_id         INT NOT NULL,
        user_id           INT NOT NULL,
        comment_text      TEXT NOT NULL,
        status            ENUM('published','pending','rejected','flagged') NOT NULL DEFAULT 'published',
        manually_approved TINYINT(1) NOT NULL DEFAULT 0,
        created_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        updated_at        TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP,
        INDEX idx_recipe (recipe_id),
        INDEX idx_user   (user_id),
        INDEX idx_status (status)
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipe_comments failed: ' . $e->getMessage());
}

// ── USERS: comment restriction column ────────────────────────────────────────
try {
    $existing_user_cols2 = $pdo->query("DESCRIBE users")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('comment_restricted_until', $existing_user_cols2)) {
        $pdo->exec("ALTER TABLE users ADD COLUMN comment_restricted_until DATE NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: comment_restricted_until failed: ' . $e->getMessage());
}


// ── INVENTORY CATEGORIES TABLE ────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS inventory_categories (
        id         INT AUTO_INCREMENT PRIMARY KEY,
        name       VARCHAR(100) NOT NULL UNIQUE,
        slug       VARCHAR(110) NOT NULL UNIQUE,
        icon       VARCHAR(10)  NULL DEFAULT NULL,
        sort_order INT          NOT NULL DEFAULT 0,
        created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");

    // ── MIGRATE: seed from distinct category text values in inventory ─────────
    $existing_inv_cats = $pdo->query("SELECT COUNT(*) FROM inventory_categories")->fetchColumn();
    if ((int)$existing_inv_cats === 0) {
        $distinct = $pdo->query(
            "SELECT DISTINCT category FROM inventory WHERE category IS NOT NULL AND category != '' ORDER BY category"
        )->fetchAll(PDO::FETCH_COLUMN);
        $ins_cat = $pdo->prepare(
            "INSERT IGNORE INTO inventory_categories (name, slug) VALUES (?, ?)"
        );
        foreach ($distinct as $cat_name) {
            $slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($cat_name)), '-');
            $ins_cat->execute([$cat_name, $slug]);
        }
        // Seed Thrift if not already present
        $pdo->exec("INSERT IGNORE INTO inventory_categories (name, slug) VALUES ('Thrift', 'thrift')");
    }

    // ── ADD category_id FK column to inventory ────────────────────────────────
    $inv_cols = $pdo->query("DESCRIBE inventory")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('category_id', $inv_cols)) {
        $pdo->exec("ALTER TABLE inventory ADD COLUMN category_id INT NULL DEFAULT NULL");
        try {
            $pdo->exec("ALTER TABLE inventory ADD CONSTRAINT fk_inv_cat
                        FOREIGN KEY (category_id) REFERENCES inventory_categories(id)
                        ON DELETE SET NULL");
        } catch (Throwable $fk_e) {} // FK may already exist
    }

    // ── BACKFILL category_id from existing category text values ───────────────
    $pdo->exec("UPDATE inventory i
                JOIN inventory_categories ic ON ic.name = i.category
                SET i.category_id = ic.id
                WHERE i.category_id IS NULL AND i.category IS NOT NULL AND i.category != ''");

} catch (Throwable $e) {
    error_log('[ERP] Schema safety: inventory_categories failed: ' . $e->getMessage());
}

// ── RECIPES: featured_image column ───────────────────────────────────────────
try {
    $recipe_cols = $pdo->query("DESCRIBE recipes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('featured_image', $recipe_cols))
        $pdo->exec("ALTER TABLE recipes ADD COLUMN featured_image VARCHAR(500) NULL DEFAULT NULL AFTER title");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipes featured_image failed: ' . $e->getMessage());
}

// ── RECIPES: cuisine + calories + servings columns ────────────────────────────
try {
    $recipe_cols2 = $pdo->query("DESCRIBE recipes")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('cuisine',    $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN cuisine    VARCHAR(60)  NULL DEFAULT NULL");
    if (!in_array('servings',   $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN servings   INT          NULL DEFAULT NULL");
    if (!in_array('prep_time',  $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN prep_time  VARCHAR(50)  NULL DEFAULT NULL");
    if (!in_array('cook_time',  $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN cook_time  VARCHAR(50)  NULL DEFAULT NULL");
    if (!in_array('difficulty', $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN difficulty VARCHAR(30)  NULL DEFAULT NULL");
    if (!in_array('calories',   $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN calories   INT          NULL DEFAULT NULL COMMENT 'Calories per serving'");
    // ── NUTRITION COLUMNS (per-serving values) ────────────────────────────────
    if (!in_array('protein_g',            $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN protein_g            DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('fat_g',                $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN fat_g                DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('carbs_g',              $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN carbs_g              DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('fiber_g',              $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN fiber_g              DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('sugar_g',              $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN sugar_g              DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('sodium_mg',            $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN sodium_mg            DECIMAL(8,2) NULL DEFAULT NULL");
    if (!in_array('nutrition_source',     $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN nutrition_source     VARCHAR(30)  NULL DEFAULT NULL");
    if (!in_array('nutrition_updated_at', $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN nutrition_updated_at TIMESTAMP    NULL DEFAULT NULL");
    if (!in_array('pairs_with_recipe_id', $recipe_cols2)) $pdo->exec("ALTER TABLE recipes ADD COLUMN pairs_with_recipe_id INT          NULL DEFAULT NULL COMMENT 'Recipe this pairs well with'");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: recipes nutrition columns failed: ' . $e->getMessage());
}

// ── BLOG_POSTS: image_url column ─────────────────────────────────────────────
try {
    $existing_bp_img_cols = $pdo->query("DESCRIBE blog_posts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('image_url', $existing_bp_img_cols)) {
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN image_url VARCHAR(500) NULL DEFAULT NULL");
    }
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: blog_posts image_url failed: ' . $e->getMessage());
}

// ── INVENTORY: Bulk backfill SKUs for existing items that have none ───────────
try {
    $no_sku_ids = $pdo->query(
        "SELECT id FROM inventory WHERE (sku IS NULL OR sku = '') ORDER BY id ASC"
    )->fetchAll(PDO::FETCH_COLUMN);

    if (!empty($no_sku_ids)) {
        $max_n = (int)$pdo->query(
            "SELECT MAX(CAST(SUBSTRING(sku,5) AS UNSIGNED)) FROM inventory WHERE sku LIKE 'BCF-%'"
        )->fetchColumn();
        $upd = $pdo->prepare("UPDATE inventory SET sku = ? WHERE id = ? AND (sku IS NULL OR sku = '')");
        foreach ($no_sku_ids as $iid) {
            $upd->execute(['BCF-' . str_pad(++$max_n, 3, '0', STR_PAD_LEFT), (int)$iid]);
        }
    }
} catch (Throwable $e) {
    error_log('[ERP] SKU backfill failed: ' . $e->getMessage());
}

// ── BLOG_POSTS: scheduled publishing column ───────────────────────────────────
try {
    $bp_cols3 = $pdo->query("DESCRIBE blog_posts")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('publish_at', $bp_cols3))
        $pdo->exec("ALTER TABLE blog_posts ADD COLUMN publish_at DATETIME NULL DEFAULT NULL COMMENT 'Scheduled publish time; NULL = publish immediately'");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: blog_posts publish_at failed: ' . $e->getMessage());
}

// ── AUDIT LOG TABLE ───────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS audit_log (
        id           INT AUTO_INCREMENT PRIMARY KEY,
        user_id      INT          NULL,
        user_name    VARCHAR(100) NOT NULL DEFAULT '',
        action       ENUM('create','update','delete') NOT NULL,
        table_name   VARCHAR(100) NOT NULL,
        record_id    INT          NULL,
        record_label VARCHAR(255) NOT NULL DEFAULT '',
        ip_address   VARCHAR(45)  NULL,
        created_at   TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_table  (table_name),
        INDEX idx_user   (user_id),
        INDEX idx_action (action),
        INDEX idx_ts     (created_at)
    )");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: audit_log failed: ' . $e->getMessage());
}

// ── SCHEMA SAFETY VERSION FLAG ────────────────────────────────────────────────
// Store a version in session so heavy ALTER checks are skipped on repeat loads.
// Increment the version string whenever schema changes are needed.
if (!defined('SCHEMA_SAFETY_DONE')) {
    // Already ran this request — skip
    define('SCHEMA_SAFETY_DONE', true);
}

// ── INVENTORY: SKU column ─────────────────────────────────────────────────────
try {
    $inv_cols = $pdo->query("DESCRIBE inventory")->fetchAll(PDO::FETCH_COLUMN);
    if (!in_array('sku', $inv_cols))
        $pdo->exec("ALTER TABLE inventory ADD COLUMN sku VARCHAR(50) NULL DEFAULT NULL UNIQUE COMMENT 'Barcode SKU — auto-generated as BCF-NNN if blank'");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: inventory sku failed: ' . $e->getMessage());
}
