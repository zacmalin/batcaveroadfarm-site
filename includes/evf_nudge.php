<?php
// includes/evf_nudge.php
// Soft, dismissible nudge for logged-in accounts that haven't confirmed their email.
// Included site-wide by header.php. CSP-safe: no inline JS — the resend is a normal
// form POST, and dismiss is a plain link that sets a session flag for this session.
// Self-contained: pulls in auth.php (defs-only, idempotent) so it works on any page.
require_once __DIR__ . '/auth.php';
if (is_logged_in() && empty($_SESSION['email_verified']) && empty($_SESSION['evf_nudge_dismissed'])):
    $evf_here = (string)($_SERVER['REQUEST_URI'] ?? '/');
?>
<div class="evf-nudge" role="status">
  <style>
    .evf-nudge{background:#fbf3d9;border-bottom:1px solid #e7d8a4;color:#5c4a1c;
      font-size:.92rem;line-height:1.4;padding:.5rem 1rem;display:flex;align-items:center;
      justify-content:center;gap:.4rem .85rem;flex-wrap:wrap;text-align:center}
    .evf-nudge p{margin:0}
    .evf-nudge form{margin:0;display:inline}
    .evf-nudge .evf-resend{background:none;border:0;padding:0;margin:0;color:#7a5c12;
      font:inherit;font-weight:700;text-decoration:underline;cursor:pointer}
    .evf-nudge .evf-resend:hover{color:#5c4a1c}
    .evf-nudge .evf-dismiss{margin-left:.25rem;color:#a3915c;text-decoration:none;
      font-weight:700;font-size:1.15rem;line-height:1}
    .evf-nudge .evf-dismiss:hover{color:#5c4a1c}
  </style>
  <p>📧 Please confirm your email to finish setting up your account.</p>
  <form method="POST" action="/account/resend_verification.php">
    <?= csrf_field() ?>
    <button type="submit" class="evf-resend">Resend confirmation email</button>
  </form>
  <a class="evf-dismiss" href="/account/dismiss_nudge.php?next=<?= htmlspecialchars(urlencode($evf_here), ENT_QUOTES) ?>" aria-label="Dismiss this notice" title="Dismiss">&times;</a>
</div>
<?php endif; ?>
