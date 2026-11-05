<?php // api/gbp-callback.php
/*
 * Google Business Profile sign-in relay (Nov 2026).
 * Google's OAuth reply carries URLs in the query string (iss=https://..., scope=https://...),
 * which Bluehost's ModSecurity blocks on admin.batcaveroadfarm.com ("Not Acceptable").
 * ModSecurity is off for api.batcaveroadfarm.com, so Google returns here and this page
 * forwards only the code and state to the admin page, which checks state against the
 * admin's session and finishes the sign-in. Nothing is stored here.
 * Google Cloud redirect URI: https://api.batcaveroadfarm.com/gbp-callback.php
 */
$keep = static fn(string $k): string =>
    preg_match('#^[A-Za-z0-9._~/\-]{1,512}$#', (string)($_GET[$k] ?? '')) ? (string)$_GET[$k] : '';

$q = ['state' => $keep('state')];
if (isset($_GET['error'])) $q['error'] = $keep('error') ?: 'access_denied';
else                       $q['code']  = $keep('code');

header('Cache-Control: no-store');
header('Location: https://admin.batcaveroadfarm.com/gbp_connect.php?' . http_build_query($q), true, 302);
exit;
