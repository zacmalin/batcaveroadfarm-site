<?php
// includes/email_verify.php
// Email verification for public accounts. Mirrors the password_resets pattern:
// a random token whose SHA-256 hash is stored, single-use, time-limited.
// Soft gate: accounts work unverified; gate sensitive actions (checkout/tickets)
// later with evf_is_verified(). Requires config.php loaded first ($pdo available).

if (is_file(__DIR__ . '/../admin/includes/email_template.php')) require_once __DIR__ . '/../admin/includes/email_template.php';

if (!function_exists('evf_issue')) {

    /** Lifetime of a verification link, in hours. */
    if (!defined('EVF_TTL_HOURS')) define('EVF_TTL_HOURS', 24);

    /**
     * Create a fresh verification token for a user and email them the link.
     * Invalidates any earlier unused tokens first. Returns true if mail() accepted it.
     * Never throws — verification is best-effort and must not break registration,
     * and it degrades gracefully if the migration hasn't been run yet.
     */
    function evf_issue(PDO $pdo, int $userId, string $email, string $firstName = ''): bool {
        try {
            $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$userId]);

            $token = bin2hex(random_bytes(32));
            $pdo->prepare(
                "INSERT INTO email_verifications (user_id, token_hash, expires_at)
                 VALUES (?, ?, DATE_ADD(NOW(), INTERVAL " . (int)EVF_TTL_HOURS . " HOUR))"
            )->execute([$userId, hash('sha256', $token)]);

            $link = 'https://batcaveroadfarm.com/account/verify.php?token=' . $token;
            $name = trim($firstName) !== '' ? trim($firstName) : 'there';
            $hrs  = (int)EVF_TTL_HOURS;
            $subject = 'Confirm your email — Bat Cave Road Farm';
            $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Confirm your email</h1>'
                . '<p style="margin:0 0 16px;">Hi ' . htmlspecialchars($name) . ', thanks for creating a Bat Cave Road Farm account. Please confirm your email to finish setting things up.</p>'
                . '<p style="margin:0 0 24px;"><a href="' . htmlspecialchars($link, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">Confirm my email</a></p>'
                . '<p style="margin:0;font-size:14px;color:rgba(245,234,214,0.8);">This link expires in ' . $hrs . ' hours. If you didn\'t create an account, you can safely ignore this email.</p>';
            if (function_exists('bcf_send_mail')) {
                return bcf_send_mail($email, $subject, $inner, ['subtitle' => 'Confirm Your Email']);
            }
            $body = "Hi {$name},\r\n\r\nConfirm your email (expires in {$hrs} hours):\r\n{$link}\r\n\r\nIf you didn't create an account, you can safely ignore this email.\r\n— Bat Cave Road Farm\r\n";
            $headers = "From: Bat Cave Road Farm <no-reply@batcaveroadfarm.com>\r\nReply-To: no-reply@batcaveroadfarm.com\r\nContent-Type: text/plain; charset=UTF-8\r\n";
            return @mail($email, $subject, $body, $headers, '-fno-reply@batcaveroadfarm.com');
        } catch (Throwable $e) {
            error_log('[evf_issue] ' . $e->getMessage());
            return false;
        }
    }

    /** Find a live (unused, unexpired) verification row for a raw token, or null. */
    function evf_find(PDO $pdo, string $rawToken): ?array {
        $rawToken = preg_replace('/[^a-f0-9]/', '', strtolower($rawToken)); // tokens are hex
        if ($rawToken === '') return null;
        try {
            $st = $pdo->prepare(
                "SELECT id, user_id FROM email_verifications
                 WHERE token_hash = ? AND used_at IS NULL AND expires_at > NOW() LIMIT 1"
            );
            $st->execute([hash('sha256', $rawToken)]);
            $row = $st->fetch(PDO::FETCH_ASSOC);
            return $row ?: null;
        } catch (Throwable $e) {
            error_log('[evf_find] ' . $e->getMessage());
            return null;
        }
    }

    /**
     * Consume a raw token: mark the user verified and burn outstanding tokens.
     * Returns the user_id on success, or null if the token was invalid/expired.
     */
    function evf_consume(PDO $pdo, string $rawToken): ?int {
        $row = evf_find($pdo, $rawToken);
        if (!$row) return null;
        $uid = (int)$row['user_id'];
        try {
            $pdo->beginTransaction();
            $pdo->prepare("UPDATE users SET email_verified_at = COALESCE(email_verified_at, NOW()) WHERE id = ?")
                ->execute([$uid]);
            $pdo->prepare("UPDATE email_verifications SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL")
                ->execute([$uid]);
            $pdo->commit();
            return $uid;
        } catch (Throwable $e) {
            if ($pdo->inTransaction()) $pdo->rollBack();
            error_log('[evf_consume] ' . $e->getMessage());
            return null;
        }
    }

    /** Authoritative check (reads the DB). Use to gate checkout/ticketing later. */
    function evf_is_verified(PDO $pdo, int $userId): bool {
        try {
            $st = $pdo->prepare("SELECT email_verified_at FROM users WHERE id = ? LIMIT 1");
            $st->execute([$userId]);
            return !empty($st->fetchColumn());
        } catch (Throwable $e) {
            return false;
        }
    }
}
