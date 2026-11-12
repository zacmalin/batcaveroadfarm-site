<?php
// includes/email_prefs.php — Farm OS Phase 3: notification preferences (Nov 12, 2026; migration 0037; 07 › Communications
// and consent, CRM extension "Account preferences").
//   Always sent, no setting: booking and party confirmations, quotes and payments, tickets, passes, receipts, gift cards,
//                sign-in and password links, weather closures (transactional, 07).
//   Optional service emails, on unless turned off: visit reminders (day before a booking), Roost Rewards reminders.
//   Marketing, off unless the person opted in: the newsletter and its two topics, kept in mailing_list as before, so the
//                signup form, unsubscribe link, admin Newsletter page and this page all show the same state.
// Preferences are by email address, so guests without an account have them too. The preferences link carries a random
// token (email_preferences.token), never the address. Opening the link proves the inbox, so it may turn the newsletter on;
// staff may only turn things off. Every change is logged in email_preference_log.
if (!function_exists('bcrf_email_prefs_ready')) {

/** Optional service emails: setting => [label, what it is]. */
define('BCRF_EMAIL_PREF_SERVICE', [
    'visit_reminders'   => ['Visit reminders', 'A reminder the day before a visit you\'ve booked.'],
    'rewards_reminders' => ['Roost Rewards reminders', 'A heads-up before your points expire.'],
]);
/** Marketing: setting => [label, what it is, mailing_list column]. */
define('BCRF_EMAIL_PREF_TOPICS', [
    'newsletter' => ['Farm newsletter', 'News, events and what\'s happening at the farm.', 'is_subscribed'],
    'nursery'    => ['Nursery & animal updates', 'When new baby animals arrive.', 'track_nursery_updates'],
    'inventory'  => ['Fresh harvest alerts', 'When seasonal produce and baked goods hit the stand.', 'track_inventory_alerts'],
]);
define('BCRF_EMAIL_PREF_VIA', ['link', 'portal', 'staff', 'unsubscribe', 'signup']);

function bcrf_email_prefs_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM email_preferences LIMIT 0"); return true; } catch (Throwable $e) { return false; }
}

function bcrf_email_prefs_norm(string $email): string {
    $e = strtolower(trim($email));
    return filter_var($e, FILTER_VALIDATE_EMAIL) ? $e : '';
}

