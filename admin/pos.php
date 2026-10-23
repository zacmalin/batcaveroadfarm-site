<?php
// admin/pos.php - Resilient Dedicated Touchscreen Register Interface Console
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/audit.php';
date_default_timezone_set('America/New_York');

// ── AJAX: Stock quantity refresh ──────────────────────────────────────────────
if (isset($_GET['ajax_stock'])) {
    header('Content-Type: application/json');
    $stock = [];
    try {
        $s = $pdo->query("SELECT id, stock_quantity FROM inventory WHERE is_active = 1");
        foreach ($s->fetchAll(PDO::FETCH_ASSOC) as $row)
            $stock[(int)$row['id']] = (int)$row['stock_quantity'];
    } catch (Throwable $e) {}
    echo json_encode($stock); exit;
}

// ── AJAX: Today's sales stats refresh ────────────────────────────────────────
if (isset($_GET['ajax_today_stats'])) {
    header('Content-Type: application/json');
    $stats = ['cnt' => 0, 'rev' => '0.00'];
    try {
        $row = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS rev
                            FROM pos_receipts WHERE DATE(created_at) = CURDATE()")
                   ->fetch(PDO::FETCH_ASSOC);
        if ($row) $stats = ['cnt' => (int)$row['cnt'], 'rev' => number_format((float)$row['rev'], 2)];
    } catch (Throwable $e) {}
    echo json_encode($stats); exit;
}

if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

$current_user_id = (int)$_SESSION['user_id'];
$current_role    = $_SESSION['user_role'] ?? 'user';
if ($current_role === 'Media') {
    die("<div class='p-6 text-red-700 font-mono text-xs'>Security Exception: Access Blocked for [ Media ].</div>");
}

// ── SHOPKEEP IDENTITY ─────────────────────────────────────────────────────────
$shopkeep_display_tag = 'Staff';
try {
    $stmt_user = $pdo->prepare("SELECT first_name, last_name FROM users WHERE id = ?");
    $stmt_user->execute([$current_user_id]);
    $user_meta = $stmt_user->fetch(PDO::FETCH_ASSOC);
    $first     = $user_meta['first_name'] ?? 'Staff';
    $last_i    = isset($user_meta['last_name']) ? substr($user_meta['last_name'], 0, 1) . '.' : '';
    $shopkeep_display_tag = htmlspecialchars($first . ' ' . $last_i);
} catch (Throwable $e) {
    error_log('[POS] Shopkeep identity fetch failed: ' . $e->getMessage());
}

// ── INVENTORY CATALOGUE ───────────────────────────────────────────────────────
$categories    = [];
$catalogueData = [];
try {
    $stmt = $pdo->query("
        SELECT i.id, i.name, i.price,
               COALESCE(ic.name, i.category, 'Uncategorized') AS category,
               i.image_url, i.stock_quantity, i.sku
        FROM   inventory i
        LEFT JOIN inventory_categories ic ON i.category_id = ic.id
        WHERE  i.is_active = 1
        ORDER BY COALESCE(ic.name, i.category) ASC, i.name ASC
    ");
    $all_items = $stmt->fetchAll(PDO::FETCH_ASSOC);
    foreach ($all_items as $item) {
        $cat = $item['category'] ?? 'Uncategorized';
        if (!in_array($cat, $categories)) {
            $categories[]        = $cat;
            $catalogueData[$cat] = [];
        }
        $item['tbl'] = 'inventory';
        $catalogueData[$cat][] = $item;
    }
} catch (Throwable $e) {
    error_log('[POS] Inventory catalogue fetch failed: ' . $e->getMessage());
}

$json_catalogue_payload  = json_encode($catalogueData,            JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$json_categories         = json_encode($categories,               JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);
$grocery_tax_categories  = ['Produce','Bakery','Pantry','Baked Goods','Fruits & Vegetables'];
$json_grocery_categories = json_encode($grocery_tax_categories,   JSON_HEX_TAG|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_HEX_AMP);

// ── TABLE SETUP ───────────────────────────────────────────────────────────────
try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS sales_history (
        id            INT AUTO_INCREMENT PRIMARY KEY,
        inventory_id  INT NOT NULL,
        quantity_sold DECIMAL(10,2) NOT NULL DEFAULT 1,
        sold_at       TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
        INDEX idx_inv (inventory_id)
    )");
    // Add sold_at if table existed before this column was introduced
    try { $pdo->exec("ALTER TABLE sales_history ADD COLUMN sold_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP"); } catch (Throwable $ignored) {}
} catch (Throwable $e) { error_log('[POS] sales_history setup: ' . $e->getMessage()); }

try {
    $pdo->exec("CREATE TABLE IF NOT EXISTS pos_receipts (
        id             INT AUTO_INCREMENT PRIMARY KEY,
        receipt_number VARCHAR(50)   NOT NULL UNIQUE,
        cart_json      LONGTEXT      NOT NULL,
        subtotal       DECIMAL(10,2) NOT NULL,
        discount       DECIMAL(10,2) NOT NULL,
        tax            DECIMAL(10,2) NOT NULL,
        total          DECIMAL(10,2) NOT NULL,
        payment_method VARCHAR(30)   NOT NULL,
        tax_exempt     TINYINT(1)    NOT NULL DEFAULT 0,
        was_printed    TINYINT(1)    NOT NULL DEFAULT 0,
        created_at     TIMESTAMP DEFAULT CURRENT_TIMESTAMP
    )");
    // Add columns if upgrading from older schema
    foreach (['tax_exempt TINYINT(1) NOT NULL DEFAULT 0', 'was_printed TINYINT(1) NOT NULL DEFAULT 0'] as $col_def) {
        $col_name = explode(' ', $col_def)[0];
        try { $pdo->exec("ALTER TABLE pos_receipts ADD COLUMN {$col_def}"); } catch (Throwable $ignored) {}
    }
} catch (Throwable $e) { error_log('[POS] Table setup: ' . $e->getMessage()); }

// Ensure sku column exists (pos.php is standalone — schema_safety may not have run)
try { $pdo->exec("ALTER TABLE inventory ADD COLUMN sku VARCHAR(50) NULL DEFAULT NULL"); } catch (Throwable $ignored) {}

// ── TODAY'S SALES SUMMARY ────────────────────────────────────────────────────
$today_stats = ['cnt' => 0, 'rev' => '0.00'];
try {
    $ts = $pdo->query("SELECT COUNT(*) AS cnt, COALESCE(SUM(total),0) AS rev
                       FROM pos_receipts WHERE DATE(created_at) = CURDATE()");
    $row = $ts->fetch(PDO::FETCH_ASSOC);
    if ($row) $today_stats = ['cnt' => (int)$row['cnt'], 'rev' => number_format((float)$row['rev'], 2)];
} catch (Throwable $e) {}

// ── SAVE TRANSACTION ──────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['archive_pos_receipt'])) {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        die("Handshake expired.");
    }
    try {
        $pdo->beginTransaction();

        $cart = json_decode($_POST['cart_json_data'], true);
        if (!is_array($cart)) throw new RuntimeException("Invalid cart payload.");

        $grocery_cats = ['Produce','Bakery','Pantry','Baked Goods','Fruits & Vegetables'];
        $suball = 0; $groc_sub = 0;
        foreach ($cart as $item) {
            $line    = (float)$item['price'] * (float)$item['qty'];
            $suball += $line;
            if (in_array($item['category'] ?? '', $grocery_cats)) $groc_sub += $line;
        }
        $disc_pct   = min(100, max(0, (float)($_POST['receipt_discount_pct'] ?? 0)));
        $disc       = $suball * ($disc_pct / 100);
        $std_sub    = $suball - $groc_sub;
        $groc_ratio = $suball > 0 ? $groc_sub / $suball : 0;
        $std_ratio  = $suball > 0 ? $std_sub  / $suball : 0;
        $final_groc = max(0, $groc_sub - ($disc * $groc_ratio));
        $final_std  = max(0, $std_sub  - ($disc * $std_ratio));
        $tax        = ($final_groc * 0.02) + ($final_std * 0.0475);
        $total      = $suball - $disc + $tax;

        // Insert receipt — retry once on duplicate receipt number (collision is rare but possible)
        $tax_exempt  = isset($_POST['tax_exempt'])  && $_POST['tax_exempt']  === '1' ? 1 : 0;
        $was_printed = isset($_POST['was_printed']) && $_POST['was_printed'] === '1' ? 1 : 0;
        // Server-side override: if tax_exempt, zero the tax
        if ($tax_exempt) { $tax = 0; $total = $suball - $disc; }

        $ins = $pdo->prepare("INSERT INTO pos_receipts
            (receipt_number, cart_json, subtotal, discount, tax, total, payment_method, tax_exempt, was_printed)
            VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?)");
        $receipt_no = $_POST['receipt_number'];
        try {
            $ins->execute([$receipt_no, $_POST['cart_json_data'],
                round($suball,2), round($disc,2), round($tax,2), round($total,2),
                $_POST['payment_method'], $tax_exempt, $was_printed]);
        } catch (PDOException $dup) {
            if ($dup->getCode() === '23000') {
                $receipt_no .= '-' . rand(10,99);
                $ins->execute([$receipt_no, $_POST['cart_json_data'],
                    round($suball,2), round($disc,2), round($tax,2), round($total,2),
                    $_POST['payment_method'], $tax_exempt, $was_printed]);
            } else { throw $dup; }
        }

        $receipt_id = (int)$pdo->lastInsertId();   // capture before other inserts

        $up_stmt = $pdo->prepare("UPDATE inventory SET stock_quantity = GREATEST(0, stock_quantity - ?) WHERE id = ?");
        $h_stmt  = $pdo->prepare("INSERT INTO sales_history (inventory_id, quantity_sold) VALUES (?, ?)");
        foreach ($cart as $item) {
            $table = $item['tbl'] ?? $item['table'] ?? 'inventory';
            if ($table === 'inventory') {
                $up_stmt->execute([(float)$item['qty'], (int)$item['id']]);
                try { $h_stmt->execute([(int)$item['id'], (float)$item['qty']]); } catch (Throwable $ignored) {}
            }
        }
        $pdo->commit();

        // Audit: one entry per receipt
        $posItems = 0; foreach ($cart as $ci) $posItems += (float)($ci['qty'] ?? 0);
        erp_audit($pdo, 'create', 'pos_receipts', $receipt_id ?: null,
            'Receipt ' . $receipt_no . ' - $' . number_format($total, 2) . ' ' . (string)$_POST['payment_method']
            . ' (' . rtrim(rtrim(number_format($posItems, 2, '.', ''), '0'), '.') . ' items'
            . ($disc_pct > 0 ? ', ' . $disc_pct . '% off' : '') . ($tax_exempt ? ', tax exempt' : '') . ')');

        // ── Store for reprint banner ──────────────────────────────────────────
        $_SESSION['last_receipt'] = [
            'number'       => $receipt_no,
            'cart'         => $_POST['cart_json_data'],
            'subtotal'     => number_format($suball, 2),
            'discount'     => number_format($disc, 2),
            'discount_pct' => $disc_pct,
            'tax'          => number_format($tax, 2),
            'total'        => number_format($total, 2),
            'method'       => $_POST['payment_method'],
            'shopkeep'     => $shopkeep_display_tag,
            'saved_at'     => date('n/j/Y g:i A'),
        ];

        $_SESSION['toast_msg']  = "Receipt " . htmlspecialchars($_POST['receipt_number']) . " logged.";
        $_SESSION['toast_type'] = "success";

    } catch (Throwable $e) {
        if ($pdo->inTransaction()) $pdo->rollBack();
        $_SESSION['toast_msg']  = "Ledger Error: " . $e->getMessage();
        $_SESSION['toast_type'] = "error";
    }
    header("Location: /admin/pos.php"); exit;
}

