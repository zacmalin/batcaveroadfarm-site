<?php
// send-message.php — Self-contained contact handler for batcaveroadfarm.com
// Renders its OWN page (no header() redirects), so a stray BOM / leading whitespace
// or a broken target page cannot turn this into a blank white screen.

$method = isset($_SERVER['REQUEST_METHOD']) ? $_SERVER['REQUEST_METHOD'] : 'GET';

// --- Direct GET (someone typed the URL): show a visible "it's alive" + link ---
if ($method !== 'POST') {
    echo '<!doctype html><meta charset="utf-8">';
    echo '<body style="font-family:Georgia,serif;padding:40px;background:#f5ead6;color:#3b2010">';
    echo '<p>✅ contact.php is running. Use the <a href="https://batcaveroadfarm.com/contact-us/">contact form</a> to send a message.</p>';
    exit;
}

// --- Honeypot: bots fill the hidden "website" field; humans never see it ---
$isError = false;

// CSRF: compare the posted token against the one stored in the form's session.
// Kept dependency-free (no config/include) so this handler can't white-screen.
if (session_status() === PHP_SESSION_NONE) { @session_start(); }
$csrfOk = isset($_POST['csrf_token'], $_SESSION['csrf_token'])
    && is_string($_POST['csrf_token'])
    && hash_equals((string) $_SESSION['csrf_token'], $_POST['csrf_token']);

// Fix pack K: server-side limits (dependency-free file counters, no database).
// 3 messages per 10 minutes and 10 per day from one connection.
$rlFile = __DIR__ . '/includes/rate_limit.php';
if (is_file($rlFile)) { require_once $rlFile; }
$errMsg = '';

