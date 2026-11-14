<?php
// includes/staff_extras.php — per-person extra access (Nov 14, 2026; migration 0064).
// A person gets everything their role has, plus any page or table an Owner ticked for them on the Permissions page
// (By person tab). Example: a Shopkeep who writes well gets Blog (edit) without becoming a Manager.
//   - Extras are checked inside hasViewAccess() / hasTableAccess() (admin/includes/permission_checks.php), so every screen,
//     menu item and save that already asks those functions honors them. They also lift the built-in role blocks
//     (Shopkeep has no blog, Content has no register) for that one person.
//   - Only the signed-in person's own checks use their extras: a check for some other role never picks them up.
//   - Owner-only screens can't be given as extras. Owners and Staff (time clock only) have no extras.
//   - Public content (bcrf_review_tables) edited only thanks to an extra waits for review: the person can save drafts and
//     "Send for review" (a bell alert to Owners and Managers), but can't publish, unpublish, change or delete what is live.
// Nothing here is farm-specific.
if (!function_exists('bcrf_extras_ready')) {

require_once __DIR__ . '/permission_sections.php';   // the items and roles the Permissions page shows

function bcrf_extras_ready(PDO $pdo): bool {
    static $ready = [];
    $k = spl_object_id($pdo);
    if (isset($ready[$k])) return $ready[$k];
    try { $pdo->query("SELECT 1 FROM staff_access_extras LIMIT 0"); return $ready[$k] = true; }
    catch (Throwable $e) { return $ready[$k] = false; }
}

/** Roles that can have extras: the roles the Permissions page edits (not Owners, who have everything, nor Staff). */
function bcrf_extras_roles(): array { return bcrf_permission_roles(); }

/** Items that can be given as extras: [type, key, icon, label, section label] for every page/table on the Permissions page. */
function bcrf_extras_items(): array {
    $out = [];
    foreach (bcrf_permission_sections() as $sec) {
        foreach ($sec['items'] as [$type, $key, $icon, $label]) {
            if ($type === 'view' || $type === 'table') $out[] = [$type, $key, $icon, $label, $sec['label']];
        }
    }
    return $out;
}

/** Forget what was loaded (after a save, and in tests). */
function bcrf_extras_forget(): void { $GLOBALS['bcrf_extras_cache'] = []; }

/** One person's extras: ['view' => [key => true], 'table' => [key => ['view' => bool, 'edit' => bool]]]. */
function bcrf_extras_load(PDO $pdo, int $uid): array {
    $empty = ['view' => [], 'table' => []];
    if ($uid <= 0 || !bcrf_extras_ready($pdo)) return $empty;
    $cache = &$GLOBALS['bcrf_extras_cache'];
    if (!is_array($cache)) $cache = [];
    if (isset($cache[$uid])) return $cache[$uid];
    $out = $empty;
    try {
        $st = $pdo->prepare("SELECT kind, item_key, can_view, can_edit FROM staff_access_extras WHERE user_id = ?");
        $st->execute([$uid]);
        foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $r) {
            if ($r['kind'] === 'view' && (int)$r['can_view'] === 1) $out['view'][$r['item_key']] = true;
            if ($r['kind'] === 'table') {
                $e = (int)$r['can_edit'] === 1;
                $out['table'][$r['item_key']] = ['view' => $e || (int)$r['can_view'] === 1, 'edit' => $e];
            }
        }
    } catch (Throwable $e) {
        error_log('[extras] load: ' . $e->getMessage());
    }
    return $cache[$uid] = $out;
}

/** The signed-in person whose extras apply to a check for $role (0 = none): only checks for their own role count. */
function bcrf_extras_uid_for(string $role): int {
    if ($role === '' || !in_array($role, bcrf_extras_roles(), true)) return 0;
    if ((string)($_SESSION['user_role'] ?? '') !== $role) return 0;
    return (int)($_SESSION['user_id'] ?? 0);
}

function bcrf_extras_pdo(): ?PDO {
    $p = $GLOBALS['pdo'] ?? null;
    return $p instanceof PDO ? $p : null;
}

/** Has the signed-in person (with role $role) been given page $view as an extra? */
function bcrf_extra_view(string $role, string $view): bool {
    $uid = bcrf_extras_uid_for($role);
    $pdo = bcrf_extras_pdo();
    if (!$uid || !$pdo) return false;
    return !empty(bcrf_extras_load($pdo, $uid)['view'][$view]);
}

/** Has the signed-in person been given table $table ($action 'view' or 'edit'; edit includes view) as an extra? */
function bcrf_extra_table(string $role, string $table, string $action = 'view'): bool {
    $uid = bcrf_extras_uid_for($role);
    $pdo = bcrf_extras_pdo();
    if (!$uid || !$pdo) return false;
    $t = bcrf_extras_load($pdo, $uid)['table'][$table] ?? null;
    if (!$t) return false;
    return $action === 'edit' ? $t['edit'] : $t['view'];
}

/** Staff the By person tab can open: [id, first_name, last_name, email, role, account_status]. */
function bcrf_extras_person(PDO $pdo, int $uid): ?array {
    if ($uid <= 0) return null;
    $st = $pdo->prepare("SELECT id, first_name, last_name, email, role, account_status FROM users WHERE id = ?");
    $st->execute([$uid]);
    $u = $st->fetch(PDO::FETCH_ASSOC);
    return ($u && in_array((string)$u['role'], bcrf_extras_roles(), true)) ? $u : null;
}