// Read + clear last receipt for reprint banner
$last_receipt = $_SESSION['last_receipt'] ?? null;
?>
<!DOCTYPE html>
<html lang="en">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, maximum-scale=1.0, user-scalable=no">
    <title>Bat Cave Road Farm — Register</title>
    <script src="https://cdn.jsdelivr.net/npm/@tailwindcss/browser@4"></script>
    <!-- ZXing for camera barcode scanning (jsdelivr — more reliable than unpkg) -->
    <script src="https://cdn.jsdelivr.net/npm/@zxing/library@0.20.0/umd/index.min.js"></script>
    <style>
        body { -webkit-tap-highlight-color: transparent; touch-action: manipulation; }

        /* Hide receipt on screen — only visible during print */
        #printReceiptBlock { display: none; }

        @media print {
            @page { size: auto; margin: 8mm; }
            /* Zero out body height so Tailwind's min-h-screen doesn't generate a blank page */
            html, body { height: auto !important; min-height: 0 !important; overflow: visible !important; }
            body > *:not(#printReceiptBlock) { display: none !important; }
            #printReceiptBlock {
                display: block !important;
                position: static !important;
                width: 100% !important;
                max-width: 7.5in !important;
                margin: 0 !important;
                padding: 0.25in !important;
                page-break-after: avoid !important;
                break-after: avoid !important;
                font-family: 'Courier New', Courier, monospace !important;
                font-size: 13px !important;
                color: #000 !important;
                line-height: 1.4 !important;
                background: #fff !important;
            }
            #printItemsListTable { width: 100% !important; border-collapse: collapse !important; }
            #printItemsListTable th, #printItemsListTable td {
                font-family: 'Courier New', Courier, monospace !important;
                color: #000 !important;
                border-bottom: 1px solid #000 !important;
                padding: 5px 2px !important;
            }
        }
    </style>
</head>
<body class="bg-stone-100 text-stone-900 font-sans text-xs select-none min-h-screen flex flex-col">

<!-- ── REPRINT BANNER (shows after a successful save) ── -->
<?php if ($last_receipt): ?>
<div id="reprintBanner" class="bg-emerald-800 text-white px-6 py-3 flex justify-between items-center print:hidden shrink-0">
    <span class="font-mono text-xs">
        ✅ <strong><?= htmlspecialchars($last_receipt['number']) ?></strong>
        saved &bull; <strong>$<?= $last_receipt['total'] ?></strong>
        &bull; <?= htmlspecialchars($last_receipt['method']) ?>
        &bull; <?= $last_receipt['saved_at'] ?>
    </span>
    <div class="flex gap-3 items-center">
        <button onclick="reprintLastReceipt()"
            class="bg-white text-emerald-800 px-4 py-1.5 rounded font-bold text-xs uppercase tracking-wider hover:bg-emerald-50 transition">
            🖨 Reprint Receipt
        </button>
        <button onclick="document.getElementById('reprintBanner').remove()"
            class="text-emerald-200 hover:text-white text-xl leading-none font-bold">&times;</button>
    </div>
</div>
<?php endif; ?>

<!-- ── TOAST ── -->
<?php if (!empty($_SESSION['toast_msg'])): ?>
<div id="posToast" class="fixed top-4 right-4 z-[9999] bg-<?= ($_SESSION['toast_type'] ?? 'success') === 'success' ? 'emerald' : 'red' ?>-700 text-white px-5 py-3 rounded shadow-lg font-mono text-xs font-bold print:hidden">
    <?= htmlspecialchars($_SESSION['toast_msg']) ?>
</div>
<?php unset($_SESSION['toast_msg'], $_SESSION['toast_type']); ?>
<script>setTimeout(() => document.getElementById('posToast')?.remove(), 4000);</script>
<?php endif; ?>


<!-- ── TODAY'S SALES SUMMARY ── -->
<div class="bg-stone-800 text-stone-300 px-6 py-2 flex justify-between items-center text-[10px] font-mono print:hidden shrink-0">
    <span class="font-bold uppercase tracking-widest text-stone-500">Today</span>
    <div class="flex gap-6">
        <span>🧾 <strong id="todayCnt" class="text-white"><?= $today_stats['cnt'] ?></strong> transactions</span>
        <span>💰 <strong id="todayRev" class="text-emerald-400">$<?= $today_stats['rev'] ?></strong> revenue</span>
    </div>
    <span id="posClockDisplay" class="text-stone-500"></span>
</div>

<!-- ── HEADER ── -->
<header class="bg-stone-900 text-stone-100 px-6 py-4 flex justify-between items-center shadow-md print:hidden shrink-0">
    <div class="flex items-center space-x-3">
        <img src="https://batcaveroadfarm.com/img/logos/logo.png" class="h-8 w-auto object-contain">
        <h1 class="text-sm font-bold uppercase tracking-wider font-mono">Bat Cave Road Farm Stand Register</h1>
    </div>
    <div class="flex items-center space-x-4">
        <span class="font-mono text-[10px] text-stone-400 bg-stone-800 px-2 py-1 border border-stone-700 rounded font-bold">Shopkeep: <?= $shopkeep_display_tag ?></span>
        <a href="/admin/admin.php?view=dashboard" class="bg-stone-800 hover:bg-stone-700 text-stone-300 font-bold px-4 py-2 border border-stone-700 rounded text-[10px] tracking-wider uppercase transition">&larr; ERP</a>
    </div>
</header>

<!-- Sticky total bar for mobile -->
<div id="stickyTotalBar" class="lg:hidden sticky top-0 z-40 bg-stone-900 text-white px-4 py-2 flex justify-between items-center text-xs font-mono font-bold print:hidden border-b border-stone-700">
    <span id="stickyItemCount" class="text-stone-400 text-[10px]">0 items</span>
    <span class="text-[10px]">DUE: <span id="stickyTotal" class="text-emerald-400 font-black">$0.00</span></span>
    <div class="flex gap-1.5">
        <button onclick="finalizeSettlement('CASH')" class="bg-stone-700 hover:bg-stone-600 px-2.5 py-1 rounded uppercase tracking-wider text-[9px]">Cash</button>
        <button onclick="openMobilePaymentModal()" class="bg-emerald-700 hover:bg-emerald-600 px-2.5 py-1 rounded uppercase tracking-wider text-[9px]">Mobile</button>
    </div>
</div>

