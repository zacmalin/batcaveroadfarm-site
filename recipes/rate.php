<?php
// /recipes/rate.php — submit a recipe rating (AJAX, JSON).
// Anyone may rate 3-5; a rating under 3 requires login. Logged-in: one rating/recipe (updatable).
// Anonymous: throttled to one rating/recipe per IP hash (updatable), plus a browser cookie.
require_once '../config.php';
require_once '../includes/auth.php';

header('Content-Type: application/json');
header('Cache-Control: no-store, private');

function rate_out(array $payload, int $code = 200) {
    http_response_code($code);
    echo json_encode($payload);
    exit;
}

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    rate_out(['ok' => false, 'error' => 'method'], 405);
}

$in = json_decode((string)file_get_contents('php://input'), true);
if (!is_array($in)) $in = $_POST;

$recipeId = (int)($in['recipe_id'] ?? 0);
$rating   = (int)($in['rating'] ?? 0);
$csrf     = $in['csrf'] ?? null;

if (!csrf_verify(is_string($csrf) ? $csrf : null)) rate_out(['ok' => false, 'error' => 'csrf'], 403);
if ($recipeId <= 0 || $rating < 1 || $rating > 5) rate_out(['ok' => false, 'error' => 'bad'], 400);

// A rating under 3 stars requires a logged-in account.
if ($rating < 3 && !is_logged_in()) rate_out(['ok' => false, 'needLogin' => true]);

try {
    $chk = $pdo->prepare("SELECT id FROM recipes WHERE id = ? LIMIT 1");
    $chk->execute([$recipeId]);
    if (!$chk->fetch()) rate_out(['ok' => false, 'error' => 'norecipe'], 404);

    if (is_logged_in()) {
        $uid = current_user_id();
        $ex = $pdo->prepare("SELECT id FROM recipe_ratings WHERE recipe_id = ? AND user_id = ? LIMIT 1");
        $ex->execute([$recipeId, $uid]);
        $row = $ex->fetch();
        if ($row) {
            $pdo->prepare("UPDATE recipe_ratings SET rating = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$rating, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO recipe_ratings (recipe_id, user_id, rating, ip_hash) VALUES (?, ?, ?, ?)")
                ->execute([$recipeId, $uid, $rating, client_ip_hash()]);
        }
    } else {
        // Anonymous (rating is >= 3 here). One per IP hash per recipe — updatable.
        $iph = client_ip_hash();
        $ex = $pdo->prepare("SELECT id FROM recipe_ratings WHERE recipe_id = ? AND user_id IS NULL AND ip_hash = ? LIMIT 1");
        $ex->execute([$recipeId, $iph]);
        $row = $ex->fetch();
        if ($row) {
            $pdo->prepare("UPDATE recipe_ratings SET rating = ?, created_at = CURRENT_TIMESTAMP WHERE id = ?")
                ->execute([$rating, $row['id']]);
        } else {
            $pdo->prepare("INSERT INTO recipe_ratings (recipe_id, user_id, rating, ip_hash) VALUES (?, NULL, ?, ?)")
                ->execute([$recipeId, $rating, $iph]);
        }
        $secure = isset($_SERVER['HTTPS']) && $_SERVER['HTTPS'] === 'on';
        setcookie('bcrf_rated_' . $recipeId, '1', time() + 31536000, '/', '', $secure, true);
    }

    $agg = $pdo->prepare("SELECT ROUND(AVG(rating),1) AS avg_rating, COUNT(*) AS cnt FROM recipe_ratings WHERE recipe_id = ?");
    $agg->execute([$recipeId]);
    $a = $agg->fetch();
    rate_out(['ok' => true, 'avg' => (float)($a['avg_rating'] ?? 0), 'count' => (int)($a['cnt'] ?? 0)]);

} catch (Throwable $e) {
    error_log('[rate] ' . $e->getMessage());
    rate_out(['ok' => false, 'error' => 'server'], 500);
}
