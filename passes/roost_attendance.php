<?php
// Staff-only Roost Rewards attendance verification form. No automatic points.
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/pass_staff.php';
if (!bcrf_pass_can_log()) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Staff access required.';
    exit;
}
if (session_status() !== PHP_SESSION_ACTIVE) {
    http_response_code(403);
    exit('Staff session required.');
}
if (empty($_SESSION['roost_attendance_csrf'])) {
    $_SESSION['roost_attendance_csrf'] = bin2hex(random_bytes(32));
}
$token = htmlspecialchars((string)$_SESSION['roost_attendance_csrf'], ENT_QUOTES, 'UTF-8');
?>
<!doctype html>
<html lang="en"><head>
<meta charset="utf-8"><meta name="viewport" content="width=device-width, initial-scale=1">
<meta name="robots" content="noindex,nofollow">
<title>Verify Roost Rewards Attendance | Bat Cave Road Farm</title>
<style>
body{font-family:system-ui,sans-serif;max-width:36rem;margin:2rem auto;padding:0 1rem;line-height:1.5;color:#252b23}
label{display:block;margin-top:1rem;font-weight:600}
input{display:block;box-sizing:border-box;width:100%;padding:.7rem;font:inherit}
button{margin-top:1.2rem;padding:.8rem 1.2rem;font:inherit;cursor:pointer}
#result{margin-top:1rem;white-space:pre-wrap} .hint{color:#515b4b}
</style></head><body>
<main>
<h1>Verify Roost Rewards attendance</h1>
<p class="hint">Staff use only. Verify the person's identity and that they actually attended before recording. This does not award points.</p>
<form id="roost-form" action="/passes/roost_verify_attendee.php" method="post">
<input type="hidden" name="csrf_token" value="<?= $token ?>">
<label for="check_in_id">Existing check-in ID</label>
<input id="check_in_id" name="check_in_id" type="number" min="1" step="1" required inputmode="numeric" autocomplete="off">
<label for="customer_id">Verified rewards customer ID</label>
<input id="customer_id" name="customer_id" type="number" min="1" step="1" required inputmode="numeric" autocomplete="off">
<button type="submit">Verify attendee</button>
</form>
<p id="result" role="status" aria-live="polite"></p>
<p class="hint">Do not use a ticket purchaser's ID or group headcount as proof of attendance. Each attendee must be individually verified.</p>
</main>
<script>
document.getElementById('roost-form').addEventListener('submit', async function (event) {
  event.preventDefault();
  const form = event.currentTarget, button = form.querySelector('button'), output = document.getElementById('result');
  button.disabled = true; output.textContent = 'Verifying…';
  try {
    const response = await fetch(form.action, {method:'POST', body:new FormData(form), credentials:'same-origin', cache:'no-store'});
    const data = await response.json();
    output.textContent = data.ok ? 'Attendance verified (record #' + data.attendance_id + '). No points awarded yet.' : (data.error || 'Verification failed.');
  } catch (_) { output.textContent = 'Verification unavailable. Please try again.'; }
  finally { button.disabled = false; }
});
</script></body></html>
