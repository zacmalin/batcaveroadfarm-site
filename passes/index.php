<?php
// passes/index.php — annual pass purchase page.
$pageTitle    = 'Annual Passes — Bat Cave Road Farm';
$metaDesc     = 'Become a Bat Cave Road Farm passholder. Unlimited visits for 12 months — individual, family, and founding member passes available.';
$canonicalUrl = 'https://batcaveroadfarm.com/passes/';
$ogImage      = 'https://batcaveroadfarm.com/img/og-home.jpg';
$pageCSS      = 'https://batcaveroadfarm.com/css/min.passes.css';
$pageJS       = ['https://batcaveroadfarm.com/js/qrcode.min.js',
                 'https://batcaveroadfarm.com/js/min.passes.js'];

require '../includes/head.php';
include '../includes/header.php';

// Load pass types
$passTypes = [];
try {
    $passTypes = $pdo->query("SELECT * FROM pass_types WHERE is_active = 1 ORDER BY sort_order, id")
                     ->fetchAll(PDO::FETCH_ASSOC) ?: [];
} catch (Throwable $e) { /* table not yet created */ }

// Founding member availability
$foundingLeft = null;
foreach ($passTypes as $pt) {
    if ($pt['is_founding'] && $pt['founding_qty_limit']) {
        try {
            $sold = (int)$pdo->prepare("SELECT COUNT(*) FROM passes WHERE pass_type_id=? AND status != 'cancelled'")
                             ->execute([$pt['id']]) ? $pdo->query("SELECT COUNT(*) FROM passes WHERE pass_type_id={$pt['id']} AND status != 'cancelled'")->fetchColumn() : 0;
            $foundingLeft = max(0, (int)$pt['founding_qty_limit'] - $sold);
        } catch (Throwable $e) { $foundingLeft = null; }
    }
}

$errMap = [
    'csrf'     => 'Session expired — please try again.',
    'type'     => 'Please select a pass type.',
    'name'     => 'Please enter the passholder\'s name.',
    'email'    => 'Please enter a valid email address.',
    'sold_out' => 'Sorry, Founding Member passes are sold out.',
    'db'       => 'Something went wrong — please call (828) 216-5488.',
];
$errKey = trim((string)($_GET['error'] ?? ''));
$errMsg = $errMap[$errKey] ?? '';
$csrf   = $_SESSION['csrf_token'] ?? ($_SESSION['csrf_token'] = bin2hex(random_bytes(32)));
?>

