# Roost Rewards — launch-blocker audit and execution order

Audit date: 2026-11-08/09 UTC. Scope: feature branch `feature/farm-rewards-ledger-20261108`, draft PR #18. **Code inspection and CI only; no staging browser or production validation.** No merge, deployment, migration, feature activation or live email authorized.

## Actual implementation findings

| Area | Code evidence | Classification | Exit criterion |
| --- | --- | --- | --- |
| Ledger, spend/visit/refund rules | `includes/rewards.php`, attendance and refund services; disposable MySQL CI green on `efef462` | Implemented core, not end-to-end verified | Staging real checkout/visit/refund replay and negative balance tests |
| POS sale/refund ingestion | `includes/roost-pos-outbox-integration.php` returns `false` from `bcrf_roost_pos_enqueue_enabled()` | **P0 launch blocker** | Approved gated production adapter, atomic sale/refund enqueue, staging reconciliation |
| POS dispatch to ledger | `includes/roost-pos-outbox-dispatch.php` returns `false` from dispatch gate and preflight expressly does not post | **P0 launch blocker** | Real bounded idempotent dispatcher, recovery, monitoring, zero-point receipts, staging tests |
| Redemption | `includes/roost-redemption-tier-preview.php` is read-only and no customer redemption route in `roost-rewards/`; benefits not configured | **P0 launch blocker for launch with redemption** | Decide tier benefits and authorization; implement reserve/commit/reversal and customer/staff UX, test |
| Enrollment, adult verification, consent, household | Customer routes and staff verification route exist; integration tests cover concurrency | Implemented, staging acceptance missing | Browser, accessibility, permissions and consent flow acceptance |
| Invitation issuance and delivery | `invite-adult.php`, encrypted outbox, worker, revocation and acceptance exist, feature-gated; keyring code and CI green | Implemented, staging acceptance and operational configuration missing | Sink-mail delivery, manager journey, expired/revoked/ambiguous outcomes, key rotation rehearsal |
| Service email and delivery failure review | `admin/views/rewards_delivery_review.php` explicitly synthetic placeholder; inbound provenance not connected | **P0 if service-email automation required at launch** | Verified SMTP and controlled bounce/suppression workflow or explicitly defer feature |
| Expiration and visits | Processor and visit services exist | Integration verification needed | Authorized operational runner, replay, idempotence and staging tests |
| Production schema | Read-only observations in `docs/farm-rewards-migration-readiness-2026-11-08.md`; migration 0024 added since | **P0 release gate** | Full 0016–0024 staging migration rehearsal, fresh read-only production preflight, backup/restore |
| Operations | Admin monitoring partly aggregate-only; worker/schedulers not activated | **P0 release gate** | Approved runbooks, job schedules, alerts, rollback and support handling |

## Work sequence — stop unrelated micro-hardening

1. **Close POS end-to-end functionality first:** inspect existing POS lifecycle, outbox worker, ledger mapper and receipt code; implement gated atomic integration and bounded dispatch with failure recovery. Keep all production gates OFF.
2. **Close redemption:** agree exact benefits/values only if still unresolved; implement staff/customer permissions, transaction-safe redemption, reversal and audit. Do not pretend the tier preview is redemption.
3. **Close operations:** stage SMTP sink invitation flow, visit/expiration jobs, admin support and suppression decisions. Explicitly defer any optional email automation that is not required for initial launch.
4. **Staging acceptance:** browser and API tests for enroll → verify → purchase → visit → refund → redeem → invite → revoke/accept → expire; verify privacy, replay and rollback.
5. **Release evidence:** staging migrations 0016–0024, production read-only preflight, backup/restore rehearsal, security review, deployment checklist and owner go/no-go. Require separate approvals for deployment, production migration, POS activation, invitation issuance, worker and SMTP.

## Completion reporting

Do **not** present an unverified numerical percentage. Report implemented vs tested vs staging-verified vs blocked, with a concrete next action. Green CI is evidence of tests, not launch readiness. Any remaining feature with no implementation is a blocker unless explicitly removed from launch scope.

## Current safety state

Draft PR only. POS enqueue and dispatch hard-disabled; invitation issuance and sending disabled by default; no production migrations or live email performed.
