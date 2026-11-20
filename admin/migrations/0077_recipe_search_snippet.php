<?php
// admin/migrations/0077_recipe_search_snippet.php — Recipe search snippets (Nov 20, 2026; recipes/recipe_detail.php).
//   recipes.search_title        what Google shows as the blue link (about 60 characters). Empty = "<Title> Recipe | farm name".
//   recipes.search_description  the grey text under it (about 155 characters). Empty = time + servings + the description.
// Fills both for the two recipes Search Console flagged (Creamy Poblano Chicken: 1,129 impressions at 0.9% clicked;
// Sauerkraut Coleslaw: "sauerkraut coleslaw" / "sauerkraut slaw recipe" at positions 5-8) and adds cook's notes to the
// slaw's description, which the recipe page now shows under the title. Only fills empty fields. Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = 'recipes' AND column_name = ?");
        $st->execute([$c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$col('search_title'))       $pdo->exec("ALTER TABLE recipes ADD COLUMN search_title VARCHAR(70) NULL COMMENT 'Google title, ~60 chars' AFTER description");
    if (!$col('search_description')) $pdo->exec("ALTER TABLE recipes ADD COLUMN search_description VARCHAR(170) NULL COMMENT 'Google description, ~155 chars' AFTER search_title");

    $set = $pdo->prepare("UPDATE recipes SET
                              search_title       = IF(search_title IS NULL OR search_title = '', ?, search_title),
                              search_description = IF(search_description IS NULL OR search_description = '', ?, search_description)
                          WHERE slug = ?");
    $set->execute(['Creamy Poblano Chicken (Pollo con Crema) – 35-Minute Skillet',
                   'Golden chicken in a silky Mexican crema sauce with fire-roasted poblano rajas and sweet corn. One skillet, 35 minutes, serves 4. Smoky and mild.',
                   'pollo-poblano-creamy-poblano-chicken']);
    $set->execute(['Tangy Sauerkraut Coleslaw | Easy Sauerkraut Slaw Recipe',
                   'Crunchy cabbage and tangy sauerkraut in a creamy cider-mustard dressing. 15 minutes, no cooking, serves 8. Made for brats, Reubens and pulled pork.',
                   'tangy-sauerkraut-coleslaw']);

    $notes = '<p><strong>Why put sauerkraut in coleslaw?</strong> The kraut brings a fermented tang plain vinegar can’t, so this sauerkraut slaw tastes bright and stays crunchy for days instead of going limp.</p>'
           . '<p><strong>Cook’s notes:</strong> Squeeze the sauerkraut hard in a clean towel first. Extra brine is what makes slaw soupy. '
           . 'Want a vinegar slaw instead? Skip the mayo and whisk 3 tablespoons of olive oil into the vinegar, mustard and honey. '
           . 'Refrigerated sauerkraut gives the brightest flavor; canned works fine but tastes milder.</p>'
           . '<p><strong>Serve it with</strong> brats, Reubens, pulled pork, fish tacos, or piled right on a hot dog. It keeps 3 days in the fridge and honestly tastes best on day two.</p>';
    $pdo->prepare("UPDATE recipes SET description = CONCAT(COALESCE(description, ''), ?)
                   WHERE slug = 'tangy-sauerkraut-coleslaw' AND COALESCE(description, '') NOT LIKE '%Squeeze the sauerkraut%'")
        ->execute([$notes]);
};
