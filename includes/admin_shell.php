<?php
// includes/admin_shell.php
// Farm OS admin shell for standalone admin pages (Nov 11, 2026 — "Bring every admin page into the shared shell").
// Gives a page in admin/ the same sidebar, phone bottom bar, More sheet and section tabs as the console
// (admin.php), and the same Tailwind look as the Hiring screens — while keeping the strict policy those pages
// use (script-src/style-src 'self'): Tailwind comes from the compiled admin/assets/admin.css, not the CDN.
//
//   require_once __DIR__ . '/../includes/admin_shell.php';
//   bcrf_shell_open('Vendors');                 // after any redirects/POST handling
//   ... page content (Tailwind classes, like admin/views/jobs/*) ...
//   bcrf_shell_close();
//
// The page's section tabs come from views/layout/sidebar.php: a tab whose href is this file is marked active.
// Pages must have run check_admin() and loaded admin/includes/permission_checks.php first (tabs are permission-filtered).

if (!function_exists('bcrf_shell_open')) {

/** Cache-busting version for admin assets: the file's modified time. */
function bcrf_shell_asset(string $file): string {
    $path = dirname(__DIR__) . '/admin/assets/' . $file;
    return '/admin/assets/' . $file . '?v=' . (is_file($path) ? filemtime($path) : '1');
}

/**
 * Open the page: <head>, sidebar, phone navigation, and <main>.
 * $opt: 'view'  => console view key this page belongs to (optional; tabs are matched by file name anyway)
 *       'toast' => ['ok'|'err'|'warning', 'message'] shown as the console's toast
 *       'width' => Tailwind max-width class for the content column (default 'max-w-5xl')
 *       'legacy'=> true for pages built on the register.css class names (card, lrow, btn, stackf…): loads
 *                  reports.css (layout) + admin-farmos.css (Hiring look) and wraps the content in .fos.
 *                  Default width for these is 'max-w-3xl'.
 */
function bcrf_shell_open(string $title, array $opt = []): void {
    global $pdo;
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');

    // Variables views/layout/sidebar.php reads (same names as admin.php sets)
    $current_role    = $_SESSION['user_role'] ?? 'user';
    $current_user_id = (int)($_SESSION['user_id'] ?? 0);
    $target_view     = (string)($opt['view'] ?? '');
    $target_table    = '';

    $legacy = !empty($opt['legacy']);
    $GLOBALS['__bcrf_shell_width'] = (string)($opt['width'] ?? ($legacy ? 'max-w-3xl' : 'max-w-5xl')) . ($legacy ? ' fos' : '');

    echo '<!DOCTYPE html><html lang="en"><head><meta charset="utf-8">',
         '<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">',
         '<meta name="theme-color" content="#1c1917"><meta name="robots" content="noindex">',
         '<meta http-equiv="Content-Security-Policy" content="default-src \'self\'; script-src \'self\'; style-src \'self\'; img-src \'self\' data: https:; connect-src \'self\'; base-uri \'self\'; form-action \'self\'">',
         '<title>', $h($title), ' — Farm OS</title>',
         '<link rel="stylesheet" href="', $h(bcrf_shell_asset('admin.css')), '">',
         '<link rel="stylesheet" href="', $h(bcrf_shell_asset('admin-shell.css')), '">',
         '<link rel="stylesheet" href="', $h(bcrf_shell_asset('admin-ui.css')), '">',
         $legacy ? '<link rel="stylesheet" href="' . $h(bcrf_shell_asset('reports.css')) . '"><link rel="stylesheet" href="' . $h(bcrf_shell_asset('admin-farmos.css')) . '">' : '',
         '<script defer src="', $h(bcrf_shell_asset('admin-ui.js')), '"></script>',
         '</head>';
    echo '<body class="bg-stone-100 min-h-screen text-stone-900 flex text-xs select-none">';

    // Toast, same markup as the console's views/layout/toast.php
    if (!empty($opt['toast'][1])) {
        $toastType    = $opt['toast'][0] === 'err' ? 'error' : (string)$opt['toast'][0];
        $toastMessage = (string)$opt['toast'][1];
        require dirname(__DIR__) . '/admin/views/layout/toast.php';
    }

    try { require dirname(__DIR__) . '/admin/views/layout/sidebar.php'; }
    catch (Throwable $e) {
        error_log('[admin_shell] sidebar failed: ' . $e->getMessage());
        echo '<aside class="w-56 bg-stone-900 p-4 text-red-400 text-xs">Navigation unavailable</aside>';
    }

    echo '<main class="flex-1 p-6 overflow-y-auto max-h-screen"><div class="', $h($GLOBALS['__bcrf_shell_width']), '">';
}

/** Close the page opened by bcrf_shell_open(). */
function bcrf_shell_close(): void {
    echo '</div></main></body></html>';
}

/** A notice box in the console style. $type: ok | err | warning | info */
function bcrf_shell_notice(string $type, string $message): string {
    $cls = [
        'ok'      => 'bg-green-50 border-green-200 text-green-800',
        'err'     => 'bg-red-50 border-red-200 text-red-800',
        'warning' => 'bg-amber-50 border-amber-200 text-amber-800',
        'info'    => 'bg-stone-50 border-stone-200 text-stone-700',
    ][$type] ?? 'bg-stone-50 border-stone-200 text-stone-700';
    return '<div class="p-3 mb-4 border rounded text-sm ' . $cls . '" role="' . ($type === 'err' ? 'alert' : 'status') . '">'
         . htmlspecialchars($message, ENT_QUOTES, 'UTF-8') . '</div>';
}

}
