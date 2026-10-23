<?php
// farm-stand/recipe_detail.php - Overhauled Multiplier Matrix Card
require_once '../config.php';
require_once '../includes/units.php';
require_once '../includes/ingredient_match.php';
require_once '../includes/ratings.php';
require_once '../includes/auth.php';
require_once '../includes/comments.php';

$slug = trim($_GET['slug'] ?? '');
if (empty($slug)) { die("Missing recipe identification parameter."); }

$stmt = $pdo->prepare("SELECT r.*, c.name AS cuisine FROM recipes r LEFT JOIN cuisines c ON r.cuisine_id = c.id WHERE r.slug = ? AND r.status = 'published'");
$stmt->execute([$slug]);
$recipe = $stmt->fetch();
if (!$recipe) { die("Recipe resource missing."); }

$recipe_id = (int)$recipe['id'];
$comments = bcrf_fetch_comments($pdo, $recipe_id);
$commentCount = count($comments);

$cat_stmt = $pdo->prepare("SELECT c.id, c.category_name AS name FROM categories c JOIN recipe_categories_junction rcj ON c.id=rcj.category_id WHERE rcj.recipe_id = ?");
$cat_stmt->execute([$recipe_id]);
$assigned_categories = $cat_stmt->fetchAll(PDO::FETCH_ASSOC);

// Aggregate rating (recipe_ratings) — feeds the schema AND the visible star line.
$__avgRating = 0.0; $__ratingCount = 0;
try {
    $rate_stmt = $pdo->prepare("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS rating_count FROM recipe_ratings WHERE recipe_id = ?");
    $rate_stmt->execute([$recipe_id]);
    if ($__r = $rate_stmt->fetch(PDO::FETCH_ASSOC)) {
        $__avgRating   = (float)($__r['avg_rating'] ?? 0);
        $__ratingCount = (int)($__r['rating_count'] ?? 0);
    }
} catch (Throwable $e) { /* table missing -> no rating */ }

