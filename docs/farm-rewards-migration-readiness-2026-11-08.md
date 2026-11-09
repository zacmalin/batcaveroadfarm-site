# Farm Rewards migration rehearsal — production evidence (2026-11-08)

**Status:** Read-only inspection only. No migration has been executed or approved.

## Observed production evidence (provided from phpMyAdmin)
- Database: `ddailnmy_bat_cave_road_farm_db`, MySQL `8.0.46-37`; collation `utf8mb4_0900_ai_ci` available.
- `customers`, `sales`, `refunds`, `check_ins`: all InnoDB and `utf8mb4_0900_ai_ci`.
- `customers.id` INT signed; `customers.email` VARCHAR(100) NOT NULL; `email_verified_at` DATETIME nullable; `account_status` ENUM(active,disabled).
- `sales.id`, `refunds.id`, `refunds.sale_id`, `check_ins.id`: BIGINT UNSIGNED. `sales.customer_id`: nullable INT. `check_ins.business_date`: DATE.
- Existing FK: `refunds.sale_id` -> `sales.id`.
- User subsequently ran the full ten-name Rewards inventory query: **zero rows**. None of those ten tables existed at inspection. The six newer onboarding, invitation-delivery and redemption tables were not included in that query, so their production absence is **not yet verified**.

## Migration-by-migration dependency review
1. `0016_farm_rewards_foundation.php`: five tables: `reward_households`, `reward_household_members`, `reward_policies`, `reward_accounts`, `reward_ledger`. Customer FK expects signed INT: matches observed `customers.id`.
2. `0017_roost_rewards_attendance.php`: `reward_verified_attendance`; check-in FK BIGINT UNSIGNED and customer FK INT match observed columns.
3. `0018_roost_refund_processing.php`: `reward_refund_processing`; refund FK BIGINT UNSIGNED matches observed `refunds.id`; references foundation tables.
4. `0019_roost_pos_outbox.php`: `reward_pos_outbox`; no worker activated by migration; immutable policy snapshot field `policy_id` nullable.
5. `0020_roost_email_outbox.php`: `reward_email_outbox`; references foundation household/member tables.
6. `0021_roost_email_suppressions.php`: `reward_email_suppressions`; no email automation activated.
7. `0022_roost_household_invitations.php`: `reward_household_invitations`; FKs to households and signed INT customer IDs, token hashes only.
8. `0023_roost_verification_consent.php`: `reward_adult_verifications` and `reward_consent_events`; signed INT customer FKs, opaque verification references and consent audit records.
9. `0024_roost_invitation_delivery_outbox.php`: `reward_invitation_delivery_outbox`; dormant encrypted invitation-delivery queue, no sender enabled by migration.
10. `0025_roost_redemption_requests.php`: `reward_redemption_benefits` and `reward_redemption_requests`; approved internal-voucher issuance remains feature-flagged OFF.

## Required before rehearsal or production approval
- **Earlier 10-table Rewards inventory confirmed empty** by read-only phpMyAdmin query; inspect all 16 tables before any deployment. Recheck immediately before any future deployment; `CREATE TABLE IF NOT EXISTS` will not repair mismatched tables.
- Confirm migration runner order, migration bookkeeping, failure handling, and privileges.
- Capture verified backup and restoration procedure, staging snapshot and rollback plan; MySQL DDL may commit independently, so do not assume a transaction can roll back partial migration.
- Rehearse migrations **in staging only**, check schema and FK definitions, rerun for idempotence, and exercise application tests.
- Obtain separate approval before any production migration, activation or deployment.

## Next read-only phpMyAdmin query (all 16 tables)
```sql
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = 'ddailnmy_bat_cave_road_farm_db'
  AND TABLE_NAME IN (
    'reward_households', 'reward_household_members', 'reward_policies',
    'reward_accounts', 'reward_ledger', 'reward_verified_attendance',
    'reward_refund_processing', 'reward_pos_outbox',
    'reward_email_outbox', 'reward_email_suppressions',
    'reward_household_invitations', 'reward_adult_verifications',
    'reward_consent_events', 'reward_invitation_delivery_outbox',
    'reward_redemption_benefits', 'reward_redemption_requests'
  )
ORDER BY TABLE_NAME;
```
If zero rows, none of these sixteen exact tables currently exist. If any rows, inspect their full definitions before a migration rehearsal.