if (!empty($_POST['website'])) {
    // pretend success, send nothing
    $isError = false;
} elseif (!$csrfOk) {
    // Missing/stale token — fail closed; the visitor can simply resubmit.
    $isError = true;
} elseif (function_exists('bcrf_rl_hit')
          // single | on purpose: both counters always record the attempt
          && (bcrf_rl_hit('contact_ip10', bcrf_rl_ip(), 3, 600) | bcrf_rl_hit('contact_ipday', bcrf_rl_ip(), 10, 86400))) {
    $isError = true;
    $errMsg  = "You've sent several messages in a short time. Please wait a few minutes and try again, or call us at (828)&nbsp;216-5488.";
} else {
    $name     = trim(strip_tags(isset($_POST['name'])     ? $_POST['name']     : ''));
    $email    = trim(isset($_POST['email'])               ? $_POST['email']    : '');
    $phone    = trim(strip_tags(isset($_POST['phone'])    ? $_POST['phone']    : ''));
    $catagory = trim(strip_tags(isset($_POST['catagory']) ? $_POST['catagory'] : ''));
    $message  = trim(strip_tags(isset($_POST['message'])  ? $_POST['message']  : ''));

    $noCrlf = function ($v) { return trim(preg_replace('/[\r\n]+/', ' ', $v)); };
    $name     = $noCrlf($name);
    $phone    = $noCrlf($phone);
    $catagory = $noCrlf($catagory);

    $validEmail = filter_var($email, FILTER_VALIDATE_EMAIL);

    if ($name === '' || $validEmail === false || $message === '') {
        $isError = true;
    } else {
        $to      = 'support@batcaveroadfarm.com';
        $subject = $noCrlf('New Contact Form Submission from ' . $name);

        // Pure, dependency-free template functions — load defensively so this
        // handler still can't white-screen if the file is missing.
        $tplFile = __DIR__ . '/admin/includes/email_template.php';
        if (is_file($tplFile)) { require_once $tplFile; }

        if (function_exists('bcf_send_mail')) {
            // 1) Branded notification to the farm (reply goes straight to the visitor)
            $notifInner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">New message from ' . htmlspecialchars($name) . '</h1>'
                . '<table style="border-collapse:collapse;margin:0 0 16px;">'
                . '<tr><td style="padding:3px 14px 3px 0;color:#e8b84b;font-weight:bold;">Email</td><td style="padding:3px 0;">' . htmlspecialchars($validEmail) . '</td></tr>'
                . '<tr><td style="padding:3px 14px 3px 0;color:#e8b84b;font-weight:bold;">Phone</td><td style="padding:3px 0;">' . htmlspecialchars($phone !== '' ? $phone : '—') . '</td></tr>'
                . '<tr><td style="padding:3px 14px 3px 0;color:#e8b84b;font-weight:bold;">Category</td><td style="padding:3px 0;">' . htmlspecialchars($catagory !== '' ? $catagory : '—') . '</td></tr>'
                . '</table>'
                . '<p style="margin:0;white-space:pre-wrap;">' . nl2br(htmlspecialchars($message)) . '</p>';
            $sent = bcf_send_mail($to, $subject, $notifInner, [
                'subtitle'   => 'New Message',
                'from_email' => 'support@batcaveroadfarm.com',
                'reply_to'   => $validEmail,
            ]);

            // 2) Branded courtesy auto-reply to the visitor
            // Fix pack K: the auto-reply no longer repeats the message text, and goes
            // to any one address at most 2 times a day. Otherwise a spammer could type
            // someone else's email and use our server to deliver their text to them.
            $ackInner = '<h1 style="font-family:Rye,Georgia,serif;color:#e8b84b;font-size:24px;margin:0 0 14px;">Thanks for reaching out! &#127807;</h1>'
                . '<p style="margin:0 0 16px;">Hi ' . htmlspecialchars($name) . ", we've received your message and will get back to you within 24 hours.</p>"
                . '<p style="margin:0;">If you need us sooner, call (828)&nbsp;216-5488. We\'re open Tuesday to Sunday, 10&nbsp;AM to 4&nbsp;PM.</p>';
            if (!function_exists('bcrf_rl_hit') || !bcrf_rl_hit('contact_ack', $validEmail, 2, 86400)) {
                @bcf_send_mail($validEmail, 'Thanks for contacting Bat Cave Road Farm', $ackInner, [
                    'subtitle'   => 'Message Received',
                    'from_email' => 'support@batcaveroadfarm.com',
                ]);
            }
        } else {
            // Fallback: original plain-text notification to the farm
            $body    = "Name:     $name\n"
                     . "Email:    $validEmail\n"
                     . "Phone:    $phone\n"
                     . "Catagory: $catagory\n"
                     . "Message:\n$message\n";
            $headers = "From: Bat Cave Road Farm <support@batcaveroadfarm.com>\r\n"
                     . "Reply-To: $name <$validEmail>\r\n"
                     . "Content-Type: text/plain; charset=UTF-8\r\n";
            $sent    = @mail($to, $subject, $body, $headers, '-fsupport@batcaveroadfarm.com');
        }
        $isError = ($sent === false);
    }
}
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title><?php echo $isError ? "That didn't go through" : "Message Sent"; ?> &mdash; Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:wght@400;600&display=swap" rel="stylesheet">
<style>
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px}
  .card{max-width:460px;background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;
    border-radius:14px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(59,32,16,.18)}
  .emoji{font-size:3rem;line-height:1}
  h1{font-family:Rye,Georgia,serif;font-size:1.5rem;margin:14px 0 8px;color:#5c3317}
  p{font-size:1rem;line-height:1.6;margin:0 0 22px}
  a.btn{display:inline-block;background:#5c3317;color:#f5ead6;text-decoration:none;font-weight:600;
    padding:12px 26px;border-radius:8px;border:2px solid #c9923a}
  a.btn:hover{background:#3b2010}
</style>
<!-- GA4: confirmation-page conversion (CSP-safe; event fires from min.analytics.js reading the body data-attr) -->
<script async src="https://www.googletagmanager.com/gtag/js?id=G-DLB6LEFWQZ"></script>
<script defer src="https://batcaveroadfarm.com/js/min.gtag.js?v=20260624b"></script>
<script defer src="https://batcaveroadfarm.com/js/min.analytics.js?v=20260624b"></script>
</head>
<body<?php echo $isError ? '' : ' data-ga-event="generate_lead" data-ga-form="contact"'; ?>>
  <div class="card">
    <div class="emoji"><?php echo $isError ? '🌾' : '🌿'; ?></div>
    <h1><?php echo $isError ? "That didn't go through" : "Message Sent!"; ?></h1>
    <p><?php echo $isError
        ? ($errMsg !== '' ? $errMsg : "Sorry &mdash; something went wrong and we couldn't send your message. Please try again, or call us at (828)&nbsp;216-5488.")
        : "Thank you for reaching out! We've received your message and will get back to you within 24 hours."; ?></p>
<?php if (!$isError): ?>
    <p style="margin-top:14px;">Enjoyed a visit to the farm? <a href="https://batcaveroadfarm.com/review" target="_blank" rel="noopener">Leave us a Google review</a> ⭐ — it really helps a small family farm.</p>
<?php endif; ?>
    <a class="btn" href="https://batcaveroadfarm.com/contact-us/">Back to Contact</a>
  </div>
</body></html>
