<?php
// includes/incidents.php — Farm OS Phase 5 slice 8: incidents (Nov 12, 2026; migration 0047; 08 › Incidents).
//   Any staff member can report one from a phone: type, when, where, what happened, what was done, severity, animals,
//   visitors (with contact details), staff, witnesses, photos, follow-up.
//   Saving emails the Owners right away and puts a high-priority follow-up task on the Owners' list for today.
//   Visitors are matched to a customer account, a waiver valid at the time (by email, or by signer or child's name),
//   that day's check-in (ticket or pass email), and the waiver's booking or party.
//   Never deleted. Every change after the report is written to incident_edits (and the audit log by the page).
//   Seeing incidents needs the 'incidents' view (Owners always; Managers by default). Whoever reported one can
//   always see their own report. Editing, adding to and closing need the view.
if (!function_exists('bcrf_incidents_ready')) {

class BcrfIncidentError extends RuntimeException {}

define('BCRF_INCIDENT_TYPES', ['visitor_injury' => 'Visitor hurt', 'animal_injury' => 'Animal hurt', 'escape' => 'Animal got out',
                               'property' => 'Property damage', 'other' => 'Other']);
define('BCRF_INCIDENT_INJURIES', ['bite' => 'Bite', 'scratch' => 'Scratch', 'fall' => 'Fall', 'other' => 'Other']);
define('BCRF_INCIDENT_SEVERITY', ['minor' => 'Minor', 'moderate' => 'Needed care', 'serious' => 'Serious']);
define('BCRF_INCIDENT_SEVERITY_HELP', ['minor' => 'first aid at most', 'moderate' => 'needed more than first aid', 'serious' => 'left for a doctor or called 911']);
define('BCRF_INCIDENT_FIELDS', ['happened_at' => 'When', 'type' => 'Type', 'injury' => 'Injury', 'severity' => 'Severity', 'location' => 'Where',
                                'description' => 'What happened', 'action_taken' => 'What was done', 'staff_involved' => 'Staff',
                                'witnesses' => 'Witnesses', 'follow_up' => 'Follow-up', 'animals' => 'Animals']);
define('BCRF_INCIDENT_PHOTO_MAX', 8);
define('BCRF_INCIDENT_PHOTO_BYTES', 15 * 1024 * 1024);
define('BCRF_INCIDENT_PEOPLE_MAX', 10);

function bcrf_incidents_ready(PDO $pdo): bool {
    try { $pdo->query("SELECT 1 FROM incident_edits LIMIT 0"); return true; }
    catch (Throwable $e) { return false; }
}

/** Can this person see every incident (and change them)? */
function bcrf_incidents_can(): bool {
    $role = (string)($_SESSION['user_role'] ?? '');
    if ($role === 'administrator') return true;
    return function_exists('hasViewAccess') && hasViewAccess($role, 'incidents');
}

function bcrf_incident_uid(): ?int { $u = (int)($_SESSION['user_id'] ?? 0); return $u ?: null; }

function bcrf_incident_tz(): DateTimeZone { return new DateTimeZone(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York'); }

function bcrf_incident_now_local(): DateTimeImmutable { return new DateTimeImmutable('now', bcrf_incident_tz()); }

/** UTC "Y-m-d H:i:s" → farm-time DateTimeImmutable. */
function bcrf_incident_local(string $utc): DateTimeImmutable {
    return (new DateTimeImmutable($utc, new DateTimeZone('UTC')))->setTimezone(bcrf_incident_tz());
}

/** Farm date + "14:30" → UTC "Y-m-d H:i:s". Not in the future. */
function bcrf_incident_when(string $date, string $time): string {
    $date = trim($date); $time = trim($time);
    $d = DateTimeImmutable::createFromFormat('!Y-m-d', $date, bcrf_incident_tz());
    if ($date === '' || !$d || $d->format('Y-m-d') !== $date) throw new BcrfIncidentError('Pick the day it happened.');
    if (!preg_match('/^([01]?\d|2[0-3]):([0-5]\d)$/', $time, $m)) throw new BcrfIncidentError('Pick the time it happened (a guess is fine).');
    $local = $d->setTime((int)$m[1], (int)$m[2]);
    if ($local > bcrf_incident_now_local()->modify('+15 minutes')) throw new BcrfIncidentError('That time is in the future.');
    if ($local < bcrf_incident_now_local()->modify('-2 years')) throw new BcrfIncidentError('That date is more than two years ago.');
    return $local->setTimezone(new DateTimeZone('UTC'))->format('Y-m-d H:i:s');
}

function bcrf_incident_text($v, int $max): ?string {
    $v = trim(str_replace("\r\n", "\n", (string)$v));
    return $v === '' ? null : mb_substr($v, 0, $max);
}

/** Check the report's own fields. Returns the row to save (plus 'animals' => [ids]). */
function bcrf_incident_clean(PDO $pdo, array $in): array {
    $type = (string)($in['type'] ?? '');
    if (!isset(BCRF_INCIDENT_TYPES[$type])) throw new BcrfIncidentError('Pick what kind of incident it was.');
    $injury = (string)($in['injury'] ?? '');
    $injury = $type === 'visitor_injury' && isset(BCRF_INCIDENT_INJURIES[$injury]) ? $injury : null;
    $sev = (string)($in['severity'] ?? '');
    if (!isset(BCRF_INCIDENT_SEVERITY[$sev])) throw new BcrfIncidentError('Pick how serious it was.');
    $loc = bcrf_incident_text($in['location'] ?? '', 120);
    if ($loc === null) throw new BcrfIncidentError('Say where it happened.');
    $desc = bcrf_incident_text($in['description'] ?? '', 5000);
    if ($desc === null) throw new BcrfIncidentError('Say what happened.');
    $animals = [];
    foreach ((array)($in['animals'] ?? []) as $a) { $a = (int)$a; if ($a > 0) $animals[$a] = $a; }
    if ($animals) {
        $st = $pdo->prepare("SELECT id FROM animals WHERE id IN (" . implode(',', array_fill(0, count($animals), '?')) . ")");
        $st->execute(array_values($animals));
        if (count($st->fetchAll(PDO::FETCH_COLUMN)) !== count($animals)) throw new BcrfIncidentError('One of those animals was not found. Reload the page.');
    }
    if (!$animals && in_array($type, ['animal_injury', 'escape'], true)) throw new BcrfIncidentError('Pick the animal (or animals) involved.');
    sort($animals);
    return [
        'happened_at'    => bcrf_incident_when((string)($in['date'] ?? ''), (string)($in['time'] ?? '')),
        'type'           => $type,
        'injury'         => $injury,
        'severity'       => $sev,
        'location'       => $loc,
        'description'    => $desc,
        'action_taken'   => bcrf_incident_text($in['action_taken'] ?? '', 5000),
        'staff_involved' => bcrf_incident_text($in['staff_involved'] ?? '', 255),
        'witnesses'      => bcrf_incident_text($in['witnesses'] ?? '', 2000),
        'follow_up'      => bcrf_incident_text($in['follow_up'] ?? '', 2000),
        'animals'        => array_values($animals),
    ];
}

/** Visitors from the form's parallel lists person_name[], person_phone[], person_email[], person_note[]. Blank rows skipped. */
function bcrf_incident_people_in(array $in): array {
    $names = (array)($in['person_name'] ?? []);
    $out = [];
    foreach ($names as $i => $name) {
        $name  = bcrf_incident_text($name, 120);
        $phone = bcrf_incident_text(($in['person_phone'] ?? [])[$i] ?? '', 30);
        $email = bcrf_incident_text(($in['person_email'] ?? [])[$i] ?? '', 190);
        $note  = bcrf_incident_text(($in['person_note'] ?? [])[$i] ?? '', 255);
        if ($name === null && $phone === null && $email === null && $note === null) continue;
        if ($name === null) throw new BcrfIncidentError('Give each visitor a name (a first name is fine).');
        if ($email !== null) {
            $email = strtolower($email);
            if (!filter_var($email, FILTER_VALIDATE_EMAIL)) throw new BcrfIncidentError('Check the email for ' . $name . '.');
        }
        if ($phone !== null && !preg_match('/^[0-9 ()+.\-]{7,30}$/', $phone)) throw new BcrfIncidentError('Check the phone number for ' . $name . '.');
        $out[] = ['name' => $name, 'phone' => $phone, 'email' => $email, 'note' => $note];
    }
    if (count($out) > BCRF_INCIDENT_PEOPLE_MAX) throw new BcrfIncidentError('Up to ' . BCRF_INCIDENT_PEOPLE_MAX . ' visitors on one report.');
    return $out;
}

function bcrf_incident_has_table(PDO $pdo, string $t): bool {
    static $c = [];
    if (!isset($c[$t]) || !empty($GLOBALS['bcrf_incidents_nocache'])) {
        try { $pdo->query("SELECT 1 FROM `$t` LIMIT 0"); $c[$t] = true; } catch (Throwable $e) { $c[$t] = false; }
    }
    return $c[$t];
}

/**
 * Find the records a visitor goes with, at the time of the incident: [customer_id, waiver_id, check_in_id, booking_id,
 * event_inquiry_id] (each may be null). A waiver counts if it was signed before the incident, had not run out and was
 * not voided; it matches by email, else by the signer's name or a child's name on it.
 */
function bcrf_incident_links(PDO $pdo, string $happenedUtc, string $name, ?string $email): array {
    $r = ['customer_id' => null, 'waiver_id' => null, 'check_in_id' => null, 'booking_id' => null, 'event_inquiry_id' => null];
    $email = $email !== null ? strtolower(trim($email)) : '';
    if (bcrf_incident_has_table($pdo, 'waivers')) {
        $w = null;
        if ($email !== '') {
            $st = $pdo->prepare("SELECT * FROM waivers WHERE LOWER(signer_email) = ? AND signed_at <= ? AND expires_at >= ? AND voided_at IS NULL
                                 ORDER BY signed_at DESC LIMIT 1");
            $st->execute([$email, $happenedUtc, $happenedUtc]);
            $w = $st->fetch(PDO::FETCH_ASSOC) ?: null;
        }
        $want = mb_strtolower(preg_replace('/\s+/', ' ', trim($name)));
        if (!$w && $want !== '') {
            $st = $pdo->prepare("SELECT * FROM waivers WHERE signed_at <= ? AND expires_at >= ? AND voided_at IS NULL ORDER BY signed_at DESC LIMIT 2000");
            $st->execute([$happenedUtc, $happenedUtc]);
            foreach ($st->fetchAll(PDO::FETCH_ASSOC) as $row) {
                $names = [(string)$row['signer_name']];
                foreach ((array)json_decode((string)($row['minors_json'] ?? ''), true) as $m) if (is_array($m)) $names[] = (string)($m['name'] ?? '');
                foreach ($names as $n) if (mb_strtolower(preg_replace('/\s+/', ' ', trim($n))) === $want) { $w = $row; break 2; }
            }
        }
        if ($w) {
            $r['waiver_id'] = (int)$w['id'];
            $r['booking_id'] = $w['booking_id'] !== null ? (int)$w['booking_id'] : null;
            $r['event_inquiry_id'] = $w['event_inquiry_id'] !== null ? (int)$w['event_inquiry_id'] : null;
            if ($w['customer_id'] !== null) $r['customer_id'] = (int)$w['customer_id'];
            if ($email === '') $email = strtolower(trim((string)$w['signer_email']));   // a child found by name: use the parent's email below
        }
    }
    if ($r['customer_id'] === null && $email !== '' && bcrf_incident_has_table($pdo, 'customers')) {
        $st = $pdo->prepare("SELECT id FROM customers WHERE LOWER(email) = ? LIMIT 1");
        $st->execute([$email]);
        $r['customer_id'] = ($v = $st->fetchColumn()) !== false ? (int)$v : null;
    }
    if ($email !== '' && bcrf_incident_has_table($pdo, 'check_ins')) {
        $day = bcrf_incident_local($happenedUtc)->format('Y-m-d');
        $parts = []; $args = [];
        if (bcrf_incident_has_table($pdo, 'tickets')) { $parts[] = "c.ticket_id IN (SELECT id FROM tickets WHERE LOWER(purchaser_email) = ?)"; $args[] = $email; }
        if (bcrf_incident_has_table($pdo, 'passes'))  { $parts[] = "c.pass_id IN (SELECT id FROM passes WHERE LOWER(email) = ?)"; $args[] = $email; }
        if ($parts) {
            $st = $pdo->prepare("SELECT c.id FROM check_ins c WHERE c.business_date = ? AND c.checked_in_at <= ? AND (" . implode(' OR ', $parts) . ")
                                 ORDER BY c.checked_in_at DESC LIMIT 1");
            $st->execute(array_merge([$day, $happenedUtc], $args));
            $r['check_in_id'] = ($v = $st->fetchColumn()) !== false ? (int)$v : null;
        }
    }
    return $r;
}

function bcrf_incident_person_insert(PDO $pdo, int $id, string $happenedUtc, array $p, ?int $uid): int {
    $l = bcrf_incident_links($pdo, $happenedUtc, $p['name'], $p['email']);
    $pdo->prepare("INSERT INTO incident_people (incident_id, name, phone, email, note, customer_id, waiver_id, check_in_id, booking_id, event_inquiry_id, created_at, created_by)
                   VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$id, $p['name'], $p['phone'], $p['email'], $p['note'], $l['customer_id'], $l['waiver_id'], $l['check_in_id'], $l['booking_id'], $l['event_inquiry_id'], $uid]);
    return (int)$pdo->lastInsertId();
}

/**
 * Save a new report. $files is the photos[] upload (or []). Returns ['id', 'warnings' => [..], 'notified' => n, 'task_id'].
 * The report is saved first; a photo, the email or the task failing only adds a warning.
 */
function bcrf_incident_add(PDO $pdo, array $in, array $files, ?int $uid): array {
    $c = bcrf_incident_clean($pdo, $in);
    $people = bcrf_incident_people_in($in);
    if ($c['type'] === 'visitor_injury' && !$people) throw new BcrfIncidentError('Add the visitor who was hurt (a name is enough; contact details if you have them).');
    $pdo->beginTransaction();
    try {
        $pdo->prepare("INSERT INTO incidents (happened_at, type, injury, severity, location, description, action_taken, staff_involved, witnesses, follow_up,
                           status, created_at, created_by) VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 'open', UTC_TIMESTAMP(), ?)")
            ->execute([$c['happened_at'], $c['type'], $c['injury'], $c['severity'], $c['location'], $c['description'], $c['action_taken'],
                       $c['staff_involved'], $c['witnesses'], $c['follow_up'], $uid]);
        $id = (int)$pdo->lastInsertId();
        $ins = $pdo->prepare("INSERT INTO incident_animals (incident_id, animal_id) VALUES (?, ?)");
        foreach ($c['animals'] as $a) $ins->execute([$id, $a]);
        foreach ($people as $p) bcrf_incident_person_insert($pdo, $id, $c['happened_at'], $p, $uid);
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }

    $warnings = [];
    foreach (bcrf_incident_files($files) as $f) {
        try { bcrf_incident_photo_add($pdo, $id, $f, $uid, false); }
        catch (BcrfIncidentError $e) { $warnings[] = 'A photo was not saved: ' . $e->getMessage(); }
        catch (Throwable $e) { error_log('[incidents] photo: ' . $e->getMessage()); $warnings[] = 'A photo was not saved.'; }
    }
    $taskId = null;
    try { $taskId = bcrf_incident_make_task($pdo, $id); }
    catch (Throwable $e) { error_log('[incidents] task: ' . $e->getMessage()); $warnings[] = 'The follow-up task was not made.'; }
    $sent = 0;
    try { $sent = bcrf_incident_notify($pdo, $id); }
    catch (Throwable $e) { error_log('[incidents] notify: ' . $e->getMessage()); }
    if (!$sent) $warnings[] = 'The Owners were not emailed. Call or text them.';
    if (function_exists('bcrf_event')) bcrf_event('incident.reported', 'incidents', $id, ['type' => $c['type'], 'severity' => $c['severity']]);
    return ['id' => $id, 'warnings' => $warnings, 'notified' => $sent, 'task_id' => $taskId];
}

/** $_FILES['photos'] (multiple) → list of single-file arrays; empty slots dropped. */
function bcrf_incident_files(array $f): array {
    if (!isset($f['name'])) return array_values(array_filter($f, 'is_array'));
    if (!is_array($f['name'])) return ((int)($f['error'] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) ? [] : [$f];
    $out = [];
    foreach ($f['name'] as $i => $n) {
        if ((int)($f['error'][$i] ?? UPLOAD_ERR_NO_FILE) === UPLOAD_ERR_NO_FILE) continue;
        $out[] = ['name' => $n, 'tmp_name' => $f['tmp_name'][$i] ?? '', 'error' => $f['error'][$i] ?? UPLOAD_ERR_NO_FILE, 'size' => $f['size'][$i] ?? 0];
    }
    return $out;
}

/** High-priority task for the Owners, due today, linked to the incident. Returns its id, or null without Tasks. */
function bcrf_incident_make_task(PDO $pdo, int $id): ?int {
    if (!function_exists('bcrf_task_create')) { if (is_file(__DIR__ . '/tasks.php')) require_once __DIR__ . '/tasks.php'; }
    if (!function_exists('bcrf_task_create') || !bcrf_tasks_ready($pdo)) return null;
    $i = bcrf_incident_get($pdo, $id);
    $what = BCRF_INCIDENT_TYPES[$i['type']] . ($i['injury'] ? ' (' . strtolower(BCRF_INCIDENT_INJURIES[$i['injury']]) . ')' : '');
    $tid = bcrf_task_create($pdo, [
        'title' => 'Follow up on incident #' . $id . ': ' . $what,
        'description' => 'Read the report, check on the people and animals involved, note what was done, and close it when it is settled: /admin/incidents.php?id=' . $id,
        'priority' => $i['severity'] === 'serious' ? 'urgent' : 'high',
        'due_date' => bcrf_incident_now_local()->format('Y-m-d'),
        'who' => 'role:administrator',
        'related_type' => 'incident', 'related_id' => (string)$id,
    ]);
    $pdo->prepare("UPDATE incidents SET task_id = ? WHERE id = ?")->execute([$tid, $id]);
    return $tid;
}

/** Active Owners with an email: [[id, name, email]]. */
function bcrf_incident_owners(PDO $pdo): array {
    $today = bcrf_incident_now_local()->format('Y-m-d');
    $st = $pdo->prepare("SELECT id, CONCAT(first_name, ' ', last_name) AS name, email FROM users
                         WHERE role = 'administrator' AND account_status = 'active' AND (date_terminated IS NULL OR date_terminated > ?)
                           AND email IS NOT NULL AND email <> '' ORDER BY id");
    $st->execute([$today]);
    return array_values(array_filter($st->fetchAll(PDO::FETCH_ASSOC), fn($u) => filter_var($u['email'], FILTER_VALIDATE_EMAIL)));
}

/** Email every Owner now (falls back to the queue if the mail server says no). Returns how many were sent or queued. */
function bcrf_incident_notify(PDO $pdo, int $id): int {
    if (!function_exists('bcf_send_mail')) { $f = dirname(__DIR__) . '/admin/includes/email_template.php'; if (is_file($f)) require_once $f; }
    $i = bcrf_incident_get($pdo, $id);
    $e = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
    $what = BCRF_INCIDENT_TYPES[$i['type']] . ($i['injury'] ? ' (' . strtolower(BCRF_INCIDENT_INJURIES[$i['injury']]) . ')' : '');
    $when = bcrf_incident_local($i['happened_at'])->format('D M j, g:i A');
    $url = (function_exists('bcrf_farm_url') ? bcrf_farm_url() : '') . '/admin/incidents.php?id=' . $id;
    $subject = ($i['severity'] === 'serious' ? 'SERIOUS incident: ' : 'Incident: ') . $what . ' — ' . $when;
    $row = fn($k, $v) => $v !== null && $v !== '' ? '<p style="margin:0 0 8px"><strong>' . $e($k) . ':</strong> ' . nl2br($e($v)) . '</p>' : '';
    $html = '<p style="margin:0 0 12px">' . $e($i['created_by_name'] ?: 'Someone') . ' reported an incident.</p>'
          . $row('What', $what) . $row('How serious', BCRF_INCIDENT_SEVERITY[$i['severity']]) . $row('When', $when) . $row('Where', $i['location'])
          . $row('Animals', implode(', ', array_column($i['animals'], 'name'))) . $row('Visitors', (string)count($i['people']) ?: '')
          . $row('What happened', $i['description']) . $row('What was done', $i['action_taken'])
          . '<p style="margin:16px 0 0"><a href="' . $e($url) . '">Open the report</a> (contact details and photos are there). A follow-up task is on the Owners\' list for today.</p>';
    $n = 0;
    foreach (bcrf_incident_owners($pdo) as $o) {
        $ok = false;
        try { $ok = function_exists('bcf_send_mail') && bcf_send_mail((string)$o['email'], $subject, $html, ['subtitle' => 'Incident', 'unsubscribe' => false]); }
        catch (Throwable $ex) { error_log('[incidents] mail: ' . $ex->getMessage()); }
        if (!$ok && function_exists('bcrf_queue_push')) $ok = bcrf_queue_push('email.send', ['to' => $o['email'], 'subject' => $subject, 'html' => $html, 'opts' => ['subtitle' => 'Incident', 'unsubscribe' => false]]) > 0;
        if ($ok) $n++;
    }
    if ($n) $pdo->prepare("UPDATE incidents SET owner_notified_at = UTC_TIMESTAMP() WHERE id = ?")->execute([$id]);
    return $n;
}

function bcrf_incident_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT i.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name, CONCAT(c.first_name, ' ', c.last_name) AS closed_by_name
                         FROM incidents i LEFT JOIN users u ON u.id = i.created_by LEFT JOIN users c ON c.id = i.closed_by WHERE i.id = ?");
    $st->execute([$id]);
    $i = $st->fetch(PDO::FETCH_ASSOC);
    if (!$i) throw new BcrfIncidentError('That incident was not found.');
    $st = $pdo->prepare("SELECT a.id, a.name FROM incident_animals ia JOIN animals a ON a.id = ia.animal_id WHERE ia.incident_id = ? ORDER BY a.name");
    $st->execute([$id]);
    $i['animals'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM incident_people WHERE incident_id = ? ORDER BY id");
    $st->execute([$id]);
    $i['people'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT * FROM incident_photos WHERE incident_id = ? ORDER BY id");
    $st->execute([$id]);
    $i['photos'] = $st->fetchAll(PDO::FETCH_ASSOC);
    $st = $pdo->prepare("SELECT e.*, CONCAT(u.first_name, ' ', u.last_name) AS by_name FROM incident_edits e LEFT JOIN users u ON u.id = e.edited_by
                         WHERE e.incident_id = ? ORDER BY e.id");
    $st->execute([$id]);
    $i['edits'] = $st->fetchAll(PDO::FETCH_ASSOC);
    return $i;
}

/** Can the current person open this report? Everyone with the view; the reporter for their own. */
function bcrf_incident_can_see(array $i): bool {
    return bcrf_incidents_can() || ((int)($i['created_by'] ?? 0) > 0 && (int)$i['created_by'] === (int)bcrf_incident_uid());
}

/** Newest first. $f: status (open|closed|''), type, mine (user id: only their reports), animal (id). */
function bcrf_incident_list(PDO $pdo, array $f = [], int $limit = 200): array {
    $w = []; $a = [];
    if (in_array($f['status'] ?? '', ['open', 'closed'], true)) { $w[] = 'i.status = ?'; $a[] = $f['status']; }
    if (isset(BCRF_INCIDENT_TYPES[$f['type'] ?? ''])) { $w[] = 'i.type = ?'; $a[] = $f['type']; }
    if (!empty($f['mine'])) { $w[] = 'i.created_by = ?'; $a[] = (int)$f['mine']; }
    if (!empty($f['animal'])) { $w[] = 'i.id IN (SELECT incident_id FROM incident_animals WHERE animal_id = ?)'; $a[] = (int)$f['animal']; }
    $st = $pdo->prepare("SELECT i.*, CONCAT(u.first_name, ' ', u.last_name) AS created_by_name,
                                (SELECT GROUP_CONCAT(an.name ORDER BY an.name SEPARATOR ', ') FROM incident_animals ia JOIN animals an ON an.id = ia.animal_id WHERE ia.incident_id = i.id) AS animal_names,
                                (SELECT COUNT(*) FROM incident_people p WHERE p.incident_id = i.id) AS people_count
                         FROM incidents i LEFT JOIN users u ON u.id = i.created_by"
                       . ($w ? ' WHERE ' . implode(' AND ', $w) : '') . " ORDER BY i.happened_at DESC, i.id DESC LIMIT " . max(1, min(1000, $limit)));
    $st->execute($a);
    return $st->fetchAll(PDO::FETCH_ASSOC);
}

function bcrf_incident_open_count(PDO $pdo): int {
    try { return (int)$pdo->query("SELECT COUNT(*) FROM incidents WHERE status = 'open'")->fetchColumn(); }
    catch (Throwable $e) { return 0; }
}

function bcrf_incident_log(PDO $pdo, int $id, string $field, $old, $new, ?int $uid): void {
    $pdo->prepare("INSERT INTO incident_edits (incident_id, field, old_value, new_value, edited_at, edited_by) VALUES (?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$id, $field, $old === null ? null : (string)$old, $new === null ? null : (string)$new, $uid]);
}

/** Change the report. Each changed field is logged with its old and new value. Returns the fields that changed. */
function bcrf_incident_update(PDO $pdo, int $id, array $in, ?int $uid): array {
    if (!bcrf_incidents_can()) throw new BcrfIncidentError('Only Owners and Managers can change a report.');
    $c = bcrf_incident_clean($pdo, $in);
    $pdo->beginTransaction();
    try {
        $st = $pdo->prepare("SELECT * FROM incidents WHERE id = ? FOR UPDATE");
        $st->execute([$id]);
        $old = $st->fetch(PDO::FETCH_ASSOC);
        if (!$old) throw new BcrfIncidentError('That incident was not found.');
        $st = $pdo->prepare("SELECT animal_id FROM incident_animals WHERE incident_id = ? ORDER BY animal_id");
        $st->execute([$id]);
        $oldAnimals = array_map('intval', $st->fetchAll(PDO::FETCH_COLUMN));
        $changed = [];
        foreach (['happened_at', 'type', 'injury', 'severity', 'location', 'description', 'action_taken', 'staff_involved', 'witnesses', 'follow_up'] as $k) {
            if ((string)($old[$k] ?? '') !== (string)($c[$k] ?? '')) { $changed[] = $k; bcrf_incident_log($pdo, $id, $k, $old[$k], $c[$k], $uid); }
        }
        if ($oldAnimals !== $c['animals']) {
            $changed[] = 'animals';
            bcrf_incident_log($pdo, $id, 'animals', bcrf_incident_animal_names($pdo, $oldAnimals), bcrf_incident_animal_names($pdo, $c['animals']), $uid);
            $pdo->prepare("DELETE FROM incident_animals WHERE incident_id = ?")->execute([$id]);
            $ins = $pdo->prepare("INSERT INTO incident_animals (incident_id, animal_id) VALUES (?, ?)");
            foreach ($c['animals'] as $a) $ins->execute([$id, $a]);
        }
        if ($changed) {
            $pdo->prepare("UPDATE incidents SET happened_at = ?, type = ?, injury = ?, severity = ?, location = ?, description = ?, action_taken = ?,
                               staff_involved = ?, witnesses = ?, follow_up = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
                ->execute([$c['happened_at'], $c['type'], $c['injury'], $c['severity'], $c['location'], $c['description'], $c['action_taken'],
                           $c['staff_involved'], $c['witnesses'], $c['follow_up'], $uid, $id]);
        }
        $pdo->commit();
    } catch (Throwable $e) { $pdo->rollBack(); throw $e; }
    return $changed;
}

function bcrf_incident_animal_names(PDO $pdo, array $ids): string {
    if (!$ids) return '';
    $st = $pdo->prepare("SELECT name FROM animals WHERE id IN (" . implode(',', array_fill(0, count($ids), '?')) . ") ORDER BY name");
    $st->execute(array_values($ids));
    return implode(', ', $st->fetchAll(PDO::FETCH_COLUMN));
}

/** Add a visitor to a saved report (logged). Returns the person's row id. */
function bcrf_incident_add_person(PDO $pdo, int $id, array $in, ?int $uid): int {
    if (!bcrf_incidents_can()) throw new BcrfIncidentError('Only Owners and Managers can change a report.');
    $i = bcrf_incident_get($pdo, $id);
    $p = bcrf_incident_people_in(['person_name' => [$in['name'] ?? ''], 'person_phone' => [$in['phone'] ?? ''], 'person_email' => [$in['email'] ?? ''], 'person_note' => [$in['note'] ?? '']]);
    if (!$p) throw new BcrfIncidentError('Give the visitor a name.');
    if (count($i['people']) >= BCRF_INCIDENT_PEOPLE_MAX) throw new BcrfIncidentError('Up to ' . BCRF_INCIDENT_PEOPLE_MAX . ' visitors on one report.');
    $pid = bcrf_incident_person_insert($pdo, $id, $i['happened_at'], $p[0], $uid);
    bcrf_incident_log($pdo, $id, 'person', null, trim($p[0]['name'] . ' ' . ($p[0]['phone'] ?? '') . ' ' . ($p[0]['email'] ?? '')), $uid);
    $pdo->prepare("UPDATE incidents SET updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$uid, $id]);
    return $pid;
}

/** Close (with what settled it) or reopen. Logged. */
function bcrf_incident_set_status(PDO $pdo, int $id, string $to, string $note, ?int $uid): void {
    if (!bcrf_incidents_can()) throw new BcrfIncidentError('Only Owners and Managers can close a report.');
    $i = bcrf_incident_get($pdo, $id);
    $note = (string)bcrf_incident_text($note, 500);
    if ($to === 'closed') {
        if ($i['status'] === 'closed') throw new BcrfIncidentError('It is already closed.');
        if ($note === '') throw new BcrfIncidentError('Say how it was settled.');
        $pdo->prepare("UPDATE incidents SET status = 'closed', closed_at = UTC_TIMESTAMP(), closed_by = ?, close_note = ?, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $note, $uid, $id]);
        bcrf_incident_log($pdo, $id, 'status', 'open', 'closed: ' . $note, $uid);
    } elseif ($to === 'open') {
        if ($i['status'] === 'open') throw new BcrfIncidentError('It is already open.');
        $pdo->prepare("UPDATE incidents SET status = 'open', closed_at = NULL, closed_by = NULL, close_note = NULL, updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")
            ->execute([$uid, $id]);
        bcrf_incident_log($pdo, $id, 'status', 'closed: ' . $i['close_note'], 'reopened' . ($note !== '' ? ': ' . $note : ''), $uid);
    } else {
        throw new BcrfIncidentError('Unknown status.');
    }
}

// ── Photos ───────────────────────────────────────────────────────────────────────────────────────

/** Folder outside the website folder (next to task photos), served only through admin/incident_photo.php. */
function bcrf_incident_photo_dir(): string {
    $base = getenv('BCRF_UPLOAD_DIR') ?: '';
    if ($base === '') $base = dirname(__DIR__, 3) . '/bcrf-uploads';   // same place as task photos (includes/tasks.php)
    $dir = rtrim($base, '/') . '/incidents';
    if (!is_dir($dir)) @mkdir($dir, 0700, true);
    if (is_dir($dir) && !is_file($dir . '/.htaccess')) @file_put_contents($dir . '/.htaccess', "Require all denied\nDeny from all\n");
    return $dir;
}

function bcrf_incident_photo_get(PDO $pdo, int $id): array {
    $st = $pdo->prepare("SELECT * FROM incident_photos WHERE id = ?");
    $st->execute([$id]);
    $r = $st->fetch(PDO::FETCH_ASSOC);
    if (!$r) throw new BcrfIncidentError('That photo was not found.');
    return $r;
}

function bcrf_incident_photo_path(array $p): string {
    $f = (string)($p['file'] ?? '');
    if (!preg_match('#^\d{4}/\d{2}/[a-f0-9]{24}\.webp$#', $f)) return '';
    return bcrf_incident_photo_dir() . '/' . $f;
}

/**
 * Save one uploaded photo on a report: checked to be a real image, turned upright, shrunk to 1600px and saved as WebP
 * under a random name. $log: write it to the edit history (photos added after the report). Returns the photo id.
 * ($GLOBALS['bcrf_incidents_test_upload'] lets the tests pass a plain file.)
 */
function bcrf_incident_photo_add(PDO $pdo, int $id, array $file, ?int $uid, bool $log = true): int {
    $st = $pdo->prepare("SELECT COUNT(*) FROM incident_photos WHERE incident_id = ?");
    $st->execute([$id]);
    if ((int)$st->fetchColumn() >= BCRF_INCIDENT_PHOTO_MAX) throw new BcrfIncidentError('Up to ' . BCRF_INCIDENT_PHOTO_MAX . ' photos on one report.');
    $err = (int)($file['error'] ?? UPLOAD_ERR_NO_FILE);
    if ($err === UPLOAD_ERR_INI_SIZE || $err === UPLOAD_ERR_FORM_SIZE) throw new BcrfIncidentError('That photo is too big to upload. Try a smaller one.');
    if ($err !== UPLOAD_ERR_OK) throw new BcrfIncidentError('The photo did not upload. Try again.');
    $tmp = (string)($file['tmp_name'] ?? '');
    if (empty($GLOBALS['bcrf_incidents_test_upload']) && !is_uploaded_file($tmp)) throw new BcrfIncidentError('The photo did not upload. Try again.');
    $size = (int)@filesize($tmp);
    if ($size <= 0 || $size > BCRF_INCIDENT_PHOTO_BYTES) throw new BcrfIncidentError('Photos can be up to 15 MB.');
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP, IMAGETYPE_GIF], true)) throw new BcrfIncidentError('That file is not a photo (JPEG, PNG or WebP).');
    if ($info[0] * $info[1] > 50000000) throw new BcrfIncidentError('That photo is too large to open.');
    $im = match ($info[2]) {
        IMAGETYPE_JPEG => @imagecreatefromjpeg($tmp),
        IMAGETYPE_PNG  => @imagecreatefrompng($tmp),
        IMAGETYPE_WEBP => function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false,
        IMAGETYPE_GIF  => @imagecreatefromgif($tmp),
        default        => false,
    };
    if (!$im) throw new BcrfIncidentError('That photo could not be opened.');
    if (!imageistruecolor($im)) imagepalettetotruecolor($im);
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $o = (int)(@exif_read_data($tmp)['Orientation'] ?? 1);
        $rot = [3 => 180, 6 => -90, 8 => 90][$o] ?? 0;
        if ($rot) { $r = imagerotate($im, $rot, 0); if ($r) $im = $r; }
    }
    $w = imagesx($im); $h = imagesy($im);
    $scale = min(1, 1600 / max($w, $h));
    if ($scale < 1) {
        $nw = max(1, (int)round($w * $scale)); $nh = max(1, (int)round($h * $scale));
        $c = imagecreatetruecolor($nw, $nh);
        imagecopyresampled($c, $im, 0, 0, 0, 0, $nw, $nh, $w, $h);
        $im = $c; $w = $nw; $h = $nh;
    }
    $rel = gmdate('Y/m') . '/' . bin2hex(random_bytes(12)) . '.webp';
    $path = bcrf_incident_photo_dir() . '/' . $rel;
    if (!is_dir(dirname($path)) && !@mkdir(dirname($path), 0700, true) && !is_dir(dirname($path))) throw new BcrfIncidentError('Could not save the photo (folder).');
    if (!@imagewebp($im, $path, 80)) throw new BcrfIncidentError('Could not save the photo.');
    @chmod($path, 0600);
    clearstatcache(true, $path);
    $pdo->prepare("INSERT INTO incident_photos (incident_id, file, width, height, bytes, created_at, created_by) VALUES (?, ?, ?, ?, ?, UTC_TIMESTAMP(), ?)")
        ->execute([$id, $rel, $w, $h, (int)filesize($path), $uid]);
    $pid = (int)$pdo->lastInsertId();
    if ($log) {
        bcrf_incident_log($pdo, $id, 'photo', null, 'photo #' . $pid . ' added', $uid);
        $pdo->prepare("UPDATE incidents SET updated_at = UTC_TIMESTAMP(), updated_by = ? WHERE id = ?")->execute([$uid, $id]);
    }
    return $pid;
}

/** One line for lists: "Visitor hurt (bite) · Serious · Goat Barn". */
function bcrf_incident_line(array $i): string {
    return BCRF_INCIDENT_TYPES[$i['type']] . ($i['injury'] ? ' (' . strtolower(BCRF_INCIDENT_INJURIES[$i['injury']]) . ')' : '')
         . ' · ' . BCRF_INCIDENT_SEVERITY[$i['severity']] . ' · ' . $i['location'];
}

}
