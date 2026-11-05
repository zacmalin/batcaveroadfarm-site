<?php
// includes/wiki_facts.php
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/farm.php';   // farm website + emails from Settings (Farm OS Phase 0)
//
// "About the breed" facts for animal pages, from Wikipedia + Wikidata (Nov 2026).
// Free for commercial use under CC BY-SA, so the page shows a "Source: Wikipedia" credit.
//
// Lookup order: the breed's "Wikipedia title" field if set (admin → Breeds), else a Wikipedia
// search for "<breed> <animal type>", else the animal type alone (e.g. "Peafowl").
// Facts come from Wikidata when present (scientific name, origin, lifespan, diet); anything
// missing is simply left out.
//
// Results are cached in the wiki_cache table for 30 days (failures for 1 day), so visitors
// never wait on Wikipedia except for the very first view of a breed, and then for at most
// ~4 seconds. If Wikipedia is slow or down, the section is just not shown.

if (!function_exists('bcrf_wiki_facts')) {

define('BCRF_WIKI_UA', ('BatCaveRoadFarm/1.0 (' . bcrf_farm_url() . '; ' . bcrf_farm_email('support') . ')'));
define('BCRF_WIKI_TTL', 30 * 86400);
define('BCRF_WIKI_FAIL_TTL', 86400);

function bcrf_wiki_http(string $url): ?array {
    $body = null;
    if (function_exists('curl_init')) {
        $ch = curl_init($url);
        curl_setopt_array($ch, [CURLOPT_RETURNTRANSFER => true, CURLOPT_TIMEOUT => 4, CURLOPT_CONNECTTIMEOUT => 2,
            CURLOPT_FOLLOWLOCATION => true, CURLOPT_MAXREDIRS => 3, CURLOPT_USERAGENT => BCRF_WIKI_UA,
            CURLOPT_HTTPHEADER => ['Accept: application/json']]);
        $body = curl_exec($ch);
        $code = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);
        if ($body === false || $code !== 200) return null;
    } else {
        $ctx = stream_context_create(['http' => ['timeout' => 4, 'header' => "User-Agent: " . BCRF_WIKI_UA . "\r\nAccept: application/json\r\n"]]);
        $body = @file_get_contents($url, false, $ctx);
        if ($body === false) return null;
    }
    $j = json_decode((string)$body, true);
    return is_array($j) ? $j : null;
}

