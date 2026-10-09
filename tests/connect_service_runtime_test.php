<?php declare(strict_types=1);
// Independent synthetic acceptance. Real service/store and SQLite; no HTTP/DNS.
define('MW_PATH', dirname(__DIR__));
require_once dirname(__DIR__).'/magicsmtp/models/MagicSmtpConnectService.php';
$checks=0;$cases=[];
function eq($actual,$expected,string $label=''):void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));}
function yes(bool $value,string $label=''):void {eq($value,true,$label);}
function denied(callable $call,string $contains=''):void {global $checks;try{$r=$call();if(is_array($r)&&isset($r['status'])&&$r['status']>=400){$checks++;return;}}catch(Throwable $e){if(!($e instanceof Exception)||($contains!==''&&strpos($e->getMessage(),$contains)===false))throw $e;$checks++;return;}throw new RuntimeException('Expected rejection: '.$contains);}
function scenario(string $name,callable $call):void {global $cases;$cases[]=[$name,$call];}
final class Fixture
{
    public $db;public $store;public $crypto;public $service;public $servers=[];public $static=[];
    public $clock=1791547200;public $ready=true;public $identityCalls=[];public $identityTenant='tenant-a';public $identityFault='';public $identityHook;
    public $base='https://kumo.example.com/ui/api';public $url='https://mail.example.com/dswh/433';public $secret='synthetic-preserved-secret-000000000000000000';public $connection;public $grant;
    public function __construct(bool $existing=false)
    {
        $this->db=new PDO('sqlite::memory:');$this->store=new MagicSmtpConnectStore($this->db);$this->store->install();$this->store->setEnabled(true,1,$this->clock);$this->crypto=new MagicSmtpConnectCrypto(base64_encode(str_repeat('k',32)));
        foreach([433,445,446]as$id)$this->servers[]=['id'=>$id,'customerId'=>1,'type'=>'magic-smtp-web-api','status'=>'active','name'=>'Synthetic '.$id,'apiBaseUrl'=>$this->base,'tlsVerified'=>true,'key'=>'synthetic-key-'.$id];
        $profile=['contract'=>'magic-smtp-policy-scheduler-v1','acceptance'=>'passed'];
        if($existing)$this->static=[['bridge_id'=>'bridge-synthetic','tenant_id'=>'tenant-a','customer_id'=>1,'server_ids'=>[433,445],'secret'=>$this->secret,'enabled'=>true,'scheduler_manifest'=>$profile]];
        $this->service=new MagicSmtpConnectService($this->store,$this->crypto,$this->static,$profile,
            function(int $customer):array{return $this->servers;},
            function(array $server,string $base,string $nonce,int $timeoutMs=8000):array{
                $this->identityCalls[]=['serverId'=>$server['id'],'timeoutMs'=>$timeoutMs];
                if($this->identityHook)($this->identityHook)($server);
                if($this->identityFault==='throw')throw new RuntimeException('synthetic identity unavailable');
                return ['ok'=>true,'nonce'=>$this->identityFault==='nonce'?'wrong-nonce':$nonce,'apiBaseUrl'=>$this->identityFault==='base'?'https://other.example.com/ui/api':$base,'tenantId'=>$this->identityTenant,'tenantName'=>'Synthetic','keyId'=>$this->identityFault==='key'?'':'key-'.$server['id']];
            },function():int{return $this->clock;},function():bool{return $this->ready;});
    }
    public function code():array
    {$result=$this->service->issueGrant(1,1,433,$this->url);$this->grant=json_decode(base64_decode(strtr($result['pairingCode'],'-_','+/')),true,16,JSON_THROW_ON_ERROR);return $this->grant;}
    public function envelope(string $action,array $data=[],?string $operation=null):array
    {return ['event_type'=>'integration.mailwizz','version'=>1,'action'=>$action,'operationId'=>$operation??'operation_'.bin2hex(random_bytes(12)),'nonce'=>'nonce_'.bin2hex(random_bytes(12)),'tenantId'=>'tenant-a','tenantName'=>'Synthetic','apiBaseUrl'=>$this->base,'data'=>$data]+($this->connection?['connectionId'=>$this->connection['connectionId']]:[]);}
    public function call(array $event,?string $signature=null,int $endpoint=433):array
    {$raw=json_encode($event,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);$r=$this->service->handle($endpoint,$raw,$signature??hash_hmac('sha256',$raw,$this->secret));if(isset($r['signature']))eq($r['signature'],hash_hmac('sha256',$r['body'],$this->secret),'response authentication');return $r;}
    public function data(array $r):array
    {eq($r['status'],200,'accepted status');return json_decode($r['body'],true,16,JSON_THROW_ON_ERROR)['data'];}
    public function pairEvent():array
    {$grant=$this->code();return $this->envelope('pair',['token'=>$grant['token'],'secret'=>$this->secret,'bridgeId'=>'bridge-synthetic','webhookId'=>'hook-synthetic']);}
    public function pair():array
    {$event=$this->pairEvent();$this->connection=$this->data($this->call($event));return $event;}
    public function prepare(array $ids=[433,445,446],?string $op=null):array
    {$event=$this->envelope('prepare',['serverIds'=>$ids,'revision'=>$this->connection['revision'],'bridgeId'=>'bridge-synthetic'],$op);$r=$this->call($event);return [$event,$r];}
    public function commit(array $prepare):array
    {$event=$prepare;$event['action']='commit';$event['nonce']='nonce_'.bin2hex(random_bytes(12));return [$event,$this->call($event)];}
    public function status(string $op):array{return $this->data($this->call($this->envelope('status',[],$op)));}
    public function row(int $id,array $changes):void {foreach($this->servers as&$s)if($s['id']===$id)$s=array_merge($s,$changes);}
}

