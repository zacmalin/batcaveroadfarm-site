<?php
// includes/comments.php — published comments for a recipe, with the commenter's name + their rating.
// Comment text is stored raw and escaped on output. Names show as "First L." for privacy.

if (!function_exists('bcrf_comment_stars_html')) {

    function bcrf_comment_stars_html(int $val): string {
        if ($val < 1) return '';
        $pct = max(0, min(100, (int)round($val / 5 * 100)));
        return '<span class="rate rate--static comment-rate" title="' . $val . ' of 5 stars">'
             . '<span class="rate-stars">'
             . '<span class="rate-empty" aria-hidden="true">☆☆☆☆☆</span>'
             . '<span class="rate-fill" style="width:' . $pct . '%">★★★★★</span>'
             . '</span></span>';
    }

    function bcrf_render_comment(array $row): string {
        $first = trim((string)($row['first_name'] ?? ''));
        $last  = trim((string)($row['last_name'] ?? ''));
        $name  = $first !== '' ? $first : 'Member';
        if ($last !== '') $name .= ' ' . strtoupper(substr($last, 0, 1)) . '.';

        $when = '';
        if (!empty($row['created_at'])) { $t = strtotime((string)$row['created_at']); if ($t) $when = date('M j, Y', $t); }

        $rating = (int)($row['user_rating'] ?? 0);
        $text   = nl2br(htmlspecialchars((string)($row['comment_text'] ?? ''), ENT_QUOTES));

        $h  = '<li class="comment">';
        $h .= '<div class="comment-head">';
        $h .= '<span class="comment-author">' . htmlspecialchars($name, ENT_QUOTES) . '</span>';
        if ($rating > 0) $h .= bcrf_comment_stars_html($rating);
        if ($when !== '') $h .= '<span class="comment-date">' . htmlspecialchars($when) . '</span>';
        $h .= '</div>';
        $h .= '<div class="comment-body">' . $text . '</div>';
        $h .= '</li>';
        return $h;
    }

    function bcrf_fetch_comments(PDO $pdo, int $recipeId): array {
        try {
            $sql = "SELECT c.id, c.user_id, c.comment_text, c.created_at, u.first_name, u.last_name,
                           (SELECT r.rating FROM recipe_ratings r
                             WHERE r.recipe_id = c.recipe_id AND r.user_id = c.user_id
                             ORDER BY r.id DESC LIMIT 1) AS user_rating
                    FROM recipe_comments c
                    JOIN users u ON u.id = c.user_id
                    WHERE c.recipe_id = ? AND c.status = 'published'
                    ORDER BY c.created_at DESC, c.id DESC";
            $st = $pdo->prepare($sql);
            $st->execute([$recipeId]);
            return $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[bcrf_fetch_comments] ' . $e->getMessage());
            return [];
        }
    }

    function bcrf_fetch_comment(PDO $pdo, int $commentId): ?array {
        try {
            $sql = "SELECT c.id, c.user_id, c.comment_text, c.created_at, u.first_name, u.last_name,
                           (SELECT r.rating FROM recipe_ratings r
                             WHERE r.recipe_id = c.recipe_id AND r.user_id = c.user_id
                             ORDER BY r.id DESC LIMIT 1) AS user_rating
                    FROM recipe_comments c
                    JOIN users u ON u.id = c.user_id
                    WHERE c.id = ? LIMIT 1";
            $st = $pdo->prepare($sql);
            $st->execute([$commentId]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[bcrf_fetch_comment] ' . $e->getMessage());
            return null;
        }
    }

    function bcrf_comment_count(PDO $pdo, int $recipeId): int {
        try {
            $st = $pdo->prepare("SELECT COUNT(*) FROM recipe_comments WHERE recipe_id = ? AND status = 'published'");
            $st->execute([$recipeId]);
            return (int)$st->fetchColumn();
        } catch (Throwable $e) {
            return 0;
        }
    }
}
