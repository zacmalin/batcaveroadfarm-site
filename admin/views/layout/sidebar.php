<?php
// admin/views/layout/sidebar.php
// Farm OS navigation — 02 Navigation & UX (V4 decisions, Nov 5 2026).
// Desktop ≥1200px: sectioned, permission-gated sidebar. Tablet 768–1199px: same sidebar, defaults to icon rail.
// Phone <768px: sidebar hidden; bottom bar (4 shortcuts + More) and a More sheet with every section.
// Items with several tools get page tabs at the top of <main>. Behaviour: /admin/assets/admin-nav.js.
// A hidden item is not an authorization boundary — every view still checks access itself.

// ── SECTION MAP ───────────────────────────────────────────────────────────────────────────
// The map lives in includes/admin_nav.php (shared with global search, Nov 14, 2026).
require_once dirname(__DIR__, 3) . '/includes/admin_nav.php';
$_sections = bcrf_admin_nav_sections();

// Phone bottom-bar defaults by role (02, Nov 5 2026). Owner set becomes Dashboard, POS, Gate, Tasks once those exist.
$_defaults_by_role = [
    'administrator' => ['dashboard', 'storefront.pos', 'visitors.gate-check-in', 'team.tasks'],   // Tasks replaced Inventory (Phase 3, Nov 11)
    'moderator'     => ['dashboard', 'storefront.pos', 'visitors.gate-check-in', 'team.tasks'],
    'Shopkeep'      => ['storefront.pos', 'storefront.products-inventory', 'team.tasks', 'team.time-clock'],
    'Media'         => ['content.blog', 'content.social-queue', 'content.comments', 'content.media-library'],
    'Staff'         => ['team.tasks', 'team.time-clock'],
];

// ── HELPERS ───────────────────────────────────────────────────────────────────────────────
$_role = $current_role ?? 'user';
require_once dirname(__DIR__, 3) . '/includes/roles.php';   // role display names (Nov 13, 2026)
$_tv   = $target_view  ?? '';
$_tt   = $target_table ?? '';
$_h    = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_slug = fn(string $s): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');

$_can = function(string $type, string $val) use ($_role): bool {
    if ($type === 'always') return true;
    if ($type === 'pinrole') return in_array($_role, ['administrator', 'moderator', 'Shopkeep'], true);   // includes/pin_auth.php bcrf_pin_roles()
    if ($_role === 'administrator') return true;
    if ($_role === 'Staff') return $type === 'view' && ($val === 'timecard' || ($val === 'tasks' && function_exists('hasViewAccess') && hasViewAccess('Staff', 'tasks')));   // Jobs pack P1: Time Clock; Phase 3: Tasks
    if ($type === 'view')  return function_exists('hasViewAccess')  ? hasViewAccess($_role, $val)  : true;
    if ($type === 'table') return function_exists('hasTableAccess') ? hasTableAccess($_role, $val) : true;
    return false;
};

$_active = function(string $href) use ($_tv, $_tt): bool {
    if (str_starts_with($href, '/')) {
        // Standalone page (Nov 11, 2026: includes/admin_shell.php): active when it is the file being shown and
        // every query value in the tab's link (e.g. ?tab=tax) matches the request.
        $file = basename((string)parse_url($href, PHP_URL_PATH));
        if ($file === '' || $file === 'admin.php' || $file !== basename((string)($_SERVER['SCRIPT_NAME'] ?? ''))) return false;
        parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $p);
        foreach ($p as $k => $v) if ((string)($_GET[$k] ?? '') !== (string)$v) return false;
        // A base-page tab is not active while a named sibling tab is selected.
        if (!array_key_exists('tab', $p) && (string)($_GET['tab'] ?? '') !== '') return false;
        return true;
    }
    parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $p);
    $v = $p['view'] ?? ''; $t = $p['table'] ?? '';
    // Social Queue has a Pinterest tab on the same view (?view=social&tab=pinterest, Nov 2026)
    if ($v === 'social' || $v === 'permissions') return $_tv === $v && (string)($p['tab'] ?? '') === (string)($_GET['tab'] ?? '');   // + Permissions › By person (Nov 14)
    return $v === 'schema' ? ($_tv === 'schema' && $_tt === $t) : ($_tv === $v && $v !== '');
};

