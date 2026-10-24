<?php
ob_start(); // Buffer all output — prevents 'headers already sent' on redirects
// admin/admin.php — Bat Cave Road Farm Unified ERP Engine
// Modular entry point. Each component is wrapped in try/catch so a
// broken module shows a warning banner instead of a white screen.

require_once '../config.php';
check_admin();

// ── LIVE ACCOUNT CHECK (Jobs pack P6) ────────────────────────────────────────
// The session remembers the role from sign-in. Re-read it on every request so a
// login that was turned off (or a role that changed) takes effect right away.
if (!empty($_SESSION['user_id']) && isset($pdo)) {
    try {
        $__acct = $pdo->prepare('SELECT role, account_status FROM users WHERE id = ?');
        $__acct->execute([(int)$_SESSION['user_id']]);
        $__acct = $__acct->fetch(PDO::FETCH_ASSOC);
        $__staffRoles = ['administrator', 'moderator', 'Shopkeep', 'Media', 'Staff'];
        if (!$__acct || ($__acct['account_status'] ?? 'active') === 'disabled' || !in_array($__acct['role'], $__staffRoles, true)) {
            $_SESSION = [];
            if (session_status() === PHP_SESSION_ACTIVE) session_destroy();
            ob_end_clean();
            header('Location: /admin/login.php');
            exit;
        }
        if ($__acct['role'] !== ($_SESSION['user_role'] ?? '')) {
            $_SESSION['user_role'] = $__acct['role'];
            check_admin();   // re-apply the Staff → Time Clock only rule for the new role
        }
    } catch (Throwable $e) {
        // users.account_status missing (Jobs P1 not installed): keep the old behaviour.
    }
    unset($__acct, $__staffRoles);
}

// ── TIMEZONE ─────────────────────────────────────────────────────────────────
// Set once here so every view, query, and date() call uses Eastern time.
date_default_timezone_set('America/New_York');

// ── CSRF ──────────────────────────────────────────────────────────────────────
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// ── CONTEXT ───────────────────────────────────────────────────────────────────
$current_user_id = (int)$_SESSION['user_id'];
$current_role    = $_SESSION['user_role'] ?? 'user';
$target_view     = $_GET['view']  ?? 'dashboard';
$target_table    = $_GET['table'] ?? '';

$system_tables = [
    'users', 'mailing_list', 'breeds', 'classifications', 'animals',
    'fruits_vegetables', 'baked_goods', 'pantry', 'thrift_finds',
    'petting_zoo_products', 'ingredients', 'measurement_units',
    'unit_conversions', 'categories', 'recipes', 'blog_posts', 'inventory',
    'recipe_ratings', 'recipe_comments',
    'inventory_categories', 'blog_categories', 'cuisines',
];

// ── SAFE INCLUDE HELPER ───────────────────────────────────────────────────────
// NOTE: This is NOT a function on purpose. Using a function would create a new
// scope and hide $pdo, $current_role, $target_view, etc. from included files.
// Instead, use this as a snippet pattern — each block below is self-contained.

// ── INCLUDES ──────────────────────────────────────────────────────────────────
try {
    require __DIR__ . '/includes/schema_safety.php';
} catch (Throwable $e) {
    error_log('[ERP] Schema Safety failed: ' . $e->getMessage());
}

try {
    require __DIR__ . '/includes/permissions.php';
} catch (Throwable $e) {
    error_log('[ERP] Permissions include failed: ' . $e->getMessage());
    die("<div class='p-4 text-red-700 font-mono text-xs'>Permissions system unavailable.</div>");
}

// ── POST PERMISSION GATE (Jobs pack P1) ──────────────────────────────────────
// Save actions run before the screen check below, and most handlers don't check
// the role themselves. So check here: a save is only allowed for a role that
// may open the screen it belongs to (table edits need "edit" on that table).
// Administrators are never blocked.
if ($_SERVER['REQUEST_METHOD'] === 'POST' && $current_role !== 'administrator') {
    $__post_gate = [
        'time_clock_action'        => ['view', 'timecard'],
        'site_info_action'         => ['view', 'site_info'],
        'execute_image_processing' => ['view', 'media'],
        'execute_image_rotate'     => ['view', 'media'],
        'save_permissions_matrix'  => ['admin', ''],
        'moderate_comments'        => ['view', 'comment_moderation'],
        'theme_action'             => ['admin', ''],
        'save_schema_record'       => ['table', $target_table],
        'event_action'             => ['view', 'events'],
        'bookings_action'          => ['view', 'bookings'],
        'inquiry_action'           => ['view', 'event_inquiries'],
        'pass_action'              => ['view', 'passes'],
        'jobs_setup_action'        => ['admin', ''],
        'jobs_pipeline_action'     => ['admin', ''],
        'jobs_emails_action'       => ['admin', ''],
        'jobs_hire_action'         => ['admin', ''],
        'jobs_alerts_action'       => ['admin', ''],
    ];
    foreach ($__post_gate as $__key => [$__type, $__val]) {
        if (!isset($_POST[$__key])) continue;
        $__allowed = match ($__type) {
            'view'  => $current_role === 'Staff' ? $__val === 'timecard' : hasViewAccess($current_role, $__val),
            'table' => $current_role !== 'Staff' && $__val !== '' && hasTableAccess($current_role, $__val, 'edit'),
            default => false,
        };
        if (!$__allowed) {
            error_log('[ERP] Blocked POST ' . $__key . ' for role ' . $current_role);
            $_SESSION['toast_msg']  = 'Your role is not allowed to make that change.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=' . ($current_role === 'Staff' ? 'timecard' : 'dashboard'));
            exit;
        }
    }
    // Staff may only clock in/out — any other POST is refused.
    if ($current_role === 'Staff' && !isset($_POST['time_clock_action'])) {
        header('Location: /admin/admin.php?view=timecard');
        exit;
    }
}

