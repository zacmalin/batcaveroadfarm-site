<?php
// Dynamic XML sitemap with image extensions — batcaveroadfarm.com.
// Merges image data into standard sitemap entries so Google discovers
// farm photos without needing a separate sitemap file.
require_once __DIR__ . '/config.php';
header('Content-Type: application/xml; charset=utf-8');
header('Cache-Control: public, max-age=3600');

$base  = 'https://batcaveroadfarm.com';
$today = date('Y-m-d');
$urls  = [];

function addUrl(&$urls, $loc, $lastmod, $freq, $prio, $images = []) {
    $urls[] = ['loc' => $loc, 'lastmod' => $lastmod, 'freq' => $freq, 'prio' => $prio, 'images' => $images];
}
function sitemapImg(string $base, string $loc, string $title, string $caption = ''): array {
    $full = (strpos($loc, 'http') === 0) ? $loc : $base . '/' . ltrim($loc, '/');
    return ['loc' => $full, 'title' => $title, 'caption' => $caption];
}

// ── Static pages with hero images ────────────────────────────────────────────
$static = [
    ['/', 'weekly', '1.0', [sitemapImg($base, '/img/og-home.jpg', 'Bat Cave Road Farm — Petting Zoo, Farmstand &amp; Thrift Shop in Old Fort, NC')]],
    ['/petting-zoo/', 'monthly', '0.9', [sitemapImg($base, '/img/og-pettingzoo.jpg', 'Petting Zoo at Bat Cave Road Farm — Goats, Donkeys, Pigs &amp; More in Old Fort, NC')]],
    ['/farm-stand/', 'weekly', '0.9', []],
    ['/thrift-shop/', 'weekly', '0.8', []],
    ['/thrift-shop/items/', 'weekly', '0.6', []],
    ['/shop/', 'weekly', '0.7', []],
    ['/recipes/', 'weekly', '0.8', []],
    ['/blog/', 'weekly', '0.7', []],
    ['/gallery/', 'monthly', '0.7', []],
    ['/about/', 'yearly', '0.6', []],
    ['/contact-us/', 'yearly', '0.6', []],
    ['/faq/', 'monthly', '0.5', []],
    ['/plan-your-visit/', 'monthly', '0.8', [sitemapImg($base, '/img/og-home.jpg', 'Plan Your Visit to Bat Cave Road Farm in Old Fort, NC')]],
    ['/directions/', 'yearly', '0.7', []],
    ['/booking/', 'monthly', '0.8', []],
    ['/tickets/', 'monthly', '0.8', [sitemapImg($base, '/img/og-pettingzoo.jpg', 'Buy Day Admission Tickets — Bat Cave Road Farm, Old Fort, NC')]],
    ['/private-events/', 'monthly', '0.8', []],
    ['/passes/', 'monthly', '0.9', [sitemapImg($base, '/img/og-pettingzoo.jpg', 'Annual Farm Passes — Bat Cave Road Farm, Old Fort, NC')]],
    ['/events/', 'weekly', '0.7', []],
    ['/privacy/', 'yearly', '0.3', []],
    ['/terms/', 'yearly', '0.3', []],
    ['/accessibility/', 'yearly', '0.3', []],
];
foreach ($static as $sp) {
    addUrl($urls, $base . $sp[0], $today, $sp[1], $sp[2], $sp[3]);
}

