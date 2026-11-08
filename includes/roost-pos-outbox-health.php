<?php
// Read-only internal Roost Rewards POS outbox health summary.
// No customer-facing route, scheduler, or mutation is provided.
function bcrf_roost_outbox_health(PDO $pdo): array {
    if ($pdo->inTransaction()) throw new LogicException('Outbox health requires autocommit.');
    $rows=$pdo->query("SELECT status, COUNT(*) AS total
        FROM reward_pos_outbox GROUP BY status")->fetchAll(PDO::FETCH_ASSOC);
    $counts=array_fill_keys(['pending','processing','completed','skipped','failed','dead'],0);
    foreach ($rows as $row) {
        if (!array_key_exists($row['status'],$counts)) throw new RuntimeException('Unknown outbox status.');
        $counts[$row['status']]=(int)$row['total'];
    }
    $st=$pdo->query("SELECT
        COALESCE(SUM(status IN ('pending','failed') AND available_at <= UTC_TIMESTAMP(3)),0) AS ready,
        COALESCE(SUM(status = 'processing' AND available_at < UTC_TIMESTAMP(3)),0) AS expired_leases,
        COALESCE(SUM(status IN ('pending','failed') AND created_at < UTC_TIMESTAMP(3) - INTERVAL 1 HOUR),0) AS waiting_over_hour
        FROM reward_pos_outbox");
    $detail=$st->fetch(PDO::FETCH_ASSOC);
    return ['counts'=>$counts,'ready'=>(int)$detail['ready'],
        'expired_leases'=>(int)$detail['expired_leases'],
        'waiting_over_hour'=>(int)$detail['waiting_over_hour']];
}