<!-- ── MAIN LAYOUT ── -->
<div class="flex-1 grid grid-cols-1 lg:grid-cols-3 gap-6 p-6 items-start print:hidden">

    <!-- CATALOGUE -->
    <div id="posCatalogueColumn" class="lg:col-span-2 space-y-4">

        <?php if (empty($categories)): ?>
            <div class="p-4 bg-amber-50 border border-amber-200 text-amber-800 font-mono text-xs rounded">
                ⚠️ No active inventory categories found.
            </div>
        <?php else: ?>
            <div id="categoryTabRow" class="flex flex-wrap gap-2 font-mono font-bold text-[10px] text-center uppercase tracking-wider">
                <?php foreach ($categories as $cat): ?>
                    <button type="button"
                        onclick="changeActiveCategoryTab(<?= htmlspecialchars(json_encode($cat)) ?>)"
                        id="tab-<?= htmlspecialchars($cat) ?>"
                        data-cat="<?= htmlspecialchars($cat) ?>"
                        class="cat-btn flex-1 min-w-[80px] py-4 bg-stone-200 text-stone-700 rounded border shadow-sm">
                        <?= htmlspecialchars($cat) ?>
                    </button>
                <?php endforeach; ?>
            </div>
        <?php endif; ?>

        <div class="bg-white border rounded p-4 shadow-sm grid grid-cols-2 sm:grid-cols-7 gap-2 items-center">
            <div class="relative flex items-center col-span-2 sm:col-span-1">
                <input type="text" id="filterInput" oninput="liveFilterGridItems(this.value)"
                    placeholder="Search inventory..."
                    class="p-2.5 pr-8 border rounded bg-stone-50 outline-none w-full text-xs font-semibold">
                <button type="button" id="clearSearchBtn" onclick="clearSearchInput()"
                    class="absolute right-2 text-stone-400 hover:text-stone-800 font-bold text-sm leading-none hidden">&times;</button>
            </div>
            <button type="button" onclick="openCustomItemModal()"  class="bg-emerald-800 hover:bg-emerald-950 font-mono font-bold text-white p-2.5 rounded uppercase tracking-wider text-[10px]">Custom Item +</button>
            <button type="button" onclick="openDiscountModal()"    class="bg-amber-600 hover:bg-amber-700 font-mono font-bold text-white p-2.5 rounded uppercase tracking-wider text-[10px]">% Discount</button>
            <button type="button" id="taxExemptBtn" onclick="toggleTaxExempt()"
                class="font-mono font-bold p-2.5 rounded uppercase tracking-wider text-[10px] border-2 border-stone-300 text-stone-600 hover:bg-stone-100 transition">Tax Exempt</button>
            <button type="button" onclick="openCameraScanModal()"
                class="bg-stone-700 hover:bg-stone-900 font-mono font-bold text-white p-2.5 rounded uppercase tracking-wider text-[10px]">📷 Scan</button>
            <button type="button" onclick="refreshInventoryStock()"
                class="bg-stone-600 hover:bg-stone-800 font-mono font-bold text-white p-2.5 rounded uppercase tracking-wider text-[10px]">↻ Stock</button>
            <button type="button" onclick="confirmClearTicket()" class="bg-red-700 hover:bg-red-800 font-mono font-bold text-white p-2.5 rounded uppercase tracking-wider text-[10px]">Clear Ticket</button>
        </div>

        <div id="productGridWrapper" class="grid grid-cols-2 sm:grid-cols-4 gap-3 max-h-[62vh] overflow-y-auto p-0.5"></div>
    </div>

    <!-- ORDER TICKET -->
    <div class="bg-white border rounded-lg p-5 shadow-sm flex flex-col justify-between min-h-[75vh]">
        <div>
            <h3 class="font-bold text-stone-800 border-b pb-2 mb-4 uppercase tracking-wider flex justify-between items-center font-mono">
                <span>Active Order Ticket</span>
                <span id="itemsVolumeBadge" class="bg-stone-100 border text-stone-500 font-bold px-2 py-0.5 text-[10px] rounded">0 Items</span>
            </h3>
            <div id="ticketRowsContainer" class="space-y-2 max-h-[44vh] overflow-y-auto pr-1">
                <p id="emptyFeedback" class="text-stone-400 italic text-center py-12 text-xs">Register empty. Tap menu tiles to build transaction.</p>
            </div>
        </div>
        <div class="border-t pt-4 mt-4 space-y-2 font-mono text-stone-600 font-bold text-[10px]">
            <div class="flex justify-between"><span>SUBTOTAL RATE:</span><span id="lblSubtotal">$0.00</span></div>
            <div id="rowDiscount" class="flex justify-between text-amber-700" style="display:none;"><span>MARKDOWN APPLIED:</span><span id="lblDiscount">-$0.00</span></div>
            <div class="flex justify-between"><span id="lblTaxLabel">NC SALES TAX:</span><span id="lblTax">$0.00</span></div>
            <div class="flex justify-between text-sm text-stone-900 font-black border-t border-dashed pt-2"><span>TOTAL DUE BALANCE:</span><span id="lblTotal">$0.00</span></div>
            <div class="grid grid-cols-2 gap-2 pt-2">
                <button type="button" onclick="finalizeSettlement('CASH')"         class="bg-stone-800 hover:bg-stone-950 text-white font-sans font-bold uppercase tracking-wider py-3 rounded shadow-xs">Cash Payment</button>
                <button type="button" onclick="openMobilePaymentModal()"           class="bg-emerald-800 hover:bg-emerald-950 text-white font-sans font-bold uppercase tracking-wider py-3 rounded shadow-xs">Mobile Apps</button>
            </div>
        </div>
    </div>
</div>

<!-- ═════════ MODALS ═════════ -->

<!-- Mobile payment provider -->
<div id="mobilePaymentModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-4 shadow-xl">
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 border-b pb-2 tracking-wide text-center">Select Mobile Payment</h4>
        <div class="grid grid-cols-1 gap-2.5">
            <button type="button" onclick="finalizeSettlement('PAYPAL')"  class="w-full bg-[#003087] text-white py-3 rounded-md font-bold font-mono uppercase tracking-wider hover:opacity-90">🔷 PayPal</button>
            <button type="button" onclick="finalizeSettlement('VENMO')"   class="w-full bg-[#008CFF] text-white py-3 rounded-md font-bold font-mono uppercase tracking-wider hover:opacity-90">⚡ Venmo</button>
            <button type="button" onclick="finalizeSettlement('CASHAPP')" class="w-full bg-[#00D632] text-stone-950 py-3 rounded-md font-bold font-mono uppercase tracking-wider hover:opacity-90">🟩 CashApp</button>
            <button type="button" onclick="finalizeSettlement('CHECK')"   class="w-full bg-stone-700 text-white py-3 rounded-md font-bold font-mono uppercase tracking-wider hover:opacity-90">📝 Check</button>
        </div>
        <button type="button" onclick="closeMobilePaymentModal()" class="w-full border p-2 rounded text-stone-500 uppercase tracking-widest font-bold text-[10px] bg-stone-50">Cancel</button>
    </div>
</div>

<!-- Cash calculator -->
<div id="cashCalculatorModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-4 shadow-xl text-center">
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 border-b pb-2 tracking-wide">Cash Settlement Ledger</h4>
        <div class="grid grid-cols-2 gap-2 text-center bg-stone-50 p-2 border rounded font-mono text-[10px]">
            <div><span class="block text-[9px] font-bold text-stone-400 uppercase">Total Owed</span><span id="cashModalOwedSpan" class="text-xs font-black text-stone-900">$0.00</span></div>
            <div><span class="block text-[9px] font-bold text-stone-400 uppercase">Tendered</span><span id="cashModalTenderedDisplay" class="text-xs font-black text-emerald-800">$0.00</span></div>
        </div>
        <input type="number" step="0.01" id="cashModalInput" class="hidden">
        <div class="grid grid-cols-3 gap-1.5 font-mono font-bold text-[10px]">
            <button type="button" onclick="injectQuickCashBillValue(20)"  class="p-2 border rounded bg-stone-50 hover:bg-stone-200">+$20</button>
            <button type="button" onclick="injectQuickCashBillValue(50)"  class="p-2 border rounded bg-stone-50 hover:bg-stone-200">+$50</button>
            <button type="button" onclick="injectQuickCashBillValue(100)" class="p-2 border rounded bg-stone-50 hover:bg-stone-200">+$100</button>
        </div>
        <div class="grid grid-cols-3 gap-1.5 font-mono font-bold text-xs text-stone-800">
            <?php foreach ([1,2,3,4,5,6,7,8,9] as $d): ?>
            <button type="button" onclick="pressCashKeypadDigit(<?= $d ?>)" class="py-2.5 border rounded bg-stone-50 active:bg-stone-900 active:text-white"><?= $d ?></button>
            <?php endforeach; ?>
            <button type="button" onclick="pressCashKeypadDigit('00')" class="py-2.5 border rounded bg-stone-50 active:bg-stone-900 active:text-white">00</button>
            <button type="button" onclick="pressCashKeypadDigit(0)"    class="py-2.5 border rounded bg-stone-50 active:bg-stone-900 active:text-white">0</button>
            <button type="button" onclick="clearCashKeypadBuffer()"    class="py-2.5 border rounded bg-red-50 text-red-700 active:bg-red-800 active:text-white text-[10px] font-bold uppercase">Clear</button>
        </div>
        <div id="cashModalError" class="text-red-700 font-bold text-[10px] font-mono hidden">Insufficient funds entered.</div>
        <button type="button" onclick="processCashSettlementVerification()" class="w-full bg-stone-900 text-white py-3 rounded font-bold font-mono uppercase tracking-wider hover:bg-stone-950">Confirm Cash &rarr;</button>
        <button type="button" onclick="closeCashCalculatorModal()" class="w-full border p-2 rounded text-stone-400 uppercase tracking-widest text-[9px] bg-stone-50">Back</button>
    </div>
</div>

