<?php declare(strict_types=1);
/** Private licensed methods are read in memory; no vendor source is copied here. */
define('MW_PATH',__DIR__);
$sourcePath=$argv[1]??'';
if(!is_file($sourcePath)||hash_file('sha256',$sourcePath)!=='a173922750c477558ac5302d5355ce68a5993f5ab2f8e639b944a41cf019bfef')throw new RuntimeException('Pass the inspected private DeliveryServer.php source');
$source=file_get_contents($sourcePath);
function check($value,string $label):void{if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
function nativeMethod(string $source,string $name):string{
    $start=strpos($source,'    public function '.$name.'(');if($start===false)throw new RuntimeException('Native method missing');
    $piece=substr($source,$start);$offset=-6;$depth=0;$opened=false;
    foreach(token_get_all("<?php\n".$piece)as$token){$text=is_array($token)?$token[1]:$token;$offset+=strlen($text);if(is_array($token))continue;if($text==='{'){$opened=true;++$depth;}elseif($text==='}'&&$opened&&--$depth===0)return substr($piece,0,$offset);}
    throw new RuntimeException('Unclosed native method');
}
$native='';foreach(['getMinuteQuotaLeft','decreaseMinuteQuota','getIsOverQuota','logUsage','undoLogUsage']as$name)$native.=nativeMethod($source,$name)."\n";
eval('class DeliveryServer {
 public $server_id=445,$minute_quota=450,$hourly_quota=15000,$hourUsed=0,$newRecord=false;
 protected $_minuteQuotaAccessKey="DeliveryServerGetMinuteQuotaLeft::%d";
 function getCanHaveMinuteQuota(){return !$this->newRecord&&$this->minute_quota>0;}
 function getCanLogUsage(){return true;} function getCustomerByDeliveryObject(){return null;} function getDeliveryFor(){return 1;}
 function getCanHaveQuota(){return true;} function disableLogUsage(){} function enableLogUsage(){} function getMailer(){return new class{function getLog(){return [];}};}
 function getIsNewRecord(){return $this->newRecord;} function getSecondQuotaLeft(){return PHP_INT_MAX;}
 function getHourlyQuotaLeft(){return max(0,$this->hourly_quota-$this->hourUsed);} function getDailyQuotaLeft(){return PHP_INT_MAX;} function getMonthlyQuotaLeft(){return PHP_INT_MAX;}
 function decreaseSecondQuota($by=1){} function decreaseHourlyQuota($by=1){} function decreaseDailyQuota($by=1){} function decreaseMonthlyQuota($by=1){}
 function countMinuteUsage(){return fixtureCount($this->server_id);}
 '.$native.'}');
$now=120.25;$logs=[];$nextId=0;$cacheValues=[];$queries=[];$held=[];
$sharedPath=($argv[2]??'')==='--worker'?($argv[3]??null):null;
if($sharedPath){$sharedDb=new PDO('sqlite:'.$sharedPath.'/ledger.sqlite');$sharedDb->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$sharedDb->exec('PRAGMA busy_timeout=5000');}
function fixtureCount(int $serverId):int{global$logs,$now,$sharedDb;$start=floor($now/60)*60;if(isset($sharedDb)){$s=$sharedDb->prepare('SELECT COUNT(*) FROM usage WHERE server_id=? AND at>=? AND at<?');$s->execute([$serverId,$start,$start+60]);return(int)$s->fetchColumn();}return count(array_filter($logs,static function($row)use($serverId,$start){return $row[0]===$serverId&&$row[1]>=$start&&$row[1]<$start+60;}));}
function cache(){static$cache;if(!$cache)$cache=new class{
 function get($key){global$cacheValues,$now,$sharedPath;if($sharedPath){$path=$sharedPath.'/'.$key.'.json';if(!is_file($path))return false;$row=json_decode(file_get_contents($path),true,8,JSON_THROW_ON_ERROR);return$row[1]>$now?$row[0]:false;}if(!isset($cacheValues[$key])||$cacheValues[$key][1]<=$now)return false;return $cacheValues[$key][0];}
 function set($key,$value,$ttl){global$cacheValues,$now,$sharedPath;if($sharedPath)return file_put_contents($sharedPath.'/'.$key.'.json',json_encode([$value,$now+$ttl]),LOCK_EX)!==false;$cacheValues[$key]=[$value,$now+$ttl];return true;}
 function delete($key){global$cacheValues,$sharedPath;if($sharedPath){$path=$sharedPath.'/'.$key.'.json';return!is_file($path)||unlink($path);}unset($cacheValues[$key]);return true;}
};return$cache;}
function mutex(){static$mutex;if(!$mutex)$mutex=new class{
 function acquire($key,$timeout){global$held,$sharedPath;if(isset($held[$key]))throw new RuntimeException('Unexpected nested native lock');if($sharedPath){$stream=fopen($sharedPath.'/'.$key.'.lock','c+');if(!$stream||!flock($stream,LOCK_EX))return false;$held[$key]=$stream;}else$held[$key]=true;return true;}
 function release($key){global$held,$sharedPath;if(!isset($held[$key]))throw new RuntimeException('Wrong native unlock');if($sharedPath){flock($held[$key],LOCK_UN);fclose($held[$key]);}unset($held[$key]);}
};return$mutex;}
class DeliveryServerUsageLog{
 const TEXT_NO='no';public$log_id,$server_id,$delivery_for;
 static function model(){return new self();}function tableName(){return'{{delivery_server_usage_log}}';}
 function addRelatedRecord(...$v){}function getIsCustomerCountable(){return false;}
 function save($validate){global$logs,$nextId,$now,$sharedDb;if(isset($sharedDb)){$s=$sharedDb->prepare('INSERT INTO usage(server_id,at) VALUES (?,?)');$s->execute([(int)$this->server_id,$now]);$this->log_id=(int)$sharedDb->lastInsertId();return true;}$this->log_id=++$nextId;$logs[$this->log_id]=[(int)$this->server_id,$now];return true;}
 function deleteAllByAttributes($attrs){global$logs;$id=$attrs['log_id'];if(!isset($logs[$id]))return 0;unset($logs[$id]);return 1;}
}
class Yii{static function app(){return new self();}function getDb(){return new self();}function createCommand(){return new QuotaCommand();}static function log(...$args){}}
class CLogger{const LEVEL_ERROR='error';}
class QuotaCommand{
 private$serverId;function select($sql){if($sql!=='UNIX_TIMESTAMP(NOW(6)) AS quota_now, COUNT(*) AS quota_used')throw new RuntimeException('Clock/count must be one SQL snapshot');return$this;}
 function from($table){if($table!=='{{delivery_server_usage_log}}')throw new RuntimeException('Native ledger required');return$this;}
 function where($sql,$params){global$queries;if(!str_contains($sql,'date_added >= DATE_FORMAT(NOW()')||!str_contains($sql,'date_added < DATE_FORMAT(NOW() + INTERVAL 1 MINUTE'))throw new RuntimeException('Calendar bounds changed');$this->serverId=$params[':serverId'];$queries[]=$sql;return$this;}
 function queryRow(){global$now;return['quota_now'=>(string)$now,'quota_used'=>(string)fixtureCount($this->serverId)];}
}
require_once __DIR__.'/../magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
if($sharedPath){
    $commandPath=$argv[5]??'';if(!is_file($commandPath)||hash_file('sha256',$commandPath)!=='4886275fe4cf16b66a699cee1ee0b377b92706e2abd8d80711f388159263a60f')throw new RuntimeException('Inspected private worker required');
    $command=file_get_contents($commandPath);$a=strpos($command,'            $canHaveQuota   = $server->getCanHaveQuota();');$b=strpos($command,'            // end 1.5.3',$a);
    if($a===false||$b===false)throw new RuntimeException('Worker reservation context changed');$send=substr($command,$a,$b-$a);
    eval('class NativeWorker {function stdout(...$args){}function send($server){$mustSend=true;$emailParams=[];$campaign=(object)["campaign_uid"=>"synthetic-only"];$subscriber=(object)[];'.$send.'return $sent;}}');
    class OldFixtureServer extends DeliveryServer{function send(array $params=[]):array{return ['synthetic'=>true];}}
    class NewFixtureServer extends DeliveryServerMagicSmtpWebApi{function send(array $params=[]):array{return ['synthetic'=>true];}}
    $worker=new NativeWorker();$accepted=0;$denied=0;$kind=$argv[4]??'new';
    for($i=0;$i<80;$i++){$server=$kind==='old'?new OldFixtureServer():new NewFixtureServer();try{if($worker->send($server))++$accepted;}catch(Exception$e){if($e->getCode()!==99)throw$e;++$denied;}}
    if($held)throw new RuntimeException('Native mutex leak');echo json_encode(['pid'=>getmypid(),'kind'=>$kind,'accepted'=>$accepted,'denied'=>$denied]);exit;
}
$server=new DeliveryServerMagicSmtpWebApi();$base=new DeliveryServer();$key=sha1('DeliveryServerGetMinuteQuotaLeft::445');
$log=$server->logUsage();check($log&&fixtureCount(445)===1&&$server->getMinuteQuotaLeft()===449,'actual native logUsage dispatches to extension writer after one durable row');
check($server->undoLogUsage($log)&&$server->getMinuteQuotaLeft()===450,'actual native undo removes row and does not double refund');
$old=$server->logUsage();$now=181;$current=$server->logUsage();
check($server->undoLogUsage($old)&&$server->getMinuteQuotaLeft()===449,'actual previous-minute native undo does not credit current minute');
$logs=[];$now=240.25;$accepted=0;
for($i=0;$i<460;$i++){$worker=new DeliveryServerMagicSmtpWebApi();if($worker->getIsOverQuota())continue;$worker->logUsage();++$accepted;}
check($accepted===450&&fixtureCount(445)===450,'actual native combined quota check and usage path accept exactly 450 across synthetic workers');
$now=301;check($base->getMinuteQuotaLeft()===450,'unchanged native base selector sees cache expired at next cron minute');
$server->hourUsed=15000;check($server->getIsOverQuota()===true,'native hourly cap still rejects when minute allowance is available');$server->hourUsed=0;
// Acceptance boundary: unchanged native selector can still populate its own zero
// just before the boundary. Extension checks do not bypass that selector.
$logs=[];$now=359.8;for($i=0;$i<450;$i++)$logs[++$nextId]=[445,359.0];cache()->delete($key);
check($base->getMinuteQuotaLeft()===0,'native base cold miss writes its original zero cache');
$now=361;check($base->getMinuteQuotaLeft()===0&&$server->getMinuteQuotaLeft()===450,'documented native base 30-second carryover remains while strict extension count is fresh');
$now=391;check($base->getMinuteQuotaLeft()===450,'native base cold-cache limitation ends at its own expiry without clearing it');
$before=count($queries);$server->minute_quota=0;check($server->getMinuteQuotaLeft()===PHP_INT_MAX&&count($queries)===$before,'native unlimited configuration avoids new query path');
check(!$held,'all actual native mutexes released');
echo "PASS private native methods + real extension, synthetic DB/cache only; no application bootstrap or network\n";
