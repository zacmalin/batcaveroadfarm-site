<?php
// admin/gate.php — Farm OS gate screen (Phase 1, slice 4a, Nov 6, 2026)
// Occupancy ("14 of 20 on site", amber at 80%, red when full, estimated wait), scan or search a ticket or annual pass,
// admit some or all of the party, and check parties out. Walk-in admission is sold on the register, which checks the
// party in itself. Strict CSP (C-09): behaviour in assets/gate.js, looks in assets/register.css.
//   GET  gate.php?api=state            occupancy + on-site parties (also copies new online ticket orders, C-04 bridge)
//   GET  gate.php?api=search&q=…       a scanned QR (ticket code / pass link) or a name, email or phone
//   POST gate.php?api=admit            {kind: ticket|pass, id, n, override?}   (JSON, X-CSRF-Token)
//   POST gate.php?api=checkout         {id}
// Logic: includes/admission.php.
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
date_default_timezone_set(function_exists('bcrf_farm_tz') ? bcrf_farm_tz() : 'America/New_York');

if (empty($_SESSION['csrf_token'])) $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
$csrf  = $_SESSION['csrf_token'];
$ready = bcrf_admission_ready($pdo);
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES);

if ($api !== '') {
    header('Content-Type: application/json');
    header('Cache-Control: no-store');
    $out = function (int $code, array $body) { http_response_code($code); echo json_encode($body, JSON_UNESCAPED_UNICODE); exit; };
    if (!$ready) $out(503, ['ok' => false, 'message' => 'The gate is waiting for migration 0012 (Settings › Database).']);
    $state = function () use ($pdo) {
        $o = bcrf_occupancy($pdo);
        return ['on_site' => $o['on_site'], 'limit' => $o['limit'], 'free' => $o['free'], 'pct' => $o['pct'], 'level' => $o['level'],
                'wait_minutes' => bcrf_wait_minutes($o, 1), 'visitors_today' => $o['visitors_today'], 'parties_today' => $o['parties_today'],
                'parties' => bcrf_on_site_view($pdo)];
    };
    try {
        if ($api === 'state') {
            try { bcrf_tickets_sync_bookings($pdo, 50); } catch (Throwable $e) { error_log('[gate] sync: ' . $e->getMessage()); }
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        if ($api === 'search') {
            try { bcrf_tickets_sync_bookings($pdo, 50); } catch (Throwable $e) {}
            $out(200, ['ok' => true, 'results' => bcrf_gate_search($pdo, (string)($_GET['q'] ?? ''))]);
        }
        if ($_SERVER['REQUEST_METHOD'] !== 'POST') $out(405, ['ok' => false, 'message' => 'POST only.']);
        if (!hash_equals($csrf, (string)($_SERVER['HTTP_X_CSRF_TOKEN'] ?? ''))) $out(403, ['ok' => false, 'message' => 'Your session expired. Reload the gate screen.']);
        $in = json_decode((string)file_get_contents('php://input'), true);
        if (!is_array($in)) $out(400, ['ok' => false, 'message' => 'The request could not be read.']);
        if ($api === 'admit') {
            $n = (int)($in['n'] ?? 0); $id = (int)($in['id'] ?? 0); $ov = (string)($in['override'] ?? '');
            ($in['kind'] ?? '') === 'pass' ? bcrf_pass_admit($pdo, $id, $n, $ov) : bcrf_ticket_redeem($pdo, $id, $n, $ov);
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        if ($api === 'checkout') {
            bcrf_check_out($pdo, (int)($in['id'] ?? 0));
            $out(200, ['ok' => true, 'state' => $state()]);
        }
        $out(404, ['ok' => false, 'message' => 'Unknown request.']);
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
<link rel="stylesheet" href="assets/register.css?v=8">
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

  <h2 class="sect">On site now</h2>
  <ul class="onsite" id="onsite"></ul>
</main>
<div class="toast" id="toast" role="status" hidden></div>
<script src="assets/gate.js?v=2"></script>
</body>
</html>