<!-- Cash change display -->
<div id="cashChangeOutputModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-3 shadow-xl text-center">
        <div class="text-3xl">💵</div>
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 tracking-wide">Dispense Change</h4>
        <div class="bg-stone-900 text-emerald-400 font-mono text-2xl py-4 rounded-md font-black shadow-inner" id="cashModalChangeSpan">$0.00</div>
        <p class="text-[10px] font-mono text-stone-400 uppercase tracking-widest" id="changeModalReceiptNo"></p>
        <div class="border rounded overflow-hidden">
            <button type="button" onclick="posPrintReceipt()"
                class="w-full bg-emerald-700 hover:bg-emerald-800 text-white py-3 font-bold font-sans uppercase tracking-wider text-sm transition">
                🖨 Print Receipt &amp; Save
            </button>
            <div class="border-t"></div>
            <button type="button" onclick="posCommitAndClose()"
                class="w-full bg-stone-900 hover:bg-stone-700 text-white py-3 font-bold font-sans uppercase tracking-wider text-sm transition">
                Skip Print — Save Only
            </button>
            <div class="border-t"></div>
            <button type="button" onclick="document.getElementById('cashChangeOutputModal').classList.add('hidden')"
                class="w-full bg-stone-50 hover:bg-stone-100 text-stone-500 py-2 text-[10px] uppercase font-bold transition">
                Back
            </button>
        </div>
    </div>
</div>

<!-- Mobile payment confirmation -->
<div id="mobileConfirmModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-3 shadow-xl text-center">
        <div class="text-3xl">✅</div>
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 tracking-wide">Payment Confirmed</h4>
        <div class="bg-stone-50 border rounded p-3 font-mono">
            <div class="text-2xl font-black text-emerald-800" id="mobileConfirmAmount">$0.00</div>
            <div class="text-[10px] text-stone-400 uppercase tracking-widest mt-1" id="mobileConfirmMethod">Via —</div>
        </div>
        <div class="border rounded overflow-hidden">
            <button type="button" onclick="posPrintReceipt()"
                class="w-full bg-emerald-700 hover:bg-emerald-800 text-white py-3 font-bold font-sans uppercase tracking-wider text-sm transition">
                🖨 Print Receipt &amp; Save
            </button>
            <div class="border-t"></div>
            <button type="button" onclick="posCommitAndClose()"
                class="w-full bg-stone-900 hover:bg-stone-700 text-white py-3 font-bold font-sans uppercase tracking-wider text-sm transition">
                Skip Print — Save Only
            </button>
            <div class="border-t"></div>
            <button type="button" onclick="document.getElementById('mobileConfirmModal').classList.add('hidden')"
                class="w-full bg-stone-50 hover:bg-stone-100 text-stone-500 py-2 text-[10px] uppercase font-bold transition">
                Back
            </button>
        </div>
    </div>
</div>

<!-- Custom item -->
<div id="customItemModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-4 shadow-xl">
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 border-b pb-2 tracking-wide text-center">Custom Item</h4>
        <div class="space-y-3 text-xs font-medium">
            <div>
                <label class="block font-bold text-stone-600 mb-0.5">Product Name</label>
                <input type="text" id="customNameInput" value="Miscellaneous" class="w-full p-2 border bg-stone-50 rounded font-semibold">
            </div>
            <div class="grid grid-cols-2 gap-3">
                <div>
                    <label class="block font-bold text-stone-600 mb-0.5">Quantity</label>
                    <input type="number" step="1" id="customQtyInput" value="1" class="w-full p-2 border bg-stone-50 font-mono rounded text-center">
                </div>
                <div>
                    <label class="block font-bold text-stone-600 mb-0.5">Unit Price ($)</label>
                    <input type="number" step="0.01" id="customPriceInput" value="1.00" class="w-full p-2 border bg-stone-50 font-mono rounded text-center">
                </div>
            </div>
        </div>
        <button type="button" onclick="submitCustomItemModal()" class="w-full bg-stone-900 text-white py-2.5 rounded font-bold uppercase font-mono tracking-wider">Add Item &rarr;</button>
        <button type="button" onclick="closeCustomItemModal()"  class="w-full text-stone-400 text-[10px] uppercase font-bold text-center block pt-1">Cancel</button>
    </div>
</div>

<!-- Discount -->
<div id="discountModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full p-6 space-y-4 shadow-xl text-center">
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 border-b pb-2 tracking-wide">Apply Markdown</h4>
        <div id="currentDiscountDisplay" class="hidden bg-amber-50 border border-amber-200 text-amber-800 font-mono text-xs font-bold px-3 py-2 rounded text-center"></div>
        <div class="grid grid-cols-4 gap-2 font-mono font-bold text-xs">
            <button type="button" onclick="submitDiscountValue(5)"  class="p-3 border rounded bg-stone-50 hover:bg-stone-900 hover:text-white">5%</button>
            <button type="button" onclick="submitDiscountValue(10)" class="p-3 border rounded bg-stone-50 hover:bg-stone-900 hover:text-white">10%</button>
            <button type="button" onclick="submitDiscountValue(20)" class="p-3 border rounded bg-stone-50 hover:bg-stone-900 hover:text-white">20%</button>
            <button type="button" onclick="submitDiscountValue(50)" class="p-3 border rounded bg-red-50 text-red-700 hover:bg-red-900 hover:text-white">50%</button>
        </div>
        <div class="pt-2 text-left">
            <label class="block font-bold text-stone-500 font-mono text-[9px] mb-0.5">Custom %</label>
            <input type="number" id="customDiscountInput" min="0" max="100" placeholder="e.g. 15" class="w-full p-2 border bg-stone-50 font-mono text-center rounded">
        </div>
        <button type="button" onclick="submitDiscountValue(document.getElementById('customDiscountInput').value)" class="w-full bg-stone-900 text-white py-2.5 rounded font-mono font-bold uppercase tracking-wider">Apply</button>
        <button type="button" onclick="closeDiscountModal()" class="w-full text-stone-400 text-[10px] uppercase font-bold block">Cancel</button>
    </div>
</div>

<!-- ── PRINT RECEIPT BLOCK ──────────────────────────────────────────────────────
     Hidden on screen; shown only via @media print CSS.
     NOTE: The print CSS uses "body > *:not(#printReceiptBlock) { display:none }"
     so this block must be a DIRECT child of <body>.
──────────────────────────────────────────────────────────────────────────── -->
<div id="printReceiptBlock" style="display:none;">
    <!-- Compact centered header -->
    <div style="text-align:center;border-bottom:2px solid #000;padding-bottom:8px;margin-bottom:10px;">
        <img src="https://batcaveroadfarm.com/img/logos/logo.png" style="height:48px;width:auto;display:inline-block;margin-bottom:4px;">
        <div style="font-size:17px;font-weight:900;font-family:serif;color:#000;line-height:1.1;">BAT CAVE ROAD FARM</div>
        <div style="font-size:9px;font-weight:bold;text-transform:uppercase;font-family:sans-serif;letter-spacing:0.05em;color:#333;margin-top:2px;">Petting Zoo &bull; Farm Stand &bull; Thrift Shop</div>
        <div style="font-size:9px;font-weight:bold;font-family:sans-serif;color:#555;margin-top:2px;">4132 Bat Cave Road, Old Fort, NC 28762 &bull; (828) 216-5488</div>
    </div>
    <table style="width:100%;background:#f5f5f5;font-size:10px;font-weight:bold;margin-bottom:10px;border:1px solid #ddd;">
        <tr>
            <td style="padding:5px 8px;text-align:left;border:none;"   id="printMetaReceiptNo">RECEIPT: —</td>
            <td style="padding:5px 8px;text-align:center;border:none;">SHOPKEEP: <?= $shopkeep_display_tag ?></td>
            <td style="padding:5px 8px;text-align:right;border:none;"  id="printMetaTimestamp">TIME: —</td>
        </tr>
    </table>
    <table id="printItemsListTable" style="width:100%;font-size:11px;margin-bottom:10px;table-layout:fixed;">
        <thead>
            <tr style="border-bottom:2px solid #000;text-align:left;font-weight:bold;font-size:10px;">
                <th style="padding-bottom:4px;text-align:left;width:55%;">ITEM</th>
                <th style="padding-bottom:4px;text-align:center;width:10%;">QTY</th>
                <th style="padding-bottom:4px;text-align:right;width:15%;">PRICE</th>
                <th style="padding-bottom:4px;text-align:right;width:20%;">LINE</th>
            </tr>
        </thead>
        <tbody id="printItemsListTableBody"></tbody>
    </table>
    <table style="width:100%;font-size:11px;font-weight:bold;margin-top:4px;">
        <tr>
            <td style="width:52%;border:none;"></td>
            <td style="width:48%;border:none;">
                <table style="width:100%;line-height:1.4;border-collapse:collapse;font-size:11px;">
                    <tr><td style="text-align:left;padding:1px 0;border:none;">SUBTOTAL:</td>   <td style="text-align:right;padding:1px 0;border:none;" id="pSub">$0.00</td></tr>
                    <tr id="pDiscRow" style="display:none;"><td style="text-align:left;padding:1px 0;border:none;">MARKDOWN:</td><td style="text-align:right;padding:1px 0;border:none;" id="pDisc">-$0.00</td></tr>
                    <tr><td style="text-align:left;padding:1px 0;border:none;">NC TAX:</td>      <td style="text-align:right;padding:1px 0;border:none;" id="pTax">$0.00</td></tr>
                    <tr style="font-size:13px;border-top:1px dashed #000;font-weight:900;"><td style="text-align:left;padding-top:4px;border:none;">TOTAL:</td><td style="text-align:right;padding-top:4px;border:none;" id="pTot">$0.00</td></tr>
                    <tr><td style="text-align:left;padding:1px 0;border:none;">TENDER:</td>      <td style="text-align:right;text-transform:uppercase;padding:1px 0;border:none;" id="pMethod">—</td></tr>
                </table>
            </td>
        </tr>
    </table>
    <div id="pThriftDisclaimer" style="display:none;border:1px solid #000;padding:6px;margin-top:10px;font-size:9px;text-transform:uppercase;font-weight:bold;text-align:center;line-height:1.3;">
        ⚠️ All thrift shop items are sold AS-IS. No warranties expressed or implied.
    </div>
    <div style="border-top:1px solid #000;margin-top:12px;padding-top:6px;text-align:center;font-size:9px;font-weight:bold;font-family:sans-serif;">
        🌐 batcaveroadfarm.com &bull; 📱 @BatCaveRoadFarm
        <div style="font-family:serif;font-style:italic;font-size:10px;margin-top:2px;">Thank you for supporting our local Blue Ridge homestead!</div>
    </div>
