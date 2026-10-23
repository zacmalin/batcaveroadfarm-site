<?php
/**
 * Related cross-link modules for batcaveroadfarm.com.
 *
 * Auto-matches related recipes / farm-stand products / journal posts by
 * keyword overlap (title + category names), with a graceful fall back to the
 * newest items so a block is never empty. Every DB call is wrapped in try/catch
 * so a related block can never take down the host page.
 *
 * Requires: $pdo (PDO) and createSlug() (from config.php).
 */

if (!function_exists('bcrf_rel_keywords')) {
    function bcrf_rel_keywords(string $text, array $extra = []): array
    {
        static $stop = [
            'the','and','for','with','from','your','our','that','this','have','has','had',
            'are','was','were','will','you','about','into','out','how','why','what','when',
            'where','all','any','can','not','but','its','their','there','here','then','than',
            'some','more','most','they','them','his','her','she','him','too','get','got','new',
            'farm','bat','cave','road', // exclude the brand words so they don't over-match everything
        ];
        $blob  = strtolower($text . ' ' . implode(' ', $extra));
        $words = preg_split('/[^a-z0-9]+/', $blob, -1, PREG_SPLIT_NO_EMPTY);
        $kw = [];
        foreach ($words as $w) {
            if (strlen($w) < 3) continue;
            if (in_array($w, $stop, true)) continue;
            $kw[$w] = true;
        }
        return array_slice(array_keys($kw), 0, 8); // cap to keep queries small
    }
}

if (!function_exists('bcrf_rel_abs')) {
    function bcrf_rel_abs(string $v): string
    {
        $v = trim($v);
        if ($v === '') return '';
        if (preg_match('#^https?://#i', $v)) return $v;
        return 'https://batcaveroadfarm.com' . ($v[0] === '/' ? $v : '/' . $v);
    }
}

if (!function_exists('bcrf_rel_img')) {
    function bcrf_rel_img(array $row): string
    {
        foreach (['featured_image','feature_image','image_url','image','photo_url','photo'] as $k) {
            if (!empty($row[$k])) return bcrf_rel_abs((string)$row[$k]);
        }
        return '';
    }
}

if (!function_exists('bcrf_rel_score')) {
    function bcrf_rel_score(string $hay, array $kw): int
    {
        $h = strtolower($hay);
        $s = 0;
        foreach ($kw as $w) {
            if (strpos($h, $w) !== false) $s++;
        }
        return $s;
    }
}

if (!function_exists('bcrf_rel_like')) {
    function bcrf_rel_like(array $kw, array $cols): array
    {
        $clauses = [];
        $params  = [];
        foreach ($kw as $w) {
            $sub = [];
            foreach ($cols as $col) {
                $sub[]    = "$col LIKE ?";
                $params[] = '%' . $w . '%';
            }
            $clauses[] = '(' . implode(' OR ', $sub) . ')';
        }
        return [$clauses ? '(' . implode(' OR ', $clauses) . ')' : '1=0', $params];
    }
}

if (!function_exists('bcrf_related_recipes')) {
    function bcrf_related_recipes(PDO $pdo, array $kw, int $limit = 2, array $excludeSlugs = []): array
    {
        $seen   = array_flip($excludeSlugs);
        $picked = [];
        try {
            [$where, $params] = bcrf_rel_like($kw, ['r.title', 'r.description', 'c.name']);
            $st = $pdo->prepare("SELECT r.*, c.name AS cuisine
                                 FROM recipes r LEFT JOIN cuisines c ON r.cuisine_id = c.id
                                 WHERE r.status = 'published' AND ($where) LIMIT 25");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['__s'] = bcrf_rel_score(($r['title'] ?? '') . ' ' . ($r['description'] ?? '') . ' ' . ($r['cuisine'] ?? ''), $kw);
            }
            unset($r);
            usort($rows, fn($a, $b) => ($b['__s'] <=> $a['__s']));
            foreach ($rows as $r) {
                if (count($picked) >= $limit) break;
                $slug = (string)($r['slug'] ?? '');
                if ($slug === '' || isset($seen[$slug])) continue;
                $seen[$slug] = true;
                $picked[] = ['url' => '/recipes/' . urlencode($slug) . '/', 'title' => (string)$r['title'], 'img' => bcrf_rel_img($r)];
            }
            if (count($picked) < $limit) {
                $st2 = $pdo->query("SELECT * FROM recipes ORDER BY id DESC LIMIT 12");
                foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) {
                    if (count($picked) >= $limit) break;
                    $slug = (string)($r['slug'] ?? '');
                    if ($slug === '' || isset($seen[$slug])) continue;
                    $seen[$slug] = true;
                    $picked[] = ['url' => '/recipes/' . urlencode($slug) . '/', 'title' => (string)$r['title'], 'img' => bcrf_rel_img($r)];
                }
            }
        } catch (Throwable $e) { /* fail silent */ }
        return $picked;
    }
}

