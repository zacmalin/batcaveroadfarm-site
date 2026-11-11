# Adding an admin page (Farm OS shell)

Every admin page uses the shared Farm OS shell: the sidebar, the phone bottom bar and More sheet,
and the tabs for the other pages in the same section. Pages look like the Hiring screens
(`admin/views/jobs/*`): stone text and borders, white cards, amber primary buttons.
(Nov 11, 2026 — "Bring every Farm OS admin page into the shared shell".)

## Which kind of page?

| Kind | Where | Shell comes from |
| --- | --- | --- |
| **Console view** (most screens) | `admin/views/<name>.php`, registered in `$view_map` in `admin/admin.php` | `admin.php` (Tailwind CDN). Nothing to add. |
| **Standalone page** (its own URL, own POST handling) | `admin/<name>.php` | `bcrf_shell_open()` / `bcrf_shell_close()` from `includes/admin_shell.php` |
| **Full-screen exception** | register, gate, legacy till, label printing, sign-in | none: listed in `tools/admin-shell-exceptions.txt` |

`admin.php` is protected from the farm connector, so a new **console view** needs a manual upload of
`admin.php`. A **standalone page** doesn't.

## Starter for a standalone page

```php
<?php
// admin/example.php — what this page is for (date)
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
require_permission([['table', 'inventory', 'edit']]);        // or [['view', '<view key>']]
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/admin_shell.php';

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf = $_SESSION['csrf_token'];
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if (($_SERVER['REQUEST_METHOD'] ?? '') === 'POST') {
    if (!hash_equals($csrf, (string)($_POST['csrf_token'] ?? ''))) { /* flash + redirect */ }
    // … save, then redirect back (POST → redirect → GET)
}

bcrf_shell_open('Example', ['width' => 'max-w-3xl']);   // after all redirects
?>
  <h1 class="text-2xl font-bold text-stone-800 mb-1">Example</h1>
  <p class="text-sm text-stone-500 mb-4">One line on what this page is for.</p>
  <form method="POST" class="bg-white border border-stone-200 rounded-lg p-4 shadow-sm space-y-3">
    <input type="hidden" name="csrf_token" value="<?= $h($csrf) ?>">
    <div>
      <label for="f_name" class="block text-xs font-semibold text-stone-600 mb-1">Name</label>
      <input id="f_name" name="name" class="p-2 border border-stone-300 rounded bg-stone-50 text-sm w-full">
    </div>
    <button class="px-4 py-1.5 rounded text-sm bg-amber-700 hover:bg-amber-800 text-white font-semibold">Save</button>
  </form>
<?php
bcrf_shell_close();
```

`admin/vendors.php` is the full worked example (list, search, editor, toast, errors).

## Checklist

- [ ] **Shell:** standalone pages call `bcrf_shell_open()` after every redirect and `bcrf_shell_close()` at the end
      (CI fails otherwise, unless the file is in `tools/admin-shell-exceptions.txt`).
- [ ] **Nav:** add the page as a tab of the right item in `$_sections` in `admin/views/layout/sidebar.php`
      (`['Label', '/admin/example.php', 'view'|'table', '<key>']`). The tab is marked active on that file; query
      values in the link (e.g. `?tab=tax`) must match too. No "← Back to console" links: the tabs replace them.
- [ ] **Permission:** check access server-side on the page itself (`require_permission`), not only in the nav.
- [ ] **Styles:** use Tailwind classes like the Hiring screens. Then add the file to `tools/admin-css/input.css` and
      rebuild `admin/assets/admin.css` (`cd tools/admin-css && npm ci && npm run build`) — CI fails if it's stale.
- [ ] **Strict policy:** no inline `<style>`, `style=""`, `<script>` or `onclick`/`onsubmit` — they're blocked.
      Put scripts in `admin/assets/*.js` and load them with `['js' => ['name.js']]`. (`['strict' => false]` exists
      only for the older Settings pages that still rely on inline confirms.)
- [ ] **Phone:** check it at phone width — bottom bar, More sheet, tabs, forms at 16px (no zoom).
- [ ] **After changing admin.php:** open every sidebar item once (a broken allow-list blanks whole sections).

## Shell options

`bcrf_shell_open($title, [...])`

- `width` — Tailwind max-width for the content (default `max-w-5xl`)
- `toast` — `['ok'|'err'|'warning', 'message']`, shown as the console toast
- `legacy` — `true` for pages still built on the old `register.css` class names (`card`, `lrow`, `btn`, `stackf`…);
  loads `reports.css` + `admin-farmos.css` and wraps the content in `.fos`
- `css`, `js` — extra files from `admin/assets/`
- `strict` — `false` skips the strict policy `<meta>` (older Settings pages only)
