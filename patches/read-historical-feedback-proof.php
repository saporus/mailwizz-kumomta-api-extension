<?php declare(strict_types=1);
// Exact ten-event read-only proof. The wrapper replaces only a base64 placeholder.
try {
    $samples=json_decode(base64_decode('MAGIC_SMTP_SAMPLE_JSON_BASE64',true),true,32,JSON_THROW_ON_ERROR);
    if(!is_array($samples)||count($samples)!==10)throw new RuntimeException('sample scope');
    chdir('/home/admin/web/servermail2.com/public_html');define('MW_APP_NAME','console');define('MW_RETURN_APP_INSTANCE',true);
    $app=require 'apps/init.php';$db=Yii::app()->getDb();
    $db->createCommand('SET SESSION MAX_EXECUTION_TIME=3000')->execute();$db->createCommand('SET SESSION TRANSACTION READ ONLY')->execute();$tx=$db->beginTransaction();
    $app->getComponent('extensionsManager')->loadAllExtensions();$prefix=(string)$db->tablePrefix;
    if(!preg_match('/^[A-Za-z0-9_]*$/D',$prefix))throw new RuntimeException('prefix');
    $bridge=MagicSmtpPolicyRuntime::bridge();$binding=$bridge?$bridge->bindingForServer(433,'tenant-4e049403a560073e',1):null;
    if(!$binding)throw new RuntimeException('binding');$ids=$binding['server_ids'];
    foreach($ids as$id)if(!is_int($id)||$id<1)throw new RuntimeException('server scope');
    $campaign=Campaign::model()->findByPk(328);
    if(!$campaign||$campaign->campaign_uid!=='nj855ymroyc89'||(int)$campaign->customer_id!==1)throw new RuntimeException('campaign scope');
    $out=['ok'=>true,'readOnly'=>true,'at'=>gmdate('c'),'tenantId'=>'tenant-4e049403a560073e','customerId'=>1,'campaignId'=>328,'campaignUid'=>'nj855ymroyc89','serverId'=>445,
        'bindingId'=>$binding['bridge_id'],'bindingEnabled'=>$binding['enabled']===true,'serverIds'=>array_map('strval',$ids),'schedulerVerified'=>MagicSmtpPolicyRuntime::schedulerVerified($binding),'sharedSecretSha256'=>hash('sha256',$binding['secret']),'runtimeHashes'=>[],'samples'=>[]];
    foreach(['MagicSmtpBounceIngress.php','DeliveryServerMagicSmtp.php']as$file)$out['runtimeHashes'][$file]=hash_file('sha256',MW_PATH.'/apps/extensions/magicsmtp/models/'.$file);
    $started=microtime(true);$seen=[];$pdo=$db->getPdoInstance();
    foreach($samples as$sample){
        if(microtime(true)-$started>15)throw new RuntimeException('proof budget');
        if(!is_string($sample['eventId']??null)||isset($seen[$sample['eventId']])||!is_string($sample['messageId']??null)||strlen($sample['messageId'])>512||!preg_match('/^[a-f0-9]{64}$/D',$sample['recipientSha256']??'')||!in_array($sample['bounceType']??'', ['soft','hard'],true))throw new RuntimeException('sample fields');
        $seen[$sample['eventId']]=true;$message=trim(trim($sample['messageId']),'<>');$matches=[];
        foreach([CampaignDeliveryLog::class,CampaignDeliveryLogArchive::class]as$class){
            $table=str_replace(['{{','}}'],[$prefix,''],$class::model()->tableName());if(!preg_match('/^[A-Za-z0-9_]+$/D',$table))throw new RuntimeException('table');
            $sql='SELECT /*+ MAX_EXECUTION_TIME(3000) */ DISTINCT d.server_id,d.campaign_id,d.subscriber_id,c.list_id,s.status FROM '.$table.' d FORCE INDEX(email_message_id) INNER JOIN '.$prefix.'campaign c ON c.campaign_id=d.campaign_id INNER JOIN '.$prefix.'list_subscriber s ON s.subscriber_id=d.subscriber_id AND s.list_id=c.list_id WHERE d.status=? AND (d.email_message_id=? OR d.email_message_id=?) AND c.customer_id=1 AND c.campaign_id=328 AND c.campaign_uid=? AND SHA2(LOWER(TRIM(s.email)),256)=? AND d.server_id IN ('.implode(',',$ids).') LIMIT 2';
            $query=$pdo->prepare($sql);$query->execute(['success',$message,'<'.$message.'>','nj855ymroyc89',$sample['recipientSha256']]);
            foreach($query->fetchAll(PDO::FETCH_ASSOC)as$row){foreach(['server_id','campaign_id','subscriber_id','list_id']as$key)$row[$key]=(int)$row[$key];$matches[json_encode($row)]=$row;}
        }
        $rows=array_values($matches);$unique=count($rows)===1&&$rows[0]['server_id']===445;$bounces=[];
        if($unique){$query=$pdo->prepare('SELECT bounce_type,date_added FROM '.$prefix.'campaign_bounce_log WHERE campaign_id=328 AND subscriber_id=? LIMIT 3');$query->execute([$rows[0]['subscriber_id']]);$bounces=$query->fetchAll(PDO::FETCH_ASSOC);}
        $out['samples'][]=['eventId'=>$sample['eventId'],'messageSha256'=>hash('sha256',$message),'recipientSha256'=>$sample['recipientSha256'],'originalBounceType'=>$sample['bounceType'],'uniqueExactTuples'=>$unique?1:0,'allAuthorizedMatchingTuples'=>count($rows),'subscriberStatus'=>$unique?$rows[0]['status']:null,'missingBounce'=>$unique&&count($bounces)===0,'persistedBounces'=>$bounces];
    }
    $tx->rollback();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $failure){if(isset($tx)&&$tx->getActive())$tx->rollback();echo json_encode(['ok'=>false,'readOnly'=>true,'error'=>get_class($failure),'code'=>(string)$failure->getCode()]);exit(1);}
