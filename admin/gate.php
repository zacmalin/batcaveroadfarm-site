<?php
// admin/gate.php — Farm OS gate screen (Phase 1, slice 4a, Nov 6, 2026)
// Occupancy ("14 of 20 on site", amber at 80%, red when full, estimated wait), scan or search a ticket or annual pass,
// admit some or all of the party, and check parties out. Walk-in admission is sold on the register, which checks the
// party in itself. Strict CSP (C-09): behaviour in assets/gate.js, looks in assets/register.css.
//   GET  gate.php?api=state            occupancy + on-site parties (also copies new online ticket orders, C-04 bridge)
//   GET  gate.php?api=search&q=…       a scanned QR (ticket code / pass link) or a name, email or phone
//   POST gate.php?api=admit            {kind: ticket|pass, id, n, override?}   (JSON, X-CSRF-Token)
//   POST gate.php?api=checkout         {id}
//   POST gate.php?api=arrive           {type: booking|event_inquiry, id, n, update_quote?, override?}   a group checks in against
//                                      its capacity hold (Phase 3 slice 5, Nov 12, 2026)
// Waivers (Phase 3, Nov 12, 2026; includes/waivers.php): when waiver_mode is warn or block, search results and groups carry
// a waiver badge with a "Sign now" link (waiver_sign.php). In block mode admit / arrive answer 409 code 'waiver' until
// someone signs, or a manager sends waiver_override with a reason.
// Logic: includes/admission.php, includes/capacity_holds.php (today's groups, holds, arrivals).
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
require_once '../config.php';
check_admin();
require_once __DIR__ . '/includes/permission_checks.php';
if (($_SESSION['user_role'] ?? '') === 'Media') { http_response_code(403); exit('Media accounts cannot use the gate.'); }
$api = (string)($_GET['api'] ?? '');
require_permission([['view', 'pos'], ['view', 'passes']], $api !== '');
require_once __DIR__ . '/includes/audit.php';
require_once __DIR__ . '/../includes/queue.php';
require_once __DIR__ . '/../includes/admission.php';
require_once __DIR__ . '/../includes/capacity_holds.php';
require_once __DIR__ . '/../includes/waivers.php';
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$ready = bcrf_admission_ready($pdo);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);
// The email a ticket, pass, booking or party is under (whose waiver counts for it).
$emailOf = function (string $kind, int $id) use ($pdo): string {
    $sql = ['ticket' => "SELECT purchaser_email FROM tickets WHERE id = ?", 'pass' => "SELECT email FROM passes WHERE id = ?",
            'booking' => "SELECT email FROM bookings WHERE id = ?", 'event_inquiry' => "SELECT email FROM event_inquiries WHERE id = ?"][$kind] ?? null;
    if (!$sql) return '';
    $st = $pdo->prepare($sql); $st->execute([$id]);
    return (string)$st->fetchColumn();
};

