<?php
// admin/includes/schema_safety.php
// Included by admin/admin.php on every console page load.
//
// Until Nov 5, 2026 this file created tables and added columns on every page load. All of that
// structure is now in migration 0001_baseline (admin → Settings → Database), and future structure
// changes go in numbered migrations (Farm OS Phase 0). It also dropped and re-added
// recipes.pairs_with_recipe_id and ingredients.pairs_with_recipe_id on every load; migration 0002
// removes those unused columns for good (pairings live in recipe_pairings).
//
// What stays here is data upkeep, not structure.

// ── INVENTORY: fill category_id from the category text ────────────────────────
// Products saved with only the category name (enum) get the matching inventory_categories id.
try {
    $pdo->exec("UPDATE inventory i
                JOIN inventory_categories ic ON ic.name = i.category
                SET i.category_id = ic.id
                WHERE i.category_id IS NULL AND i.category IS NOT NULL AND i.category != ''");
} catch (Throwable $e) {
    error_log('[ERP] Schema safety: inventory category backfill failed: ' . $e->getMessage());
}

if (!defined('SCHEMA_SAFETY_DONE')) define('SCHEMA_SAFETY_DONE', true);