// ── Dynamic pages ─────────────────────────────────────────────────────────────
// Each section has its own try/catch so one failing query can't drop the rest.
try {
    // Recipes (with featured image)
    foreach ($pdo->query("SELECT slug, date_published, title, featured_image FROM recipes WHERE status = 'published' AND slug IS NOT NULL AND slug <> ''") as $r) {
        $lm = (!empty($r['date_published']) && $r['date_published'] !== '0000-00-00') ? $r['date_published'] : $today;
        $imgs = [];
        if (!empty($r['featured_image'])) {
            $imgs[] = sitemapImg($base, $r['featured_image'], htmlspecialchars($r['title'], ENT_XML1) . ' — Farmhouse Recipe from Bat Cave Road Farm');
        }
        addUrl($urls, $base . '/recipes/' . rawurlencode($r['slug']) . '/', $lm, 'monthly', '0.7', $imgs);
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Recipes (with featured image)]: ' . $e->getMessage());
}
try {
    // Blog posts
    foreach ($pdo->query("SELECT slug, date_published FROM blog_posts WHERE status = 'published' AND slug IS NOT NULL AND slug <> ''") as $b) {
        $lm = !empty($b['date_published']) ? date('Y-m-d', strtotime($b['date_published'])) : $today;
        addUrl($urls, $base . '/blog/' . rawurlencode($b['slug']) . '/', $lm, 'monthly', '0.6');
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Blog posts]: ' . $e->getMessage());
}
try {
    // Farm-stand products
    foreach ($pdo->query("SELECT i.id, i.name, i.image_url FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id WHERE i.is_active = 1 AND i.stock_quantity > 0 AND (LOWER(ic.slug) IN ('produce','bakery','baked-goods','pantry') OR LOWER(ic.name) IN ('produce','bakery','baked goods','pantry'))") as $row) {
        $imgs = [];
        if (!empty($row['image_url'])) {
            $imgs[] = sitemapImg($base, $row['image_url'], htmlspecialchars($row['name'], ENT_XML1) . ' — Bat Cave Road Farm Farmstand');
        }
        addUrl($urls, $base . '/farm-stand/items/' . (int)$row['id'] . '/' . (createSlug($row['name']) ?: 'item') . '/', $today, 'weekly', '0.5', $imgs);
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Farm-stand products]: ' . $e->getMessage());
}
try {
    // Merchandise
    foreach ($pdo->query("SELECT i.id, i.name, i.image_url FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id WHERE i.is_active = 1 AND i.stock_quantity > 0 AND (LOWER(ic.slug) IN ('merchandise','merch') OR LOWER(ic.name) IN ('merchandise','merch'))") as $row) {
        $imgs = [];
        if (!empty($row['image_url'])) {
            $imgs[] = sitemapImg($base, $row['image_url'], htmlspecialchars($row['name'], ENT_XML1) . ' — Bat Cave Road Farm Shop');
        }
        addUrl($urls, $base . '/farm-stand/items/' . (int)$row['id'] . '/' . (createSlug($row['name']) ?: 'item') . '/', $today, 'weekly', '0.6', $imgs);
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Merchandise]: ' . $e->getMessage());
}
try {
    // Animals (with profile photo)
    foreach ($pdo->query("SELECT id, name, photo_url AS image_url, tagline FROM animals WHERE is_active = 1") as $a) {
        $imgs = [];
        if (!empty($a['image_url'])) {
            $cap = !empty($a['tagline']) ? htmlspecialchars($a['tagline'], ENT_XML1) : '';
            $imgs[] = sitemapImg($base, $a['image_url'], htmlspecialchars($a['name'], ENT_XML1) . ' — Bat Cave Road Farm Petting Zoo in Old Fort, NC', $cap);
        }
        addUrl($urls, $base . '/petting-zoo/animals/' . (int)$a['id'] . '/' . createSlug((string)$a['name']) . '/', $today, 'monthly', '0.6', $imgs);
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Animals (with profile photo)]: ' . $e->getMessage());
}
try {
    // Breeds
    foreach ($pdo->query("SELECT id, slug, breed_name FROM breeds WHERE is_active = 1") as $b) {
        $bslug = trim((string)($b['slug'] ?? '')) !== '' ? $b['slug'] : createSlug((string)$b['breed_name']);
        addUrl($urls, $base . '/petting-zoo/breeds/' . (int)$b['id'] . '/' . $bslug . '/', $today, 'monthly', '0.6');
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Breeds]: ' . $e->getMessage());
}
try {
    // Thrift items
    foreach ($pdo->query("SELECT i.id, i.name FROM inventory i JOIN inventory_categories ic ON i.category_id = ic.id WHERE i.is_active = 1 AND i.stock_quantity > 0 AND (ic.slug = 'thrift' OR LOWER(ic.name) = 'thrift')") as $row) {
        addUrl($urls, $base . '/thrift-shop/items/' . (int)$row['id'] . '/' . (createSlug($row['name']) ?: 'item') . '/', $today, 'weekly', '0.4');
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Thrift items]: ' . $e->getMessage());
}
try {
    // Jobs (pack P3): listing only when hiring, plus each job taking applications
    require_once __DIR__ . '/includes/jobs/repo.php';
    $openJobs = jobs_public_open($pdo);
    if ($openJobs) {
        addUrl($urls, $base . '/jobs/', $today, 'weekly', '0.6');
        foreach ($openJobs as $j) {
            addUrl($urls, $base . '/jobs/' . rawurlencode($j['slug']) . '/', substr((string)$j['opened_at'], 0, 10), 'weekly', '0.6');
        }
    }
} catch (Throwable $e) {
    error_log('sitemap.php [Jobs]: ' . $e->getMessage());
}

// ── XML output ────────────────────────────────────────────────────────────────
echo '<?xml version="1.0" encoding="UTF-8"?>' . "\n";
echo '<urlset xmlns="http://www.sitemaps.org/schemas/sitemap/0.9"' . "\n";
echo '        xmlns:image="http://www.google.com/schemas/sitemap-image/1.1">' . "\n";
foreach ($urls as $u) {
    echo "  <url>\n";
    echo "    <loc>" . htmlspecialchars($u['loc'], ENT_XML1) . "</loc>\n";
    echo "    <lastmod>" . htmlspecialchars($u['lastmod'], ENT_XML1) . "</lastmod>\n";
    echo "    <changefreq>" . $u['freq'] . "</changefreq>\n";
    echo "    <priority>" . $u['prio'] . "</priority>\n";
    foreach ($u['images'] ?? [] as $img) {
        echo "    <image:image>\n";
        echo "      <image:loc>" . htmlspecialchars($img['loc'], ENT_XML1) . "</image:loc>\n";
        if (!empty($img['title'])) {
            echo "      <image:title>" . $img['title'] . "</image:title>\n";
        }
        if (!empty($img['caption'])) {
            echo "      <image:caption>" . $img['caption'] . "</image:caption>\n";
        }
        echo "    </image:image>\n";
    }
    echo "  </url>\n";
}
echo "</urlset>\n";
