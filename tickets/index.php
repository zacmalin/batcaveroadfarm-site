<?php
// tickets/index.php — self-serve day-admission ticket purchase (groups < 6).
// Pricing is driven by inventory items in the 'admissions' category.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
header('Pragma: no-cache');

require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/booking.php';

$pageTitle    = 'Buy Tickets — Bat Cave Road Farm';
$metaDesc     = 'Buy day-admission tickets online for the Bat Cave Road Farm petting zoo in Old Fort, NC. Walk-in tickets for groups of 5 or fewer — pick your date and pay securely online.';
$canonicalUrl = 'https://batcaveroadfarm.com/tickets/';
$ogImage      = 'https://batcaveroadfarm.com/img/og-pettingzoo.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.tickets.css';
$pageJS       = 'https://batcaveroadfarm.com/js/min.tickets.js';
require '../includes/head.php';
include '../includes/header.php';

// Admission ticket types from inventory
$ticketTypes = [];
try {
    $ticketTypes = $pdo->query("
        SELECT i.id, i.name, i.price
        FROM inventory i
        JOIN inventory_categories ic ON ic.id = i.category_id
        WHERE ic.slug = 'admissions' AND i.is_active = 1
        ORDER BY i.price DESC, i.name ASC
    ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* fallback: no ticket types, show message */ }

// Blackout dates for date-picker validation
$blackouts = [];
try {
    $blackouts = $pdo->query(
        "SELECT d_date FROM booking_blackouts WHERE d_date >= CURDATE() ORDER BY d_date"
    )->fetchAll(PDO::FETCH_COLUMN) ?: [];
} catch (Throwable $e) {}

// Closed days (JS day number: 0=Sun … 6=Sat)
$closedDays = [];
if (function_exists('site_hours')) {
    foreach (range(0, 6) as $d) {
        $h = site_hours('general')[$d] ?? [];
        if (!empty($h['closed'])) $closedDays[] = $d;
    }
}
if (empty($closedDays)) $closedDays = [1]; // default: Monday

$bhours  = function_exists('site_hours_summary') ? site_hours_summary('general') : [];
$openDay = $bhours['open_days'] ?? 'Tuesday – Sunday';
$openTm  = $bhours['open_time'] ?? '10AM – 4PM';
$minDate = date('Y-m-d', strtotime('+1 day'));
$maxDate = date('Y-m-d', strtotime('+90 days'));

// CSRF
if (empty($_SESSION['csrf_token'])) {
    $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
}

// Error from redirect-back (submit.php)
$errMap = [
    'csrf'           => 'Your session expired — please try again.',
    'no_tickets'     => 'Please select at least one ticket.',
    'invalid_date'   => 'Please choose a valid future date.',
    'closed_monday'  => 'We\'re closed on Mondays — please choose another day.',
    'blackout'       => 'That date is not available. Please choose another day.',
    'missing_info'   => 'Please fill in your name and email.',
    'no_type'        => 'Ticket sales are temporarily unavailable. Please call (828) 216-5488.',
    'db'             => 'Something went wrong — please try again or call (828) 216-5488.',
    'too_many'       => 'For groups of 6 or more, please use the <a href="/booking/">group booking form</a>.',
];
$errKey = trim((string)($_GET['error'] ?? ''));
$errMsg = $errMap[$errKey] ?? '';
?>

<div class="tickets-wrap">
    <div class="tickets-intro">
        <h1>Day Admission Tickets</h1>
        <p>Buy tickets for today's visit or choose a date ahead of time. Open <?= htmlspecialchars($openDay) ?>, <?= htmlspecialchars($openTm) ?>.</p>
    </div>

    <?php if ($errMsg !== ''): ?>
    <div class="tickets-error" role="alert"><?= $errMsg ?></div>
    <?php endif; ?>

    <?php if (empty($ticketTypes)): ?>
    <div class="tickets-unavailable">
        <p>Ticket pricing isn't configured yet. Please call <a href="tel:+18282165488">(828) 216-5488</a> or stop by during open hours.</p>
    </div>
    <?php else: ?>

    <form id="tickets-form" method="POST" action="/tickets/submit.php"
          data-blackouts="<?= htmlspecialchars(json_encode($blackouts), ENT_QUOTES) ?>"
          data-closed-days="<?= htmlspecialchars(json_encode($closedDays), ENT_QUOTES) ?>">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($_SESSION['csrf_token'], ENT_QUOTES) ?>">

        <!-- ── Ticket types ── -->
        <section class="tickets-section">
            <h2>Choose your tickets</h2>

            <?php foreach ($ticketTypes as $tt):
                $isFree   = (float)$tt['price'] <= 0;
                $priceStr = $isFree ? 'Free' : '$' . number_format((float)$tt['price'], 2);
            ?>
            <div class="ticket-type-row">
                <div class="ticket-type-info">
                    <span class="ticket-type-name"><?= htmlspecialchars($tt['name']) ?></span>
                    <span class="ticket-type-price"><?= $priceStr ?></span>
                </div>
                <div class="ticket-qty-ctrl">
                    <button type="button" class="qty-btn" data-qty-btn="dec" data-target="qty_<?= (int)$tt['id'] ?>" aria-label="Decrease">−</button>
                    <input type="number" id="qty_<?= (int)$tt['id'] ?>" name="qty[<?= (int)$tt['id'] ?>]"
                           class="ticket-qty-input" value="0" min="0" max="20"
                           data-price="<?= number_format((float)$tt['price'], 2) ?>"
                           aria-label="<?= htmlspecialchars($tt['name']) ?> quantity">
                    <button type="button" class="qty-btn" data-qty-btn="inc" data-target="qty_<?= (int)$tt['id'] ?>" aria-label="Increase">+</button>
                </div>
            </div>
            <?php endforeach; ?>

            <div class="tickets-subtotal">
                <span>Total: <strong id="tickets-total">$0.00</strong></span>
                <span class="tickets-people-count">· <span id="tickets-people">0</span> guests</span>
            </div>

            <div id="group-warning" class="tickets-group-warning" style="display:none">
                🐐 For groups of 6 or more, please use our
                <a href="/booking/">group booking form</a> — we'll reserve a dedicated time slot for you.
            </div>
        </section>

        <!-- ── Date ── -->
        <section class="tickets-section">
            <h2>Pick your visit date</h2>
            <div class="tickets-date-wrap">
                <input type="date" id="visit_date" name="visit_date"
                       min="<?= $minDate ?>" max="<?= $maxDate ?>"
                       class="tickets-date-input" required aria-required="true">
                <div id="date-feedback" class="tickets-date-feedback" aria-live="polite"></div>
            </div>
            <p class="tickets-date-note">Open <?= htmlspecialchars($openDay) ?> · Last entry 30 min before closing · Closed Mondays and select holidays.</p>
        </section>

        <!-- ── Your details ── -->
        <section class="tickets-section">
            <h2>Your details</h2>
            <div class="tickets-fields">
                <div class="tickets-field">
                    <label for="t_name">Name *</label>
                    <input type="text" id="t_name" name="customer_name" required
                           autocomplete="name" placeholder="Your name">
                </div>
                <div class="tickets-field">
                    <label for="t_email">Email *</label>
                    <input type="email" id="t_email" name="email" required
                           autocomplete="email" placeholder="you@example.com">
                </div>
                <div class="tickets-field">
                    <label for="t_phone">Phone <span class="tickets-optional">(optional)</span></label>
                    <input type="tel" id="t_phone" name="phone"
                           autocomplete="tel" placeholder="(828) 555-0100">
                </div>
            </div>
        </section>

        <div class="tickets-actions">
            <button type="submit" id="tickets-submit" class="cta-btn tickets-buy-btn" disabled>
                Buy Tickets →
            </button>
            <p class="tickets-secure-note">🔒 Secure checkout via Stripe — no card details stored on our servers.</p>
        </div>
    </form>
    <?php endif; ?>

    <div class="tickets-group-note">
        <strong>Group of 6 or more?</strong> <a href="/booking/">Use our group booking form</a> to reserve a dedicated time slot.
    </div>
    <div class="tickets-group-note" style="margin-top:10px;padding-top:10px;border-top:1px solid rgba(201,146,58,.25)">
        🪪 <strong>Visit often?</strong> An <a href="/passes/">annual pass</a> pays for itself in just a few visits — unlimited entry for a full year.
    </div>
</div>

<?php require '../includes/footer.php'; ?>
