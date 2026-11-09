<?php
// Aggregate-only monitoring. No recipients, bearer tokens, ciphertext or claim IDs.
function bcrf_roost_invitation_delivery_metrics(PDO $pdo): array {
    $q=$pdo->query("SELECT status,COUNT(*) AS total
        FROM reward_invitation_delivery_outbox GROUP BY status");
    $counts=array_fill_keys(['pending','processing','sent','suppressed','failed'],0);
    foreach ($q->fetchAll(PDO::FETCH_ASSOC) as $row) {
        if (array_key_exists($row['status'],$counts)) $counts[$row['status']]=(int)$row['total'];
    }
    $q=$pdo->query("SELECT
        SUM(status='pending' AND available_at<=UTC_TIMESTAMP(3)) AS ready,
        SUM(status='processing' AND claimed_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 15 MINUTE)) AS stale,
        SUM(status='pending' AND available_at<=UTC_TIMESTAMP(3) AND created_at<DATE_SUB(UTC_TIMESTAMP(3),INTERVAL 60 MINUTE)) AS delayed_count,
        SUM(status='failed' AND dispatch_started_at IS NOT NULL) AS ambiguous_count
        FROM reward_invitation_delivery_outbox");
    $row=$q->fetch(PDO::FETCH_ASSOC) ?: [];
    return ['status_counts'=>$counts,
        'ready'=>(int)($row['ready']??0),
        'stale'=>(int)($row['stale']??0),
        'delayed'=>(int)($row['delayed_count']??0),
        'ambiguous'=>(int)($row['ambiguous_count']??0)];
}
