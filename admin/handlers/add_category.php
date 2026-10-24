<?php
// admin/handlers/add_category.php
// AJAX endpoint — adds a new category to the categories table.

// Buffer all output so any PHP warnings/errors don't corrupt the JSON response
ob_start();

// Set JSON header immediately — before any possible redirect or output
header('Content-Type: application/json');

function json_exit(bool $ok, string $message, array $extra = [], int $status = 200): void {
    // Discard any stray output (PHP notices, session headers, etc.)
    ob_end_clean();
    http_response_code($status);
    echo json_encode(array_merge(['ok' => $ok, 'message' => $message], $extra));
    exit;
}

require_once '../../config.php';
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log

// Check session manually rather than calling check_admin() which may redirect
if (session_status() !== PHP_SESSION_ACTIVE) session_start();   // config.php usually started it already (fix pack O)
if (empty($_SESSION['user_id']) || empty($_SESSION['user_role'])) {
    json_exit(false, 'Not authenticated.', [], 401);
}
$allowed_roles = ['administrator', 'moderator', 'Shopkeep'];
if (!in_array($_SESSION['user_role'], $allowed_roles)) {
    json_exit(false, 'Insufficient permissions.', [], 403);
}

date_default_timezone_set('America/New_York');

// ── CSRF ──────────────────────────────────────────────────────────────────────
$posted_token = $_POST['csrf_token'] ?? '';
if (empty($posted_token) || !hash_equals($_SESSION['csrf_token'] ?? '', $posted_token)) {
    json_exit(false, 'Security token expired. Refresh and try again.', [], 403);
}

// ── VALIDATE ──────────────────────────────────────────────────────────────────
$category_name = trim($_POST['category_name'] ?? '');

if (empty($category_name)) {
    json_exit(false, 'Category name cannot be empty.', [], 400);
}
if (mb_strlen($category_name) > 80) {
    json_exit(false, 'Category name too long (max 80 characters).', [], 400);
}

// ── SAVE ──────────────────────────────────────────────────────────────────────
$context = trim($_POST['context'] ?? 'recipes'); // 'recipes' or 'blog'

try {
    if ($context === 'blog') {
        // Blog categories — separate table, name + slug
        $slug = preg_replace('/[^a-z0-9]+/', '-', strtolower($category_name));
        $slug = trim($slug, '-');

        $check = $pdo->prepare("SELECT id FROM blog_categories WHERE name = ? OR slug = ?");
        $check->execute([$category_name, $slug]);
        if ($existing = $check->fetch()) {
            json_exit(false, 'A blog category with that name already exists.', ['id' => $existing['id'], 'name' => $category_name]);
        }

        $stmt = $pdo->prepare("INSERT INTO blog_categories (name, slug) VALUES (?, ?)");
        $stmt->execute([$category_name, $slug]);
        $new_id = (int)$pdo->lastInsertId();
        erp_audit($pdo, 'create', 'blog_categories', $new_id, $category_name);

    } else {
        // Recipe categories
        $check = $pdo->prepare("SELECT id FROM categories WHERE category_name = ?");
        $check->execute([$category_name]);
        if ($existing = $check->fetch()) {
            json_exit(false, 'A category with that name already exists.', ['id' => $existing['id'], 'name' => $category_name]);
        }

        $stmt = $pdo->prepare("INSERT INTO categories (category_name) VALUES (?)");
        $stmt->execute([$category_name]);
        $new_id = (int)$pdo->lastInsertId();
        erp_audit($pdo, 'create', 'categories', $new_id, $category_name);
    }

    json_exit(true, 'Category created.', ['id' => $new_id, 'name' => $category_name]);

} catch (Throwable $e) {
    error_log('[ERP] add_category failed: ' . $e->getMessage());
    json_exit(false, 'Database error — check server logs.', [], 500);
}
