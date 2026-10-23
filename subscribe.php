<?php
ob_start();
require_once './config.php';

$pageCSS         = "https://batcaveroadfarm.com/css/min.contactus.css";
$pageTitle       = "Join Our Farm Mailing List | Bat Cave Road Farm";
$pageDescription = "Sign up for Bat Cave Road Farm updates. Customize notifications for new arrivals at our petting zoo or fresh harvest alerts at our farm stand.";

$message = '';
$ok = false;

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    // Honeypot: real visitors leave this blank; bots tend to fill every field.
    if (!empty($_POST['website'])) {
        $ok = true;
        $message = "You're on the list! Welcome to the farm community.";
    } else {
        $email     = filter_var(trim($_POST['email'] ?? ''), FILTER_VALIDATE_EMAIL);
        $nursery   = isset($_POST['track_nursery']) ? 1 : 0;
        $inventory = isset($_POST['track_inventory']) ? 1 : 0;

        if ($email) {
            try {
                $stmt = $pdo->prepare("INSERT INTO mailing_list (email, is_subscribed, track_nursery_updates, track_inventory_alerts)
                                       VALUES (?, 1, ?, ?)
                                       ON DUPLICATE KEY UPDATE is_subscribed = 1, track_nursery_updates = ?, track_inventory_alerts = ?");
                $stmt->execute([$email, $nursery, $inventory, $nursery, $inventory]);
                $ok = true;
                $message = "You're on the list! Welcome to the farm community.";
            } catch (Throwable $e) {
                error_log('subscribe.php: ' . $e->getMessage());
                $ok = false;
                $message = "Something went wrong on our end — please try again in a moment.";
            }
        } else {
            $ok = false;
            $message = "That email doesn't look right — please check it and try again.";
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