// ── POST HANDLERS ─────────────────────────────────────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    if (isset($_POST['time_clock_action'])) {
        try { require __DIR__ . '/handlers/time_clock.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Time Clock handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Time clock error — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: ' . $_SERVER['REQUEST_URI']); exit;
        }
    }
    if (isset($_POST['site_info_action'])) {
        try { require __DIR__ . '/handlers/site_info_save.php'; }
        catch (Throwable $e) { error_log('[ERP] Site Info handler failed: ' . $e->getMessage()); }
    }
    if (isset($_POST['execute_image_processing'])) {
        try { require __DIR__ . '/handlers/image_processing.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Image Processing handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Image pipeline error: ' . $e->getMessage();
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=media'); exit;
        }
    }
    if (isset($_POST['execute_image_rotate'])) {
        try { require __DIR__ . '/handlers/image_rotate.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Image Rotate handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Rotate error: ' . $e->getMessage();
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=media'); exit;
        }
    }
    if (isset($_POST['save_permissions_matrix'])) {
        try { require __DIR__ . '/handlers/permissions_matrix.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Permissions Matrix handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Permissions save error.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=permissions'); exit;
        }
    }
    if (isset($_POST['moderate_comments'])) {
        try { require __DIR__ . '/handlers/moderate_comments.php'; }
        catch (Throwable $e) {
            error_log('[ERP] moderate_comments handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Moderation error.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=comment_moderation'); exit;
        }
    }
    if (isset($_POST['theme_action'])) {
        try { require __DIR__ . '/handlers/theme_save.php'; }
        catch (Throwable $e) {
            error_log('[ERP] theme_save handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Theme error.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=theme_scheduler'); exit;
        }
    }
    if (isset($_POST['save_schema_record'])) {
        try { require __DIR__ . '/handlers/schema_record.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Schema Record handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Database write error: ' . $e->getMessage();
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=schema&table=' . urlencode($target_table)); exit;
        }
    }
    if (isset($_POST['event_action'])) {
        try { require __DIR__ . '/handlers/event_save.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Event handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Event save error — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=events'); exit;
        }
    }
    if (isset($_POST['bookings_action'])) {
        try { require __DIR__ . '/handlers/bookings_action.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Bookings handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Booking action error — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=bookings'); exit;
        }
    }
    if (isset($_POST['inquiry_action'])) {
        try { require __DIR__ . '/handlers/inquiry_action.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Inquiry handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Inquiry action failed — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=event_inquiries'); exit;
        }
    }
    if (isset($_POST['jobs_setup_action'])) {
        try { require __DIR__ . '/handlers/jobs_setup.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Jobs setup handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Hiring setup save failed — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=jobs_posts'); exit;
        }
    }
    if (isset($_POST['jobs_pipeline_action'])) {
        try { require __DIR__ . '/handlers/jobs_pipeline.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Jobs pipeline handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Applicant change failed — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=jobs'); exit;
        }
    }
    if (isset($_POST['jobs_emails_action'])) {
        try { require __DIR__ . '/handlers/jobs_emails.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Jobs emails handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Email change failed — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=jobs_emails'); exit;
        }
    }
    if (isset($_POST['jobs_alerts_action'])) {
        try { require __DIR__ . '/handlers/jobs_alerts.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Jobs alerts handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'That didn\'t work. Please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=jobs_alerts'); exit;
        }
    }
    if (isset($_POST['jobs_hire_action'])) {
        try { require __DIR__ . '/handlers/jobs_hire.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Jobs hire handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Hire change failed — nothing was saved. Please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=jobs_staff'); exit;
        }
    }
    if (isset($_POST['pass_action'])) {
        try { require __DIR__ . '/handlers/pass_action.php'; }
        catch (Throwable $e) {
            error_log('[ERP] Pass handler failed: ' . $e->getMessage());
            $_SESSION['toast_msg'] = 'Pass action failed — please try again.';
            $_SESSION['toast_type'] = 'error';
            header('Location: /admin/admin.php?view=passes'); exit;
        }
    }
}

