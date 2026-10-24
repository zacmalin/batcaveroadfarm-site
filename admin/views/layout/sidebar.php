<?php
// admin/views/layout/sidebar.php
// Sectioned, collapsible sidebar with permission gating.

// ── SECTION MAP ───────────────────────────────────────────────────────────────
// Each item: [label, icon, href, check_type, check_value]
// check_type: 'view' | 'table' | 'always'
$_sections = [
    'storefront' => ['label'=>'Storefront','icon'=>'🏪','items'=>[
        ['Time Clock',      '⏰', '?view=timecard',                    'view',  'timecard'],
        ['POS',             '💳', '/admin/pos.php',                    'view',  'pos'],
        ['Inventory',       '📦', '?view=inventory_update',            'view',  'inventory_update'],
        ['Full Editor',     '🗂️', '?view=schema&table=inventory',      'table', 'inventory'],
        ['Inv. Categories', '🏷️', '?view=schema&table=inventory_categories', 'table', 'inventory_categories'],
        ['Bookings',        '📋', '?view=bookings',                   'view',  'bookings'],
        ['Private Events',  '🎉', '?view=event_inquiries',            'view',  'event_inquiries'],
        ['Annual Passes',   '🎟', '?view=passes',                     'view',  'passes'],
    ]],
    'animals' => ['label'=>'Animals','icon'=>'🐾','items'=>[
        ['Roster',          '📋', '?view=schema&table=animals',        'table', 'animals'],
        ['Breeds',          '🐄', '?view=schema&table=breeds',         'table', 'breeds'],
        ['Classification',  '🔬', '?view=schema&table=classifications','table', 'classifications'],
    ]],
    'kitchen' => ['label'=>'Kitchen','icon'=>'🍳','items'=>[
        ['Recipes',          '📖', '?view=schema&table=recipes',           'table', 'recipes'],
        ['Cuisines',         '🌍', '?view=schema&table=cuisines',           'table', 'cuisines'],
        ['Ingredients',      '🌿', '?view=schema&table=ingredients',        'table', 'ingredients'],
        ['Categories',       '🏷️', '?view=schema&table=categories',         'table', 'categories'],
        ['Measurement Units','⚖️', '?view=schema&table=measurement_units',  'table', 'measurement_units'],
        ['Unit Conversions', '🔄', '?view=schema&table=unit_conversions',   'table', 'unit_conversions'],
    ]],
    'media' => ['label'=>'Media','icon'=>'📡','items'=>[
        ['Blog',             '✍️', '?view=schema&table=blog_posts',    'table', 'blog_posts'],
        ['Newsletter',       '📧', '?view=newsletter',                 'view',  'newsletter'],
        ['Social Queue',     '📣', '?view=social',                     'view',  'social'],
        ['Events',           '📅', '?view=events',                    'view',  'events'],
        ['Image Library',    '🖼️', '?view=media',                      'view',  'media'],
        ['Comments',         '💬', '?view=comment_moderation',         'view',  'comment_moderation'],
        ['Subscribers',      '👥', '?view=schema&table=mailing_list',  'table', 'mailing_list'],
    ]],
    'hiring' => ['label'=>'Hiring','icon'=>'🧑‍🌾','items'=>[
        ['Applicants',       '📥', '?view=jobs',                  'view',  'jobs'],
        ['Hiring emails',    '📧', '?view=jobs_emails',           'view',  'jobs_emails'],
        ['Staff',            '🪪', '?view=jobs_staff',            'view',  'jobs_staff'],
        ['Jobs',             '📌', '?view=jobs_posts',            'view',  'jobs_posts'],
        ['Question Bank',    '❓', '?view=jobs_questions',        'view',  'jobs_questions'],
        ['Categories',       '🏷️', '?view=jobs_categories',       'view',  'jobs_categories'],
    ]],
    'admin' => ['label'=>'Admin','icon'=>'⚙️','items'=>[
        ['Timesheets',       '📋', '?view=timesheet',              'view',  'timesheet'],
        ['Permission Matrix','🔐', '?view=permissions',            'view',  'permissions'],
        ['Staff',            '👤', '?view=schema&table=users',     'table', 'users'],
        ['Receipts',         '🧾', '?view=receipts',              'view',  'receipts'],
        ['Audit Log',        '📜', '?view=audit_log',              'view',  'audit_log'],
        ['Sales Analytics',  '📈', '?view=sales_analytics',       'view',  'sales_analytics'],
        ['Seasonal Themes',  '🎨', '?view=theme_scheduler',       'view',  'theme_scheduler'],
        ['Site Info',        '🏠', '?view=site_info',             'view',  'site_info'],
    ]],
];

