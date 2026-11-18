<?php
// includes/low_stock.php — the one "low on stock" rule (Nov 18, 2026 cleanup).
// Used by the dashboard (admin/data/loader.php), the Action Center / Owners' morning email (includes/action_center.php),
// the bell (includes/notifications.php), the menu badge (includes/nav_badges.php) and the 8 AM low-stock email
// (admin/handlers/check_stock_alerts.php). Before, each kept its own copy of this SQL.
// Rule: an active product whose stock is counted (track_inventory = 1; gift cards, admissions and other untracked items
// sit at 0) and that has its own minimum above 0, with stock at or below that minimum. Same as Stock › Low only.
if (!function_exists('bcrf_low_stock_where')) {

/** SQL condition for "low on stock" on the inventory table. $alias: table alias with no dot ('' for none). */
function bcrf_low_stock_where(string $alias = ''): string {
    $a = $alias === '' ? '' : $alias . '.';
    return "{$a}is_active = 1 AND {$a}track_inventory = 1 AND {$a}min_stock_level IS NOT NULL AND {$a}min_stock_level > 0"
         . " AND {$a}stock_quantity <= {$a}min_stock_level";
}

/** Low-stock products, lowest stock first: rows of cat, name, stock_quantity, min_stock_level. $extraWhere is trusted SQL. */
function bcrf_low_stock_items(PDO $pdo, string $extraWhere = ''): array {
    return $pdo->query("SELECT category AS cat, name, stock_quantity, min_stock_level FROM inventory WHERE " . bcrf_low_stock_where()
                       . ($extraWhere !== '' ? ' AND ' . $extraWhere : '') . " ORDER BY stock_quantity ASC")->fetchAll(PDO::FETCH_ASSOC);
}

}
