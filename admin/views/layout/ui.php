<?php
// admin/views/layout/ui.php
// Shared UI kit — 02 Navigation & UX (Nov 5, 2026).
// One set of colors, icons and wording for status in every module, plus money, buttons and empty states.
// Use: require_once __DIR__ . '/layout/ui.php';  (from admin/views/*.php)

if (!function_exists('ui_chip')) {

/** Status vocabulary: key => [classes, icon, label]. Add new statuses here, not in views. */
function ui_chip_map(): array {
    return [
        // Waiting on us / in progress
        'new'        => ['bg-sky-100 text-sky-800',         '🆕', 'New'],
        'pending'    => ['bg-amber-100 text-amber-800',     '⏳', 'Pending'],
        'contacted'  => ['bg-amber-100 text-amber-800',     '📞', 'Contacted'],
        'quoted'     => ['bg-violet-100 text-violet-800',   '💰', 'Quoted'],
        // Good outcomes
        'confirmed'  => ['bg-emerald-100 text-emerald-800', '✅', 'Confirmed'],
        'completed'  => ['bg-stone-200 text-stone-700',     '✔️', 'Completed'],
        'paid'       => ['bg-emerald-100 text-emerald-800', '💳', 'Paid'],
        'waived'     => ['bg-stone-100 text-stone-600',     '💵', 'Paid in person'],
        'ok'         => ['bg-emerald-100 text-emerald-800', '',   'OK'],
        // Bad outcomes / needs attention
        'unpaid'     => ['bg-amber-100 text-amber-800',     '⏳', 'Unpaid'],
        'low'        => ['bg-red-100 text-red-700',         '',   'Low'],
        'no_show'    => ['bg-red-100 text-red-700',         '🚫', 'No-show'],
        'declined'   => ['bg-red-100 text-red-700',         '❌', 'Declined'],
        'refunded'   => ['bg-red-100 text-red-700',         '↩️', 'Refunded'],
        // Closed
        'cancelled'  => ['bg-stone-100 text-stone-500 line-through', '', 'Cancelled'],
        'archived'   => ['bg-stone-100 text-stone-500',     '📁', 'Archived'],
        // Publishing (blog posts, recipes, animals)
        'published'  => ['bg-emerald-100 text-emerald-800', '🟢', 'Live'],
        'draft'      => ['bg-yellow-100 text-yellow-800',   '🟡', 'Draft'],
        // Flags
        'private'    => ['bg-red-100 text-red-700',         '🔒', 'Private'],
    ];
}

/** Status chip. $label overrides the standard wording (keep that rare). */
function ui_chip(string $status, string $label = ''): string {
    [$cls, $icon, $text] = ui_chip_map()[$status] ?? ['bg-stone-100 text-stone-600', '', ucfirst(str_replace('_', ' ', $status))];
    if ($label !== '') $text = $label;
    return '<span class="ui-chip ' . $cls . '">' . ($icon !== '' ? '<span aria-hidden="true">' . $icon . '</span>' : '')
         . htmlspecialchars($text, ENT_QUOTES, 'UTF-8') . '</span>';
}

/** Money: always "$1,234.50" (negative as "-$5.00"). */
function ui_money($n): string {
    $n = (float)$n;
    return ($n < 0 ? '-' : '') . '$' . number_format(abs($n), 2);
}

/** Button classes. Thumb-sized on phones, compact on desktop. */
function ui_btn(string $kind = 'secondary'): string {
    $base = 'inline-flex items-center justify-center gap-1 min-h-10 md:min-h-8 px-3 rounded-lg text-xs font-bold transition disabled:opacity-40';
    return $base . ' ' . ([
        'primary'   => 'bg-stone-900 text-white hover:bg-stone-700',
        'success'   => 'bg-emerald-600 text-white hover:bg-emerald-700',
        'danger'    => 'bg-red-600 text-white hover:bg-red-700',
        'danger-soft' => 'bg-red-50 text-red-700 hover:bg-red-100',
        'accent'    => 'bg-amber-600 text-white hover:bg-amber-700',
        'quiet'     => 'text-stone-500 hover:bg-stone-100 hover:text-stone-800',
        'secondary' => 'bg-stone-100 text-stone-700 hover:bg-stone-200',
    ][$kind] ?? 'bg-stone-100 text-stone-700 hover:bg-stone-200');
}

/** Empty state: what the screen is for + the first action. $action = [label, href] or null. */
function ui_empty(string $icon, string $title, string $text = '', ?array $action = null): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
    return '<div class="bg-white border border-stone-200 rounded-xl p-8 text-center">'
         . '<div class="text-3xl mb-2" aria-hidden="true">' . $icon . '</div>'
         . '<div class="text-sm font-bold text-stone-700">' . $h($title) . '</div>'
         . ($text !== '' ? '<p class="text-xs text-stone-500 mt-1 max-w-sm mx-auto">' . $h($text) . '</p>' : '')
         . ($action ? '<a href="' . $h($action[1]) . '" class="' . ui_btn('primary') . ' mt-4">' . $h($action[0]) . '</a>' : '')
         . '</div>';
}

}
