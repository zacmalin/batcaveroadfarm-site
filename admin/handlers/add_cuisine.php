<?php
require_once '../../config.php';
check_admin();

header('Content-Type: application/json');

function json_out(bool $ok, string $msg, array $extra = []): void {
    echo json_encode(array_merge(['success' => $ok, 'message' => $msg], $extra));
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') json_out(false, 'Method not allowed.');
if (empty($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token']))
    json_out(false, 'Security token expired.');

$name = trim($_POST['name'] ?? '');
if (!$name) json_out(false, 'Name is required.');
if (strlen($name) > 100) json_out(false, 'Name too long (max 100 characters).');

$slug = trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($name)), '-');

try {
    $check = $pdo->prepare("SELECT id FROM cuisines WHERE name = ? OR slug = ?");
    $check->execute([$name, $slug]);
    if ($existing = $check->fetch()) {
        json_out(false, 'That cuisine already exists.', ['id' => $existing['id'], 'name' => $name]);
    }

    $pdo->prepare("INSERT INTO cuisines (name, slug) VALUES (?, ?)")->execute([$name, $slug]);
    $new_id = (int)$pdo->lastInsertId();
    json_out(true, 'Cuisine added.', ['id' => $new_id, 'name' => $name]);

} catch (Throwable $e) {
    error_log('[ERP] add_cuisine failed: ' . $e->getMessage());
    json_out(false, 'Database error.');
}