scenario('readiness, enrollment, eligibility and authoritative customer gate code generation',static function():void{
    $f=new Fixture();$f->ready=false;denied(static function()use($f){$f->code();},'scheduler');eq(count($f->identityCalls),0);
    $f->ready=true;$f->store->setEnabled(false,1,$f->clock);denied(static function()use($f){$f->code();},'enable');eq(count($f->identityCalls),0);
    foreach([['customerId'=>2],['customerId'=>0],['type'=>'smtp'],['status'=>'inactive'],['tlsVerified'=>false],['key'=>'']]as$change){$f=new Fixture();$f->row(433,$change);denied(static function()use($f){$f->code();},'owned enabled');eq(count($f->identityCalls),0);}
    $f=new Fixture();denied(static function()use($f){$f->service->issueGrant(2,2,433,$f->url);},'owned enabled');
    foreach(['nonce','base']as$bad){$f=new Fixture();$f->identityFault=$bad;denied(static function()use($f){$f->code();},'sending credential');}
});

scenario('single-use grant is hashed and exact pair replay does not duplicate or rotate secrets',static function():void{
    $f=new Fixture(true);$event=$f->pairEvent();yes(preg_match('/^mwp1_[A-Za-z0-9_-]{43}$/D',$f->grant['token'])===1);
    $stored=$f->store->grant(hash('sha256',$f->grant['token']));eq((int)$stored['expires_at'],$f->clock+600);yes(strpos(json_encode($stored),$f->grant['token'])===false);
    $r=$f->call($event);$data=$f->data($r);eq($data['serverIds'],[433,445]);eq($f->call($event),$r);eq(count($f->store->connections()),1);
    $row=$f->store->connection($data['connectionId']);yes(strpos($row['secret_box'],$f->secret)===false);eq($f->crypto->open($row['secret_box'],$row['connection_id']),$f->secret);
    $changed=$event;$changed['nonce']='nonce_changed_0123456789';denied(static function()use($f,$changed){return $f->call($changed);},'content changed');
    $changed=$event;$changed['operationId']='operation_other_0123456789';denied(static function()use($f,$changed){return $f->call($changed);},'expired, used');
    eq($f->service->effectiveBindings(),$f->static);
});

scenario('pair token cannot cross endpoint tenant API base expiry or changed stored credentials',static function():void{
    foreach(['endpoint','tenant','base','expired','key','customer','secret']as$bad){
        $f=new Fixture($bad==='secret');$event=$f->pairEvent();$endpoint=433;
        if($bad==='endpoint')$endpoint=445;elseif($bad==='tenant')$event['tenantId']='tenant-b';elseif($bad==='base')$event['apiBaseUrl']='https://other.example.com/ui/api';elseif($bad==='expired')$f->clock+=601;elseif($bad==='key')$f->row(433,['key'=>'different']);elseif($bad==='customer')$f->row(433,['customerId'=>2]);elseif($bad==='secret')$event['data']['secret']=str_repeat('z',40);
        denied(static function()use($f,$event,$endpoint){return $f->call($event,null,$endpoint);});eq(count($f->store->connections()),0);
    }
});

