<?php
// includes/kitchen_bible.php — Farm OS Phase 4: the house Recipe Bible (Nov 13, 2026; migration 0052, admin/kitchen_bible.php).
// recipes.in_house marks a recipe for the staff-only Recipe Bible, separate from recipes.status (the website).
// The Bible is for cooking: ingredient lines in the order they were entered (the order you need them, with group
// headings), scaled by a batch multiplier, then the method. Labels are different: they list ingredients heaviest first.
// Used by admin/kitchen_bible.php and tests/kitchen_bible_test.php.
if (!function_exists('bcrf_bible_ready')) {

require_once __DIR__ . '/units.php';

/** Migration 0052 has run. */
function bcrf_bible_ready(PDO $pdo): bool {
    try { return (bool)$pdo->query("SHOW COLUMNS FROM recipes LIKE 'in_house'")->fetch(); }
    catch (Throwable $e) { return false; }
}

/** Batch multipliers offered on a Bible recipe. */
function bcrf_bible_multipliers(): array {
    return ['0.5' => '½×', '1' => '1×', '2' => '2×', '3' => '3×', '4' => '4×'];
}

/** A multiplier from the query string; anything not offered is 1. */
function bcrf_bible_multiplier($raw): float {
    $k = (string)$raw;
    return array_key_exists($k, bcrf_bible_multipliers()) ? (float)$k : 1.0;
}

/** Recipes in the Bible, by title. Each: id, title, slug, status, recipe_yield, lines. */
function bcrf_bible_list(PDO $pdo, string $q = ''): array {
    $sql = "SELECT r.id, r.title, r.slug, r.status, r.recipe_yield,
                   (SELECT COUNT(*) FROM recipe_ingredients ri WHERE ri.recipe_id = r.id) AS line_count
            FROM recipes r WHERE r.in_house = 1" . ($q !== '' ? " AND r.title LIKE ?" : '') . " ORDER BY r.title";
    $st = $pdo->prepare($sql);
    $st->execute($q !== '' ? ['%' . $q . '%'] : []);
    return array_map(fn($r) => ['id' => (int)$r['id'], 'title' => (string)$r['title'], 'slug' => (string)$r['slug'],
                                'status' => (string)$r['status'], 'recipe_yield' => (string)($r['recipe_yield'] ?? ''),
                                'lines' => (int)$r['line_count']], $st->fetchAll(PDO::FETCH_ASSOC));
}

/** Format one quantity for a cook: fractions where close (¼, ½…), else a short decimal. Empty for 0. */
function bcrf_bible_qty(float $qty): string {
    if ($qty <= 0) return '';
    return function_exists('formatRecipeQty') ? formatRecipeQty($qty) : rtrim(rtrim(number_format($qty, 2, '.', ''), '0'), '.');
}

/**
 * One recipe for cooking, or null if it isn't in the Bible.
 * lines: in entry order (ri.id), each with group, qty (scaled), qty_text, unit, name, notes, linked_recipe_id,
 * linked_in_bible. Groups come back as headings in the order they first appear.
 */
function bcrf_bible_recipe(PDO $pdo, int $id, float $mult = 1.0): ?array {
    $st = $pdo->prepare("SELECT id, title, slug, status, in_house, recipe_yield, prep_time, cook_time, description, instructions
                         FROM recipes WHERE id = ? AND in_house = 1");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $st = $pdo->prepare("
        SELECT ri.id, ri.quantity, ri.notes, COALESCE(ri.ingredient_group, '') AS grp, ri.linked_recipe_id,
               i.singular_name, i.plural_name, mu.name AS unit_name, mu.abbreviation AS unit_abbr,
               lr.title AS linked_title, lr.in_house AS linked_in_house
        FROM recipe_ingredients ri
        LEFT JOIN ingredients i ON i.id = ri.ingredient_id
        LEFT JOIN recipes lr ON lr.id = ri.linked_recipe_id
        LEFT JOIN measurement_units mu ON mu.id = ri.unit_id
        WHERE ri.recipe_id = ?
        ORDER BY ri.id");
    $st->execute([$id]);
    $lines = [];
    foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $l) {
        $qty = round((float)$l['quantity'] * $mult, 4);
        $isRecipe = !empty($l['linked_recipe_id']);
        $name = $isRecipe ? (string)($l['linked_title'] ?? 'Recipe')
              : (string)(($qty > 1 && trim((string)($l['plural_name'] ?? '')) !== '') ? $l['plural_name'] : ($l['singular_name'] ?? ''));
        $unit = trim((string)($l['unit_abbr'] ?? '')) !== '' ? (string)$l['unit_abbr'] : (string)($l['unit_name'] ?? '');
        $lines[] = ['id' => (int)$l['id'], 'group' => trim((string)$l['grp']), 'qty' => $qty, 'qty_text' => bcrf_bible_qty($qty),
                    'unit' => $unit, 'name' => $name, 'notes' => trim((string)($l['notes'] ?? '')),
                    'linked_recipe_id' => $isRecipe ? (int)$l['linked_recipe_id'] : null,
                    'linked_in_bible' => $isRecipe && !empty($l['linked_in_house'])];
    }
    $r['id'] = (int)$r['id'];
    $r['lines'] = $lines;
    $r['method_html'] = bcrf_bible_clean_html((string)($r['instructions'] ?? ''));
    $r['mult'] = $mult;
    return $r;
}

/** Recipe method HTML from the editor, kept to plain formatting tags with no attributes. */
function bcrf_bible_clean_html(string $html): string {
    $html = preg_replace('#<(script|style|iframe|object|embed)\b[^>]*>.*?</\1>#is', '', $html) ?? '';
    $html = strip_tags($html, '<p><br><ol><ul><li><strong><em><b><i><u><h2><h3><h4><blockquote>');
    return preg_replace('#<(/?)(p|br|ol|ul|li|strong|em|b|i|u|h2|h3|h4|blockquote)\b[^>]*>#i', '<$1$2>', $html) ?? '';
}

}