// Pairs-well-with recipes (canonical min/max storage -> check both directions).
$pairings = [];
try {
    $pair_stmt = $pdo->prepare("
        SELECT r.*,
               (SELECT ROUND(AVG(rating),1) FROM recipe_ratings WHERE recipe_id = r.id) AS avg_rating,
               (SELECT COUNT(*)             FROM recipe_ratings WHERE recipe_id = r.id) AS rating_count
        FROM recipe_pairings rp
        JOIN recipes r ON r.id = CASE WHEN rp.recipe_id = ? THEN rp.paired_recipe_id ELSE rp.recipe_id END
                       AND r.status = 'published'
        WHERE rp.recipe_id = ? OR rp.paired_recipe_id = ?
        ORDER BY r.title ASC
    ");
    $pair_stmt->execute([$recipe_id, $recipe_id, $recipe_id]);
    $pairings = $pair_stmt->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) { $pairings = []; }

// Ingredients: LEFT JOIN so sub-recipe rows (linked_recipe_id, no ingredient) survive,
// plus the linked recipe's title/slug for the 📖 link.
$ing_stmt = $pdo->prepare("
    SELECT ri.quantity, ri.ingredient_id, ri.linked_recipe_id, ri.unit_id, ri.notes,
           COALESCE(ri.ingredient_group, '') AS ingredient_group,
           mu.name AS unit_name, mu.abbreviation AS unit_abbr, mu.system_type AS unit_system,
           i.singular_name, i.plural_name,
           lr.title AS linked_title, lr.slug AS linked_slug
    FROM recipe_ingredients ri
    LEFT JOIN ingredients i        ON ri.ingredient_id   = i.id
    LEFT JOIN recipes lr           ON ri.linked_recipe_id = lr.id
    LEFT JOIN measurement_units mu ON ri.unit_id          = mu.id
    WHERE ri.recipe_id = ?
");
$ing_stmt->execute([$recipe_id]);
$recipe_ingredients = $ing_stmt->fetchAll(PDO::FETCH_ASSOC);

// Normalize sub-recipe lines: use the linked recipe's title as the display name (so the
// JSON-LD + farm-stand matcher keep working) and flag it for the 📖 link in the list.
foreach ($recipe_ingredients as $__k => &$__ri) {
    if (!empty($__ri['linked_recipe_id'])) {
        $__nm = $__ri['linked_title'] ?? 'Recipe';
        $__ri['singular_name'] = $__nm;
        $__ri['plural_name']   = $__nm;
        $__ri['__is_recipe']   = true;
        $__ri['__recipe_slug'] = $__ri['linked_slug'] ?? '';
    } else {
        $__ri['__is_recipe']   = false;
        $__ri['__recipe_slug'] = '';
    }
}
unset($__ri);

// Distinct sub-recipes used as ingredients (image + rating) for the "Made With Our Recipes" cards
$subrecipes = [];
$__sub_ids = [];
foreach ($recipe_ingredients as $__ri2) {
    if (!empty($__ri2['linked_recipe_id'])) {
        $__sid = (int)$__ri2['linked_recipe_id'];
        if (!in_array($__sid, $__sub_ids, true)) $__sub_ids[] = $__sid;
    }
}
if ($__sub_ids) {
    try {
        $__ph = implode(',', array_fill(0, count($__sub_ids), '?'));
        $__sub_stmt = $pdo->prepare("
            SELECT r.*,
                   (SELECT ROUND(AVG(rating),1) FROM recipe_ratings WHERE recipe_id = r.id) AS avg_rating,
                   (SELECT COUNT(*)             FROM recipe_ratings WHERE recipe_id = r.id) AS rating_count
            FROM recipes r
            WHERE r.id IN ($__ph) AND r.status = 'published'
            ORDER BY r.title ASC
        ");
        $__sub_stmt->execute($__sub_ids);
        $subrecipes = $__sub_stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { $subrecipes = []; }
}

// ---- Unit conversions: Original / Metric / US per ingredient ----
// For Metric/US we emit the full candidate set so the client can re-pick the
// friendliest unit at any scale. "Original" always stays as authored.
$unitData = [];
$anyConvert = false;
$bcrf_meaningful = function (array $c, string $origAbbr): bool {
    if (!$c) return false;
    if (count($c) === 1 && (string)$c[0][1] === $origAbbr) return false; // identity only -> nothing to switch to
    return true;
};
foreach ($recipe_ingredients as $idx => $ri) {
    $base = (float)$ri['quantity'];
    $uid  = (int)($ri['unit_id'] ?? 0);
    $sys  = $ri['unit_system'] ?? null; // 'Metric' | 'US' | null
    $origAbbr = (($ri['unit_abbr'] ?? '') !== '') ? (string)$ri['unit_abbr'] : (string)($ri['unit_name'] ?? '');

    $metricC = []; $usC = [];
    if ($uid) {
        try { $metricC = conversionCandidates($pdo, $uid, $origAbbr, $sys, 'Metric'); } catch (Throwable $e) { $metricC = []; }
        try { $usC     = conversionCandidates($pdo, $uid, $origAbbr, $sys, 'US');     } catch (Throwable $e) { $usC = []; }
    }
    if ($bcrf_meaningful($metricC, $origAbbr) || $bcrf_meaningful($usC, $origAbbr)) { $anyConvert = true; }

    $unitData[$idx] = [
        'origQty'  => $base,
        'origUnit' => $origAbbr,
        'metric'   => $metricC,
        'us'       => $usC,
    ];
}

// In-stock farm-stand items (live inventory schema) for ingredient matching.
$farmKeys = ['produce', 'bakery', 'baked-goods', 'baked goods', 'pantry'];
$ph = implode(',', array_fill(0, count($farmKeys), '?'));
$standItems = [];
try {
    $si = $pdo->prepare(
        "SELECT i.id, i.name, i.stock_quantity
         FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id
         WHERE i.is_active = 1 AND i.stock_quantity > 0
           AND (LOWER(ic.slug) IN ($ph) OR LOWER(ic.name) IN ($ph))"
    );
    $si->execute(array_merge($farmKeys, $farmKeys));
    $standItems = $si->fetchAll(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    $standItems = [];
}

$recImg = '';
foreach (['feature_image','image_url','featured_image','image','photo_url'] as $ik) { if (!empty($recipe[$ik])) { $recImg = $recipe[$ik]; break; } }
$recImgAbs = $recImg;
if ($recImgAbs && !preg_match('#^https?://#', $recImgAbs)) { $recImgAbs = 'https://batcaveroadfarm.com' . ($recImgAbs[0] === '/' ? '' : '/') . $recImgAbs; }

$pageTitle    = $recipe['title'] . " | Farmhouse Recipes | Bat Cave Road Farm";
$metaDesc     = !empty($recipe['description']) ? mb_substr(trim($recipe['description']),0,160) : ("Make " . $recipe['title'] . " at home — a farmhouse recipe from Bat Cave Road Farm in Old Fort, NC.");
$canonicalUrl = "https://batcaveroadfarm.com/recipes/" . $recipe['slug'] . "/";
$pageCSS      = "https://batcaveroadfarm.com/css/min.recipes.css";
$pageJS       = ["https://batcaveroadfarm.com/js/min.recipe.js", "https://batcaveroadfarm.com/js/min.share.js", "https://batcaveroadfarm.com/js/min.auth.js", "https://batcaveroadfarm.com/js/min.rating.js", "https://batcaveroadfarm.com/js/min.comments.js"];
$breadcrumbs = [
    ['label' => 'Recipes', 'url' => '/recipes/'],
    ['label' => $recipe['title']],
];
require_once '../includes/head.php';
require_once '../includes/header.php';
?>
<?php
$__instrPlain = preg_replace('#<br\s*/?>#i', "\n", (string)($recipe['instructions'] ?? ''));
$__instrPlain = preg_replace('#</(p|li|ol|ul|div|h[1-6])>#i', "\n", $__instrPlain);
$__instrPlain = strip_tags($__instrPlain);
$__steps = array_values(array_filter(array_map('trim', preg_split('/\r\n|\r|\n/', $__instrPlain)), fn($l) => $l !== ''));
$__prep  = (int)($recipe['prep_time'] ?? 0);
$__cook  = (int)($recipe['cook_time'] ?? 0);
$__total = $__prep + $__cook;
$__yield = trim((string)($recipe['recipe_yield'] ?? $recipe['servings'] ?? ''));
if ($__yield === '0') { $__yield = ''; }
elseif ($__yield !== '' && ctype_digit($__yield)) { $__yield .= ' servings'; }
$__cuisine  = trim((string)($recipe['cuisine'] ?? ''));
$__catNames = array_column($assigned_categories, 'name');
$__kw = array_values(array_filter(array_merge([trim((string)($recipe['difficulty'] ?? ''))], $__catNames), fn($v) => $v !== ''));
$__recipeSchema = array_filter([
    "@context" => "https://schema.org",
    "@type" => "Recipe",
    "name" => $recipe['title'],
    "url" => $canonicalUrl,
    "image" => $recImgAbs,
    "description" => $recipe['description'] ?? '',
    "author" => ["@type" => "Person", "name" => $recipe['author'] ?: "Bat Cave Road Farm"],
    "recipeCategory" => $__catNames,
    "recipeIngredient" => array_map(function ($ri) {
        $n = ((float)$ri['quantity'] <= 1.0) ? $ri['singular_name'] : $ri['plural_name'];
        return trim(((float)$ri['quantity']) . ' ' . (string)($ri['unit_name'] ?? '') . ' ' . $n);
    }, $recipe_ingredients),
    "recipeInstructions" => array_map(
        fn($i, $x) => ["@type" => "HowToStep", "name" => "Step " . ($i + 1), "text" => $x, "url" => $canonicalUrl . "#step-" . ($i + 1)],
        array_keys($__steps), $__steps
    ),
    "prepTime"    => $__prep  > 0 ? "PT{$__prep}M"  : null,
    "cookTime"    => $__cook  > 0 ? "PT{$__cook}M"  : null,
    "totalTime"   => $__total > 0 ? "PT{$__total}M" : null,
    "recipeYield" => $__yield !== '' ? $__yield : null,
    "keywords"    => $__kw ? implode(', ', $__kw) : null,
    "recipeCuisine" => $__cuisine !== '' ? $__cuisine : null,
    "publisher" => ["@id" => "https://batcaveroadfarm.com/#business"],
], fn($v) => $v !== null && $v !== '' && $v !== []);
if (!empty($recipe['date_published']) && $recipe['date_published'] !== '0000-00-00') { $__recipeSchema['datePublished'] = $recipe['date_published']; }
if ($__ratingCount > 0 && $__avgRating > 0) {
    $__recipeSchema['aggregateRating'] = [
        "@type" => "AggregateRating",
        "ratingValue" => (string)$__avgRating,
        "ratingCount" => $__ratingCount,
        "bestRating"  => "5",
        "worstRating" => "1",
    ];
}
$__cal = (int)($recipe['calories'] ?? 0);
if ($__cal > 0) {
    $__recipeSchema['nutrition'] = ["@type" => "NutritionInformation", "calories" => $__cal . " calories"];
}
// Individual recipe reviews (policy-safe: Recipe type only) from published, rated comments
$__reviews = [];
foreach ($comments as $__cm) {
    $__rt = (int)($__cm['user_rating'] ?? 0);
    if ($__rt < 1) continue;
    $__fn = trim((string)($__cm['first_name'] ?? ''));
    if ($__fn === '') continue;
    $__ln = trim((string)($__cm['last_name'] ?? ''));
    $__rev = [
        "@type"        => "Review",
        "author"       => ["@type" => "Person", "name" => $__fn . ($__ln !== '' ? ' ' . strtoupper(substr($__ln, 0, 1)) . '.' : '')],
        "reviewRating" => ["@type" => "Rating", "ratingValue" => (string)$__rt, "bestRating" => "5", "worstRating" => "1"],
    ];
    $__body = trim((string)($__cm['comment_text'] ?? ''));
    if ($__body !== '') { $__rev["reviewBody"] = $__body; }
    if (!empty($__cm['created_at'])) { $__ct = strtotime((string)$__cm['created_at']); if ($__ct) { $__rev["datePublished"] = date('c', $__ct); } }
    $__reviews[] = $__rev;
    if (count($__reviews) >= 5) break;
}
if ($__reviews) { $__recipeSchema['review'] = $__reviews; }

echo '<script type="application/ld+json">' . json_encode($__recipeSchema, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT) . '</script>';
?>

<?php
// ---- Match recipe ingredients to in-stock farm-stand items (generous, whole-word) ----
// Matching helpers come from includes/ingredient_match.php
$bagSvg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18 6h-2c0-2.21-1.79-4-4-4S8 3.79 8 6H6c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h12c1.1 0 2-.9 2-2V8c0-1.1-.9-2-2-2zm-6-2c1.1 0 2 .9 2 2h-4c0-1.1.9-2 2-2zm6 16H6V8h2v2c0 .55.45 1 1 1s1-.45 1-1V8h4v2c0 .55.45 1 1 1s1-.45 1-1V8h2v12z"/></svg>';
$recipeSvg = '<svg viewBox="0 0 24 24" fill="currentColor" aria-hidden="true" focusable="false"><path d="M21 5c-1.11-.35-2.33-.5-3.5-.5-1.95 0-4.05.4-5.5 1.5-1.45-1.1-3.55-1.5-5.5-1.5S2.45 4.9 1 6v14.66c0 .25.25.5.5.5.1 0 .15-.05.25-.05C3.1 20.45 5.05 20 6.5 20c1.95 0 4.05.4 5.5 1.5 1.35-.85 3.8-1.5 5.5-1.5 1.65 0 3.35.3 4.75 1.05.1.05.15.05.25.05.25 0 .5-.25.5-.5V6c-.6-.45-1.25-.75-2-1zm0 13.5c-1.1-.35-2.3-.5-3.5-.5-1.7 0-4.15.65-5.5 1.5V8c1.35-.85 3.8-1.5 5.5-1.5 1.2 0 2.4.15 3.5.5v11.5z"/></svg>';
$standMatches = [];
$anyStandMatch = false;
if (!empty($standItems)) {
    foreach ($standItems as $k => $it) { $standItems[$k]['__w'] = bcrf_words($it['name']); }
    foreach ($recipe_ingredients as $idx => $ri) {
        $cands = [];
        foreach ([$ri['singular_name'] ?? '', $ri['plural_name'] ?? ''] as $nm) {
            if ($nm === '') continue;
            $iw = bcrf_words($nm);
            if (!$iw) continue;
            foreach ($standItems as $it) {
                if (bcrf_words_match($iw, $it['__w'])) { $cands[(int)$it['id']] = $it; }
            }
        }
        if ($cands) {
            $cands = array_values($cands);
            usort($cands, fn($a, $b) => ((float)$b['stock_quantity']) <=> ((float)$a['stock_quantity']));
            $best = $cands[0];
            $bslug = createSlug((string)$best['name']) ?: 'item';
            $standMatches[$idx] = [
                'url'  => '/farm-stand/items/' . (int)$best['id'] . '/' . $bslug . '/',
                'name' => (string)$best['name'],
            ];
            $anyStandMatch = true;
        }
    }
}
?>


<?php
// ---- Share controls (Web Share API + Facebook / X / email / copy) ----
$svg = [
  'share' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18 16.08c-.76 0-1.44.3-1.96.77L8.91 12.7c.05-.23.09-.46.09-.7s-.04-.47-.09-.7l7.05-4.11c.54.5 1.25.81 2.04.81 1.66 0 3-1.34 3-3s-1.34-3-3-3-3 1.34-3 3c0 .24.04.47.09.7L8.04 9.81C7.5 9.31 6.79 9 6 9c-1.66 0-3 1.34-3 3s1.34 3 3 3c.79 0 1.5-.31 2.04-.81l7.12 4.16c-.05.21-.08.43-.08.65 0 1.61 1.31 2.92 2.92 2.92s2.92-1.31 2.92-2.92-1.31-2.92-2.92-2.92z"/></svg>',
  'fb' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M24 12.07C24 5.41 18.63 0 12 0S0 5.4 0 12.07C0 18.1 4.39 23.1 10.13 24v-8.44H7.08v-3.49h3.04V9.41c0-3.02 1.8-4.7 4.54-4.7 1.31 0 2.68.24 2.68.24v2.97h-1.5c-1.49 0-1.96.93-1.96 1.89v2.26h3.32l-.53 3.49h-2.8V24C19.62 23.1 24 18.1 24 12.07z"/></svg>',
  'x' => '<svg viewBox="0 0 24 24" width="18" height="18" fill="currentColor" aria-hidden="true" focusable="false"><path d="M18.244 2.25h3.308l-7.227 8.26 8.502 11.24h-6.66l-5.214-6.817L4.99 21.75H1.68l7.73-8.835L1.254 2.25H8.08l4.713 6.231 5.45-6.231zm-1.161 17.52h1.833L7.084 4.126H5.117l11.966 15.644z"/></svg>',
  'mail' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M20 4H4c-1.1 0-2 .9-2 2v12c0 1.1.9 2 2 2h16c1.1 0 2-.9 2-2V6c0-1.1-.9-2-2-2zm0 4l-8 5-8-5V6l8 5 8-5v2z"/></svg>',
  'link' => '<svg viewBox="0 0 24 24" width="20" height="20" fill="currentColor" aria-hidden="true" focusable="false"><path d="M3.9 12c0-1.71 1.39-3.1 3.1-3.1h4V7H7c-2.76 0-5 2.24-5 5s2.24 5 5 5h4v-1.9H7c-1.71 0-3.1-1.39-3.1-3.1zM8 13h8v-2H8v2zm9-6h-4v1.9h4c1.71 0 3.1 1.39 3.1 3.1s-1.39 3.1-3.1 3.1h-4V17h4c2.76 0 5-2.24 5-5s-2.24-5-5-5z"/></svg>',
];
$renderShare = function ($variant) use ($canonicalUrl, $recipe, $svg) {
    $u    = rawurlencode($canonicalUrl);
    $t    = rawurlencode($recipe['title']);
    $body = rawurlencode($recipe['title'] . ' — ' . $canonicalUrl);
    $hu   = htmlspecialchars($canonicalUrl);
    $ht   = htmlspecialchars($recipe['title']);
    $label = $variant === 'full' ? '<span class="share-label">Share this recipe</span>' : '';
    return '<div class="share-bar share-bar--' . $variant . '" data-share-url="' . $hu . '" data-share-title="' . $ht . '" data-share-type="recipe" role="group" aria-label="Share this recipe">'
        . $label
        . '<button type="button" class="share-btn share-native" data-share-native hidden aria-label="Share">' . $svg['share'] . '</button>'
        . '<a class="share-btn share-fb" href="https://www.facebook.com/sharer/sharer.php?u=' . $u . '" target="_blank" rel="noopener" data-share="facebook" aria-label="Share on Facebook">' . $svg['fb'] . '</a>'
        . '<a class="share-btn share-x" href="https://twitter.com/intent/tweet?url=' . $u . '&amp;text=' . $t . '" target="_blank" rel="noopener" data-share="x" aria-label="Share on X">' . $svg['x'] . '</a>'
        . '<a class="share-btn share-email" href="mailto:?subject=' . $t . '&amp;body=' . $body . '" data-share="email" aria-label="Share by email">' . $svg['mail'] . '</a>'
        . '<button type="button" class="share-btn share-copy" data-share-copy data-share="copy" aria-label="Copy link">' . $svg['link'] . '</button>'
        . '</div>';
};
?>
<div class="content-wrap">
  <div class="recipe-detail reveal">
    <div class="recipe-head">
      <div class="recipe-head-main">
        <?php if ($recImg !== ''): ?>
          <button type="button" class="recipe-img-btn" aria-label="View larger image">
            <img class="recipe-head-img" src="<?= htmlspecialchars($recImg) ?>" alt="<?= htmlspecialchars($recipe['title']) ?>" fetchpriority="high" decoding="async">
          </button>
        <?php endif; ?>
        <div class="recipe-head-titles">
          <?php if ($__cuisine !== '' || $assigned_categories): ?><div class="recipe-chips"><?php if ($__cuisine !== ''): ?><a class="recipe-chip recipe-chip--cuisine" href="/recipes/?cuisine=<?= urlencode($__cuisine) ?>"><?= htmlspecialchars($__cuisine) ?></a><?php endif; ?><?php foreach ($assigned_categories as $cat): ?><a class="recipe-chip" href="/recipes/?category=<?= (int)$cat['id'] ?>"><?= htmlspecialchars($cat['name']) ?></a><?php endforeach; ?></div><?php endif; ?>
          <h1><?= htmlspecialchars($recipe['title']) ?></h1>
          <?php if (!empty($recipe['author'])): ?><p class="recipe-byline">from the kitchen of <?= htmlspecialchars($recipe['author']) ?></p><?php endif; ?>
          <?= bcrf_rating_widget_html((int)$recipe['id'], $__avgRating, $__ratingCount) ?>
          <?php
            $__metaBits = [];
            if ($__prep  > 0) $__metaBits[] = 'Prep ' . $__prep . ' min';
            if ($__cook  > 0) $__metaBits[] = 'Cook ' . $__cook . ' min';
            if ($__total > 0 && $__prep > 0 && $__cook > 0) $__metaBits[] = 'Total ' . $__total . ' min';
            if ($__yield !== '') $__metaBits[] = $__yield;
            if ($__cal > 0) $__metaBits[] = $__cal . ' cal';
            $__diff = trim((string)($recipe['difficulty'] ?? ''));
            if ($__diff !== '') $__metaBits[] = ucfirst($__diff);
          ?>
          <?php if ($__metaBits): ?>
          <ul class="recipe-meta">
            <?php foreach ($__metaBits as $__b): ?><li><?= htmlspecialchars($__b) ?></li><?php endforeach; ?>
          </ul>
          <?php endif; ?>
          <?= $renderShare('compact') ?>
        </div>
      </div>
      <div class="recipe-head-tools">
        <div class="recipe-tool-row">
          <div class="recipe-scaler">
            <label for="recipeMultiplierSelector">Scale Serving Size</label>
            <select id="recipeMultiplierSelector">
              <option value="1">1× (Default)</option>
              <option value="2">2× (Double)</option>
              <option value="3">3× (Triple)</option>
              <option value="4">4× (Quadruple)</option>
            </select>
          </div>
<?php if ($anyConvert): ?>
          <div class="recipe-converter">
            <label for="recipeUnitSelector">Units</label>
            <select id="recipeUnitSelector">
              <option value="orig">Original</option>
              <option value="metric">Metric</option>
              <option value="us">US</option>
            </select>
          </div>
<?php endif; ?>
        </div>
        <button type="button" class="recipe-print-btn">🖨️ Print recipe</button>
      </div>
    </div>

    <div class="recipe-body">
      <aside class="recipe-ingredients">
        <h2>Ingredients</h2>
        <?php if ($anyStandMatch): ?>
          <p class="ing-legend"><span class="ing-bag-key" aria-hidden="true"><?= $bagSvg ?></span> <span>This means we have it fresh at our <a href="/farm-stand/inventory.php">farm stand</a> — tap to shop.</span></p>
        <?php endif; ?>
        <?php if (!empty($subrecipes)): ?>
          <p class="ing-legend ing-legend--recipe"><span class="ing-recipe-key" aria-hidden="true"><?= $recipeSvg ?></span> <span>This means we have a recipe for it — tap to open.</span></p>
        <?php endif; ?>
        <ul id="ingredientsList">
          <?php $__lastGroup = null; ?>
          <?php foreach ($recipe_ingredients as $idx => $ri): ?>
            <?php
              $__grp = trim((string)($ri['ingredient_group'] ?? ''));
              if ($__grp !== '' && $__grp !== $__lastGroup):
                  $__lastGroup = $__grp;
            ?>
            <li class="ingredient-group-head"><?= htmlspecialchars($__grp) ?></li>
            <?php endif; ?>
            <?php $ud = $unitData[$idx] ?? ['origQty'=>(float)$ri['quantity'],'origUnit'=>(string)($ri['unit_name'] ?? ''),'metric'=>[],'us'=>[]]; ?>
            <li class="ingredient-row"
                data-singular-title="<?= htmlspecialchars($ri['singular_name']) ?>"
                data-plural-title="<?= htmlspecialchars($ri['plural_name']) ?>"
                data-orig-qty="<?= htmlspecialchars((string)$ud['origQty']) ?>" data-orig-unit="<?= htmlspecialchars($ud['origUnit']) ?>"
                data-metric-cands="<?= htmlspecialchars(json_encode($ud['metric']), ENT_QUOTES) ?>"
                data-us-cands="<?= htmlspecialchars(json_encode($ud['us']), ENT_QUOTES) ?>">
              <span class="qty-display"><?= htmlspecialchars(formatRecipeQty((float)$ud['origQty'])) ?></span>
              <span class="unit"><?= htmlspecialchars($ud['origUnit']) ?></span>
              <span class="title-display"><?= formatIngredientName($ri['quantity'], $ri['singular_name'], $ri['plural_name']) ?></span>
              <?php if (trim((string)($ri['notes'] ?? '')) !== ''): ?><span class="ing-note"> (<?= htmlspecialchars(trim((string)$ri['notes'])) ?>)</span><?php endif; ?>
              <?php if (!empty($ri['__is_recipe']) && $ri['__recipe_slug'] !== ''): ?>
                <a class="ing-recipe" href="/recipes/<?= htmlspecialchars($ri['__recipe_slug']) ?>/" data-ga="recipe_to_subrecipe" title="We have a recipe for this" aria-label="View our recipe for <?= htmlspecialchars($ri['singular_name']) ?>"><?= $recipeSvg ?></a>
              <?php elseif (isset($standMatches[$idx])): ?>
                <a class="ing-bag" href="<?= htmlspecialchars($standMatches[$idx]['url'] . '?from=recipe&r=' . urlencode($slug)) ?>" data-ga="recipe_to_stand" title="Available at our farm stand" aria-label="Buy <?= htmlspecialchars($standMatches[$idx]['name']) ?> at our farm stand — in stock now"><?= $bagSvg ?></a>
              <?php endif; ?>
            </li>
          <?php endforeach; ?>
        </ul>
      </aside>
      <div class="recipe-steps">
        <h2>Preparation</h2>
        <?php if (count($__steps) > 1): ?>
        <ol class="instructions instructions-steps">
          <?php foreach ($__steps as $__i => $__s): ?>
            <li id="step-<?= $__i + 1 ?>"><?= $__s ?></li>
          <?php endforeach; ?>
        </ol>
        <?php else: ?>
        <div class="instructions"><?= $recipe['instructions'] ?></div>
        <?php endif; ?>
      </div>

    <div class="recipe-card-footer">
      <?php if (!empty($subrecipes)): ?>
      <section class="recipe-pairings recipe-subrecipes" aria-labelledby="subr-h">
        <h2 id="subr-h" class="recipe-pairings-title">Made With Our Recipes</h2>
        <div class="pairing-grid">
          <?php foreach ($subrecipes as $sr):
              $sImg = '';
              foreach (['feature_image','image_url','featured_image','image','photo_url'] as $ik) { if (!empty($sr[$ik])) { $sImg = $sr[$ik]; break; } }
              $sAvg = (float)($sr['avg_rating'] ?? 0); $sCnt = (int)($sr['rating_count'] ?? 0);
          ?>
          <a class="pairing-card" href="/recipes/<?= htmlspecialchars($sr['slug']) ?>/">
            <span class="pairing-thumb"<?= $sImg ? ' style="background-image:url(\'' . htmlspecialchars($sImg, ENT_QUOTES) . '\')"' : '' ?>><?= $sImg ? '' : '📖' ?></span>
            <span class="pairing-name"><?= htmlspecialchars($sr['title']) ?></span>
            <?php if ($sCnt > 0 && $sAvg > 0): ?>
            <span class="pairing-rating">★ <?= number_format($sAvg, 1) ?> <span class="pairing-rating-count">(<?= $sCnt ?>)</span></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <?php if (!empty($pairings)): ?>
      <section class="recipe-pairings" aria-labelledby="pairs-h">
        <h2 id="pairs-h" class="recipe-pairings-title">Pairs Well With</h2>
        <div class="pairing-grid">
          <?php foreach ($pairings as $pp):
              $pImg = '';
              foreach (['feature_image','image_url','featured_image','image','photo_url'] as $ik) { if (!empty($pp[$ik])) { $pImg = $pp[$ik]; break; } }
              $pAvg = (float)($pp['avg_rating'] ?? 0); $pCnt = (int)($pp['rating_count'] ?? 0);
          ?>
          <a class="pairing-card" href="/recipes/<?= htmlspecialchars($pp['slug']) ?>/">
            <span class="pairing-thumb"<?= $pImg ? ' style="background-image:url(\'' . htmlspecialchars($pImg, ENT_QUOTES) . '\')"' : '' ?>><?= $pImg ? '' : '🍽' ?></span>
            <span class="pairing-name"><?= htmlspecialchars($pp['title']) ?></span>
            <?php if ($pCnt > 0 && $pAvg > 0): ?>
            <span class="pairing-rating">★ <?= number_format($pAvg, 1) ?> <span class="pairing-rating-count">(<?= $pCnt ?>)</span></span>
            <?php endif; ?>
          </a>
          <?php endforeach; ?>
        </div>
      </section>
      <?php endif; ?>

      <div class="recipe-actions-row">
        <div class="recipe-actions-rate"><?= bcrf_rating_widget_html((int)$recipe['id'], $__avgRating, $__ratingCount) ?></div>
        <a class="recipe-comment-jump cta-btn" href="#comments">💬 Comments (<?= (int)$commentCount ?>)</a>
        <div class="recipe-actions-share"><?= $renderShare('full') ?></div>
      </div>
    </div>
    </div>

    <section class="recipe-comments" id="comments">
      <h2 class="recipe-comments-title">Comments <span class="comment-count-wrap">(<span id="commentCount"><?= (int)$commentCount ?></span>)</span></h2>

      <?php if (is_logged_in()): ?>
        <form id="commentForm" data-recipe-id="<?= (int)$recipe['id'] ?>" class="comment-form" novalidate>
          <div class="comment-msg" role="status" style="display:none"></div>
          <label class="comment-label" for="comment_text">Leave a comment</label>
          <textarea id="comment_text" name="comment_text" rows="4" maxlength="2000" placeholder="Share your thoughts or how it turned out…"></textarea>
          <div class="comment-form-foot">
            <span class="comment-counter" aria-hidden="true"></span>
            <button type="button" class="auth-btn comment-submit" data-comment-submit>Post Comment</button>
          </div>
          <p class="comment-note">Comments are reviewed before appearing — usually within a day.</p>
        </form>
      <?php else: ?>
        <div class="comment-login">
          <p>Log in to leave a comment.</p>
          <button type="button" class="auth-btn comment-login-btn" id="commentLoginBtn">Log In to Comment</button>
        </div>
      <?php endif; ?>

      <ul class="comment-list" id="commentList">
        <?php if (empty($comments)): ?>
          <li class="comment-empty">No comments yet — be the first!</li>
        <?php else: foreach ($comments as $__c): ?>
          <?= bcrf_render_comment($__c) ?>
        <?php endforeach; endif; ?>
      </ul>
    </section>
    <a class="recipe-back cta-btn" href="/recipes/">← Back to all recipes</a>
    <div class="recipe-print-footer" aria-hidden="true">Recipe from Bat Cave Road Farm - batcaveroadfarm.com</div>
  </div>
</div>

<?php if ($recImg !== ''): ?>
<div class="recipe-lightbox" id="recipeLightbox" role="dialog" aria-modal="true" aria-label="<?= htmlspecialchars($recipe['title']) ?>">
  <button type="button" class="recipe-lightbox-close" aria-label="Close">&times;</button>
  <img src="<?= htmlspecialchars($recImg) ?>" alt="<?= htmlspecialchars($recipe['title']) ?>" loading="lazy" decoding="async">
</div>
<?php endif; ?>

<?php
require_once '../includes/related.php';
$__kwR = bcrf_rel_keywords((string)($recipe['title'] ?? ''), array_merge($__catNames ?? [], [(string)($__cuisine ?? '')]));
$__cardsR = [];
foreach (bcrf_related_posts($pdo, $__kwR, 2) as $__c) { $__c['kind'] = 'Journal'; $__cardsR[] = $__c; }
echo bcrf_related_render('From the journal', $__cardsR);
?>
<?php require_once '../includes/auth_modals.php'; ?>
<?php require_once '../includes/footer.php'; ?>
