# Farm Rewards — pre-activation release checklist (2026-11-08)

**Status:** Development checks passing; **not approved for production**. This document records verified code boundaries and outstanding evidence. Do not merge, deploy, run migrations, schedule jobs, enable POS enqueue/dispatch, send service email, or turn on customer-facing rewards without explicit approval.

## Verified on feature branch
- PR #18 latest reviewed CI commit `cafefb64c7342d57dd97fc4430528f10c3ffb49b`: Code check and Roost Rewards integration workflows succeeded.
- POS transaction adapter hard-disabled by `bcrf_roost_pos_enqueue_enabled(): false`; dispatch hard-disabled by `bcrf_roost_outbox_dispatch_enabled(): false`.
- Service email requires SMTP rather than silently falling back to PHP mail. Suppression checks, claim eligibility, and dormant delivery-review helpers have disposable-fixture tests.
- Administrator-only delivery-review placeholder uses synthetic, unverified data; it does not ingest mail or write suppression records.
- Bluehost Webmail test bounce arrived at `support@batcaveroadfarm.com`; this **does not** establish website SMTP return-path or authenticated bounce processing.

## Onboarding implementation and test evidence (2026-11-08)
- Draft PR #18 includes gated staff adult verification, customer consent, enrollment, invitation preview and acceptance, and the private household dashboard. The routes remain disabled unless separately configured.
- The disposable MySQL integration suite passed on commit `861cffe9463f12573b5a382950867180268d98ff`, including competing enrollment and invitation-acceptance workers. Expiration and recovery tests passed on `c3897fcaa5a63916a498f7d39192e48e139df3db`.
- Dashboard feature-gate messaging passed CI on `5bdf87109a511da3794e15d8f093c4210794fd12`; offline audit checks passed on `32f78da9b5bb86740bebbcc0082310a02ef63e8d`.
- Revoked-invitation rejection was added in `2175b35f48d81021bb1e50d1f33daff3618fa438`; check its CI result before marking verified.
- **Still missing:** browser-based staging checks, invitation secure delivery and manager-facing issuing UI, customer support/revocation workflow, production read-only preflight run, migrations 0022–0023 in the staging rehearsal, and explicit activation approval. Automated success does not establish these.
- Release must remain staged: deploy inert code only after approval, rehearse all migrations against staging, separately authorize production migrations, and only then consider feature gates and workers. Never flip POS dispatch/enqueue gates or send email as part of an onboarding-only release.

## Blocking evidence and decisions before launch
1. **Production schema compatibility:** Run the existing read-only `tests/rewards_production_preflight.php` against the intended database, inspect MySQL version, engine/collation, column types, foreign keys, and any preexisting outbox. Resolve discrepancies before considering migrations. Never paste credentials into chat or commit them.
2. **Migration plan:** Inventory migrations 0016–0023 against production, take a verified backup, stage rehearsal and rollback/recovery plan, and separately approve the production migration window.
3. **POS integration:** Validate source transaction atomicity, sale/refund replay, immutable policy snapshots, zero-point receipts, and worker recovery under production-equivalent staging. Explicit approval required before changing either disabled gate or scheduling dispatch.
4. **Rewards operations:** Confirm initial household/account policy setup, verified-visit process, expiration and negative-balance workflows, redemption authorization and customer visibility. Run end-to-end staging acceptance tests for spending, visits, refunds, and redemptions.
5. **Email:** Verify website authenticated SMTP host, sender and envelope return-path, SPF/DKIM/DMARC, and bounce receipt using a controlled **website-originated** test. Design verified inbound provenance, event correlation, replay protection and human audit before any suppression ingestion. Confirm legal/consent and service/marketing separation.
6. **Admin review:** Review role gating, audit logging and customer privacy before connecting real reports. Existing view is a synthetic placeholder, not an operational queue.
7. **Go/no-go:** Review evidence, remaining risks, owner approval, monitoring, support runbook and rollback steps; explicitly approve each production activation separately.

## Safe next steps
- Continue offline tests, release audits and documentation on PR #18.
- Gather read-only production preflight results and SMTP configuration observations without secrets.
- Do **not** infer readiness from green CI alone.
