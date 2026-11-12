<?php
// account/index.php — "My farm", the customer portal (Farm OS Phase 3 slice 6, Nov 12, 2026; includes/customer_portal.php).
// Bookings and parties (what's left to pay, with the quote page to pay it), online tickets and annual passes (each opens
// its QR page), register receipts, email preferences (reminders, newsletter and topics), and name and phone. Signed-out visitors go to the emailed-link
// sign-in. Bookings, tickets and passes show only once the account's email is verified.
//   POST act=profile     first name, last name, phone
//   POST act=newsletter  on / off (only before migration 0037)
//   POST act=prefs       email preferences (includes/email_prefs.php); news can be turned on only with a verified email
if (!function_exists('bcrf_farm_url')) require_once __DIR__ . '/../includes/farm.php';
ob_start();
require_once '../config.php';
require_once '../includes/auth.php';
require_once '../includes/customer_auth.php';
require_once '../includes/queue.php';
require_once '../admin/includes/audit.php';
require_once '../includes/booking.php';
require_once '../includes/event_quotes.php';
require_once '../includes/tickets_online.php';
require_once '../includes/customer_portal.php';
require_once '../includes/email_prefs.php';
header('Cache-Control: no-store, private');
header('X-Robots-Tag: noindex, nofollow');

if (!is_logged_in()) { ob_end_clean(); header('Location: /account/link.php?next=' . rawurlencode('/account/')); exit; }
$c = bcrf_customer_find($pdo, current_user_id());
if (!$c || !bcrf_customer_active($c)) { auth_logout(); ob_end_clean(); header('Location: /account/link.php'); exit; }

$flash = '';
$error = '';
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $act = (string)($_POST['act'] ?? '');
    if (!csrf_verify($_POST['csrf_token'] ?? null)) {
        $error = 'Your session expired. Please try again.';
    } elseif ($act === 'profile') {
        $first = trim(strip_tags((string)($_POST['first_name'] ?? '')));
        $last  = trim(strip_tags((string)($_POST['last_name'] ?? '')));
        $phone = trim(preg_replace('/[^0-9+().\- ]/', '', (string)($_POST['phone'] ?? '')));
        if ($first === '' || mb_strlen($first) > 50 || mb_strlen($last) > 50) {
            $error = 'Please enter your first name (up to 50 letters).';
        } elseif (strlen($phone) > 20) {
            $error = 'That phone number is too long.';
        } else {
            bcrf_customer_update($pdo, (int)$c['id'], ['first_name' => $first, 'last_name' => $last, 'phone_number' => $phone !== '' ? $phone : null]);
            $_SESSION['customer_name'] = trim($first . ' ' . $last);
            $_SESSION['portal_flash'] = 'Saved.';
            ob_end_clean(); header('Location: /account/#details'); exit;
        }
    } elseif ($act === 'newsletter') {
        $on = ($_POST['on'] ?? '') === '1';
        if (bcrf_portal_newsletter_set($pdo, $c, $on)) {
            $_SESSION['portal_flash'] = $on ? 'You\'re on the newsletter. Thanks!' : 'You\'re off the newsletter.';
            ob_end_clean(); header('Location: /account/#details'); exit;
        }
        $error = 'Verify your email first, then you can join the newsletter.';
    } elseif ($act === 'prefs') {
        try {
            bcrf_email_prefs_save($pdo, (string)$c['email'], bcrf_email_prefs_from_post($_POST), 'portal', null, !empty($c['email_verified_at']));
            $_SESSION['portal_flash'] = 'Email preferences saved.';
            ob_end_clean(); header('Location: /account/#emails'); exit;
        } catch (Throwable $e) {
            error_log('[portal] prefs: ' . $e->getMessage());
            $error = 'Could not save your email preferences. Please try again.';
        }
    }
}
$flash = (string)($_SESSION['portal_flash'] ?? ''); unset($_SESSION['portal_flash']);

$items = bcrf_portal_items($pdo, $c);
$prefs = bcrf_email_prefs_ready($pdo) ? bcrf_email_prefs_get($pdo, (string)$c['email']) : null;
$news = $prefs === null ? bcrf_portal_newsletter($pdo, (string)$c['email']) : null;
ob_end_clean();

$pageTitle       = 'My Farm | ' . bcrf_farm_name();
$metaDesc        = 'Your bookings, parties, tickets, passes and receipts.';
$canonicalUrl    = bcrf_farm_url('/account/');
$hideBreadcrumbs = true;
$pageCSS         = [bcrf_farm_url() . '/css/min.portal.css'];
require_once '../includes/head.php';
require_once '../includes/header.php';

