<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
require dirname(__DIR__).'/magicsmtp/models/MagicSmtpPolicyBridge.php';
function check($value,string $name): void { if (!$value) throw new RuntimeException($name); echo 'PASS '.$name."\n"; }
function rejects(callable $call,string $name): void { try {$call();}catch(Throwable $e){check(true,$name);return;} throw new RuntimeException('Expected rejection: '.$name); }
$path=tempnam(sys_get_temp_dir(),'magic-policy-');
register_shutdown_function(static function()use($path){@unlink($path);});
$db=new PDO('sqlite:'.$path); $store=new MagicSmtpPolicyStore($db); $store->install();
$binding=['bridge_id'=>'bridge-a','tenant_id'=>'tenant-a','customer_id'=>10,'server_ids'=>[42,43],'secret'=>str_repeat('a',40),'enabled'=>true];
$now=1800000000000; $ready=true;
$bridge=new MagicSmtpPolicyBridge($store,[$binding],static function(){return false;},static function()use(&$ready){return $ready;},static function()use(&$now){return $now;});
$bridge->recordDispatch(42,10,'User@Example.test','<original@example.test>','campaign-a','subscriber-a');
$event=['event_id'=>'event-a','webhook_event_id'=>'event-a','tenant'=>'tenant-a','timestamp'=>$now,'event_type'=>'recipient.policy_suppressed','data'=>[
    'bridge_id'=>'bridge-a','recipient'=>'USER@example.test','policy_effect_id'=>'effect-a','action'=>'temporary','expires_at'=>$now+60000,
    'rule_id'=>'rule-a','rule_revision'=>1,'message_id'=>'<original@example.test>','campaign_id'=>'campaign-a','subscriber_uid'=>'subscriber-a','reason'=>'Mailbox full rule',
    'evidence'=>['response_code'=>552,'response_text'=>'Mailbox full','source'=>'test']]];