scenario('all post-pair actions require signature and exact connection tenant endpoint and API identity',static function():void{
    $f=new Fixture();$f->pair();
    foreach(['discover','prepare','commit','status']as$action){$event=$f->envelope($action,['serverIds'=>[433],'revision'=>0,'bridgeId'=>'bridge-synthetic']);denied(static function()use($f,$event){return $f->call($event,str_repeat('0',64));},'signature');
        foreach(['tenantId'=>'tenant-b','apiBaseUrl'=>'https://other.example.com/ui/api','connectionId'=>'mwc_unknown']as$field=>$value){$altered=$event;$altered[$field]=$value;denied(static function()use($f,$altered){return $f->call($altered);},'scope');}
        denied(static function()use($f,$event){return $f->call($event,null,445);},'scope');
    }
    eq($f->service->effectiveBindings(),[]);
});

scenario('new scopes reject shared foreign SMTP disabled and unmatched-tenant servers',static function():void{
    foreach([['customerId'=>0],['customerId'=>2],['type'=>'smtp'],['status'=>'inactive'],['tlsVerified'=>false],['apiBaseUrl'=>'https://other.example.com/ui/api']]as$change){$f=new Fixture();$f->pair();$f->row(445,$change);[, $r]=$f->prepare([433,445]);yes($r['status']>=400);eq($f->service->effectiveBindings(),[]);}
    foreach(['tenant','nonce','base','key']as$bad){$f=new Fixture();$f->pair();if($bad==='tenant')$f->identityTenant='tenant-b';else $f->identityFault=$bad;[, $r]=$f->prepare([433]);yes($r['status']>=400);eq($f->service->effectiveBindings(),[]);}
});

scenario('legacy shared callback can be adopted but new shared server cannot be added',static function():void{
    $f=new Fixture(true);$f->row(433,['customerId'=>0]);$f->pair();eq($f->service->effectiveBindings(),$f->static);
    $f->row(446,['customerId'=>0]);[, $r]=$f->prepare();yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);
    $f->row(446,['customerId'=>1]);[$prepare,$r]=$f->prepare();$f->data($r);[, $r]=$f->commit($prepare);$f->data($r);
    $b=$f->service->effectiveBindings()[0];eq($b['server_ids'],[433,445,446]);eq($b['secret'],$f->secret);eq($b['bridge_id'],'bridge-synthetic');eq($b['scheduler_manifest'],$f->static[0]['scheduler_manifest']);
});

scenario('prepare and commit remain add-only and enforce exact revision scope and current credentials',static function():void{
    $f=new Fixture(true);$f->pair();foreach([[433],[445],[],[433,445,445],['433',445],range(1,101)]as$ids){[, $r]=$f->prepare($ids);yes($r['status']>=400);}
    $event=$f->envelope('prepare',['serverIds'=>[433,445,446],'revision'=>99,'bridgeId'=>'bridge-synthetic']);denied(static function()use($f,$event){return $f->call($event);});
    [$prepare,$r]=$f->prepare();$f->data($r);$f->row(446,['key'=>'new-credential']);[, $r]=$f->commit($prepare);yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);
    $f=new Fixture(true);$f->pair();[$prepare,$r]=$f->prepare();$f->data($r);$altered=$prepare;$altered['data']['serverIds']=[433,445];[, $r]=$f->commit($altered);yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);
    $f=new Fixture(true);$f->pair();[$prepare,$r]=$f->prepare();$f->data($r);$f->ready=false;[, $r]=$f->commit($prepare);yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);
});

scenario('status reconciles failed expired missing and committed operations without guessing current scope',static function():void{
    $f=new Fixture(true);$f->pair();$unknown=$f->status('operation_unknown_0123456789');eq($unknown['state'],'not_found');
    [$prepare,$r]=$f->prepare();$f->data($r);$f->clock+=61;$expired=$f->status($prepare['operationId']);eq($expired['state'],'not_committed');eq($expired['retryable'],false);[, $r]=$f->commit($prepare);yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);
    $f=new Fixture(true);$f->pair();$f->identityFault='throw';[$prepare,$r]=$f->prepare();yes($r['status']>=400);eq($f->status($prepare['operationId'])['state'],'not_committed');
    $f=new Fixture(true);$f->pair();[$prepare,$r]=$f->prepare();$f->data($r);[$commit,$r]=$f->commit($prepare);$committed=$f->data($r);eq($committed['revision'],1);eq($f->call($commit),$r);$f->clock+=600;
    eq($f->status($prepare['operationId'])['state'],'committed');eq($f->status($prepare['operationId'])['result'],$committed);eq($f->status('operation_unknown_9876543210')['state'],'not_found');
    $changed=$commit;$changed['data']['serverIds']=[433,445];denied(static function()use($f,$changed){return $f->call($changed);});eq($f->store->connection($committed['connectionId'])['revision'],1);
});

