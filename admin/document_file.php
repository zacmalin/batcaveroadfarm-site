<?php
// admin/document_file.php — send one invoice / receipt page (Nov 14, 2026; includes/documents.php).
//   GET document_file.php?id=FILE_ID[&thumb=1][&dl=1]
// Files are stored outside the website folder; this sends one only to staff who can see its document.
// thumb=1: a small JPEG for lists. dl=1: download with the original file name.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit'], ['view', 'animal_expenses']]);
require_once __DIR__ . '/../includes/documents.php';

$fail = function (int $code) { http_response_code($code); header('Content-Type: text/plain'); exit($code === 404 ? 'Not found' : 'Not allowed'); };
if (!bcrf_docs_ready($pdo)) $fail(404);
try {
    $f = bcrf_doc_file_get($pdo, (int)($_GET['id'] ?? 0));
    $d = bcrf_doc_get($pdo, (int)$f['doc_id']);
} catch (BcrfDocError $e) { $fail(404); }
if (!bcrf_doc_can_see($d)) $fail(403);
if ($f['deleted_at'] && !bcrf_doc_is_owner()) $fail(404);
$path = bcrf_doc_file_path($f);
if ($path === '' || !is_file($path)) $fail(404);

$thumb = !empty($_GET['thumb']) && $f['mime'] === 'image/jpeg';
$etag = '"df' . (int)$f['id'] . '-' . (int)$f['bytes'] . ($thumb ? 't' : '') . '"';
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

if ($thumb) {
    $im = @imagecreatefromjpeg($path);
    if ($im) {
        $w = imagesx($im); $h = imagesy($im); $s = min(1, 240 / max($w, $h));
        $c = imagecreatetruecolor(max(1, (int)round($w * $s)), max(1, (int)round($h * $s)));
        imagecopyresampled($c, $im, 0, 0, 0, 0, imagesx($c), imagesy($c), $w, $h);
        header('Content-Type: image/jpeg');
        imagejpeg($c, null, 70);
        exit;
    }
}

$ext = ['image/jpeg' => 'jpg', 'application/pdf' => 'pdf', 'image/heic' => 'heic'][$f['mime']] ?? 'bin';
$name = preg_replace('/\.[A-Za-z0-9]{1,5}$/', '', (string)($f['original_name'] ?: 'document')) . '.' . $ext;
$ascii = preg_replace('/[^A-Za-z0-9 ._()-]/', '_', $name);
$inline = empty($_GET['dl']) && $f['mime'] !== 'image/heic';
header('Content-Type: ' . $f['mime']);
header('Content-Length: ' . (int)filesize($path));
header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $ascii . '"; filename*=UTF-8\'\'' . rawurlencode($name));
readfile($path);
