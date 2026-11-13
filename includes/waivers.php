<?php
// includes/waivers.php — Farm OS Phase 3: waivers (Nov 12, 2026; migration 0039; 07 › Waivers).
//   Wording   waiver_templates, versioned. Staff edit one draft; an administrator publishes it as the next version,
//             confirming an NC attorney reviewed it. Published versions never change. Signing uses the newest published
//             version whose effective date has come. Nothing can be signed until one is published.
//   Signing   one adult per party signs for the minors in their care (Q-07 working assumption): name, email, phone,
//             children's first names and ages, a typed signature and an optional drawn one (waiver.php: online, or on
//             the gate device with staff signed in). A signature is good for waiver_valid_months (12).
//   Gate      waiver_mode: off (default) = nothing shown or checked; warn = the gate shows who hasn't signed;
//             block = the gate won't check them in until someone signs, unless a manager lets them in with a reason.
//   Keeping   signatures are never deleted, even on a deletion request (legal retention); staff can void one.
// Times are UTC in the database.
if (!function_exists('bcrf_waivers_ready')) {

class BcrfWaiverError extends RuntimeException {}

define('BCRF_WAIVER_MODES', ['off' => 'Off', 'warn' => 'Show who hasn\'t signed', 'block' => 'Require a waiver to check in']);
define('BCRF_WAIVER_VIA', ['online' => 'Online', 'gate' => 'At the gate', 'portal' => 'My farm']);

function bcrf_waivers_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM waivers LIMIT 0"); $pdo->query("SELECT 1 FROM waiver_templates LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

function bcrf_waiver_setting(PDO $pdo, string $key, string $default): string {
    try {
        $st = $pdo->prepare("SELECT setting_value FROM site_settings WHERE setting_key = ?");
        $st->execute([$key]);
        $v = $st->fetchColumn();
        return $v === false || $v === null ? $default : (string)$v;
    } catch (Throwable $e) { return $default; }
}

function bcrf_waiver_set_setting(PDO $pdo, string $key, string $value): void {
    $pdo->prepare("INSERT INTO site_settings (setting_key, setting_value) VALUES (?, ?) ON DUPLICATE KEY UPDATE setting_value = VALUES(setting_value)")
        ->execute([$key, $value]);
}

/** off | warn | block. Always off until a version is published. */
function bcrf_waiver_mode(PDO $pdo): string {
    if (!bcrf_waivers_ready($pdo)) return 'off';
    $m = bcrf_waiver_setting($pdo, 'waiver_mode', 'off');
    if (!isset(BCRF_WAIVER_MODES[$m]) || $m === 'off') return 'off';
    return bcrf_waiver_current($pdo) ? $m : 'off';
}

function bcrf_waiver_valid_months(PDO $pdo): int {
    $n = (int)bcrf_waiver_setting($pdo, 'waiver_valid_months', '12');
    return $n >= 1 && $n <= 60 ? $n : 12;
}

function bcrf_waiver_norm_email(?string $e): string { return strtolower(trim((string)$e)); }

function bcrf_waiver_hash(array $t): string { return hash('sha256', $t['title'] . "\n\n" . $t['body']); }

/** Paragraphs of a template body, for display. */
function bcrf_waiver_paragraphs(string $body): array {
    return array_values(array_filter(array_map('trim', preg_split('/\R\s*\R/', str_replace("\r", '', $body))), 'strlen'));
}

// ── Wording ────────────────────────────────────────────────────────────────────────────────────

/** The version people sign now: newest published one in effect, or null. */
function bcrf_waiver_current(PDO $pdo): ?array {
    try {
        $r = $pdo->query("SELECT * FROM waiver_templates WHERE published_at IS NOT NULL AND effective_at <= UTC_TIMESTAMP()
                          ORDER BY effective_at DESC, version DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
        return $r ?: null;
    } catch (Throwable $e) { return null; }
}

function bcrf_waiver_template(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT * FROM waiver_templates WHERE id = ?");
    $st->execute([$id]);
    return $st->fetch(PDO::FETCH_ASSOC) ?: null;
}

/** The draft being edited (made from the newest version when there is none). */
function bcrf_waiver_draft(PDO $pdo): array {
    $r = $pdo->query("SELECT * FROM waiver_templates WHERE published_at IS NULL ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    if ($r) return $r;
    $last = $pdo->query("SELECT * FROM waiver_templates ORDER BY id DESC LIMIT 1")->fetch(PDO::FETCH_ASSOC);
    $pdo->prepare("INSERT INTO waiver_templates (title, body, created_at) VALUES (?, ?, UTC_TIMESTAMP())")
        ->execute([$last['title'] ?? 'Release and waiver of liability', $last['body'] ?? '']);
    return bcrf_waiver_template($pdo, (int)$pdo->lastInsertId());
}

/** All versions, newest first. */
function bcrf_waiver_versions(PDO $pdo): array {
    return $pdo->query("SELECT t.*, (SELECT COUNT(*) FROM waivers w WHERE w.template_id = t.id) AS signed
                        FROM waiver_templates t WHERE t.published_at IS NOT NULL ORDER BY t.version DESC")->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_waiver_draft_save(PDO $pdo, string $title, string $body, ?int $uid): void {
    $title = trim(strip_tags($title));
    $body = trim(str_replace("\r", '', strip_tags($body)));
    if ($title === '' || mb_strlen($title) > 160) throw new BcrfWaiverError('Give the waiver a title (160 characters at most).');
    if (mb_strlen($body) < 20) throw new BcrfWaiverError('Paste the waiver wording.');
    if (mb_strlen($body) > 60000) throw new BcrfWaiverError('That wording is too long.');
    $d = bcrf_waiver_draft($pdo);
    $pdo->prepare("UPDATE waiver_templates SET title = ?, body = ?, updated_at = UTC_TIMESTAMP(), created_by = COALESCE(created_by, ?) WHERE id = ? AND published_at IS NULL")
        ->execute([$title, $body, $uid, $d['id']]);
}

/**
 * Publish the draft as the next version. $effective: 'Y-m-d' farm date (today or later), from the start of that day.
 * $reviewedNote: who reviewed it, e.g. "Jane Doe, attorney, Nov 20, 2026". Administrators only (checked by the page).
 */
function bcrf_waiver_publish(PDO $pdo, string $effective, string $reviewedNote, bool $attorneyReviewed, ?int $uid): int {
    if (!$attorneyReviewed) throw new BcrfWaiverError('Tick the box to confirm an NC attorney reviewed this wording.');
    $reviewedNote = mb_substr(trim(strip_tags($reviewedNote)), 0, 255);
    if ($reviewedNote === '') throw new BcrfWaiverError('Say who reviewed it and when.');
    $tz = new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $effective, $tz);
    $today = new DateTimeImmutable('today', $tz);
    if (!$d || $d->format('Y-m-d') !== $effective) throw new BcrfWaiverError('Pick the date it takes effect.');
    if ($d < $today) throw new BcrfWaiverError('It can\'t take effect in the past.');
    $draft = bcrf_waiver_draft($pdo);
    if (str_starts_with(trim($draft['body']), 'PLACEHOLDER')) throw new BcrfWaiverError('Replace the placeholder with the reviewed wording first.');
    $pdo->beginTransaction();
    try {
        $v = (int)$pdo->query("SELECT COALESCE(MAX(version), 0) + 1 FROM waiver_templates FOR UPDATE")->fetchColumn();
        $pdo->prepare("UPDATE waiver_templates SET version = ?, effective_at = ?, published_at = UTC_TIMESTAMP(), published_by = ?, reviewed_note = ?
                       WHERE id = ? AND published_at IS NULL")
            ->execute([$v, $d->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s'), $uid, $reviewedNote, $draft['id']]);
        $pdo->commit();
    } catch (Throwable $e) { if ($pdo->inTransaction()) $pdo->rollBack(); throw $e; }
    return $v;
}

// ── Signing ────────────────────────────────────────────────────────────────────────────────────

/** "Ava, 7" / "Ben 4" / "Cara" per line → [['name' => 'Ava', 'age' => '7'], …] (at most 20). */
function bcrf_waiver_parse_minors(string $text): array {
    $out = [];
    foreach (preg_split('/\R/', $text) as $line) {
        $line = trim(strip_tags($line));
        if ($line === '') continue;
        $age = '';
        if (preg_match('/^(.*?)[\s,;:-]+(\d{1,2})\s*(?:y(?:ea)?rs?(?: old)?|yo)?\.?$/i', $line, $m)) { $line = trim($m[1]); $age = $m[2]; }
        if ($line === '') continue;
        $out[] = ['name' => mb_substr($line, 0, 60), 'age' => $age];
        if (count($out) >= 20) break;
    }
    return $out;
}

/** A drawn signature from the pad: 'data:image/png;base64,…' → base64, or '' when none / not a small PNG. */
function bcrf_waiver_clean_png(string $dataUrl): string {
    if (!preg_match('#^data:image/png;base64,([A-Za-z0-9+/=]+)$#', trim($dataUrl), $m)) return '';
    $bin = base64_decode($m[1], true);
    if ($bin === false || strlen($bin) < 100 || strlen($bin) > 300000 || substr($bin, 0, 8) !== "\x89PNG\r\n\x1a\n") return '';
    return base64_encode($bin);
}

/**
 * Record a signature. $in: name, email, phone, minors (text), typed (signature), agree, signature (data URL, optional),
 * booking_id, event_inquiry_id. $via: online | gate | portal. Returns the new id.
 */
function bcrf_waiver_sign(PDO $pdo, array $in, string $via, ?int $staffUid = null, ?array $client = null): int {
    $t = bcrf_waiver_current($pdo);
    if (!$t) throw new BcrfWaiverError('Waivers aren\'t open for signing yet.');
    if (!isset(BCRF_WAIVER_VIA[$via])) $via = 'online';
    $name = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($in['name'] ?? '')))), 0, 120);
    $email = bcrf_waiver_norm_email((string)($in['email'] ?? ''));
    $phone = mb_substr(trim(strip_tags((string)($in['phone'] ?? ''))), 0, 30);
    $typed = mb_substr(trim(preg_replace('/\s+/', ' ', strip_tags((string)($in['typed'] ?? '')))), 0, 120);
    if ($name === '' || !str_contains($name, ' ')) throw new BcrfWaiverError('Type your first and last name.');
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) throw new BcrfWaiverError('Type a working email address.');
    if (empty($in['adult'])) throw new BcrfWaiverError('Confirm you are 18 or older.');
    if (empty($in['agree'])) throw new BcrfWaiverError('Tick the box to say you have read and agree to the waiver.');
    if ($typed === '' || mb_strtolower($typed) !== mb_strtolower($name)) throw new BcrfWaiverError('Type your full name as your signature, the same as above.');
    $minors = bcrf_waiver_parse_minors((string)($in['minors'] ?? ''));
    $png = bcrf_waiver_clean_png((string)($in['signature'] ?? ''));
    if ($via === 'gate' && $png === '') throw new BcrfWaiverError('Sign with your finger in the box.');
    $cid = null;
    try { if (!function_exists('bcrf_crm_find_or_create')) require_once __DIR__ . '/crm.php'; if (bcrf_crm_ready($pdo)) $cid = bcrf_crm_find_or_create($pdo, $email, $name, $phone) ?: null; }
    catch (Throwable $e) { $cid = null; }
    $months = bcrf_waiver_valid_months($pdo);
    $client ??= ['ip' => (string)($_SERVER['REMOTE_ADDR'] ?? ''), 'ua' => (string)($_SERVER['HTTP_USER_AGENT'] ?? '')];
    $pdo->prepare("INSERT INTO waivers (template_id, body_sha256, customer_id, signer_name, signer_email, signer_phone, minors_json, typed_signature, signature_png,
                                        via, staff_user_id, booking_id, event_inquiry_id, ip, user_agent, signed_at, expires_at)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), UTC_TIMESTAMP() + INTERVAL $months MONTH)")
        ->execute([$t['id'], bcrf_waiver_hash($t), $cid, $name, $email, $phone !== '' ? $phone : null, $minors ? json_encode($minors, JSON_UNESCAPED_UNICODE) : null,
                   $typed, $png !== '' ? $png : null, $via, $via === 'gate' ? $staffUid : null,
                   (int)($in['booking_id'] ?? 0) ?: null, (int)($in['event_inquiry_id'] ?? 0) ?: null,
                   mb_substr($client['ip'], 0, 45) ?: null, mb_substr($client['ua'], 0, 255) ?: null]);
    $id = (int)$pdo->lastInsertId();
    if (function_exists('bcrf_event')) bcrf_event('waiver.signed', 'waivers', $id, ['via' => $via, 'minors' => count($minors)]);
    return $id;
}