if (!function_exists('bcrf_related_products')) {
    function bcrf_related_products(PDO $pdo, array $kw, int $limit = 1): array
    {
        $catFilter = "(LOWER(ic.slug) IN ('produce','bakery','baked-goods','pantry') OR LOWER(ic.name) IN ('produce','bakery','baked goods','pantry'))";
        $base      = "FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id
                      WHERE i.is_active = 1 AND i.stock_quantity > 0 AND $catFilter";
        $picked = [];
        $seen   = [];
        $add = function (array $r) use (&$picked, &$seen, $limit) {
            if (count($picked) >= $limit) return;
            $id = (int)($r['id'] ?? 0);
            if ($id <= 0 || isset($seen[$id])) return;
            $seen[$id] = true;
            $nm   = (string)($r['name'] ?? '');
            $pr   = (float)($r['price'] ?? 0);
            $picked[] = [
                'url'   => '/farm-stand/items/' . $id . '/' . (createSlug($nm) ?: 'item') . '/',
                'title' => $nm,
                'img'   => bcrf_rel_img($r),
                'meta'  => $pr > 0 ? ('$' . number_format($pr, 2)) : '',
            ];
        };
        try {
            [$where, $params] = bcrf_rel_like($kw, ['i.name', 'i.description']);
            $st = $pdo->prepare("SELECT i.* $base AND $where LIMIT 20");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) {
                $r['__s'] = bcrf_rel_score(($r['name'] ?? '') . ' ' . ($r['description'] ?? ''), $kw);
            }
            unset($r);
            usort($rows, fn($a, $b) => ($b['__s'] <=> $a['__s']));
            foreach ($rows as $r) { $add($r); }
            if (count($picked) < $limit) {
                $st2 = $pdo->query("SELECT i.* $base ORDER BY i.id DESC LIMIT 8");
                foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) { $add($r); }
            }
        } catch (Throwable $e) { /* fail silent */ }
        return $picked;
    }
}

if (!function_exists('bcrf_related_posts')) {
    function bcrf_related_posts(PDO $pdo, array $kw, int $limit = 2, array $excludeSlugs = []): array
    {
        $seen   = array_flip($excludeSlugs);
        $picked = [];
        $add = function (array $r) use (&$picked, &$seen, $limit) {
            if (count($picked) >= $limit) return;
            $slug = (string)($r['slug'] ?? '');
            if ($slug === '' || isset($seen[$slug])) return;
            $seen[$slug] = true;
            $picked[] = ['url' => '/blog/' . urlencode($slug) . '/', 'title' => (string)($r['title'] ?? ''), 'img' => bcrf_rel_img($r)];
        };
        try {
            [$where, $params] = bcrf_rel_like($kw, ['bp.title']);
            $st = $pdo->prepare("SELECT bp.* FROM blog_posts bp WHERE bp.status = 'published' AND $where LIMIT 20");
            $st->execute($params);
            $rows = $st->fetchAll(PDO::FETCH_ASSOC) ?: [];
            foreach ($rows as &$r) { $r['__s'] = bcrf_rel_score((string)($r['title'] ?? ''), $kw); }
            unset($r);
            usort($rows, fn($a, $b) => ($b['__s'] <=> $a['__s']));
            foreach ($rows as $r) { $add($r); }
            if (count($picked) < $limit) {
                $st2 = $pdo->query("SELECT * FROM blog_posts WHERE status = 'published' ORDER BY id DESC LIMIT 8");
                foreach (($st2->fetchAll(PDO::FETCH_ASSOC) ?: []) as $r) { $add($r); }
            }
        } catch (Throwable $e) { /* fail silent */ }
        return $picked;
    }
}

if (!function_exists('bcrf_visit_card')) {
    function bcrf_visit_card(): array
    {
        return [
            'url'   => '/plan-your-visit/',
            'title' => 'Plan your visit',
            'img'   => '',
            'kind'  => 'Visit us',
            'meta'  => (function_exists('site_hours_compact') && site_hours_compact('general') !== '') ? 'Open ' . site_hours_compact('general') : 'Open Tue–Sun, 10AM–4PM',
        ];
    }
}

if (!function_exists('bcrf_related_render')) {
    function bcrf_related_render(string $heading, array $cards): string
    {
        $cards = array_values(array_filter($cards, fn($c) => !empty($c['url']) && !empty($c['title'])));
        if (!$cards) return '';
        $h  = '<section class="related" aria-label="' . htmlspecialchars($heading) . '">';
        $h .= '<h2 class="related-title">' . htmlspecialchars($heading) . '</h2>';
        $h .= '<div class="related-grid">';
        foreach ($cards as $c) {
            $img  = (string)($c['img'] ?? '');
            $kind = (string)($c['kind'] ?? '');
            $meta = (string)($c['meta'] ?? '');
            $h .= '<a class="related-card' . ($img === '' ? ' related-card--noimg' : '') . '" href="' . htmlspecialchars($c['url']) . '">';
            if ($img !== '') {
                $h .= '<span class="related-thumb"><img src="' . htmlspecialchars($img) . '" alt="' . htmlspecialchars($c['title']) . '" loading="lazy" decoding="async"></span>';
            }
            $h .= '<span class="related-body">';
            if ($kind !== '') $h .= '<span class="related-kind">' . htmlspecialchars($kind) . '</span>';
            $h .= '<span class="related-name">' . htmlspecialchars($c['title']) . '</span>';
            if ($meta !== '') $h .= '<span class="related-meta">' . htmlspecialchars($meta) . '</span>';
            $h .= '</span></a>';
        }
        $h .= '</div></section>';
        return $h;
    }
}

if (!function_exists('bcrf_visit_cta_html')) {
    function bcrf_visit_cta_html(string $subject = ''): string
    {
        $line = $subject !== ''
            ? 'Come meet ' . htmlspecialchars($subject) . ' in person!'
            : 'Come see the animals in person!';
        return '<section class="visit-cta" aria-label="Plan your visit">'
             . '<div class="visit-cta-inner">'
             . '<p class="visit-cta-lead">' . $line . '</p>'
             . '<p class="visit-cta-sub">We\'re open ' . ((function_exists('site_hours_compact') && site_hours_compact('general') !== '') ? site_hours_compact('general') : 'Tue–Sun, 10AM–4PM') . ' in Old Fort, NC.</p>'
             . '<a class="cta-btn" href="/plan-your-visit/">Plan your visit →</a>'
             . '</div></section>';
    }
}
