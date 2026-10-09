<?php declare(strict_types=1);
/** Exact existing customer/bridge postflight. Read-only SQL, no credential output. */
if(PHP_SAPI!=='cli')exit(2);
define('MW_APP_NAME','console');define('MW_RETURN_APP_INSTANCE',true);
$root='/home/admin/web/servermail2.com/public_html';require $root.'/apps/init.php';
require_once $root.'/apps/extensions/magicsmtp/MagicSmtpConnectRuntime.php';
require_once $root.'/apps/extensions/magicsmtp/MagicSmtpPolicyRuntime.php';
$db=Yii::app()->getDb();$db->setActive(true);$pdo=$db->getPdoInstance();$pdo->exec('SET SESSION MAX_EXECUTION_TIME=3000');$pdo->exec('SET TRANSACTION READ ONLY');$pdo->beginTransaction();
try{
    $store=MagicSmtpConnectRuntime::store();$started=microtime(true);$rows=$store->query('SELECT connection_id,customer_id,tenant_id,bridge_id,server_ids,revision,state FROM '.$store->table('connection').' WHERE customer_id=? LIMIT 2',[1])->fetchAll(PDO::FETCH_ASSOC);$queryMs=round((microtime(true)-$started)*1000,3);
    if(count($rows)!==1)throw new RuntimeException('Expected one managed connection');$row=$rows[0];$bridge='rpb-58702af2-dde8-44e7-ac86-07bccd0ae772';$tenant='tenant-4e049403a560073e';$ids=[433,434,436,442,444,445];
    if($row['bridge_id']!==$bridge||$row['tenant_id']!==$tenant||$row['state']!=='active'||json_decode($row['server_ids'],true)!==$ids)throw new RuntimeException('Managed scope differs');
    $started=microtime(true);$effective=MagicSmtpConnectRuntime::bindings();$loadMs=round((microtime(true)-$started)*1000,3);$static=MagicSmtpConnectRuntime::staticBindings();$found=[];$protected=[];
    foreach($effective as$b)if($b['bridge_id']===$bridge)$found[]=$b;foreach($static as$b)if($b['bridge_id']===$bridge)$protected[]=$b;
    if(count($found)!==1||count($protected)!==1)throw new RuntimeException('Unique protected bridge missing');$binding=$found[0];$original=$protected[0];
    $same=hash_equals($original['secret'],$binding['secret']);$scheduler=MagicSmtpPolicyRuntime::schedulerVerified($binding);
    if(!$same||!$scheduler||$binding['server_ids']!==$ids||$binding['customer_id']!==1||$binding['tenant_id']!==$tenant)throw new RuntimeException('Effective authority verification failed');
    $out=['ok'=>true,'readOnly'=>true,'at'=>gmdate('c'),'customerId'=>1,'serverIds'=>$ids,'state'=>$row['state'],'revision'=>(int)$row['revision'],'managedConnections'=>1,'overlaySecretMatchesStatic'=>$same,'schedulerVerified'=>$scheduler,'policyBridgeVersion'=>MagicSmtpPolicyBridge::VERSION,'connectionQueryMs'=>$queryMs,'bindingLoadMs'=>$loadMs,'runtimeSha256'=>hash_file('sha256',$root.'/apps/extensions/magicsmtp/MagicSmtpConnectRuntime.php'),'enrollmentEnabled'=>$store->enabled(),'secretsPrinted'=>false];$pdo->rollBack();echo json_encode($out,JSON_PRETTY_PRINT)."\n";
}catch(Throwable $e){if($pdo->inTransaction())$pdo->rollBack();echo json_encode(['ok'=>false,'readOnly'=>true,'errorType'=>get_class($e),'details'=>'Scoped connection proof failed'])."\n";exit(1);}
