<?php // admin/pinterest_image.php
// Shows (or with ?dl=1 downloads) the generated image of one Pinterest draft (Nov 2026). Served through
// the admin so the preview works on the admin subdomain whatever the main site's hotlink settings are.
// Staff who can see the Social Queue or are administrators.
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['view', 'social']]);
require_once __DIR__ . '/../includes/pinterest.php';

$id = (int)($_GET['id'] ?? 0);
$file = null; $name = 'pin.jpg';
if ($id > 0 && bcrf_pinterest_ready($pdo)) {
    $st = $pdo->prepare("SELECT content_type, content_id, title, image_path FROM social_queue_targets WHERE id = ? AND platform = 'pinterest'");
    $st->execute([$id]);
    if ($r = $st->fetch(PDO::FETCH_ASSOC)) {
        $file = bcrf_pin_image_abs($r['image_path']);
        $slug = function_exists('createSlug') ? createSlug((string)$r['title']) : 'pin';
        $name = 'pinterest-' . ($slug !== '' ? strtolower($slug) : $r['content_type'] . '-' . $r['content_id']) . '.jpg';
    }
}
while (ob_get_level() > 0) ob_end_clean();
if (!$file || !is_file($file)) { http_response_code(404); header('Content-Type: text/plain'); exit('Image not found. Regenerate the draft.'); }
header('Content-Type: image/jpeg');
header('Content-Length: ' . filesize($file));
header('Cache-Control: private, max-age=300');
header('X-Content-Type-Options: nosniff');
if (!empty($_GET['dl'])) header('Content-Disposition: attachment; filename="' . preg_replace('/[^a-z0-9.-]/i', '-', $name) . '"');
readfile($file);
exit;
