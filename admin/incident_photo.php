<?php
// admin/incident_photo.php — show one incident photo (Farm OS Phase 5 slice 8, Nov 12, 2026; includes/incidents.php).
//   GET incident_photo.php?id=N[&thumb=1]
// Photos are stored outside the website folder; this page sends one only to Owners and Managers (view 'incidents').
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'incidents']]);
require_once __DIR__ . '/../includes/incidents.php';

$fail = function (int $code) { http_response_code($code); header('Content-Type: text/plain'); exit($code === 404 ? 'Not found' : 'Not allowed'); };
if (!bcrf_incidents_ready($pdo)) $fail(404);
try { $p = bcrf_incident_photo_get($pdo, (int)($_GET['id'] ?? 0)); } catch (BcrfIncidentError $e) { $fail(404); }
$path = bcrf_incident_photo_path($p);
if ($path === '' || !is_file($path)) $fail(404);

$etag = '"ip' . (int)$p['id'] . '-' . (int)$p['bytes'] . '"';
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

if (!empty($_GET['thumb']) && function_exists('imagecreatefromwebp')) {
    $im = @imagecreatefromwebp($path);
    if ($im) {
        $w = imagesx($im); $h = imagesy($im); $s = min(1, 320 / max($w, $h));
        $c = imagecreatetruecolor(max(1, (int)round($w * $s)), max(1, (int)round($h * $s)));
        imagecopyresampled($c, $im, 0, 0, 0, 0, imagesx($c), imagesy($c), $w, $h);
        header('Content-Type: image/webp');
        imagewebp($c, null, 70);
        exit;
    }
}
header('Content-Type: image/webp');
header('Content-Length: ' . filesize($path));
header('Content-Disposition: inline; filename="incident-' . (int)$p['incident_id'] . '-photo-' . (int)$p['id'] . '.webp"');
readfile($path);
