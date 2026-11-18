<?php
// manifest.php — the web app manifest (Nov 18, 2026). includes/head.php links here instead of site.webmanifest.
// Everything comes from site.webmanifest except the name (Site Info business name) and the icons, which follow
// Site Info → Logos & Icon (192 and 512 px PNGs made from the uploaded icon; the original icon until then).
define('BCRF_NO_SESSION', true);
require __DIR__ . '/config.php';
require_once __DIR__ . '/includes/farm.php';
require_once __DIR__ . '/includes/brand.php';

$m = json_decode((string)@file_get_contents(__DIR__ . '/site.webmanifest'), true);
if (!is_array($m)) $m = ['start_url' => '/', 'scope' => '/', 'display' => 'standalone'];
$m['name'] = bcrf_farm_name();
if (mb_strlen((string)($m['short_name'] ?? '')) === 0) $m['short_name'] = mb_substr(bcrf_farm_name(), 0, 12);
$m['icons'] = bcrf_brand_manifest_icons();

header('Content-Type: application/manifest+json; charset=utf-8');
header('Cache-Control: public, max-age=3600');
header('X-Robots-Tag: noindex');
echo json_encode($m, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
