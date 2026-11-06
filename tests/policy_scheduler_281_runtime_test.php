<?php declare(strict_types=1);
/** Executes candidate MailWizz methods against isolated SQLite fixtures, with no application/network bootstrap. */
define('MW_PATH',__DIR__);
define('MW_VERSION','2.8.1');
$candidate=$argv[1]??'';
if (!$candidate || !is_file($candidate.'/policy-scheduler-manifest.json')) { fwrite(STDERR,"Usage: php policy_scheduler_281_runtime_test.php PREPARED_CANDIDATE_DIRECTORY\n");exit(2); }
$candidate=realpath($candidate);$manifest=json_decode(file_get_contents($candidate.'/policy-scheduler-manifest.json'),true,16,JSON_THROW_ON_ERROR);
if(($manifest['mailwizz_version']??'')!=='2.8.1'||($manifest['qualification_profile']??'')!=='mailwizz-2.8.1-policy-20261106')throw new RuntimeException('Expected exact 2.8.1 qualification profile');
foreach($manifest['sha256'] as $file=>$hash) if(!hash_equals($hash,hash_file('sha256',$candidate.'/'.$file))) throw new RuntimeException('Candidate hash mismatch');
function check($value,string $label): void { if(!$value)throw new RuntimeException($label);echo 'PASS '.$label."\n"; }
class CDbCriteria {
    public $with=[],$params=[],$order='',$condition='',$limit=0,$offset=0;
    function addCondition($v){$this->condition=$this->condition?'('.$this->condition.') AND ('.$v.')':$v;}
    function addInCondition($field,$values){$this->addCondition($field.' IN ('.implode(',',array_map('intval',$values)).')');}
    function mergeWith($c){if($c->condition)$this->addCondition($c->condition);$this->params=array_merge($this->params,$c->params);}
}
class CActiveRecordBehavior {public $owner;}
class ConsoleCommand {function stdout(...$args){} }
class FakeHooks {public $override=false;function hasFilters($name){return $this->override;}function doAction(...$args){}function applyFilters($name,$value,...$args){if($name==='console_command_send_campaigns_before_send_to_subscriber'&&!empty($GLOBALS['injectLate']))($GLOBALS['injectLate'])();return $value;} }
$hooks=new FakeHooks();function hooks(){return $GLOBALS['hooks'];}
class Query {
    private $pdo,$sql,$fields='*',$table='',$where=[],$params=[],$order='',$limit,$offset=0;
    function __construct($pdo,$sql=''){$this->pdo=$pdo;$this->sql=$sql;}
    function select($v){$this->fields=$v;return $this;}function from($v){$this->table=$v;return $this;}
    function expression($v){if(!is_array($v))return str_replace('NOW()',"'2027-01-15 00:00:00'",$v);$op=array_shift($v);if($op==='and')return '('.implode(' AND ',array_map([$this,'expression'],$v)).')';if($op==='in')return $v[0].' IN ('.implode(',',array_map([$this->pdo,'quote'],$v[1])).')';throw new RuntimeException('Unexpected query expression');}
    function where($v){$this->where=[$this->expression($v)];return $this;}function andWhere($v){$this->where[]=$this->expression($v);return $this;}
    function order($v){$this->order=$v;return $this;}function offset($v){$this->offset=$v;return $this;}function limit($v){$this->limit=$v;return $this;}
    function run($params=[]){$sql=$this->sql?:'SELECT '.$this->fields.' FROM '.$this->table.($this->where?' WHERE '.implode(' AND ',$this->where):'').($this->order?' ORDER BY '.$this->order:'').($this->limit!==null?' LIMIT '.(int)$this->limit.' OFFSET '.(int)$this->offset:'');$sql=str_replace(['{{','}}'],['',''],$sql);$stmt=$this->pdo->prepare($sql);$stmt->execute($params);return $stmt;}
    function queryAll($params=[]){return $this->run($params)->fetchAll(PDO::FETCH_ASSOC);}function queryRow($params=[]){return $this->run($params)->fetch(PDO::FETCH_ASSOC);}function queryScalar($params=[]){return $this->run($params)->fetchColumn();}
}
class FakeDb {public $pdo,$tablePrefix='';function __construct($p){$this->pdo=$p;}function createCommand($sql=''){return new Query($this->pdo,$sql);}function setActive($v){}function getPdoInstance(){return $this->pdo;}}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);$database=new FakeDb($pdo);function db(){return $GLOBALS['database'];}
class Yii {static function app(){return (object)['db'=>db()];}static function getPathOfAlias($v){return $GLOBALS['candidate'].'/apps/common';}}
function app_param($key,$default=null){return $key==='magicsmtp.policyBridges'?$GLOBALS['bindings']:$default;}
class ListSubscriber {
    const STATUS_CONFIRMED='confirmed';public $subscriber_id,$email,$subscriber_uid='fixture-subscriber',$status='confirmed',$blocked=false;
    static function model(){return new self();}function tableName(){return '{{list_subscriber}}';}
    function findAll($c){$sql='SELECT t.* FROM list_subscriber t'.($c->condition?' WHERE '.$c->condition:'');$s=db()->pdo->query($sql);return $s->fetchAll(PDO::FETCH_CLASS,self::class);}
    function getIsConfirmed(){return $this->status==='confirmed';}function getIsBlacklisted($options=[]){return $this->blocked?new EmailBlacklistCheckInfo():false;}
}
class CMap {static function mergeArray(...$arrays){return array_merge(...$arrays);}}
class CAttributeCollection {private $items;function __construct($items){$this->items=$items;}function itemAt($name){return $this->items[$name];}}
class OptionUrl {function getFrontendUrl($path){return 'https://fixture.example.test/'.$path;}}
function container(){return new class{function get($class){return new $class();}};}
function t($category,$value){return $value;}
class EmailHelper {static function getListIDHeaderValue($list){return 'fixture.example.test';}}
class Lists {public $list_uid='fixture-list';}
class Customer {public $customer_id=10,$customer_uid='fixture-customer';function getIsOverQuota(){return false;}function getGroupOption($key,$default){return $default;}function getFullName(){return 'Synthetic customer';}}
class EmailBlacklist {const CHECK_ZONE_CAMPAIGN='campaign';}
class EmailBlacklistCheckInfo {function getCustomerBlacklist(){return false;}}
class CustomerSuppressionListEmail {static function isSubscriberListedByCampaign($s,$c){return !empty($GLOBALS['nativeSuppressed']);}}
class CampaignDeliveryLog {const STATUS_SUCCESS='success',STATUS_ERROR='error',STATUS_BLACKLISTED='blacklisted',STATUS_SUPPRESSED='suppressed',STATUS_BLOCKED='blocked',STATUS_GIVEUP='giveup',STATUS_HANDLED_BY_OTHER_SEND_GROUP_CAMPAIGN='group',STATUS_DOMAIN_POLICY_REJECT='domain';}
class DeliveryServer {
    const USE_FOR_CAMPAIGNS='campaigns',DELIVERY_FOR_CAMPAIGN='campaign',FORCE_REPLY_TO_ALWAYS='always';
    public $server_id=42,$hostname='synthetic.invalid',$name='Synthetic server',$reply_to_email='',$additional_headers=[],$pause_after_send=0,$usage=0,$submissions=0;
    function canSendToDomainOf($email){return true;}function getIsOverQuota(){return false;}function parseHeadersFormat($headers){return $headers;}
    function setDeliveryFor($v){return $this;}function setDeliveryObject($v){return $this;}
    function getCanHaveQuota(){return true;}function getCanLogUsage(){return true;}function logUsage(){$this->usage++;return (object)['synthetic'=>true];}
    function disableLogUsage(){}function enableLogUsage(){}function send($params){$this->submissions++;return ['message_id'=>'synthetic-message'];}
    function getMailer(){return new class{function getLog(){return 'Synthetic accepted';}};}
}
$mutex=new class{public $calls=0;function acquire(...$args){$this->calls++;return true;}function release(...$args){}};function mutex(){return $GLOBALS['mutex'];}
class Campaign {
    const STATUS_SENDING='sending',STATUS_PROCESSING='processing',STATUS_PENDING_SENDING='pending-sending',STATUS_SENT='sent',TYPE_REGULAR='regular',TYPE_AUTORESPONDER='autoresponder';
    public $campaign_id=1,$campaign_uid='fixture-campaign',$name='Synthetic campaign',$customer_id=10,$list_id=1,$queue=false,$auto=false,$status='processing',$option,$queueTable,$customer,$group_id=0,$reply_to='',$send_group_id=0,$segmentEmails=[];
    function __construct(){$this->option=new class{function getTimewarpEnabled(){return false;}function getCanSetMaxSendCountRandom(){return false;}};$this->customer=(object)['logAction'=>new class{function campaignSent($c){}}];}
    function getCanUseQueueTable(){return $this->queue;}function getIsRegular(){return !$this->auto;}function getIsAutoresponder(){return $this->auto;}
    function getIsPaused(){return $this->status==='paused';}function getCanDoAbTest(){return false;}
    function getReloadedStatus(){return $this->status;}function saveStatus($v){$this->status=$v;}function refresh(){}function getIsSent(){return $this->status==='sent';}function sendStatsEmail(){}function tryReschedule(){}function getSendingGiveupsCount(){return 0;}
    function sql($c){return ' FROM list_subscriber t WHERE t.list_id='.(int)$this->list_id." AND t.status='confirmed' AND NOT EXISTS (SELECT 1 FROM campaign_delivery_log d WHERE d.campaign_id=".(int)$this->campaign_id.' AND d.subscriber_id=t.subscriber_id)'.($this->segmentEmails?' AND t.email IN ('.implode(',',array_map([db()->pdo,'quote'],$this->segmentEmails)).')':'').($c&&$c->condition?' AND '.$c->condition:'');}
    function countSubscribers($c=null){return (int)db()->pdo->query('SELECT COUNT(*)'.$this->sql($c))->fetchColumn();}
    function findSubscribers($offset,$limit,$c=null){return db()->pdo->query('SELECT t.*'.$this->sql($c).' ORDER BY t.subscriber_id LIMIT '.(int)$limit.' OFFSET '.(int)$offset)->fetchAll(PDO::FETCH_CLASS,ListSubscriber::class);}
}
require dirname(__DIR__).'/magicsmtp/MagicSmtpPolicyRuntime.php';
require $candidate.'/apps/console/commands/SendCampaignsCommand.php';
require $candidate.'/apps/common/components/db/behaviors/CampaignQueueTableBehavior.php';
class TestCommand extends SendCampaignsCommand {
    public $deliveries=[];
    protected function getCanUsePcntl(){return false;}protected function campaignMustHandleGiveups(Campaign $c){return false;}
    function select(Campaign $c,int $offset=0,int $limit=1){return $this->findSubscribers($offset,$limit,$c);}function pending(Campaign $c){return $this->countSubscribers($c);}function complete(Campaign $c){return $this->markCampaignSent($c);}
    function loop(Campaign $c,ListSubscriber $s,DeliveryServer $server){return $this->processSubscribersLoop(['campaign'=>$c,'subscribers'=>[$s],'changeServerAt'=>0,'list'=>new Lists(),'customer'=>new Customer(),'server'=>$server]);}
    protected function prepareEmail(ListSubscriber $s,DeliveryServer $server,Campaign $c){return ['subject'=>'Synthetic','body'=>'Synthetic body'];}
    public function getFeedbackIdHeaderValue(Campaign $c,ListSubscriber $s,Lists $l,Customer $owner){return 'synthetic';}
    protected function logDelivery(ListSubscriber $s,string $message,string $status,string $messageId,DeliveryServer $server,Campaign $c):bool{$this->deliveries[]=$status;return true;}
    protected function handleCampaignSentActionSubscriberField(Campaign $c,ListSubscriber $s,DeliveryServer $server):self{return $this;}
    protected function handleCampaignSentActionSubscriber(Campaign $c,ListSubscriber $s):self{return $this;}
}
class TestQueue extends CampaignQueueTableBehavior {
    function createTable(): bool{return false;}
    protected function _getTimewarpCriteria(): ?CDbCriteria{return null;}
    protected function _applyOpenUnopenPreviousCampaignsCriteria(array $subscribers=[]): array{return $subscribers;}
}
$manifest['acceptance']='passed';$bindings=[['bridge_id'=>'bridge-a','tenant_id'=>'tenant-a','customer_id'=>10,'server_ids'=>[42],'secret'=>str_repeat('s',40),'enabled'=>true,'scheduler_manifest'=>$manifest]];
$store=new MagicSmtpPolicyStore($pdo);$store->install();
$pdo->exec('CREATE TABLE list_subscriber(subscriber_id INTEGER PRIMARY KEY,list_id INTEGER,email TEXT,status TEXT);CREATE TABLE campaign_delivery_log(campaign_id INTEGER,subscriber_id INTEGER);CREATE TABLE campaign_queue_1(subscriber_id INTEGER,send_at TEXT,failures INTEGER)');
for($i=1;$i<=4;$i++){$pdo->exec("INSERT INTO list_subscriber VALUES ($i,1,'user$i@example.test','confirmed')");$pdo->exec("INSERT INTO campaign_queue_1 VALUES ($i,'2020-01-01 00:00:00',0)");}
$now=(int)floor(microtime(true)*1000);
foreach([1=>'temporary',2=>'permanent'] as $id=>$action){$store->query('INSERT INTO magic_smtp_policy_effect VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[hash('sha256',(string)$id),'bridge-a','effect-'.$id,10,'user'.$id.'@example.test',$action,$action==='temporary'?$now+3600000:null,'active',$now,'rule',1,'Synthetic policy','message','campaign',$now]);}
$campaign=new Campaign();$command=new TestCommand();
check(MagicSmtpPolicyRuntime::schedulerVerified($bindings[0]),'exact prepared core hashes and acceptance receipt verify');
$reordered=$bindings[0];$reordered['scheduler_manifest']['sha256']=array_reverse($reordered['scheduler_manifest']['sha256'],true);check(MagicSmtpPolicyRuntime::schedulerVerified($reordered),'hash map order is not authority and does not invalidate identical qualified sources');
$bad=$bindings[0];$bad['scheduler_manifest']['acceptance']='pending';check(!MagicSmtpPolicyRuntime::schedulerVerified($bad),'unverified candidate remains activation gated');
$bad=$bindings[0];$bad['scheduler_manifest']['sha256'][array_key_first($manifest['sha256'])]=str_repeat('0',64);check(!MagicSmtpPolicyRuntime::schedulerVerified($bad),'changed core source invalidates activation');
foreach([false,true] as $queue) {
    $campaign->queue=$queue;$campaign->status='processing';$campaign->queueTable=new TestQueue();$campaign->queueTable->owner=$campaign;
    check($command->pending($campaign)===3,($queue?'queue':'regular').' count retains hold and excludes permanent suppression');
    check((int)$command->select($campaign)[0]->subscriber_id===3,($queue?'queue':'regular').' selection excludes effects before LIMIT');
    check((int)$command->select($campaign,1)[0]->subscriber_id===4,($queue?'queue':'regular').' pagination continues other recipients');
    check(!$command->complete($campaign)&&$campaign->status==='sending',($queue?'queue':'regular').' held work prevents early campaign completion');
    $campaign->status='paused';check(!MagicSmtpPolicyRuntime::canComplete($campaign)&&$campaign->status==='paused',($queue?'queue':'regular').' held completion check preserves paused campaign');
}
check((int)$pdo->query('SELECT COUNT(*) FROM campaign_queue_1')->fetchColumn()===4,'temporary and permanent selection never consumes queue rows');
$campaign->queue=true;$campaign->auto=true;
check((int)$command->select($campaign)[0]->subscriber_id===3,'autoresponder retains due-date selection and excludes held recipient');
$pdo->exec("UPDATE campaign_queue_1 SET send_at='2099-01-01 00:00:00' WHERE subscriber_id=3");
check((int)$command->select($campaign)[0]->subscriber_id===4,'future autoresponder recipient remains pending without being sent');
$pdo->exec("UPDATE magic_smtp_policy_effect SET expires_at=1 WHERE action='temporary'");
$campaign->status='paused';check((int)$command->select($campaign)[0]->subscriber_id===1 && $campaign->status==='paused','expiry restores eligibility without resuming paused campaign');
$pdo->exec("UPDATE magic_smtp_policy_effect SET state='released' WHERE action='permanent'");
check((int)$command->select($campaign,1)[0]->subscriber_id===2,'release restores extension eligibility without blacklist mutation');
check((int)$pdo->query('SELECT COUNT(*) FROM campaign_delivery_log')->fetchColumn()===0,'holds never fabricate delivered or giveup records');
$hooks->override=true;check(!MagicSmtpPolicyRuntime::schedulerVerified($bindings[0]),'custom selector overrides invalidate compatibility');
try{$command->select($campaign);throw new RuntimeException('Override did not fail closed');}catch(RuntimeException $e){check(strpos($e->getMessage(),'incompatible')!==false,'custom selector cannot bypass policy protection');}$hooks->override=false;

// A caller's native segment restriction must remain composed with policy criteria.
$campaign->queue=false;$campaign->auto=false;$campaign->segmentEmails=['user1@example.test','user4@example.test'];
$pdo->exec("UPDATE magic_smtp_policy_effect SET state='active',expires_at=".($now+3600000)." WHERE recipient='user1@example.test'");
check($command->pending($campaign)===2 && (int)$command->select($campaign)[0]->subscriber_id===4,'segment restriction composes with hold selection while retaining pending count');
$campaign->segmentEmails=[];

// Execute the actual complete native processSubscribersLoop method, with only
// external/native resource adapters and message preparation replaced by stubs.
$subscriber=new ListSubscriber();$subscriber->subscriber_id=1;$subscriber->email='user1@example.test';
$initialBindings=$bindings;$injectLate=null;$nativeSuppressed=false;
function resetLoopFixture():array {
    global $pdo,$bindings,$initialBindings,$injectLate,$nativeSuppressed,$mutex;
    $bindings=$initialBindings;$injectLate=null;$nativeSuppressed=false;$mutex->calls=0;
    $pdo->exec("UPDATE magic_smtp_policy_effect SET state='released'");
    return [new TestCommand(),new Campaign(),new DeliveryServer()];
}
foreach([false,true]as$queue)foreach(['temporary','permanent','store_failure','readiness_failure']as$case){
    [$loop,$c,$server]=resetLoopFixture();$c->queue=$queue;$c->auto=$queue;$c->send_group_id=77;
    $injectLate=static function()use($case,$pdo,$now):void{
        if($case==='store_failure'){$pdo->exec('ALTER TABLE magic_smtp_policy_effect RENAME TO unavailable_policy_effect');return;}
        if($case==='readiness_failure'){$GLOBALS['bindings'][0]['scheduler_manifest']['qualification_profile']='changed';return;}
        $stmt=$pdo->prepare("UPDATE magic_smtp_policy_effect SET state='active',action=?,expires_at=? WHERE recipient='user1@example.test'");$stmt->execute([$case,$case==='temporary'?$now+3600000:null]);
    };
    $failure=null;try{$loop->loop($c,$subscriber,$server);}catch(Throwable $e){$failure=$e;}
    if($case==='store_failure')$pdo->exec('ALTER TABLE unavailable_policy_effect RENAME TO magic_smtp_policy_effect');
    if($case==='permanent')check(!$failure&&$loop->deliveries===['suppressed'],($queue?'autoresponder/queue':'regular').' late permanent effect uses only suppressed outcome');
    else check($failure&&(int)$failure->getCode()===99&&!$loop->deliveries,($queue?'autoresponder/queue':'regular').' late '.$case.' is retryable without terminal/success records');
    check($server->usage===0&&$server->submissions===0&&$mutex->calls===0,'late '.$case.' consumes no quota, send-group or delivery side effect');
    check((int)$pdo->query('SELECT COUNT(*) FROM campaign_queue_1')->fetchColumn()===4,'late '.$case.' retains original queue membership');
}
[$loop,$c,$server]=resetLoopFixture();$pdo->exec("UPDATE magic_smtp_policy_effect SET state='active',action='temporary',expires_at=".($now+3600000)." WHERE recipient='user1@example.test'");
try{$loop->loop($c,$subscriber,$server);throw new RuntimeException('Early hold sent');}catch(Exception $e){check($e->getCode()===99&&$server->usage===0&&!$loop->deliveries,'post-selection early hold remains pending without quota or giveup');}
[$loop,$c,$server]=resetLoopFixture();$loop->loop($c,$subscriber,$server);
check($server->submissions===1&&$server->usage===1&&$loop->deliveries===['success'],'eligible retry reaches one synthetic handoff and exactly one native quota charge');
foreach(['unsubscribed','blacklisted','native_suppression']as$case){
    [$loop,$c,$server]=resetLoopFixture();$s=clone$subscriber;
    if($case==='unsubscribed')$s->status='unsubscribed';elseif($case==='blacklisted')$s->blocked=true;else$nativeSuppressed=true;
    $loop->loop($c,$s,$server);
    check($server->submissions===0&&$server->usage===0&&count($loop->deliveries)===1&&$loop->deliveries[0]!=='success','native '.$case.' restriction is preserved before quota and dispatch');
}
[$loop,$c,$server]=resetLoopFixture();$bindings=[];$pdo->exec('ALTER TABLE magic_smtp_policy_effect RENAME TO unavailable_policy_effect');
$loop->loop($c,$subscriber,$server);check($server->submissions===1&&$server->usage===1,'absent bindings leave native send path inert without a policy table');
$pdo->exec('ALTER TABLE unavailable_policy_effect RENAME TO magic_smtp_policy_effect');
[$loop,$c,$server]=resetLoopFixture();$c->customer_id=20;$pdo->exec('ALTER TABLE magic_smtp_policy_effect RENAME TO unavailable_policy_effect');
$loop->loop($c,$subscriber,$server);check($server->submissions===1,'other customer binding does not impose added final policy-store checks on unrelated customer');
$pdo->exec('ALTER TABLE unavailable_policy_effect RENAME TO magic_smtp_policy_effect');
[$loop,$c,$server]=resetLoopFixture();
$bad=$bindings[0];$bad['scheduler_manifest']['qualification_profile']='unreviewed';check(!MagicSmtpPolicyRuntime::schedulerVerified($bad),'unreviewed 2.8.1 profile cannot acquire readiness');
$bad=$bindings[0];$bad['scheduler_manifest']['mailwizz_version']='2.7.3';check(!MagicSmtpPolicyRuntime::schedulerVerified($bad),'cross-version acceptance cannot grant 2.8.1 readiness');
$manifest['acceptance']='passed';$manifest['verified_at']=gmdate('c');$manifest['test_sha256']=hash_file('sha256',__FILE__);
file_put_contents($candidate.'/policy-scheduler-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "PASS exact candidate scheduler methods; isolated existing/new policy schema, regular/queue/autoresponder, no external execution\n";