$send=static function(array $event,int $server=42)use($bridge,$binding){$body=json_encode($event,JSON_UNESCAPED_SLASHES);return $bridge->receive($server,$body,hash_hmac('sha256',$body,$binding['secret']));};
$ack=$send($event);
check($ack===['ok'=>true,'eventId'=>'event-a','effectId'=>'effect-a','bridgeId'=>'bridge-a','tenantId'=>'tenant-a','state'=>'active'],'signed scoped policy returns exact correlated active acknowledgement after any native soft bounce');
check($store->effective(10,'user@example.test',$now)['action']==='temporary','temporary effect is extension owned');
check($store->effective(11,'user@example.test',$now)===null,'customer isolation');
$duplicate=$send($event);check($duplicate==$ack+['duplicate'=>true],'stable event dedupe returns correlated current state');
$tamper=$event;$tamper['data']['reason']='different';rejects(static function()use($send,$tamper){$send($tamper);},'event ID content conflict');
foreach (['tenant','recipient','message_id','campaign_id','subscriber_uid','bridge_id'] as $field) {
    $bad=$event;$bad['event_id']='bad-'.$field;$bad['webhook_event_id']=$bad['event_id'];
    if ($field==='tenant') $bad['tenant']='tenant-b'; else $bad['data'][$field]='other@example.test';
    rejects(static function()use($send,$bad){$send($bad);},'reject wrong '.$field);
}
rejects(static function()use($send,$event){$send($event,99);},'reject unauthorized callback server');
$raw=json_encode($event); rejects(static function()use($bridge,$raw,$binding){$bridge->receive(42,$raw.' ',hash_hmac('sha256',$raw,$binding['secret']));},'signature preserves raw JSON bytes');
rejects(static function()use($bridge,$raw){$bridge->receive(42,$raw,'');},'missing signature');
$ready=false; $probe=['event_id'=>'probe','event_type'=>'recipient.policy_probe','tenant'=>'tenant-a','timestamp'=>$now,'data'=>['bridge_id'=>'bridge-a','challenge'=>'synthetic-challenge']];
check($send($probe)['holdSchedulerVerified']===false,'probe fails readiness closed without verified scheduler');
$bad=$event;$bad['event_id']=$bad['webhook_event_id']='not-ready'; rejects(static function()use($send,$bad){$send($bad);},'unverified scheduler rejects effect activation'); $ready=true;
check($send($probe)['challenge']==='synthetic-challenge','probe echoes signed challenge without effects');
$probe['data']['nonce']='new-nonce';check($send($probe)['nonce']==='new-nonce','nonce probe contract');
$release=$event;$release['event_type']='recipient.policy_released';$release['event_id']=$release['webhook_event_id']='release-a';$release['timestamp']=$now+1;
check($send($release)['state']==='released','manual release applies');
$releasedAck=$send($release);check($releasedAck===['ok'=>true,'eventId'=>'release-a','effectId'=>'effect-a','bridgeId'=>'bridge-a','tenantId'=>'tenant-a','duplicate'=>true,'state'=>'released'],'release duplicate returns correlated released acknowledgement');
check($send($event)['state']==='released','duplicate suppression reports current released state rather than stale active acknowledgement');
check($store->effective(10,'user@example.test',$now)===null,'release removes only matching extension effect');
$late=$event;$late['event_id']=$late['webhook_event_id']='late-suppression';$late['timestamp']=$now+2;
$ignored=$send($late);check($ignored===['ok'=>true,'eventId'=>'late-suppression','effectId'=>'effect-a','bridgeId'=>'bridge-a','tenantId'=>'tenant-a','ignored'=>true,'state'=>'released'],'released effect cannot be resurrected and ignored event acknowledges current correlated state');
$permanent=$event;$permanent['event_id']=$permanent['webhook_event_id']='permanent';$permanent['data']['policy_effect_id']='effect-permanent';$permanent['data']['action']='permanent';$permanent['data']['expires_at']=null;
$send($permanent);
$other=$event;$other['event_id']=$other['webhook_event_id']='other-hold';$other['data']['policy_effect_id']='effect-other';$send($other);
check($store->effective(10,'user@example.test',$now)['action']==='permanent','overlapping permanent effect wins');
$release['event_id']=$release['webhook_event_id']='release-other';$release['data']['policy_effect_id']='effect-other';$send($release);
check($store->effective(10,'user@example.test',$now)['action']==='permanent','releasing one effect preserves another');
$release['event_id']=$release['webhook_event_id']='release-permanent';$release['data']=$permanent['data'];$send($release);
check($store->effective(10,'user@example.test',$now)===null,'all released effects are inactive');
$expiry=$event;$expiry['event_id']=$expiry['webhook_event_id']='expiry';$expiry['data']['policy_effect_id']='effect-expiry';$send($expiry);
$now+=60001;check($store->effective(10,'user@example.test',$now)===null,'hold expiry is passive, no campaign status mutation');
$reopened=new MagicSmtpPolicyStore(new PDO('sqlite:'.$path));check($reopened->ready(),'existing workspace reopen preserves schema and records');
check((int)$db->query('SELECT COUNT(*) FROM magic_smtp_policy_receipt')->fetchColumn()===8,'rejected and probe callbacks have no receipt effects');
$db->exec("CREATE TRIGGER break_effect BEFORE INSERT ON magic_smtp_policy_effect BEGIN SELECT RAISE(ABORT,'simulated disk failure'); END");
$bad=$event;$bad['event_id']=$bad['webhook_event_id']='rollback';$bad['data']['policy_effect_id']='rollback';
rejects(static function()use($send,$bad){$send($bad);},'effect write failure surfaced');
check((int)$db->query("SELECT COUNT(*) FROM magic_smtp_policy_receipt WHERE event_id='rollback'")->fetchColumn()===0,'receipt and effect roll back atomically');
$db->exec("DELETE FROM magic_smtp_policy_effect WHERE effect_id='effect-a'");
rejects(static function()use($send,$event){$send($event);},'orphan receipt cannot acknowledge an uncommitted or missing effect');
$ambiguous=$binding;$ambiguous['bridge_id']='ambiguous';rejects(static function()use($store,$binding,$ambiguous){new MagicSmtpPolicyBridge($store,[$binding,$ambiguous],static function(){return false;},static function(){return true;});},'ambiguous server binding rejected');
// Shared delivery servers require independently scoped keys and dispatch proof.
$sharedDb=new PDO('sqlite::memory:');$sharedStore=new MagicSmtpPolicyStore($sharedDb);$sharedStore->install();
$bindingB=$binding;$bindingB['bridge_id']='bridge-b';$bindingB['tenant_id']='tenant-b';$bindingB['customer_id']=11;$bindingB['server_ids']=[42];$bindingB['secret']=str_repeat('b',40);
$sharedBindings=[$binding,$bindingB];
$shared=new MagicSmtpPolicyBridge($sharedStore,$sharedBindings,static function(){return null;},static function(){return true;},static function()use(&$now){return $now;});
check($shared->bindingForServer(42,'tenant-b')['customer_id']===11,'shared server selects callback tenant without broadening customer authority');
rejects(static function()use($shared){$shared->bindingForServer(42);},'unscoped shared-server lookup is rejected instead of selecting first tenant');
foreach(['tenant_id','customer_id'] as $field){$bad=$bindingB;$bad[$field]=$binding[$field];rejects(static function()use($sharedStore,$binding,$bad){new MagicSmtpPolicyBridge($sharedStore,[$binding,$bad],static function(){return null;},static function(){return true;});},'shared server rejects repeated '.$field.' binding');}
require_once dirname(__DIR__).'/magicsmtp/MagicSmtpPolicyRuntime.php';
function app_param($key,$default=null){return $key==='magicsmtp.policyBridges'?$GLOBALS['sharedBindings']:$default;}
$property=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bridge');$property->setAccessible(true);$property->setValue(null,$shared);
$fingerprint=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bindingFingerprint');$fingerprint->setAccessible(true);$fingerprint->setValue(null,hash('sha256',json_encode($sharedBindings,JSON_THROW_ON_ERROR)));
$server=(object)['server_id'=>42];
foreach([10=>'a',11=>'b'] as $customer=>$suffix){
 $campaign=(object)['customer_id'=>$customer,'campaign_uid'=>'campaign-'.$suffix];
 MagicSmtpPolicyRuntime::recordDispatch($server,['campaign'=>$campaign,'subscriberUid'=>'subscriber-'.$suffix],'user@example.test','<shared@example.test>');
}
check((int)$sharedDb->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===2,'runtime records both customers on the shared server without early skip');
MagicSmtpPolicyRuntime::recordDispatch($server,['campaign'=>(object)['customer_id'=>12,'campaign_uid'=>'unbound'],'subscriberUid'=>'unbound'],'user@example.test','shared@example.test');
check((int)$sharedDb->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===2,'unbound customer sharing the server remains unaffected');
$sharedEvent=$event;$sharedEvent['timestamp']=$now;$sharedEvent['event_id']=$sharedEvent['webhook_event_id']='same-event';$sharedEvent['data']['policy_effect_id']='same-effect';$sharedEvent['data']['message_id']='shared@example.test';
$sharedSend=static function(array $e,array $b)use($shared){$raw=json_encode($e);return $shared->receive(42,$raw,hash_hmac('sha256',$raw,$b['secret']));};
check($sharedSend($sharedEvent,$binding)['tenantId']==='tenant-a','first shared-server tenant applies its own dispatch');
$eventB=$sharedEvent;$eventB['tenant']='tenant-b';$eventB['data']['bridge_id']='bridge-b';$eventB['data']['campaign_id']='campaign-b';$eventB['data']['subscriber_uid']='subscriber-b';
check($sharedSend($eventB,$bindingB)['tenantId']==='tenant-b','second shared-server tenant uses independent receipt/effect scope');
check((int)$sharedDb->query('SELECT COUNT(*) FROM magic_smtp_policy_effect')->fetchColumn()===2,'identical shared-server event and effect IDs cannot collide across bindings');
try{$sharedSend($eventB,$binding);throw new RuntimeException('Wrong tenant key accepted');}catch(RuntimeException $e){check($e->getCode()===401,'foreign tenant secret cannot authenticate selected shared-server binding');}
$foreign=$sharedEvent;$foreign['event_id']=$foreign['webhook_event_id']='foreign-campaign';$foreign['data']['campaign_id']='campaign-b';$foreign['data']['subscriber_uid']='subscriber-b';$foreign['data']['customer_id']=11;
rejects(static function()use($sharedSend,$foreign,$binding){$sharedSend($foreign,$binding);},'signed customer payload cannot authorize foreign customer campaign on shared server');
$emptyStore=new MagicSmtpPolicyStore(new PDO('sqlite::memory:'));
$beforeDb=new MagicSmtpPolicyBridge($emptyStore,$sharedBindings,static function(){throw new RuntimeException('No correlation before authentication');},static function(){throw new RuntimeException('No scheduler check before authentication');});
try{$beforeDb->receive(42,json_encode($eventB),str_repeat('0',64));throw new RuntimeException('Unsigned event accepted');}catch(RuntimeException $e){check($e->getCode()===401,'shared-server raw signature is verified before any database or readiness access');}
echo "PASS policy bridge acceptance (synthetic only; no network, native blacklist or campaign writes)\n";
