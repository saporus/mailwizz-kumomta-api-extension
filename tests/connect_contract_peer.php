<?php declare(strict_types=1);
// Synthetic pipe peer for the real Node/PHP connection protocol. No network.
define('MW_PATH',__DIR__);
require dirname(__DIR__).'/magicsmtp/models/MagicSmtpConnectService.php';
require dirname(__DIR__).'/magicsmtp/models/MagicSmtpPolicyBridge.php';
$store=null;$service=null;$policy=null;$clock=1791547200;$identityCalls=0;
while(($line=fgets(STDIN))!==false){
    try{
        $input=json_decode($line,true,32,JSON_THROW_ON_ERROR);
        if($input['action']==='init'){
            $pdo=new PDO('sqlite::memory:');$store=new MagicSmtpConnectStore($pdo);$store->install();$store->setEnabled(true,1,$clock);
            $policy=new MagicSmtpPolicyStore($pdo);$policy->install();
            $profile=['contract'=>'magic-smtp-policy-scheduler-v1','acceptance'=>'passed'];
            $bindings=$input['bindings'];
            $servers=[];foreach([433,445,446]as$id)$servers[]=['id'=>$id,'customerId'=>1,'type'=>'magic-smtp-web-api','status'=>'active','name'=>'Synthetic '.$id,'apiBaseUrl'=>'https://kumo.example.com/ui/api','tlsVerified'=>true,'key'=>'synthetic-key-'.$id];
            $service=new MagicSmtpConnectService($store,new MagicSmtpConnectCrypto(base64_encode(str_repeat('k',32))),$bindings,$profile,
                static function(int $customer)use($servers):array{return $servers;},
                static function(array $server,string $base,string $nonce)use(&$identityCalls):array{$identityCalls++;return ['ok'=>true,'nonce'=>$nonce,'tenantId'=>'tenant-a','tenantName'=>'synthetic-a','keyId'=>'key-'.$server['id'],'apiBaseUrl'=>$base];},
                static function()use(&$clock):int{return $clock;},static function():bool{return true;});
            $out=$service->issueGrant(1,1,433,'https://mail.example.com/dswh/433');
        }elseif($input['action']==='request'){
            $event=json_decode($input['body'],true,32,JSON_THROW_ON_ERROR);
            if($event['event_type']==='recipient.policy_probe'){
                $bridge=new MagicSmtpPolicyBridge($policy,$service->effectiveBindings(),static function(){return false;},static function(){return true;},static function()use(&$clock){return $clock*1000;});
                $out=['status'=>200,'body'=>json_encode($bridge->receive(433,$input['body'],$input['signature']),JSON_THROW_ON_ERROR),'signature'=>''];
            }else $out=$service->handle(433,$input['body'],$input['signature']);
        }elseif($input['action']==='advance'){$clock+=(int)$input['seconds'];$out=['ok'=>true];}
        elseif($input['action']==='state'){$out=['bindings'=>array_map(static function($b){unset($b['secret']);return $b;},$service->effectiveBindings()),'connections'=>count($store->connections()),'identityCalls'=>$identityCalls];}
        else throw new RuntimeException('Unknown synthetic command');
        echo json_encode(['ok'=>true,'result'=>$out],JSON_THROW_ON_ERROR)."\n";
    }catch(Throwable $e){echo json_encode(['ok'=>false,'error'=>$e->getMessage(),'status'=>$e->getCode()],JSON_THROW_ON_ERROR)."\n";}
    fflush(STDOUT);
}