// ── DELETE HANDLER ────────────────────────────────────────────────────────────
if (isset($_GET['delete_id'])) {
    try { require __DIR__ . '/handlers/schema_delete.php'; }
    catch (Throwable $e) {
        error_log('[ERP] Schema Delete handler failed: ' . $e->getMessage());
        $_SESSION['toast_msg'] = 'Delete failed — please try again.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=schema&table=' . urlencode($target_table)); exit;
    }
}

// ── TOAST STATE ───────────────────────────────────────────────────────────────
$toastMessage = $_SESSION['toast_msg']  ?? '';
$toastType    = $_SESSION['toast_type'] ?? 'success';
unset($_SESSION['toast_msg'], $_SESSION['toast_type']);

// ── DATA LAYER ────────────────────────────────────────────────────────────────
try {
    require __DIR__ . '/data/loader.php';
} catch (Throwable $e) {
    error_log('[ERP] Data Loader failed: ' . $e->getMessage());
    // Ensure view variables exist even if loader partially failed
    $active_records  = $active_records  ?? [];
    $edit_data       = $edit_data       ?? null;
    $low_stock_items = $low_stock_items ?? [];
    $all_breeds      = $all_breeds      ?? [];
    $all_classes     = $all_classes     ?? [];
    $all_ingredients = $all_ingredients ?? [];
    $all_units       = $all_units       ?? [];
    $all_categories  = $all_categories  ?? [];
    $all_staff_members = $all_staff_members ?? [];
}

// ── RENDER ────────────────────────────────────────────────────────────────────
try { require __DIR__ . '/views/layout/head.php'; }
catch (Throwable $e) { echo '<!DOCTYPE html><html><head><meta charset="UTF-8"><title>ERP</title></head>'; }

echo '<body class="bg-stone-100 min-h-screen text-stone-900 flex text-xs select-none">';

try { require __DIR__ . '/views/layout/toast.php'; }
catch (Throwable $e) { error_log('[ERP] Toast failed: ' . $e->getMessage()); }

try { require __DIR__ . '/views/layout/sidebar.php'; }
catch (Throwable $e) {
    error_log('[ERP] Sidebar failed: ' . $e->getMessage());
    echo '<aside class="w-64 bg-stone-900 p-4 text-red-400 text-xs">Navigation unavailable</aside>';
}

echo '<main class="flex-1 p-6 overflow-y-auto max-h-screen">';

$view_map = [
    'dashboard'   => __DIR__ . '/views/dashboard.php',
    'receipts'    => __DIR__ . '/views/receipts.php',
    'timecard'    => __DIR__ . '/views/timecard.php',
    'media'       => __DIR__ . '/views/media.php',
    'permissions' => __DIR__ . '/views/permissions.php',
    'schema'           => __DIR__ . '/views/schema.php',
    'inventory_update' => __DIR__ . '/views/inventory_update.php',
    'newsletter'       => __DIR__ . '/views/newsletter.php',
    'timesheet'          => __DIR__ . '/views/timesheet.php',
    'comment_moderation' => __DIR__ . '/views/comment_moderation.php',
    'theme_scheduler'    => __DIR__ . '/views/theme_scheduler.php',
    'audit_log'          => __DIR__ . '/views/audit_log.php',
    'site_info'          => __DIR__ . '/views/site_info.php',
    'sales_analytics'    => __DIR__ . '/views/sales_analytics.php',
    'events'           => __DIR__ . '/views/events.php',
    'bookings'         => __DIR__ . '/views/bookings.php',
    'event_inquiries'  => __DIR__ . '/views/event_inquiries.php',
    'passes'           => __DIR__ . '/views/passes.php',
    'social' => __DIR__ . '/views/social.php',
    'jobs'             => __DIR__ . '/views/jobs/applicants.php',
    'jobs_emails'      => __DIR__ . '/views/jobs/emails.php',
    'jobs_hire'        => __DIR__ . '/views/jobs/hire.php',
    'jobs_staff'       => __DIR__ . '/views/jobs/staff.php',
    'jobs_alerts'      => __DIR__ . '/views/jobs/alerts.php',
    'jobs_posts'       => __DIR__ . '/views/jobs/posts.php',
    'jobs_questions'   => __DIR__ . '/views/jobs/questions.php',
    'jobs_categories'  => __DIR__ . '/views/jobs/categories.php',
];

$view_file = $view_map[$target_view] ?? null;
if ($view_file && file_exists($view_file)) {
    try { require $view_file; }
    catch (Throwable $e) {
        error_log('[ERP] View [' . $target_view . '] failed: ' . $e->getMessage());
        echo "<div class='p-4 bg-red-50 border border-red-200 text-red-700 font-mono text-xs rounded'>
                ⚠️ The <strong>" . htmlspecialchars($target_view) . "</strong> panel is temporarily unavailable.
              </div>";
    }
} else {
    echo "<p class='text-stone-400 italic p-6'>Unknown view requested.</p>";
}

ob_end_flush();
echo '</main></body></html>';