scenario('unreceived pair status is signed and terminal expired proof permits safe new attempt',static function():void{
    $f=new Fixture();$event=$f->pairEvent();$event['action']='status';$r=$f->data($f->call($event));eq($r['state'],'not_found');eq($r['retryable'],true);
    $f->clock+=601;$r=$f->data($f->call($event));eq($r['state'],'not_found');eq($r['retryable'],false);eq(count($f->store->connections()),0);
    $event['tenantId']='tenant-b';denied(static function()use($f,$event){return $f->call($event);},'scope');
});

scenario('shared key is proved once per preparation with bounded remaining timeout and no transaction',static function():void{
    $f=new Fixture();$f->pair();foreach([433,445,446]as$id)$f->row($id,['key'=>'shared-key']);$before=count($f->identityCalls);
    $f->identityHook=static function()use($f):void{eq($f->db->inTransaction(),false,'no identity inside write transaction');$f->store->atomic(static function():void{});};
    [$prepare,$r]=$f->prepare();$f->data($r);eq(count($f->identityCalls)-$before,1);$budget=end($f->identityCalls)['timeoutMs'];yes($budget>0&&$budget<=5000);
    [, $r]=$f->commit($prepare);$f->data($r);eq(count($f->identityCalls)-$before,1,'commit must not perform unbounded network proof');
});

scenario('disable enrollment preserves active bindings and signed investigation status',static function():void{
    $f=new Fixture(true);$f->pair();[$p,$prepared]=$f->prepare();$f->data($prepared);[$commit,$committed]=$f->commit($p);$f->data($committed);$before=$f->service->effectiveBindings();
    $f->store->setEnabled(false,1,$f->clock);eq($f->service->effectiveBindings(),$before);eq($f->status($p['operationId'])['state'],'committed');$f->data($f->call($f->envelope('discover')));
    eq($f->call($p),$prepared,'completed prepare replay survives enrollment disable');eq($f->call($commit),$committed,'completed commit replay survives enrollment disable');
    [, $r]=$f->prepare();yes($r['status']>=400);eq($f->service->effectiveBindings(),$before);
    $f=new Fixture(true);$f->pair();[$p,$r]=$f->prepare();$f->data($r);$f->store->setEnabled(false,1,$f->clock);[, $r]=$f->commit($p);yes($r['status']>=400);eq($f->service->effectiveBindings(),$f->static);eq($f->status($p['operationId'])['state'],'prepared');
});

scenario('total connection limit rejects new enrollment before it can poison existing bindings',static function():void{
    $f=new Fixture();$event=$f->pairEvent();
    for($i=0;$i<100;$i++)$f->store->query('INSERT INTO '.$f->store->table('connection').' (connection_id,customer_id,endpoint_id,api_base,tenant_id,tenant_name,bridge_id,webhook_id,secret_box,static_hash,server_ids,revision,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[
        'mwc_existing_'.$i,$i+10,1000+$i,$f->base,'tenant-'.$i,'Synthetic','bridge-'.$i,'hook-'.$i,$f->crypto->seal($f->secret,'mwc_existing_'.$i),'','[]',0,'paired',$f->clock,$f->clock]);
    denied(static function()use($f,$event){return $f->call($event);},'capacity');eq(count($f->store->connections()),100);eq($f->service->effectiveBindings(),[]);
});

scenario('encryption authenticates context tampering and store rejects unsafe identifiers',static function():void{
    $f=new Fixture();$box=$f->crypto->seal($f->secret,'context-a');eq($f->crypto->open($box,'context-a'),$f->secret);denied(static function()use($f,$box){$f->crypto->open($box,'context-b');},'secret unavailable');
    $raw=base64_decode(substr($box,3));$raw[28]=chr(ord($raw[28])^1);denied(static function()use($f,$raw){$f->crypto->open('v1.'.base64_encode($raw),'context-a');},'secret unavailable');
    denied(static function()use($f){new MagicSmtpConnectStore($f->db,'bad;prefix');},'prefix');denied(static function()use($f){$f->store->table('unknown');},'table');
});

$failed=[];foreach($cases as[$name,$call]){try{$call();echo 'PASS '.$name."\n";}catch(Throwable $e){$failed[]=['name'=>$name,'error'=>$e->getMessage()];echo 'FAIL '.$name.': '.$e->getMessage()."\n";}}
echo json_encode(['ok'=>!$failed,'scenarios'=>count($cases),'checks'=>$checks,'failures'=>$failed,'externalRequests'=>0],JSON_UNESCAPED_SLASHES)."\n";exit($failed?1:0);