$_full_href = fn(string $h): string => (str_starts_with($h, '/') ? $h : '/admin/admin.php' . $h);

// ── BADGE COUNTS ──────────────────────────────────────────────────────────────────────────
$_pcc = (int)($pending_comments_count ?? 0);
$_jnc = 0; $_jqc = 0; $_jyc = 0;
if ($_role === 'administrator' && isset($pdo)) {
    try {
        $_jnc = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new' AND is_spam = 0 AND archived_at IS NULL")->fetchColumn();   // Jobs pack P4
        $_jqc = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('pending','failed')")->fetchColumn();                           // Jobs pack P5
        $_jyc = (int)$pdo->query("SELECT COUNT(*) FROM user_employment WHERE end_date IS NULL AND is_minor = 1 AND youth_cert_received_at IS NULL")->fetchColumn();   // Jobs pack P6
    }
    catch (Throwable $e) { $_jnc = 0; $_jqc = 0; $_jyc = 0; /* Jobs tables not installed */ }
}
$_badges = ['comment_moderation' => $_pcc, 'jobs' => $_jnc, 'jobs_emails' => $_jqc, 'jobs_staff' => $_jyc];
$_badge_titles = ['jobs' => 'New applicants', 'jobs_emails' => 'Emails waiting for approval', 'jobs_staff' => 'Youth certificates still needed', 'comment_moderation' => 'Comments awaiting moderation'];
// "Needs attention" counts on single tabs, by their link (Nov 13, 2026; includes/nav_badges.php). Each is counted only for
// people who can act on it, and adds up the chain to its item and section like the counts above.
$_href_badges = [];
if ($_role !== 'Staff' && $_role !== 'user' && isset($pdo)) {
    try {
        require_once dirname(__DIR__, 3) . '/includes/nav_badges.php';
        $_href_badges = bcrf_nav_badges($pdo, (string)$_role, $_can,
            fn(string $t): bool => $_role === 'administrator' || (function_exists('hasTableAccess') && hasTableAccess($_role, $t, 'edit')));
    } catch (Throwable $e) { error_log('[sidebar] badges: ' . $e->getMessage()); $_href_badges = []; }
}

// ── BUILD VISIBLE NAV ─────────────────────────────────────────────────────────────────────
$_nav = []; $_catalog = []; $_cur_section = null; $_cur_item = null;
if ($_role !== 'Staff') {
    $_catalog['dashboard'] = ['label'=>'Dashboard','short'=>'Home','icon'=>'🏠','href'=>'/admin/admin.php?view=dashboard','active'=>$_tv === 'dashboard','badge'=>0];
    // Global search (02 › Search, Nov 13, 2026): can be pinned to the phone bottom bar like any other item.
    $_catalog['search'] = ['label'=>'Search','short'=>'Search','icon'=>'🔍','href'=>'/admin/search.php',
                           'active'=>basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'search.php','badge'=>0];
}
// Notification bell (Nov 13, 2026; includes/notifications.php, migration 0063). Not for the Staff role: config.php only lets
// Staff open the time clock and tasks.php, and they get late-task emails already.
$_bell = 0;
if ($_role !== 'Staff') {
try {
    if (!function_exists('bcrf_notifications_unread')) require_once dirname(__DIR__, 3) . '/includes/notifications.php';
    $_bell = bcrf_notifications_unread($pdo, (int)($current_user_id ?? 0), (string)$_role);
} catch (Throwable $e) { $_bell = 0; }
$_catalog['notifications'] = ['label'=>'Notifications','short'=>'Alerts','icon'=>'🔔','href'=>'/admin/notifications.php',
                              'active'=>basename((string)($_SERVER['SCRIPT_NAME'] ?? '')) === 'notifications.php','badge'=>$_bell];
}
foreach ($_sections as $_sk => $_sec) {
    $_items = [];
    foreach ($_sec['items'] as $_it) {
        $_tabs = [];
        foreach ($_it['tabs'] as [$_tl, $_th, $_tct, $_tcv]) {
            if (!$_can($_tct, $_tcv)) continue;
            // Different permissions can open the same page; show only one tab.
            if (in_array($_full_href($_th), array_column($_tabs, 'href'), true)) continue;
            $_tabs[] = ['label'=>$_tl, 'href'=>$_full_href($_th), 'active'=>$_active($_th),
                        'badge'=>(int)($_href_badges[$_th]['n'] ?? $_badges[$_tcv] ?? 0), 'badge_title'=>$_href_badges[$_th]['title'] ?? $_badge_titles[$_tcv] ?? ''];
        }
        if (!$_tabs) continue;
        $_is_act = in_array(true, array_column($_tabs, 'active'), true) || in_array($_tv, $_it['match'] ?? [], true);
        $_row = [
            'id'     => $_sk . '.' . $_slug($_it['label']),
            'label'  => $_it['label'],
            'short'  => $_it['short'] ?? $_it['label'],
            'icon'   => $_it['icon'],
            'href'   => $_tabs[0]['href'],
            'active' => $_is_act,
            'badge'  => array_sum(array_column($_tabs, 'badge')),
            'tabs'   => $_tabs,
        ];
        $_items[] = $_row;
        $_catalog[$_row['id']] = ['label'=>$_row['label'], 'short'=>$_row['short'], 'icon'=>$_row['icon'],
                                  'href'=>$_row['href'], 'active'=>$_is_act, 'badge'=>$_row['badge']];
        if ($_is_act && $_cur_section === null) { $_cur_section = $_sk; $_cur_item = $_row; }
    }
    if ($_items) {
        $_nav[$_sk] = ['label'=>$_sec['label'], 'icon'=>$_sec['icon'], 'items'=>$_items,
                       'badge'=>array_sum(array_column($_items, 'badge'))];
    }
}

