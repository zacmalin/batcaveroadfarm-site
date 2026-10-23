<?php
/**
 * Site-wide breadcrumbs — ARIA compliant (<nav aria-label="Breadcrumb"> + <ol>,
 * current item marked aria-current="page", separators are CSS-only/decorative) and
 * emits BreadcrumbList JSON-LD for SEO.
 *
 * A page may set $breadcrumbs BEFORE including header.php for exact labels:
 *   $breadcrumbs = [
 *     ['label' => 'Petting Zoo', 'url' => '/petting-zoo/'],
 *     ['label' => 'Nigerian Dwarf'],   // last item = current page, omit 'url'
 *   ];
 * Otherwise the trail is auto-derived from the request path. "Home" is always added.
 */
(function () {
    global $hideBreadcrumbs;
    if (!empty($hideBreadcrumbs)) return;
    $base = 'https://batcaveroadfarm.com';
    $labelMap = [
        'farm-stand' => 'Farm Stand', 'petting-zoo' => 'Petting Zoo', 'thrift-shop' => 'Thrift Shop',
        'about' => 'About', 'contact-us' => 'Contact', 'faq' => 'FAQ', 'gallery' => 'Gallery',
        'blog' => 'The Journal', 'recipes' => 'Recipes', 'items' => 'Items',
        'inventory' => 'Inventory', 'breeds' => 'Breeds', 'animals' => 'Animals', 'catalog' => 'Catalog',
    ];

    global $breadcrumbs, $canonicalUrl;
    $crumbs = [];

    if (isset($breadcrumbs) && is_array($breadcrumbs) && $breadcrumbs) {
        $crumbs = $breadcrumbs;
    } else {
        $path = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH);
        $path = trim((string)$path, '/');
        if ($path === '' || strtolower($path) === 'index.php') return; // homepage: no breadcrumbs
        $acc = '';
        foreach (explode('/', $path) as $seg) {
            if ($seg === '') continue;
            $clean = preg_replace('/\.php$/i', '', $seg);
            if (ctype_digit($clean)) continue; // skip numeric id segments
            $acc .= '/' . $seg;
            $label = $labelMap[strtolower($clean)] ?? ucwords(str_replace(['-', '_'], ' ', $clean));
            $crumbs[] = ['label' => $label, 'url' => $acc . '/'];
        }
        if ($crumbs) unset($crumbs[count($crumbs) - 1]['url']); // current page: no link
    }

    array_unshift($crumbs, ['label' => 'Home', 'url' => '/']);
    if (count($crumbs) < 2) return; // nothing meaningful beyond Home

    // --- visible nav ---
    echo '<nav class="breadcrumbs" aria-label="Breadcrumb"><ol>';
    $n = count($crumbs);
    foreach ($crumbs as $i => $c) {
        $label = htmlspecialchars((string)($c['label'] ?? ''), ENT_QUOTES);
        $url   = $c['url'] ?? null;
        $isLast = ($i === $n - 1);
        echo '<li>';
        if (!$isLast && $url) {
            echo '<a href="' . htmlspecialchars($url, ENT_QUOTES) . '">' . $label . '</a>';
        } else {
            echo '<span aria-current="page">' . $label . '</span>';
        }
        echo '</li>';
    }
    echo '</ol></nav>';

    // --- BreadcrumbList JSON-LD ---
    $items = [];
    foreach ($crumbs as $i => $c) {
        $url = $c['url'] ?? null;
        if ($url) {
            $abs = preg_match('#^https?://#i', $url) ? $url : $base . $url;
        } else {
            $abs = (isset($canonicalUrl) && $canonicalUrl)
                 ? $canonicalUrl
                 : $base . ($_SERVER['REQUEST_URI'] ?? '/');
        }
        $items[] = [
            '@type' => 'ListItem',
            'position' => $i + 1,
            'name' => (string)($c['label'] ?? ''),
            'item' => $abs,
        ];
    }
    echo '<script type="application/ld+json">'
        . json_encode(
            ['@context' => 'https://schema.org', '@type' => 'BreadcrumbList', 'itemListElement' => $items],
            JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE
          )
        . '</script>';
})();