// ── HELPERS ───────────────────────────────────────────────────────────────────
$_role = $current_role ?? 'user';
$_tv   = $target_view  ?? '';
$_tt   = $target_table ?? '';

$_can = function(string $type, string $val) use ($_role): bool {
    if ($_role === 'administrator') return true;
    if ($_role === 'Staff') return $type === 'view' && $val === 'timecard';   // Jobs pack P1: Time Clock only
    if ($type === 'view')  return function_exists('hasViewAccess')  ? hasViewAccess($_role, $val)  : true;
    if ($type === 'table') return function_exists('hasTableAccess') ? hasTableAccess($_role, $val) : true;
    return false;
};

$_active = function(string $href) use ($_tv, $_tt): bool {
    if (strpos($href, '/admin/pos.php') !== false) return false;
    parse_str(parse_url($href, PHP_URL_QUERY) ?: '', $p);
    $v = $p['view'] ?? ''; $t = $p['table'] ?? '';
    return $v === 'schema' ? ($_tv === 'schema' && $_tt === $t) : ($_tv === $v && $v !== '');
};

$_full_href = fn(string $h): string => (str_starts_with($h, '/') ? $h : '/admin/admin.php' . $h);

// Detect which section the current page is in
$_cur_section = null;
foreach ($_sections as $_sk => $_sec) {
    foreach ($_sec['items'] as $_it) {
        if ($_active($_it[2])) { $_cur_section = $_sk; break 2; }
    }
}
$_pcc = $pending_comments_count ?? 0;
// Jobs pack P4: new-applicant count for the Applicants badge (administrators only).
$_jnc = 0; $_jqc = 0; $_jyc = 0;
if ($_role === 'administrator' && isset($pdo)) {
    try {
        $_jnc = (int)$pdo->query("SELECT COUNT(*) FROM applications WHERE status = 'new' AND is_spam = 0 AND archived_at IS NULL")->fetchColumn();
        $_jqc = (int)$pdo->query("SELECT COUNT(*) FROM email_queue WHERE status IN ('pending','failed')")->fetchColumn();   // Jobs pack P5
        $_jyc = (int)$pdo->query("SELECT COUNT(*) FROM user_employment WHERE end_date IS NULL AND is_minor = 1 AND youth_cert_received_at IS NULL")->fetchColumn();   // Jobs pack P6
    }
    catch (Throwable $e) { $_jnc = 0; $_jqc = 0; $_jyc = 0; /* Jobs tables not installed */ }
}
?>

<style>
/* Sidebar collapse (icon rail) — admin is CSP-exempt, inline style is fine */
#adminSidebar{transition:width .18s ease;position:relative;z-index:30}
#adminSidebar.sb-collapsed{width:3.5rem;overflow:visible}
#adminSidebar.sb-collapsed .sb-text{display:none}
#adminSidebar.sb-collapsed .sb-arrow{display:none}
#adminSidebar.sb-collapsed .sb-badge{display:none}
#adminSidebar.sb-collapsed .sb-brand-full{display:none}
#adminSidebar.sb-collapsed .sb-brand-mark{display:block}
#adminSidebar.sb-collapsed .sb-brand{padding-left:0;padding-right:0;text-align:center}
#adminSidebar.sb-collapsed .sb-row{justify-content:center;padding-left:0;padding-right:0;gap:0}
#adminSidebar .sb-brand-mark{display:none}

/* Collapsed: section items become a hover fly-out anchored to the rail */
#adminSidebar.sb-collapsed [data-sec]{position:relative}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"]{
    display:none !important;            /* override the accordion's inline display */
    position:absolute; left:100%; top:0;
    min-width:12rem; max-height:80vh; overflow-y:auto;
    background:#1c1917; border:1px solid #44403c; border-radius:.5rem;
    padding:.4rem; box-shadow:0 12px 30px rgba(0,0,0,.55); z-index:60;
}
#adminSidebar.sb-collapsed [data-sec]:hover > div[id^="sec-"]{ display:block !important; }
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-text{ display:inline !important; }
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-row{
    justify-content:flex-start !important; padding:.35rem .5rem !important; gap:.5rem !important;
}
#adminSidebar.sb-collapsed [data-sec] > div[id^="sec-"] .sb-badge{ display:inline-block !important; }
</style>

