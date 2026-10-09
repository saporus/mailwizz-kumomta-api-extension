<?php declare(strict_types=1);
// Read-only runtime/table/index/config metadata. No callback, install or probe send.
try {
    chdir('/home/admin/web/servermail2.com/public_html');
    define('MW_APP_NAME','console');define('MW_RETURN_APP_INSTANCE',true);
    $app=require 'apps/init.php';$db=Yii::app()->getDb();
    $db->createCommand('SET SESSION MAX_EXECUTION_TIME=3000')->execute();
    $db->createCommand('SET SESSION TRANSACTION READ ONLY')->execute();$tx=$db->beginTransaction();
    $app->getComponent('extensionsManager')->loadAllExtensions();
    $p=(string)$db->tablePrefix;if(!preg_match('/^[A-Za-z0-9_]*$/D',$p))throw new RuntimeException('prefix');
    $out=['ok'=>true,'readOnly'=>true,'at'=>gmdate('c'),'tables'=>[]];
    foreach([CampaignDeliveryLog::class,CampaignDeliveryLogArchive::class]as$class){
        $table=str_replace(['{{','}}'],[$p,''],$class::model()->tableName());
        if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('table');
        $sql='SELECT /*+ MAX_EXECUTION_TIME(3000) */ DISTINCT d.server_id,c.customer_id,c.campaign_id,c.campaign_uid,c.list_id,s.subscriber_id,s.subscriber_uid FROM '.$table.' d FORCE INDEX (email_message_id) INNER JOIN '.$p.'campaign c ON c.campaign_id=d.campaign_id INNER JOIN '.$p.'list_subscriber s ON s.subscriber_id=d.subscriber_id AND s.list_id=c.list_id WHERE d.status=? AND (d.email_message_id=? OR d.email_message_id=?) AND c.customer_id=? AND LOWER(TRIM(s.email))=? AND (c.campaign_uid=? OR c.campaign_id=?) AND d.server_id IN (433,434,436,442,444,445) LIMIT 2';
        $stmt=$db->getPdoInstance()->prepare('EXPLAIN '.$sql);$stmt->execute(['success','synthetic-unmatched-message@example.test','<synthetic-unmatched-message@example.test>',1,'synthetic@example.test','nj855ymroyc89',328]);
        $out['tables'][]=['model'=>$class,'table'=>$table,'sourceSha256'=>hash_file('sha256',(new ReflectionClass($class))->getFileName()),'columns'=>array_column($db->createCommand('SHOW COLUMNS FROM '.$table)->queryAll(),'Field'),'indexes'=>$db->createCommand('SHOW INDEX FROM '.$table)->queryAll(),'plan'=>$stmt->fetchAll(PDO::FETCH_ASSOC)];
    }
    $bridge=MagicSmtpPolicyRuntime::bridge();$binding=$bridge->bindingForServer(433,'tenant-4e049403a560073e',1);
    if(!$binding||$binding['server_ids']!==[433,434,436,442,444])throw new RuntimeException('binding drift');
    $out['binding']=array_intersect_key($binding,array_flip(['bridge_id','tenant_id','customer_id','server_ids','enabled']));
    $out['currentSchedulerVerified']=MagicSmtpPolicyRuntime::schedulerVerified($binding);
    $proposed=$binding;$proposed['server_ids'][]=445;$out['proposedSchedulerVerified']=MagicSmtpPolicyRuntime::schedulerVerified($proposed);
    $out['mutex']=['class'=>get_class(mutex()),'sourceSha256'=>hash_file('sha256',(new ReflectionClass(mutex()))->getFileName())];
    $out['mutex']['ttl']=mutex()->ttl;
    $fbl=container()->get(OptionCronProcessFeedbackLoopServers::class);$out['complaintAction']=['unsubscribe'=>$fbl->getSubscriberActionIsUnsubscribe(),'blacklist'=>$fbl->getSubscriberActionIsBlacklist(),'delete'=>$fbl->getSubscriberActionIsDelete()];
    $out['transactionTables']=$db->createCommand("SELECT TABLE_NAME,ENGINE FROM information_schema.TABLES WHERE TABLE_SCHEMA=DATABASE() AND TABLE_NAME IN ('".$p."list_subscriber','".$p."campaign_bounce_log','".$p."campaign_complain_log','".$p."campaign_track_unsubscribe','".$p."email_blacklist','".$p."customer_email_blacklist')")->queryAll();
    $out['nativeSources']=[];
    foreach([get_class(mutex()),OptionCronProcessFeedbackLoopServers::class,CustomerEmailBlacklist::class,CampaignComplainLog::class,CampaignTrackUnsubscribe::class,Lists::class]as$class){$file=(new ReflectionClass($class))->getFileName();$out['nativeSources'][]=['class'=>$class,'path'=>$file,'sha256'=>hash_file('sha256',$file)];}
    $path='/home/admin/web/servermail2.com/private/magicsmtp-policy/bridge-tenant-4e049403a560073e.php';
    $shape=require $path;$out['bindingFile']=['path'=>$path,'sha256'=>hash_file('sha256',$path),'topLevelKeys'=>array_keys($shape),'topLevelTypes'=>array_map('gettype',$shape),'mode'=>fileperms($path)&0777,'uid'=>fileowner($path),'gid'=>filegroup($path)];
    $out['campaign']=$db->createCommand('SELECT campaign_id,campaign_uid,status FROM '.$p.'campaign WHERE campaign_id=328')->queryRow();
    $tx->rollback();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $failure){if(isset($tx)&&$tx->getActive())$tx->rollback();echo json_encode(['ok'=>false,'error'=>get_class($failure),'code'=>(string)$failure->getCode()]);exit(1);}
