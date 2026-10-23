<?php
// Determine the current top-level section from the request path so we can
// mark the active nav item server-side (adds .active + aria-current="page").
$navPath = parse_url($_SERVER['REQUEST_URI'] ?? '/', PHP_URL_PATH) ?: '/';
$navPath = '/' . trim($navPath, '/');
if ($navPath === '/index.php') { $navPath = '/'; }
$navIsActive = function (string $seg) use ($navPath): bool {
    $seg = '/' . trim($seg, '/');
    if ($seg === '/') { return $navPath === '/'; }
    return $navPath === $seg || str_starts_with($navPath, $seg . '/');
};

// Show Events only when at least one published event exists.
$navShowEvents = false;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $navShowEvents = (bool) $pdo->query(
            "SELECT 1 FROM events WHERE status = 'published' "
          . "AND (recurrence <> 'none' OR end_datetime >= NOW() OR start_datetime >= NOW()) "
          . "LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) { $navShowEvents = false; }
}

// Show Shop only when merchandise is in stock.
$navShowShop = false;
if (isset($pdo) && $pdo instanceof PDO) {
    try {
        $navShowShop = (bool) $pdo->query(
            "SELECT 1 FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id "
          . "WHERE i.is_active = 1 AND i.stock_quantity > 0 "
          . "AND (LOWER(ic.slug) IN ('merchandise','merch') OR LOWER(ic.name) IN ('merchandise','merch')) "
          . "LIMIT 1"
        )->fetchColumn();
    } catch (Throwable $e) { $navShowShop = false; }
}

// ── Dropdown items ────────────────────────────────────────────────────────────
$visitDropdown = [
    ['/plan-your-visit/', '📍', 'Plan Your Visit'],
    ['/directions/',      '🗺️', 'Map & Directions'],
    ['/tickets/',         '🎫', 'Day Tickets'],
    ['/passes/',          '🪪', 'Annual Passes'],
    ['/booking/',         '📋', 'Book a Group'],
    ['/private-events/',  '🎈', 'Private Events'],
];
$aboutDropdown = [
    ['/about/',      '🏡', 'About Us'],
    ['/faq/',        '❓', 'FAQ'],
    ['/contact-us/', '✉️', 'Contact'],
];

// ── Flat nav items (Contact moves to About dropdown) ─────────────────────────
$navItems = [
    ['/',             '🏡',  'Home',        'home'],
    ['/petting-zoo/', '🐐',  'Petting Zoo', 'petting-zoo'],
    ['/farm-stand/',  '🥕',  'Farm Stand',  'farmstand'],
    ['/thrift-shop/', '🛋️', 'Thrift Shop', 'thrift-shop'],
];
if ($navShowShop)   { $navItems[] = ['/shop/',   '🧢', 'Shop',   'shop']; }
if ($navShowEvents) { $navItems[] = ['/events/', '📅', 'Events', 'events']; }
$navItems = array_merge($navItems, [
    ['/blog/',    '📖', 'Blog',    'blog'],
    ['/recipes/', '🍳', 'Recipes', 'recipes'],
]);

// ── Render helpers ────────────────────────────────────────────────────────────
if (!function_exists('renderNavBtn')) {
function renderNavBtn(array $item, callable $navIsActive, string $suffix = '', bool $withClose = false): void {
    [$href, $emoji, $label, $id] = $item;
    $active = $navIsActive($href);
    $cls    = 'nav-btn' . ($active ? ' active' : '');
    $aria   = $active ? ' aria-current="page"' : '';
    $full   = 'https://batcaveroadfarm.com' . ($href === '/' ? '' : $href);
    echo '<a href="' . $full . '" class="' . $cls . '" id="nav-' . $id . $suffix . '"' . $aria . '>'
       . '<span class="nav-emoji" aria-hidden="true">' . $emoji . '</span>'
       . '<span class="nav-label">' . $label . '</span></a>';
}
}

// Generic dropdown — used for both Visit ▾ and About ▾
if (!function_exists('renderNavDropdown')) {
function renderNavDropdown(string $dropId, string $emoji, string $label, array $items, callable $navIsActive, string $suffix = ''): void {
    $active = false;
    foreach ($items as [$href]) { if ($navIsActive($href)) { $active = true; break; } }
    $id   = 'nav-' . $dropId . $suffix;
    $cls  = 'nav-btn nav-dropdown-toggle' . ($active ? ' active' : '');
    $aria = $active ? ' aria-current="true"' : '';
    $base = 'https://batcaveroadfarm.com';
    echo '<div class="nav-dropdown">';
    echo '<button type="button" class="' . $cls . '" id="' . $id . '" aria-expanded="false" aria-haspopup="true"' . $aria . '>'
       . '<span class="nav-emoji" aria-hidden="true">' . $emoji . '</span>'
       . '<span class="nav-label">' . htmlspecialchars($label) . '</span>'
       . '</button>';
    echo '<div class="nav-dropdown-menu" role="menu" aria-labelledby="' . $id . '">';
    foreach ($items as [$href, $iEmoji, $iLabel]) {
        $ia = $navIsActive($href);
        echo '<a href="' . $base . $href . '" class="nav-dropdown-item' . ($ia ? ' active' : '') . '" role="menuitem">'
           . '<span class="nav-dd-emoji">' . $iEmoji . '</span>' . htmlspecialchars($iLabel)
           . '</a>';
    }
    echo '</div></div>';
}
}
?>

<!-- ══════════════ NAV ══════════════ -->
<nav id="main-nav" aria-label="Primary">

  <!-- Hamburger toggle (mobile only) -->
  <button class="hamburger" id="hamburger-btn" type="button"
          aria-label="Toggle navigation menu" aria-expanded="false" aria-controls="nav-drawer">
    <span class="hamburger-label">☰ &nbsp;Menu</span>
    <span class="hamburger-icon" aria-hidden="true">
      <span></span><span></span><span></span>
    </span>
  </button>

  <!-- Desktop: horizontal bar -->
  <div class="nav-inner" id="nav-desktop">
    <?php renderNavBtn($navItems[0], $navIsActive); // Home ?>
    <?php renderNavDropdown('visit', '📍', 'Visit ▾', $visitDropdown, $navIsActive); ?>
    <?php foreach (array_slice($navItems, 1) as $item) { renderNavBtn($item, $navIsActive); } ?>
    <?php renderNavDropdown('about', 'ℹ️', 'About ▾', $aboutDropdown, $navIsActive); ?>
  </div>

  <!-- Mobile: dropdown drawer -->
  <div class="nav-drawer" id="nav-drawer">
    <?php renderNavBtn($navItems[0], $navIsActive, '-m', true); // Home ?>
    <?php renderNavDropdown('visit', '📍', 'Visit ▾', $visitDropdown, $navIsActive, '-m'); ?>
    <?php foreach (array_slice($navItems, 1) as $item) { renderNavBtn($item, $navIsActive, '-m', true); } ?>
    <?php renderNavDropdown('about', 'ℹ️', 'About ▾', $aboutDropdown, $navIsActive, '-m'); ?>
  </div>

  <!-- Mobile: dim backdrop behind the open drawer (tap to close) -->
  <div class="nav-backdrop" id="nav-backdrop" aria-hidden="true"></div>

</nav>
