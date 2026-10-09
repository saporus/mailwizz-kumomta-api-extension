<?php declare(strict_types=1);
/** Actual ingress/model, SQLite ledger adapters and process-shared DB transactions. */
define('MW_PATH', __DIR__);
class DeliveryServerSmtp { public $server_id = 433; }
class CHttpRequest { public $raw; function __construct($payload) { $this->raw = json_encode($payload); } function getRawBody() { return $this->raw; } }
class CDbCriteria { public $params = []; function addCondition($value) {} }
class FixtureConnection {
    public $tablePrefix = 'mw_', $pdo, $transaction;
    function setActive($value) { if (!empty($GLOBALS['forbidDb'])) throw new RuntimeException('Unauthorized database access'); }
    function getPdoInstance() { $this->setActive(true); ++$GLOBALS['dbReads']; return $this->pdo; }
    function getCurrentTransaction() { return $this->transaction && $this->transaction->getActive() ? $this->transaction : null; }
    function beginTransaction() {
        if (!empty($GLOBALS['lockFail'])) throw new RuntimeException('Synthetic DB lock failure');
        $this->pdo->exec('BEGIN IMMEDIATE');
        return $this->transaction=new class($this->pdo) {
            private $pdo,$active=true; function __construct($pdo){$this->pdo=$pdo;}
            function getActive(){return $this->active;}
            function commit(){if(!empty($GLOBALS['commitFail']))throw new RuntimeException('Synthetic commit failure');$this->pdo->exec('COMMIT');$this->active=false;}
            function rollback(){$this->pdo->exec('ROLLBACK');$this->active=false;}
        };
    }
}
class Yii { static function app() { if (!empty($GLOBALS['forbidDb'])) throw new RuntimeException('Unauthorized app access'); return (object)['db' => $GLOBALS['connection']]; } static function log(...$args) {} }
class CLogger { const LEVEL_ERROR='error'; }
function query($sql, $params = []) { ++$GLOBALS['dbReads']; $stmt = $GLOBALS['connection']->getPdoInstance()->prepare($sql); $stmt->execute($params); return $stmt; }
class CampaignDeliveryLog { const STATUS_SUCCESS = 'success'; static function model() { return new self(); } function tableName() { return '{{campaign_delivery_log}}'; } }
class CampaignDeliveryLogArchive { static function model() { return new self(); } function tableName() { return '{{campaign_delivery_log_archive}}'; } }
class CampaignDeliveryLogHelper {
    static function findByCriteria($criteria) { $p=$criteria->params; return query('SELECT * FROM mw_campaign_delivery_log WHERE server_id=? AND status=? AND email_message_id IN (?,?) LIMIT 1',[$p[':magic_server_id'],$p['status'],$p['email_message_id'],$p['email_message_id_bracket']])->fetchObject() ?: null; }
}
class Campaign {
    public $campaign_id,$campaign_uid,$customer_id,$list_id,$customer;
    static function model() { return new self(); }
    function findByPk($id) { $row=query('SELECT * FROM mw_campaign WHERE campaign_id=?',[$id])->fetchObject(self::class); if($row)$row->customer=new class{function getGroupOption($name,$default){return !empty($GLOBALS['ownBlacklist'])?'yes':'no';}}; return $row?:null; }
}
class ListSubscriber {
    const STATUS_CONFIRMED='confirmed', STATUS_BLACKLISTED='blacklisted',STATUS_UNSUBSCRIBED='unsubscribed';
    public $list_id,$subscriber_id,$subscriber_uid,$email,$status;
    static function model() { return new self(); }
    function findByAttributes($attrs) { return query('SELECT * FROM mw_list_subscriber WHERE list_id=? AND subscriber_id=?',[$attrs['list_id'],$attrs['subscriber_id']])->fetchObject(self::class) ?: null; }
    function addToBlacklist($reason) {
        $GLOBALS['globalBlacklistCalls']=($GLOBALS['globalBlacklistCalls']??0)+1;
        if (($GLOBALS['blacklistMode']??'')==='false') return false;
        $this->status='blacklisted';
        if (($GLOBALS['blacklistMode']??'')!=='memory') query("UPDATE mw_list_subscriber SET status='blacklisted' WHERE subscriber_id=?",[$this->subscriber_id]);
        return true;
    }
    function addToCustomerBlacklist($reason) {
        $GLOBALS['customerBlacklistCalls']=($GLOBALS['customerBlacklistCalls']??0)+1;
        if(!empty($GLOBALS['customerBlacklistFail']))return false;
        query('INSERT INTO mw_customer_email_blacklist VALUES (?,?)',[1,$this->email]);
        return $this->saveStatus('blacklisted');
    }
    function saveStatus($status) { if(!empty($GLOBALS['statusFail']))return false;query('UPDATE mw_list_subscriber SET status=? WHERE subscriber_id=?',[$status,$this->subscriber_id]);$this->status=$status;return true; }
    function getIsUnsubscribed() { return $this->status==='unsubscribed'; }
    function delete() { throw new RuntimeException('Fixture forbids deletion'); }
}
abstract class FixtureFeedbackLog {
    public $campaign_id,$subscriber_id,$message,$note,$ip_address,$user_agent;
    static function model() { return new static(); }
    function findByAttributes($attrs) { return query('SELECT campaign_id,subscriber_id FROM '.static::TABLE.' WHERE campaign_id=? AND subscriber_id=?',[$attrs['campaign_id'],$attrs['subscriber_id']])->fetchObject(static::class)?:null; }
    function countByAttributes($attrs) { return $this->findByAttributes($attrs)?1:0; }
    function save($validate=false) { if(($GLOBALS['logFail']??'')===static::TABLE)return false;query('INSERT INTO '.static::TABLE.' VALUES (?,?)',[$this->campaign_id,$this->subscriber_id]);return true; }
}
class CampaignComplainLog extends FixtureFeedbackLog { const TABLE='mw_campaign_complain_log'; }
class CampaignTrackUnsubscribe extends FixtureFeedbackLog { const TABLE='mw_campaign_track_unsubscribe'; }
class CustomerEmailBlacklist { static function model(){return new self();} function findByAttributes($attrs){return query('SELECT * FROM mw_customer_email_blacklist WHERE customer_id=? AND email=?',[$attrs['customer_id'],$attrs['email']])->fetchObject()?:null;} }
class EmailBlacklist { const ABUSE_COMPLAINT_REASON='Abuse complaint'; }
class Lists { static function flushSubscribersCountCacheByListsIds($ids) { if($GLOBALS['connection']->getCurrentTransaction() || $ids!==[22])throw new RuntimeException('Invalid counter invalidation scope');++$GLOBALS['counterInvalidations'];if(!empty($GLOBALS['cacheFail']))throw new RuntimeException('Synthetic counter cache failure'); } }
class OptionBase { const TEXT_YES='yes'; }
class StringHelper { static function truncateLength($value,$length){return substr($value,0,$length);} }
function request(){return new class{function getUserHostAddress(){return '127.0.0.1';}function getUserAgent(){return 'synthetic';}};}
$nativeFbl=getenv('MAGIC_SMTP_NATIVE_FBL');
if(!$nativeFbl || hash_file('sha256',$nativeFbl)!=='305bdf8c422c1bcac02d05df7c633f1a36dbbd0a53e6a99796e729db2d0414cc')throw new RuntimeException('Pinned private native FBL source required');
require $nativeFbl;
function container(){return new class{function get($class){$action=new OptionCronProcessFeedbackLoopServers();$action->subscriber_action=$GLOBALS['complaintAction']??'blacklist';return $action;}};}
class CampaignBounceLog {
    const BOUNCE_HARD='hard', BOUNCE_SOFT='soft';
    public $campaign_id,$subscriber_id,$message,$bounce_type;
    static function model() { return new self(); }
    function findByAttributes($attrs) { return query('SELECT * FROM mw_campaign_bounce_log WHERE campaign_id=? AND subscriber_id=?',[$attrs['campaign_id'],$attrs['subscriber_id']])->fetchObject(self::class) ?: null; }
    function save() { if (!empty($GLOBALS['saveFail'])) return false; query('INSERT INTO mw_campaign_bounce_log VALUES (?,?,?,?)',[$this->campaign_id,$this->subscriber_id,$this->message,$this->bounce_type]); return true; }
}
class FixtureMutex {
    public $held=[];
    function acquire($key,$timeout) {
        if (!empty($GLOBALS['lockFail'])) return false;
        if (isset($this->held[$key])) throw new RuntimeException('Nested bounce mutex');
        $handle=fopen($GLOBALS['lockDirectory'].'/'.hash('sha256',$key).'.lock','c');
        if (!$handle || !flock($handle,LOCK_EX)) throw new RuntimeException('Fixture lock failed');
        $this->held[$key]=$handle; return true;
    }
    function release($key) { flock($this->held[$key],LOCK_UN); fclose($this->held[$key]); unset($this->held[$key]); }
}
function mutex() { static $value; return $value ?? ($value=new FixtureMutex()); }
function app_param($name,$default=[]) { return $name==='magicsmtp.policyBridges'?$GLOBALS['bindings']:$default; }
function controller() { static $value; return $value??($value=new class { public $data; function renderJson($data,$status=200) { $this->data=$data; http_response_code($status); } }); }
require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtp.php';
function binding(): array { return ['bridge_id'=>'synthetic-bridge','tenant_id'=>'synthetic-tenant','customer_id'=>1,'server_ids'=>[433,445],'enabled'=>true,'secret'=>str_repeat('s',40)]; }
function payload(): array { return ['event_type'=>'bounce','tenant'=>'synthetic-tenant','event_id'=>'original@example.test','webhook_event_id'=>'synthetic-webhook-event','data'=>['recipient'=>'synthetic@example.test','message_id'=>'original@example.test','campaign_id'=>'campaign328','bounce_type'=>'hard','response_text'=>'Synthetic no mailbox']]; }
function connect(string $file): void { $GLOBALS['connection']=new FixtureConnection(); $GLOBALS['connection']->pdo=new PDO('sqlite:'.$file,null,null,[PDO::ATTR_ERRMODE=>PDO::ERRMODE_EXCEPTION]); $GLOBALS['connection']->pdo->exec('PRAGMA busy_timeout=10000'); $GLOBALS['dbReads']=0; }
function seed(): void {
    $pdo=$GLOBALS['connection']->pdo;
    $pdo->exec('CREATE TABLE mw_campaign(campaign_id INTEGER,campaign_uid TEXT,customer_id INTEGER,list_id INTEGER); CREATE TABLE mw_list_subscriber(subscriber_id INTEGER,subscriber_uid TEXT,list_id INTEGER,email TEXT,status TEXT); CREATE TABLE mw_campaign_delivery_log(server_id INTEGER,campaign_id INTEGER,subscriber_id INTEGER,email_message_id TEXT,status TEXT); CREATE TABLE mw_campaign_delivery_log_archive AS SELECT * FROM mw_campaign_delivery_log; CREATE TABLE mw_campaign_bounce_log(campaign_id INTEGER,subscriber_id INTEGER,message TEXT,bounce_type TEXT); CREATE TABLE mw_campaign_complain_log(campaign_id INTEGER,subscriber_id INTEGER); CREATE TABLE mw_campaign_track_unsubscribe(campaign_id INTEGER,subscriber_id INTEGER); CREATE TABLE mw_customer_email_blacklist(customer_id INTEGER,email TEXT)');
    $pdo->exec("INSERT INTO mw_campaign VALUES(328,'campaign328',1,22); INSERT INTO mw_list_subscriber VALUES(33,'subscriber33',22,'synthetic@example.test','confirmed'); INSERT INTO mw_campaign_delivery_log VALUES(445,328,33,'<original@example.test>','success')");
}
function resetFixture(): void { $GLOBALS['forbidDb']=false; $GLOBALS['bindings']=[binding()]; $GLOBALS['blacklistMode']='';$GLOBALS['logFail']='';$GLOBALS['complaintAction']='blacklist';$GLOBALS['globalBlacklistCalls']=0;$GLOBALS['customerBlacklistCalls']=0;$GLOBALS['counterInvalidations']=0;foreach(['saveFail','lockFail','commitFail','statusFail','ownBlacklist','customerBlacklistFail','cacheFail']as$key)$GLOBALS[$key]=false; connect(':memory:'); seed(); }
function callback(?array $event=null, string $signature='valid', int $server=433): int {
    $request=new CHttpRequest($event??payload());
    if($signature==='tamper') { $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']=hash_hmac('sha256',$request->raw,binding()['secret']); $request->raw.=' '; }
    else $_SERVER['HTTP_X_WEBHOOK_SIGNATURE']=$signature==='valid'?hash_hmac('sha256',$request->raw,binding()['secret']):$signature;
    $model=new DeliveryServerMagicSmtp(); $model->server_id=$server; $model->handleCallback($request); return http_response_code();
}
function countBounces(): int { return (int)query('SELECT COUNT(*) FROM mw_campaign_bounce_log')->fetchColumn(); }
function complaint(): int { $event=payload();$event['event_type']='complaint';return callback($event); }
function complaints(): int { return (int)query('SELECT COUNT(*) FROM mw_campaign_complain_log')->fetchColumn(); }
function check($value,$label): void { if(!$value)throw new RuntimeException($label); echo 'PASS '.$label."\n"; }

if (in_array($argv[1]??'', ['seed','verify'], true)) {
    $GLOBALS['lockDirectory']=$argv[2];connect($argv[2].'/ledger.sqlite');
    if($argv[1]==='seed'){seed();echo 'PASS seed';}
    else {if(countBounces()!==1 || query('SELECT status FROM mw_list_subscriber')->fetchColumn()!=='blacklisted')throw new RuntimeException('Concurrent database proof failed');echo 'PASS concurrent database proof';}
    exit;
}
if (($argv[1]??'')==='worker') {
    $GLOBALS['lockDirectory']=$argv[2]; $GLOBALS['bindings']=[binding()]; connect($argv[2].'/ledger.sqlite');
    for($i=0;$i<20;$i++) if(callback()!==200) throw new RuntimeException('Concurrent callback failed');
    if($GLOBALS['connection']->getCurrentTransaction())throw new RuntimeException('Transaction leaked'); echo 'PASS worker'; exit;
}
$GLOBALS['lockDirectory']=sys_get_temp_dir().'/magicsmtp-signed-bounce-'.bin2hex(random_bytes(8)); mkdir($GLOBALS['lockDirectory'],0700);
try {
    foreach([''=>401,'invalid'=>401,'tamper'=>401] as $signature=>$expected) {
        resetFixture(); $GLOBALS['forbidDb']=true; $before=$GLOBALS['dbReads']; $status=callback(null,$signature);
        check($status===$expected && $GLOBALS['dbReads']===$before,'unsigned or modified bytes rejected before database access');
    }
    foreach(['tenant','enabled','ambiguous'] as $case) {
        resetFixture(); $event=payload(); if($case==='tenant')$event['tenant']='other'; if($case==='enabled')$GLOBALS['bindings'][0]['enabled']=false; if($case==='ambiguous')$GLOBALS['bindings'][]=binding();
        $GLOBALS['forbidDb']=true; check(callback($event)===403 && $GLOBALS['dbReads']===0,$case.' binding rejected without database access');
    }
    resetFixture();$GLOBALS['bindings'][0]['enabled']='false';$GLOBALS['forbidDb']=true;check(callback()===403 && $GLOBALS['dbReads']===0,'enabled must be boolean true');
    foreach(['campaign_id','message_id','recipient'] as $field) {
        resetFixture(); $event=payload(); $event['data'][$field]='unknown@example.test';
        check(callback($event)===409 && countBounces()===0,'wrong '.$field.' cannot create a bounce');
    }
    resetFixture(); unset($GLOBALS['bindings'][0]['server_ids'][1]); check(callback()===409 && countBounces()===0,'445 must be explicitly allowed');
    resetFixture(); query('UPDATE mw_campaign SET customer_id=2'); check(callback()===409 && countBounces()===0,'same message cannot cross customer');
    resetFixture(); query("UPDATE mw_campaign_delivery_log SET status='error'"); check(callback()===409 && countBounces()===0,'non-success delivery is not proof');
    resetFixture(); $event=payload(); $event['data']['server_id']=999; check(callback($event)===200 && countBounces()===1,'payload server selector grants no authority');
    resetFixture(); check(callback()===200 && countBounces()===1,'signed shared ingress persists exact server445 delivery');
    check(callback()===200 && countBounces()===1,'signed hard duplicate proves persisted protection without another row');
    resetFixture();query('UPDATE mw_campaign_delivery_log SET server_id=433');check(callback()===200 && countBounces()===1,'existing signed server433 delivery remains compatible');
    resetFixture(); query('INSERT INTO mw_campaign_delivery_log_archive SELECT * FROM mw_campaign_delivery_log'); check(callback()===200 && countBounces()===1,'identical current/archive copy is one tuple');
    resetFixture(); query('INSERT INTO mw_campaign_delivery_log_archive SELECT * FROM mw_campaign_delivery_log'); query('DELETE FROM mw_campaign_delivery_log'); check(callback()===200,'archive-only successful delivery is eligible');
    resetFixture(); query('INSERT INTO mw_campaign_delivery_log_archive SELECT 433,campaign_id,subscriber_id,email_message_id,status FROM mw_campaign_delivery_log'); check(callback()===409 && countBounces()===0,'different authorized servers are ambiguous and have no effects');
    resetFixture(); query("INSERT INTO mw_list_subscriber VALUES(34,'subscriber34',22,'synthetic@example.test','confirmed')"); query('INSERT INTO mw_campaign_delivery_log SELECT server_id,campaign_id,34,email_message_id,status FROM mw_campaign_delivery_log'); check(callback()===409 && countBounces()===0,'different subscriber tuples are ambiguous');
    foreach(['false','memory'] as $mode) { resetFixture(); $GLOBALS['blacklistMode']=$mode; check(callback()===503 && countBounces()===0,'failed signed protection rolls back bounce '.$mode); $GLOBALS['blacklistMode']=''; check(callback()===200 && countBounces()===1,'signed protection retry commits durably '.$mode); }
    resetFixture(); query("INSERT INTO mw_campaign_bounce_log VALUES(328,33,'Prior partial hard result','hard')");check(callback()===200 && countBounces()===1,'previously durable partial bounce repairs protection');
    resetFixture(); $GLOBALS['saveFail']=true; check(callback()===503 && countBounces()===0 && !$GLOBALS['connection']->getCurrentTransaction(),'save failure rolls back transaction without effect');
    resetFixture(); $GLOBALS['lockFail']=true; check(callback()===503 && countBounces()===0,'lock failure returns retryable without effects');
    resetFixture(); $GLOBALS['bindings']=[]; check(callback(null,'',445)===200,'unbound legacy same-server remains compatible');
    resetFixture(); $GLOBALS['bindings']=[]; check(callback(null,'',433)===409 && countBounces()===0,'legacy cannot cross servers');
    resetFixture(); $event=payload(); $event['event_type']='complaint'; $GLOBALS['forbidDb']=true; check(callback($event,'')===401,'configured complaint cannot bypass authentication');
    resetFixture();check(complaint()===200 && complaints()===1 && $GLOBALS['globalBlacklistCalls']===1,'native configured blacklist complaint commits protected log');
    check(complaint()===200 && complaints()===1 && $GLOBALS['globalBlacklistCalls']===1,'duplicate complaint proves existing effects without repeating action');
    resetFixture();$GLOBALS['ownBlacklist']=true;check(complaint()===200 && complaints()===1 && $GLOBALS['globalBlacklistCalls']===0 && $GLOBALS['customerBlacklistCalls']===1,'customer blacklist action never escalates to global blacklist');
    resetFixture();$GLOBALS['ownBlacklist']=true;$GLOBALS['customerBlacklistFail']=true;check(complaint()===503 && complaints()===0,'failed customer blacklist cannot commit complaint');
    resetFixture();$GLOBALS['complaintAction']='unsubscribe';check(complaint()===200 && complaints()===1 && $GLOBALS['globalBlacklistCalls']===0 && query('SELECT status FROM mw_list_subscriber')->fetchColumn()==='unsubscribed','native unsubscribe action never blacklists');
    check(complaint()===200 && complaints()===1 && (int)query('SELECT COUNT(*) FROM mw_campaign_track_unsubscribe')->fetchColumn()===1,'unsubscribe duplicate has one native record of each effect');
    resetFixture();$GLOBALS['complaintAction']='unsubscribe';query("UPDATE mw_list_subscriber SET status='unsubscribed'");check(complaint()===200 && complaints()===1 && (int)query('SELECT COUNT(*) FROM mw_campaign_track_unsubscribe')->fetchColumn()===1,'old partial unsubscribe repairs only authenticated campaign logs');
    foreach(['mw_campaign_complain_log','mw_campaign_track_unsubscribe']as$table){resetFixture();$GLOBALS['complaintAction']='unsubscribe';$GLOBALS['logFail']=$table;check(complaint()===503 && complaints()===0 && query('SELECT status FROM mw_list_subscriber')->fetchColumn()==='confirmed','native swallowed log failure rolls back status and logs '.$table);}
    resetFixture();$GLOBALS['statusFail']=true;$GLOBALS['complaintAction']='unsubscribe';check(complaint()===503 && complaints()===0,'native swallowed unsubscribe status failure does not ACK');
    check($GLOBALS['counterInvalidations']===1,'rolled-back native action invalidates only its list counter after transaction');
    $GLOBALS['statusFail']=false;check(complaint()===200 && complaints()===1 && $GLOBALS['counterInvalidations']===1,'retry commits once without repeated rollback invalidation');
    resetFixture();$GLOBALS['statusFail']=true;$GLOBALS['complaintAction']='unsubscribe';$GLOBALS['cacheFail']=true;check(complaint()===503 && complaints()===0 && !$GLOBALS['connection']->getCurrentTransaction(),'counter cache failure preserves retryable error and rolled-back native effects');
    resetFixture();$GLOBALS['commitFail']=true;check(complaint()===503 && complaints()===0 && query('SELECT status FROM mw_list_subscriber')->fetchColumn()==='confirmed','failed commit returns retryable and rolls back');
    resetFixture();$GLOBALS['complaintAction']='delete';check(complaint()===503 && complaints()===0 && query('SELECT COUNT(*) FROM mw_list_subscriber')->fetchColumn()==1,'delete action remains retryable without destroying correlation');
    resetFixture();$event=payload();$event['event_type']='complaint';$event['data']['campaign_id']='other';check(callback($event)===409 && complaints()===0 && $GLOBALS['globalBlacklistCalls']===0,'unmatched complaint never changes protection');
    resetFixture(); $event=payload(); unset($event['webhook_event_id']); $GLOBALS['forbidDb']=true; check(callback($event)===422,'event_id is not substituted for webhook receipt identity');
    resetFixture(); $event=payload(); $event['data']['campaign_id']=[]; $GLOBALS['forbidDb']=true; check(callback($event)===422,'malformed signed tuple rejected before DB');
    check(!$GLOBALS['connection']->getCurrentTransaction() && !mutex()->held,'every persistence exit closes transaction and native action lock');

    if (($argv[1]??'')!=='suite-only') {
    $GLOBALS['forbidDb']=false; connect($GLOBALS['lockDirectory'].'/ledger.sqlite'); seed(); $processes=[];
    for($i=0;$i<8;$i++) { $pipes=[]; $process=proc_open([PHP_BINARY,'-d','extension='.(getenv('MAGIC_SMTP_SQLITE_EXTENSION')?:'pdo_sqlite'),__FILE__,'worker',$GLOBALS['lockDirectory']],[0=>['pipe','r'],1=>['pipe','w'],2=>['pipe','w']],$pipes); if(!is_resource($process))throw new RuntimeException('Could not start fixture worker'); fclose($pipes[0]); $processes[]=[$process,$pipes]; }
    $workerErrors=[];foreach($processes as [$process,$pipes]) { $out=stream_get_contents($pipes[1]); $error=stream_get_contents($pipes[2]); fclose($pipes[1]); fclose($pipes[2]); if(proc_close($process)!==0 || $error || $out!=='PASS worker')$workerErrors[]=$error; }if($workerErrors)throw new RuntimeException('Concurrent fixture failed '.implode(';',$workerErrors));
    check(countBounces()===1 && query('SELECT status FROM mw_list_subscriber')->fetchColumn()==='blacklisted','8 real processes / 160 callbacks persist one protected bounce');
    }
    echo "PASS synthetic PHP/SQLite/process acceptance; zero network calls; normal demo accounts not verified\n";
} finally {
    $GLOBALS['connection']=null;
    foreach(glob($GLOBALS['lockDirectory'].'/*') as $path)unlink($path);
    rmdir($GLOBALS['lockDirectory']);
}
