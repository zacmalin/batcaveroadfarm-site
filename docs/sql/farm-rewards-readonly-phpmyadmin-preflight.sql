-- Farm Rewards: read-only phpMyAdmin compatibility inspection.
-- Select the intended BCRF database in phpMyAdmin before running.
-- SELECT statements only; no migrations, DDL, or data changes.
SELECT DATABASE() AS selected_database, VERSION() AS server_version;
SELECT COLLATION_NAME, CHARACTER_SET_NAME
FROM information_schema.COLLATIONS
WHERE COLLATION_NAME = 'utf8mb4_0900_ai_ci';
SELECT TABLE_NAME, ENGINE, TABLE_COLLATION
FROM information_schema.TABLES
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME IN ('customers','sales','refunds','check_ins',
    'reward_households','reward_household_members','reward_policies',
    'reward_accounts','reward_ledger','reward_pos_outbox',
    'reward_email_outbox','reward_email_suppressions')
ORDER BY TABLE_NAME;
SELECT TABLE_NAME, COLUMN_NAME, COLUMN_TYPE, IS_NULLABLE, COLUMN_KEY
FROM information_schema.COLUMNS
WHERE TABLE_SCHEMA = DATABASE()
  AND (
    (TABLE_NAME='customers' AND COLUMN_NAME IN ('id','email','email_verified_at','account_status'))
    OR (TABLE_NAME='sales' AND COLUMN_NAME IN ('id','customer_id'))
    OR (TABLE_NAME='refunds' AND COLUMN_NAME IN ('id','sale_id'))
    OR (TABLE_NAME='check_ins' AND COLUMN_NAME IN ('id','business_date'))
    OR (TABLE_NAME='reward_pos_outbox' AND COLUMN_NAME IN ('id','event_type','source_id','policy_id','status'))
  )
ORDER BY TABLE_NAME, ORDINAL_POSITION;
SELECT TABLE_NAME, INDEX_NAME, NON_UNIQUE, SEQ_IN_INDEX, COLUMN_NAME
FROM information_schema.STATISTICS
WHERE TABLE_SCHEMA = DATABASE()
  AND TABLE_NAME = 'reward_pos_outbox'
ORDER BY INDEX_NAME, SEQ_IN_INDEX;
-- If the reward_pos_outbox table exists, a separate guarded read-only
-- check can count unresolved sale events missing policy_id. Do not run
-- that query until its columns are verified above.
