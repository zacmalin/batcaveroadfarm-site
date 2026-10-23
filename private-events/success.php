<?php
// private-events/success.php
$pageTitle = 'Inquiry Received — Bat Cave Road Farm';
require '../includes/head.php';
include '../includes/header.php';
?>
<style>
.pe-success{max-width:520px;margin:60px auto;text-align:center;padding:0 20px}
.pe-success-card{background:linear-gradient(160deg,#fdf4e3,#f0ddb8);border:2px solid #c9923a;border-radius:14px;padding:40px 32px;box-shadow:0 10px 30px rgba(59,32,16,.14)}
.pe-success-card h1{font-family:'Rye',Georgia,serif;font-size:1.6rem;color:#5c3317;margin:16px 0 10px}
.pe-success-card p{font-family:'Lora',serif;color:#5c3317;line-height:1.65;margin:0 0 16px}
.pe-success-card .emoji{font-size:3rem;line-height:1}
</style>
<div class="pe-success">
    <div class="pe-success-card">
        <div class="emoji">🎉</div>
        <h1>Inquiry received!</h1>
        <p>Thanks for reaching out! We've got all your details and will be in touch within <strong>24 hours</strong> to discuss your event and put together a custom package.</p>
        <p>Check your inbox for a confirmation — and feel free to call us at <a href="tel:+18282165488" style="color:#5c3317;font-weight:bold">(828) 216-5488</a> if you need anything sooner.</p>
        <a class="cta-btn" href="/">Back to the farm</a>
    </div>
</div>
<?php require '../includes/footer.php'; ?>
