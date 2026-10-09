<?php declare(strict_types=1);
// Bounded native evidence only. No application bootstrap, callbacks or model hooks.
define('MW_PATH','/home/admin/web/servermail2.com/public_html');
try {
    $cutoff='2026-10-09 15:27:21';
    $cfg=require MW_PATH.'/apps/common/config/main-custom.php';$c=$cfg['components']['db'];$p=$c['tablePrefix'];
    if(!preg_match('/^[A-Za-z0-9_]*$/D',$p))throw new RuntimeException('prefix');
    $db=new PDO($c['connectionString'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('SET SESSION MAX_EXECUTION_TIME=3000');$db->exec('SET SESSION TRANSACTION READ ONLY');$db->beginTransaction();
    function q($sql,$params=[]){global $db;$s=$db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    $campaign=q('SELECT campaign_id,campaign_uid,customer_id,status FROM '.$p.'campaign WHERE campaign_id=328');
    if(count($campaign)!==1||$campaign[0]['campaign_uid']!=='nj855ymroyc89'||(int)$campaign[0]['customer_id']!==1)throw new RuntimeException('campaign scope');
    $path='/home/admin/web/servermail2.com/private/magicsmtp-policy/bridge-tenant-4e049403a560073e.php';$bindings=require $path;
    if(!is_array($bindings)||count($bindings)!==1)throw new RuntimeException('binding count');$b=array_values($bindings)[0];
    if(($b['enabled']??null)!==true||($b['tenant_id']??'')!=='tenant-4e049403a560073e'||($b['customer_id']??null)!==1||($b['server_ids']??[])!==[433,434,436,442,444,445])throw new RuntimeException('binding scope');
    $out=['ok'=>true,'readOnly'=>true,'applicationBootstrapped'=>false,'at'=>gmdate('c'),'sinceUtc'=>$cutoff,'campaign'=>$campaign[0],'serverIds'=>$b['server_ids'],'bindingSha256'=>hash_file('sha256',$path),'runtimeHashes'=>[],'samples'=>[]];
    foreach(['MagicSmtpBounceIngress.php','DeliveryServerMagicSmtp.php']as$file)$out['runtimeHashes'][$file]=hash_file('sha256',MW_PATH.'/apps/extensions/magicsmtp/models/'.$file);
    $out['databaseClock']=q('SELECT NOW() local_now,UTC_TIMESTAMP() utc_now');
    $out['allCampaignBounces']=q('SELECT bounce_type,COUNT(*) n,MIN(date_added) first_at,MAX(date_added) latest_at FROM '.$p.'campaign_bounce_log WHERE campaign_id=328 GROUP BY bounce_type');
    $out['postBindingBounces']=q('SELECT b.bounce_type,s.status subscriber_status,COUNT(*) n,MIN(b.date_added) first_at,MAX(b.date_added) latest_at FROM '.$p.'campaign_bounce_log b INNER JOIN '.$p.'list_subscriber s ON s.subscriber_id=b.subscriber_id WHERE b.campaign_id=328 AND b.date_added>=? GROUP BY b.bounce_type,s.status',[$cutoff]);
    $out['bounceCounters']=q('SELECT bounces_count,hard_bounces_count,soft_bounces_count,internal_bounces_count FROM '.$p.'campaign_option WHERE campaign_id=328');
    // Native beforeSave can reclassify callback soft bounces as internal.
    // Only fixed public error fragments are emitted; never return raw error bodies.
    $out['internalNativeRuleMatches']=q("SELECT COUNT(*) total,SUM(LOWER(message) REGEXP 'unsolicited mail|spam|block(ed)?|dnsbl|rbl|cdrbl|blacklist') matches_native_rule,SUM(LOWER(message) REGEXP 'spam|block(ed)?') spam_or_block,SUM(LOWER(message) REGEXP 'dnsbl|rbl|cdrbl|blacklist') blacklist_or_blocklist FROM ".$p.'campaign_bounce_log WHERE campaign_id=328 AND date_added>=? AND bounce_type=?',[$cutoff,'internal']);
    $out['nativeBounceModelSha256']=hash_file('sha256',MW_PATH.'/apps/common/models/CampaignBounceLog.php');
    $out['internalErrorCategories']=[];
    $errors=q('SELECT message,COUNT(*) n FROM '.$p.'campaign_bounce_log WHERE campaign_id=328 AND date_added>=? AND bounce_type=? GROUP BY message ORDER BY n DESC LIMIT 12',[$cutoff,'internal']);
    foreach($errors as$error){
        preg_match_all('/unsolicited mail|spam|block(?:ed)?|DNSBL|RBL|CDRBL|Blacklist|Policy dispatch binding is not ready|Policy dispatch requires (?:a campaign|campaign and subscriber correlation)|SQLSTATE\[[A-Z0-9]+\]|Unknown column|Duplicate entry|Invalid (?:recipient|message ID|message_id|campaign_uid|subscriber_uid)|recipient_suppressed|recipient_policy_suppressed|RECIPIENT_SUPPRESSED|HTTP\s*(?:400|401|403|409|422|429|500|503)|Unable to send|Error executing|No server|not ready|Unauthorized|No active transaction|There is no active transaction/i',(string)$error['message'],$categories);
        $out['internalErrorCategories'][]=['messageSha256'=>hash('sha256',(string)$error['message']),'n'=>(int)$error['n'],'categories'=>array_values(array_unique($categories[0]))];
    }
    $started=microtime(true);
    foreach(['hard'=>5,'soft'=>5,'internal'=>10]as$type=>$limit){
        $rows=q('SELECT b.subscriber_id,b.bounce_type,b.date_added,SHA2(b.message,256) reason_sha256,s.status subscriber_status,SHA2(LOWER(TRIM(s.email)),256) recipient_sha256 FROM '.$p.'campaign_bounce_log b INNER JOIN '.$p.'list_subscriber s ON s.subscriber_id=b.subscriber_id WHERE b.campaign_id=328 AND b.date_added>=? AND b.bounce_type=? ORDER BY b.date_added DESC LIMIT '.$limit,[$cutoff,$type]);
        foreach($rows as$row){
            if(microtime(true)-$started>15)throw new RuntimeException('proof budget');$tuples=[];
            foreach(['campaign_delivery_log','campaign_delivery_log_archive']as$table){
                $matches=q('SELECT DISTINCT server_id,email_message_id FROM '.$p.$table.' FORCE INDEX(sub_proc_status) WHERE campaign_id=328 AND subscriber_id=? AND status=? AND server_id IN (433,434,436,442,444,445) LIMIT 2',[$row['subscriber_id'],'success']);
                foreach($matches as$match){$message=trim(trim((string)$match['email_message_id']),'<>');$tuple=['serverId'=>(int)$match['server_id'],'messageSha256'=>hash('sha256',$message)];$tuples[json_encode($tuple)]=$tuple;}
            }
            unset($row['subscriber_id']);$row['successfulDeliveryTuples']=array_values($tuples);$row['uniqueServer445Tuple']=count($tuples)===1&&array_values($tuples)[0]['serverId']===445;$out['samples'][]=$row;
        }
    }
    $db->rollBack();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $failure){if(isset($db)&&$db->inTransaction())$db->rollBack();echo json_encode(['ok'=>false,'readOnly'=>true,'error'=>get_class($failure),'code'=>(string)$failure->getCode()]);exit(1);}