// Role defaults, topped up from the catalog when a default isn't available to this user.
$_defaults = array_values(array_filter($_defaults_by_role[$_role] ?? ['dashboard'], fn($id) => isset($_catalog[$id])));
foreach (array_keys($_catalog) as $_cid) {
    if (count($_defaults) >= 4) break;
    if (!in_array($_cid, $_defaults, true)) $_defaults[] = $_cid;
}

// Your own shortcuts, saved on your account (Nov 15, 2026; includes/staff_prefs.php, migration 0068, saved by admin/nav_prefs.php).
$_prefs_ready = false; $_saved_pins = null;
try {
    require_once dirname(__DIR__, 3) . '/includes/staff_prefs.php';
    if (isset($pdo) && $pdo instanceof PDO && bcrf_staff_prefs_ready($pdo)) {
        $_prefs_ready = true;
        $_sv = bcrf_staff_pref_get($pdo, (int)($current_user_id ?? 0), 'nav_shortcuts');
        if (is_array($_sv)) $_saved_pins = array_values(array_filter(bcrf_nav_shortcuts_clean($_sv), fn($id) => isset($_catalog[$id])));
    }
} catch (Throwable $e) { error_log('[sidebar] shortcuts: ' . $e->getMessage()); }
$_bar = $_saved_pins ?: $_defaults;
if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));

$_nav_data = [
    'sections'       => array_keys($_nav),
    'currentSection' => $_cur_section,
    'catalog'        => $_catalog,
    'defaults'       => $_defaults,
    'userKey'        => (string)($current_user_id ?? ''),
    'prefsReady'     => $_prefs_ready,
    'saved'          => $_saved_pins,
    'prefsUrl'       => '/admin/nav_prefs.php',
    'csrf'           => (string)($_SESSION['csrf_token'] ?? ''),
];
// Badge markup shared by the bottom bar and the More sheet: big, ringed, top-right corner (Nov 15, 2026).
$_badge = fn(int $n): string => $n > 0
    ? '<span class="nav-badge" aria-label="' . $n . ' need attention">' . ($n > 99 ? '99+' : $n) . '</span>' : '';
?>

<!-- Shell styles: /admin/assets/admin-shell.css (moved out of this file Nov 11, 2026, so strict-policy pages can use it) -->

