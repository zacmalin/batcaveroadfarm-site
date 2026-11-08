<?php
// Source-level release guard: never silently remove or move transactional hooks.
$root=dirname(__DIR__);
$cases=[
    ['includes/sales.php', "bcrf_roost_pos_enqueue_if_enabled(\$pdo,'sale.completed',\$saleId);"],
    ['includes/refunds.php', "bcrf_roost_pos_enqueue_if_enabled(\$pdo,'sale.refunded',\$rid);"],
];
foreach ($cases as [$path,$hook]) {
    $source=file_get_contents($root.'/'.$path);
    $pos=strpos($source,$hook);
    if ($pos===false || strpos($source,$hook,$pos+1)!==false)
        throw new RuntimeException("Expected exactly one source transaction hook in $path.");
    $begin=strrpos(substr($source,0,$pos),'$pdo->beginTransaction();');
    $commit=strpos($source,'$pdo->commit();',$pos);
    if ($begin===false || $commit===false || $begin>$pos)
        throw new RuntimeException("POS hook must precede source transaction commit: $path.");
    $nextRollback=strpos($source,'$pdo->rollBack();',$pos);
    if ($nextRollback===false)
        throw new RuntimeException("POS hook lost rollback handling: $path.");
}
$adapter=file_get_contents($root.'/includes/roost-pos-outbox-integration.php');
$dispatch=file_get_contents($root.'/includes/roost-pos-outbox-dispatch.php');
if (!str_contains($adapter,'function bcrf_roost_pos_enqueue_enabled(): bool { return false; }') ||
    !str_contains($dispatch,'function bcrf_roost_outbox_dispatch_enabled(): bool { return false; }'))
    throw new RuntimeException('A production rewards gate was activated.');
echo "POS source hook and disabled activation guards passed.\\n";