function bcrf_waiver_get(PDO $pdo, int $id): ?array {
    $st = $pdo->prepare("SELECT w.*, t.version, t.title, t.body FROM waivers w JOIN waiver_templates t ON t.id = w.template_id WHERE w.id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) return null;
    $r['minors'] = json_decode((string)$r['minors_json'], true) ?: [];
    return $r;
}

/** The newest signature for an email that's still good, or null. */
function bcrf_waiver_valid_for(PDO $pdo, ?string $email): ?array {
    $email = bcrf_waiver_norm_email($email);
    if ($email === '' || !bcrf_waivers_ready($pdo)) return null;
    $st = $pdo->prepare("SELECT id FROM waivers WHERE signer_email = ? AND voided_at IS NULL AND expires_at > UTC_TIMESTAMP() ORDER BY signed_at DESC LIMIT 1");
    $st->execute([$email]);
    $id = (int)$st->fetchColumn();
    return $id ? bcrf_waiver_get($pdo, $id) : null;
}

/** What the gate shows for a party: null when waivers are off; else signed, label, and the link to sign. */
function bcrf_waiver_badge(PDO $pdo, ?string $email, string $name = '', ?string $mode = null): ?array {
    $mode ??= bcrf_waiver_mode($pdo);
    if ($mode === 'off') return null;
    $w = bcrf_waiver_valid_for($pdo, $email);
    if ($w) {
        $kids = count($w['minors']);
        return ['signed' => true, 'block' => false, 'label' => 'Waiver signed by ' . $w['signer_name'] . ($kids ? ' (+' . $kids . ' child' . ($kids === 1 ? '' : 'ren') . ')' : '')
                . ', good until ' . date('M j, Y', strtotime($w['expires_at'] . ' UTC')), 'sign_url' => ''];
    }
    $q = http_build_query(array_filter(['gate' => 1, 'email' => bcrf_waiver_norm_email($email), 'name' => $name]));
    return ['signed' => false, 'block' => $mode === 'block', 'label' => 'No waiver signed', 'sign_url' => 'waiver_sign.php?' . $q];   // relative: the gate is in admin/
}

/**
 * The gate refuses a check-in in block mode when no waiver is on file, unless a manager gives a reason.
 * Returns the reason to record ('' when none was needed). Throws BcrfWaiverError otherwise.
 */
function bcrf_waiver_gate_check(PDO $pdo, ?string $email, string $override, bool $canOverride): string {
    if (bcrf_waiver_mode($pdo) !== 'block' || bcrf_waiver_valid_for($pdo, $email)) return '';
    $override = mb_substr(trim(strip_tags($override)), 0, 200);
    if ($override !== '' && $canOverride) return $override;
    if ($override !== '') $GLOBALS['bcrf_mgr_needed'] = true;   // a manager's PIN would let them in (includes/pin_auth.php)
    throw new BcrfWaiverError('No waiver on file. Have the adult sign on this device first.');
}

function bcrf_waiver_void(PDO $pdo, int $id, string $reason, ?int $uid): void {
    $reason = mb_substr(trim(strip_tags($reason)), 0, 255);
    if ($reason === '') throw new BcrfWaiverError('Say why you are voiding it.');
    $st = $pdo->prepare("UPDATE waivers SET voided_at = UTC_TIMESTAMP(), voided_by = ?, void_reason = ? WHERE id = ? AND voided_at IS NULL");
    $st->execute([$uid, $reason, $id]);
    if (!$st->rowCount()) throw new BcrfWaiverError('That waiver is already void or gone.');
}

/** Signatures, newest first; $q matches name, email, phone or a child's name. */
function bcrf_waiver_search(PDO $pdo, string $q = '', int $limit = 100): array {
    $sql = "SELECT w.id, w.signer_name, w.signer_email, w.signer_phone, w.minors_json, w.via, w.signed_at, w.expires_at, w.voided_at, w.customer_id, t.version
            FROM waivers w JOIN waiver_templates t ON t.id = w.template_id";
    $args = [];
    $q = trim($q);
    if ($q !== '') {
        $like = '%' . str_replace(['\\', '%', '_'], ['\\\\', '\\%', '\\_'], $q) . '%';
        $sql .= " WHERE w.signer_name LIKE ? OR w.signer_email LIKE ? OR w.signer_phone LIKE ? OR w.minors_json LIKE ?";
        $args = [$like, $like, $like, $like];
    }
    $st = $pdo->prepare($sql . " ORDER BY w.signed_at DESC, w.id DESC LIMIT " . max(1, min(500, $limit)));
    $st->execute($args);
    return array_map(function ($r) { $r['minors'] = json_decode((string)$r['minors_json'], true) ?: []; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** A customer's signatures (by customer id or email), newest first. */
function bcrf_waiver_for_customer(PDO $pdo, int $customerId, string $email): array {
    if (!bcrf_waivers_ready($pdo)) return [];
    $st = $pdo->prepare("SELECT w.id, w.signer_name, w.minors_json, w.signed_at, w.expires_at, w.voided_at, t.version
                         FROM waivers w JOIN waiver_templates t ON t.id = w.template_id
                         WHERE w.customer_id = ? OR w.signer_email = ? ORDER BY w.signed_at DESC LIMIT 20");
    $st->execute([$customerId, bcrf_waiver_norm_email($email)]);
    return array_map(function ($r) { $r['minors'] = json_decode((string)$r['minors_json'], true) ?: []; return $r; }, $st->fetchAll(PDO::FETCH_ASSOC));
}

/** The signer's copy, emailed after signing. [subject, inner HTML]. */
function bcrf_waiver_copy_email(array $w): array {
    $h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
    $kids = $w['minors'] ? implode(', ', array_map(fn($m) => $m['name'] . ($m['age'] !== '' ? ' (' . $m['age'] . ')' : ''), $w['minors'])) : 'None listed';
    $inner = '<h1 style="font-family:Georgia,serif;color:#f5ead6;">Your signed waiver</h1>'
        . '<p style="color:#e8d5b0;">Thanks, ' . $h(explode(' ', $w['signer_name'])[0]) . '. Here is a copy for your records. It\'s good until '
        . $h(date('F j, Y', strtotime($w['expires_at'] . ' UTC'))) . '.</p>'
        . '<p style="color:#c9b48f;">Signed ' . $h(date('F j, Y g:i A', strtotime($w['signed_at'] . ' UTC'))) . ' · version ' . (int)$w['version'] . ' · children: ' . $h($kids) . '</p>'
        . '<h2 style="font-family:Georgia,serif;color:#f5ead6;font-size:18px;">' . $h($w['title']) . '</h2>'
        . implode('', array_map(fn($p) => '<p style="color:#e8d5b0;">' . nl2br($h($p)) . '</p>', bcrf_waiver_paragraphs($w['body'])))
        . '<p style="color:#e8d5b0;">Signed: <strong>' . $h($w['typed_signature']) . '</strong></p>';
    return ['Your signed waiver — ' . (function_exists('bcrf_farm_name') ? bcrf_farm_name() : 'Bat Cave Road Farm'), $inner];
}

} // function_exists guard
