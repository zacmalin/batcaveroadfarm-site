# Roost Rewards household invitation delivery gate

Status: **blocked / not launch-ready** (2026-11-08). This is an implementation and release gate, not authorization to send messages.

## Current verified code boundaries
- `bcrf_roost_create_household_invitation()` commits a pending invitation, stores only SHA-256 digests of the recipient email and bearer token, and returns the plaintext token once. It **does not send**.
- `bcrf_roost_household_invitation_email()` renders HTML/plaintext email with the one-time code in the body, not in a URL. It **does not send**.
- `accept-invitation.php` accepts codes by POST only, requires login, verified customer email, staff-approved adult verification, current household-join consent, CSRF and rate limiting.
- The existing `reward_email_outbox` is scoped to `welcome` and `expiration_reminder`; **do not reuse its table for invitation tokens** without a separately reviewed migration and encryption design.

## Required implementation before enabling invitation issuance
1. Create a separate, feature-flagged invitation issuance controller for verified managers, with CSRF, durable IP/account rate limiting, input normalization, and generic error responses. The existing read-only invitation review page must remain read-only.
2. Design an **atomic issuance-and-delivery intent**. Do not issue a bearer code and then silently discard it when SMTP is unavailable. Persist a minimal delivery job with encrypted token material (authenticated encryption and versioned key ID), recipient address encrypted at rest, invitation ID, expiry, retry count, status and dedupe key. Never persist a plaintext token or email address in logs, query strings, or analytics. Ensure an operator can rotate keys and purge completed/expired encrypted payloads.
3. Use an independently configured encryption key outside the database and repository. Fail closed when the key, authenticated encryption support, SMTP configuration, durable queue, or worker is unavailable. Do not use a static hash of a token as reversible delivery material.
4. Have a bounded worker decrypt only after confirming the invitation is still pending and unexpired and the inviter still has verified manager authority. Check service-email suppression and SMTP availability immediately before dispatch. The worker must not email on a mere page GET.
5. Use the existing branded `bcf_send_mail()` wrapper with `transport=smtp_required`; explicitly test its actual return/exception semantics. Record a non-sensitive delivery outcome; on definitive failure, allow safe retry or cancellation, never claim delivery succeeded.
6. Add tests for transaction rollback, deduplication, retries, stale/expired/revoked invitation suppression, missing keys, recipient privacy, code confidentiality, queue locking and simultaneous workers. Staging should use a sink/test mailbox with no external recipients.
7. Add separate **explicit launch approval** for invitation issuance and for the sender/worker. Default both off. Require migration review, staging acceptance, monitoring, rollback instructions and operator sign-off.

## Do not do
- Do not email the bearer token from the current issuance service directly as an untracked side effect.
- Do not store the token plaintext in the existing rewards outbox, logs, URL parameters, or customer session.
- Do not enable the invitation email template, scheduler, issuance endpoint, or production flags as part of a documentation or test change.

## Release evidence required
Record passing CI, a staging issuance-to-acceptance test with an actual sink-delivered message, expiry and revocation tests, migration dry run, secrets/config readiness, worker failure recovery, and an approved rollback plan. Production deployment remains separately gated.