<aside id="adminSidebar" class="w-56 shrink-0 bg-stone-900 text-stone-200 flex flex-col min-h-screen overflow-y-auto print:hidden">
<script>try{if(localStorage.getItem('bcf_sidebar_collapsed')==='1'){document.getElementById('adminSidebar').classList.add('sb-collapsed');}}catch(e){}</script>

  <!-- Brand -->
  <a href="/admin/admin.php?view=dashboard"
     class="sb-brand block px-5 py-4 border-b border-stone-700 hover:bg-stone-800 transition shrink-0">
    <div class="sb-brand-full">
      <div class="font-serif font-black text-sm text-white leading-snug">Bat Cave Road Farm</div>
      <div class="text-[9px] font-mono text-stone-500 uppercase tracking-widest mt-0.5">Admin Panel</div>
    </div>
    <div class="sb-brand-mark text-lg">🐐</div>
  </a>

  <!-- Collapse toggle -->
  <button type="button" id="sbCollapseBtn" onclick="sidebarCollapseToggle()"
    class="sb-row flex items-center justify-end gap-2 px-3 py-1.5 shrink-0 border-b border-stone-800
           text-stone-500 hover:text-white hover:bg-stone-800 transition">
    <span class="sb-text text-[9px] font-mono uppercase tracking-widest">Collapse</span>
    <span id="sbCollapseIcon" class="text-sm leading-none">«</span>
  </button>

  <nav class="flex-1 px-2 py-3 text-xs">

    <!-- Dashboard -->
    <?php if ($_role !== 'Staff'): ?>
    <a href="/admin/admin.php?view=dashboard" title="Dashboard"
       class="sb-row flex items-center gap-2 px-3 py-2 rounded mb-1 font-bold transition
              <?= $_tv === 'dashboard' ? 'bg-stone-700 text-white' : 'text-stone-300 hover:bg-stone-800' ?>">
      <span class="shrink-0">🏠</span> <span class="sb-text">Dashboard</span>
    </a>
    <?php endif; ?>

    <?php foreach ($_sections as $_sk => $_sec):
      // Gate: only show section if at least one item is accessible
      $_visible = array_filter($_sec['items'], fn($_i) => $_can($_i[3], $_i[4]));
      if (empty($_visible)) continue;
    ?>
    <div class="mb-1" data-sec="<?= $_sk ?>">

      <!-- Section header -->
      <button type="button" onclick="sidebarToggle('<?= $_sk ?>')" title="<?= htmlspecialchars($_sec['label']) ?>"
        class="sb-row w-full flex items-center justify-between px-3 py-1.5 rounded
               text-[10px] font-black uppercase tracking-widest
               text-stone-400 hover:text-white hover:bg-stone-800 transition mt-1">
        <span class="flex items-center gap-1.5">
          <span class="shrink-0 relative"><?= $_sec['icon'] ?><?php if ($_sk === 'hiring' && $_jnc > 0): ?><span class="absolute -top-1.5 -right-2 bg-amber-500 text-white text-[8px] font-black leading-none px-1 py-0.5 rounded-full" title="New applicants"><?= $_jnc ?></span><?php endif; ?></span>
          <span class="sb-text"><?= $_sec['label'] ?></span>
        </span>
        <span id="arr-<?= $_sk ?>" class="sb-arrow text-stone-500">▾</span>
      </button>

      <!-- Section items -->
      <div id="sec-<?= $_sk ?>" class="pl-1 mt-0.5 space-y-px">
        <?php foreach ($_visible as $_item):
          [$_label, $_icon, $_href, $_ct, $_cv] = $_item;
          $_is_act = $_active($_href);
        ?>
        <a href="<?= htmlspecialchars($_full_href($_href)) ?>" title="<?= htmlspecialchars($_label) ?>"
           class="sb-row flex items-center gap-2 px-3 py-1.5 rounded transition
                  <?= $_is_act ? 'bg-stone-700 text-white font-bold' : 'text-stone-400 hover:bg-stone-800 hover:text-stone-200' ?>">
          <span class="shrink-0"><?= $_icon ?></span>
          <span class="sb-text truncate"><?= htmlspecialchars($_label) ?></span>
          <?php if ($_label === 'Comments' && $_pcc > 0): ?>
            <span class="sb-badge ml-auto shrink-0 bg-amber-500 text-white text-[8px] font-black px-1 py-0.5 rounded"><?= $_pcc ?></span>
          <?php endif; ?>
          <?php if ($_label === 'Hiring emails' && $_jqc > 0): ?>
            <span class="sb-badge ml-auto shrink-0 bg-stone-500 text-white text-[8px] font-black px-1 py-0.5 rounded" title="Emails waiting for approval"><?= $_jqc ?></span>
          <?php endif; ?>
          <?php if ($_label === 'Staff' && $_jyc > 0): ?>
            <span class="sb-badge ml-auto shrink-0 bg-sky-600 text-white text-[8px] font-black px-1 py-0.5 rounded" title="Youth certificates still needed"><?= $_jyc ?></span>
          <?php endif; ?>
          <?php if ($_label === 'Applicants' && $_jnc > 0): ?>
            <span class="sb-badge ml-auto shrink-0 bg-amber-500 text-white text-[8px] font-black px-1 py-0.5 rounded" title="New applicants"><?= $_jnc ?></span>
          <?php endif; ?>
        </a>
        <?php endforeach; ?>
      </div>

    </div>
    <?php endforeach; ?>

  </nav>

  <!-- Role badge + logout -->
  <div class="px-4 py-3 border-t border-stone-700 shrink-0 space-y-2">
    <div class="sb-text text-[9px] font-mono text-stone-500 uppercase tracking-wider"><?= htmlspecialchars($_role) ?></div>
    <a href="/admin/logout.php" title="Sign Out"
       class="sb-row flex items-center gap-2 px-2 py-1.5 rounded text-[10px] font-bold uppercase tracking-wider font-mono
              text-stone-400 hover:bg-red-900 hover:text-red-200 transition">
      <span class="shrink-0">🚪</span> <span class="sb-text">Sign Out</span>
    </a>
  </div>

