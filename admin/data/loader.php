<?php
// admin/data/loader.php
// Centralized data-gathering layer. Each query is isolated so a single
// table failure doesn't prevent the rest of the page from loading.

$active_records = [];
$edit_data      = null;
$low_stock_items = [];

// ── ALL RECIPES (for blog_posts linked_recipe_id dropdown) ──────────────────
$all_recipes = [];
try {
    if ($target_view === 'schema' && $target_table === 'blog_posts') {
        $all_recipes = $pdo->query("SELECT id, title FROM recipes ORDER BY title ASC")->fetchAll(PDO::FETCH_ASSOC);
    }
} catch (Throwable $e) {
    error_log('[ERP] Data loader: all_recipes failed: ' . $e->getMessage());
}

// ── SCHEMA VIEW DATA ──────────────────────────────────────────────────────────
if ($target_view === 'schema' && !empty($target_table)) {
    try {
        $active_records = $pdo->query("SELECT * FROM {$target_table} ORDER BY id DESC")->fetchAll();
    } catch (Throwable $e) {
        error_log("[ERP] Data loader: failed to load records for {$target_table}: " . $e->getMessage());
        $active_records = [];
    }

    if (isset($_GET['edit_id'])) {
        try {
            $stmt = $pdo->prepare("SELECT * FROM {$target_table} WHERE id = ?");
            $stmt->execute([filter_input(INPUT_GET, 'edit_id', FILTER_VALIDATE_INT)]);
            $edit_data = $stmt->fetch();
        } catch (Throwable $e) {
            error_log("[ERP] Data loader: failed to load edit_id for {$target_table}: " . $e->getMessage());
            $edit_data = null;
        }
    }
}

