<?php declare(strict_types=1);
define('MW_PATH','/home/admin/web/servermail2.com/public_html');
try {
    $cfg=require MW_PATH.'/apps/common/config/main-custom.php';$c=$cfg['components']['db'];$p=$c['tablePrefix'];
    if(!preg_match('/^[A-Za-z0-9_]*$/D',$p))throw new RuntimeException('prefix');
    $db=new PDO($c['connectionString'],$c['username'],$c['password'],[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]);
    $db->exec('SET SESSION MAX_EXECUTION_TIME=3000');$db->exec('SET SESSION TRANSACTION READ ONLY');$db->beginTransaction();
    function q($sql,$params=[]){global $db;$s=$db->prepare($sql);$s->execute($params);return $s->fetchAll(PDO::FETCH_ASSOC);}
    $out=['ok'=>true,'readOnly'=>true,'at'=>gmdate('c'),'databaseClock'=>q('SELECT NOW() local_now,UTC_TIMESTAMP() utc_now')];
    $out['campaign']=q('SELECT campaign_id,campaign_uid,status,started_at,finished_at,last_updated FROM '.$p.'campaign WHERE campaign_id=328');
    if(($out['campaign'][0]['campaign_uid']??'')!=='nj855ymroyc89')throw new RuntimeException('scope');
    $out['options']=q('SELECT processed_count,delivery_success_count,delivery_error_count,giveup_count,giveup_counter,cronjob_enabled,cronjob_runs_counter,sending_from_processing_counter FROM '.$p.'campaign_option WHERE campaign_id=328');
    $out['queueFailures']=q('SELECT failures,COUNT(*) n FROM '.$p.'campaign_queue_328 GROUP BY failures');
    $out['latestLogs']=q('SELECT log_id,status,date_added,retries FROM '.$p.'campaign_delivery_log FORCE INDEX(cid_date_added) WHERE campaign_id=328 ORDER BY date_added DESC LIMIT 12');
    $db->rollBack();
    function tailSafe($path,$limit){$f=@fopen($path,'rb');if(!$f)return'';fseek($f,max(0,filesize($path)-$limit));$v=stream_get_contents($f,$limit);fclose($f);return$v;}
    $out['workers']=[];foreach(glob('/proc/[0-9]*',GLOB_ONLYDIR)as$dir){$cmd=@file_get_contents($dir.'/cmdline');if($cmd!==false&&str_contains($cmd,'send-campaigns'))$out['workers'][]=['pid'=>(int)basename($dir),'campaign328Explicit'=>str_contains($cmd,'nj855ymroyc89')||(bool)preg_match('/\b328\b/',$cmd)];}
    $out['latestWorkerLogMetadata']=[];
    foreach(preg_split('/\r?\n/',tailSafe(MW_PATH.'/apps/common/runtime/application.log',300000))as$line){
        if(!preg_match('/send-campaigns|sending|campaign|temporarily delayed|memory_admission|retry|exception|mutex/i',$line))continue;
        preg_match('/2026[\/-]\d\d[\/-]\d\d[ T]\d\d:\d\d:\d\d/',$line,$date);
        if(!$date)continue;
        preg_match_all('/memory_admission_limited|recovery_inflight|memory_cutoff|429|503|mutex|temporarily delayed|paused|sent|sending/i',$line,$codes);
        preg_match('/Retry in (\d+) seconds/i',$line,$retry);
        $out['latestWorkerLogMetadata'][]=['time'=>$date[0],'campaign328Explicit'=>str_contains($line,'nj855ymroyc89'),'codes'=>array_values(array_unique($codes[0])),'retrySeconds'=>isset($retry[1])?(int)$retry[1]:null];
    }
    $out['latestWorkerLogMetadata']=array_slice($out['latestWorkerLogMetadata'],-18);
    $out['cronJobs']=[];foreach(['/var/spool/cron/crontabs/admin','/var/spool/cron/crontabs/root']as$path){foreach(explode("\n",tailSafe($path,25000))as$line){if(!str_contains($line,'send-campaigns'))continue;preg_match('/^\s*((?:\S+\s+){5})/',$line,$schedule);preg_match_all('/[>]\s*([\/a-zA-Z0-9_.-]+)/',$line,$dest);$out['cronJobs'][]=['source'=>$path,'disabled'=>str_starts_with(ltrim($line),'#'),'schedule'=>trim($schedule[1]??''),'stdoutPaths'=>$dest[1]];}}
    echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if(isset($db)&&$db->inTransaction())$db->rollBack();echo json_encode(['ok'=>false,'error'=>get_class($e),'code'=>(string)$e->getCode()]);exit(1);}
