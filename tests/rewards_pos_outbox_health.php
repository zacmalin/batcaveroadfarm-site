<?php
require_once __DIR__.'/../includes/roost-pos-outbox-health.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$m=require __DIR__.'/../admin/migrations/0019_roost_pos_outbox.php'; $m($pdo);
$pdo->exec('DELETE FROM reward_pos_outbox');
$empty=bcrf_roost_outbox_health($pdo);
if ($empty['ready']!==0 || array_sum($empty['counts'])!==0) throw new RuntimeException('Empty metrics wrong.');
$pdo->exec("INSERT INTO reward_pos_outbox (event_type,source_id,status,available_at,created_at)
VALUES ('sale.completed',81,'pending',UTC_TIMESTAMP(3)-INTERVAL 2 HOUR,UTC_TIMESTAMP(3)-INTERVAL 2 HOUR),
('sale.refunded',82,'dead',UTC_TIMESTAMP(3),UTC_TIMESTAMP(3)),
('sale.completed',83,'processing',UTC_TIMESTAMP(3)-INTERVAL 1 MINUTE,UTC_TIMESTAMP(3))");
$health=bcrf_roost_outbox_health($pdo);
if ($health['counts']['dead']!==1 || $health['counts']['pending']!==1 ||
    $health['ready']!==1 || $health['expired_leases']!==1 || $health['waiting_over_hour']!==1)
    throw new RuntimeException('Outbox health counters wrong: '.json_encode($health));
echo "POS outbox health metrics passed.\n";
