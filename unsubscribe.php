<?php
// unsubscribe.php — newsletter opt-out.
//  1. One-click (RFC 8058): Gmail / Apple Mail "Unsubscribe" button POSTs
//     List-Unsubscribe=One-Click to ?e=..&t=..  -> unsubscribed instantly.
//  2. Link in the email (GET ?e=..&t=..): shows a one-button confirm page.
//     (Not instant on GET: Outlook/spam filters pre-open links and would
//      unsubscribe people by accident.)
//  3. No / bad token: plain form, email prefilled when known.
require_once __DIR__ . '/config.php';
require_once __DIR__ . '/includes/unsubscribe.php';

$email = trim((string)($_GET['e'] ?? $_POST['e'] ?? ''));
$token = trim((string)($_GET['t'] ?? $_POST['t'] ?? ''));
$valid = $email !== '' && filter_var($email, FILTER_VALIDATE_EMAIL) && bcrf_unsub_verify($email, $token);

/** Mark the address unsubscribed. Returns true if the query ran. */
function bcrf_do_unsubscribe(PDO $pdo, string $email, string $how): bool
{
    try {
        $st = $pdo->prepare("UPDATE mailing_list SET is_subscribed = 0 WHERE LOWER(email) = LOWER(?)");
        $st->execute([$email]);
        if ($st->rowCount() > 0) {
            $audit = __DIR__ . '/admin/includes/audit.php';
            if (is_file($audit)) {
                require_once $audit;
                if (function_exists('erp_audit')) erp_audit($pdo, 'update', 'mailing_list', null, $email . ' - unsubscribed (' . $how . ')', 'Subscriber (self)');
            }
        }
        return true;
    } catch (Throwable $e) {
        error_log('[unsubscribe] failed: ' . $e->getMessage());
        return false;
    }
}

$isPost = ($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST';

// ── 1. One-click from the mail app: answer with plain text, no page ─────────
if ($isPost && ($_POST['List-Unsubscribe'] ?? '') === 'One-Click') {
    header('Content-Type: text/plain; charset=utf-8');
    header('X-Robots-Tag: noindex');
    if (!$valid) { http_response_code(400); echo 'Invalid unsubscribe link.'; exit; }
    if (!isset($pdo) || !bcrf_do_unsubscribe($pdo, $email, 'one-click')) { http_response_code(500); echo 'Please try again later.'; exit; }
    echo 'You have been unsubscribed.'; exit;
}

$message = '';
$done    = false;

if ($isPost) {
    if ($valid) {
        // 2b. Confirm button on the signed link
        $done = isset($pdo) && bcrf_do_unsubscribe($pdo, $email, 'email link');
    } else {
        // 3b. Manual form
        $formEmail = filter_var(trim((string)($_POST['email'] ?? '')), FILTER_VALIDATE_EMAIL);
        if ($formEmail) {
            $done  = isset($pdo) && bcrf_do_unsubscribe($pdo, $formEmail, 'form');
            $email = $formEmail;
        } else {
            $message = 'Please enter a valid email address.';
        }
    }
    if ($done) {
        // Same message whether or not the address was on the list (privacy).
        $message = 'Done. ' . $email . ' will no longer receive Bat Cave Road Farm newsletters.';
    } elseif ($message === '') {
        $message = 'Something went wrong. Please try again, or email support@batcaveroadfarm.com.';
    }
}

$pageTitle = 'Unsubscribe | Bat Cave Road Farm';
$pageCSS   = 'https://batcaveroadfarm.com/css/min.contactus.css';
header('X-Robots-Tag: noindex');
require_once __DIR__ . '/includes/head.php';
require_once __DIR__ . '/includes/header.php';
$h = fn($s) => htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
?>
<div class="contact-form reveal">
    <h2>Unsubscribe</h2>

    <?php if ($message): ?>
        <div class="p-3 mb-4 text-xs bg-stone-50 border border-stone-200 text-stone-800 rounded font-medium" role="status"><?= $h($message) ?></div>
    <?php endif; ?>

    <?php if ($done): ?>
        <p>Changed your mind? You can sign up again anytime on our <a href="/">home page</a>.</p>

    <?php elseif ($valid): ?>
        <p>Stop sending newsletters to <strong><?= $h($email) ?></strong>?</p>
        <form method="POST" action="/unsubscribe.php" class="space-y-4 text-sm">
            <input type="hidden" name="e" value="<?= $h($email) ?>">
            <input type="hidden" name="t" value="<?= $h($token) ?>">
            <button type="submit" class="form-submit">Yes, unsubscribe me</button>
        </form>

    <?php else: ?>
        <p>Enter your email address to stop receiving our newsletters.</p>
        <form method="POST" action="/unsubscribe.php" class="space-y-4 text-sm">
            <div class="form-group">
                <label for="unsub-email">Email address</label>
                <input type="email" id="unsub-email" name="email" required autocomplete="email" placeholder="name@example.com" value="<?= $h(filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '') ?>">
            </div>
            <button type="submit" class="form-submit">Unsubscribe</button>
        </form>
    <?php endif; ?>
</div>
<p></p>
<?php require_once __DIR__ . '/includes/footer.php'; ?>