</div>


<!-- Camera barcode scan modal -->
<div id="cameraScanModal" class="fixed inset-0 bg-stone-900/80 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-sm w-full shadow-xl overflow-hidden">
        <div class="bg-stone-900 text-white px-5 py-3 flex justify-between items-center">
            <h4 class="font-serif font-black text-sm uppercase tracking-wide">Scan Barcode</h4>
            <button onclick="closeCameraScanModal()" class="text-stone-400 hover:text-white text-xl leading-none">&times;</button>
        </div>

        <!-- Camera viewfinder -->
        <div class="relative bg-stone-950" style="height:240px;">
            <video id="scanVideo" class="w-full h-full object-cover" playsinline autoplay muted></video>
            <!-- Aim guide -->
            <div class="absolute inset-0 flex items-center justify-center pointer-events-none">
                <div class="border-2 border-emerald-400 rounded" style="width:80%;height:60px;box-shadow:0 0 0 9999px rgba(0,0,0,0.35);"></div>
            </div>
            <canvas id="scanCanvas" class="hidden"></canvas>
        </div>

        <!-- Manual SKU entry fallback -->
        <div class="p-4 space-y-3">
            <div id="scanStatus" class="text-xs font-mono text-stone-500 text-center">Point camera at barcode…</div>
            <div class="flex gap-2">
                <input type="text" id="manualSkuInput"
                    placeholder="Or type SKU manually + Enter"
                    class="flex-1 p-2 border rounded bg-stone-50 font-mono text-sm outline-none"
                    onkeydown="if(event.key==='Enter'){processScannedSKU(this.value.trim());this.value='';}">
                <button onclick="processScannedSKU(document.getElementById('manualSkuInput').value.trim()); document.getElementById('manualSkuInput').value='';"
                    class="bg-stone-900 text-white px-4 rounded font-bold text-xs uppercase tracking-wider">Add</button>
            </div>
            <button onclick="closeCameraScanModal()" class="w-full border py-2 rounded text-stone-500 text-[10px] uppercase font-bold hover:bg-stone-50">Done</button>
        </div>
    </div>
</div>

<!-- Clear Ticket confirmation -->
<div id="clearTicketModal" class="fixed inset-0 bg-stone-900/60 backdrop-blur-sm z-50 hidden flex items-center justify-center p-4">
    <div class="bg-white rounded-lg border max-w-xs w-full p-6 space-y-4 shadow-xl text-center">
        <div class="text-3xl">🗑️</div>
        <h4 class="font-serif font-black text-sm uppercase text-stone-800 tracking-wide">Clear Ticket?</h4>
        <p class="text-xs text-stone-500 font-mono">This will remove all items and reset the register. This cannot be undone.</p>
        <button type="button" onclick="wipeActiveTicketForm(); document.getElementById('clearTicketModal').classList.add('hidden')"
            class="w-full bg-red-700 hover:bg-red-800 text-white py-3 rounded font-bold uppercase tracking-wider text-sm">
            Yes, Clear Everything
        </button>
        <button type="button" onclick="document.getElementById('clearTicketModal').classList.add('hidden')"
            class="w-full border py-2 rounded text-stone-500 text-[10px] uppercase font-bold bg-stone-50">
            Cancel
        </button>
    </div>
</div>

<!-- ── HIDDEN FORM ── -->
<form id="ledgerHiddenSubmissionForm" method="POST" action="/admin/pos.php" style="display:none;">
    <input type="hidden" name="csrf_token"           value="<?= $_SESSION['csrf_token'] ?>">
    <input type="hidden" name="archive_pos_receipt"  value="1">
    <input type="hidden" name="receipt_number"       id="formReceiptNo">
    <input type="hidden" name="cart_json_data"       id="formCartJson">
    <input type="hidden" name="receipt_subtotal"     id="formSubtotal">
    <input type="hidden" name="receipt_discount"     id="formDiscount">
    <input type="hidden" name="receipt_tax"          id="formTax">
    <input type="hidden" name="receipt_total"        id="formTotal">
    <input type="hidden" name="payment_method"       id="formMethod">
    <input type="hidden" name="receipt_discount_pct" id="formDiscountPct">
    <input type="hidden" name="tax_exempt"           id="formTaxExempt"  value="0">
    <input type="hidden" name="was_printed"          id="formWasPrinted" value="0">
</form>

<script>
// ── DATA FROM PHP ─────────────────────────────────────────────────────────────
const catalogueMatrix   = <?= $json_catalogue_payload ?>;
const allCategories     = <?= $json_categories ?>;
const groceryCategories = <?= $json_grocery_categories ?>;

// ── STATE ─────────────────────────────────────────────────────────────────────
let currentCart          = [];
let currentTab           = allCategories[0] ?? '';
let cartDiscountPercent  = 0;
let globalCurrentMethod  = 'CASH';
let globalCurrentReceiptNo = '';
let cashCentsAccumulator = 0;
let taxExemptMode        = false;
let wasPrinted           = false;

// ── MODAL HELPERS ─────────────────────────────────────────────────────────────
function openMobilePaymentModal()  { if (!currentCart.length) return; document.getElementById('mobilePaymentModal').classList.remove('hidden'); }
function closeMobilePaymentModal() { document.getElementById('mobilePaymentModal').classList.add('hidden'); }
function openCustomItemModal()     { document.getElementById('customItemModal').classList.remove('hidden'); }
function closeCustomItemModal()    { document.getElementById('customItemModal').classList.add('hidden'); }
function closeCashCalculatorModal(){ document.getElementById('cashCalculatorModal').classList.add('hidden'); }

function openDiscountModal() {
    document.getElementById('discountModal').classList.remove('hidden');
    const display = document.getElementById('currentDiscountDisplay');
    if (cartDiscountPercent > 0) {
        display.textContent = 'Current: ' + cartDiscountPercent + '% — selecting a new rate will replace it';
        display.classList.remove('hidden');
    } else {
        display.classList.add('hidden');
    }
}
function closeDiscountModal() { document.getElementById('discountModal').classList.add('hidden'); }

// ── TAX EXEMPT TOGGLE ────────────────────────────────────────────────────────
function toggleTaxExempt() {
    taxExemptMode = !taxExemptMode;
    const btn = document.getElementById('taxExemptBtn');
    const lbl = document.getElementById('lblTaxLabel');
    if (taxExemptMode) {
        btn.className = btn.className.replace('border-stone-300 text-stone-600 hover:bg-stone-100', 'border-emerald-500 bg-emerald-50 text-emerald-800 font-black');
        if (lbl) lbl.textContent = 'TAX EXEMPT:';
    } else {
        btn.className = btn.className.replace('border-emerald-500 bg-emerald-50 text-emerald-800 font-black', 'border-stone-300 text-stone-600 hover:bg-stone-100');
        if (lbl) lbl.textContent = 'NC SALES TAX:';
    }
    renderTicketCalculationDisplay();
}

// ── CONFIRM CLEAR TICKET ─────────────────────────────────────────────────────
function confirmClearTicket() {
    if (!currentCart.length) return; // nothing to clear
    document.getElementById('clearTicketModal').classList.remove('hidden');
}

// ── INVENTORY STOCK REFRESH ───────────────────────────────────────────────────
function refreshInventoryStock() {
    const btn = document.querySelector('[onclick="refreshInventoryStock()"]');
    if (btn) { btn.textContent = '↻ Refreshing…'; btn.disabled = true; }
    fetch('/admin/pos.php?ajax_stock=1')
        .then(r => r.json())
        .then(stockMap => {
            // Update stock_quantity in catalogueMatrix
            let updated = 0;
            Object.values(catalogueMatrix).forEach(items => {
                items.forEach(item => {
                    if (stockMap.hasOwnProperty(item.id)) {
                        item.stock_quantity = stockMap[item.id];
                        updated++;
                    }
                });
            });
            renderPOSMatrixGrid(catalogueMatrix[currentTab] || []);
            if (btn) { btn.textContent = '✓ Updated'; btn.disabled = false; setTimeout(() => { btn.textContent = '↻ Stock'; }, 2000); }
        })
        .catch(() => { if (btn) { btn.textContent = '↻ Stock'; btn.disabled = false; } });
}

