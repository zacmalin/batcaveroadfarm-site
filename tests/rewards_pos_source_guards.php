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
if (!str_contains($adapter,'getenv('BCRF_ROOST_POS_ENQUEUE_ENABLED') === '1' && getenv('BCRF_ROOST_POS_ACTIVATION_APPROVED') === '1'') ||
    !str_contains($dispatch,'getenv('BCRF_ROOST_POS_DISPATCH_ENABLED') === '1' && getenv('BCRF_ROOST_POS_ACTIVATION_APPROVED') === '1''))
    throw new RuntimeException('A production rewards gate was activated.');

// Default-off activation must require both a feature switch and explicit approval.
require_once $root.'/includes/roost-pos-outbox-integration.php';
require_once $root.'/includes/roost-pos-outbox-dispatch.php';
foreach (['BCRF_ROOST_POS_ENQUEUE_ENABLED','BCRF_ROOST_POS_DISPATCH_ENABLED','BCRF_ROOST_POS_ACTIVATION_APPROVED'] as $key) {
    putenv($key.'=0');
}
if (bcrf_roost_pos_enqueue_enabled() || bcrf_roost_outbox_dispatch_enabled())
    throw new RuntimeException('POS gates must default off.');
putenv('BCRF_ROOST_POS_ENQUEUE_ENABLED=1');
putenv('BCRF_ROOST_POS_DISPATCH_ENABLED=1');
if (bcrf_roost_pos_enqueue_enabled() || bcrf_roost_outbox_dispatch_enabled())
    throw new RuntimeException('POS gates bypassed approval.');
putenv('BCRF_ROOST_POS_ACTIVATION_APPROVED=1');
if (!bcrf_roost_pos_enqueue_enabled() || !bcrf_roost_outbox_dispatch_enabled())
    throw new RuntimeException('Approved POS gate configuration rejected.');
foreach (['BCRF_ROOST_POS_ENQUEUE_ENABLED','BCRF_ROOST_POS_DISPATCH_ENABLED','BCRF_ROOST_POS_ACTIVATION_APPROVED'] as $key) putenv($key.'=0');
echo "POS source hook and disabled activation guards passed.\\n";