if ($api !== '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $out = function (int $code, array $body) { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; };
    if (!$ready) $out(503, ['ok' => false, 'message' => 'The gate is waiting for migration 0012 (Settings › Database).']);
    $wMode = bcrf_waiver_mode($pdo);
    $withWaivers = function (array $rows, bool $groups) use ($pdo, $wMode, $emailOf): array {
        if ($wMode === 'off') return $rows;
        foreach ($rows as &$r) {
            $email = $groups ? $emailOf($r['type'], (int)$r['id']) : (string)($r['email'] ?? '');
            $r['waiver'] = bcrf_waiver_badge($pdo, $email, (string)($r['name'] ?? ''), $wMode);
        }
        unset($r);
        return $rows;
    };
    $waiverGate = function (string $kind, int $id, array $in) use ($pdo, $emailOf): void {
        $email = $emailOf($kind, $id);
        $why = bcrf_waiver_gate_check($pdo, $email, (string)($in['waiver_override'] ?? ''), bcrf_can_override_capacity());
        if ($why !== '' && function_exists('erp_audit')) erp_audit($pdo, 'update', 'waivers', 0, 'Let in without a waiver (' . $kind . ' #' . $id . ', ' . $email . '): ' . $why);
    };
    $state = function () use ($pdo, $withWaivers) {
        $o = bcrf_occupancy($pdo);
        return ['on_site' => $o['on_site'], 'limit' => $o['limit'], 'free' => $o['free'], 'pct' => $o['pct'], 'level' => $o['level'],
                'wait_minutes' => bcrf_wait_minutes($o, 1), 'visitors_today' => $o['visitors_today'], 'parties_today' => $o['parties_today'],
                'parties' => bcrf_on_site_view($pdo), 'held' => $o['held'] ?? 0, 'held_waiting' => $o['held_waiting'] ?? 0,
                'groups' => $withWaivers(bcrf_holds_gate_view($pdo), true)];
    };
    try {
        if ($api === 'state') {
            try { bcrf_tickets_sync_bookings($pdo, 50); } catch (Throwable $e) { error_log('[gate] sync: ' . $e->getMessage()); }
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        if ($api === 'search') {
            try { bcrf_tickets_sync_bookings($pdo, 50); } catch (Throwable $e) {}
            $out(200, ['ok' => true, 'results' => $withWaivers(bcrf_gate_search($pdo, (string)($_GET['q'] ?? '')), false)]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the gate screen.']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read.']);
        if ($api === 'admit') {
            $n = (int)($in['n'] ?? 0); $id = (int)($in['id'] ?? 0); $ov = (string)($in['override'] ?? '');
            $waiverGate(($in['kind'] ?? '') === 'pass' ? 'pass' : 'ticket', $id, $in);
            ($in['kind'] ?? '') === 'pass' ? bcrf_pass_admit($pdo, $id, $n, $ov) : bcrf_ticket_redeem($pdo, $id, $n, $ov);
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        if ($api === 'arrive') {
            $waiverGate((string)($in['type'] ?? '') === 'booking' ? 'booking' : 'event_inquiry', (int)($in['id'] ?? 0), $in);
            $r = bcrf_hold_arrive($pdo, (string)($in['type'] ?? ''), (int)($in['id'] ?? 0), (int)($in['n'] ?? 0), !empty($in['update_quote']), (string)($in['override'] ?? ''));
            $out(200, ['ok' => true, 'state' => $state(), 'arrived' => $r['arrived'], 'quote' => $r['quote']]);
        }
        if ($api === 'checkout') {
            bcrf_check_out($pdo, (int)($in['id'] ?? 0));
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        $out(404, ['ok' => false, 'message' => 'Unknown request.']);
    } catch (BcrfWaiverError $e) {
        $out(409, ['ok' => false, 'code' => 'waiver', 'message' => $e->getMessage(), 'can_override' => bcrf_can_override_capacity()]);
    } catch (BcrfCapacityError $e) {
        $out(409, ['ok' => false, 'code' => 'capacity', 'message' => $e->getMessage(), 'can_override' => bcrf_can_override_capacity()]);
    } catch (BcrfSaleError $e) {
        $out(422, ['ok' => false, 'message' => $e->getMessage()]);
    } catch (Throwable $e) {
        error_log('[gate] ' . $api . ': ' . $e->getMessage());
        $out(500, ['ok' => false, 'message' => 'The server could not do that. Try again.']);
    }
}

$boot = ['ready' => $ready, 'csrf' => $csrf];
?><!DOCTYPE html>
<html lang="en">
<head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1, viewport-fit=cover">
<meta http-equiv="Content-Security-Policy" content="default-src 'self'; script-src 'self'; style-src 'self'; img-src 'self' data: blob:; media-src 'self' blob:; connect-src 'self'; base-uri 'self'; form-action 'self'">
<meta name="robots" content="noindex">
<title>Gate — <?= $h(bcrf_farm_name()) ?></title>
<link rel="stylesheet" href="assets/register.css?v=9">
<link rel="stylesheet" href="assets/waiver.css?v=1">
</head>
<body class="gate">
<script type="application/json" id="gateBoot"><?= json_encode($boot, JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT) ?></script>
<header class="top">
  <a class="back drawerbtn" href="/admin/admin.php"><span aria-hidden="true">←</span>&nbsp;Console</a>
  <a class="drawerbtn" href="register.php">Register</a>
</header>
<main class="gatewrap">
  <?php if (!$ready): ?><p class="banner err">The gate needs migration 0012. An administrator can run it in Settings › Database.</p><?php endif; ?>
  <section class="meter" id="meter" aria-live="polite">
    <div class="meternum"><b id="mOn">–</b> <span>of <span id="mLimit">–</span> on site</span></div>
    <div class="meterbar"><span id="mBar"></span></div>
    <p class="muted" id="mSub"></p>
  </section>

  <form class="find" id="formFind" autocomplete="off">
    <input type="search" id="q" placeholder="Code, name, email or phone" aria-label="Find a ticket or pass">
    <button class="btn" type="button" id="btnScan">Scan</button>
    <button class="btn primary">Find</button>
  </form>
  <video id="scanVideo" playsinline muted hidden></video>
  <p class="muted" id="findMsg">Walk-ins: sell admission on the <a href="register.php">register</a>; it checks them in.</p>
  <div id="results"></div>

  <section id="groupsWrap" hidden>
    <h2 class="sect">Groups today</h2>
    <div id="groups"></div>
  </section>

  <h2 class="sect">On site now</h2>
  <ul class="onsite" id="onsite"></ul>
</main>
<div class="toast" id="toast" role="status" hidden></div>
<script src="assets/gate.js?v=4"></script>
</body>
</html>
