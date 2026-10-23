<?php
/**
 * includes/site_info.php — "Site Info" accessor (single source of truth).
 *
 * Centralizes business address, phones, emails, hours, and the season toggle.
 * Loads every table ONCE per request into an in-process static cache, then
 * resolves "same as general" inheritance at read time — so changing a general
 * value instantly updates every inheriting slot with no re-save.
 *
 * Public API:
 *   site('email.general'), site('phone.booking')   -> contact value (inheritance-resolved)
 *   site('business_name'), site('timezone')        -> scalar setting (keys without a dot)
 *   site_setting($key, $default='')                -> explicit setting getter
 *   site_address()      -> "4132 Bat Cave Road, Old Fort, NC 28762"
 *   site_address(true)  -> ['street'=>..,'city'=>..,'state'=>..,'zip'=>..,'country'=>..]
 *   site_season()       -> 'open' | 'closed'
 *   site_hours($scope)  -> [0..6 => ['closed'=>bool, 'blocks'=>[['open'=>'HH:MM:SS','close'=>'HH:MM:SS'], ...]]]
 *   site_open_status($scope='general') -> ['open'=>bool, 'label'=>string, 'detail'=>string]
 *   site_is_open($scope='general')     -> bool
 *
 * Admin / raw (no inheritance resolution):
 *   site_all_settings(), site_all_contacts(), site_all_scopes(), site_hours_raw($scope)
 *
 * Requires a connected $pdo (config.php) in scope. If the tables are missing or
 * the DB errors, every accessor degrades to a safe empty/default value.
 */

