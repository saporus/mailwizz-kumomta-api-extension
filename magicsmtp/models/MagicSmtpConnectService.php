<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');
require_once __DIR__.'/MagicSmtpConnectStore.php';

/** Pairing is an explicit management protocol; sending credentials never grant management. */
final class MagicSmtpConnectService
{
    private $store;private $crypto;private $static;private $profile;private $servers;private $identity;private $clock;private $schedulerReady;
    public function __construct(MagicSmtpConnectStore $store,MagicSmtpConnectCrypto $crypto,array $static,array $profile,callable $servers,callable $identity,?callable $clock=null,?callable $schedulerReady=null)
    {$this->store=$store;$this->crypto=$crypto;$this->static=$static;$this->profile=$profile;$this->servers=$servers;$this->identity=$identity;$this->clock=$clock??static function():int{return time();};$this->schedulerReady=$schedulerReady??static function():bool{return false;};}
    private function ready():void{if(($this->profile['contract']??'')!=='magic-smtp-policy-scheduler-v1'||($this->profile['acceptance']??'')!=='passed'||!($this->schedulerReady)())throw new RuntimeException('Installed scheduler compatibility is not verified',409);}
    private function now():int{return ($this->clock)();}
    private function field(array $data,string $name,int $max=128):string
    {$v=$data[$name]??null;if(!is_string($v)||$v===''||strlen($v)>$max||preg_match('/[\x00-\x1f\x7f]/',$v))throw new InvalidArgumentException('Invalid connection request',422);return $v;}
    private function ids($value):array
    {if(!is_array($value)||!$value||count($value)>100)throw new InvalidArgumentException('Select between one and one hundred servers',422);$out=[];foreach($value as$id){if(!is_int($id)||$id<1||isset($out[$id]))throw new InvalidArgumentException('Invalid server selection',422);$out[$id]=$id;}return array_values($out);}
    private function staticBinding(int $customer,?string $bridge=null):?array
    {$found=[];foreach($this->static as$b)if((int)$b['customer_id']===$customer&&($bridge===null||$b['bridge_id']===$bridge))$found[]=$b;if(count($found)>1)throw new RuntimeException('Administrator must resolve multiple existing bridges',409);return $found[0]??null;}
    public static function staticHash(array $binding):string
    {return hash('sha256',json_encode([$binding['bridge_id'],$binding['tenant_id'],$binding['customer_id'],$binding['server_ids'],$binding['enabled']??false,hash('sha256',$binding['secret']),$binding['scheduler_manifest']??null],JSON_THROW_ON_ERROR));}
    public static function fingerprint(array $server):string
    {return hash('sha256',json_encode([(int)$server['id'],(int)$server['customerId'],$server['type'],$server['status'],$server['apiBaseUrl'],!empty($server['tlsVerified']),hash('sha256',(string)$server['key'])],JSON_THROW_ON_ERROR));}
    private function candidates(int $customer):array
    {$rows=($this->servers)($customer);if(!is_array($rows)||count($rows)>100)throw new RuntimeException('Server inventory is unavailable',503);return $rows;}
    private function server(int $customer,int $id):array
    {foreach($this->candidates($customer)as$s)if((int)$s['id']===$id)return $s;throw new RuntimeException('Server is not authorized for this customer',403);}
    private function eligible(array $server,int $customer,string $base):bool
    {return (int)$server['customerId']===$customer&&$server['type']==='magic-smtp-web-api'&&in_array($server['status'],['active','in-use'],true)&&$server['apiBaseUrl']===$base&&!empty($server['tlsVerified'])&&!empty($server['key']);}
    private function proof(array $server,string $tenant,string $base,int $timeoutMs):array
    {
        $nonce=bin2hex(random_bytes(16));$proof=($this->identity)($server,$base,$nonce,$timeoutMs);
        if(!is_array($proof)||($proof['ok']??null)!==true||($proof['nonce']??null)!==$nonce||($proof['apiBaseUrl']??null)!==$base||($proof['tenantId']??null)!==$tenant||!is_string($proof['keyId']??null)||$proof['keyId']==='')throw new RuntimeException('Server credential belongs to a different or unavailable tenant',409);
        return $proof;
    }
    /** Customer identity is supplied by the authenticated native controller, never a request field. */
    public function issueGrant(int $customer,int $actor,int $endpoint,string $callbackUrl):array
    {
        if(!$this->store->enabled())throw new RuntimeException('An administrator must enable connections first',403);
        $this->ready();
        $server=$this->server($customer,$endpoint);$base=$server['apiBaseUrl'];$static=$this->staticBinding($customer);
        $legacy=$static&&($static['enabled']??null)===true&&in_array($endpoint,$static['server_ids'],true);
        if(!$this->eligible($server,$customer,$base)&&!($legacy&&$server['type']==='magic-smtp-web-api'&&in_array($server['status'],['active','in-use'],true)&&!empty($server['tlsVerified'])))throw new RuntimeException('Choose an owned enabled Magic SMTP Web API server',403);
        if(!preg_match('~^https://[^/?#@]+/[^?#]*dswh/'.$endpoint.'$~D',$callbackUrl))throw new RuntimeException('A secure configured callback URL is required',409);
        $nonce=bin2hex(random_bytes(16));$proof=($this->identity)($server,$base,$nonce);
        if(($proof['ok']??null)!==true||($proof['nonce']??null)!==$nonce||($proof['apiBaseUrl']??null)!==$base||!is_string($proof['tenantId']??null)||$proof['tenantId']===''||!is_string($proof['keyId']??null))throw new RuntimeException('Could not verify the sending credential',409);
        if($static&&($static['tenant_id']!==$proof['tenantId']||!$legacy))throw new RuntimeException('Use the existing authorized callback server to connect this customer',409);
        $now=$this->now();$fingerprint=self::fingerprint($server);$token='mwp1_'.rtrim(strtr(base64_encode(random_bytes(32)),'+/','-_'),'=');
        $this->store->atomic(function()use($customer,$actor,$endpoint,$base,$proof,$static,$fingerprint,$token,$now):void{
            if(!$this->store->enabled()||!hash_equals($fingerprint,self::fingerprint($this->server($customer,$endpoint))))throw new RuntimeException('Server settings changed; try again',409);
            $count=(int)$this->store->query('SELECT COUNT(*) FROM '.$this->store->table('grant').' WHERE customer_id=? AND created_at>?',[$customer,$now-3600])->fetchColumn();if($count>=10)throw new RuntimeException('Too many pairing codes; try again later',429);
            $this->store->query('INSERT INTO '.$this->store->table('grant').' (token_hash,customer_id,endpoint_id,api_base,tenant_id,static_bridge_id,server_fingerprint,created_at,expires_at,claimed_operation) VALUES(?,?,?,?,?,?,?,?,?,NULL)',[hash('sha256',$token),$customer,$endpoint,$base,$proof['tenantId'],$static['bridge_id']??'',$fingerprint,$now,$now+600]);
            $this->store->audit($customer,$actor,'issue_pairing_code','',['endpointId'=>$endpoint],$now);
        });
        return ['pairingCode'=>rtrim(strtr(base64_encode(json_encode(['v'=>1,'url'=>$callbackUrl,'token'=>$token],JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)),'+/','-_'),'='),'expiresAt'=>$now+600,'tenantName'=>(string)($proof['tenantName']??'MagicSMTP')];
    }
    private function secret(array $connection):string{return $this->crypto->open($connection['secret_box'],$connection['connection_id']);}
    private function sign(array $body,string $secret,int $status=200):array
    {$raw=json_encode($body,JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR);return ['status'=>$status,'body'=>$raw,'signature'=>hash_hmac('sha256',$raw,$secret)];}
    private function response(array $event,array $data,string $secret,int $status=200):array
    {return $this->sign(['ok'=>$status>=200&&$status<300,'nonce'=>$event['nonce'],'operationId'=>$event['operationId'],'action'=>$event['action'],'data'=>$data],$secret,$status);}
    private function verify(string $raw,string $signature,string $secret):void
    {if(!preg_match('/^[a-fA-F0-9]{64}$/D',$signature)||!hash_equals(hash_hmac('sha256',$raw,$secret),strtolower($signature)))throw new RuntimeException('Invalid integration signature',401);}
    private function checkedStatic(array $connection):?array
    {
        $b=$this->staticBinding((int)$connection['customer_id'],$connection['bridge_id']);
        if($connection['static_hash']!==''){
            if(!$b||!hash_equals($connection['static_hash'],self::staticHash($b))||!hash_equals($b['secret'],$this->secret($connection)))throw new RuntimeException('Existing binding changed; administrator review required',409);
        }elseif($b)throw new RuntimeException('A protected binding now owns this connection',409);
        return $b;
    }
    private function serverList(array $connection):array
    {
        $active=json_decode($connection['server_ids'],true,16,JSON_THROW_ON_ERROR);$out=[];
        foreach($this->candidates((int)$connection['customer_id'])as$s){
            $connected=in_array((int)$s['id'],$active,true);$eligible=$this->eligible($s,(int)$connection['customer_id'],$connection['api_base']);
            if(!$connected&&(int)$s['customerId']!==(int)$connection['customer_id'])continue;
            $row=['id'=>(int)$s['id'],'name'=>(string)$s['name'],'status'=>(string)$s['status'],'connected'=>$connected,'eligible'=>$eligible];
            if(!$eligible)$row['reason']=$connected?'Existing administrator-authorized server':'Requires an owned enabled Magic SMTP Web API server with verified TLS and matching API URL';
            $out[]=$row;
        }
        return $out;
    }
    private function data(array $connection,bool $withServers=true):array
    {
        $out=['connectionId'=>$connection['connection_id'],'customerId'=>(int)$connection['customer_id'],'revision'=>(int)$connection['revision'],'serverIds'=>json_decode($connection['server_ids'],true,16,JSON_THROW_ON_ERROR),'bridgeId'=>$connection['bridge_id']];
        if($withServers)$out['servers']=$this->serverList($connection);return $out;
    }
    private function replay(array $operation,string $raw,string $secret):array
    {
        if(!hash_equals($operation['request_hash'],hash('sha256',$raw)))throw new RuntimeException('Operation content changed',409);
        if($operation['state']==='in_progress')throw new RuntimeException('Operation is still being reconciled',409);
        if(!is_string($operation['response_body']))throw new RuntimeException('Operation result unavailable',503);
        return ['status'=>(int)$operation['response_status'],'body'=>$operation['response_body'],'signature'=>hash_hmac('sha256',$operation['response_body'],$secret)];
    }
    private function saveResult(array $event,string $raw,array $connection,array $data):array
    {
        $result=$this->response($event,$data,$this->secret($connection));
        $this->store->query('UPDATE '.$this->store->table('operation').' SET state=?,response_body=?,response_status=?,updated_at=? WHERE operation_id=? AND action=? AND request_hash=?',['complete',$result['body'],$result['status'],$this->now(),$event['operationId'],$event['action'],hash('sha256',$raw)]);
        return $result;
    }
    public function handle(int $endpoint,string $raw,string $signature):array
    {
        if(strlen($raw)>32768)throw new InvalidArgumentException('Integration request too large',413);
        $e=json_decode($raw,true,16,JSON_THROW_ON_ERROR);
        if(!is_array($e)||($e['event_type']??null)!=='integration.mailwizz'||($e['version']??null)!==1||!is_array($e['data']??null))throw new InvalidArgumentException('Invalid integration envelope',422);
        foreach(['action','operationId','tenantId','apiBaseUrl','nonce']as$field)$this->field($e,$field,$field==='apiBaseUrl'?255:128);
        if(!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$e['operationId'])||!preg_match('/^[A-Za-z0-9_-]{16,128}$/D',$e['nonce']))throw new InvalidArgumentException('Invalid operation identity',422);
        if($e['action']==='pair')return $this->pair($endpoint,$e,$raw);
        if($e['action']==='status'&&empty($e['connectionId']))return $this->pairStatus($endpoint,$e,$raw,$signature);
        $connection=$this->store->connection($this->field($e,'connectionId',64));
        if(!$connection||(int)$connection['endpoint_id']!==$endpoint||$connection['tenant_id']!==$e['tenantId']||$connection['api_base']!==$e['apiBaseUrl'])throw new RuntimeException('Connection scope rejected',403);
        $secret=$this->secret($connection);$this->verify($raw,$signature,$secret);$this->checkedStatic($connection);
        try{
            if($e['action']==='discover')return $this->response($e,$this->data($connection),$secret);
            if($e['action']==='status')return $this->status($e,$connection);
            if(!in_array($e['action'],['prepare','commit'],true))throw new InvalidArgumentException('Unsupported integration action',422);
            return $e['action']==='prepare'?$this->prepare($e,$raw,$connection):$this->commit($e,$raw,$connection);
        }catch(Throwable $failure){$code=in_array((int)$failure->getCode(),[403,409,422,429],true)?(int)$failure->getCode():503;return $this->response($e,['reason'=>'connection_validation_failed'],$secret,$code);}
    }
    private function pair(int $endpoint,array $e,string $raw):array
    {
        $token=$this->field($e['data'],'token',64);$secret=$this->field($e['data'],'secret',256);$bridge=$this->field($e['data'],'bridgeId');$webhook=$this->field($e['data'],'webhookId');
        if(!preg_match('/^mwp1_[A-Za-z0-9_-]{43}$/D',$token)||strlen($secret)<32)throw new RuntimeException('Pairing code rejected',403);
        return $this->store->atomic(function()use($endpoint,$e,$raw,$token,$secret,$bridge,$webhook):array{
            $old=$this->store->operation($e['operationId'],'pair');
            if($old){$c=$this->store->connection($old['connection_id']);if(!$c||(int)$c['endpoint_id']!==$endpoint||$c['tenant_id']!==$e['tenantId']||$c['api_base']!==$e['apiBaseUrl']||!hash_equals($this->secret($c),$secret))throw new RuntimeException('Pairing scope rejected',403);return $this->replay($old,$raw,$secret);}
            if(!$this->store->enabled())throw new RuntimeException('Connection enrollment is disabled',403);
            $this->ready();
            $grant=$this->store->grant(hash('sha256',$token));$now=$this->now();
            if(!$grant||$grant['claimed_operation']!==null||(int)$grant['expires_at']<=$now||(int)$grant['endpoint_id']!==$endpoint||$grant['tenant_id']!==$e['tenantId']||$grant['api_base']!==$e['apiBaseUrl'])throw new RuntimeException('Pairing code is expired, used or does not match this tenant',403);
            $server=$this->server((int)$grant['customer_id'],$endpoint);if(!hash_equals($grant['server_fingerprint'],self::fingerprint($server)))throw new RuntimeException('Server settings changed; create a new pairing code',409);
            $static=$this->staticBinding((int)$grant['customer_id']);
            if($static&&($grant['static_bridge_id']!==$static['bridge_id']||$bridge!==$static['bridge_id']||$e['tenantId']!==$static['tenant_id']||!hash_equals($static['secret'],$secret)))throw new RuntimeException('Existing protected binding must be preserved',409);
            if(!$static&&$grant['static_bridge_id']!=='')throw new RuntimeException('Protected binding changed',409);
            foreach($this->store->connections((int)$grant['customer_id'])as$c)if($c['state']!=='failed')throw new RuntimeException('This customer already has a managed connection; use its existing connection',409);
            if(count($this->store->connections())>=100)throw new RuntimeException('Installation connection capacity requires administrator review',409);
            $id='mwc_'.bin2hex(random_bytes(16));$ids=$static?$static['server_ids']:[];$hash=$static?self::staticHash($static):'';
            $this->store->query('INSERT INTO '.$this->store->table('connection').' (connection_id,customer_id,endpoint_id,api_base,tenant_id,tenant_name,bridge_id,webhook_id,secret_box,static_hash,server_ids,revision,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',[$id,(int)$grant['customer_id'],$endpoint,$grant['api_base'],$e['tenantId'],isset($e['tenantName'])?$this->field($e,'tenantName',255):'MagicSMTP',$bridge,$webhook,$this->crypto->seal($secret,$id),$hash,json_encode($ids),0,'paired',$now,$now]);
            $this->store->query('UPDATE '.$this->store->table('grant').' SET claimed_operation=? WHERE token_hash=?',[$e['operationId'],hash('sha256',$token)]);
            $this->store->query('INSERT INTO '.$this->store->table('operation').' (operation_id,action,connection_id,request_hash,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$e['operationId'],'pair',$id,hash('sha256',$raw),'in_progress',$now,$now]);
            $this->store->audit((int)$grant['customer_id'],0,'pair',$id,['existingStatic'=>$static!==null],$now);$c=$this->store->connection($id);return $this->saveResult($e,$raw,$c,$this->data($c));
        });
    }
    private function pairStatus(int $endpoint,array $e,string $raw,string $signature):array
    {
        $op=$this->store->operation($e['operationId'],'pair');
        if($op){$c=$this->store->connection($op['connection_id']);if(!$c||(int)$c['endpoint_id']!==$endpoint||$c['tenant_id']!==$e['tenantId']||$c['api_base']!==$e['apiBaseUrl'])throw new RuntimeException('Connection scope rejected',403);$this->verify($raw,$signature,$this->secret($c));return $this->status($e,$c);}
        $secret=$this->field($e['data'],'secret',256);$token=$this->field($e['data'],'token',64);if(strlen($secret)<32||!preg_match('/^mwp1_[A-Za-z0-9_-]{43}$/D',$token))throw new RuntimeException('Pairing reconciliation rejected',403);$this->verify($raw,$signature,$secret);
        foreach($this->store->connections()as$c)if((int)$c['endpoint_id']===$endpoint&&$c['tenant_id']===$e['tenantId']){if($c['api_base']!==$e['apiBaseUrl']||!hash_equals($secret,$this->secret($c)))throw new RuntimeException('Existing connection scope rejected',403);return $this->response($e,['reason'=>'existing_connection_requires_reconciliation','connectionId'=>$c['connection_id']],$secret,409);}
        $grant=$this->store->grant(hash('sha256',$token));
        if($grant&&((int)$grant['endpoint_id']!==$endpoint||$grant['tenant_id']!==$e['tenantId']||$grant['api_base']!==$e['apiBaseUrl']||$grant['claimed_operation']!==null))throw new RuntimeException('Pairing grant scope rejected',403);
        $retry=$grant&&(int)$grant['expires_at']>$this->now();return $this->response($e,['state'=>'not_found','retryable'=>$retry,'reason'=>$retry?'pairing_not_received':($grant?'pairing_expired':'pairing_invalid')],$secret);
    }
    private function status(array $e,array $c):array
    {
        return $this->store->atomic(function()use($e,$c):array{
            $commit=$this->store->operation($e['operationId'],'commit');$prepare=$this->store->operation($e['operationId'],'prepare');$pair=$this->store->operation($e['operationId'],'pair');
            foreach([$commit,$prepare,$pair]as$op)if($op&&$op['connection_id']!==$c['connection_id'])throw new RuntimeException('Operation scope rejected',403);
            if($commit&&$commit['state']==='complete')return $this->response($e,['state'=>'committed','result'=>json_decode($commit['response_body'],true,16,JSON_THROW_ON_ERROR)['data']],$this->secret($c));
            if($prepare){
                $candidate=$prepare['candidate']?json_decode($prepare['candidate'],true,16,JSON_THROW_ON_ERROR):[];
                $expired=($prepare['state']==='complete'&&(int)($candidate['expiresAt']??0)<$this->now())||($prepare['state']==='in_progress'&&(int)$prepare['created_at']+30<$this->now());
                if($prepare['state']==='failed'||$expired)return $this->response($e,['state'=>'not_committed','retryable'=>false,'reason'=>$expired?'prepare_expired':'prepare_failed'],$this->secret($c));
                if($prepare['state']==='complete')return $this->response($e,['state'=>'prepared','result'=>json_decode($prepare['response_body'],true,16,JSON_THROW_ON_ERROR)['data']],$this->secret($c));
                return $this->response($e,['state'=>'in_progress','retryable'=>false],$this->secret($c));
            }
            if($pair&&$pair['state']==='complete')return $this->response($e,['state'=>'paired','result'=>json_decode($pair['response_body'],true,16,JSON_THROW_ON_ERROR)['data']],$this->secret($c));
            return $this->response($e,['state'=>'not_found','retryable'=>false,'reason'=>'operation_not_found'],$this->secret($c));
        });
    }
    private function prepare(array $e,string $raw,array $connection):array
    {
        $ids=$this->ids($e['data']['serverIds']??null);$revision=$e['data']['revision']??null;if(!is_int($revision)||$this->field($e['data'],'bridgeId')!==$connection['bridge_id'])throw new RuntimeException('Binding revision rejected',409);
        $claim=$this->store->atomic(function()use($e,$raw,$connection,$ids,$revision):array{
            $old=$this->store->operation($e['operationId'],'prepare');if($old)return ['replay'=>$this->replay($old,$raw,$this->secret($connection))];
            if(!$this->store->enabled())throw new RuntimeException('Connection enrollment is disabled',403);
            $c=$this->store->connection($connection['connection_id']);$this->checkedStatic($c);$active=json_decode($c['server_ids'],true,16,JSON_THROW_ON_ERROR);
            $this->ready();$this->candidates((int)$c['customer_id']);
            if((int)$c['revision']!==$revision||array_diff($active,$ids)||!in_array((int)$c['endpoint_id'],$ids,true))throw new RuntimeException('Only additions to the current binding are allowed',409);
            $now=$this->now();$this->store->query('INSERT INTO '.$this->store->table('operation').' (operation_id,action,connection_id,request_hash,state,candidate,created_at,updated_at) VALUES(?,?,?,?,?,?,?,?)',[$e['operationId'],'prepare',$c['connection_id'],hash('sha256',$raw),'in_progress',json_encode(['serverIds'=>$ids,'revision'=>$revision]),$now,$now]);return ['connection'=>$c,'added'=>array_values(array_diff($ids,$active))];
        });
        if(isset($claim['replay']))return $claim['replay'];$c=$claim['connection'];$proofs=[];$verifiedKeys=[];$started=microtime(true);
        try{
            foreach($claim['added']as$id){$remaining=5000-(int)ceil((microtime(true)-$started)*1000);if($remaining<1)throw new RuntimeException('Verification budget exhausted; select fewer servers',409);$s=$this->server((int)$c['customer_id'],$id);if(!$this->eligible($s,(int)$c['customer_id'],$c['api_base']))throw new RuntimeException('New server is not eligible for this customer',403);$keyHash=hash('sha256',$s['key']);if(!isset($verifiedKeys[$keyHash])){$this->proof($s,$c['tenant_id'],$c['api_base'],$remaining);$verifiedKeys[$keyHash]=true;}$proofs[(string)$id]=self::fingerprint($s);}
            return $this->store->atomic(function()use($e,$raw,$c,$ids,$revision,$proofs):array{
                $fresh=$this->store->connection($c['connection_id']);$this->checkedStatic($fresh);if((int)$fresh['revision']!==$revision)throw new RuntimeException('Binding revision changed',409);
                $pending=$this->store->operation($e['operationId'],'prepare');if(!$pending||$pending['state']!=='in_progress'||(int)$pending['created_at']+30<$this->now())throw new RuntimeException('Preparation claim expired',409);
                foreach($proofs as$id=>$hash)if(!hash_equals($hash,self::fingerprint($this->server((int)$c['customer_id'],(int)$id))))throw new RuntimeException('Server settings changed',409);
                $candidate=['serverIds'=>$ids,'revision'=>$revision,'proofs'=>$proofs,'expiresAt'=>$this->now()+60];$this->store->query('UPDATE '.$this->store->table('operation').' SET candidate=? WHERE operation_id=? AND action=?',[json_encode($candidate),$e['operationId'],'prepare']);
                $data=$this->data($fresh,false);$data['state']='prepared';$data['serverIds']=$ids;$this->store->audit((int)$c['customer_id'],0,'prepare',$c['connection_id'],['serverIds'=>$ids],$this->now());return $this->saveResult($e,$raw,$fresh,$data);
            });
        }catch(Throwable $failure){$this->finishFailure($e,$raw,$c);throw $failure;}
    }
    private function finishFailure(array $e,string $raw,array $connection):void
    {
        $result=$this->response($e,['state'=>'failed','reason'=>'connection_validation_failed'],$this->secret($connection),409);
        $this->store->atomic(function()use($e,$raw,$result):void{$this->store->query('UPDATE '.$this->store->table('operation').' SET state=?,response_body=?,response_status=?,updated_at=? WHERE operation_id=? AND action=? AND request_hash=? AND state=?',['failed',$result['body'],409,$this->now(),$e['operationId'],$e['action'],hash('sha256',$raw),'in_progress']);});
    }
    private function commit(array $e,string $raw,array $connection):array
    {
        return $this->store->atomic(function()use($e,$raw,$connection):array{
            $old=$this->store->operation($e['operationId'],'commit');if($old)return $this->replay($old,$raw,$this->secret($connection));
            if(!$this->store->enabled())throw new RuntimeException('Connection enrollment is disabled',403);
            $this->ready();$this->candidates((int)$connection['customer_id']);
            $prepared=$this->store->operation($e['operationId'],'prepare');if(!$prepared||$prepared['connection_id']!==$connection['connection_id']||$prepared['state']!=='complete')throw new RuntimeException('Prepare this operation first',409);
            $candidate=json_decode($prepared['candidate'],true,16,JSON_THROW_ON_ERROR);$c=$this->store->connection($connection['connection_id']);$this->checkedStatic($c);
            if((int)$candidate['expiresAt']<$this->now()||(int)$c['revision']!==(int)$candidate['revision']||($e['data']['revision']??null)!==(int)$c['revision']||($e['data']['bridgeId']??null)!==$c['bridge_id']||$this->ids($e['data']['serverIds']??null)!==$candidate['serverIds'])throw new RuntimeException('Prepared binding expired or changed; prepare a new operation',409);
            foreach($candidate['proofs']as$id=>$hash){$s=$this->server((int)$c['customer_id'],(int)$id);if(!$this->eligible($s,(int)$c['customer_id'],$c['api_base'])||!hash_equals($hash,self::fingerprint($s)))throw new RuntimeException('Server settings changed after verification',409);}
            // All identity proofs are <=60 seconds old and exact credentials/ownership are rechecked above.
            $ids=$candidate['serverIds'];$active=json_decode($c['server_ids'],true,16,JSON_THROW_ON_ERROR);if(array_diff($active,$ids))throw new RuntimeException('Existing server scope cannot be removed',409);
            $bindings=$this->effectiveBindings($this->store->connections(),$c['connection_id'],$ids);if(!$bindings)throw new RuntimeException('Binding validation failed',409);
            $now=$this->now();$this->store->query('INSERT INTO '.$this->store->table('operation').' (operation_id,action,connection_id,request_hash,state,created_at,updated_at) VALUES(?,?,?,?,?,?,?)',[$e['operationId'],'commit',$c['connection_id'],hash('sha256',$raw),'in_progress',$now,$now]);
            $this->store->query('UPDATE '.$this->store->table('connection').' SET server_ids=?,revision=revision+1,state=?,updated_at=? WHERE connection_id=? AND revision=?',[json_encode($ids),'active',$now,$c['connection_id'],(int)$c['revision']]);
            $fresh=$this->store->connection($c['connection_id']);$data=$this->data($fresh,false);$data['state']='committed';$this->store->audit((int)$c['customer_id'],0,'commit',$c['connection_id'],['serverIds'=>$ids,'revision'=>$data['revision']],$now);return $this->saveResult($e,$raw,$fresh,$data);
        });
    }
    public function effectiveBindings(?array $connections=null,?string $candidateId=null,?array $candidateIds=null):array
    {
        $out=$this->static;$rows=$connections??$this->store->connections();if(count($rows)>100)throw new RuntimeException('Connection inventory exceeds its configured bound');
        foreach($rows as$c){if($c['state']!=='active'&&$c['connection_id']!==$candidateId)continue;$b=$this->checkedStatic($c);$ids=$c['connection_id']===$candidateId?$candidateIds:json_decode($c['server_ids'],true,16,JSON_THROW_ON_ERROR);
            if(!$ids)continue;if($b){if(array_diff($b['server_ids'],$ids))throw new RuntimeException('Protected server scope changed');foreach($out as$index=>$old)if($old['bridge_id']===$b['bridge_id']){$out[$index]['server_ids']=$ids;break;}}
            else{$out[]=['bridge_id'=>$c['bridge_id'],'tenant_id'=>$c['tenant_id'],'customer_id'=>(int)$c['customer_id'],'server_ids'=>$ids,'secret'=>$this->secret($c),'enabled'=>true,'scheduler_manifest'=>$this->profile];}
        }
        $bridges=[];$tenantServers=[];$customerServers=[];
        foreach($out as$b){if(isset($bridges[$b['bridge_id']]))throw new RuntimeException('Connection binding conflict');$bridges[$b['bridge_id']]=true;foreach($b['server_ids']as$id){$t=$b['tenant_id'].':'.$id;$c=$b['customer_id'].':'.$id;if(isset($tenantServers[$t])||isset($customerServers[$c]))throw new RuntimeException('Connection server scope conflict');$tenantServers[$t]=true;$customerServers[$c]=true;}}
        return $out;
    }
}
