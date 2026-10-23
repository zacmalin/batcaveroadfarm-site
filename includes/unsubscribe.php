<?php
// includes/unsubscribe.php — signed, per-recipient unsubscribe links.
// One place builds and checks them, so every newsletter sender stays in sync.
// Needs BCRF_LIST_SECRET (set 'LIST_SECRET' in bcrf-secrets.php, loaded by config.php).

if (!function_exists('bcrf_unsub_token')) {
    /** 24-char signature for an email address ('' if no secret is configured). */
    function bcrf_unsub_token(string $email): string
    {
        if (!defined('BCRF_LIST_SECRET') || BCRF_LIST_SECRET === '') return '';
        return substr(hash_hmac('sha256', strtolower(trim($email)), BCRF_LIST_SECRET), 0, 24);
    }

    /** Full unsubscribe URL for one recipient (falls back to the plain form). */
    function bcrf_unsub_url(string $email): string
    {
        $base = 'https://batcaveroadfarm.com/unsubscribe.php';
        $t = bcrf_unsub_token($email);
        return $t === '' ? $base : $base . '?e=' . rawurlencode(trim($email)) . '&t=' . $t;
    }

    /** True if $token is the correct signature for $email. */
    function bcrf_unsub_verify(string $email, string $token): bool
    {
        $expected = bcrf_unsub_token($email);
        return $expected !== '' && strlen($token) === 24 && hash_equals($expected, $token);
    }
}
