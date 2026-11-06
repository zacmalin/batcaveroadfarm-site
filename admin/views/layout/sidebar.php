<?php
// admin/views/layout/sidebar.php
// Farm OS navigation — 02 Navigation & UX (V4 decisions, Nov 5 2026).
// Desktop ≥1200px: sectioned, permission-gated sidebar. Tablet 768–1199px: same sidebar, defaults to icon rail.
// Phone <768px: sidebar hidden; bottom bar (4 shortcuts + More) and a More sheet with every section.
// Items with several tools get page tabs at the top of <main>. Behaviour: /admin/assets/admin-nav.js.
// A hidden item is not an authorization boundary — every view still checks access itself.

// ── SECTION MAP ───────────────────────────────────────────────────────────────
// Item: label, icon, short (bottom-bar label), tabs = [[tab label, href, check_type, check_value], ...],
//       match = extra views that belong to this item (highlight only).
// check_type: 'view' | 'table' | 'always'. The item links to its first tab the user can open.
$_sections = [
    'storefront' => ['label'=>'Storefront','icon'=>'🏪','items'=>[
        ['label'=>'POS','icon'=>'💳','tabs'=>[['POS','/admin/pos.php','view','pos']]],
        ['label'=>'Products & Inventory','short'=>'Inventory','icon'=>'📦','tabs'=>[
            ['Counts',     '?view=inventory_update',                  'view',  'inventory_update'],
            ['Catalog',    '?view=schema&table=inventory',            'table', 'inventory'],
            ['Categories', '?view=schema&table=inventory_categories', 'table', 'inventory_categories'],
        ]],
        ['label'=>'Sales & Drawers','short'=>'Sales','icon'=>'🧾','tabs'=>[['Receipts','?view=receipts','view','receipts']]],
    ]],
    'visitors' => ['label'=>'Visitors & Events','icon'=>'🎟','items'=>[
        ['label'=>'Bookings',       'icon'=>'📋','tabs'=>[['Bookings','?view=bookings','view','bookings']]],
        ['label'=>'Private Events', 'short'=>'Parties','icon'=>'🎉','tabs'=>[['Private Events','?view=event_inquiries','view','event_inquiries']]],
        ['label'=>'Annual Passes',  'short'=>'Passes','icon'=>'🎫','tabs'=>[['Annual Passes','?view=passes','view','passes']]],
    ]],
    'animals' => ['label'=>'Animals','icon'=>'🐾','items'=>[
        ['label'=>'Roster',    'icon'=>'🐐','tabs'=>[['Roster','?view=schema&table=animals','table','animals']]],
        ['label'=>'Reference', 'short'=>'Ref','icon'=>'📚','tabs'=>[
            ['Breeds',          '?view=schema&table=breeds',          'table', 'breeds'],
            ['Classifications', '?view=schema&table=classifications', 'table', 'classifications'],
        ]],
    ]],
    'kitchen' => ['label'=>'Kitchen','icon'=>'🍳','items'=>[
        ['label'=>'Recipes',     'icon'=>'📖','tabs'=>[['Recipes','?view=schema&table=recipes','table','recipes']]],
        ['label'=>'Ingredients', 'icon'=>'🌿','tabs'=>[['Ingredients','?view=schema&table=ingredients','table','ingredients']]],
        ['label'=>'Reference',   'short'=>'Ref','icon'=>'📚','tabs'=>[
            ['Cuisines',          '?view=schema&table=cuisines',          'table', 'cuisines'],
            ['Categories',        '?view=schema&table=categories',        'table', 'categories'],
            ['Measurement Units', '?view=schema&table=measurement_units', 'table', 'measurement_units'],
            ['Unit Conversions',  '?view=schema&table=unit_conversions',  'table', 'unit_conversions'],
        ]],
    ]],
    // Customers: no screens built yet — the section stays hidden until it has items.
    'customers' => ['label'=>'Customers','icon'=>'👥','items'=>[]],
    'content' => ['label'=>'Marketing & Content','icon'=>'📡','items'=>[
        ['label'=>'Blog',            'icon'=>'✍️','tabs'=>[['Blog','?view=schema&table=blog_posts','table','blog_posts']]],
        ['label'=>'Public Events',   'short'=>'Events','icon'=>'📅','tabs'=>[['Public Events','?view=events','view','events']]],
        ['label'=>'Newsletter',      'icon'=>'📧','tabs'=>[['Newsletter','?view=newsletter','view','newsletter']]],
        ['label'=>'Social Queue',    'short'=>'Social','icon'=>'📣','tabs'=>[['Social Queue','?view=social','view','social'], ['Pinterest','?view=social&tab=pinterest','view','social']]],
        ['label'=>'Comments',        'icon'=>'💬','tabs'=>[['Comments','?view=comment_moderation','view','comment_moderation']]],
        ['label'=>'Subscribers',     'short'=>'Subs','icon'=>'💌','tabs'=>[['Subscribers','?view=schema&table=mailing_list','table','mailing_list']]],
        ['label'=>'Media Library',   'short'=>'Media','icon'=>'🖼️','tabs'=>[['Media Library','?view=media','view','media']]],
        ['label'=>'Seasonal Themes', 'short'=>'Themes','icon'=>'🎨','tabs'=>[['Seasonal Themes','?view=theme_scheduler','view','theme_scheduler']]],
    ]],
    'team' => ['label'=>'Team','icon'=>'🧑‍🌾','items'=>[
        ['label'=>'Staff',      'icon'=>'🪪','tabs'=>[['Staff','?view=jobs_staff','view','jobs_staff']], 'match'=>['jobs_alerts','jobs_records']],
        ['label'=>'Time Clock', 'short'=>'Clock','icon'=>'⏰','tabs'=>[['Time Clock','?view=timecard','view','timecard']]],
        ['label'=>'Timesheets', 'icon'=>'🗓️','tabs'=>[['Timesheets','?view=timesheet','view','timesheet']]],
        ['label'=>'Hiring',     'icon'=>'📥','tabs'=>[
            ['Applicants',    '?view=jobs',            'view', 'jobs'],
            ['Emails',        '?view=jobs_emails',     'view', 'jobs_emails'],
            ['Jobs',          '?view=jobs_posts',      'view', 'jobs_posts'],
            ['Question Bank', '?view=jobs_questions',  'view', 'jobs_questions'],
            ['Categories',    '?view=jobs_categories', 'view', 'jobs_categories'],
        ], 'match'=>['jobs_hire']],
    ]],
    'reports' => ['label'=>'Reports','icon'=>'📈','items'=>[
        ['label'=>'Sales',   'icon'=>'💵','tabs'=>[['Sales','?view=sales_analytics','view','sales_analytics'],
                                                   ['Daily & tax','/admin/farm_reports.php','view','sales_analytics']]],   // Farm OS reports (Phase 1, slice 5)
        ['label'=>'Website', 'icon'=>'🔎','tabs'=>[['Website','?view=search_analytics','view','search_analytics']]],
    ]],
    'settings' => ['label'=>'Settings','icon'=>'⚙️','items'=>[
        ['label'=>'Hours & Contacts', 'short'=>'Hours','icon'=>'🏠','tabs'=>[['Hours & Contacts','?view=site_info','view','site_info']]],
        ['label'=>'Users & Roles',    'short'=>'Users','icon'=>'🔐','tabs'=>[
            ['Staff',       '?view=schema&table=users',     'table', 'users'],       // staff accounts (Farm OS Phase 0, C-02)
            ['Customers',   '?view=schema&table=customers', 'table', 'customers'],   // public site accounts
            ['Permissions', '?view=permissions',            'view',  'permissions'],
            ['Two-step',    '/admin/two_step.php',          'view',  'two_step'],
        ]],
        ['label'=>'Integrations',     'icon'=>'🤖','tabs'=>[['AI Connections','/admin/mcp_connections.php','view','mcp_connections']]],   // administrators only
        ['label'=>'Backups & Export', 'short'=>'Backups','icon'=>'💾','tabs'=>[['Backups','/admin/backups.php','view','backups']]],          // administrators only
        ['label'=>'System Health',    'short'=>'Health','icon'=>'🩺','tabs'=>[['System Health','/admin/system_health.php','view','system_health']]], // administrators only
        ['label'=>'Database',         'short'=>'DB','icon'=>'🗄️','tabs'=>[['Database','/admin/migrations.php','view','migrations']]],             // administrators only (Phase 0 migrations)
        ['label'=>'Audit Log',        'short'=>'Audit','icon'=>'📜','tabs'=>[['Audit Log','?view=audit_log','view','audit_log']]],
    ]],
];

