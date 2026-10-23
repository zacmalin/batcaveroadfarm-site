<?php
// includes/subscribe_confirm.php — double opt-in for the mailing list (fix pack K).
//
// Signing up no longer adds the address right away. subscribe.php emails a
// signed confirmation link; nothing is written to mailing_list until the owner
// of the inbox taps it (subscribe-confirm.php). This stops people (or bots)
// signing up addresses that aren't theirs, which protects email deliverability.
//
// Links are signed with BCRF_LIST_SECRET (same secret as unsubscribe links)
// and expire after 7 days. No database table or column changes needed.

if (!function_exists('bcrf_subconf_token')) {

    if (!defined('BCRF_SUBCONF_TTL')) define('BCRF_SUBCONF_TTL', 604800); // 7 days

    /** 32-char signature over email + chosen lists + timestamp ('' if no secret). */
    function bcrf_subconf_token(string $email, int $nursery, int $inventory, int $ts): string
    {
        if (!defined('BCRF_LIST_SECRET') || BCRF_LIST_SECRET === '') return '';
        $msg = 'subscribe|' . strtolower(trim($email)) . '|' . ($nursery ? 1 : 0) . '|' . ($inventory ? 1 : 0) . '|' . $ts;
        return substr(hash_hmac('sha256', $msg, BCRF_LIST_SECRET), 0, 32);
    }

    /** Confirmation URL for one signup ('' if no secret is configured). */
    function bcrf_subconf_url(string $email, int $nursery, int $inventory, ?int $ts = null): string
    {
        $ts = $ts ?? time();
        $t  = bcrf_subconf_token($email, $nursery, $inventory, $ts);
        if ($t === '') return '';
        return 'https://batcaveroadfarm.com/subscribe-confirm.php?' . http_build_query([
            'e' => strtolower(trim($email)), 'n' => $nursery ? 1 : 0, 'i' => $inventory ? 1 : 0, 'ts' => $ts, 't' => $t,
        ]);
    }

    /** 'ok', 'expired' or 'invalid'. */
    function bcrf_subconf_check(string $email, int $nursery, int $inventory, int $ts, string $token): string
    {
        $expected = bcrf_subconf_token($email, $nursery, $inventory, $ts);
        if ($expected === '' || strlen($token) !== 32 || !hash_equals($expected, $token)) return 'invalid';
        if ($ts > time() + 300 || time() - $ts > BCRF_SUBCONF_TTL) return 'expired';
        return 'ok';
    }

    /**
     * Add or re-subscribe an address with the chosen lists. Returns true on success.
     * Portable SQL (select, then update or insert) so it does not depend on a
     * UNIQUE index on email.
     */
    function bcrf_subconf_apply(PDO $pdo, string $email, int $nursery, int $inventory): bool
    {
        $email = strtolower(trim($email));
        try {
            $st = $pdo->prepare("SELECT id FROM mailing_list WHERE LOWER(email) = ? LIMIT 1");
            $st->execute([$email]);
            $id = $st->fetchColumn();
            $existed = (bool)$id;
            if ($existed) {
                $pdo->prepare("UPDATE mailing_list SET is_subscribed = 1, track_nursery_updates = ?, track_inventory_alerts = ? WHERE id = ?")
                    ->execute([$nursery ? 1 : 0, $inventory ? 1 : 0, (int)$id]);
            } else {
                $pdo->prepare("INSERT INTO mailing_list (email, is_subscribed, track_nursery_updates, track_inventory_alerts) VALUES (?, 1, ?, ?)")
                    ->execute([$email, $nursery ? 1 : 0, $inventory ? 1 : 0]);
                $id = (int)$pdo->lastInsertId();
            }
            if (function_exists('erp_audit')) {
                erp_audit($pdo, $existed ? 'update' : 'create', 'mailing_list', $id ? (int)$id : null, $email . ' - subscribed (confirmed by email)', 'Subscriber (self)');
            }
            return true;
        } catch (Throwable $e) {
            error_log('[subscribe-confirm] ' . $e->getMessage());
            return false;
        }
    }

    /** Send the "please confirm" email. Returns true if the mailer accepted it. */
    function bcrf_subconf_send(string $email, int $nursery, int $inventory): bool
    {
        $url = bcrf_subconf_url($email, $nursery, $inventory);
        if ($url === '' || !function_exists('bcf_send_mail')) {
            error_log('[subscribe] cannot send confirmation (missing LIST_SECRET or email template)');
            return false;
        }
        $lists = [];
        if ($nursery)   $lists[] = 'Nursery &amp; animal updates';
        if ($inventory) $lists[] = 'Fresh harvest alerts';
        $listHtml = $lists ? '<p style="margin:0 0 16px;">You chose: ' . implode(' and ', $lists) . '.</p>' : '';

        $inner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">One more step</h1>'
            . '<p style="margin:0 0 16px;">Tap the button below to confirm you want Bat Cave Road Farm updates at this address.</p>'
            . $listHtml
            . '<p style="margin:0 0 24px;"><a href="' . htmlspecialchars($url, ENT_QUOTES) . '" style="display:inline-block;background:#c9923a;color:#3b2010;font-family:Lora,Georgia,serif;font-weight:bold;text-decoration:none;padding:13px 28px;border-radius:8px;">Yes, sign me up</a></p>'
            . '<p style="margin:0;font-size:14px;color:rgba(245,234,214,0.8);">This link works for 7 days. If you didn\'t sign up, ignore this email and you won\'t hear from us.</p>';

        return bcf_send_mail($email, 'Please confirm your Bat Cave Road Farm signup', $inner, [
            'subtitle'   => 'Confirm Signup',
            'from_email' => 'support@batcaveroadfarm.com',
            'text'       => "Confirm your Bat Cave Road Farm signup (link works for 7 days):\r\n{$url}\r\n\r\nIf you didn't sign up, ignore this email.",
        ]);
    }
}
