<?php
require_once __DIR__ . '/img_dims.php';
/**
 * Farm events engine for batcaveroadfarm.com.
 * Supports one-off, seasonal-range, weekly, and annual recurring events.
 * Emits valid schema.org Event markup (with eventSchedule for recurring).
 * Requires: $pdo (PDO), createSlug() from config.php.
 */

if (!function_exists('bcrf_events_ensure_schema')) {
    function bcrf_events_ensure_schema(PDO $pdo): void
    {
        static $done = false;
        if ($done) return;
        $done = true;
        try {
            $pdo->exec("CREATE TABLE IF NOT EXISTS events (
                id INT AUTO_INCREMENT PRIMARY KEY,
                title VARCHAR(160) NOT NULL,
                slug VARCHAR(190) NOT NULL,
                description TEXT NULL,
                image_url VARCHAR(255) NULL,
                start_datetime DATETIME NOT NULL,
                end_datetime DATETIME NULL,
                all_day TINYINT(1) NOT NULL DEFAULT 0,
                recurrence ENUM('none','range','weekly','annual') NOT NULL DEFAULT 'none',
                recur_days VARCHAR(20) NULL,
                recur_until DATE NULL,
                recurrence_note VARCHAR(160) NULL,
                is_free TINYINT(1) NOT NULL DEFAULT 1,
                price DECIMAL(10,2) NULL,
                location_note VARCHAR(160) NULL,
                status ENUM('draft','published') NOT NULL DEFAULT 'published',
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP ON UPDATE CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4");
        } catch (Throwable $e) {
            error_log('[bcrf_events_ensure_schema] ' . $e->getMessage());
        }
    }
}

if (!function_exists('bcrf_event_tz')) {
    function bcrf_event_tz(): DateTimeZone { return new DateTimeZone('America/New_York'); }
}

if (!function_exists('bcrf_event_iso')) {
    function bcrf_event_iso(?string $dt, bool $dateOnly = false): ?string
    {
        $dt = trim((string)$dt);
        if ($dt === '' || strpos($dt, '0000-00-00') === 0) return null;
        try {
            $d = new DateTime($dt, bcrf_event_tz());
            return $dateOnly ? $d->format('Y-m-d') : $d->format('c');
        } catch (Throwable $e) { return null; }
    }
}

if (!function_exists('bcrf_event_dow_names')) {
    function bcrf_event_dow_names(?string $csv): array
    {
        $map = ['Sunday','Monday','Tuesday','Wednesday','Thursday','Friday','Saturday'];
        $out = [];
        foreach (array_filter(array_map('trim', explode(',', (string)$csv)), 'strlen') as $n) {
            $i = (int)$n;
            if ($i >= 0 && $i <= 6) $out[] = 'https://schema.org/' . $map[$i];
        }
        return $out;
    }
}

if (!function_exists('bcrf_events_fetch_upcoming')) {
    function bcrf_events_fetch_upcoming(PDO $pdo, int $limit = 50): array
    {
        bcrf_events_ensure_schema($pdo);
        try {
            $sql = "SELECT * FROM events
                    WHERE status = 'published'
                      AND (
                            (recurrence IN ('none','range') AND COALESCE(end_datetime, start_datetime) >= NOW())
                         OR (recurrence IN ('weekly','annual') AND (recur_until IS NULL OR recur_until >= CURDATE()))
                          )
                    ORDER BY start_datetime ASC
                    LIMIT " . (int)$limit;
            return $pdo->query($sql)->fetchAll(PDO::FETCH_ASSOC) ?: [];
        } catch (Throwable $e) {
            error_log('[bcrf_events_fetch_upcoming] ' . $e->getMessage());
            return [];
        }
    }
}

if (!function_exists('bcrf_event_anchor')) {
    function bcrf_event_anchor(array $ev): string
    {
        $slug = trim((string)($ev['slug'] ?? ''));
        if ($slug === '') $slug = 'event-' . (int)($ev['id'] ?? 0);
        return 'https://batcaveroadfarm.com/events/#' . $slug;
    }
}

if (!function_exists('bcrf_event_schema')) {
    function bcrf_event_schema(array $ev): array
    {
        $allDay = !empty($ev['all_day']);
        $sd = bcrf_event_iso($ev['start_datetime'] ?? '', $allDay);
        if ($sd === null) return []; // startDate is required; skip invalid
        $bcrfAddr = function_exists('site_address')
            ? site_address(true)
            : ['street' => '4132 Bat Cave Road', 'city' => 'Old Fort', 'state' => 'NC', 'zip' => '28762', 'country' => 'US'];
        $node = [
            '@type'               => 'Event',
            'name'                => (string)($ev['title'] ?? ''),
            'startDate'           => $sd,
            'eventStatus'         => 'https://schema.org/EventScheduled',
            'eventAttendanceMode' => 'https://schema.org/OfflineEventAttendanceMode',
            'url'                 => bcrf_event_anchor($ev),
            'location'            => [
                '@type'   => 'Place',
                'name'    => 'Bat Cave Road Farm',
                'address' => [
                    '@type'           => 'PostalAddress',
                    'streetAddress'   => $bcrfAddr['street'],
                    'addressLocality' => $bcrfAddr['city'],
                    'addressRegion'   => $bcrfAddr['state'],
                    'postalCode'      => $bcrfAddr['zip'],
                    'addressCountry'  => $bcrfAddr['country'],
                ],
            ],
            'organizer' => ['@type' => 'Organization', '@id' => 'https://batcaveroadfarm.com/#business', 'name' => 'Bat Cave Road Farm', 'url' => 'https://batcaveroadfarm.com/'],
        ];
        $ed = bcrf_event_iso($ev['end_datetime'] ?? '', $allDay);
        if ($ed !== null) $node['endDate'] = $ed;
        $desc = trim((string)($ev['description'] ?? ''));
        if ($desc !== '') $node['description'] = $desc;
        $img = trim((string)($ev['image_url'] ?? ''));
        if ($img !== '') $node['image'] = (strpos($img, 'http') === 0) ? $img : 'https://batcaveroadfarm.com/' . ltrim($img, '/');

        // recurring → eventSchedule
        $rec = (string)($ev['recurrence'] ?? 'none');
        if ($rec === 'weekly' || $rec === 'annual') {
            $sched = [
                '@type'            => 'Schedule',
                'startDate'        => bcrf_event_iso($ev['start_datetime'] ?? '', true),
                'repeatFrequency'  => ($rec === 'weekly') ? 'P1W' : 'P1Y',
                'scheduleTimezone' => 'America/New_York',
            ];
            if (!$allDay) {
                try {
                    $st = new DateTime((string)$ev['start_datetime'], bcrf_event_tz());
                    $sched['startTime'] = $st->format('H:i:s');
                } catch (Throwable $e) {}
                if (!empty($ev['end_datetime'])) {
                    try {
                        $en = new DateTime((string)$ev['end_datetime'], bcrf_event_tz());
                        $sched['endTime'] = $en->format('H:i:s');
                    } catch (Throwable $e) {}
                }
            }
            if (!empty($ev['recur_until'])) $sched['endDate'] = bcrf_event_iso($ev['recur_until'] . ' 00:00:00', true);
            if ($rec === 'weekly') {
                $by = bcrf_event_dow_names($ev['recur_days'] ?? '');
                if ($by) $sched['byDay'] = $by;
            }
            $node['eventSchedule'] = $sched;
        }

        // offers
        if (!empty($ev['is_free'])) {
            $node['isAccessibleForFree'] = true;
        } elseif (isset($ev['price']) && (float)$ev['price'] > 0) {
            $node['offers'] = [
                '@type'         => 'Offer',
                'price'         => number_format((float)$ev['price'], 2, '.', ''),
                'priceCurrency' => 'USD',
                'availability'  => 'https://schema.org/InStock',
                'url'           => bcrf_event_anchor($ev),
            ];
        }
        return $node;
    }
}

if (!function_exists('bcrf_events_render')) {
    function bcrf_events_render(array $events): string
    {
        if (!$events) {
            return '<p class="events-empty">No events are on the calendar right now — check back soon, or <a href="/plan-your-visit/">plan a visit</a> during our regular hours (' . ((function_exists('site_hours_compact') && site_hours_compact('general') !== '') ? site_hours_compact('general') : 'Tue–Sun, 10AM–4PM') . ').</p>';
        }
        $tz = bcrf_event_tz();
        $h  = '<div class="events-list">';
        foreach ($events as $ev) {
            $slug = trim((string)($ev['slug'] ?? '')) ?: ('event-' . (int)($ev['id'] ?? 0));
            $title = (string)($ev['title'] ?? '');
            $mon = $day = '';
            try { $d = new DateTime((string)$ev['start_datetime'], $tz); $mon = $d->format('M'); $day = $d->format('j'); } catch (Throwable $e) {}
            // human date line
            $when = '';
            $note = trim((string)($ev['recurrence_note'] ?? ''));
            if ($note !== '') {
                $when = $note;
            } else {
                try {
                    $d = new DateTime((string)$ev['start_datetime'], $tz);
                    $when = $d->format('l, F j, Y');
                    if (empty($ev['all_day'])) $when .= ' · ' . $d->format('g:i A');
                    if (!empty($ev['end_datetime'])) {
                        $e2 = new DateTime((string)$ev['end_datetime'], $tz);
                        if ($e2->format('Y-m-d') !== $d->format('Y-m-d')) $when .= ' – ' . $e2->format('F j, Y');
                        elseif (empty($ev['all_day'])) $when .= ' – ' . $e2->format('g:i A');
                    }
                } catch (Throwable $e) {}
            }
            $desc = trim(strip_tags((string)($ev['description'] ?? '')));
            $img  = trim((string)($ev['image_url'] ?? ''));
            $free = !empty($ev['is_free']);
            $price = (float)($ev['price'] ?? 0);

            $h .= '<article class="event-card" id="' . htmlspecialchars($slug) . '">';
            $h .= '<div class="event-date" aria-hidden="true"><span class="event-date-mon">' . htmlspecialchars($mon) . '</span><span class="event-date-day">' . htmlspecialchars($day) . '</span></div>';
            if ($img !== '') {
                $src = (strpos($img, 'http') === 0) ? $img : '/' . ltrim($img, '/');
                $h .= '<div class="event-thumb"><img src="' . htmlspecialchars($src) . '"' . bcrf_img_attrs($src) . ' alt="' . htmlspecialchars($title) . '" loading="lazy" decoding="async"></div>';
            }
            $h .= '<div class="event-body">';
            $h .= '<h2 class="event-title">' . htmlspecialchars($title) . '</h2>';
            if ($when !== '') $h .= '<p class="event-when">📅 ' . htmlspecialchars($when) . '</p>';
            $h .= '<p class="event-cost">' . ($free ? 'Free' : ($price > 0 ? '$' . number_format($price, 2) : 'See details')) . '</p>';
            if ($desc !== '') $h .= '<p class="event-desc">' . htmlspecialchars($desc) . '</p>';
            $h .= '</div></article>';
        }
        $h .= '</div>';
        return $h;
    }
}
