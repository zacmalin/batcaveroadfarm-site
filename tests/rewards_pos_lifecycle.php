<?php
require_once __DIR__.'/../includes/roost-pos-lifecycle.php';
function rr_assert(bool $ok, string $message): void {
    if (!$ok) throw new RuntimeException($message);
}
$pdo = new PDO('sqlite::memory:');
rr_assert(bcrf_roost_pos_integration_enabled() === false, 'Integration must default disabled.');
foreach (['sale.completed','sale.refunded'] as $kind) {
    $result = bcrf_roost_pos_postcommit_signal($pdo,$kind,123);
    rr_assert($result['enabled'] === false && $result['processed'] === false, 'Disabled signal must never process.');
}
$pdo->beginTransaction();
try {
    bcrf_roost_pos_postcommit_signal($pdo,'sale.completed',123);
    throw new RuntimeException('Transaction guard failed.');
} catch (LogicException $expected) {
    $pdo->rollBack();
}
foreach ([['bad.event',1],['sale.completed',0]] as [$kind,$id]) {
    try {
        bcrf_roost_pos_postcommit_signal($pdo,$kind,$id);
        throw new RuntimeException('Invalid signal accepted.');
    } catch (InvalidArgumentException $expected) {}
}
echo "Disabled POS lifecycle gate passed.\n";
