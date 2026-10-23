<?php
// booking/cancel.php — shown when visitor abandons the Stripe checkout.
// Booking remains in the DB (status=pending, payment_status=pending) so
// they can retry or be followed up manually.
header('Cache-Control: no-store, no-cache, must-revalidate, max-age=0');
require_once __DIR__ . '/../config.php';

$token = trim((string)($_GET['token'] ?? ''));
?><!doctype html>
<html lang="en"><head>
<meta charset="utf-8">
<meta name="viewport" content="width=device-width, initial-scale=1">
<title>Payment Cancelled — Bat Cave Road Farm</title>
<link href="https://fonts.googleapis.com/css2?family=Rye&family=Lora:ital,wght@0,400;0,600;1,400&display=swap" rel="stylesheet">
<style>
  *{box-sizing:border-box}
  body{margin:0;min-height:100vh;display:flex;align-items:center;justify-content:center;
    background:#f5ead6;font-family:Lora,Georgia,serif;color:#3b2010;padding:24px}
  .card{max-width:480px;width:100%;background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;
    border-radius:14px;padding:36px 28px;text-align:center;box-shadow:0 10px 30px rgba(59,32,16,.18)}
  .emoji{font-size:3rem;line-height:1}
  h1{font-family:Rye,Georgia,serif;font-size:1.5rem;margin:14px 0 8px;color:#5c3317}
  p{font-size:1rem;line-height:1.6;margin:0 0 16px}
  .actions{display:flex;flex-wrap:wrap;gap:12px;justify-content:center;margin-top:8px}
  a.btn{display:inline-block;text-decoration:none;font-weight:600;padding:12px 22px;border-radius:8px;border:2px solid #c9923a;transition:background .15s}
  a.btn.primary{background:#5c3317;color:#f5ead6}
  a.btn.primary:hover{background:#3b2010}
  a.btn.secondary{background:transparent;color:#5c3317}
  a.btn.secondary:hover{background:rgba(201,146,58,.15)}
  .note{font-size:.82rem;color:#8b5c2a;margin-top:18px;line-height:1.55}
</style>
</head><body>
<div class="card">
  <div class="emoji">🌾</div>
  <h1>Payment not completed</h1>
  <p>No worries — your booking request is still saved but hasn't been reserved yet. You can complete payment now or give us a call.</p>
  <div class="actions">
    <?php if ($token !== ''): ?>
      <a class="btn primary" href="/booking/checkout.php?token=<?= rawurlencode($token) ?>">Try payment again</a>
    <?php endif; ?>
    <a class="btn secondary" href="/booking/">Start over</a>
    <a class="btn secondary" href="/contact-us/">Contact us</a>
  </div>
  <p class="note">Need help? Call (828) 216-5488 or email support@batcaveroadfarm.com<br>Open Tuesday – Sunday, 10AM – 4PM.</p>
</div>
</body></html>
