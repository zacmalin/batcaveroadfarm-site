<?php
require_once __DIR__.'/../includes/roost-pos-outbox-integration.php';
$pdo=new PDO(getenv('BCRF_REWARDS_TEST_DSN'),getenv('BCRF_REWARDS_TEST_USER')?:'',getenv('BCRF_REWARDS_TEST_PASSWORD')?:'');
$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
if (bcrf_roost_pos_enqueue_enabled()) throw new RuntimeException('Outbox integration unexpectedly enabled.');
try { bcrf_roost_pos_enqueue_if_enabled($pdo,'sale.completed',123); throw new RuntimeException('Autocommit enqueue accepted.'); }
catch(LogicException $e) { if ($e->getMessage()==='Autocommit enqueue accepted.') throw $e; }
$pdo->beginTransaction();
$before=(int)$pdo->query("SELECT COUNT(*) FROM reward_pos_outbox")->fetchColumn();
if (bcrf_roost_pos_enqueue_if_enabled($pdo,'sale.completed',123) ||
    bcrf_roost_pos_enqueue_if_enabled($pdo,'sale.refunded',456))
    throw new RuntimeException('Disabled integration enqueued an event.');
$after=(int)$pdo->query("SELECT COUNT(*) FROM reward_pos_outbox")->fetchColumn();
$pdo->rollBack();
if ($before!==$after) throw new RuntimeException('Disabled integration modified outbox.');
echo "Disabled POS transaction adapter guards passed.\\n";
