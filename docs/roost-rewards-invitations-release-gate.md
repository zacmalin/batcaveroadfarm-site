# Roost Rewards invitation workflow — release gate

Invitation services are **dormant** and must not be exposed by a public route or enabled in a worker until release approval.

## Implemented on feature branch

- Migration `0022_roost_household_invitations.php` creates the invitation table with a SHA-256 token digest and hashed normalized recipient email.
- Manager-only issuing service generates a 256-bit random token and seven-day expiry. It returns the token once for an eventual secure delivery adapter; it never sends email.
- Acceptance requires an authenticated, active, verified-email customer, independently verified adult status, explicit household-join consent, and a matching token/email digest. The new adult has `can_redeem=0`.
- Revocation is restricted to verified managers in the invitation household; accepted invitations are preserved.
- Bounded expiration maintenance marks only overdue pending invitations as expired. Expired tokens are rejected even before maintenance runs.

## Release blockers

1. Persist auditable adult-verification and consent evidence; do not trust boolean values from HTTP requests.
2. Add rate limits and anti-abuse controls for issuing, accepting and revoking invitations.
3. Implement secure invitation delivery and customer UI with CSRF protection, safe redirects, generic errors and accessible status.
4. Establish membership removal and household permissions policy, including staff approval for major changes.
5. Verify invitation and enrollment flows in a disposable MySQL 8 environment and browser-based staging.
6. Run migrations only after separate production approval. Do not enable public routes or workers automatically.

No token, raw recipient email, SMTP credential, or customer personal data should appear in application logs.
