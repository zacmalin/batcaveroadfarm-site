<?php
// admin/migrations/0076_garden_shapes_pictures.php — Garden: round and triangle beds, plant pictures on the map (Nov 19, 2026;
//   includes/garden_planner.php, includes/garden_plant_art.php, admin/garden/areas.php, admin/garden/crops.php, admin/assets/garden.js).
//   garden_growing_locations.shape   rect | round | triangle; length × width is the box it fits in (round = circle or oval,
//                                    triangle = point at the far end). Growing area = box × 1, π/4 or 1/2.
//   garden_crop_settings.picture     the crop's own map picture (/img/garden/plants/crop-…webp); NULL = built-in drawing or emoji
// Safe to repeat.
return function (PDO $pdo): void {
    $col = function (string $t, string $c) use ($pdo): bool {
        $st = $pdo->prepare("SELECT COUNT(*) FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ?");
        $st->execute([$t, $c]);
        return (int)$st->fetchColumn() > 0;
    };
    if (!$col('garden_growing_locations', 'shape')) $pdo->exec("ALTER TABLE garden_growing_locations ADD COLUMN shape ENUM('rect','round','triangle') NOT NULL DEFAULT 'rect' COMMENT 'map shape inside length × width' AFTER color");
    if (!$col('garden_crop_settings', 'picture')) $pdo->exec("ALTER TABLE garden_crop_settings ADD COLUMN picture VARCHAR(255) NULL COMMENT 'map picture' AFTER notes");
};
