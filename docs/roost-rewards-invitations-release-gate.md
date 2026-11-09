# Roost Rewards invitation workflow — release gate

**Status (2026-11-08):** Implemented and automated-test verified on draft PR #18; **not approved for production**. Customer-facing routes are feature-gated. Do not deploy, migrate, activate, or send invitations without separate approval.

## Implemented on feature branch

- Migration `0022_roost_household_invitations.php` stores a SHA-256 token digest and hashed normalized recipient email; migration `0023_roost_verification_consent.php` adds persisted adult-verification and consent evidence.
- Manager-only issuing service creates a 256-bit bearer token with seven-day expiry; it does **not** send email. Manager revocation preserves accepted invitation records.
- Signed-in invitation preview and acceptance pages require a verified matching email. Acceptance checks persisted adult-verification and household-join consent, active household and pending unexpired invitation; a new member starts with `can_redeem=0`.
- The customer balance dashboard links to enrollment and invitation acceptance only when their corresponding feature flags are enabled.
- Acceptance uses CSRF and rate limiting. Expired and revoked invitations are rejected; transaction and concurrency tests cover replay, rollback, and competing requests.
- The disposable MySQL integration suite and code checks passed on commit `202d0b7c22c1e563d56e1a1cd39ebda424963d18`. This does not establish browser-based staging or production readiness.

## Outstanding release blockers

1. **Manager issuing/revocation UI and abuse controls:** no approved customer-facing invitation issuance/delivery workflow. Review authorization, throttling, and staff-support procedures for manager operations.
2. **Secure delivery:** implement and verify a consent-appropriate delivery channel without token logging, raw recipient email exposure in stored invitation records, or unintended marketing automation.
3. **Browser acceptance:** exercise authenticated consent, staff verification, enrollment, invitation preview, acceptance, expiry, revocation, replay, accessibility, and safe redirect/error handling in staging.
4. **Household governance:** confirm removal, permission changes, verified-manager recovery, and staff approval for major organization changes.
5. **Database readiness:** complete read-only 13-table production inventory; rehearse migrations 0016–0023 on staging with verified backup and restoration plan. Production migration requires separate approval.
6. **Go/no-go:** review monitoring, support runbook, customer communications, explicit feature-flag activation, and rollback before launch.

No invitation token, raw recipient email, SMTP credential, or customer personal data should appear in application logs.
