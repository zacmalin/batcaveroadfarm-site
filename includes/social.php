<?php
// includes/social.php — Facebook/Instagram auto-share foundation.
// Pure composition + queue helpers. The actual posting (cron -> Graph API) is a
// separate layer added once a Meta app + page/IG tokens exist. Everything here is
// function_exists-guarded so it is safe to include anywhere.

if (file_exists(__DIR__ . '/site_info.php')) {
    require_once __DIR__ . '/site_info.php';   // for site() — optional
}

if (!defined('BCRF_SOCIAL_BASE_URL')) {
    define('BCRF_SOCIAL_BASE_URL', 'https://batcaveroadfarm.com');
}

if (!function_exists('social_tables_ready')) {
    function social_tables_ready(PDO $pdo): bool {
        try { $pdo->query("SELECT 1 FROM social_queue LIMIT 1"); return true; }
        catch (Throwable $e) { return false; }
    }
}

if (!function_exists('social_business_name')) {
    function social_business_name(): string {
        $n = function_exists('site') ? trim((string)site('business_name')) : '';
        return $n !== '' ? $n : 'Bat Cave Road Farm';
    }
}

if (!function_exists('social_base_hashtags')) {
    // Default brand/local tags. (Later: make these editable in the Site Info module.)
    function social_base_hashtags(): array {
        return ['#BatCaveRoadFarm', '#OldFortNC', '#FarmToTable'];
    }
}

if (!function_exists('social_tagify')) {
    // "Comfort Food" -> "#ComfortFood"
    function social_tagify(string $label): string {
        $clean = preg_replace('/[^A-Za-z0-9 ]/', '', $label);
        $parts = array_filter(preg_split('/\s+/', trim((string)$clean)));
        $cap   = array_map(fn($w) => ucfirst(strtolower($w)), $parts);
        $j     = implode('', $cap);
        return $j !== '' ? '#' . $j : '';
    }
}

if (!function_exists('social_permalink')) {
    function social_permalink(string $type, array $rec): string {
        if ($type === 'animal') {
            $id   = (int)($rec['id'] ?? 0);
            $slug = strtolower(trim(preg_replace('/[^a-z0-9]+/i', '-', (string)($rec['name'] ?? '')), '-')) ?: 'animal';
            return BCRF_SOCIAL_BASE_URL . '/petting-zoo/animals/' . $id . '/' . $slug . '/';
        }
        $slug = (string)($rec['slug'] ?? '');
        if ($slug === '') return BCRF_SOCIAL_BASE_URL . '/';
        $path = $type === 'recipe' ? '/recipes/' : '/blog/';
        return BCRF_SOCIAL_BASE_URL . $path . rawurlencode($slug) . '/';
    }
}

if (!function_exists('social_excerpt')) {
    // Best available short text: description/excerpt/meta_description/summary, else stripped content.
    function social_excerpt(array $rec, int $len = 180): string {
        foreach (['description', 'excerpt', 'meta_description', 'summary', 'tagline', 'bio', 'personality'] as $k) {
            if (!empty($rec[$k])) {
                $t = trim(strip_tags((string)$rec[$k]));
                if ($t !== '') return mb_strimwidth($t, 0, $len, '…');
            }
        }
        if (!empty($rec['content'])) {
            $t = trim(preg_replace('/\s+/', ' ', strip_tags((string)$rec['content'])));
            if ($t !== '') return mb_strimwidth($t, 0, $len, '…');
        }
        return '';
    }
}

