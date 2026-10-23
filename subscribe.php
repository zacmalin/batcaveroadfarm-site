<?php
ob_start();
require_once './config.php';

$pageCSS         = "https://batcaveroadfarm.com/css/min.contactus.css";
$pageTitle       = "Join Our Farm Mailing List | Bat Cave Road Farm";
$pageDescription = "Sign up for Bat Cave Road Farm updates. Customize notifications for new arrivals at our petting zoo or fresh harvest alerts at our farm stand.";

$message = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Fix pack K: double opt-in + server-side limits.
    // Nothing is saved here any more. We email a signed link, and the address is
    // added only when its owner taps it (see subscribe-confirm.php).
    require_once __DIR__ . '/includes/rate_limit.php';
    require_once __DIR__ . '/includes/subscribe_confirm.php';
    require_once __DIR__ . '/admin/includes/email_template.php';

    $checkInbox = "Almost done! Check your inbox and tap the link we sent to confirm your signup.";

    // Honeypot: real visitors leave this blank; bots tend to fill every field.
    if (!empty($_POST['website'])) {
        $ok = true;
        $message = $checkInbox;
    } else {
        $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $nursery   = isset($_POST['track_nursery']) ? 1 : 0;
        $inventory = isset($_POST['track_inventory']) ? 1 : 0;

        if (!$email) {
            $ok = false;
            $message = "That email doesn't look right — please check it and try again.";
        } elseif (bcrf_rl_hit('sub_ip', bcrf_rl_ip(), 5, 3600)) {
            // Max 5 signups per connection per hour
            $ok = false;
            $message = "Too many signups from this connection. Please try again in an hour.";
        } elseif (bcrf_rl_hit('sub_email', $email, 3, 86400)) {
            // Max 3 confirmation emails per address per day. Same reply as normal
            // so this can't be used to find out anything about an address.
            $ok = true;
            $message = $checkInbox;
        } elseif (bcrf_subconf_send($email, $nursery, $inventory)) {
            $ok = true;
            $message = $checkInbox;
        } else {
            $ok = false;
            $message = "Something went wrong on our end — please try again in a moment.";
        }
    }

    // Modal (fetch) requests get JSON; the full-page form falls through to HTML.
    if (!empty($_POST['ajax'])) {
        ob_clean();
        header('Content-Type: application/json; charset=utf-8');
        echo json_encode(['ok' => $ok, 'message' => $message]);
        exit;
    }
}

require_once './includes/head.php';
require_once './includes/header.php';
?>
        <div id="page-subscribe" class="page1">
          <div class="content-wrap1">
            <div class="contact-form reveal">
                <h2>Farm Connection</h2>
                <p>Stay up to date with fresh harvests and nursery additions directly from our fields.</p>

                <?php if ($message): ?>
                    <div class="subscribe-msg <?= $ok ? 'is-ok' : 'is-err' ?>"><?= htmlspecialchars($message) ?></div>
                <?php endif; ?>

                <form method="POST" class="sub-form">
                    <div class="form-group">
                        <label for="sub-email">Email Address<span class="required">*</span></label>
                        <input id="sub-email" type="email" name="email" required placeholder="name@domain.com">

                        <span class="sub-choose">Choose your updates:</span>
                        <label class="sub-check"><input type="checkbox" name="track_nursery" value="1" checked> <span>Nursery &amp; animal updates — be the first to know when new baby animals arrive.</span></label>
                        <label class="sub-check"><input type="checkbox" name="track_inventory" value="1" checked> <span>Fresh harvest alerts — get notified when seasonal produce and baked goods hit the stand.</span></label>

                        <input type="text" name="website" class="hp" tabindex="-1" autocomplete="off" aria-hidden="true">
                        <button type="submit" class="form-submit cta-btn">Save Subscription</button>
                    </div>
                </form>
                <p><a href="https://batcaveroadfarm.com/unsubscribe.php">Opt out or change preferences here &rarr;</a></p>
            </div>
          </div>
        </div>
<?php require_once './includes/footer.php'; ?>
