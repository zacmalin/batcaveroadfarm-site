# Fix Pack L: Booking Security Tokens (2026-10-23)

3 updated files. Needs Pack K installed first (uses `includes/auth.php` and `includes/rate_limit.php`). No database changes.

## What it does

| File | Change |
|---|---|
| `booking/index.php` | Booking form now includes a security token. |
| `booking/submit.php` | Rejects requests without a valid token (blocks forged or bot posts). Also limits each connection to 5 booking requests per hour, so nobody can fill your calendar with fake requests. The hidden bot trap stays. |
| `cancel-booking.php` | The "Yes, cancel it" button now carries a token. If the page sat open too long and the token expired, the guest sees "please tap the button once more" instead of an error. The per-booking secret link is still required, as before. |

Not changed:
- `review/index.php` has no form. It only redirects to your Google review link, so there is nothing to protect.
- `booking/checkout.php` to `stripe_session.php` (the pay step) already had a token.
- `booking/cancel.php` is just the "payment cancelled" message page, with no form.

## Install

1. Optional safety copy: in File Manager, compress `booking/index.php`, `booking/submit.php` and `cancel-booking.php` into `before-pack-l.zip`.
2. Upload `fix-pack-L-booking-tokens-3-files-2026-10-23.zip` to `public_html/batcaveroadfarm/`, choose Extract, and allow it to overwrite. Then delete the zip.

## Test (3 minutes)

1. Go to /booking/ and send a test request. You should see "Request Received!" and get the usual emails.
2. Open the cancel link from that booking's email, tap "Yes, cancel it", and you should see "Booking cancelled".
3. In Admin > Bookings, delete or ignore the test booking.

## Undo

Extract `before-pack-l.zip` over the site.

## Tested (PHP 8.5 + MariaDB 11.8)

- Valid booking saved. Missing or wrong token: rejected, nothing saved. 6th request in an hour from one connection: blocked.
- Cancel: the email link still shows the confirm page and does not cancel by itself. A post without a token, or from a different browser, shows "tap once more" and nothing is cancelled. A valid tap cancels. A wrong booking link shows "Link not found". An expired token, then tapping again, works.
- All 168 site PHP files pass `php -l`.
