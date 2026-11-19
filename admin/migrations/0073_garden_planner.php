<?php
// admin/migrations/0073_garden_planner.php — Garden bed planner (Nov 19, 2026; includes/garden_planner.php, admin/garden/areas.php).
//   garden_growing_locations  what each bed is like: which way it runs, sun, watering, ground cover, soil type, notes.
//   garden_plantings          expected_end_on (when the crop should be out of the bed, typed or worked out from days to
//                             maturity) and ended_on (when it really came out: Done or Failed). Together they give each
//                             planting a time span, so the map can show any season and catch plantings that overlap.
//   ended_on backfill         Done/Failed plantings take the date of their Done/Failed step, else their last harvest.
//   garden_crops              common vegetables, herbs and flowers with their families (for rotation and map colors).
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    $adds = [
        'garden_growing_locations' => [
            'orientation'  => "ENUM('n_s','e_w','ne_sw','nw_se') NULL COMMENT 'which way the bed or row runs' AFTER rotation_degrees",
            'sun_exposure' => "ENUM('full_sun','part_sun','part_shade','full_shade') NULL AFTER orientation",
            'irrigation'   => "ENUM('none','hand','drip','soaker','sprinkler','other') NULL AFTER sun_exposure",
            'ground_cover' => "ENUM('bare','landscape_fabric','plastic_mulch','straw','wood_chips','compost','cover_crop','other') NULL AFTER irrigation",
            'soil_type'    => "ENUM('clay','clay_loam','loam','sandy_loam','sandy','silt','raised_bed_mix','potting_mix','other') NULL AFTER ground_cover",
            'notes'        => "VARCHAR(2000) NULL AFTER soil_type",
        ],
        'garden_plantings' => [
            'expected_end_on' => "DATE NULL COMMENT 'when it should be out of the bed' AFTER allocated_area_sq_m",
            'ended_on'        => "DATE NULL COMMENT 'when it came out (Done or Failed)' AFTER expected_end_on",
        ],
    ];
    foreach ($adds as $t => $cols) foreach ($cols as $c => $def) if (!$col($t, $c)) $pdo->exec("ALTER TABLE `$t` ADD COLUMN `$c` $def");
    $idx = $pdo->query("SELECT COUNT(*) FROM information_schema.statistics WHERE table_schema = DATABASE() AND table_name = 'garden_plantings' AND index_name = 'idx_planting_location_dates'")->fetchColumn();
    if (!(int)$idx) $pdo->exec('ALTER TABLE garden_plantings ADD KEY idx_planting_location_dates (organization_id, location_id, planted_on)');

    // Closed plantings: when they came out of the bed.
    $pdo->exec("UPDATE garden_plantings p SET p.ended_on = (
                    SELECT MAX(e.occurred_on) FROM garden_planting_lifecycle_events e
                    WHERE e.planting_id = p.id AND e.organization_id = p.organization_id AND e.event_type IN ('completed','failed'))
                WHERE p.status IN ('completed','failed') AND p.ended_on IS NULL");
    $pdo->exec("UPDATE garden_plantings p SET p.ended_on = (
                    SELECT MAX(h.harvested_on) FROM garden_planting_harvest_events h
                    WHERE h.planting_id = p.id AND h.organization_id = p.organization_id)
                WHERE p.status IN ('completed','failed') AND p.ended_on IS NULL");
    $pdo->exec("UPDATE garden_plantings SET ended_on = planted_on WHERE status IN ('completed','failed') AND ended_on IS NULL");

    // Common crops and their families. Days are a typical middle; each farm can set its own in Crops.
    $crops = [
        // name, scientific, family, lifecycle, days to maturity, basis
        ['Tomato', 'Solanum lycopersicum', 'Solanaceae', 'annual', 75, 'from_transplant'],
        ['Pepper', 'Capsicum annuum', 'Solanaceae', 'annual', 70, 'from_transplant'],
        ['Eggplant', 'Solanum melongena', 'Solanaceae', 'annual', 70, 'from_transplant'],
        ['Potato', 'Solanum tuberosum', 'Solanaceae', 'annual', 90, 'from_sowing'],
        ['Cabbage', 'Brassica oleracea var. capitata', 'Brassicaceae', 'annual', 70, 'from_transplant'],
        ['Broccoli', 'Brassica oleracea var. italica', 'Brassicaceae', 'annual', 60, 'from_transplant'],
        ['Kale', 'Brassica oleracea var. sabellica', 'Brassicaceae', 'annual', 55, 'from_sowing'],
        ['Collards', 'Brassica oleracea var. viridis', 'Brassicaceae', 'annual', 60, 'from_sowing'],
        ['Radish', 'Raphanus sativus', 'Brassicaceae', 'annual', 28, 'from_sowing'],
        ['Turnip', 'Brassica rapa subsp. rapa', 'Brassicaceae', 'annual', 45, 'from_sowing'],
        ['Cucumber', 'Cucumis sativus', 'Cucurbitaceae', 'annual', 55, 'from_sowing'],
        ['Summer squash', 'Cucurbita pepo', 'Cucurbitaceae', 'annual', 50, 'from_sowing'],
        ['Winter squash', 'Cucurbita maxima', 'Cucurbitaceae', 'annual', 100, 'from_sowing'],
        ['Pumpkin', 'Cucurbita pepo var. pepo', 'Cucurbitaceae', 'annual', 110, 'from_sowing'],
        ['Watermelon', 'Citrullus lanatus', 'Cucurbitaceae', 'annual', 85, 'from_sowing'],
        ['Bean', 'Phaseolus vulgaris', 'Fabaceae', 'annual', 55, 'from_sowing'],
        ['Pea', 'Pisum sativum', 'Fabaceae', 'annual', 60, 'from_sowing'],
        ['Carrot', 'Daucus carota subsp. sativus', 'Apiaceae', 'annual', 70, 'from_sowing'],
        ['Parsley', 'Petroselinum crispum', 'Apiaceae', 'biennial', 75, 'from_sowing'],
        ['Dill', 'Anethum graveolens', 'Apiaceae', 'annual', 45, 'from_sowing'],
        ['Cilantro', 'Coriandrum sativum', 'Apiaceae', 'annual', 50, 'from_sowing'],
        ['Beet', 'Beta vulgaris', 'Amaranthaceae', 'annual', 55, 'from_sowing'],
        ['Swiss chard', 'Beta vulgaris subsp. vulgaris', 'Amaranthaceae', 'annual', 55, 'from_sowing'],
        ['Spinach', 'Spinacia oleracea', 'Amaranthaceae', 'annual', 40, 'from_sowing'],
        ['Onion', 'Allium cepa', 'Amaryllidaceae', 'annual', 100, 'from_transplant'],
        ['Garlic', 'Allium sativum', 'Amaryllidaceae', 'annual', 240, 'from_sowing'],
        ['Leek', 'Allium ampeloprasum', 'Amaryllidaceae', 'annual', 110, 'from_transplant'],
        ['Sweet corn', 'Zea mays', 'Poaceae', 'annual', 80, 'from_sowing'],
        ['Okra', 'Abelmoschus esculentus', 'Malvaceae', 'annual', 60, 'from_sowing'],
        ['Sweet potato', 'Ipomoea batatas', 'Convolvulaceae', 'annual', 100, 'from_transplant'],
        ['Sunflower', 'Helianthus annuus', 'Asteraceae', 'annual', 75, 'from_sowing'],
        ['Zinnia', 'Zinnia elegans', 'Asteraceae', 'annual', 70, 'from_sowing'],
        ['Mint', 'Mentha spicata', 'Lamiaceae', 'perennial', null, 'unknown'],
        ['Strawberry', 'Fragaria × ananassa', 'Rosaceae', 'perennial', null, 'unknown'],
        ['Blueberry', 'Vaccinium corymbosum', 'Ericaceae', 'perennial', null, 'unknown'],
    ];
    $c = $pdo->prepare("INSERT INTO garden_crops (common_name, scientific_name, crop_family, lifecycle) VALUES (?, ?, ?, ?)
                        ON DUPLICATE KEY UPDATE crop_family = COALESCE(crop_family, VALUES(crop_family))");
    $v = $pdo->prepare("INSERT INTO garden_varieties (crop_id, name, days_to_maturity, maturity_basis)
                        SELECT id, 'Generic / unspecified', ?, ? FROM garden_crops WHERE scientific_name = ?
                        ON DUPLICATE KEY UPDATE days_to_maturity = COALESCE(days_to_maturity, VALUES(days_to_maturity))");
    foreach ($crops as [$name, $sci, $fam, $life, $days, $basis]) {
        $c->execute([$name, $sci, $fam, $life]);
        $v->execute([$days, $basis, $sci]);
    }
    // The three starter crops from 0072 get typical days too.
    foreach ([['Ocimum basilicum', 60, 'from_sowing'], ['Lactuca sativa', 45, 'from_sowing']] as [$sci, $days, $basis]) $v->execute([$days, $basis, $sci]);
};