// ── CATEGORY TABS ─────────────────────────────────────────────────────────────
function changeActiveCategoryTab(catKey) {
    currentTab = catKey;
    document.querySelectorAll('.cat-btn').forEach(btn => {
        btn.className = 'cat-btn flex-1 min-w-[80px] py-4 bg-stone-200 text-stone-700 rounded border shadow-sm font-mono font-bold text-[10px] text-center uppercase tracking-wider';
    });
    const active = document.getElementById('tab-' + catKey);
    if (active) active.className = 'cat-btn flex-1 min-w-[80px] py-4 bg-stone-900 text-white rounded border border-stone-950 shadow-sm font-mono font-bold text-[10px] text-center uppercase tracking-wider';
    document.getElementById('filterInput').value = '';
    renderPOSMatrixGrid(catalogueMatrix[catKey] || []);
}

// ── ITEM GRID ─────────────────────────────────────────────────────────────────
function renderPOSMatrixGrid(items) {
    const dock = document.getElementById('productGridWrapper');
    if (!dock) return;
    dock.innerHTML = '';
    if (!items || !items.length) {
        dock.innerHTML = '<p class="col-span-4 text-center italic text-stone-400 py-12 border border-dashed rounded bg-white w-full">No active items in this category.</p>';
        return;
    }
    items.forEach(item => {
        let card = document.createElement('div');
        card.setAttribute('data-search', item.name.toLowerCase());
        const isOutOfStock = item.stock_quantity !== null && item.stock_quantity <= 0;
        if (isOutOfStock) {
            card.onclick = null;
            card.style.opacity = '0.45';
            card.style.cursor  = 'not-allowed';
            card.style.pointerEvents = 'none';
        } else {
            card.onclick = () => addItemToTicketQueue(item.id, item.name, parseFloat(item.price), item.tbl, item.category);
        }
        if (item.image_url) {
            card.className = 'relative rounded-lg h-24 overflow-hidden bg-cover bg-center cursor-pointer shadow-sm active:scale-95 transition-transform duration-75 border border-stone-300';
            card.style.backgroundImage = "url('" + item.image_url + "')";
            const lowBadge = (item.stock_quantity !== null && item.stock_quantity <= 3)
                ? '<span class="absolute top-1.5 right-1.5 bg-red-600 text-white text-[8px] font-black px-1 py-0.5 rounded uppercase">' + (item.stock_quantity <= 0 ? 'OUT' : 'LOW') + '</span>'
                : '';
            card.innerHTML = '<div class="absolute inset-0 bg-stone-950/50 flex flex-col justify-end p-2 text-left"><h4 class="font-bold text-white text-[11px] leading-tight truncate">' + item.name + '</h4><span class="font-mono text-[10px] font-black text-emerald-300 block mt-0.5">$' + parseFloat(item.price).toFixed(2) + '</span></div>' + lowBadge;
        } else {
            card.className = 'bg-white border rounded-lg h-24 p-2.5 flex flex-col justify-between items-center text-center cursor-pointer shadow-sm border-stone-200 active:scale-95 transition-transform duration-75';
            const lowLabel = (item.stock_quantity !== null && item.stock_quantity <= 3)
                ? '<span class="text-[8px] font-black uppercase px-1 py-0.5 rounded mt-0.5 inline-block ' + (item.stock_quantity <= 0 ? 'bg-red-100 text-red-700' : 'bg-amber-100 text-amber-700') + '">' + (item.stock_quantity <= 0 ? 'Out' : 'Low: ' + item.stock_quantity) + '</span>'
                : '';
            card.innerHTML = '<div class="w-full h-8 flex items-center justify-center text-xl text-stone-300">📦</div><div class="w-full"><h4 class="font-bold text-stone-800 leading-tight truncate text-[11px]">' + item.name + '</h4><span class="font-mono text-[10px] font-black text-emerald-800 block mt-0.5">$' + parseFloat(item.price).toFixed(2) + '</span>' + lowLabel + '</div>';
        }
        dock.appendChild(card);
    });
}

function liveFilterGridItems(val) {
    const q = val.toLowerCase().trim();
    document.getElementById('clearSearchBtn')?.classList.toggle('hidden', q === '');
    if (q === '') { renderPOSMatrixGrid(catalogueMatrix[currentTab] || []); return; }
    const all = Object.values(catalogueMatrix).flat();
    renderPOSMatrixGrid(all.filter(i => i.name.toLowerCase().includes(q)));
}
function clearSearchInput() {
    document.getElementById('filterInput').value = '';
    liveFilterGridItems('');
    document.getElementById('filterInput').focus();
}

// ── CART ──────────────────────────────────────────────────────────────────────
function addItemToTicketQueue(id, name, price, table, category, qty = 1) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (match) { match.qty += qty; }
    else { currentCart.push({ id, name, price, tbl: 'inventory', qty, category }); }
    renderTicketCalculationDisplay();
}
function adjustCartQty(id, table, delta) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (!match) return;
    match.qty = Math.max(0.5, match.qty + delta);
    if (match.qty <= 0) currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    renderTicketCalculationDisplay();
}
function updateRowQuantity(id, table, newQty) {
    let match = currentCart.find(i => i.id === id && i.tbl === table);
    if (match) {
        match.qty = parseFloat(newQty) || 0;
        if (match.qty <= 0) currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    }
    renderTicketCalculationDisplay();
}
function deleteRowItem(id, table) {
    currentCart = currentCart.filter(i => !(i.id === id && i.tbl === table));
    renderTicketCalculationDisplay();
}
function submitCustomItemModal() {
    let label = document.getElementById('customNameInput').value.trim();
    let qty   = parseFloat(document.getElementById('customQtyInput').value);
    let price = parseFloat(document.getElementById('customPriceInput').value);
    if (!label || isNaN(qty) || qty <= 0 || isNaN(price) || price < 0) return;
    addItemToTicketQueue(Date.now(), '★ ' + label, price, 'VIRTUAL_CUSTOM_ITEM', 'Custom');
    closeCustomItemModal();
}
function submitDiscountValue(val) {
    let pct = parseInt(val);
    if (isNaN(pct) || pct < 0 || pct > 100) return;
    cartDiscountPercent = pct;
    renderTicketCalculationDisplay();
    closeDiscountModal();
}
function wipeActiveTicketForm() {
    currentCart = []; cartDiscountPercent = 0;
    globalCurrentReceiptNo = ''; globalCurrentMethod = 'CASH';
    wasPrinted = false;
    // Reset tax exempt
    if (taxExemptMode) toggleTaxExempt();
    renderTicketCalculationDisplay();
}

// ── TAX CALC ──────────────────────────────────────────────────────────────────
function getCurrentCalculations() {
    let suball   = currentCart.reduce((s, i) => s + (i.price * i.qty), 0);
    let disc     = suball * (cartDiscountPercent / 100);
    let grocSub  = currentCart.filter(i => groceryCategories.includes(i.category)).reduce((s, i) => s + (i.price * i.qty), 0);
    let stdSub   = suball - grocSub;
    let gRatio   = suball > 0 ? grocSub / suball : 0;
    let sRatio   = suball > 0 ? stdSub  / suball : 0;
    let fGroc    = Math.max(0, grocSub - (disc * gRatio));
    let fStd     = Math.max(0, stdSub  - (disc * sRatio));
    let tax      = taxExemptMode ? 0 : (fGroc * 0.02) + (fStd * 0.0475);
    return { suball, disc, tax, totalDue: suball - disc + tax };
}

