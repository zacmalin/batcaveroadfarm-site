<?php
// includes/themes.php — seasonal theme catalog + active-theme resolver.
// Palettes live in CSS (body.theme-<slug>); this file holds metadata + scheduling logic.

if (!function_exists('bcrf_theme_catalog')) {

    /** slug => [name, glyph (falling animation), banner (short message), window (default annual MM-DD..MM-DD)] */
    function bcrf_theme_catalog(): array {
        return [
            'spring'       => ['name' => 'Spring',              'glyph' => '🌸', 'banner' => 'Spring has sprung at the farm — fresh blooms and baby animals!',        'window' => '03-01..05-31'],
            'summer'       => ['name' => 'Summer',              'glyph' => '🌻', 'banner' => 'Summer on the farm — sunflowers, sunshine and fresh produce!',          'window' => '06-01..08-31'],
            'autumn'       => ['name' => 'Autumn / Harvest',    'glyph' => '🍂', 'banner' => 'Harvest season is here — pumpkins, mums and crisp mountain air!',       'window' => '09-01..11-30'],
            'winter'       => ['name' => 'Winter',              'glyph' => '❄️', 'banner' => 'Cozy winter days at Bat Cave Road Farm.',                               'window' => '01-02..02-28'],
            'halloween'    => ['name' => 'Halloween',           'glyph' => '🎃', 'banner' => 'Spooky season on the farm — pick your perfect pumpkin!',                'window' => '10-15..10-31'],
            'thanksgiving' => ['name' => 'Thanksgiving',        'glyph' => '🦃', 'banner' => 'Grateful for our farm family — Happy Thanksgiving!',                    'window' => '11-20..11-30'],
            'christmas'    => ['name' => 'Christmas',           'glyph' => '🎄', 'banner' => 'Merry Christmas from Bat Cave Road Farm!',                              'window' => '12-10..12-26'],
            'newyears'     => ['name' => "New Year's",          'glyph' => '✨', 'banner' => 'Happy New Year from all of us at the farm!',                           'window' => '12-27..01-01'],
            'valentines'   => ['name' => "Valentine's Day",     'glyph' => '❤️', 'banner' => 'Spread a little love this Valentine\'s Day!',                          'window' => '02-08..02-14'],
            'easter'       => ['name' => 'Easter',              'glyph' => '🐣', 'banner' => 'Hop on over — Happy Easter from the farm!',                            'window' => '03-22..04-22'],
            'patriotic'    => ['name' => 'Patriotic (July 4th)','glyph' => '🎆', 'banner' => 'Happy Independence Day from Bat Cave Road Farm!',                      'window' => '06-28..07-07'],
        ];
    }

    function bcrf_theme_exists(string $slug): bool { return array_key_exists($slug, bcrf_theme_catalog()); }

    /** Is today's MM-DD inside an annual window that may wrap the year end (e.g. 12-27..01-01)? */
    function bcrf_md_in_window(string $todayMd, string $startMd, string $endMd): bool {
        if ($startMd <= $endMd) return ($todayMd >= $startMd && $todayMd <= $endMd);
        return ($todayMd >= $startMd || $todayMd <= $endMd); // wraps year-end
    }

    /**
     * Resolve the active theme slug. Precedence:
     *   1) ?theme=slug preview (render-only)   2) manual override   3) scheduled (highest priority)
     *   4) configured default   5) none (base palette).
     * Degrades silently to '' if the DB or theme tables aren't available.
     */
    function bcrf_active_theme(?PDO $pdo): string {
        $cat = bcrf_theme_catalog();

        $preview = isset($_GET['theme']) ? preg_replace('/[^a-z0-9_]/', '', strtolower((string)$_GET['theme'])) : '';
        if ($preview !== '' && isset($cat[$preview])) return $preview;

        if (!$pdo instanceof PDO) return '';

        try {
            $settings = [];
            try {
                foreach ($pdo->query("SELECT skey, sval FROM theme_settings")->fetchAll(PDO::FETCH_KEY_PAIR) as $k => $v) {
                    $settings[$k] = (string)$v;
                }
            } catch (Throwable $e) { return ''; } // tables not created yet

            $override = (string)($settings['override'] ?? '');
            if ($override !== '' && isset($cat[$override])) return $override;

            $today   = date('Y-m-d');
            $todayMd = date('m-d');
            $best = null; $bestPriority = PHP_INT_MIN;

            $rows = $pdo->query("SELECT theme_slug, mode, start_md, end_md, start_date, end_date, priority
                                 FROM theme_schedules WHERE is_active = 1")->fetchAll(PDO::FETCH_ASSOC);
            foreach ($rows as $r) {
                $slug = (string)$r['theme_slug'];
                if (!isset($cat[$slug])) continue;
                $match = false;
                if ($r['mode'] === 'annual') {
                    if (!empty($r['start_md']) && !empty($r['end_md'])) {
                        $match = bcrf_md_in_window($todayMd, (string)$r['start_md'], (string)$r['end_md']);
                    }
                } else {
                    if (!empty($r['start_date']) && !empty($r['end_date'])) {
                        $match = ($today >= $r['start_date'] && $today <= $r['end_date']);
                    }
                }
                if ($match && (int)$r['priority'] >= $bestPriority) { $best = $slug; $bestPriority = (int)$r['priority']; }
            }
            if ($best !== null) return $best;

            $default = (string)($settings['default'] ?? '');
            if ($default !== '' && isset($cat[$default])) return $default;
        } catch (Throwable $e) {
            error_log('[themes] ' . $e->getMessage());
        }
        return '';
    }

    function bcrf_theme_glyph(string $slug): string {
        $cat = bcrf_theme_catalog();
        return isset($cat[$slug]) ? (string)$cat[$slug]['glyph'] : '';
    }

    function bcrf_theme_banner_html(string $slug): string {
        $cat = bcrf_theme_catalog();
        if (!isset($cat[$slug])) return '';
        $msg = trim((string)$cat[$slug]['banner']);
        if ($msg === '') return '';
        return '<div class="theme-banner" role="note"><span class="theme-banner-glyph" aria-hidden="true">'
             . htmlspecialchars((string)$cat[$slug]['glyph']) . '</span> '
             . htmlspecialchars($msg) . '</div>';
    }
}
