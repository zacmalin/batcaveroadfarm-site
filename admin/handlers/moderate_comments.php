<?php
// admin/handlers/moderate_comments.php
require_once __DIR__ . '/../includes/audit.php';   // fix pack O: audit log

try {
    if (!isset($_POST['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $_POST['csrf_token'])) {
        $_SESSION['toast_msg']  = 'Security token expired.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=comment_moderation'); exit;
    }

    $action      = $_POST['mod_action'] ?? '';
    $comment_ids = array_filter(array_map('intval', $_POST['comment_ids'] ?? []));

    if (empty($comment_ids) || !in_array($action, ['approve', 'reject'])) {
        $_SESSION['toast_msg']  = 'Nothing to process.';
        $_SESSION['toast_type'] = 'error';
        header('Location: /admin/admin.php?view=comment_moderation'); exit;
    }

    $placeholders = implode(',', array_fill(0, count($comment_ids), '?'));

    $pdo->beginTransaction();

    if ($action === 'approve') {
        // Mark as published + manually approved so lazy expiry won't touch them
        $stmt = $pdo->prepare("
            UPDATE recipe_comments
            SET    status = 'published', manually_approved = 1
            WHERE  id IN ({$placeholders})
        ");
        $stmt->execute($comment_ids);
        $done = $stmt->rowCount();

        $pdo->commit();
        erp_audit($pdo, 'update', 'recipe_comments', count($comment_ids) === 1 ? $comment_ids[0] : null,
            "Approved {$done} comment(s): #" . implode(', #', array_slice($comment_ids, 0, 20)));
        $_SESSION['toast_msg']  = "{$done} comment" . ($done !== 1 ? 's' : '') . " approved and published.";
        $_SESSION['toast_type'] = 'success';

    } else {
        // Reject — get user IDs before updating so we can check ban threshold
        $sel = $pdo->prepare("SELECT DISTINCT user_id FROM recipe_comments WHERE id IN ({$placeholders})");
        $sel->execute($comment_ids);
        $affected_users = $sel->fetchAll(PDO::FETCH_COLUMN);

        $stmt = $pdo->prepare("
            UPDATE recipe_comments
            SET    status = 'rejected'
            WHERE  id IN ({$placeholders})
        ");
        $stmt->execute($comment_ids);
        $done = $stmt->rowCount();

        // ── BAN CHECK ─────────────────────────────────────────────────────────
        // If a user has 5+ rejections in the last 30 days, restrict for 60 days
        $banned_count = 0;
        $restricted   = [];
        foreach ($affected_users as $uid) {
            $count_stmt = $pdo->prepare("
                SELECT COUNT(*) FROM recipe_comments
                WHERE  user_id    = ?
                  AND  status     = 'rejected'
                  AND  updated_at >= DATE_SUB(NOW(), INTERVAL 30 DAY)
            ");
            $count_stmt->execute([$uid]);
            $rejection_count = (int)$count_stmt->fetchColumn();

            if ($rejection_count >= 5) {
                $pdo->prepare("
                    UPDATE users
                    SET    comment_restricted_until = DATE_ADD(CURDATE(), INTERVAL 60 DAY)
                    WHERE  id = ?
                      AND (comment_restricted_until IS NULL OR comment_restricted_until < CURDATE())
                ")->execute([$uid]);
                $banned_count++;
                $restricted[] = (int)$uid;
            }
        }

        $pdo->commit();
        erp_audit($pdo, 'update', 'recipe_comments', count($comment_ids) === 1 ? $comment_ids[0] : null,
            "Rejected {$done} comment(s): #" . implode(', #', array_slice($comment_ids, 0, 20)));
        foreach ($restricted as $ruid) {
            erp_audit($pdo, 'update', 'users', $ruid, 'Comments restricted 60 days (5+ rejections in 30 days)');
        }

        $msg = "{$done} comment" . ($done !== 1 ? 's' : '') . " rejected.";
        if ($banned_count > 0) {
            $msg .= " {$banned_count} user" . ($banned_count !== 1 ? 's' : '') . " restricted for 60 days (5+ rejections this month).";
        }
        $_SESSION['toast_msg']  = $msg;
        $_SESSION['toast_type'] = 'success';
    }

} catch (Throwable $e) {
    if ($pdo->inTransaction()) $pdo->rollBack();
    error_log('[ERP] moderate_comments failed: ' . $e->getMessage());
    $_SESSION['toast_msg']  = 'Moderation error: ' . $e->getMessage();
    $_SESSION['toast_type'] = 'error';
}

header('Location: /admin/admin.php?view=comment_moderation');
exit;