// ── REFERENCE LOOKUPS ─────────────────────────────────────────────────────────
$all_breeds = [];
try {
    $all_breeds = $pdo->query("SELECT * FROM breeds ORDER BY breed_name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: breeds failed: ' . $e->getMessage()); }

$all_classes = [];
try {
    $all_classes = $pdo->query("SELECT * FROM classifications ORDER BY class_name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: classifications failed: ' . $e->getMessage()); }

$all_ingredients = [];
try {
    $all_ingredients = $pdo->query("SELECT * FROM ingredients ORDER BY singular_name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: ingredients failed: ' . $e->getMessage()); }

$all_units = [];
try {
    $all_units = $pdo->query("SELECT * FROM measurement_units ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: measurement_units failed: ' . $e->getMessage()); }

$all_categories = [];
try {
    $all_categories = $pdo->query("SELECT * FROM categories ORDER BY category_name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: categories failed: ' . $e->getMessage()); }

$all_blog_categories = [];
try {
    $all_blog_categories = $pdo->query("SELECT * FROM blog_categories ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: blog_categories failed: ' . $e->getMessage()); }

$all_inventory_categories = [];
try {
    $all_inventory_categories = $pdo->query("SELECT * FROM inventory_categories ORDER BY sort_order ASC, name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: inventory_categories failed: ' . $e->getMessage()); }

$all_cuisines = [];
try {
    $all_cuisines = $pdo->query("SELECT * FROM cuisines ORDER BY name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: cuisines failed: ' . $e->getMessage()); }

$all_staff_members = [];
try {
    $all_staff_members = $pdo->query("SELECT id, first_name, last_name, role FROM users ORDER BY last_name ASC")->fetchAll();
} catch (Throwable $e) { error_log('[ERP] Data loader: staff members failed: ' . $e->getMessage()); }

// ── DASHBOARD DATA ────────────────────────────────────────────────────────────
// Every query isolated in its own try/catch — one broken table never
// takes down the whole dashboard.

$low_stock_items  = [];
$sales_today      = ['rev' => 0, 'count' => 0];
$sales_yesterday  = ['rev' => 0];
$sales_7day_avg   = 0;
$forecast_items   = [];
$forecast_trends  = [];

if ($target_view === 'dashboard') {

    // Low stock — from unified inventory table
    try {
        $low_stock_items = $pdo->query("
            SELECT category AS cat, name, stock_quantity
            FROM   inventory
            WHERE  is_active = 1
              AND  stock_quantity < 5
            ORDER  BY stock_quantity ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: low stock query failed: ' . $e->getMessage());
    }

    // Today's sales
    try {
        $sales_today = $pdo->query("
            SELECT COALESCE(SUM(total), 0) AS rev, COUNT(*) AS count
            FROM   pos_receipts
            WHERE  DATE(created_at) = CURDATE()
        ")->fetch();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: sales today query failed: ' . $e->getMessage());
    }

    // Yesterday's revenue for comparison
    try {
        $sales_yesterday = $pdo->query("
            SELECT COALESCE(SUM(total), 0) AS rev
            FROM   pos_receipts
            WHERE  DATE(created_at) = CURDATE() - INTERVAL 1 DAY
        ")->fetch();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: sales yesterday query failed: ' . $e->getMessage());
    }

    // 7-day average daily revenue
    try {
        $result = $pdo->query("
            SELECT COALESCE(SUM(total), 0) / 7 AS avg_daily
            FROM   pos_receipts
            WHERE  created_at >= CURDATE() - INTERVAL 7 DAY
        ")->fetch();
        $sales_7day_avg = (float)($result['avg_daily'] ?? 0);
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: 7-day avg query failed: ' . $e->getMessage());
    }

    // Forecast velocity — FETCH_ASSOC + manual build avoids FETCH_KEY_PAIR fragility
    try {
        $rows = $pdo->query("
            SELECT   inventory_id,
                     SUM(quantity_sold) / 30.0 AS daily_velocity
            FROM     sales_history
            WHERE    sale_date >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            GROUP BY inventory_id
        ")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($rows as $row) {
            $forecast_trends[(int)$row['inventory_id']] = (float)$row['daily_velocity'];
        }
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: forecast trends query failed: ' . $e->getMessage());
    }

    // Forecast items — COALESCE guards against missing min_stock_level column
    try {
        $forecast_items = $pdo->query("
            SELECT id, name, category, stock_quantity,
                   COALESCE(min_stock_level, 0) AS min_stock_level
            FROM   inventory
            WHERE  is_active = 1
            ORDER  BY name ASC
        ")->fetchAll();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: forecast items query failed: ' . $e->getMessage());
    }

    // Lazy expiry: flip auto-published comments to pending after 24h
    try {
        $pdo->exec("
            UPDATE recipe_comments
            SET    status = 'pending'
            WHERE  status = 'published'
              AND  manually_approved = 0
              AND  created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: lazy comment expiry failed: ' . $e->getMessage());
    }

    // Pending comments count for dashboard badge
    $pending_comments_count = 0;
    try {
        $pending_comments_count = (int)$pdo->query("
            SELECT COUNT(*) FROM recipe_comments WHERE status IN ('pending','flagged')
        ")->fetchColumn();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: pending comments count failed: ' . $e->getMessage());
    }

    // Active staff shifts
    try {
        $active_shifts = $pdo->query("
            SELECT u.first_name, u.last_name
            FROM   time_cards t
            JOIN   users u ON t.user_id = u.id
            WHERE  t.clock_out IS NULL
        ")->fetchAll();
    } catch (Throwable $e) {
        error_log('[ERP] Dashboard: active shifts query failed: ' . $e->getMessage());
        $active_shifts = [];
    }

}

// ── TIMECARD VIEW DATA ────────────────────────────────────────────────────────
$active_shift   = null;
$my_shift_rows  = [];
$weekly_seconds = 0;

if ($target_view === 'timecard') {

    // Fix 1: only select columns we need, not SELECT *
    try {
        $chk = $pdo->prepare("
            SELECT id, clock_in, notes
            FROM   time_cards
            WHERE  user_id    = ?
              AND  clock_out IS NULL
            ORDER  BY id DESC
            LIMIT  1
        ");
        $chk->execute([$current_user_id]);
        $active_shift = $chk->fetch() ?: null;
    } catch (Throwable $e) {
        error_log('[ERP] Timecard: active shift query failed: ' . $e->getMessage());
    }

    // Weekly log rows — used for table display and hours total
    try {
        $wk = $pdo->prepare("
            SELECT id, clock_in, clock_out, ip_address, notes
            FROM   time_cards
            WHERE  user_id  = ?
              AND  clock_in >= DATE_SUB(NOW(), INTERVAL 7 DAY)
            ORDER  BY id DESC
        ");
        $wk->execute([$current_user_id]);
        $my_shift_rows = $wk->fetchAll();
    } catch (Throwable $e) {
        error_log('[ERP] Timecard: weekly logs query failed: ' . $e->getMessage());
    }

    // Fix 6: total completed seconds this week (excludes any still-active shift)
    foreach ($my_shift_rows as $row) {
        if ($row['clock_out']) {
            $weekly_seconds += strtotime($row['clock_out']) - strtotime($row['clock_in']);
        }
    }
}

// ── MEDIA VIEW DATA ───────────────────────────────────────────────────────────
// Fix 6+10: vault scan moved here from the view, with a 300-image cap
$vault_images     = [];   // ['url' => '/img/...', 'folder' => 'zoo', 'size' => 41200]
$vault_folders    = [];   // distinct subfolder names for the filter tabs
$vault_scan_capped = false;
$vault_cap         = 300;

if ($target_view === 'media') {
    try {
        $img_root = rtrim($_SERVER['DOCUMENT_ROOT'], '/') . '/img';
        if (is_dir($img_root)) {
            $doc_root  = str_replace('\\', '/', $_SERVER['DOCUMENT_ROOT']);
            $iterator  = new RecursiveIteratorIterator(
                new RecursiveDirectoryIterator($img_root, FilesystemIterator::SKIP_DOTS)
            );
            $count = 0;
            foreach ($iterator as $file) {
                if (!$file->isFile()) continue;
                if (!in_array(strtolower($file->getExtension()), ['jpg', 'jpeg', 'png', 'webp'])) continue;

                if ($count >= $vault_cap) { $vault_scan_capped = true; break; }

                $abs      = str_replace('\\', '/', $file->getPathname());
                $url      = str_replace($doc_root, '', $abs);
                // Fix 7: derive subfolder label from path segment after /img/
                $parts    = explode('/', trim(str_replace('/img', '', $url), '/'));
                $folder   = count($parts) > 1 ? $parts[0] : '_root';

                if (!in_array($folder, $vault_folders)) $vault_folders[] = $folder;

                $vault_images[] = [
                    'url'    => $url,
                    'folder' => $folder,
                    'size'   => $file->getSize(),  // Fix 8: file size in bytes
                ];
                $count++;
            }
        }
    } catch (Throwable $e) {
        error_log('[ERP] Media vault scan failed: ' . $e->getMessage());
    }

    sort($vault_folders);
}

// ── INVENTORY UPDATE VIEW DATA ────────────────────────────────────────────────
$inventory_items  = [];
$inventory_units  = [];

if ($target_view === 'inventory_update') {

    try {
        $inventory_items = $pdo->query("
            SELECT   id, category, name, price,
                     size_value, size_unit_id,
                     stock_quantity, min_stock_level, image_url
            FROM     inventory
            WHERE    is_active = 1
            ORDER BY category ASC, name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ERP] Inventory update: items query failed: ' . $e->getMessage());
    }

    try {
        $rows = $pdo->query("SELECT id, name FROM measurement_units ORDER BY name ASC")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($rows as $u) $inventory_units[(int)$u['id']] = $u['name'];
    } catch (Throwable $e) {
        error_log('[ERP] Inventory update: units query failed: ' . $e->getMessage());
    }

}

// ── NEWSLETTER VIEW DATA ──────────────────────────────────────────────────────
$nl_subscribers    = [];
$nl_segment_counts = ['all' => 0, 'nursery' => 0, 'inventory' => 0];
$nl_campaigns      = [];

if ($target_view === 'newsletter') {

    try {
        // SELECT * — let PHP handle missing columns via null coalescing
        // so the query works regardless of which optional columns your table has
        $raw_subs = $pdo->query("SELECT * FROM mailing_list ORDER BY id DESC")->fetchAll(PDO::FETCH_ASSOC);

        foreach ($raw_subs as $row) {
            $nl_subscribers[] = [
                'id'                    => $row['id'],
                'email'                 => $row['email'] ?? '',
                'first_name'            => $row['first_name']            ?? '',
                'last_name'             => $row['last_name']             ?? '',
                'is_subscribed'         => (int)($row['is_subscribed']         ?? 1), // default active if column missing
                'track_nursery_updates' => (int)($row['track_nursery_updates']  ?? 0),
                'track_inventory_alerts'=> (int)($row['track_inventory_alerts'] ?? 0),
            ];
        }
    } catch (Throwable $e) {
        error_log('[ERP] Newsletter: subscriber query failed: ' . $e->getMessage());
    }

    // Segment counts — only subscribed people
    foreach ($nl_subscribers as $sub) {
        if (!$sub['is_subscribed']) continue;
        $nl_segment_counts['all']++;
        if ($sub['track_nursery_updates'])  $nl_segment_counts['nursery']++;
        if ($sub['track_inventory_alerts']) $nl_segment_counts['inventory']++;
    }

    try {
        $raw_campaigns = $pdo->query("SELECT * FROM campaigns ORDER BY id DESC LIMIT 30")->fetchAll(PDO::FETCH_ASSOC);
        foreach ($raw_campaigns as $row) {
            $nl_campaigns[] = [
                'id'              => $row['id'],
                'subject'         => $row['subject']         ?? '(no subject)',
                'segment'         => $row['segment']         ?? 'all',
                'from_name'       => $row['from_name']       ?? '',
                'recipient_count' => $row['recipient_count'] ?? 0,
                'is_test'         => $row['is_test']         ?? 0,
                'status'          => $row['status']          ?? 'sent',
                'created_at'      => $row['created_at']      ?? '',
            ];
        }
    } catch (Throwable $e) {
        error_log('[ERP] Newsletter: campaign history query failed: ' . $e->getMessage());
    }

}

// ── TIMESHEET VIEW DATA ───────────────────────────────────────────────────────
$ts_staff       = [];
$ts_shifts      = [];
$ts_summary     = [];
$ts_date_from   = '';
$ts_date_to     = '';
$ts_filter_user = 0;

if ($target_view === 'timesheet') {

    // Load all staff for the selector
    try {
        $ts_staff = $pdo->query("
            SELECT id, first_name, last_name, role
            FROM   users
            WHERE  role != 'user'
            ORDER  BY last_name ASC, first_name ASC
        ")->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ERP] Timesheet: staff query failed: ' . $e->getMessage());
    }

    // Date range from preset or custom inputs
    $preset = $_GET['preset'] ?? 'this_week';
    $ts_filter_user = (int)($_GET['user_id'] ?? 0);

    switch ($preset) {
        case 'last_week':
            $ts_date_from = date('Y-m-d', strtotime('monday last week'));
            $ts_date_to   = date('Y-m-d', strtotime('sunday last week'));
            break;
        case 'this_month':
            $ts_date_from = date('Y-m-01');
            $ts_date_to   = date('Y-m-d');
            break;
        case 'last_month':
            $ts_date_from = date('Y-m-01', strtotime('first day of last month'));
            $ts_date_to   = date('Y-m-t',  strtotime('last day of last month'));
            break;
        case 'custom':
            $ts_date_from = $_GET['date_from'] ?? date('Y-m-d', strtotime('-7 days'));
            $ts_date_to   = $_GET['date_to']   ?? date('Y-m-d');
            break;
        default: // this_week
            $ts_date_from = date('Y-m-d', strtotime('monday this week'));
            $ts_date_to   = date('Y-m-d');
            $preset       = 'this_week';
    }

    // Clamp dates to sensible range
    $ts_date_from = max($ts_date_from, '2020-01-01');
    $ts_date_to   = min($ts_date_to,   date('Y-m-d'));

    // Build WHERE clause
    $where_parts  = ["DATE(t.clock_in) >= ?", "DATE(t.clock_in) <= ?"];
    $where_params = [$ts_date_from, $ts_date_to];
    if ($ts_filter_user > 0) {
        $where_parts[]  = "t.user_id = ?";
        $where_params[] = $ts_filter_user;
    }
    $where_sql = implode(' AND ', $where_parts);

    // Load shift rows with staff name joined
    try {
        $stmt = $pdo->prepare("
            SELECT t.id, t.user_id, t.clock_in, t.clock_out, t.ip_address,
                   COALESCE(t.notes, '') AS notes,
                   u.first_name, u.last_name, u.role
            FROM   time_cards t
            JOIN   users u ON t.user_id = u.id
            WHERE  {$where_sql}
              AND  u.role != 'user'
            ORDER  BY t.clock_in DESC
        ");
        $stmt->execute($where_params);
        $ts_shifts = $stmt->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) {
        error_log('[ERP] Timesheet: shifts query failed: ' . $e->getMessage());
    }

    // Build summary — total seconds and shift count per staff member
    foreach ($ts_shifts as $shift) {
        $uid = $shift['user_id'];
        if (!isset($ts_summary[$uid])) {
            $ts_summary[$uid] = [
                'name'    => trim($shift['first_name'] . ' ' . $shift['last_name']),
                'role'    => $shift['role'],
                'seconds' => 0,
                'shifts'  => 0,
                'active'  => false,
            ];
        }
        $ts_summary[$uid]['shifts']++;
        if ($shift['clock_out']) {
            $ts_summary[$uid]['seconds'] += strtotime($shift['clock_out']) - strtotime($shift['clock_in']);
        } else {
            $ts_summary[$uid]['active'] = true; // currently on shift
        }
    }

    // Sort summary by most hours desc
    uasort($ts_summary, fn($a, $b) => $b['seconds'] <=> $a['seconds']);
}

// ── COMMENT MODERATION VIEW DATA ──────────────────────────────────────────────
$mod_comments           = [];
$mod_pending_count      = 0;
$mod_flagged_count      = 0;

if ($target_view === 'comment_moderation') {

    // Lazy expiry on this page too
    try {
        $pdo->exec("
            UPDATE recipe_comments
            SET    status = 'pending'
            WHERE  status = 'published'
              AND  manually_approved = 0
              AND  created_at < DATE_SUB(NOW(), INTERVAL 24 HOUR)
        ");
    } catch (Throwable $e) {
        error_log('[ERP] Moderation: lazy expiry failed: ' . $e->getMessage());
    }

    // Load all unmoderated comments (pending + flagged)
    try {
        $stmt = $pdo->query("
            SELECT  c.id, c.recipe_id, c.user_id, c.comment_text,
                    c.status, c.created_at, c.updated_at,
                    r.title      AS recipe_title,
                    u.first_name, u.last_name, u.role,
                    COALESCE(u.comment_restricted_until, NULL) AS restricted_until,
                    (SELECT COUNT(*) FROM recipe_comments rc2
                     WHERE  rc2.user_id = c.user_id
                       AND  rc2.status  = 'rejected'
                       AND  rc2.updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
                    ) AS recent_rejections
            FROM    recipe_comments c
            JOIN    recipes r ON c.recipe_id = r.id
            JOIN    users   u ON c.user_id   = u.id
            WHERE   c.status IN ('pending','flagged')
            ORDER   BY c.created_at ASC
        ");
        $mod_comments      = $stmt->fetchAll(PDO::FETCH_ASSOC);
        $mod_pending_count = count(array_filter($mod_comments, fn($c) => $c['status'] === 'pending'));
        $mod_flagged_count = count(array_filter($mod_comments, fn($c) => $c['status'] === 'flagged'));
    } catch (Throwable $e) {
        error_log('[ERP] Moderation: comments query failed: ' . $e->getMessage());
    }
}