<!-- ═══ Sidebar: tablet + desktop ═══ -->
<aside id="adminSidebar" class="hidden md:flex w-56 shrink-0 bg-stone-900 text-stone-200 flex-col min-h-screen overflow-y-auto print:hidden">

  <a href="/admin/admin.php?view=dashboard"
     class="sb-brand block px-5 py-4 border-b border-stone-700 hover:bg-stone-800 transition shrink-0">
    <div class="sb-brand-full">
      <div class="font-serif font-black text-sm text-white leading-snug">Bat Cave Road Farm</div>
      <div class="text-[9px] font-mono text-stone-500 uppercase tracking-widest mt-0.5">Farm OS</div>
    </div>
    <div class="sb-brand-mark text-lg">🐐</div>
  </a>

  <button type="button" id="sbCollapseBtn"
    class="sb-row flex items-center justify-end gap-2 px-3 py-1.5 shrink-0 border-b border-stone-800 text-stone-500 hover:text-white hover:bg-stone-800 transition">
    <span class="sb-text text-[9px] font-mono uppercase tracking-widest">Collapse</span>
    <span id="sbCollapseIcon" class="text-sm leading-none">«</span>
  </button>

  <?php if (isset($_catalog['search'])): ?>
  <a href="/admin/search.php" title="Search (press /)" role="search"
     class="sb-row sb-search flex items-center gap-2 px-3 py-2 rounded border border-stone-700 text-stone-400 hover:text-white hover:bg-stone-800 transition shrink-0<?= $_catalog['search']['active'] ? ' bg-stone-700 text-white' : '' ?>">
    <span class="shrink-0">🔍</span> <span class="sb-text">Search</span> <span class="sb-text ml-auto text-[9px] font-mono text-stone-500">/</span>
  </a>
  <?php endif; ?>
  <?php if (isset($_catalog['notifications'])): ?>
  <a href="/admin/notifications.php" title="Notifications<?= $_bell ? ' (' . (int)$_bell . ' new)' : '' ?>"
     class="sb-row sb-search flex items-center gap-2 px-3 py-2 rounded text-stone-400 hover:text-white hover:bg-stone-800 transition shrink-0<?= $_catalog['notifications']['active'] ? ' bg-stone-700 text-white' : '' ?>">
    <span class="shrink-0">🔔</span> <span class="sb-text">Notifications</span>
    <?php if ($_bell > 0): ?><span class="sb-badge ml-auto shrink-0 bg-amber-500 text-white text-[8px] font-black px-1 py-0.5 rounded"><?= (int)$_bell ?></span><?php endif; ?>
  </a>
  <?php endif; ?>

  <nav class="flex-1 px-2 py-3 text-xs" aria-label="Main">

    <?php if (isset($_catalog['dashboard'])): ?>
    <a href="/admin/admin.php?view=dashboard" title="Dashboard"
       class="sb-row flex items-center gap-2 px-3 py-2 rounded mb-1 font-bold transition
              <?= $_tv === 'dashboard' ? 'bg-stone-700 text-white' : 'text-stone-300 hover:bg-stone-800' ?>">
      <span class="shrink-0">🏠</span> <span class="sb-text">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php foreach ($_nav as $_sk => $_sec): ?>
    <?php if ($_sk === 'customers' && count($_sec['items']) === 1): $_direct = $_sec['items'][0]; ?>
    <a href="<?= $_h($_direct['href']) ?>" title="Customers"<?= $_direct['active'] ? ' aria-current="page"' : '' ?>
       class="sb-row flex items-center gap-2 px-3 py-2 rounded mb-1 transition <?= $_direct['active'] ? 'bg-stone-700 text-white font-bold' : 'text-stone-300 hover:bg-stone-800' ?>">
      <span class="shrink-0"><?= $_sec['icon'] ?></span><span class="sb-text">Customers</span>
      <?php if ($_direct['badge'] > 0): ?><span class="sb-badge ml-auto shrink-0 bg-amber-500 text-white text-[8px] font-black px-1 py-0.5 rounded"><?= (int)$_direct['badge'] ?></span><?php endif; ?>
    </a>
    <?php else: ?>
    <div class="mb-1" data-sec="<?= $_h($_sk) ?>">
      <button type="button" data-sb-toggle="<?= $_h($_sk) ?>" title="<?= $_h($_sec['label']) ?>"
        class="sb-row w-full flex items-center justify-between px-3 py-1.5 rounded text-[10px] font-black uppercase tracking-widest
               text-stone-400 hover:text-white hover:bg-stone-800 transition mt-1">
        <span class="flex items-center gap-1.5">
          <span class="shrink-0 relative"><?= $_sec['icon'] ?><?php if ($_sec['badge'] > 0): ?><span class="absolute -top-1.5 -right-2 bg-amber-500 text-white text-[8px] font-black leading-none px-1 py-0.5 rounded-full"><?= (int)$_sec['badge'] ?></span><?php endif; ?></span>
          <span class="sb-text"><?= $_h($_sec['label']) ?></span>
        </span>
        <span id="arr-<?= $_h($_sk) ?>" class="sb-arrow text-stone-500">▾</span>
      </button>
      <div id="sec-<?= $_h($_sk) ?>" class="pl-1 mt-0.5 space-y-px">
        <?php foreach ($_sec['items'] as $_item): ?>
        <a href="<?= $_h($_item['href']) ?>" title="<?= $_h($_item['label']) ?>"<?= $_item['active'] ? ' aria-current="page"' : '' ?>
           class="sb-row flex items-center gap-2 px-3 py-1.5 rounded transition
                  <?= $_item['active'] ? 'bg-stone-700 text-white font-bold' : 'text-stone-400 hover:bg-stone-800 hover:text-stone-200' ?>">
          <span class="shrink-0"><?= $_item['icon'] ?></span>
          <span class="sb-text truncate"><?= $_h($_item['label']) ?></span>
          <?php if ($_item['badge'] > 0): ?>
            <span class="sb-badge ml-auto shrink-0 bg-amber-500 text-white text-[8px] font-black px-1 py-0.5 rounded"><?= (int)$_item['badge'] ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endif; ?>
    <?php endforeach; ?>

  </nav>

  <div class="px-4 py-3 border-t border-stone-700 shrink-0 space-y-2">
    <div class="sb-text text-[9px] font-mono text-stone-500 uppercase tracking-wider"><?= $_h(bcrf_role_label($_role)) ?></div>
    <a href="/admin/logout.php" title="Sign Out"
       class="sb-row flex items-center gap-2 px-2 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider font-mono text-stone-400 hover:bg-red-900 hover:text-red-200 transition">
      <span class="shrink-0">🚪</span> <span class="sb-text">Sign Out</span>
    </a>
  </div>
