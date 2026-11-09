<?php
// Disposable-MySQL race worker. Never invoke against production.
if ($argc!==3 || !in_array($argv[1],['enroll','accept'],true)
    || !ctype_digit($argv[2])) exit(2);
$dsn=getenv('BCRF_REWARDS_TEST_DSN');
if (!$dsn) exit(2);
$pdo=new PDO($dsn,getenv('BCRF_REWARDS_TEST_USER') ?: '',
    getenv('BCRF_REWARDS_TEST_PASSWORD') ?: '',
    [PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
$pdo->exec('SET SESSION innodb_lock_wait_timeout=20');
try {
    if ($argv[1]==='enroll') {
        require_once __DIR__.'/../includes/roost-household-enrollment.php';
        bcrf_roost_enroll_verified_adult($pdo,(int)$argv[2],true,true);
    } else {
        require_once __DIR__.'/../includes/roost-household-invitation-acceptance.php';
        $token=getenv('BCRF_REWARDS_RACE_INVITATION_TOKEN');
        if (!$token) exit(2);
        bcrf_roost_accept_household_invitation($pdo,$token,(int)$argv[2],true,true);
    }
    echo "created\n";
} catch (DomainException $e) {
    echo "rejected\n";
} catch (Throwable $e) {
    fwrite(STDERR,get_class($e).': '.$e->getMessage()."\n");
    exit(1);
}
