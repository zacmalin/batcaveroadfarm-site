<?php
/**
 * includes/ratings.php — shared star rendering.
 *  - bcrf_rating_static_html(): read-only stars + count for index/search cards (empty string if no ratings)
 *  - bcrf_rating_widget_html(): interactive widget for the recipe page (JS in min.rating.js enhances it)
 * Both use a two-layer overlay so the average can be shown to a fractional fill.
 */

if (!function_exists('bcrf_rating_pct')) {
    function bcrf_rating_pct(float $avg): int {
        return (int)round(max(0.0, min(5.0, $avg)) / 5 * 100);
    }
    function bcrf_rating_num(float $avg): string {
        return rtrim(rtrim(number_format($avg, 1), '0'), '.');
    }

    function bcrf_rating_static_html(float $avg, int $count): string {
        if ($count <= 0) return '';
        $pct = bcrf_rating_pct($avg);
        $num = bcrf_rating_num($avg);
        $lbl = 'Rated ' . $num . ' out of 5 from ' . $count . ' rating' . ($count === 1 ? '' : 's');
        return '<div class="rate rate--static" role="img" aria-label="' . htmlspecialchars($lbl) . '">'
             . '<span class="rate-stars"><span class="rate-empty" aria-hidden="true">☆☆☆☆☆</span>'
             . '<span class="rate-fill" aria-hidden="true" style="width:' . $pct . '%">★★★★★</span></span>'
             . '<span class="rate-count">' . $num . ' (' . $count . ')</span></div>';
    }

    function bcrf_rating_widget_html(int $recipeId, float $avg, int $count): string {
        $pct = bcrf_rating_pct($avg);
        $num = bcrf_rating_num($avg);
        $label = $count > 0 ? ($num . ' (' . $count . ')') : 'Be the first to rate';
        $h  = '<div class="rate rate--interactive" data-recipe-id="' . $recipeId . '" data-pct="' . $pct . '">';
        $h .= '<div class="rate-stars"><span class="rate-empty" aria-hidden="true">☆☆☆☆☆</span>';
        $h .= '<span class="rate-fill" aria-hidden="true" style="width:' . $pct . '%">★★★★★</span>';
        $h .= '<div class="rate-input" role="radiogroup" aria-label="Rate this recipe">';
        for ($i = 1; $i <= 5; $i++) {
            $h .= '<button type="button" class="rate-hit" data-val="' . $i . '" aria-label="' . $i . ' star' . ($i === 1 ? '' : 's') . '"></button>';
        }
        $h .= '</div></div>';
        $h .= '<span class="rate-label">' . $label . '</span>';
        $h .= '</div>';
        return $h;
    }
}