<div class="passes-wrap">
    <div class="passes-hero">
        <h1><span aria-hidden="true">🎟</span> Annual Passes</h1>
        <p>One payment, unlimited visits for a full year. The more you come, the more it pays off — and we love seeing familiar faces.</p>
    </div>

    <?php if ($errMsg): ?>
    <div class="pass-error"><?= htmlspecialchars($errMsg) ?></div>
    <?php endif; ?>

    <?php if (empty($passTypes)): ?>
    <p style="font-family:'Lora',serif;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.6);text-align:center">
        Pass sales coming soon — call <a href="tel:+18282165488" style="color:inherit">(828) 216-5488</a> to enquire.
    </p>
    <?php else: ?>

    <!-- Pass type cards -->
    <div class="passes-grid">
        <?php foreach ($passTypes as $pt):
            $perks = json_decode($pt['perks'] ?? '[]', true) ?: [];
            $isFounding = !empty($pt['is_founding']);
            $soldOut = $isFounding && $foundingLeft !== null && $foundingLeft <= 0;
        ?>
        <div class="pass-card <?= $isFounding ? 'is-founding' : '' ?>">
            <?php if ($isFounding): ?>
            <div class="pass-badge">⭐ Limited Edition</div>
            <?php endif; ?>

            <div class="pass-label"><?= htmlspecialchars($pt['label']) ?></div>
            <div class="pass-tagline"><?= htmlspecialchars($pt['tagline'] ?? '') ?></div>
            <div class="pass-price">$<?= number_format((float)$pt['price'], 0) ?></div>
            <div class="pass-price-note">per year · up to <?= (int)$pt['max_guests'] ?> guest<?= $pt['max_guests'] > 1 ? 's' : '' ?> per visit</div>

            <ul class="pass-perks">
                <?php foreach ($perks as $perk): ?>
                <li class="pass-perk"><?= htmlspecialchars($perk) ?></li>
                <?php endforeach; ?>
            </ul>

            <?php if ($isFounding && $foundingLeft !== null): ?>
            <div class="pass-qty-note">
                <?= $soldOut ? 'Sold out' : $foundingLeft . ' of ' . (int)$pt['founding_qty_limit'] . ' remaining' ?>
            </div>
            <?php endif; ?>

            <?php if (!$soldOut): ?>
            <a href="#buy-form" class="pass-select-btn"
               data-pass-id=REDACTED
               data-pass-label=REDACTED
                Get this pass →
            </a>
            <?php else: ?>
            <span class="pass-select-btn" style="opacity:.5;cursor:not-allowed">Sold out</span>
            <?php endif; ?>
        </div>
        <?php endforeach; ?>
    </div>

    <!-- Purchase form -->
    <div id="buy-form"></div>
    <div id="pass-form-wrap" class="pass-form-wrap" style="display:none">
        <h2>Your pass details</h2>
        <p class="pass-form-selected">Selected: <strong id="selected-pass-label">—</strong></p>

        <form method="POST" action="/passes/submit.php">
            <input type="hidden" name="csrf_token" value="<?= htmlspecialchars($csrf, ENT_QUOTES) ?>">
            <input type="hidden" name="website" value=""><!-- honeypot -->

            <div class="pass-fields">
                <div class="pass-field full">
                    <label class="pass-label-sm">Pass type *</label>
                    <select id="pass_type_id" name="pass_type_id" class="pass-input" required>
                        <option value="">— Select a pass —</option>
                        <?php foreach ($passTypes as $pt):
                            $isFounding = !empty($pt['is_founding']);
                            $soldOut = $isFounding && $foundingLeft !== null && $foundingLeft <= 0;
                            if ($soldOut) continue;
                        ?>
                        <option value="<?= (int)$pt['id'] ?>">
                            <?= htmlspecialchars($pt['label']) ?> — $<?= number_format((float)$pt['price'], 0) ?>/yr
                        </option>
                        <?php endforeach; ?>
                    </select>
                </div>
                <div class="pass-field">
                    <label class="pass-label-sm">Passholder name *</label>
                    <input type="text" name="holder_name" class="pass-input" required
                           autocomplete="name" placeholder="Full name">
                </div>
                <div class="pass-field">
                    <label class="pass-label-sm">Email *</label>
                    <input type="email" name="email" class="pass-input" required
                           autocomplete="email" placeholder="you@example.com">
                </div>
                <div class="pass-field full">
                    <label class="pass-label-sm">Phone <span style="font-weight:400;opacity:.7">(optional)</span></label>
                    <input type="tel" name="phone" class="pass-input"
                           autocomplete="tel" placeholder="(828) 555-0100">
                </div>
            </div>

            <div class="pass-submit">
                <button type="submit" class="cta-btn">Continue to secure checkout →</button>
            </div>
            <p class="pass-secure">🔒 Secure payment via Stripe. Your pass activates immediately after checkout.</p>
        </form>
    </div>

    <!-- FAQ -->
    <div style="background:rgba(245,234,214,.08);border:1px solid rgba(201,146,58,.3);border-radius:12px;padding:22px 24px;margin-top:8px">
        <h3 style="font-family:'Rye',Georgia,serif;color:var(--cream,#f5ead6);font-size:1rem;margin:0 0 14px;text-shadow:1px 1px 4px rgba(0,0,0,.6)">Pass FAQ</h3>
        <?php foreach ([
            'When does my pass start?' => 'Immediately after purchase. Your 12 months start the day you buy.',
            'Can I bring guests?' => 'Your pass covers up to the listed number of guests per visit. Anyone over that limit pays regular admission.',
            'How do I use it?' => 'You\'ll receive a digital pass link by email. Show it to staff at the gate — we\'ll check your name and status.',
            'What about the farmstand discount?' => 'Just mention you\'re a passholder at the farmstand register and we\'ll take 10% off.',
            'Can I buy as a gift?' => 'Absolutely. Enter the recipient\'s name and their email so they receive the pass directly.',
        ] as $q => $a): ?>
        <div style="margin-bottom:12px">
            <div style="font-family:'Lora',serif;font-weight:600;color:var(--cream,#f5ead6);text-shadow:1px 1px 3px rgba(0,0,0,.5);font-size:.88rem"><?= htmlspecialchars($q) ?></div>
            <div style="font-family:'Lora',serif;color:var(--cream-dark,#e8d5b0);text-shadow:1px 1px 3px rgba(0,0,0,.5);font-size:.85rem;line-height:1.55"><?= htmlspecialchars($a) ?></div>
        </div>
        <?php endforeach; ?>
    </div>
    <?php endif; ?>
</div>

<?php require '../includes/footer.php'; ?>
