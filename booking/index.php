<?php
    header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
    header('Pragma: no-cache');
    require_once __DIR__ . '/../config.php';
    require_once __DIR__ . '/../includes/booking.php';
    $pageTitle    = "Book a Visit - Bat Cave Road Farm";
    $metaDesc     = "Request a farm visit, group tour, or private event at Bat Cave Road Farm in Old Fort, NC. Walk-ins welcome for groups under 6; reserve ahead for larger groups and events.";
    $canonicalUrl = "https://batcaveroadfarm.com/booking/";
    $ogImage      = "https://batcaveroadfarm.com/img/og-pettingzoo.jpg";
    $pageCSS      = 'https://batcaveroadfarm.com/css/min.booking.css';
    $pageJS       = 'https://batcaveroadfarm.com/js/min.booking.js';
    require('../includes/head.php');
    include('../includes/header.php');
    $types  = function_exists('booking_types') ? booking_types($pdo) : [];
    $bhours = function_exists('site_hours_summary') ? site_hours_summary('general') : ['open_days' => 'Tuesday – Sunday', 'open_time' => '10:00 AM – 4:00 PM'];

    // Admission tiers for per-person booking types (same inventory items as /tickets/)
    $admTiers = [];
    try {
        $admTiers = $pdo->query("
            SELECT i.id, i.name, i.price
            FROM inventory i
            JOIN inventory_categories ic ON ic.id = i.category_id
            WHERE ic.slug = 'admissions' AND i.is_active = 1
            ORDER BY i.price DESC, i.name ASC
        ")->fetchAll(PDO::FETCH_ASSOC) ?: [];
    } catch (Throwable $e) { /* category not yet created */ }
?>

<div class="booking-wrap">
    <div class="booking-intro">
        <h1>Book Your Visit</h1>
        <p>Open <?= htmlspecialchars($bhours['open_days']) ?>, <?= htmlspecialchars($bhours['open_time']) ?>.</p>
    </div>

    <p class="booking-note">
        🐐 <strong>Walk-ins welcome</strong> for groups of 5 or fewer during open hours — no booking needed.
        Please reserve ahead for <strong>groups of 6+</strong>, guided tours, and private events.</p>
    <div class="booking-tix-cta">
        <strong>Visiting with 5 or fewer?</strong>
        <a class="cta-btn cta-btn--sm" href="/tickets/">Buy tickets online →</a>
    </div>
    <p class="booking-note">Private events are by <strong>request</strong> — we'll call to confirm details and pricing. Nothing is reserved until we confirm by email.</p>

    <?php if (!$types): ?>
        <p class="booking-note">Online booking is briefly unavailable. Please call
            <?= htmlspecialchars(function_exists('site') ? site('phone.general') : '(828) 216-5488') ?> to book.</p>
    <?php else: ?>
    <form class="booking-form" action="https://batcaveroadfarm.com/booking/submit.php" method="POST">
        <div class="hp" aria-hidden="true">
            <label for="website">Website</label>
            <input type="text" id="website" name="website" tabindex="-1" autocomplete="off">
        </div>

        <label for="type_key">What would you like to book?</label>
        <select id="type_key" name="type_key" required>
            <?php foreach ($types as $t): ?>
                <option value="<?= htmlspecialchars($t['type_key'], ENT_QUOTES) ?>"
                        data-model="<?= htmlspecialchars($t['pricing_model'] ?? 'flat', ENT_QUOTES) ?>"
                        data-price="<?= number_format((float)($t['unit_price'] ?? 0), 2) ?>"
                        data-reqpay="<?= !empty($t['requires_payment']) ? '1' : '0' ?>">
                    <?= htmlspecialchars($t['label']) ?>
                </option>
            <?php endforeach; ?>
        </select>
        <div id="price-preview" style="display:none;margin:6px 0 0;padding:8px 12px;background:#fdf4e3;border:1px solid #c9923a;border-radius:7px;font-size:.9rem;color:#5c3317;font-weight:600;"></div>
        <div class="booking-row">
            <div>
                <label for="customer_name">Your name</label>
                <input type="text" id="customer_name" name="customer_name" maxlength="120" required>
            </div>
            <!-- Flat rate: single party size input -->
            <div id="party-size-section">
                <label for="party_size_flat">Party size</label>
                <input type="number" id="party_size_flat" name="party_size" min="1" max="25" value="6">
                <p class="booking-help">Up to 25.</p>
            </div>
        </div>

        <!-- Per-person: individual adult / child / under-3 qty controls -->
        <div id="tier-section" style="display:none">
            <?php if (!empty($admTiers)): ?>
            <label class="tier-section-label">Party breakdown</label>
            <?php foreach ($admTiers as $tier):
                $isFree = (float)$tier['price'] <= 0;
            ?>
            <div class="tier-row">
                <div class="tier-info">
                    <span class="tier-name"><?= htmlspecialchars($tier['name']) ?></span>
                    <span class="tier-price"><?= $isFree ? 'Free' : '$' . number_format((float)$tier['price'], 2) ?></span>
                </div>
                <div class="tier-qty-ctrl">
                    <button type="button" class="tier-qty-btn" data-tier-dec="tier_<?= (int)$tier['id'] ?>" aria-label="Decrease">−</button>
                    <input type="number" id="tier_<?= (int)$tier['id'] ?>"
                           name="tier_qty[<?= (int)$tier['id'] ?>]"
                           class="tier-qty-input" value="0" min="0" max="25"
                           data-price="<?= number_format((float)$tier['price'], 2) ?>">
                    <button type="button" class="tier-qty-btn" data-tier-inc="tier_<?= (int)$tier['id'] ?>" aria-label="Increase">+</button>
                </div>
            </div>
            <?php endforeach; ?>
            <!-- Hidden party_size submitted for per-person types -->
            <input type="hidden" id="party_size_tier" name="party_size" value="0" disabled>
            <div id="tier-total" class="tier-total-display">Total: <strong id="tier-amount">$0.00</strong> · <span id="tier-people">0</span> guests</div>
            <?php else: ?>
            <p style="font-family:'Lora',serif;color:#8b5c2a;font-size:.9rem;">
                No admission tiers configured yet. Add Adult, Child, and Under 3 items via
                admin → Schema → inventory (category: Admissions).
            </p>
            <input type="hidden" id="party_size_tier" name="party_size" value="0" disabled>
            <?php endif; ?>
        </div>

        <div class="booking-row">
            <div>
                <label for="email">Email</label>
                <input type="email" id="email" name="email" maxlength="190" required>
            </div>
            <div>
                <label for="phone">Phone</label>
                <input type="tel" id="phone" name="phone" maxlength="40">
            </div>
        </div>

        <div class="booking-row">
            <div>
                <label for="booking_date">Preferred date</label>
                <input type="date" id="booking_date" name="booking_date" min="<?= date('Y-m-d') ?>" required>
            </div>
            <div>
                <label for="start_time">Preferred arrival time</label>
                <input type="time" id="start_time" name="start_time" required>
            </div>
        </div>

        <label for="message">Anything else? <span class="opt">(event details, questions)</span></label>
        <textarea id="message" name="message" maxlength="2000"></textarea>

        <button class="booking-submit" type="submit">Request Booking</button>
    </form>
    <?php endif; ?>
</div>

<?php include('../includes/footer.php'); ?>