// Phone bottom-bar defaults by role (02, Nov 5 2026). Owner set becomes Dashboard, POS, Gate, Tasks once those exist.
$_defaults_by_role = [
    'administrator' => ['dashboard', 'storefront.pos', 'visitors.bookings', 'storefront.products-inventory'],
    'moderator'     => ['dashboard', 'storefront.pos', 'visitors.bookings', 'storefront.products-inventory'],
    'Shopkeep'      => ['storefront.pos', 'storefront.products-inventory', 'storefront.sales-drawers', 'team.time-clock'],
    'Media'         => ['content.blog', 'content.social-queue', 'content.comments', 'content.media-library'],
    'Staff'         => ['team.time-clock'],
];

// ── HELPERS ───────────────────────────────────────────────────────────────────
$_role = $current_role ?? 'user';
$_tv   = $target_view  ?? '';
$_tt   = $target_table ?? '';
$_h    = fn($s): string => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
$_slug = fn(string $s): string => trim(preg_replace('/[^a-z0-9]+/', '-', strtolower($s)), '-');

$_can = function(string $type, string $val) use ($_role): bool {
    if ($type === 'always') return true;
    if ($_role === 'administrator') return true;
    if ($_role === 'Staff') return $type === 'view' && $val === 'timecard';   // Jobs pack P1: Time Clock only
    if ($type === 'view')  return function_exists('hasViewAccess')  ? hasViewAccess($_role, $val)  : true;
    if ($type === 'table') return function_exists('hasTableAccess') ? hasTableAccess($_role, $val) : true;
    return false;
};