if (!function_exists('_site_data')) {

/** Load + cache all Site Info rows once per request. */
function _site_data(): array {
    static $d = null;
    if ($d !== null) return $d;
    $d = ['settings' => [], 'contacts' => [], 'scopes' => [], 'hours' => [], 'socials' => [], 'ok' => false];

    global $pdo;
    if (!($pdo instanceof PDO)) return $d;

    try {
        foreach ($pdo->query("SELECT setting_key, setting_value FROM site_settings")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d['settings'][$r['setting_key']] = $r['setting_value'];
        }
        foreach ($pdo->query("SELECT id, contact_type, slot, label, value, inherit_general, sort_order, is_active FROM site_contacts WHERE is_active = 1 ORDER BY contact_type, sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d['contacts'][$r['contact_type']][$r['slot']] = $r;
        }
        foreach ($pdo->query("SELECT scope, label, inherit_general, sort_order, is_active FROM site_hours_scopes WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d['scopes'][$r['scope']] = $r;
        }
        foreach ($pdo->query("SELECT scope, day_of_week, block_order, open_time, close_time, is_closed FROM site_hours ORDER BY scope, day_of_week, block_order")->fetchAll(PDO::FETCH_ASSOC) as $r) {
            $d['hours'][$r['scope']][(int)$r['day_of_week']][] = $r;
        }
        // socials — separate guard so a not-yet-migrated table can't blank everything else
        try {
            foreach ($pdo->query("SELECT id, name, image, url, sort_order, is_active FROM site_socials WHERE is_active = 1 ORDER BY sort_order, id")->fetchAll(PDO::FETCH_ASSOC) as $r) {
                $d['socials'][] = $r;
            }
        } catch (Throwable $eSoc) { /* site_socials not created yet */ }
        $d['ok'] = true;
    } catch (Throwable $e) {
        error_log('[BCRF site_info] load failed: ' . $e->getMessage());
    }
    return $d;
}

/** Scalar setting (business_name, address_*, timezone, season_status, ...). */
function site_setting(string $key, string $default = ''): string {
    $d = _site_data();
    return (isset($d['settings'][$key]) && $d['settings'][$key] !== null && $d['settings'][$key] !== '')
        ? (string)$d['settings'][$key] : $default;
}

/** Resolved contact value for a (type, slot), following "same as general". */
function site_contact(string $type, string $slot): string {
    $d = _site_data();
    $row = $d['contacts'][$type][$slot] ?? null;
    if (!$row) return '';
    if ((int)$row['inherit_general'] === 1) {
        $gen = $d['contacts'][$type]['general'] ?? null;
        return $gen ? (string)$gen['value'] : '';
    }
    return (string)$row['value'];
}

/** Generic getter: "type.slot" -> contact; otherwise a scalar setting. */
function site(string $key, string $default = ''): string {
    if (strpos($key, '.') !== false) {
        [$type, $slot] = explode('.', $key, 2);
        $v = site_contact($type, $slot);
        return $v !== '' ? $v : $default;
    }
    return site_setting($key, $default);
}

/** One-line address string, or the parts array when $parts = true. */
function site_address(bool $parts = false) {
    $p = [
        'street'  => site_setting('address_street'),
        'city'    => site_setting('address_city'),
        'state'   => site_setting('address_state'),
        'zip'     => site_setting('address_zip'),
        'country' => site_setting('address_country', 'US'),
    ];
    if ($parts) return $p;
    $cityline = trim($p['city'] . (($p['city'] && $p['state']) ? ', ' : '') . $p['state'] . ' ' . $p['zip']);
    return trim($p['street'] . (($p['street'] && $cityline) ? ', ' : '') . $cityline);
}

/** Master season toggle. */
function site_season(): string {
    return site_setting('season_status', 'open') === 'closed' ? 'closed' : 'open';
}

/** Geo coordinates as ['lat'=>string,'lng'=>string] (empty strings if unset). */
function site_geo(): array {
    return ['lat' => site_setting('geo_lat'), 'lng' => site_setting('geo_lng')];
}

/** Active social links (ordered): each ['id','name','image','url','sort_order','is_active']. */
function site_socials(): array {
    return _site_data()['socials'];
}

/** Phone in E.164-ish form for tel: links / schema telephone (e.g. +18282165488). */
function site_phone_e164(string $slot = 'general'): string {
    $digits = preg_replace('/\D+/', '', site('phone.' . $slot));
    if ($digits === '') return '';
    if (strlen($digits) === 10) $digits = '1' . $digits;
    return '+' . $digits;
}

/** Human-readable weekly hours summary for a scope.
 *  Returns ['groups'=>[['days'=>..,'time'=>..]], 'open_days','open_time','closed_phrase','sentence'].
 *  Days are grouped Monday-first; a contiguous open run renders as "Tuesday – Sunday". */
function site_hours_summary(string $scope = 'general'): array {
    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $order = [1, 2, 3, 4, 5, 6, 0]; // Monday-first week order
    $week  = site_hours($scope);

    $groups = [];  // timeSig => [day indices]
    $closed = [];
    foreach ($order as $d) {
        if ($week[$d]['closed'] || empty($week[$d]['blocks'])) { $closed[] = $d; continue; }
        $sig = implode(' & ', array_map(fn($b) => _site_fmt_time($b['open']) . ' – ' . _site_fmt_time($b['close']), $week[$d]['blocks']));
        $groups[$sig][] = $d;
    }

    $label = function (array $days) use ($names, $order) {
        $pos = array_map(fn($d) => array_search($d, $order), $days);
        sort($pos);
        if (count($pos) === 1) return $names[$order[$pos[0]]];
        $contig = ($pos === range($pos[0], $pos[count($pos) - 1]));
        if ($contig) return $names[$order[$pos[0]]] . ' – ' . $names[$order[$pos[count($pos) - 1]]];
        return implode(', ', array_map(fn($p) => $names[$order[$p]], $pos));
    };

    $g = [];
    foreach ($groups as $time => $days) $g[] = ['days' => $label($days), 'time' => $time];

    $closedPhrase = '';
    if ($closed) {
        $cpos = array_map(fn($d) => array_search($d, $order), $closed);
        sort($cpos);
        $cdays = array_map(fn($p) => $names[$order[$p]], $cpos);
        $closedPhrase = (count($cdays) === 1) ? 'Closed ' . $cdays[0] . 's' : 'Closed ' . implode(' & ', $cdays);
    }

    $single = (count($g) === 1);
    $sentence = $single
        ? ($g[0]['days'] . ', ' . $g[0]['time'] . ($closedPhrase ? '. ' . $closedPhrase : ''))
        : implode('; ', array_map(fn($x) => $x['days'] . ' ' . $x['time'], $g));

    return [
        'groups'        => $g,
        'open_days'     => $single ? $g[0]['days'] : '',
        'open_time'     => $single ? $g[0]['time'] : '',
        'closed_phrase' => $closedPhrase,
        'sentence'      => $sentence,
    ];
}

/** openingHoursSpecification array for schema.org JSON-LD, grouping like days. */
function site_hours_schema(string $scope = 'general'): array {
    $names = ['Sunday', 'Monday', 'Tuesday', 'Wednesday', 'Thursday', 'Friday', 'Saturday'];
    $week  = site_hours($scope);
    $groups = []; // signature => ['days'=>[], 'blocks'=>[]]
    for ($d = 0; $d <= 6; $d++) {
        if ($week[$d]['closed'] || empty($week[$d]['blocks'])) continue;
        $sig = implode(';', array_map(fn($b) => substr($b['open'], 0, 5) . '-' . substr($b['close'], 0, 5), $week[$d]['blocks']));
        if (!isset($groups[$sig])) $groups[$sig] = ['days' => [], 'blocks' => $week[$d]['blocks']];
        $groups[$sig]['days'][] = $names[$d];
    }
    $out = [];
    foreach ($groups as $g) {
        foreach ($g['blocks'] as $b) {
            $out[] = [
                '@type'     => 'OpeningHoursSpecification',
                'dayOfWeek' => $g['days'],
                'opens'     => substr($b['open'], 0, 5),
                'closes'    => substr($b['close'], 0, 5),
            ];
        }
    }
    return $out;
}

/** Resolve an hours scope through its "same as general" flag. */
function _site_effective_hours_scope(string $scope): string {
    $d = _site_data();
    if ($scope === 'general') return 'general';
    $row = $d['scopes'][$scope] ?? null;
    if (!$row) return 'general';
    if ((int)$row['inherit_general'] === 1) return 'general';
    return $scope;
}

/** Normalized weekly hours for a scope: [0..6 => ['closed'=>bool,'blocks'=>[...]]]. */
function site_hours(string $scope = 'general'): array {
    $d   = _site_data();
    $eff = _site_effective_hours_scope($scope);
    $raw = $d['hours'][$eff] ?? [];
    if ($eff !== 'general' && empty($raw)) $raw = $d['hours']['general'] ?? []; // safety fallback

    $week = [];
    for ($day = 0; $day <= 6; $day++) {
        $blocks = [];
        foreach (($raw[$day] ?? []) as $b) {
            if ((int)$b['is_closed'] === 1 || empty($b['open_time']) || empty($b['close_time'])) continue;
            $blocks[] = ['open' => (string)$b['open_time'], 'close' => (string)$b['close_time']];
        }
        $week[$day] = ['closed' => empty($blocks), 'blocks' => $blocks];
    }
    return $week;
}

/** 'HH:MM:SS' -> 'g:i A' (e.g. 16:00:00 -> 4:00 PM). */
function _site_fmt_time(string $t): string {
    $ts = strtotime($t);
    return $ts ? date('g:i A', $ts) : $t;
}

/** Rich open/closed status for a scope, season-aware, in the business timezone. */
function site_open_status(string $scope = 'general'): array {
    if (site_season() === 'closed') {
        return ['open' => false, 'label' => 'Closed for the season', 'detail' => ''];
    }
    $tz = site_setting('timezone', 'America/New_York');
    try { $now = new DateTime('now', new DateTimeZone($tz)); }
    catch (Throwable $e) { $now = new DateTime('now'); }

    $dow  = (int)$now->format('w');   // 0=Sun .. 6=Sat (matches schema)
    $nowT = $now->format('H:i:s');
    $week = site_hours($scope);

    foreach ($week[$dow]['blocks'] as $b) {
        if ($nowT >= $b['open'] && $nowT < $b['close']) {
            return ['open' => true, 'label' => 'Open now', 'detail' => 'until ' . _site_fmt_time($b['close'])];
        }
    }
    foreach ($week[$dow]['blocks'] as $b) {
        if ($nowT < $b['open']) {
            return ['open' => false, 'label' => 'Closed', 'detail' => 'opens ' . _site_fmt_time($b['open']) . ' today'];
        }
    }
    $names = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    for ($i = 1; $i <= 7; $i++) {
        $d2 = ($dow + $i) % 7;
        if (!empty($week[$d2]['blocks'])) {
            $when = ($i === 1) ? 'tomorrow' : $names[$d2];
            return ['open' => false, 'label' => 'Closed', 'detail' => 'opens ' . $when . ' ' . _site_fmt_time($week[$d2]['blocks'][0]['open'])];
        }
    }
    return ['open' => false, 'label' => 'Closed', 'detail' => ''];
}

/** Boolean convenience wrapper. */
function site_is_open(string $scope = 'general'): bool {
    return site_open_status($scope)['open'];
}

/* ---- Admin / raw accessors (NO inheritance resolution; for editing UI) ---- */
function site_all_settings(): array { return _site_data()['settings']; }
function site_all_contacts(): array {
    $out = [];
    foreach (_site_data()['contacts'] as $slots) {
        foreach ($slots as $row) $out[] = $row;
    }
    return $out;
}
function site_all_scopes(): array { return array_values(_site_data()['scopes']); }
function site_hours_raw(string $scope): array { return _site_data()['hours'][$scope] ?? []; }

/* ---- Display helpers for page templates (fallback-safe) -------------------- */

/** 'HH:MM:SS' -> compact form: 16:00 -> "4PM", 10:30 -> "10:30AM". */
function _site_fmt_time_compact(string $t): string {
    $ts = strtotime($t);
    if (!$ts) return $t;
    return (date('i', $ts) === '00') ? date('gA', $ts) : date('g:iA', $ts);
}

/** Compact one-line hours for meta descriptions, e.g. "Tue–Sun, 10AM–4PM". '' if none. */
function site_hours_compact(string $scope = 'general'): string {
    $abbr  = ['Sun', 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat'];
    $order = [1, 2, 3, 4, 5, 6, 0]; // Monday-first
    $week  = site_hours($scope);

    $groups = [];
    foreach ($order as $d) {
        if ($week[$d]['closed'] || empty($week[$d]['blocks'])) continue;
        $sig = implode(' & ', array_map(fn($b) => _site_fmt_time_compact($b['open']) . '–' . _site_fmt_time_compact($b['close']), $week[$d]['blocks']));
        $groups[$sig][] = $d;
    }
    if (empty($groups)) return '';

    $label = function (array $days) use ($abbr, $order) {
        $pos = array_map(fn($d) => array_search($d, $order), $days);
        sort($pos);
        if (count($pos) === 1) return $abbr[$order[$pos[0]]];
        $contig = ($pos === range($pos[0], $pos[count($pos) - 1]));
        if ($contig) return $abbr[$order[$pos[0]]] . '–' . $abbr[$order[$pos[count($pos) - 1]]];
        return implode(', ', array_map(fn($p) => $abbr[$order[$p]], $pos));
    };

    $parts = [];
    foreach ($groups as $time => $days) $parts[] = $label($days) . ', ' . $time;
    return implode('; ', $parts);
}

/** Visible-block fields with literal fallbacks so a DB miss never blanks a page.
 *  ['days'=>'Tuesday – Sunday','time'=>'10:00 AM – 4:00 PM','closed'=>'Closed Mondays','sentence'=>...]. */
function site_hours_parts(string $scope = 'general'): array {
    $s = function_exists('site_hours_summary') ? site_hours_summary($scope) : [];
    $f = fn($k, $d) => (!empty($s[$k]) ? $s[$k] : $d);
    return [
        'days'     => $f('open_days',     'Tuesday – Sunday'),
        'time'     => $f('open_time',     '10:00 AM – 4:00 PM'),
        'closed'   => $f('closed_phrase', 'Closed Mondays'),
        'sentence' => $f('sentence',      'Tuesday – Sunday, 10:00 AM – 4:00 PM. Closed Mondays'),
    ];
}

/** Phone display value with literal fallback, e.g. "(828) 216-5488". */
function site_phone_display(string $slot = 'general'): string {
    $v = site('phone.' . $slot);
    return $v !== '' ? $v : '(828) 216-5488';
}

/** tel: target (E.164) with literal fallback, e.g. "+18282165488". */
function site_tel(string $slot = 'general'): string {
    $t = site_phone_e164($slot);
    return $t !== '' ? $t : '+18282165488';
}

/** Email with literal fallback. */
function site_email(string $slot = 'general'): string {
    $v = site('email.' . $slot);
    return $v !== '' ? $v : 'support@batcaveroadfarm.com';
}

/** Address split for two-line display: ['line1'=>street, 'line2'=>'City, ST ZIP']. */
function site_address_lines(): array {
    $a = site_address(true);
    $l1 = $a['street'] !== '' ? $a['street'] : '4132 Bat Cave Road';
    $l2 = trim($a['city'] . (($a['city'] && $a['state']) ? ', ' : '') . $a['state'] . ' ' . $a['zip']);
    if ($l2 === '') $l2 = 'Old Fort, NC 28762';
    return ['line1' => $l1, 'line2' => $l2];
}

/** Google Maps "?q=" link to the address, fallback-safe. */
function site_map_query_url(): string {
    $a = site_address();
    if ($a === '') $a = '4132 Bat Cave Road, Old Fort, NC 28762';
    return 'https://maps.google.com/?q=' . rawurlencode($a);
}

/** schema.org PostalAddress array (fallback-safe). */
function site_schema_address(): array {
    $a = site_address(true);
    return [
        '@type'           => 'PostalAddress',
        'streetAddress'   => $a['street']  !== '' ? $a['street']  : '4132 Bat Cave Road',
        'addressLocality' => $a['city']    !== '' ? $a['city']    : 'Old Fort',
        'addressRegion'   => $a['state']   !== '' ? $a['state']   : 'NC',
        'postalCode'      => $a['zip']     !== '' ? $a['zip']     : '28762',
        'addressCountry'  => $a['country'] !== '' ? $a['country'] : 'US',
    ];
}

/** schema.org GeoCoordinates array (fallback-safe). */
function site_schema_geo(): array {
    $g = site_geo();
    return [
        '@type'     => 'GeoCoordinates',
        'latitude'  => $g['lat'] !== '' ? (float)$g['lat'] : 35.619,
        'longitude' => $g['lng'] !== '' ? (float)$g['lng'] : -82.259,
    ];
}

/** Social profile URLs for schema sameAs (falls back to FB + IG). */
function site_sameas(): array {
    $urls = [];
    foreach (site_socials() as $s) {
        if (!empty($s['url'])) $urls[] = (string)$s['url'];
    }
    if (empty($urls)) $urls = ['https://www.facebook.com/batcaveroadfarm', 'https://www.instagram.com/batcaveroadfarm'];
    return $urls;
}

/** Social links for footer/markup, fallback-safe: each ['name','url','image']. */
function site_socials_display(): array {
    $out = [];
    foreach (site_socials() as $s) {
        if (empty($s['url'])) continue;
        $out[] = ['name' => (string)($s['name'] ?? ''), 'url' => (string)$s['url'], 'image' => (string)($s['image'] ?? '')];
    }
    if (empty($out)) {
        $out = [
            ['name' => 'Facebook',  'url' => 'https://www.facebook.com/batcaveroadfarm',  'image' => 'https://batcaveroadfarm.com/img/facebook.png'],
            ['name' => 'Instagram', 'url' => 'https://www.instagram.com/batcaveroadfarm', 'image' => 'https://batcaveroadfarm.com/img/instagram.png'],
        ];
    }
    return $out;
}

} // end function_exists guard
