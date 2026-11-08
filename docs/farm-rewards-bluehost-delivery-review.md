# Farm Rewards — Bluehost email delivery failure review (dormant)

Status: **planning and review only**. No mailbox reader, webhook, sender, scheduled worker, production migration, or automatic suppression is enabled.

## What is known
- The site's shared mail utility supports authenticated SMTP and a legacy PHP mail fallback. Rewards service mail requires authenticated SMTP and fails closed if unavailable.
- Bluehost is the provisional mail provider, **not yet verified** against the production SMTP host, mailbox type, or account configuration.
- The designated Farm Rewards support and delivery-failure review mailbox is **support@batcaveroadfarm.com** (user-specified 2026-11-08).
- **Observed Return-Path (2026-11-08):** The user inspected an outgoing test message and reported `Return-Path: <support@batcaveroadfarm.com>`. This confirms the envelope return-path on **that message**, not all website mail or the authenticated SMTP login.
- **Observed Webmail bounce (2026-11-08):** The user confirmed that a failed delivery sent from Bluehost Webmail returned to `support@batcaveroadfarm.com`. This establishes bounce receipt for the tested Webmail path, **not** for Farm Rewards website SMTP or all delivery failures. The exact DSN status and authentication details were not supplied.
- SMTP acceptance is not proof of final delivery. Delivery-status notices may be delayed, absent, or forged. Bluehost account capabilities must be confirmed before integrating inbound feedback.
- The pure `bcrf_roost_dsn_review_category()` helper categorizes *enhanced status codes only*. It does not authenticate messages, determine the affected customer, or write to the database.

## Manual review procedure (future operations)
1. Check **support@batcaveroadfarm.com** in the Bluehost control panel and confirm whether it is the outbound sender mailbox and authenticated SMTP account **without copying credentials into GitHub or logs**.
2. A genuine failed-delivery report was observed in **support@batcaveroadfarm.com** for a Bluehost Webmail test. Separately check that website SMTP and future Farm Rewards service messages use the same envelope return-path before relying on this route. Do not assume the sender receives every bounce.
3. For a candidate report, verify its source and correlate the original message identifier, recipient, and outbound event using trusted records. A quoted address or status code in an email is not proof.
4. Record the provider's exact enhanced status code, date, original event reference, and whether it represents a temporary or permanent failure. Treat `4.x.x` as transient; `5.1.1` and `5.1.10` are candidates for *mailbox-unknown review*, not automatic suppression. Other `5.x.x` may be policy, capacity, or configuration issues.
5. Confirm the address and cause before using an **authorized, audited** administrative suppression workflow. Never feed raw mailbox text into `bcrf_roost_record_verified_provider_feedback()`; it expects already-verified canonical events.
6. Resolve temporary failures and infrastructure issues without permanently blocking a household. Document any clearance and ensure other active suppression reasons remain effective.

## Production activation gates
- Verify the actual SMTP host, sender identity, SPF/DKIM/DMARC and whether Rewards mail uses the observed support-mailbox return-path. A single observed header is not proof of end-to-end bounce handling.
- Choose and implement a trusted inbound source, authentication/provenance checks, event-to-recipient correlation, replay protection, audit trail, and operator permissions.
- Test forged reports, unknown recipients, duplicates, delayed bounces, and multiple suppression reasons on a non-production fixture.
- Explicit approval required before installing mailbox polling, accepting provider webhooks, sending Rewards email, or deploying.

## Current safety boundary
The provider feedback classifier, recording adapter, and delivery-status reviewer are **dormant library helpers**. The recording adapter must only be invoked after external verification by a trusted caller. Its existence is not evidence that Bluehost offers authenticated event callbacks.
