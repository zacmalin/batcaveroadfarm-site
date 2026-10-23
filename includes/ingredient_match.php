<?php
/**
 * includes/ingredient_match.php
 * Shared fuzzy ingredient <-> item name matching, used in BOTH directions:
 *   - forward  : recipe ingredient  -> in-stock farm-stand item  (bag icon on recipe page)
 *   - converse : farm-stand item    -> recipes that use it       (recipe card on stand pages)
 * Keeping the logic here means the two directions can never drift apart.
 */

if (!function_exists('bcrf_singular')) {
    function bcrf_singular($w) {
        $n = strlen($w);
        if ($n > 4 && substr($w, -3) === 'ies') return substr($w, 0, -3) . 'y';
        if ($n > 4 && preg_match('/(ches|shes|xes|zes|ses|oes)$/', $w)) return substr($w, 0, -2);
        if ($n > 3 && substr($w, -1) === 's' && substr($w, -2) !== 'ss') return substr($w, 0, -1);
        return $w;
    }
    function bcrf_words($s) {
        $s = strtolower((string)$s);
        $s = preg_replace('/[^a-z0-9\s]+/', ' ', $s);
        $ws = preg_split('/\s+/', trim($s), -1, PREG_SPLIT_NO_EMPTY);
        return array_values(array_unique(array_map('bcrf_singular', $ws)));
    }
}

if (!function_exists('bcrf_words_match')) {
    /**
     * Generous whole-word match: true when the smaller word-set is fully
     * contained in the larger (e.g. "tomato" matches "heirloom tomato").
     */
    function bcrf_words_match(array $a, array $b): bool {
        if (!$a || !$b) return false;
        $short = count($a) <= count($b) ? $a : $b;
        $long  = count($a) <= count($b) ? $b : $a;
        foreach ($short as $w) { if (!in_array($w, $long, true)) return false; }
        return true;
    }
}

if (!function_exists('bcrf_recipe_ingredient_index')) {
    /**
     * Every ingredient that appears in at least one recipe, with a
     * count of distinct recipes and pre-computed name word-sets.
     * One query; callers match items against this in memory.
     */
    function bcrf_recipe_ingredient_index(PDO $pdo): array {
        try {
            $rows = $pdo->query(
                "SELECT i.id, i.singular_name, i.plural_name,
                        COUNT(DISTINCT r.id) AS recipe_count
                 FROM ingredients i
                 JOIN recipe_ingredients ri ON ri.ingredient_id = i.id
                 JOIN recipes r ON r.id = ri.recipe_id
                 GROUP BY i.id, i.singular_name, i.plural_name"
            )->fetchAll(PDO::FETCH_ASSOC);
        } catch (Throwable $e) {
            return [];
        }
        $out = [];
        foreach ($rows as $r) {
            $wsets = [];
            foreach ([$r['singular_name'] ?? '', $r['plural_name'] ?? ''] as $nm) {
                if ($nm === null || $nm === '') continue;
                $w = bcrf_words($nm);
                if ($w) $wsets[] = $w;
            }
            if (!$wsets) continue;
            $r['recipe_count'] = (int)$r['recipe_count'];
            $r['__wsets'] = $wsets;
            $out[] = $r;
        }
        return $out;
    }

    /**
     * Best recipe-ingredient match for a given item name, or null.
     * Picks the ingredient used in the most recipes (tie -> closest name).
     */
    function bcrf_item_recipe_match(string $itemName, array $index): ?array {
        $iw = bcrf_words($itemName);
        if (!$iw) return null;
        $matches = [];
        foreach ($index as $c) {
            foreach ($c['__wsets'] as $ws) {
                if (bcrf_words_match($iw, $ws)) { $matches[] = $c; break; }
            }
        }
        if (!$matches) return null;
        usort($matches, function ($a, $b) {
            $c = ($b['recipe_count'] <=> $a['recipe_count']);
            if ($c !== 0) return $c;
            return strlen((string)$a['singular_name']) <=> strlen((string)$b['singular_name']);
        });
        return $matches[0];
    }
}

if (!function_exists('bcrf_recipe_icon')) {
    function bcrf_recipe_icon(): string {
        return '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M11 9H9V2H7v7H5V2H3v7c0 2.12 1.66 3.84 3.75 3.97V22h2.5v-9.03C11.34 12.84 13 11.12 13 9V2h-2v7zm5-3v8h2.5v8H21V2c-2.76 0-5 2.24-5 4z"/></svg>';
    }
}
