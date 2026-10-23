<?php
// /recipes/comment.php — post a comment (login-gated, auto-published, admin-moderatable).
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/ratings.php';
require_once '../includes/comments.php';
header('Content-Type: application/json');
header('Cache-Control: no-store, private');

if ($_SERVER['REQUEST_METHOD'] !== 'POST') { http_response_code(405); echo json_encode(['ok' => false, 'error' => 'method']); exit; }

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

if (!csrf_verify(is_string($in['csrf'] ?? null) ? $in['csrf'] : null)) {
    http_response_code(403); echo json_encode(['ok' => false, 'error' => 'Your session expired — please refresh and try again.']); exit;
}
if (!is_logged_in()) { echo json_encode(['ok' => false, 'needLogin' => true]); exit; }

$uid = current_user_id();

if (comment_blocked_until($pdo, $uid) !== null) {
    echo json_encode(['ok' => false, 'error' => 'Commenting is temporarily unavailable on your account.']); exit;
}
if (throttle_hit('comment', 6, 600)) {
    echo json_encode(['ok' => false, 'error' => "You're posting quickly — please wait a moment and try again."]); exit;
}

$recipeId = (int)($in['recipe_id'] ?? 0);
$text = trim((string)($in['comment_text'] ?? ''));

if ($recipeId < 1)            { echo json_encode(['ok' => false, 'error' => 'Missing recipe.']); exit; }
if ($text === '')            { echo json_encode(['ok' => false, 'error' => 'Please write a comment first.']); exit; }
if (mb_strlen($text) > 2000) { echo json_encode(['ok' => false, 'error' => 'Comments are limited to 2000 characters.']); exit; }

try {
    $chk = $pdo->prepare("SELECT id FROM recipes WHERE id = ? LIMIT 1");
    $chk->execute([$recipeId]);
    if (!$chk->fetch()) { echo json_encode(['ok' => false, 'error' => 'Recipe not found.']); exit; }

    // Insert as 'pending' so it enters the admin moderation queue (Media -> Comment Moderation).
    // It appears on the site only after an admin approves it (status -> 'published').
    $ins = $pdo->prepare("INSERT INTO recipe_comments (recipe_id, user_id, comment_text, status) VALUES (?, ?, ?, 'pending')");
    $ins->execute([$recipeId, $uid, $text]);

    echo json_encode(['ok' => true, 'pending' => true, 'message' => 'Thanks! Your comment is awaiting moderation and will appear once approved.']);
} catch (Throwable $e) {
    error_log('[comment] ' . $e->getMessage());
    echo json_encode(['ok' => false, 'error' => 'A server error occurred. Please try again.']);
}