if (!function_exists('social_derive_hashtags')) {
    // Brand base tags + tags derived from the record's cuisine/categories. Deduped, order-preserving.
    function social_derive_hashtags(PDO $pdo, string $type, int $id, array $rec): array {
        $tags = social_base_hashtags();
        try {
            if ($type === 'animal') {
                $tags[] = '#MeetTheAnimals';
                $tags[] = '#FarmAnimals';
                $tags[] = '#BabyAnimals';
                // Breed name as a hashtag if linked
                if (!empty($rec['breed_id'])) {
                    $b = $pdo->prepare("SELECT breed_name FROM breeds WHERE id = ?");
                    $b->execute([(int)$rec['breed_id']]);
                    $bn = (string)$b->fetchColumn();
                    if ($bn !== '') { $t = social_tagify($bn); if ($t) $tags[] = $t; }
                }
            } elseif ($type === 'recipe') {
                $tags[] = '#Recipe';
                if (!empty($rec['cuisine_id'])) {
                    $c = $pdo->prepare("SELECT name FROM cuisines WHERE id = ?");
                    $c->execute([$rec['cuisine_id']]);
                    $cn = (string)$c->fetchColumn();
                    if ($cn !== '') $tags[] = social_tagify($cn);
                }
                $q = $pdo->prepare("SELECT c.category_name FROM categories c
                                    JOIN recipe_categories_junction j ON c.id = j.category_id
                                    WHERE j.recipe_id = ?");
                $q->execute([$id]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cn) {
                    $t = social_tagify((string)$cn); if ($t) $tags[] = $t;
                }
            } else {
                $tags[] = '#FarmBlog';
                $q = $pdo->prepare("SELECT c.category_name FROM categories c
                                    JOIN blog_post_categories j ON c.id = j.category_id
                                    WHERE j.post_id = ?");
                $q->execute([$id]);
                foreach ($q->fetchAll(PDO::FETCH_COLUMN) as $cn) {
                    $t = social_tagify((string)$cn); if ($t) $tags[] = $t;
                }
            }
        } catch (Throwable $e) {
            // Category/cuisine schemas vary — base tags still apply.
        }
        $seen = []; $out = [];
        foreach ($tags as $t) {
            $t = trim($t); if ($t === '') continue;
            $k = strtolower($t); if (isset($seen[$k])) continue;
            $seen[$k] = 1; $out[] = $t;
        }
        return $out;
    }
}

if (!function_exists('social_compose')) {
    // Default, editable caption set for the publish-time review panel.
    // Returns ['fb' => string, 'ig' => string, 'hashtags' => string (space-joined)].
    function social_compose(PDO $pdo, string $type, array $rec): array {
        $id    = (int)($rec['id'] ?? 0);
        $title = trim((string)($rec['title'] ?? ($rec['name'] ?? '')));
        $url   = social_permalink($type, $rec);
        $blurb = social_excerpt($rec);

        if ($type === 'animal') {
            $lead = "🐐 Meet {$title}, the newest member of the Bat Cave Road Farm family!";
            $fb = $lead;
            if ($blurb !== '') $fb .= "\n\n{$blurb}";
            $fb .= "\n\nCome say hi — we're open Tuesday–Sunday, 10AM–4PM.\n\n{$url}";

            $ig = $lead;
            if ($blurb !== '') $ig .= "\n\n{$blurb}";
            $ig .= "\n\nCome say hi — open Tue–Sun, 10AM–4PM. 🔗 Meet the whole crew — link in our bio.";

            $tags = social_derive_hashtags($pdo, $type, $id, $rec);
            return ['fb' => $fb, 'ig' => $ig, 'hashtags' => implode(' ', $tags)];
        }

        $lead  = $type === 'recipe' ? "🍴 New recipe: {$title}" : "📝 New on the journal: {$title}";
        $noun  = $type === 'recipe' ? 'recipe' : 'post';

        // Facebook: the link renders an OG preview card; keep hashtags minimal/none.
        $fb = $lead;
        if ($blurb !== '') $fb .= "\n\n{$blurb}";
        $fb .= "\n\n{$url}";

        // Instagram: no clickable link in feed captions -> "link in bio"; hashtags sit separately.
        $ig = $lead;
        if ($blurb !== '') $ig .= "\n\n{$blurb}";
        $ig .= "\n\n🔗 Full {$noun} — link in our bio.";

        $tags = social_derive_hashtags($pdo, $type, $id, $rec);
        return ['fb' => $fb, 'ig' => $ig, 'hashtags' => implode(' ', $tags)];
    }
}

if (!function_exists('social_enqueue')) {
    // Store the admin-approved post for the cron layer to send later.
    function social_enqueue(PDO $pdo, string $type, int $id, array $payload): bool {
        if (!social_tables_ready($pdo)) return false;
        $st = $pdo->prepare(
            "INSERT INTO social_queue
                (content_type, content_id, platforms, fb_caption, ig_caption, ig_hashtags, status, created_at)
             VALUES (?, ?, ?, ?, ?, ?, 'pending', NOW())"
        );
        return $st->execute([
            $type,
            $id,
            (string)($payload['platforms'] ?? 'facebook,instagram'),
            (string)($payload['fb'] ?? ''),
            (string)($payload['ig'] ?? ''),
            (string)($payload['hashtags'] ?? ''),
        ]);
    }
}