</aside>

<script>
(function () {
    var SECT_KEY = 'bcf_sidebar_open';        // single open section name (accordion)
    var COLL_KEY = 'bcf_sidebar_collapsed';   // '1' when collapsed to icon rail
    var sections = <?= json_encode(array_keys($_sections)) ?>;
    var currentSection = <?= json_encode($_cur_section) ?>;
    var aside = document.getElementById('adminSidebar');

    // ── Collapse (icon rail) ──────────────────────────────────────────────
    function applyCollapse(on) {
        if (aside) aside.classList.toggle('sb-collapsed', on);
        var ic = document.getElementById('sbCollapseIcon');
        if (ic) ic.textContent = on ? '»' : '«';
    }
    var collapsed = false;
    try { collapsed = localStorage.getItem(COLL_KEY) === '1'; } catch (e) {}
    applyCollapse(collapsed);

    window.sidebarCollapseToggle = function () {
        var nowCollapsed = !(aside && aside.classList.contains('sb-collapsed'));
        applyCollapse(nowCollapsed);
        try { localStorage.setItem(COLL_KEY, nowCollapsed ? '1' : '0'); } catch (e) {}
    };

    // ── Accordion (one section open at a time) ────────────────────────────
    function setOpen(openName) {
        sections.forEach(function (name) {
            var body = document.getElementById('sec-' + name);
            var arr  = document.getElementById('arr-' + name);
            var isOpen = (name === openName);
            if (body) body.style.display = isOpen ? '' : 'none';
            if (arr)  arr.textContent = isOpen ? '▾' : '▸';
        });
    }

    var stored = null;
    try { stored = localStorage.getItem(SECT_KEY); } catch (e) {}
    // Current page's section wins; otherwise the last one the user opened.
    var initial = currentSection || stored || null;
    setOpen(initial);
    try { localStorage.setItem(SECT_KEY, initial || ''); } catch (e) {}

    window.sidebarToggle = function (name) {
        var body = document.getElementById('sec-' + name);
        if (!body) return;
        var isOpen = body.style.display !== 'none';
        var next = isOpen ? null : name;   // re-clicking the open section closes it
        setOpen(next);
        try { localStorage.setItem(SECT_KEY, next || ''); } catch (e) {}
    };
}());
</script>
