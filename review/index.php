<?php
// review/index.php — short, memorable redirect to our Google review link.
// Every surface (emails, receipts, footer) points here, so the actual Google
// URL only ever has to be set in ONE place: the 'review_url' site setting.
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/site_info.php';

$dest = function_exists('site_setting') ? trim(site_setting('review_url')) : '';

// Until the real review URL is set (e.g. pre-verification), send people to a
// Google Maps search for the business so the link still does something useful.
if ($dest === '') {
    $q = rawurlencode('Bat Cave Road Farm, 4132 Bat Cave Road, Old Fort, NC 28762');
    $dest = 'https://www.google.com/maps/search/?api=1&query=' . $q;
}

// 302 (not 301): the destination can change, so don't let it get cached forever.
header('Cache-Control: no-store, private');
header('Location: ' . $dest, true, 302);
echo 'Redirecting to leave a review… <a href="' . htmlspecialchars($dest, ENT_QUOTES) . '">continue</a>.';