/** Search staff who can have extras by name or email (blank = everyone), turned-off logins last. */
function bcrf_extras_search(PDO $pdo, string $q, int $limit = 30): array {
    $roles = bcrf_extras_roles();
    $in = implode(',', array_fill(0, count($roles), '?'));
    $args = $roles;
    $where = "role IN ($in)";
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $where .= " AND (CONCAT(first_name, ' ', last_name) LIKE ? OR email LIKE ?)";
        $args[] = $like; $args[] = $like;
    }
    $limit = max(1, min(200, $limit));
    $st = $pdo->prepare("SELECT id, first_name, last_name, email, role, account_status FROM users WHERE $where
                         ORDER BY account_status = 'disabled', first_name, last_name LIMIT $limit");
    $st->execute($args);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

/** Everyone who has extras, with how many: [id, first_name, last_name, role, account_status, n]. */
function bcrf_extras_people(PDO $pdo): array {
    if (!bcrf_extras_ready($pdo)) return [];
    return $pdo->query("SELECT u.id, u.first_name, u.last_name, u.role, u.account_status, COUNT(*) AS n
                        FROM staff_access_extras x JOIN users u ON u.id = x.user_id
                        GROUP BY u.id, u.first_name, u.last_name, u.role, u.account_status
                        ORDER BY u.first_name, u.last_name")->fetchAll(PDO::FETCH_ASSOC);
}

/**
 * Replace one person's extras with the ticked items. $views = [key => 1], $tables = [key => ['view' => 1, 'edit' => 1]]
 * (the same shape the role form posts). Items not on the Permissions page are ignored. Returns [pages, view tables, edit tables].
 */
function bcrf_extras_save(PDO $pdo, int $uid, array $views, array $tables, ?int $by): array {
    if (!bcrf_extras_ready($pdo)) throw new RuntimeException('Run migration 0064 in Settings > Database first.');
    if (!bcrf_extras_person($pdo, $uid)) throw new RuntimeException('Pick a staff member (Owners already have everything; Staff logins are time clock only).');
    $ins = $pdo->prepare("INSERT INTO staff_access_extras (user_id, kind, item_key, can_view, can_edit, granted_by, granted_at)
                          VALUES (?, ?, ?, ?, ?, ?, UTC_TIMESTAMP())");
    $n = [0, 0, 0];
    $pdo->beginTransaction();
    try {
        $pdo->prepare("DELETE FROM staff_access_extras WHERE user_id = ?")->execute([$uid]);
        foreach (bcrf_extras_items() as [$type, $key]) {
            if ($type === 'view' && !empty($views[$key])) {
                $ins->execute([$uid, 'view', $key, 1, 0, $by]); $n[0]++;
            } elseif ($type === 'table') {
                $e = !empty($tables[$key]['edit']) ? 1 : 0;
                $v = ($e || !empty($tables[$key]['view'])) ? 1 : 0;
                if ($v) { $ins->execute([$uid, 'table', $key, $v, $e, $by]); $n[1]++; $n[2] += $e; }
            }
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    bcrf_extras_forget();
    return $n;
}

// ── Review before public content goes live ──────────────────────────────────────────────────────

/** Tables whose rows show on the public website (Save Draft / Publish). */
function bcrf_review_tables(): array { return ['blog_posts', 'recipes', 'animals']; }

/** True when this person can edit $table only because of an extra (their role can't), so public changes need review. */
function bcrf_needs_review(string $role, string $table): bool {
    if ($role === 'administrator' || !in_array($table, bcrf_review_tables(), true) || !function_exists('hasTableAccess')) return false;
    return bcrf_extra_table($role, $table, 'edit') && !hasTableAccess($role, $table, 'edit', false);
}

/** Is this row live on the website now? (animals: is_active; blog posts and recipes: status published) */
function bcrf_review_row_live(PDO $pdo, string $table, int $id): bool {
    if ($id <= 0 || !in_array($table, bcrf_review_tables(), true)) return false;
    $col = $table === 'animals' ? "is_active = 1" : "status = 'published'";
    try {
        $st = $pdo->prepare("SELECT $col FROM `$table` WHERE id = ?");
        $st->execute([$id]);
        return (int)$st->fetchColumn() === 1;
    } catch (Throwable $e) { return false; }
}

/** Where a reviewer opens the item. */
function bcrf_review_link(string $table, int $id): string {
    return $table === 'animals' ? '/admin/animals.php?id=' . $id . '&tab=public'
                                : '/admin/admin.php?view=schema&table=' . rawurlencode($table) . '&edit_id=' . $id;
}

/** Bell alert to Owners and Managers: "<title> is ready for review". Returns the notification id (0 = not sent). */
function bcrf_review_request(PDO $pdo, string $table, int $id, string $title, string $who): int {
    $nf = __DIR__ . '/notifications.php';
    if (!function_exists('bcrf_notify')) { if (!is_file($nf)) return 0; require_once $nf; }
    $what = ['blog_posts' => 'blog post', 'recipes' => 'recipe', 'animals' => 'animal page'][$table] ?? 'item';
    $title = trim($title) !== '' ? trim($title) : ucfirst($what) . ' #' . $id;
    return bcrf_notify($pdo, [
        'kind'   => 'content_review',
        'title'  => 'Ready for review: ' . $title,
        'body'   => trim($who) . ' sent this ' . $what . ' for review. Open it, check it, and press Publish when it looks good.',
        'link'   => bcrf_review_link($table, $id),
        'dedupe' => 'content_review:' . $table . ':' . $id . ':' . gmdate('YmdHi'),
    ]);
}

/** Content (Media) logins stay off the register and gate unless an Owner gave that person one of $views as an extra. */
function bcrf_content_role_blocked(array $views): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    if ($role !== 'Media') return false;
    foreach ($views as $v) if (bcrf_extra_view($role, (string)$v)) return false;
    return true;
}

}