$h = fn($x) => htmlspecialchars((string)$x, ENT_QUOTES);
$m = fn($c) => bcrf_quote_money((int)$c);
$d = fn($ymd, $f = 'M j, Y') => $ymd ? date($f, strtotime($ymd)) : '';
$card = function (array $b) use ($h, $m) {
    ob_start(); ?>
    <li class="portal-item">
      <div class="portal-row">
        <strong><?= $h($b['what']) ?></strong>
        <span class="portal-badge<?= $b['status'] === 'Confirmed' ? ' portal-badge-ok' : '' ?>"><?= $h($b['status']) ?></span>
      </div>
      <p class="portal-muted"><?= $h($b['when']) ?> · <?= (int)$b['guests'] ?> guest<?= $b['guests'] === 1 ? '' : 's' ?></p>
      <?php if ($b['quote_url']): ?>
        <p>
          <?php if ($b['balance'] > 0): ?>
            Total <?= $h($m($b['total'])) ?><?= $b['paid'] ? ', paid ' . $h($m($b['paid'])) : '' ?>. <strong><?= $h($m($b['balance'])) ?> left</strong>, due <?= $b['balance_when'] === 'before' ? 'before the day' : 'when you arrive' ?>.
          <?php elseif ($b['balance'] < 0): ?>
            We owe you <?= $h($m(-$b['balance'])) ?>. We'll be in touch.
          <?php elseif ($b['paid'] > 0): ?>
            Paid in full. Thank you!
          <?php else: ?>
            Total <?= $h($m($b['total'])) ?>.
          <?php endif; ?>
        </p>
        <p><a class="portal-link" href="<?= $h($b['quote_url']) ?>"><?= $b['balance'] > 0 ? 'See quote and pay' : 'See quote and payments' ?></a></p>
      <?php endif; ?>
    </li>
    <?php return ob_get_clean();
};
?>
<div class="content-wrap">
  <div class="portal-wrap">
    <h1 class="portal-title">My Farm</h1>
    <p class="portal-sub">Hi <?= $h($c['first_name']) ?>! Here's everything you have with us. <a href="/account/logout.php">Sign out</a></p>

    <?php if ($flash !== ''): ?><p class="portal-flash" role="status"><?= $h($flash) ?></p><?php endif; ?>
    <?php if ($error !== ''): ?><p class="portal-flash portal-flash-err" role="alert"><?= $h($error) ?></p><?php endif; ?>

    <?php if (!$items['verified']): ?>
      <section class="portal-card">
        <h2>Verify your email</h2>
        <p>To keep your bookings private, they show here once you've proved this email is yours. Use the link we emailed when you signed up, or get a sign-in link now: it verifies your email too.</p>
        <p><a class="portal-link" href="/account/link.php">Email me a sign-in link</a></p>
      </section>
    <?php endif; ?>

    <section class="portal-card">
      <h2>Bookings and parties</h2>
      <?php if ($items['upcoming']): ?>
        <ul class="portal-list"><?php foreach ($items['upcoming'] as $b) echo $card($b); ?></ul>
      <?php else: ?>
        <p class="portal-muted">Nothing coming up. <a href="/private-events/">Plan a party</a> or <a href="/booking/">book a group visit</a>.</p>
      <?php endif; ?>
      <?php if ($items['past']): ?>
        <details class="portal-more"><summary>Past and cancelled (<?= count($items['past']) ?>)</summary>
          <ul class="portal-list"><?php foreach ($items['past'] as $b) echo $card($b); ?></ul>
        </details>
      <?php endif; ?>
    </section>

    <section class="portal-card">
      <h2>Tickets</h2>
      <?php if ($items['tickets']): ?>
        <ul class="portal-list">
          <?php foreach ($items['tickets'] as $t): ?>
          <li class="portal-item">
            <div class="portal-row">
              <strong><?= (int)$t['quantity'] ?> admission<?= $t['quantity'] === 1 ? '' : 's' ?></strong>
              <span class="portal-badge<?= $t['usable'] ? ' portal-badge-ok' : '' ?>"><?= $t['usable'] ? $t['left'] . ' left to use' : 'Used up or expired' ?></span>
            </div>
            <p class="portal-muted">Bought <?= $h($d($t['bought'])) ?><?= $t['expires'] ? ' · good any open day until ' . $h($d($t['expires'])) : '' ?></p>
            <?php if ($t['usable']): ?><p><a class="portal-link" href="<?= $h($t['url']) ?>">Show ticket QR code</a></p><?php endif; ?>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="portal-muted">No online tickets. <a href="/tickets/">Buy tickets</a> (good any open day).</p>
      <?php endif; ?>
    </section>

    <section class="portal-card">
      <h2>Annual passes</h2>
      <?php if ($items['passes']): ?>
        <ul class="portal-list">
          <?php foreach ($items['passes'] as $p): ?>
          <li class="portal-item">
            <div class="portal-row">
              <strong><?= $h($p['type']) ?> · <?= $h($p['holder']) ?></strong>
              <span class="portal-badge<?= $p['active'] ? ' portal-badge-ok' : '' ?>"><?= $h($p['status']) ?></span>
            </div>
            <?php if ($p['until']): ?><p class="portal-muted"><?= $p['active'] ? 'Good until' : 'Ended' ?> <?= $h($d($p['until'])) ?></p><?php endif; ?>
            <p><a class="portal-link" href="<?= $h($p['url']) ?>"><?= $p['active'] ? 'Show pass QR code' : 'See pass' ?></a><?php if (!$p['active']): ?> · <a class="portal-link" href="/passes/">Get a new pass</a><?php endif; ?></p>
          </li>
          <?php endforeach; ?>
        </ul>
      <?php else: ?>
        <p class="portal-muted">No annual pass. <a href="/passes/">See passes</a>.</p>
      <?php endif; ?>
    </section>

    <?php if ($items['receipts']): ?>
    <section class="portal-card">
      <h2>Receipts</h2>
      <ul class="portal-list">
        <?php foreach ($items['receipts'] as $r): ?>
        <li class="portal-item">
          <details>
            <summary class="portal-row"><span><?= $h($d($r['date'])) ?> · <?= $h($r['number']) ?></span>
              <span><?= $r['voided'] ? 'Voided' : $h($m($r['total'])) ?><?= $r['refunded'] && !$r['voided'] ? ' (' . $h($m($r['refunded'])) . ' refunded)' : '' ?></span></summary>
            <table class="portal-lines">
              <?php foreach ($r['lines'] as $l): $qty = rtrim(rtrim(number_format((float)$l['quantity'], 3, '.', ''), '0'), '.'); ?>
              <tr><td><?= $h($l['description']) ?><?= $qty !== '1' ? ' <span class="portal-muted">× ' . $h($qty) . ($l['unit'] !== 'each' ? ' ' . $h($l['unit']) : '') . '</span>' : '' ?></td>
                  <td class="portal-amt"><?= $h($m($l['line_total_cents'])) ?></td></tr>
              <?php endforeach; ?>
            </table>
          </details>
        </li>
        <?php endforeach; ?>
      </ul>
    </section>
    <?php endif; ?>

    <section class="portal-card" id="details">
      <h2>Your details</h2>
      <form method="POST" action="/account/" class="portal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="profile">
        <div class="auth-field"><label for="p-first">First name</label><input id="p-first" name="first_name" maxlength="50" required autocomplete="given-name" value="<?= $h($c['first_name']) ?>"></div>
        <div class="auth-field"><label for="p-last">Last name</label><input id="p-last" name="last_name" maxlength="50" autocomplete="family-name" value="<?= $h($c['last_name']) ?>"></div>
        <div class="auth-field"><label for="p-phone">Phone</label><input id="p-phone" name="phone" type="tel" maxlength="20" autocomplete="tel" value="<?= $h($c['phone_number'] ?? '') ?>"></div>
        <p class="portal-muted">Email: <?= $h($c['email']) ?><?= $items['verified'] ? '' : ' (not verified yet)' ?>. To change it, reply to any of our emails and we'll update it.</p>
        <button type="submit" class="auth-btn">Save</button>
      </form>

      <?php if ($prefs === null && $news !== null): ?>
      <form method="POST" action="/account/" class="portal-news">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="newsletter">
        <?php if ($news): ?>
          <p>You get the farm newsletter.</p>
          <input type="hidden" name="on" value="0"><button type="submit" class="portal-btn-alt">Stop the newsletter</button>
        <?php else: ?>
          <p>Get farm news, new animals and events about once a month. You can stop any time.</p>
          <input type="hidden" name="on" value="1"><button type="submit" class="portal-btn-alt">Get the newsletter</button>
        <?php endif; ?>
      </form>
      <?php endif; ?>
      <p class="portal-muted"><?= empty($c['password']) ? 'You sign in with an emailed link. Want a password too? <a href="/account/forgot.php">Set one</a>.' : '<a href="/account/forgot.php">Change your password</a>' ?></p>
    </section>

    <?php if ($prefs !== null): ?>
    <section class="portal-card" id="emails">
      <h2>Emails from us</h2>
      <form method="POST" action="/account/" class="portal-form">
        <?= csrf_field() ?>
        <input type="hidden" name="act" value="prefs">
        <?= bcrf_email_prefs_fields($prefs, !empty($c['email_verified_at'])) ?>
        <button type="submit" class="auth-btn">Save</button>
      </form>
    </section>
    <?php endif; ?>
  </div>
</div>
<?php require_once '../includes/footer.php'; ?>