</aside>

<!-- ═══ Phone: bottom bar ═══ -->
<nav id="navBottomBar" aria-label="Shortcuts"
     class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-stone-900 border-t border-stone-700 print:hidden nav-safe-bottom">
  <div id="navBarSlots" class="nav-bar">
    <?php foreach ($_bar as $_cid): $_c = $_catalog[$_cid]; ?>
    <a href="<?= $_h($_c['href']) ?>" data-sc-slot="<?= $_h($_cid) ?>" class="nav-slot<?= $_c['active'] ? ' is-active' : '' ?><?= $_c['badge'] > 0 ? ' has-badge' : '' ?>">
      <span class="nav-slot-icon"><?= $_c['icon'] ?><?= $_badge((int)$_c['badge']) ?></span>
      <span class="nav-slot-label"><?= $_h($_c['short']) ?></span>
    </a>
    <?php endforeach; ?>
    <button type="button" id="navMoreBtn" class="nav-slot" aria-haspopup="dialog" aria-expanded="false" aria-controls="navMoreSheet">
      <span class="nav-slot-icon">☰</span>
      <span class="nav-slot-label">More</span>
    </button>
  </div>
</nav>

<!-- ═══ Phone: More sheet ═══ -->
<div id="navMoreSheet" class="md:hidden fixed inset-0 z-50 hidden print:hidden" role="dialog" aria-modal="true" aria-label="All sections">
  <div class="absolute inset-0 bg-black/60" data-nav-close></div>
  <div class="absolute bottom-0 inset-x-0 max-h-[88vh] overflow-y-auto bg-stone-900 text-stone-200 rounded-t-2xl px-4 pt-3 nav-sheet-safe">
    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-stone-600"></div>
    <div class="flex items-center justify-between gap-2 mb-2">
      <div>
        <div class="font-serif font-black text-sm text-white">Bat Cave Road Farm</div>
        <div class="text-[9px] font-mono text-stone-500 uppercase tracking-widest"><?= $_h(bcrf_role_label($_role)) ?></div>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" id="navResetBtn" class="hidden px-3 py-2 rounded-lg text-[11px] font-bold text-stone-300 bg-stone-800">Reset</button>
        <button type="button" id="navEditBtn" class="px-3 py-2 rounded-lg text-[11px] font-bold text-stone-900 bg-amber-400">Edit shortcuts</button>
        <button type="button" data-nav-close class="w-9 h-9 rounded-full bg-stone-800 text-stone-300 text-lg leading-none" aria-label="Close">&times;</button>
      </div>
    </div>
    <p id="navEditHint" class="hidden mb-3 text-[11px] text-amber-300"></p>

    <?php if (isset($_catalog['search'])): ?>
    <form method="get" action="/admin/search.php" role="search" class="nav-search mb-4">
      <input type="search" name="q" placeholder="🔍 Search everything" aria-label="Search everything" enterkeyhint="search" autocomplete="off" class="nav-search-box">
      <input type="hidden" name="go" value="1">
    </form>
    <?php endif; ?>

    <?php if (isset($_catalog['dashboard'])): $_c = $_catalog['dashboard']; ?>
    <div class="grid grid-cols-3 gap-2 mb-4">
      <a href="<?= $_h($_c['href']) ?>" data-sc-id="dashboard" class="nav-tile<?= $_c['active'] ? ' is-current' : '' ?>">
        <span class="nav-tile-icon">🏠</span><span>Dashboard</span>
      </a>
      <?php if (isset($_catalog['search'])): $_c = $_catalog['search']; ?>
      <a href="<?= $_h($_c['href']) ?>" data-sc-id="search" class="nav-tile<?= $_c['active'] ? ' is-current' : '' ?>">
        <span class="nav-tile-icon">🔍</span><span>Search</span>
      </a>
      <?php endif; ?>
      <?php if (isset($_catalog['notifications'])): ?>
      <a href="/admin/notifications.php" data-sc-id="notifications" class="nav-tile<?= $_catalog['notifications']['active'] ? ' is-current' : '' ?><?= $_bell > 0 ? ' has-badge' : '' ?>">
        <span class="nav-tile-icon">🔔</span><span>Notifications</span><?= $_badge((int)$_bell) ?>
      </a>
      <?php endif; ?>
    </div>
    <?php endif; ?>

    <?php foreach ($_nav as $_sk => $_sec): ?>
    <div class="mb-4">
      <div class="text-[10px] font-black uppercase tracking-widest text-stone-500 mb-2"><?= $_sec['icon'] ?> <?= $_h($_sec['label']) ?></div>
      <div class="grid grid-cols-3 gap-2">
        <?php foreach ($_sec['items'] as $_item): ?>
        <a href="<?= $_h($_item['href']) ?>" data-sc-id="<?= $_h($_item['id']) ?>" class="nav-tile<?= $_item['active'] ? ' is-current' : '' ?><?= $_item['badge'] > 0 ? ' has-badge' : '' ?>">
          <span class="nav-tile-icon"><?= $_item['icon'] ?></span>
          <span><?= $_h($_item['label']) ?></span><?= $_badge((int)$_item['badge']) ?>
        </a>
        <?php endforeach; ?>
      </div>
    </div>
    <?php endforeach; ?>

    <a href="/admin/logout.php" class="flex items-center justify-center gap-2 w-full py-3 rounded-xl bg-stone-800 text-red-300 font-bold text-xs">🚪 Sign Out</a>
  </div>
</div>

<?php if ($_cur_item && count($_cur_item['tabs']) > 1): ?>
<!-- ═══ Page tabs (moved to the top of <main> by admin-nav.js) ═══ -->
<div id="navTabs" class="nav-tabs-wrap print:hidden" hidden>
  <nav class="nav-tabs" aria-label="<?= $_h($_cur_item['label']) ?>">
    <?php foreach ($_cur_item['tabs'] as $_tab): ?>
    <a href="<?= $_h($_tab['href']) ?>" class="nav-tab<?= $_tab['active'] ? ' is-active' : '' ?>"<?= $_tab['active'] ? ' aria-current="page"' : '' ?>>
      <?= $_h($_tab['label']) ?><?php if ($_tab['badge'] > 0): ?><span class="nav-tab-badge" title="<?= $_h($_tab['badge_title']) ?>"><?= (int)$_tab['badge'] ?></span><?php endif; ?>
    </a>
    <?php endforeach; ?>
  </nav>
</div>
<?php endif; ?>

<script type="application/json" id="navData"><?= json_encode($_nav_data, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_UNICODE) ?></script>
<script src="/admin/assets/admin-nav.js?v=20261115a"></script>
