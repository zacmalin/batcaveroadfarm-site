<?php
// speculation-rules.php — browser hint: start fetching the next public page when a visitor
// hovers (desktop) or starts tapping (phone) a link, so it opens almost instantly.
// Sent to every public page by includes/head.php as a "Speculation-Rules" header (Nov 15, 2026).
// Prefetch only (no prerender), so no page scripts or analytics run early.
// Chrome/Edge use it; other browsers ignore it. Skips admin, accounts, forms that change things,
// any link with a ?query, new-tab links, downloads and anything marked class="no-prefetch".
header('Content-Type: application/speculationrules+json');
header('Cache-Control: public, max-age=86400');
header('X-Content-Type-Options: nosniff');
echo json_encode([
    'tag'      => 'bcrf-prefetch',
    'prefetch' => [[
        'where' => ['and' => [
            ['href_matches' => '/*'],
            ['not' => ['href_matches' => [
                '/admin/*', '/account/*', '/api/*', '/booking/*', '/cancel-booking*',
                '/*logout*', '/*login*', '/subscribe*', '/unsubscribe*', '/preferences*',
                '/send-message*', '/waiver*', '/review/*', '/*.pdf', '/*\\?(.+)',
            ]]],
            ['not' => ['selector_matches' => '[rel~=nofollow], [target=_blank], [download], .no-prefetch']],
        ]],
        'eagerness' => 'moderate',
    ]],
], JSON_UNESCAPED_SLASHES | JSON_PRETTY_PRINT);
