<?php
// includes/list_sort.php — one Sort box for every Farm OS list (Nov 15, 2026).
// Each list gives its choices; the first one is the list's own order (what it showed before Sort existed).
//   $opts = ['default' => ['Due date'], 'az' => ['Name A to Z', 'name', 'asc'], 'price_hi' => ['Price high to low', 'price', 'desc']];
//   field = a row key or a function(row) => value. Blank values always go last, whichever way it sorts.
//   Optional 4th item: an SQL ORDER BY (for lists capped at "the newest N", so the sort picks which rows show).
// Your pick is remembered on your staff account per list (staff_prefs 'list_sort'), so it sticks on any phone.
//   bcrf_list_sort_pick($pdo, 'products', $opts)       ?sort= (saved when it changes) > your saved pick > the first choice
//   bcrf_list_sort_rows($rows, $opts, $key)            sorts fetched rows (stable: ties keep the list's own order)
//   bcrf_list_sort_sql($opts, $key, $fallback)         the ORDER BY for that choice, or $fallback
//   bcrf_list_sort_select($opts, $key, $attr)          <select name="sort" data-sort-auto> for the list's GET form (admin-ui.js submits on change)
if (!function_exists('bcrf_list_sort_pick')) {
require_once __DIR__ . '/staff_prefs.php';

function bcrf_list_sort_pick(?PDO $pdo, string $list, array $opts): string {
    if (!$opts) return '';
    $first = (string)array_key_first($opts);
    $uid = (int)($_SESSION['user_id'] ?? 0);
    $saved = ($pdo && $uid) ? bcrf_staff_pref_get($pdo, $uid, 'list_sort') : null;
    $saved = is_array($saved) ? $saved : [];
    $want = isset($_GET['sort']) ? (string)$_GET['sort'] : null;
    if ($want !== null && isset($opts[$want])) {
        if ($pdo && $uid && ($saved[$list] ?? $first) !== $want) {
            if ($want === $first) unset($saved[$list]); else $saved[$list] = $want;
            if (count($saved) > 60) $saved = array_slice($saved, -60, null, true);
            try { bcrf_staff_pref_set($pdo, $uid, 'list_sort', $saved ?: null); } catch (Throwable $e) { /* sorting still works for this page */ }
        }
        return $want;
    }
    $s = (string)($saved[$list] ?? '');
    return isset($opts[$s]) ? $s : $first;
}

function bcrf_list_sort_rows(array $rows, array $opts, string $key): array {
    $o = $opts[$key] ?? null;
    if (!$o || !isset($o[1]) || $o[1] === null || $o[1] === '') return $rows;
    $field = $o[1];
    $desc = strtolower((string)($o[2] ?? 'asc')) === 'desc';
    $val = function ($r) use ($field) {
        $v = $field instanceof Closure ? $field($r) : ($r[$field] ?? null);
        if (is_string($v)) $v = trim($v);
        return $v;
    };
    $keyed = [];
    $i = 0;
    foreach ($rows as $k => $r) $keyed[] = [$val($r), $i++, $k, $r];
    usort($keyed, function ($a, $b) use ($desc) {
        $ae = $a[0] === null || $a[0] === ''; $be = $b[0] === null || $b[0] === '';
        if ($ae || $be) return $ae === $be ? $a[1] <=> $b[1] : ($ae ? 1 : -1);   // blanks last
        if (is_numeric($a[0]) && is_numeric($b[0])) $c = (float)$a[0] <=> (float)$b[0];
        else $c = strnatcasecmp((string)$a[0], (string)$b[0]);
        if ($desc) $c = -$c;
        return $c !== 0 ? $c : $a[1] <=> $b[1];
    });
    return array_column($keyed, 3);
}

function bcrf_list_sort_sql(array $opts, string $key, string $fallback): string {
    $s = $opts[$key][3] ?? '';
    return is_string($s) && $s !== '' ? $s : $fallback;
}

function bcrf_list_sort_select(array $opts, string $key, array $attr = []): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $attr += ['aria-label' => 'Sort'];
    $o = '<select name="sort" data-sort-auto';
    foreach ($attr as $k => $v) $o .= ' ' . $h($k) . '="' . $h($v) . '"';
    $o .= '>';
    foreach ($opts as $k => $v) $o .= '<option value="' . $h($k) . '"' . ((string)$k === $key ? ' selected' : '') . '>' . $h($v[0]) . '</option>';
    return $o . '</select>';
}

}
