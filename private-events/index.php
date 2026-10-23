<?php
// private-events/index.php — Private event & birthday party inquiry form.
$pageTitle    = 'Private Events & Birthday Parties | Bat Cave Road Farm';
$metaDesc     = 'Plan a private birthday party, school field trip, or farm buyout at Bat Cave Road Farm in Old Fort, NC. Tell us about your event and we\'ll put together a custom package.';
$canonicalUrl = 'https://batcaveroadfarm.com/private-events/';
$ogImage      = 'https://batcaveroadfarm.com/img/og-home.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.booking.css';

require '../includes/head.php';
include '../includes/header.php';

$errMap = [
    'csrf'    => 'Session expired — please try again.',
    'name'    => 'Please enter your name.',
    'email'   => 'Please enter a valid email address.',
    'type'    => 'Please select an event type.',
    'guests'  => 'Please enter the number of guests.',
    'db'      => 'Something went wrong saving your inquiry. Please call (828) 216-5488.',
];
$errKey = trim((string)($_GET['error'] ?? ''));
$errMsg = $errMap[$errKey] ?? '';

$csrfToken = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));

$eventTypes = [
    'birthday'   => '🎂 Birthday Party',
    'field_trip' => '🎒 School / Group Field Trip',
    'buyout'     => '🌾 Private Farm Buyout',
    'corporate'  => '🤝 Corporate / Team Event',
    'other'      => '✨ Other / Not Sure Yet',
];
$addons = [
    'guided_tour'    => 'Guided animal tour',
    'extended_time'  => 'Extended visit time',
    'custom_signage' => 'Custom signage / banner',
    'feeding_bags'   => 'Animal feeding bags for all guests',
    'private_barn'   => 'Private barn access',
    'photographer'   => 'Farm photographer (ask about availability)',
];
$heardFrom = ['Google search', 'Google Maps / Apple Maps', 'Facebook', 'Instagram', 'Friend or family', 'Returning visitor', 'Other'];
?>
<style>
.pe-wrap{max-width:680px;margin:0 auto;padding:0 16px 60px}
.pe-hero{text-align:center;margin:0 0 36px}
.pe-hero h1{font-family:'Rye',Georgia,serif;font-size:2rem;color:var(--cream,#f5ead6);text-shadow:2px 2px 8px rgba(0,0,0,.8);margin:0 0 10px}
.pe-hero p{font-family:'Lora',serif;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 4px rgba(0,0,0,.7);font-size:1rem;line-height:1.65;max-width:540px;margin:0 auto}
.pe-section{background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;border-radius:12px;padding:22px 24px;margin:0 0 20px}
.pe-section h2{font-family:'Rye',Georgia,serif;font-size:1.05rem;color:#5c3317;margin:0 0 16px}
.pe-grid{display:grid;grid-template-columns:1fr 1fr;gap:14px}
.pe-field{display:flex;flex-direction:column;gap:5px}
.pe-field.full{grid-column:1/-1}
.pe-label{font-family:'Lora',serif;font-size:.82rem;font-weight:600;color:#8b5c2a;text-transform:uppercase;letter-spacing:.05em}
.pe-label .opt{font-weight:400;opacity:.65;text-transform:none}
.pe-input,.pe-select,.pe-textarea{width:100%;padding:10px 14px;border:2px solid #c9923a;border-radius:9px;font-family:'Lora',serif;font-size:.95rem;color:#3b2010;background:#fffdf7;box-sizing:border-box}
.pe-input:focus,.pe-select:focus,.pe-textarea:focus{outline:none;border-color:#5c3317;box-shadow:0 0 0 3px rgba(201,146,58,.25)}
.pe-textarea{resize:vertical;min-height:90px}
.pe-type-grid{display:grid;grid-template-columns:1fr 1fr;gap:10px}
.pe-type-label{display:flex;align-items:center;gap:10px;background:#fffdf7;border:2px solid #e8d5b0;border-radius:9px;padding:10px 14px;cursor:pointer;font-family:'Lora',serif;font-size:.88rem;color:#5c3317;transition:border-color .15s,background .15s}
.pe-type-label:has(input:checked){border-color:#c9923a;background:#fdf0d4}
.pe-type-label input{accent-color:#c9923a;width:16px;height:16px;flex-shrink:0}
.pe-addons{display:grid;grid-template-columns:1fr 1fr;gap:8px}
.pe-addon-label{display:flex;align-items:center;gap:8px;font-family:'Lora',serif;font-size:.85rem;color:#5c3317;cursor:pointer}
.pe-addon-label input{accent-color:#c9923a}
.pe-error{background:#fde8e8;border:1px solid #f5c6c6;border-radius:9px;padding:12px 16px;color:#b91c1c;font-family:'Lora',serif;font-size:.9rem;margin:0 0 20px}
.pe-submit{text-align:center;margin:8px 0}
.pe-submit .cta-btn{font-size:1.1rem;padding:15px 40px}
.pe-note{font-family:'Lora',serif;font-size:.82rem;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.6);text-align:center;margin-top:12px;line-height:1.55}
@media(max-width:520px){.pe-grid,.pe-type-grid,.pe-addons{grid-template-columns:1fr}}
</style>

<div class="pe-wrap">
    <div class="pe-hero">
        <h1>🎉 Private Events & Parties</h1>
        <p>From intimate birthday parties to full farm buyouts — we'd love to host your group. Tell us about your vision and we'll put together a custom package just for you.</p>
    </div>

    <?php if ($errMsg): ?>
    <div class="pe-error"><?= htmlspecialchars($errMsg) ?></div>
    <?php endif; ?>

    <form method="POST" action="/private-events/submit.php">
        <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrfToken, ENT_QUOTES) ?>">
        <input type="hidden" name="website" value=""><!-- honeypot -->

        <!-- Event type -->
        <div class="pe-section">
            <h2>What kind of event?</h2>
            <div class="pe-type-grid">
                <?php foreach ($eventTypes as $val => $label): ?>
                <label class="pe-type-label">
                    <input type="radio" name="event_type" value="<?= htmlspecialchars($val) ?>" required>
                    <?= htmlspecialchars($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Date & headcount -->
        <div class="pe-section">
            <h2>Date &amp; guest count</h2>
            <div class="pe-grid">
                <div class="pe-field">
                    <label class="pe-label">Preferred date <span class="opt">(optional)</span></label>
                    <input type="date" name="preferred_date" class="pe-input"
                           min="<?= date('Y-m-d', strtotime('+7 days')) ?>">
                </div>
                <div class="pe-field" style="justify-content:flex-end">
                    <label class="pe-addon-label" style="margin-top:auto;padding-bottom:10px">
                        <input type="checkbox" name="date_flexible" value="1">
                        Dates are flexible
                    </label>
                </div>
                <div class="pe-field">
                    <label class="pe-label">Adults</label>
                    <input type="number" name="adult_count" class="pe-input" min="0" max="200" placeholder="0">
                </div>
                <div class="pe-field">
                    <label class="pe-label">Children</label>
                    <input type="number" name="child_count" class="pe-input" min="0" max="200" placeholder="0">
                </div>
                <div class="pe-field full">
                    <label class="pe-label">Birthday child's age <span class="opt">(for birthday parties)</span></label>
                    <input type="number" name="birthday_age" class="pe-input" min="1" max="99" placeholder="Leave blank if not a birthday">
                </div>
            </div>
        </div>

        <!-- Add-ons -->
        <div class="pe-section">
            <h2>Add-ons you're interested in <span style="font-family:'Lora',serif;font-size:.85rem;font-weight:400;color:#8b5c2a">(check all that apply)</span></h2>
            <div class="pe-addons">
                <?php foreach ($addons as $val => $label): ?>
                <label class="pe-addon-label">
                    <input type="checkbox" name="addons[]" value="<?= htmlspecialchars($val) ?>">
                    <?= htmlspecialchars($label) ?>
                </label>
                <?php endforeach; ?>
            </div>
        </div>

        <!-- Details -->
        <div class="pe-section">
            <h2>Tell us more</h2>
            <div class="pe-grid">
                <div class="pe-field full">
                    <label class="pe-label">Special requests or questions <span class="opt">(optional)</span></label>
                    <textarea name="special_requests" class="pe-textarea"
                              placeholder="Tell us about your vision, any special needs, themes, or questions…"></textarea>
                </div>
                <div class="pe-field full">
                    <label class="pe-label">How did you hear about us? <span class="opt">(optional)</span></label>
                    <select name="heard_from" class="pe-select">
                        <option value="">— Select —</option>
                        <?php foreach ($heardFrom as $h): ?>
                        <option value="<?= htmlspecialchars($h) ?>"><?= htmlspecialchars($h) ?></option>
                        <?php endforeach; ?>
                    </select>
                </div>
            </div>
        </div>

        <!-- Contact info -->
        <div class="pe-section">
            <h2>Your details</h2>
            <div class="pe-grid">
                <div class="pe-field full">
                    <label class="pe-label">Your name *</label>
                    <input type="text" name="customer_name" class="pe-input" required
                           autocomplete="name" placeholder="Full name">
                </div>
                <div class="pe-field">
                    <label class="pe-label">Email *</label>
                    <input type="email" name="email" class="pe-input" required
                           autocomplete="email" placeholder="you@example.com">
                </div>
                <div class="pe-field">
                    <label class="pe-label">Phone <span class="opt">(optional)</span></label>
                    <input type="tel" name="phone" class="pe-input"
                           autocomplete="tel" placeholder="(828) 555-0100">
                </div>
            </div>
        </div>

        <div class="pe-submit">
            <button type="submit" class="cta-btn">Send My Inquiry →</button>
        </div>
        <p class="pe-note">We typically respond within 24 hours. For urgent requests call <a href="tel:+18282165488" style="color:var(--cream,#f5ead6)">(828) 216-5488</a>.</p>
    </form>
</div>

<?php require '../includes/footer.php'; ?>