function renderTicketCalculationDisplay() {
    const wrapper = document.getElementById('ticketRowsContainer');
    if (!wrapper) return;
    wrapper.innerHTML = '';
    if (!currentCart.length) {
        wrapper.innerHTML = '<p id="emptyFeedback" class="text-stone-400 italic text-center py-12 text-xs">Register empty. Tap menu tiles.</p>';
        document.getElementById('itemsVolumeBadge').textContent = '0 Items';
        ['lblSubtotal','lblTax','lblTotal'].forEach(id => document.getElementById(id).textContent = '$0.00');
        document.getElementById('lblDiscount').textContent = '-$0.00';
        document.getElementById('rowDiscount').style.display = 'none';
        return;
    }
    currentCart.forEach(item => {
        let lp = item.price * item.qty;
        let row = document.createElement('div');
        row.className = 'flex justify-between items-center bg-stone-50 border p-2.5 rounded text-[10px]';
        // Stock warning: check available qty from catalogueMatrix
        let stockAvail = null;
        const catItems = Object.values(catalogueMatrix).flat();
        const srcItem  = catItems.find(ci => ci.id == item.id);
        if (srcItem && srcItem.stock_quantity !== null) stockAvail = parseInt(srcItem.stock_quantity);
        const overStock = stockAvail !== null && item.qty > stockAvail;
        const rowBg = overStock ? 'bg-amber-50 border-amber-300' : 'bg-stone-50 border-stone-200';
        row.className = 'flex justify-between items-center border p-2.5 rounded text-[10px] ' + rowBg;
        const stockWarn = overStock ? '<span class="text-amber-700 font-black text-[8px] block mt-0.5">⚠ Only ' + stockAvail + ' in stock</span>' : '';
        row.innerHTML = '<div class="max-w-[50%]"><span class="font-bold text-stone-900 block truncate">' + item.name + '</span><span class="text-[10px] text-stone-400 font-mono mt-0.5">$' + item.price.toFixed(2) + '</span>' + stockWarn + '</div>'
            + '<div class="flex items-center gap-1 font-mono">'
            + '<button type="button" onclick="adjustCartQty(' + item.id + ',\'' + item.tbl + '\',-1)" class="w-7 h-7 rounded border bg-stone-200 hover:bg-stone-300 font-black text-stone-800 text-sm leading-none flex items-center justify-center">−</button>'
            + '<span class="w-8 text-center font-bold text-stone-900">' + item.qty + '</span>'
            + '<button type="button" onclick="adjustCartQty(' + item.id + ',\'' + item.tbl + '\',1)" class="w-7 h-7 rounded border bg-stone-200 hover:bg-stone-300 font-black text-stone-800 text-sm leading-none flex items-center justify-center">+</button>'
            + '<span class="font-bold text-stone-900 w-14 text-right">$' + lp.toFixed(2) + '</span>'
            + '<button type="button" onclick="deleteRowItem(' + item.id + ',\'' + item.tbl + '\')" class="text-red-500 hover:text-red-700 font-bold text-base pl-1">&times;</button>'
            + '</div>';
        wrapper.appendChild(row);
    });
    let cal = getCurrentCalculations();
    document.getElementById('itemsVolumeBadge').textContent = currentCart.reduce((s, i) => s + parseFloat(i.qty), 0) + ' Units';
    document.getElementById('lblSubtotal').textContent = '$' + cal.suball.toFixed(2);
    if (cal.disc > 0) {
        document.getElementById('lblDiscount').textContent = '-$' + cal.disc.toFixed(2) + ' (' + cartDiscountPercent + '%)';
        document.getElementById('rowDiscount').style.display = 'flex';
    } else {
        document.getElementById('rowDiscount').style.display = 'none';
    }
    document.getElementById('lblTax').textContent   = '$' + cal.tax.toFixed(2);
    document.getElementById('lblTotal').textContent = '$' + cal.totalDue.toFixed(2);
    // Update sticky mobile bar
    const stickyT = document.getElementById('stickyTotal');
    const stickyC = document.getElementById('stickyItemCount');
    if (stickyT) stickyT.textContent = '$' + cal.totalDue.toFixed(2);
    if (stickyC) stickyC.textContent = currentCart.reduce((s,i) => s + parseFloat(i.qty), 0) + ' items';
}

// ── CASH KEYPAD ───────────────────────────────────────────────────────────────
function pressCashKeypadDigit(n) {
    cashCentsAccumulator = n === '00' ? cashCentsAccumulator * 100 : (cashCentsAccumulator * 10) + parseInt(n);
    refreshCashDisplay();
}
function injectQuickCashBillValue(d) { cashCentsAccumulator += d * 100; refreshCashDisplay(); }
function clearCashKeypadBuffer()     { cashCentsAccumulator = 0; refreshCashDisplay(); }
function refreshCashDisplay() {
    let f = cashCentsAccumulator / 100;
    document.getElementById('cashModalInput').value           = f.toFixed(2);
    document.getElementById('cashModalTenderedDisplay').textContent = '$' + f.toFixed(2);
}

// ── SETTLEMENT ────────────────────────────────────────────────────────────────
function generateUniqueReceiptNumber() {
    let n = new Date();
    return 'BCF-' + n.getFullYear() + String(n.getMonth()+1).padStart(2,'0') + String(n.getDate()).padStart(2,'0') + '-' + String(Math.floor(1000+Math.random()*9000));
}

function finalizeSettlement(method) {
    if (!currentCart.length) return;
    closeMobilePaymentModal();
    globalCurrentMethod    = method;
    globalCurrentReceiptNo = generateUniqueReceiptNumber();
    let cal = getCurrentCalculations();

    if (method === 'CASH') {
        cashCentsAccumulator = 0;
        document.getElementById('cashModalOwedSpan').textContent       = '$' + cal.totalDue.toFixed(2);
        document.getElementById('cashModalTenderedDisplay').textContent = '$0.00';
        document.getElementById('cashModalInput').value                = '0.00';
        document.getElementById('cashModalError').classList.add('hidden');
        document.getElementById('cashCalculatorModal').classList.remove('hidden');
    } else {
        // Mobile payment: populate receipt then show confirmation modal
        hydrateReceiptBlock();
        document.getElementById('mobileConfirmAmount').textContent = '$' + cal.totalDue.toFixed(2);
        document.getElementById('mobileConfirmMethod').textContent = 'Via ' + method;
        document.getElementById('mobileConfirmModal').classList.remove('hidden');
    }
}

function processCashSettlementVerification() {
    let cal     = getCurrentCalculations();
    let tendered = parseFloat(document.getElementById('cashModalInput').value);
    if (isNaN(tendered) || tendered < cal.totalDue) {
        document.getElementById('cashModalError').classList.remove('hidden'); return;
    }
    document.getElementById('cashCalculatorModal').classList.add('hidden');
    var changeAmt = tendered - cal.totalDue;
    var changeEl = document.getElementById('cashModalChangeSpan');
    changeEl.textContent = '$' + changeAmt.toFixed(2);
    changeEl.style.color = changeAmt < 0.005 ? '#86efac' : '#4ade80'; // exact change = soft green
    const rnoEl = document.getElementById('changeModalReceiptNo'); if(rnoEl) rnoEl.textContent = globalCurrentReceiptNo;
    hydrateReceiptBlock();
    document.getElementById('cashChangeOutputModal').classList.remove('hidden');
}

// ── PRINT HELPERS ─────────────────────────────────────────────────────────────
function hydrateReceiptBlock() {
    if (!currentCart.length) { console.warn('[POS] hydrateReceiptBlock called with empty cart'); return false; }
    let cal = getCurrentCalculations();
    document.getElementById('printMetaReceiptNo').textContent = 'RECEIPT: ' + globalCurrentReceiptNo;
    document.getElementById('printMetaTimestamp').textContent = 'TIME: ' + new Date().toLocaleString();
    const tbody = document.getElementById('printItemsListTableBody');
    tbody.innerHTML = '';
    let hasThrift = false;
    currentCart.forEach(item => {
        let lp = item.price * item.qty;
        if (item.category && item.category.toLowerCase().includes('thrift')) hasThrift = true;
        let tr = document.createElement('tr');
        tr.innerHTML = '<td style="padding:7px 0;text-align:left;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;word-break:break-word;">' + item.name + '</td>'
            + '<td style="padding:7px 0;text-align:center;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.qty + '</td>'
            + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + item.price.toFixed(2) + '</td>'
            + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + lp.toFixed(2) + '</td>';
        tbody.appendChild(tr);
    });
    document.getElementById('pSub').textContent = '$' + cal.suball.toFixed(2);
    document.getElementById('pTax').textContent = '$' + cal.tax.toFixed(2);
    document.getElementById('pTot').textContent = '$' + cal.totalDue.toFixed(2);
    document.getElementById('pMethod').textContent = globalCurrentMethod;
    if (cal.disc > 0) {
        document.getElementById('pDisc').textContent      = '-$' + cal.disc.toFixed(2) + ' (' + cartDiscountPercent + '%)';
        document.getElementById('pDiscRow').style.display = 'table-row';
    } else {
        document.getElementById('pDiscRow').style.display = 'none';
    }
    document.getElementById('pThriftDisclaimer').style.display = hasThrift ? 'block' : 'none';
    return true; // ← required: posPrintReceipt checks this
}

// ── PRINT + SAVE — fully decoupled so mobile redirect can't kill print job ───
// posPrintReceipt: prints AND auto-saves when dialog closes
function posPrintReceipt() {
    if (!hydrateReceiptBlock()) return;
    ['cashChangeOutputModal','mobileConfirmModal'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });
    // Mark as printed; auto-commit when print dialog closes
    wasPrinted = true;
    document.getElementById('formWasPrinted').value = '1';
    window.onafterprint = function() {
        window.onafterprint = null;
        executeFormCommit();
    };
    window.print();
}

// posCommitAndClose: saves WITHOUT printing (skip print path)
function posCommitAndClose() {
    ['cashChangeOutputModal','mobileConfirmModal'].forEach(id => {
        document.getElementById(id)?.classList.add('hidden');
    });
    executeFormCommit();
}

function executeFormCommit() {
    let cal = getCurrentCalculations();
    document.getElementById('formReceiptNo').value    = globalCurrentReceiptNo;
    document.getElementById('formCartJson').value     = JSON.stringify(currentCart);
    document.getElementById('formSubtotal').value     = cal.suball.toFixed(2);
    document.getElementById('formDiscount').value     = cal.disc.toFixed(2);
    document.getElementById('formTax').value          = cal.tax.toFixed(2);
    document.getElementById('formTotal').value        = cal.totalDue.toFixed(2);
    document.getElementById('formMethod').value       = globalCurrentMethod;
    document.getElementById('formDiscountPct').value  = cartDiscountPercent;
    document.getElementById('formTaxExempt').value    = taxExemptMode ? '1' : '0';
    // formWasPrinted already set in posPrintReceipt; posCommitAndClose leaves it 0
    document.getElementById('ledgerHiddenSubmissionForm').submit();
}

// ── REPRINT LAST RECEIPT (from session data) ──────────────────────────────────
<?php if ($last_receipt): ?>
const lastReceiptData = <?= json_encode($last_receipt) ?>;

