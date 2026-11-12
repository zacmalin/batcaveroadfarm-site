<?php
// admin/task_photo.php — show one task photo (Farm OS Phase 3 slice 2, Nov 11, 2026; includes/tasks.php).
//   GET task_photo.php?id=N[&thumb=1]
// Photos are stored outside the website folder; this page sends one only to staff who can see its task.
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'tasks']]);
require_once __DIR__ . '/../includes/tasks.php';

$fail = function (int $code) { http_response_code($code); header('Content-Type: text/plain'); exit($code === 404 ? 'Not found' : 'Not allowed'); };
if (!bcrf_tasks_ready($pdo) || !bcrf_tasks_v2($pdo)) $fail(404);
try {
    $p = bcrf_task_photo_get($pdo, (int)($_GET['id'] ?? 0));
    $t = bcrf_task_get($pdo, (int)$p['task_id']);
} catch (BcrfTaskError $e) { $fail(404); }
if (!bcrf_tasks_can_see($t)) $fail(403);
$path = bcrf_task_photo_path($p);
if ($path === '' || !is_file($path)) $fail(404);

$etag = '"tp' . (int)$p['id'] . '-' . (int)$p['bytes'] . '"';
header('Cache-Control: private, max-age=86400');
header('ETag: ' . $etag);
header('X-Content-Type-Options: nosniff');
if (trim((string)($_SERVER['HTTP_IF_NONE_MATCH'] ?? '')) === $etag) { http_response_code(304); exit; }

if (!empty($_GET['thumb']) && function_exists('imagecreatefromwebp')) {
    // Small copy for the task page, made on the fly (the originals are at most 1600px, so this is quick).
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
header('Content-Disposition: inline; filename="task-' . (int)$t['id'] . '-photo-' . (int)$p['id'] . '.webp"');
readfile($path);
