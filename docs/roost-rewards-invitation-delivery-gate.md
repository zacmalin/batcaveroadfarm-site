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

## Implemented on draft PR #18 (not activated)
- Migration 0024 creates a separate authenticated-encrypted invitation outbox, including a durable `dispatch_started_at` boundary. Issuance can enqueue inside the invitation transaction when explicitly configured.
- The opt-in worker verifies invitation eligibility, decrypts the payload, rechecks eligibility, and marks dispatch started before invoking the injected transport. It does not schedule itself. The SMTP adapter requires `smtp_enabled=true` and `transport=smtp_required`, so legacy PHP mail fallback is prohibited.
- Pre-dispatch abandoned claims may be retried up to three total attempts. Once dispatch may have begun, a crash, timeout, or lost acknowledgement must **fail closed**; never automatically resend. Recovery records `delivery_unknown` for stale post-dispatch claims.
- The administrator Action Center reads only aggregate counts when migration 0024 exists. Failed deliveries, stale processing claims, overdue ready jobs, and ambiguous post-dispatch failures are visible without addresses or tokens. No worker is invoked by the dashboard.
- Disposable MySQL integration tests cover claim/recovery, transient retry, ambiguous delivery, URL preflight, encrypted payload checks, and aggregate monitoring. CI status must be checked on the latest commit; prior successes do not prove staging or production readiness.

## Manual review and recovery policy
1. For a `failed` row with `dispatch_started_at` populated, treat delivery as **unknown**, even if the transport returned an error. Do not reset the row to pending or automatically resend: the recipient might already have the invitation.
2. Confirm whether the recipient received the invitation using an authorized support workflow that does not expose the bearer code or encrypted payload in logs or tickets. If uncertain, revoke the old invitation and issue a **new** code only through an approved, rate-limited manager workflow after confirming recipient identity and consent.
3. For a stale `processing` row with no dispatch marker, recovery can return it to pending while attempts remain; exhausted rows remain failed. Do not manually alter attempts or clear the dispatch marker.
4. Investigate delayed ready jobs and missing SMTP configuration before enabling a scheduler. A healthy dashboard does **not** establish that mail is being delivered.
5. Do not put recipient addresses, tokens, encryption keys, ciphertext, or raw SMTP exception strings in monitoring, analytics, or operational notes.

## Remaining blockers
Manager-facing issuing UI and authorized support/revocation flow; encrypted-payload retention and key rotation/purge procedure; controlled staging SMTP sink delivery and browser tests; production read-only schema preflight; migration rehearsal and backup/rollback plan; explicit separate approval to deploy, migrate, enable issuance, and start sending. No live send is authorized.

## Retention and suppression maintenance (not scheduled)
- `bcrf_roost_suppress_inactive_invitation_delivery($pdo, $batchSize)` moves only **pending** rows linked to expired or non-pending invitations into `suppressed`; it does not interrupt processing claims or send mail. Run with a bounded batch (1–500) and repeat until zero only in an approved maintenance workflow.
- `bcrf_roost_purge_invitation_delivery_payloads($pdo, $minimumAgeDays, $batchSize)` blanks reversible encrypted nonce/ciphertext/tag for terminal `sent`, `suppressed`, or `failed` rows older than the chosen retention interval (default 7 days), retaining status, dispatch markers, and error codes. It rechecks age and terminal status in the update. The original invitation record remains intact.
- Neither function is connected to a scheduler or customer request. **Do not activate maintenance automatically** until retention policy, key rotation, backup retention, operational authorization, and staging tests have been approved.
- Key rotation must preserve the prior decryption key while any unpurged pending or processing jobs use its key ID. This implementation currently accepts one configured key ID per worker; a multi-key decrypting keyring and an operational rotation runbook remain launch gates.
