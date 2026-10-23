<?php
// includes/stripe.php — lightweight Stripe API helper for batcaveroadfarm.com.
// No SDK dependency: pure cURL + signature verification.
// Requires BCRF_STRIPE_SK and BCRF_STRIPE_WH_SEC from config.php / bcrf-secrets.php.

if (!function_exists('stripe_api')) :

/**
 * Make a Stripe REST API call. Returns the decoded JSON array.
 * Throws RuntimeException on curl failure, non-JSON, or API error.
 */
function stripe_api(string $method, string $endpoint, array $data = []): array {
    $sk  = defined('BCRF_STRIPE_SK') ? BCRF_STRIPE_SK : '';
    $url = 'https://api.stripe.com/v1/' . ltrim($endpoint, '/');

    $ch = curl_init();
    curl_setopt_array($ch, [
        CURLOPT_URL            => $url,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_USERPWD        => $sk . ":",  // (line reconstructed — redaction artifact)
        CURLOPT_HTTPHEADER     => ['Content-Type: application/x-www-form-urlencoded'],
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    if ($method === 'POST') {
        curl_setopt($ch, CURLOPT_POST,       true);
        curl_setopt($ch, CURLOPT_POSTFIELDS, http_build_query($data));
    }
    $body = curl_exec($ch);
    $err  = curl_error($ch);

    if ($body === false) throw new RuntimeException('Stripe cURL error: ' . $err);
    $resp = json_decode($body, true);
    if (!is_array($resp))           throw new RuntimeException('Stripe API non-JSON response');
    if (isset($resp['error']))      throw new RuntimeException('Stripe: ' . ($resp['error']['message'] ?? 'Unknown error'));
    return $resp;
}

/**
 * Create a hosted Checkout Session. Returns the full session array including ->url.
 * $booking: bookings row; $type: booking_types row.
 */
function stripe_create_checkout_session(array $booking, array $type): array {
    $total   = (float)($booking['amount_total'] ?? 0);
    $amtCents = (int)round($total * 100);
    if ($amtCents <= 0) throw new RuntimeException('Cannot create a Stripe session for a zero-amount booking.');

    $model     = $type['pricing_model'] ?? 'flat';
    $pplLabel  = $model === 'per_person' ? ' · ' . (int)$booking['party_size'] . ' guests' : '';
    $itemName  = htmlspecialchars_decode($type['label'] ?? 'Farm Booking') . $pplLabel;

    $base    = rtrim(defined('BCRF_SOCIAL_BASE_URL') ? BCRF_SOCIAL_BASE_URL : 'https://batcaveroadfarm.com', '/');
    $tok     = rawurlencode((string)$booking['token']);

    return stripe_api('POST', 'checkout/sessions', [
        'mode'                                             => 'payment',
        'line_items[0][price_data][currency]'              => 'usd',
        'line_items[0][price_data][unit_amount]'           => $amtCents,
        'line_items[0][price_data][product_data][name]'    => $itemName,
        'line_items[0][price_data][product_data][description]' =>
            booking_fmt_date($booking['booking_date']) . ' at ' . booking_fmt_time($booking['start_time']),
        'line_items[0][quantity]'                          => 1,
        'customer_email'                                   => $booking['email'],
        'success_url'                                      => $base . '/booking/success.php?token=' . $tok,
        'cancel_url'                                       => $base . '/booking/cancel.php?token=' . $tok,
        'metadata[booking_id]'                             => (string)$booking['id'],
        'metadata[booking_token]'                          => (string)$booking['token'],
        'payment_intent_data[metadata][booking_id]'        => (string)$booking['id'],
        'payment_intent_data[metadata][booking_token]'     => (string)$booking['token'],
    ]);
}

/** Retrieve a Checkout Session by ID. */
function stripe_retrieve_session(string $sessionId): array {
    return stripe_api('GET', 'checkout/sessions/' . urlencode($sessionId));
}

/**
 * Issue a full refund for a checkout session.
 * Retrieves the session to get the payment_intent, then refunds it.
 * Returns the Stripe refund object array.
 */
function stripe_refund_session(string $sessionId): array {
    $session = stripe_retrieve_session($sessionId);
    $paymentIntent = (string)($session['payment_intent'] ?? '');
    if ($paymentIntent === '') throw new RuntimeException('No payment_intent found on session ' . $sessionId);
    return stripe_api('POST', 'refunds', ['payment_intent' => $paymentIntent]);
}

/**
 * Verify a Stripe webhook request.
 * Returns the decoded event array on success; throws RuntimeException on failure.
 */
function stripe_verify_webhook(string $payload, string $sigHeader, string $secret): array {
    $t = null; $sigs = [];
    foreach (explode(',', $sigHeader) as $part) {
        [$k, $v] = array_pad(explode('=', $part, 2), 2, '');
        if ($k === 't')  $t      = (int)$v;
        if ($k === 'v1') $sigs[] = trim($v);
    }
    if ($t === null || empty($sigs)) throw new RuntimeException('Invalid Stripe-Signature header');
    if (abs(time() - $t) > 300)     throw new RuntimeException('Webhook timestamp too old (replay protection)');

    $expected = hash_hmac('sha256', $t . '.' . $payload, $secret);
    $valid = false;
    foreach ($sigs as $sig) { if (hash_equals($expected, $sig)) { $valid = true; break; } }
    if (!$valid) throw new RuntimeException('Stripe webhook signature mismatch');

    $event = json_decode($payload, true);
    if (!is_array($event)) throw new RuntimeException('Webhook payload is not valid JSON');
    return $event;
}

endif;