/** The email_preferences row, made with the defaults when there isn't one yet. null for a bad address or before 0037. */
function bcrf_email_prefs_row(PDO $pdo, string $email, bool $create = true): ?array {
    $email = bcrf_email_prefs_norm($email);
    if ($email === '' || !bcrf_email_prefs_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT * FROM email_preferences WHERE email = ?");
    $st->execute([$email]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if ($r || !$create) return $r ?: null;
    $pdo->prepare("INSERT IGNORE INTO email_preferences (email, token, created_at) VALUES (?, ?, UTC_TIMESTAMP())")->execute([$email, bin2hex(random_bytes(16))]);
    $st->execute([$email]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The row for a preferences-link token, or null. */
function bcrf_email_prefs_by_token(PDO $pdo, string $token): ?array {
    if (!preg_match('/^[a-f0-9]{32}$/', $token) || !bcrf_email_prefs_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT * FROM email_preferences WHERE token = ?");
    $st->execute([$token]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** Every setting for an address: ['visit_reminders' => bool, …, 'newsletter' => bool, 'nursery' => bool, 'inventory' => bool, 'consent' => […]]. */
function bcrf_email_prefs_get(PDO $pdo, string $email): array {
    $out = [];
    $row = bcrf_email_prefs_row($pdo, $email, false);
    foreach (BCRF_EMAIL_PREF_SERVICE as $k => $_) $out[$k] = $row ? (bool)$row[$k] : true;
    foreach (BCRF_EMAIL_PREF_TOPICS as $k => $_) $out[$k] = false;
    $out['consent'] = null;
    $e = bcrf_email_prefs_norm($email);
    if ($e === '') return $out;
    try {
        $st = $pdo->prepare("SELECT * FROM mailing_list WHERE LOWER(email) = ? LIMIT 1");
        $st->execute([$e]);
        if ($m = $st->fetch(PDO::FETCH_ASSOC)) {
            $on = (bool)$m['is_subscribed'];
            $out['newsletter'] = $on;
            $out['nursery'] = $on && (bool)$m['track_nursery_updates'];
            $out['inventory'] = $on && (bool)$m['track_inventory_alerts'];
            $out['consent'] = ['source' => $m['consent_source'] ?? null, 'at' => $m['consent_at'] ?? null, 'unsubscribed_at' => $m['unsubscribed_at'] ?? null];
        }
    } catch (Throwable $e) {}
    return $out;
}

/**
 * May the farm send this optional service email? True unless the person turned it off. Errors and a missing table
 * count as yes, so a broken preferences table never stops a reminder the person didn't turn off.
 */
function bcrf_email_pref_allowed(PDO $pdo, string $email, string $setting): bool {
    if (!isset(BCRF_EMAIL_PREF_SERVICE[$setting])) return true;
    try { $r = bcrf_email_prefs_row($pdo, $email, false); return !$r || (bool)$r[$setting]; }
    catch (Throwable $e) { return true; }
}

/** The preferences link for an address ('' when it can't be made). */
function bcrf_email_prefs_url(PDO $pdo, string $email): string {
    try { $r = bcrf_email_prefs_row($pdo, $email); } catch (Throwable $e) { $r = null; }
    if (!$r) return '';
    $base = function_exists('bcrf_farm_url') ? bcrf_farm_url() : 'https://batcaveroadfarm.com';
    return $base . '/preferences.php?t=' . $r['token'];
}

/** A small line for the bottom of an optional email: why they got it and where to turn it off. */
function bcrf_email_prefs_footer(PDO $pdo, string $email, string $what, string $because = 'you booked with us'): string {
    $url = bcrf_email_prefs_url($pdo, $email);
    if ($url === '') return '';
    return '<p style="color:#c9b48f;font-size:12px;margin-top:18px;">You get ' . htmlspecialchars($what, ENT_QUOTES) . ' because ' . htmlspecialchars($because, ENT_QUOTES) . '. '
         . '<a style="color:#e8b84b;" href="' . htmlspecialchars($url, ENT_QUOTES) . '">Change your email preferences</a>.</p>';
}

/**
 * Saves settings for an address. $want = ['visit_reminders' => bool, 'newsletter' => bool, …] (only the keys given change).
 * $via: link | portal | staff | unsubscribe | signup. $mayOptIn: the person proved the inbox (preferences link, verified
 * portal account, confirmed signup), so marketing may be turned on; otherwise marketing can only be turned off.
 * Returns the settings that changed: ['newsletter' => false, …].
 */
function bcrf_email_prefs_save(PDO $pdo, string $email, array $want, string $via, ?int $actorId = null, bool $mayOptIn = false): array {
    $email = bcrf_email_prefs_norm($email);
    if ($email === '' || !in_array($via, BCRF_EMAIL_PREF_VIA, true)) return [];
    $now = bcrf_email_prefs_get($pdo, $email);
    $changed = [];
    $pdo->beginTransaction();
    try {
        $svc = [];
        foreach (BCRF_EMAIL_PREF_SERVICE as $k => $_) {
            if (!array_key_exists($k, $want) || (bool)$want[$k] === $now[$k]) continue;
            $svc[$k] = (bool)$want[$k];
        }
        if ($svc) {
            bcrf_email_prefs_row($pdo, $email);
            $set = implode(', ', array_map(fn($k) => "$k = ?", array_keys($svc)));
            $pdo->prepare("UPDATE email_preferences SET $set, updated_at = UTC_TIMESTAMP() WHERE email = ?")
                ->execute([...array_map(fn($v) => $v ? 1 : 0, array_values($svc)), $email]);
            $changed += $svc;
        }
        // Marketing. Topics only mean something while the newsletter is on.
        $nl = array_key_exists('newsletter', $want) ? (bool)$want['newsletter'] : $now['newsletter'];
        if ($nl && !$now['newsletter'] && !$mayOptIn) $nl = false;
        $topics = [];
        foreach (['nursery', 'inventory'] as $k) {
            $v = array_key_exists($k, $want) ? (bool)$want[$k] : $now[$k];
            if ($v && !$now[$k] && !$mayOptIn) $v = false;
            $topics[$k] = $nl && $v;
        }
        if ($nl !== $now['newsletter'] || $topics['nursery'] !== $now['nursery'] || $topics['inventory'] !== $now['inventory']) {
            $st = $pdo->prepare("SELECT id FROM mailing_list WHERE LOWER(email) = ? LIMIT 1");
            $st->execute([$email]);
            $id = (int)$st->fetchColumn();
            if ($nl) {
                $src = $via === 'signup' ? 'signup' : ($via === 'portal' ? 'portal' : 'link');
                if ($id) {
                    // MySQL applies SET left to right, so is_subscribed changes last: the IFs see the old value.
                    $pdo->prepare("UPDATE mailing_list SET track_nursery_updates = ?, track_inventory_alerts = ?,
                                   consent_source = IF(is_subscribed = 1, consent_source, ?), consent_at = IF(is_subscribed = 1, consent_at, UTC_TIMESTAMP()),
                                   unsubscribed_at = IF(is_subscribed = 1, unsubscribed_at, NULL), is_subscribed = 1 WHERE id = ?")
                        ->execute([$topics['nursery'] ? 1 : 0, $topics['inventory'] ? 1 : 0, $src, $id]);
                } else {
                    $pdo->prepare("INSERT INTO mailing_list (email, is_subscribed, track_nursery_updates, track_inventory_alerts, consent_source, consent_at)
                                   VALUES (?, 1, ?, ?, ?, UTC_TIMESTAMP())")->execute([$email, $topics['nursery'] ? 1 : 0, $topics['inventory'] ? 1 : 0, $src]);
                }
            } elseif ($id) {
                // Off: keep the topic choices, so turning the newsletter back on restores them.
                $pdo->prepare("UPDATE mailing_list SET is_subscribed = 0, unsubscribed_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
            }
            foreach (['newsletter' => $nl] + $topics as $k => $v) if ($v !== $now[$k]) $changed[$k] = $v;
        }
        $log = $pdo->prepare("INSERT INTO email_preference_log (email, setting, value, via, actor_id, created_at) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP())");
        foreach ($changed as $k => $v) $log->execute([$email, $k, $v ? 1 : 0, $via, $actorId]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    if ($changed && function_exists('erp_audit')) {
        try {
            $words = implode(', ', array_map(fn($k, $v) => (BCRF_EMAIL_PREF_SERVICE[$k][0] ?? BCRF_EMAIL_PREF_TOPICS[$k][0] ?? $k) . ($v ? ' on' : ' off'), array_keys($changed), $changed));
            erp_audit($pdo, 'update', 'email_preferences', null, $email . ': ' . $words . ' (' . $via . ')', $via === 'staff' ? null : 'Customer (self)');
        } catch (Throwable $e) {}
    }
    return $changed;
}

/** Turns every optional email off: service emails and marketing. */
function bcrf_email_prefs_all_off(PDO $pdo, string $email, string $via, ?int $actorId = null): array {
    $off = [];
    foreach (BCRF_EMAIL_PREF_SERVICE as $k => $_) $off[$k] = false;
    foreach (BCRF_EMAIL_PREF_TOPICS as $k => $_) $off[$k] = false;
    return bcrf_email_prefs_save($pdo, $email, $off, $via, $actorId);
}

/** Reads the checkboxes from bcrf_email_prefs_fields() out of a POST. */
function bcrf_email_prefs_from_post(array $post): array {
    $want = [];
    foreach (array_keys(BCRF_EMAIL_PREF_SERVICE + BCRF_EMAIL_PREF_TOPICS) as $k) if (array_key_exists('pref_' . $k, $post)) $want[$k] = (string)$post['pref_' . $k] === '1';
    return $want;
}

/** The checkboxes for the public preferences page and My farm (portal styles in css/min.portal.css). */
function bcrf_email_prefs_fields(array $prefs, bool $mayOptIn): string {
    $h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $box = function (string $k, string $label, string $what, bool $on, bool $locked) use ($h) {
        return '<label class="pref-row"><input type="hidden" name="pref_' . $k . '" value="0">'
             . '<input type="checkbox" name="pref_' . $k . '" value="1"' . ($on ? ' checked' : '') . ($locked ? ' disabled' : '') . '>'
             . '<span><strong>' . $h($label) . '</strong><br><span class="portal-muted">' . $h($what) . '</span></span></label>';
    };
    $out = '<fieldset class="pref-group"><legend>Reminders</legend>';
    foreach (BCRF_EMAIL_PREF_SERVICE as $k => [$label, $what]) $out .= $box($k, $label, $what, (bool)$prefs[$k], false);
    $out .= '</fieldset><fieldset class="pref-group"><legend>News and offers</legend>';
    foreach (BCRF_EMAIL_PREF_TOPICS as $k => [$label, $what]) $out .= $box($k, $label, $what, (bool)$prefs[$k], !$mayOptIn && !$prefs[$k]);
    if (!$mayOptIn) $out .= '<p class="portal-muted">Verify your email to sign up for news. You can always turn things off.</p>';
    return $out . '<p class="portal-muted">The topics come with the newsletter. We always send booking and party confirmations, tickets, passes, receipts, payment links and weather closures.</p></fieldset>';
}

/** The last changes for an address, newest first. */
function bcrf_email_prefs_log(PDO $pdo, string $email, int $limit = 20): array {
    try {
        $st = $pdo->prepare("SELECT * FROM email_preference_log WHERE email = ? ORDER BY id DESC LIMIT " . max(1, min(100, $limit)));
        $st->execute([bcrf_email_prefs_norm($email)]);
        return $st->fetchAll(PDO::FETCH_ASSOC);
    } catch (Throwable $e) { return []; }
}

}