function bcrf_wiki_cache_get(PDO $pdo, string $key): ?array {
    try {
        $pdo->exec("CREATE TABLE IF NOT EXISTS wiki_cache (
            cache_key  VARCHAR(190) NOT NULL PRIMARY KEY,
            data       MEDIUMTEXT   NULL,
            fetched_at INT          NOT NULL
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        $st = $pdo->prepare("SELECT data, fetched_at FROM wiki_cache WHERE cache_key = ?");
        $st->execute([$key]);
        $r = $st->fetch(PDO::FETCH_ASSOC);
        if (!$r) return null;
        $d = $r['data'] === null ? false : json_decode((string)$r['data'], true);
        $age = time() - (int)$r['fetched_at'];
        if ($d === false && $age < BCRF_WIKI_FAIL_TTL) return ['_none' => true];
        if (is_array($d) && $age < BCRF_WIKI_TTL) return $d;
        return is_array($d) ? $d + ['_stale' => true] : null;   // stale: use it, but refresh
    } catch (Throwable $e) { return null; }
}

function bcrf_wiki_cache_put(PDO $pdo, string $key, ?array $data): void {
    try {
        $pdo->prepare("REPLACE INTO wiki_cache (cache_key, data, fetched_at) VALUES (?, ?, ?)")
            ->execute([$key, $data === null ? null : json_encode($data, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES), time()]);
    } catch (Throwable $e) { error_log('[wiki] cache: ' . $e->getMessage()); }
}

/** Best Wikipedia title for a search, or null. */
function bcrf_wiki_search(string $q): ?string {
    $j = bcrf_wiki_http('https://en.wikipedia.org/w/api.php?action=query&list=search&srlimit=1&format=json&srsearch=' . rawurlencode($q));
    return $j['query']['search'][0]['title'] ?? null;
}

/** First value of a Wikidata claim. */
function bcrf_wd_claim(array $ent, string $p) {
    foreach ($ent['claims'][$p] ?? [] as $c) {
        $v = $c['mainsnak']['datavalue']['value'] ?? null;
        if ($v !== null) return $v;
    }
    return null;
}

/** Fetch summary + facts for one Wikipedia title. */
function bcrf_wiki_fetch_title(string $title): ?array {
    $sum = bcrf_wiki_http('https://en.wikipedia.org/api/rest_v1/page/summary/' . rawurlencode(str_replace(' ', '_', $title)) . '?redirect=true');
    if (!$sum || ($sum['type'] ?? '') !== 'standard' || empty($sum['extract'])) return null;
    $out = [
        'title'   => (string)$sum['title'],
        'extract' => (string)$sum['extract'],
        'url'     => (string)($sum['content_urls']['desktop']['page'] ?? ('https://en.wikipedia.org/wiki/' . rawurlencode($title))),
        'facts'   => [],
    ];
    $qid = (string)($sum['wikibase_item'] ?? '');
    if (preg_match('/^Q\d+$/', $qid)) {
        $wd = bcrf_wiki_http('https://www.wikidata.org/wiki/Special:EntityData/' . $qid . '.json');
        $ent = $wd['entities'][$qid] ?? null;
        if (is_array($ent)) {
            $facts = [];
            $refs = [];   // entity-valued facts to label: [fact label => Q-id list]
            if (is_string($v = bcrf_wd_claim($ent, 'P225'))) $facts['Scientific name'] = $v;            // taxon name
            foreach (['Origin' => 'P495', 'Diet' => 'P1034'] as $label => $p) {                          // country of origin, main food source
                $ids = [];
                foreach (array_slice($ent['claims'][$p] ?? [], 0, 3) as $c) {
                    $id = $c['mainsnak']['datavalue']['value']['id'] ?? null;
                    if ($id) $ids[] = $id;
                }
                if ($ids) $refs[$label] = $ids;
            }
            $life = bcrf_wd_claim($ent, 'P2250');                                                        // life expectancy
            if (is_array($life) && isset($life['amount'])) {
                $n = abs((float)$life['amount']);
                $unitQ = basename((string)($life['unit'] ?? ''));
                $unit = ['Q577' => 'years', 'Q5151' => 'months', 'Q573' => 'days'][$unitQ] ?? 'years';
                if ($n > 0) $facts['Typical lifespan'] = rtrim(rtrim(number_format($n, 1), '0'), '.') . ' ' . $unit;
            }
            if ($refs) {
                $all = array_values(array_unique(array_merge(...array_values($refs))));
                $lab = bcrf_wiki_http('https://www.wikidata.org/w/api.php?action=wbgetentities&props=labels&languages=en&format=json&ids=' . implode('|', $all));
                foreach ($refs as $label => $ids) {
                    $names = [];
                    foreach ($ids as $id) { $n = $lab['entities'][$id]['labels']['en']['value'] ?? null; if ($n) $names[] = $n; }
                    if ($names) $facts[$label] = implode(', ', $names);
                }
            }
            $order = ['Scientific name', 'Origin', 'Typical lifespan', 'Diet'];
            $out['facts'] = array_filter(array_replace(array_flip($order), $facts), 'is_string');
        }
    }
    return $out;
}

/**
 * Facts for an animal. $override = breed's Wikipedia title (optional),
 * $breed / $kind = breed name and animal type (classification).
 */
function bcrf_wiki_facts(PDO $pdo, string $override, string $breed, string $kind): ?array {
    $override = trim($override); $breed = trim($breed); $kind = trim($kind);
    $key = 'animal:' . mb_strtolower($override !== '' ? 't:' . $override : $breed . '|' . $kind);
    if ($key === 'animal:|') return null;
    $cached = bcrf_wiki_cache_get($pdo, $key);
    if ($cached && empty($cached['_stale'])) return !empty($cached['_none']) ? null : $cached;

    $res = null;
    if ($override !== '') {
        $res = bcrf_wiki_fetch_title($override);
    } else {
        foreach (array_filter([$breed !== '' ? trim($breed . ' ' . $kind) : '', $kind]) as $q) {
            $t = bcrf_wiki_search($q);
            if ($t && ($res = bcrf_wiki_fetch_title($t))) break;
        }
    }
    if ($res) { bcrf_wiki_cache_put($pdo, $key, $res); return $res; }
    if ($cached && !empty($cached['_stale'])) { unset($cached['_stale']); return $cached; }   // keep old data if refresh failed
    bcrf_wiki_cache_put($pdo, $key, null);
    return null;
}

/** Short summary: first two sentences, max ~420 chars. */
function bcrf_wiki_short(string $extract): string {
    $parts = preg_split('/(?<=[.!?])\s+(?=[A-Z])/', trim($extract));
    $s = implode(' ', array_slice($parts, 0, 2));
    return mb_strlen($s) > 420 ? mb_substr($s, 0, 417) . '…' : $s;
}

}