$_active = function(string $href) use ($_tv, $_tt): bool {
    if (str_starts_with($href, '/')) return false;   // standalone pages (POS, Backups…) have no sidebar state
    parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $p);
    $v = $p['view'] ?? ''; $t = $p['table'] ?? '';
    // Social Queue has a Pinterest tab on the same view (?view=social&tab=pinterest, Nov 2026)
    if ($v === 'social') return $_tv === 'social' && (string)($p['tab'] ?? '') === (string)($_GET['tab'] ?? '');
    return $v === 'schema' ? ($_tv === 'schema' && $_tt === $t) : ($_tv === $v && $v !== '');
};

$_full_href = fn(string $h): string => (str_starts_with($h, '/') ? $h : '/admin/admin.php' . $h);

// ── BADGE COUNTS ──────────────────────────────────────────────────────────────
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

// ── BUILD VISIBLE NAV ─────────────────────────────────────────────────────────
$_nav = []; $_catalog = []; $_cur_section = null; $_cur_item = null;
if ($_role !== 'Staff') {
    $_catalog['dashboard'] = ['label'=>'Dashboard','short'=>'Home','icon'=>'🏠','href'=>'/admin/admin.php?view=dashboard','active'=>$_tv === 'dashboard','badge'=>0];
}
foreach ($_sections as $_sk => $_sec) {
    $_items = [];
    foreach ($_sec['items'] as $_it) {
        $_tabs = [];
        foreach ($_it['tabs'] as [$_tl, $_th, $_tct, $_tcv]) {
            if (!$_can($_tct, $_tcv)) continue;
            $_tabs[] = ['label'=>$_tl, 'href'=>$_full_href($_th), 'active'=>$_active($_th),
                        'badge'=>(int)($_badges[$_tcv] ?? 0), 'badge_title'=>$_badge_titles[$_tcv] ?? ''];
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

$_nav_data = [
    'sections'       => array_keys($_nav),
    'currentSection' => $_cur_section,
    'catalog'        => $_catalog,
    'defaults'       => $_defaults,
    'userKey'        => (string)($current_user_id ?? ''),
];
?>

<style>
/* ── Sidebar collapse (icon rail) ── */
#adminSidebar{transition:width .18s ease;position:relative;z-index:30}
#adminSidebar.sb-collapsed{width:3.5rem;overflow:visible}
#adminSidebar.sb-collapsed .sb-text,
#adminSidebar.sb-collapsed .sb-arrow,
#adminSidebar.sb-collapsed .sb-badge,
#adminSidebar.sb-collapsed .sb-brand-full{display:none}
#adminSidebar.sb-collapsed .sb-brand-mark{display:block}
#adminSidebar.sb-collapsed .sb-brand{padding-left:0;padding-right:0;text-align:center}
#adminSidebar.sb-collapsed .sb-row{justify-content:center;padding-left:0;padding-right:0;gap:0}
#adminSidebar .sb-brand-mark{display:none}
/* Collapsed: section items become a fly-out anchored to the rail */
#adminSidebar.sb-collapsed [data-sec]{position:relative}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"]{
    display:none !important; position:absolute; left:100%; top:0;
    min-width:12rem; max-height:80vh; overflow-y:auto;
    background:#1c1917; border:1px solid #44403c; border-radius:.5rem;
    padding:.4rem; box-shadow:0 12px 30px rgba(0,0,0,.55); z-index:60;
}
#adminSidebar.sb-collapsed [data-sec]:hover > div[id^="sec-"]{display:block !important}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-text{display:inline !important}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-row{justify-content:flex-start !important;padding:.35rem .5rem !important;gap:.5rem !important}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-badge{display:inline-block !important}

/* ── Phone bottom bar ── */
.nav-bar{display:flex}
.nav-slot{flex:1 1 0;min-width:0;min-height:58px;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:2px;
          padding:6px 2px;color:#a8a29e;font-size:10px;font-weight:700;text-decoration:none;background:none;border:0}
.nav-slot.is-active{color:#fff}
.nav-slot.is-active .nav-slot-icon{background:#44403c}
.nav-slot-icon{position:relative;font-size:20px;line-height:1;padding:4px 14px;border-radius:999px}
.nav-slot-label{max-width:100%;overflow:hidden;text-overflow:ellipsis;white-space:nowrap}
.nav-badge{position:absolute;top:-4px;right:4px;background:#f59e0b;color:#fff;font-size:9px;font-weight:900;line-height:1;padding:2px 4px;border-radius:999px}

/* ── More sheet ── */
html.nav-sheet-open, html.nav-sheet-open body{overflow:hidden}
.nav-tile{position:relative;display:flex;flex-direction:column;align-items:center;justify-content:center;gap:4px;min-height:74px;padding:8px 4px;
          border-radius:12px;background:#292524;color:#e7e5e4;font-size:11px;font-weight:700;line-height:1.2;text-align:center;text-decoration:none;border:2px solid transparent}
.nav-tile .nav-tile-icon{position:relative;font-size:22px;line-height:1}
.nav-tile.is-current{background:#44403c;color:#fff}
.is-editing .nav-tile{border-style:dashed;border-color:#57534e}
.is-editing .nav-tile.is-pinned{border-style:solid;border-color:#f59e0b;background:#3a332c}
.is-editing .nav-tile.is-pinned::after{content:"✓";position:absolute;top:4px;right:6px;color:#f59e0b;font-size:12px;font-weight:900}

/* ── Page tabs ── */
.nav-tabs-wrap{margin:0 0 1rem}
.nav-tabs-crumb{font-size:10px;font-weight:900;text-transform:uppercase;letter-spacing:.1em;color:#78716c;margin-bottom:.35rem}
.nav-tabs{display:flex;gap:.25rem;overflow-x:auto;border-bottom:1px solid #d6d3d1;scrollbar-width:none}
.nav-tabs::-webkit-scrollbar{display:none}
.nav-tab{position:relative;white-space:nowrap;padding:.5rem .85rem;font-weight:700;font-size:12px;color:#57534e;border-bottom:2px solid transparent;margin-bottom:-1px;text-decoration:none}
.nav-tab:hover{color:#1c1917}
.nav-tab.is-active{color:#1c1917;border-color:#1c1917}
.nav-tab .nav-tab-badge{margin-left:.35rem;background:#f59e0b;color:#fff;font-size:9px;font-weight:900;padding:1px 5px;border-radius:999px}

/* ── Phone layout (<768px) ── */
@media (max-width:767.98px){
    body > main{padding:.75rem .75rem calc(5.5rem + env(safe-area-inset-bottom)) !important;max-height:none !important;min-width:0;overflow-x:auto !important}
    /* 16px stops iOS zooming into form fields */
    body > main input:not([type=checkbox]):not([type=radio]):not([type=range]), body > main select, body > main textarea{font-size:16px !important}
    .nav-tab{padding:.7rem .9rem;font-size:13px}
}
</style>

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

  <nav class="flex-1 px-2 py-3 text-xs" aria-label="Main">

    <?php if (isset($_catalog['dashboard'])): ?>
    <a href="/admin/admin.php?view=dashboard" title="Dashboard"
       class="sb-row flex items-center gap-2 px-3 py-2 rounded mb-1 font-bold transition
              <?= $_tv === 'dashboard' ? 'bg-stone-700 text-white' : 'text-stone-300 hover:bg-stone-800' ?>">
      <span class="shrink-0">🏠</span> <span class="sb-text">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php foreach ($_nav as $_sk => $_sec): ?>
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
    <?php endforeach; ?>

  </nav>

  <div class="px-4 py-3 border-t border-stone-700 shrink-0 space-y-2">
    <div class="sb-text text-[9px] font-mono text-stone-500 uppercase tracking-wider"><?= $_h($_role) ?></div>
    <a href="/admin/logout.php" title="Sign Out"
       class="sb-row flex items-center gap-2 px-2 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider font-mono text-stone-400 hover:bg-red-900 hover:text-red-200 transition">
      <span class="shrink-0">🚪</span> <span class="sb-text">Sign Out</span>
    </a>
  </div>
</aside>

<!-- ═══ Phone: bottom bar ═══ -->
<nav id="navBottomBar" aria-label="Shortcuts"
     class="md:hidden fixed bottom-0 inset-x-0 z-40 bg-stone-900 border-t border-stone-700 print:hidden"
     style="padding-bottom:env(safe-area-inset-bottom)">
  <div id="navBarSlots" class="nav-bar">
    <?php foreach ($_defaults as $_cid): $_c = $_catalog[$_cid]; ?>
    <a href="<?= $_h($_c['href']) ?>" data-sc-slot="<?= $_h($_cid) ?>" class="nav-slot<?= $_c['active'] ? ' is-active' : '' ?>">
      <span class="nav-slot-icon"><?= $_c['icon'] ?><?php if ($_c['badge'] > 0): ?><span class="nav-badge"><?= (int)$_c['badge'] ?></span><?php endif; ?></span>
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
  <div class="absolute bottom-0 inset-x-0 max-h-[88vh] overflow-y-auto bg-stone-900 text-stone-200 rounded-t-2xl px-4 pt-3"
       style="padding-bottom:calc(1rem + env(safe-area-inset-bottom))">
    <div class="mx-auto mb-3 h-1 w-10 rounded-full bg-stone-600"></div>
    <div class="flex items-center justify-between gap-2 mb-2">
      <div>
        <div class="font-serif font-black text-sm text-white">Bat Cave Road Farm</div>
        <div class="text-[9px] font-mono text-stone-500 uppercase tracking-widest"><?= $_h($_role) ?></div>
      </div>
      <div class="flex items-center gap-2">
        <button type="button" id="navResetBtn" class="hidden px-3 py-2 rounded-lg text-[11px] font-bold text-stone-300 bg-stone-800">Reset</button>
        <button type="button" id="navEditBtn" class="px-3 py-2 rounded-lg text-[11px] font-bold text-stone-900 bg-amber-400">Edit shortcuts</button>
        <button type="button" data-nav-close class="w-9 h-9 rounded-full bg-stone-800 text-stone-300 text-lg leading-none" aria-label="Close">&times;</button>
      </div>
    </div>
    <p id="navEditHint" class="hidden mb-3 text-[11px] text-amber-300"></p>

    <?php if (isset($_catalog['dashboard'])): $_c = $_catalog['dashboard']; ?>
    <div class="grid grid-cols-3 gap-2 mb-4">
      <a href="<?= $_h($_c['href']) ?>" data-sc-id="dashboard" class="nav-tile<?= $_c['active'] ? ' is-current' : '' ?>">
        <span class="nav-tile-icon">🏠</span><span>Dashboard</span>
      </a>
    </div>
    <?php endif; ?>

    <?php foreach ($_nav as $_sk => $_sec): ?>
    <div class="mb-4">
      <div class="text-[10px] font-black uppercase tracking-widest text-stone-500 mb-2"><?= $_sec['icon'] ?> <?= $_h($_sec['label']) ?></div>
      <div class="grid grid-cols-3 gap-2">
        <?php foreach ($_sec['items'] as $_item): ?>
        <a href="<?= $_h($_item['href']) ?>" data-sc-id="<?= $_h($_item['id']) ?>" class="nav-tile<?= $_item['active'] ? ' is-current' : '' ?>">
          <span class="nav-tile-icon"><?= $_item['icon'] ?><?php if ($_item['badge'] > 0): ?><span class="nav-badge"><?= (int)$_item['badge'] ?></span><?php endif; ?></span>
          <span><?= $_h($_item['label']) ?></span>
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
  <div class="nav-tabs-crumb"><?= $_h($_nav[$_cur_section]['label']) ?> › <?= $_h($_cur_item['label']) ?></div>
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
<script src="/admin/assets/admin-nav.js?v=20261105a"></script>
