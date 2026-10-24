<?php
// admin/print_label.php
// PLS451 — 3"×3" labels, 6 per letter sheet (2 cols × 3 rows)
// Positions match standard 2×3 label sheet layout.

require_once '../config.php';
if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
// Staff only — public customer accounts also have user_id, so check the role.
if (empty($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['administrator', 'moderator', 'Shopkeep', 'Media'], true)) { http_response_code(403); exit('Access denied.'); }
date_default_timezone_set('America/New_York');

$item_id   = filter_input(INPUT_GET, 'item_id',   FILTER_VALIDATE_INT);
$copies    = max(1, min(60, (int)($_GET['copies']    ?? 1)));
$start_pos = max(1, min(6,  (int)($_GET['start_pos'] ?? 1)));
$best_by   = trim($_GET['best_by'] ?? '');

if (!$item_id) { http_response_code(400); exit('Invalid item ID.'); }

// ── ENSURE SKU COLUMN EXISTS (print_label.php is standalone) ─────────────────
try { $pdo->exec("ALTER TABLE inventory ADD COLUMN sku VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $ignored) {}

// ── FETCH ITEM ────────────────────────────────────────────────────────────────
try {
    $stmt = $pdo->prepare("
        SELECT i.id, i.name, i.size_value, i.size_unit_id,
               i.sku,
               COALESCE(u.name,'') AS unit_name
        FROM   inventory i
        LEFT JOIN measurement_units u ON i.size_unit_id = u.id
        WHERE  i.id = ? AND i.is_active = 1
    ");
    $stmt->execute([$item_id]);
    $item = $stmt->fetch(PDO::FETCH_ASSOC);
} catch (Throwable $e) {
    exit('<p style="color:red;font-family:sans-serif;padding:20px;">DB error: ' . htmlspecialchars($e->getMessage()) . '</p>');
}
if (!$item) { http_response_code(404); exit('Item not found.'); }

// ── AUTO-GENERATE SKU if item was saved before barcode system existed ─────────
if (empty($item['sku'])) {
    try {
        $max_n   = $pdo->query("SELECT MAX(CAST(SUBSTRING(sku, 5) AS UNSIGNED)) FROM inventory WHERE sku LIKE 'BCF-%'")->fetchColumn();
        $new_sku = 'BCF-' . str_pad((int)$max_n + 1, 3, '0', STR_PAD_LEFT);
        $pdo->prepare("UPDATE inventory SET sku = ? WHERE id = ? AND (sku IS NULL OR sku = '')")
            ->execute([$new_sku, $item['id']]);
        $item['sku'] = $new_sku;
    } catch (Throwable $e) {
        error_log('[Label] SKU auto-generate failed: ' . $e->getMessage());
    }
}

// ── FETCH INGREDIENTS ─────────────────────────────────────────────────────────
$ingredients = [];
try {
    $stmt = $pdo->prepare("
        SELECT ing.singular_name
        FROM   product_ingredients pi
        JOIN   ingredients ing ON pi.ingredient_id = ing.id
        WHERE  pi.product_type = 'inventory' AND pi.product_id = ?
        ORDER  BY ing.singular_name ASC
    ");
    $stmt->execute([$item_id]);
    $ingredients = $stmt->fetchAll(PDO::FETCH_COLUMN);
} catch (Throwable $e) {}

// ── BUILD DISPLAY STRINGS ─────────────────────────────────────────────────────
$name_esc  = htmlspecialchars($item['name'], ENT_QUOTES);
$sku_esc   = htmlspecialchars($item['sku'] ?? '', ENT_QUOTES);
$size_num  = $item['size_value'] ? (float)$item['size_value'] : '';
$size_unit = $item['unit_name'] ?? '';
// Build a single "8 oz" string so no flex/span issues
$size_str  = trim(($size_num !== '' ? $size_num : '') . ($size_unit ? ' ' . $size_unit : ''));
$size_esc  = htmlspecialchars($size_str ?: '—', ENT_QUOTES);
$ing_str   = !empty($ingredients)
    ? htmlspecialchars(implode(', ', $ingredients), ENT_QUOTES)
    : $name_esc;
$best_str  = '';
if ($best_by) {
    $ts = strtotime($best_by);
    if ($ts) $best_str = date('M j, Y', $ts);
}

// ── GRID LAYOUT ───────────────────────────────────────────────────────────────
// PLS451: 2 cols × 3 rows on 8.5"×11" letter
// Horizontal: 0.75" left margin | 3" label | 1" gap | 3" label | 0.75" right
// Vertical:   0.5"  top margin  | 3" label | 0.5" gap | 3" | 0.5" gap | 3" | 0.5" bottom
$positions = [
    ['x' => '0.75in', 'y' => '0.5in' ],  // pos 1: col1 row1
    ['x' => '4.75in', 'y' => '0.5in' ],  // pos 2: col2 row1
    ['x' => '0.75in', 'y' => '4in'   ],  // pos 3: col1 row2
    ['x' => '4.75in', 'y' => '4in'   ],  // pos 4: col2 row2
    ['x' => '0.75in', 'y' => '7.5in' ],  // pos 5: col1 row3
    ['x' => '4.75in', 'y' => '7.5in' ],  // pos 6: col2 row3
];
$per_page = 6;

// Build pages: each page is an array of 6 slots (null = blank, true = print label)
$pages         = [];
$slot          = $start_pos - 1; // current slot across all pages
$placed        = 0;
$total_slots   = $slot + $copies;
$total_pages   = (int)ceil($total_slots / $per_page);

for ($p = 0; $p < $total_pages; $p++) {
    $page_slots = [];
    for ($s = 0; $s < $per_page; $s++) {
        $global_slot = $p * $per_page + $s;
        $is_label    = ($global_slot >= $slot && $placed < $copies);
        $page_slots[] = $is_label;
        if ($is_label) $placed++;
    }
    $pages[] = $page_slots;
}
?>
<!doctype html>
<html lang="en">
<head>
    <meta charset="utf-8">
    <title>Labels — <?= $name_esc ?></title>
    <script src="https://cdnjs.cloudflare.com/ajax/libs/jsbarcode/3.11.6/JsBarcode.all.min.js"></script>
    <style>
        @page {
            size: 8.5in 11in;
            margin: 0;
        }
        * { box-sizing: border-box; margin: 0; padding: 0; }

        body { background: #fff; }

        .sheet {
            position:   relative;
            width:      8.5in;
            height:     11in;
            page-break-after: always;
            overflow:   hidden;
        }
        .sheet:last-child { page-break-after: avoid; }

        /* ── INDIVIDUAL LABEL ── */
        .label {
            position:   absolute;
            width:      3in;
            height:     3in;
            overflow:   hidden;
            background: #fff;
            -webkit-print-color-adjust: exact;
            print-color-adjust: exact;
            font-family: Georgia, serif;
        }

        /* Top-left: Logo 2"×2" */
        .l-logo {
            position: absolute;
            left: 0; top: 0;
            width: 2in; height: 2in;
        }
        .l-logo img {
            width: 100%; height: 100%;
            object-fit: contain;
            display: block;
        }

        /* Bottom-left: Product name 2"×1" */
        .l-name {
            position:   absolute;
            left: 0;    top: 2in;
            width: 2in; height: 1in;
            background: #322016;
            color:      #e6cca4;
            display:    flex;
            align-items: center;
            justify-content: center;
            text-align: center;
            font-family: Georgia, serif;
            font-weight: bold;
            font-size:  9pt;
            line-height: 1.2;
            padding:    5px;
        }

        /* Right column */
        .l-right {
            position: absolute;
            right: 0; top: 0;
            width: 1in; height: 3in;
            border-left: 0.5pt solid #ccc;
        }

        /* Rotated info: top 1"×2" */
        .l-info-wrap {
            position: absolute;
            top: 0; left: 0;
            width: 1in; height: 2in;
            overflow: hidden;
        }
        .l-info-inner {
            position: absolute;
            left: 1in; top: 0;
            width: 2in;
            transform: rotate(90deg);
            transform-origin: top left;
            padding: 4pt 3pt;
            font-family: Arial, Helvetica, sans-serif;
            color: #111;
        }
        .l-url  { font-size: 6.5pt; font-weight: bold; color: #322016; margin-bottom: 2pt; }
        .l-ing  { font-size: 5pt; line-height: 1.4; word-break: break-word; }
        .l-ing strong { font-size: 5.5pt; display: block; margin-bottom: 1pt; }
        .l-bestby { font-size: 5.5pt; font-weight: bold; margin-top: 3pt; }

        /* Divider */
        .l-div {
            position: absolute;
            left: 0; top: 2in;
            width: 1in;
            border-top: 0.5pt solid #ccc;
        }

        /* Bottom-right 1"×1": size text + optional barcode, stacked in one container */
        .l-bottom {
            position: absolute;
            bottom: 0; left: 0;
            width: 1in; height: 1in;
            display: flex;
            flex-direction: column;
            align-items: center;
            justify-content: center;
            text-align: center;
            padding: 4pt;
            box-sizing: border-box;
            gap: 3pt;
        }
        .l-bottom-size {
            font-family: Arial, Helvetica, sans-serif;
            font-size: 8pt;
            font-weight: bold;
            color: #222;
            white-space: nowrap;
            flex-shrink: 0;
        }
        .l-bottom-barcode {
            width: 100%;
            flex: 1;
            min-height: 0;
            display: flex;
            align-items: center;
            justify-content: center;
        }
        .l-bottom-barcode svg {
            width: 100%;
            height: auto;
            display: block;
        }
    </style>
</head>
<body>

<?php foreach ($pages as $page_slots): ?>
<div class="sheet">
    <?php foreach ($page_slots as $idx => $print_it):
        if (!$print_it) continue;
        $pos = $positions[$idx];
    ?>
    <div class="label" style="left:<?= $pos['x'] ?>;top:<?= $pos['y'] ?>;">

        <div class="l-logo">
            <img src="https://batcaveroadfarm.com/img/logos/print_square_logo.png"
                 alt="Bat Cave Road Farm">
        </div>

        <div class="l-name"><?= $name_esc ?></div>

        <div class="l-right">
            <div class="l-info-wrap">
                <div class="l-info-inner">
                    <div class="l-url">batcaveroadfarm.com</div>
                    <div class="l-ing">
                        <strong>Ingredients:</strong><?= $ing_str ?>
                    </div>
                    <?php if ($best_str): ?>
                    <div class="l-bestby">Best By: <?= htmlspecialchars($best_str) ?></div>
                    <?php endif; ?>
                </div>
            </div>
            <div class="l-div"></div>

            <!-- Bottom: size text + optional barcode in one stacked container -->
            <div class="l-bottom">
                <div class="l-bottom-size"><?= $size_esc ?></div>
                <?php if ($sku_esc): ?>
                <div class="l-bottom-barcode">
                    <svg class="barcode-svg" data-sku="<?= $sku_esc ?>"></svg>
                </div>
                <?php endif; ?>
            </div>
        </div>

    </div>
    <?php endforeach; ?>
</div>
<?php endforeach; ?>

<!-- Print button bar — visible on screen, hidden in print -->
<div id="printBar" style="position:fixed;top:0;left:0;right:0;z-index:999;background:#1c1917;color:#fff;padding:10px 20px;display:flex;align-items:center;justify-content:space-between;font-family:Arial,sans-serif;font-size:12px;">
    <span style="font-weight:bold;">
        <?= $copies ?> label<?= $copies !== 1 ? 's' : '' ?> — <?= $name_esc ?>
        <?php if ($sku_esc): ?><span style="color:#6ee7b7;margin-left:8px;">SKU: <?= $sku_esc ?></span><?php endif; ?>
    </span>
    <button onclick="window.print()" style="background:#16a34a;color:#fff;border:none;padding:8px 24px;border-radius:4px;font-weight:bold;font-size:13px;cursor:pointer;letter-spacing:0.05em;">
        🖨 Print Labels
    </button>
</div>
<!-- Spacer so first label isn't hidden under the bar -->
<div style="height:44px;" class="no-print"></div>

<script>
// Render barcodes on page load so they're visible in the preview
window.onload = function() {
    document.querySelectorAll('.barcode-svg[data-sku]').forEach(function(svg) {
        var sku = svg.getAttribute('data-sku');
        if (!sku) return;
        try {
            JsBarcode(svg, sku, {
                format:       'CODE128',
                lineColor:    '#000',
                background:   '#ffffff',
                width:        1.1,
                height:       26,
                displayValue: true,
                fontSize:     7,
                margin:       1,
                textMargin:   1
            });
        } catch(e) { console.warn('Barcode render failed:', sku, e); }
    });
};
</script>
<style>
    @media print { #printBar, .no-print { display: none !important; } }
</style>
</body>
</html>