function reprintLastReceipt() {
    try {
        const cart = JSON.parse(lastReceiptData.cart);
        document.getElementById('printMetaReceiptNo').textContent = 'RECEIPT: ' + lastReceiptData.number;
        document.getElementById('printMetaTimestamp').textContent = 'TIME: ' + lastReceiptData.saved_at;
        const tbody = document.getElementById('printItemsListTableBody');
        tbody.innerHTML = '';
        let hasThrift = false;
        cart.forEach(item => {
            let lp = parseFloat(item.price) * parseFloat(item.qty);
            if (item.category && item.category.toLowerCase().includes('thrift')) hasThrift = true;
            let tr = document.createElement('tr');
            tr.innerHTML = '<td style="padding:7px 0;text-align:left;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.name + '</td>'
                + '<td style="padding:7px 0;text-align:center;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">' + item.qty + '</td>'
                + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + parseFloat(item.price).toFixed(2) + '</td>'
                + '<td style="padding:7px 0;text-align:right;border-bottom:1px dashed #ccc;font-weight:bold;color:#000;">$' + lp.toFixed(2) + '</td>';
            tbody.appendChild(tr);
        });
        document.getElementById('pSub').textContent    = '$' + lastReceiptData.subtotal;
        document.getElementById('pTax').textContent    = '$' + lastReceiptData.tax;
        document.getElementById('pTot').textContent    = '$' + lastReceiptData.total;
        document.getElementById('pMethod').textContent = lastReceiptData.method;
        if (parseFloat(lastReceiptData.discount) > 0) {
            document.getElementById('pDisc').textContent      = '-$' + lastReceiptData.discount + ' (' + lastReceiptData.discount_pct + '%)';
            document.getElementById('pDiscRow').style.display = 'table-row';
        } else {
            document.getElementById('pDiscRow').style.display = 'none';
        }
        document.getElementById('pThriftDisclaimer').style.display = hasThrift ? 'block' : 'none';
        window.print();
    } catch(e) {
        sysAlert('Reprint error: ' + e.message, 'Print Error', '🖨️');
    }
}
<?php endif; ?>

// ── KEYBOARD SHORTCUTS ────────────────────────────────────────────────────────
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        // Close topmost visible modal
        const modals = ['mobileConfirmModal','cashChangeOutputModal','cashCalculatorModal',
                        'mobilePaymentModal','customItemModal','discountModal','clearTicketModal'];
        for (const id of modals) {
            const el = document.getElementById(id);
            if (el && !el.classList.contains('hidden')) {
                el.classList.add('hidden');
                if (id === 'cashCalculatorModal') cashCentsAccumulator = 0;
                break;
            }
        }
    }
    // Ctrl/Cmd + P = print last receipt if exists
    if ((e.ctrlKey || e.metaKey) && e.key === 'p') {
        const banner = document.getElementById('reprintBanner');
        if (banner) { e.preventDefault(); reprintLastReceipt?.(); }
    }
});

// ── BARCODE SCANNING ─────────────────────────────────────────────────────────
// Hardware scanner: emulates keyboard — rapid keystrokes + Enter
// Camera scanner: ZXing via getUserMedia

let scanBuffer       = '';
let scanLastKeyTime  = 0;
let scanTimeout      = null;
const SCAN_GAP_MS    = 80; // scanner fires chars faster than human typing

// Listen for hardware scanner input anywhere on the page
// Ignores keystrokes when user is actively in a text input
document.addEventListener('keydown', function(e) {
    const tag = (e.target.tagName || '').toUpperCase();
    const isTyping = ['INPUT','TEXTAREA','SELECT'].includes(tag) &&
                     e.target.id !== 'manualSkuInput'; // allow manual sku field passthrough
    if (isTyping) return;

    const now = Date.now();
    if (e.key === 'Enter') {
        if (scanBuffer.length >= 3) {
            processScannedSKU(scanBuffer.trim());
        }
        scanBuffer = ''; clearTimeout(scanTimeout); return;
    }
    if (e.key.length === 1) {
        if (now - scanLastKeyTime > 500) scanBuffer = ''; // reset if long pause
        scanBuffer += e.key;
        scanLastKeyTime = now;
        clearTimeout(scanTimeout);
        scanTimeout = setTimeout(() => { scanBuffer = ''; }, 300);
    }
});

function processScannedSKU(sku) {
    if (!sku) return;
    const allItems = Object.values(catalogueMatrix).flat();
    const item = allItems.find(i => i.sku && i.sku.toUpperCase() === sku.toUpperCase());
    if (item) {
        if (item.stock_quantity !== null && item.stock_quantity <= 0) {
            showScanFeedback('⚠ Out of stock: ' + item.name, true);
            return;
        }
        addItemToTicketQueue(item.id, item.name, parseFloat(item.price), item.tbl, item.category);
        showScanFeedback('✓ Added: ' + item.name, false);
        // Close camera modal after successful scan
        closeCameraScanModal();
    } else {
        showScanFeedback('✗ SKU not found: ' + sku, true);
    }
}

function showScanFeedback(msg, isError) {
    // Update scan status in modal if open
    const statusEl = document.getElementById('scanStatus');
    if (statusEl) {
        statusEl.textContent = msg;
        statusEl.style.color = isError ? '#b91c1c' : '#059669';
    }
    // Also flash a brief toast on the main screen
    let toast = document.getElementById('scanToast');
    if (!toast) {
        toast = document.createElement('div');
        toast.id = 'scanToast';
        toast.style.cssText = 'position:fixed;bottom:80px;left:50%;transform:translateX(-50%);z-index:9999;padding:10px 20px;border-radius:8px;font-mono;font-size:13px;font-weight:bold;transition:opacity 0.3s;white-space:nowrap;';
        document.body.appendChild(toast);
    }
    toast.textContent = msg;
    toast.style.background = isError ? '#991b1b' : '#065f46';
    toast.style.color = '#fff';
    toast.style.opacity = '1';
    clearTimeout(toast._t);
    toast._t = setTimeout(() => { toast.style.opacity = '0'; }, 2000);
}

// ── CAMERA SCANNER (ZXing) ───────────────────────────────────────────────────
let zxingReader   = null;
let scannerStream = null;

function openCameraScanModal() {
    document.getElementById('cameraScanModal').classList.remove('hidden');
    startCameraScanner();
}

function closeCameraScanModal() {
    document.getElementById('cameraScanModal').classList.add('hidden');
    stopCameraScanner();
    document.getElementById('scanStatus').textContent = 'Point camera at barcode…';
    document.getElementById('scanStatus').style.color = '';
}

function startCameraScanner() {
    const video = document.getElementById('scanVideo');
    const statusEl = document.getElementById('scanStatus');

    if (typeof ZXing === 'undefined') {
        statusEl.textContent = 'Barcode library loading…';
        setTimeout(startCameraScanner, 800);
        return;
    }

    try {
        zxingReader = new ZXing.BrowserMultiFormatReader();
        statusEl.textContent = 'Starting camera…';

        zxingReader.listVideoInputDevices().then(devices => {
            if (!devices || devices.length === 0) {
                statusEl.textContent = 'No camera found. Use manual entry below.';
                return;
            }
            // Prefer back camera on mobile
            const backCam = devices.find(d =>
                /back|rear|environment/i.test(d.label)) || devices[devices.length - 1];

            zxingReader.decodeFromVideoDevice(backCam.deviceId, 'scanVideo', (result, err) => {
                if (result) {
                    const scannedText = result.getText();
                    statusEl.textContent = '✓ Scanned: ' + scannedText;
                    statusEl.style.color = '#059669';
                    processScannedSKU(scannedText);
                    // Brief pause before next scan attempt
                    setTimeout(() => {
                        if (statusEl.textContent.startsWith('✓') || statusEl.textContent.startsWith('✗') || statusEl.textContent.startsWith('⚠')) {
                            statusEl.textContent = 'Point camera at barcode…';
                            statusEl.style.color = '';
                        }
                    }, 2500);
                }
                // err is normal when no barcode in frame — ignore
            });
            statusEl.textContent = 'Point camera at barcode…';
        }).catch(err => {
            statusEl.textContent = 'Camera error: ' + err.message;
        });
    } catch(e) {
        statusEl.textContent = 'Scanner error: ' + e.message;
    }
}

function stopCameraScanner() {
    try {
        if (zxingReader) {
            zxingReader.reset();
            zxingReader = null;
        }
    } catch(e) {}
}

// ── LIVE STATS REFRESH — update today's revenue/count every 60 seconds ────────
setInterval(function() {
    fetch('/admin/pos.php?ajax_today_stats=1')
        .then(function(r) { return r.json(); })
        .then(function(d) {
            var c = document.getElementById('todayCnt');
            var r = document.getElementById('todayRev');
            if (c) c.textContent = d.cnt;
            if (r) r.textContent = '$' + d.rev;
        })
        .catch(function() {}); // silent — don't disrupt POS on stat failure
}, 60000);

// ── LIVE CLOCK ───────────────────────────────────────────────────────────────
(function liveClock() {
    const el = document.getElementById('posClockDisplay');
    if (!el) return;
    function tick() {
        const n = new Date();
        el.textContent = n.toLocaleTimeString('en-US', {hour:'numeric', minute:'2-digit'});
    }
    tick();
    setInterval(tick, 10000);
})();

// ── INIT ──────────────────────────────────────────────────────────────────────
if (allCategories.length > 0) changeActiveCategoryTab(allCategories[0]);
</script>
</body>
</html>
