<?php
// includes/pager.php
// Shared pagination row for paginated listings (recipes, blog, …).
// Expects in the including scope:
//   int      $page        current page (1-based)
//   int      $totalPages  total number of pages
//   callable $pagerUrl    fn(int $p): string — builds the URL for page $p
//   string   $pagerClass  (optional) page-specific class, e.g. 'recipe-pager'
//   string   $pagerLabel  (optional) <nav> aria-label
// Renders nothing unless there is more than one page. Background/sticky styling
// is neutralized by the .site-pager rule in min.style.css (the global nav{} rule
// would otherwise bleed the header's wood texture + gold border into this row).

if (!isset($page, $totalPages) || !is_callable($pagerUrl ?? null)) return;

$__pgr_page  = max(1, (int)$page);
$__pgr_total = (int)$totalPages;
if ($__pgr_total <= 1) return;
if ($__pgr_page > $__pgr_total) $__pgr_page = $__pgr_total;

$__pgr_class = isset($pagerClass) ? trim('site-pager ' . $pagerClass) : 'site-pager';
$__pgr_label = $pagerLabel ?? 'Pagination';

// Visible number window: always first, last, and current ±1; gaps collapse to "…".
$__pgr_win  = 1;
$__pgr_nums = [];
for ($__pgr_i = 1; $__pgr_i <= $__pgr_total; $__pgr_i++) {
    if ($__pgr_i === 1 || $__pgr_i === $__pgr_total
        || ($__pgr_i >= $__pgr_page - $__pgr_win && $__pgr_i <= $__pgr_page + $__pgr_win)) {
        $__pgr_nums[] = $__pgr_i;
    }
}
$__pgr_items = [];
$__pgr_prev  = 0;
foreach ($__pgr_nums as $__pgr_n) {
    if ($__pgr_n - $__pgr_prev > 1) $__pgr_items[] = 'gap';
    $__pgr_items[] = $__pgr_n;
    $__pgr_prev = $__pgr_n;
}
?>
  <nav class="<?= htmlspecialchars($__pgr_class) ?>" aria-label="<?= htmlspecialchars($__pgr_label) ?>">
    <?php if ($__pgr_page > 1): ?>
      <a class="pager-prev" rel="prev" href="<?= htmlspecialchars($pagerUrl($__pgr_page - 1)) ?>" aria-label="Previous page">&lsaquo; Prev</a>
    <?php endif; ?>
    <?php foreach ($__pgr_items as $__pgr_it): ?>
      <?php if ($__pgr_it === 'gap'): ?>
        <span class="pager-gap" aria-hidden="true">&hellip;</span>
      <?php else: ?>
        <a href="<?= htmlspecialchars($pagerUrl($__pgr_it)) ?>" class="<?= $__pgr_page === $__pgr_it ? 'active' : '' ?>"<?= $__pgr_page === $__pgr_it ? ' aria-current="page"' : '' ?>><?= $__pgr_it ?></a>
      <?php endif; ?>
    <?php endforeach; ?>
    <?php if ($__pgr_page < $__pgr_total): ?>
      <a class="pager-next" rel="next" href="<?= htmlspecialchars($pagerUrl($__pgr_page + 1)) ?>" aria-label="Next page">Next &rsaquo;</a>
    <?php endif; ?>
  </nav>
