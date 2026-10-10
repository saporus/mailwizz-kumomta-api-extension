<?php declare(strict_types=1);
/** Synthetic HTTP plus isolated SQLite only. Never sends mail or loads native customer data. */
namespace GuzzleHttp {
    class Client
    {
        public $calls=[];
        public $mode='success';
        public $beforePost;
        public function post($url,array $options)
        {
            if ($this->beforePost) ($this->beforePost)();
            $this->calls[]=['url'=>$url,'options'=>$options];
            if ($this->mode==='policy_rejected') throw new \GuzzleHttp\Exception\RequestException(
                'Recipient policy rejected by gateway',new Response(400,'{"error":"recipient_suppressed","retryable":false}'));
            return new Response(200,'OK');
        }
    }
    class Response
    {
        private $status; private $body;
        public function __construct(int $status,string $body) {$this->status=$status;$this->body=$body;}
        public function getStatusCode() {return $this->status;}
        public function getBody() {return $this->body;}
        public function getHeaderLine($name) {return '';}
    }
}
namespace GuzzleHttp\Exception {
    class RequestException extends \Exception
    {
        private $response;
        public function __construct($message,$response) {parent::__construct($message);$this->response=$response;}
        public function getResponse() {return $this->response;}
    }
    class ConnectException extends \Exception {}
}
namespace {
    define('MW_PATH',__DIR__);
    define('MW_VERSION','2.7.3');
    $runtime=sys_get_temp_dir().'/magic-policy-interactive-'.bin2hex(random_bytes(8));
    mkdir($runtime,0700);
    $schedulerFiles=['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'];
    $schedulerSource="<?php // MAGIC_SMTP_POLICY_SCHEDULER_V1 synthetic acceptance fixture only\n";
    $manifest=['contract'=>'magic-smtp-policy-scheduler-v1','acceptance'=>'passed','mailwizz_version'=>'2.7.3','sha256'=>[]];
    foreach($schedulerFiles as$file){mkdir(dirname($runtime.'/'.$file),0700,true);file_put_contents($runtime.'/'.$file,$schedulerSource);$manifest['sha256'][$file]=hash('sha256',$schedulerSource);}
    register_shutdown_function(static function()use($runtime,$schedulerFiles){
        foreach($schedulerFiles as$file)unlink($runtime.'/'.$file);
        foreach(['apps/console/commands','apps/console','apps/common/components/db/behaviors','apps/common/components/db','apps/common/components','apps/common','apps']as$directory)rmdir($runtime.'/'.$directory);
        foreach(glob($runtime.'/*')as$file)unlink($file);rmdir($runtime);
    });
    $cli=false;$appName='customer';$post=true;$guest=false;$actorId=10;
    $controllerId='campaigns';$actionId='test';$bindingFailure=false;
    function is_cli(){return $GLOBALS['cli'];}
    function apps(){return new class {public function isAppName($name){return $GLOBALS['appName']===$name;}};}
    function request(){return new class {public function getIsPostRequest(){return $GLOBALS['post'];}};}
    function customer(){return new class {
        public function __get($name){if($name==='isGuest')return $GLOBALS['guest'];throw new \RuntimeException('Unknown customer property');}
        public function getId(){return $GLOBALS['actorId'];}
    };}
    class Yii
    {
        public static function getPathOfAlias($alias){return $GLOBALS['runtime'].($alias==='common'?'/apps/common':'');}
        public static function app(){return new class {
            public function getController(){return new class {
                public function getId(){return $GLOBALS['controllerId'];}
                public function getAction(){return new class {public function getId(){return $GLOBALS['actionId'];}};}
            };}
            public function __get($name){throw new \RuntimeException('Unexpected native database access');}
        };}
    }
    function app_param($key,$default=null)
    {
        if($key==='magicsmtp.policyBridges'){
            if($GLOBALS['bindingFailure'])throw new \RuntimeException('Synthetic binding configuration unavailable');
            return $GLOBALS['bindings'];
        }
        return $default;
    }
    class Campaign
    {
        public $customer_id=10;public $campaign_uid='campaign-test';
        public static function model(){return new self;}
        public function findByAttributes($attributes){return null;}
    }
    class CustomerEmailTemplate {public $customer_id=10;}
    class FakeMailer
    {
        public $logs=[];
        public function findEmailAndName($mailbox){return is_array($mailbox)?[(string)array_key_first($mailbox),(string)reset($mailbox)]:[(string)$mailbox,''];}
        public function getEmailMessage(array $params){return "From: sender@example.test\r\nTo: preview@example.test\r\nMessage-ID: <preview@example.test>\r\n\r\n".$params['body'];}
        public function getEmailMessageId(){return '<preview@example.test>';}
        public function addLog($message){$this->logs[]=(string)$message;}
    }
    class DeliveryServer
    {
        public const DELIVERY_FOR_CAMPAIGN_TEST='campaign-test';
        public const DELIVERY_FOR_TEMPLATE_TEST='template-test';
        public const DELIVERY_FOR_CAMPAIGN='campaign';
        public $server_id=42;public $hostname='https://synthetic.example.test/ui/api/ingest/email';
        public $password='synthetic-direct-api-key';public $timeout=30;public $usage=0;
        private $deliveryObject;private $deliveryFor='system';protected $mailer;
        public function __construct(){$this->mailer=new FakeMailer;}
        public function setDeliveryFor(string $purpose){$this->deliveryFor=$purpose;return $this;}
        public function getDeliveryFor():string{return $this->deliveryFor;}
        public function setDeliveryObject($object){$this->deliveryObject=$object;return $this;}
        public function getDeliveryObject(){return $this->deliveryObject;}
        public function getParamsArray(array $params){return $params+['campaignUid'=>'','subscriberUid'=>''];}
        public function getMailer(){return $this->mailer;}
        public function logUsage(){$this->usage++;}
    }
    class ArrayHelper {public static function hasKeys(array $params,array $keys){foreach($keys as$key)if(!array_key_exists($key,$params))return false;return true;}}
    function hooks(){return new class {
        public function applyFilters($name,$params,$server){return $params;}
        public function doAction($name,$params,$server,$sent){}
        public function hasFilters($name){return false;}
    };}
    require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
    class TestServer extends DeliveryServerMagicSmtpWebApi
    {
        public $client;
        public function __construct(){parent::__construct();$this->client=new \GuzzleHttp\Client;}
        public function getClient():\GuzzleHttp\Client{return $this->client;}
    }
    function check($ok,string $name):void{if(!$ok)throw new \RuntimeException($name);echo 'PASS '.$name."\n";}
    function installBridge(array $bindings,?PDO $db=null):PDO
    {
        $GLOBALS['bindings']=$bindings;
        $db=$db??new PDO('sqlite::memory:');$store=new MagicSmtpPolicyStore($db);$store->install();
        $bridge=new MagicSmtpPolicyBridge($store,$bindings,static function(){return null;},static function(){return true;});
        $cache=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bridge');$cache->setAccessible(true);$cache->setValue(null,$bridge);
        $fingerprint=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bindingFingerprint');$fingerprint->setAccessible(true);
        $fingerprint->setValue(null,hash('sha256',json_encode($bindings,JSON_THROW_ON_ERROR)));
        return $db;
    }
    $binding=['bridge_id'=>'bridge-a','tenant_id'=>'tenant-a','customer_id'=>10,'server_ids'=>[42],
        'secret'=>str_repeat('a',40),'enabled'=>true,'scheduler_manifest'=>$manifest];
    $db=installBridge([$binding]);
    $params=['from'=>'sender@example.test','to'=>'preview@example.test','subject'=>'Native preview','body'=>'Synthetic body'];
    $make=static function(){$server=new TestServer;return $server->setDeliveryFor(DeliveryServer::DELIVERY_FOR_CAMPAIGN_TEST)->setDeliveryObject(new Campaign);};
    $sendBlocked=static function(TestServer $server,array $input,string $name)use(&$cli):void{
        $before=count($server->client->calls);$exception=null;$result=null;
        try{$result=$server->send($input);}catch(\Exception $e){$exception=$e;}
        check(count($server->client->calls)===$before,$name.' makes no HTTP request');
        check($cli?($exception instanceof \Exception && $exception->getCode()===99):($exception===null && $result===[]),$name.' preserves native retry/log outcome');
    };
    foreach(['synthetic-direct-api-key','synthetic-elastic-api-key']as$key){
        $server=$make();$server->password=$key;$result=$server->send($params);
        $options=$server->client->calls[0]['options']??[];
        check($result===['message_id'=>'preview@example.test'] && count($server->client->calls)===1,'authenticated campaign preview submits once through normal API');
        check(($options['headers']['x-tenant-api-key']??null)===$key,'preview retains API key routing');
        check(!isset($options['headers']['Idempotency-Key'],$options['json']['IdempotencyKey'])
            && !isset($options['json']['campaign']) && !isset($options['json']['recipients'][0]['metadata']['subscriber_uid']),'preview invents no campaign, subscriber or campaign idempotency proof');
    }
    check((int)$db->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===0,'manual previews write no campaign dispatch records');
    $actionId='bulk_action';check((bool)$make()->send($params),'native bulk campaign preview is supported');$actionId='test';
    $controllerId='templates';$server=$make()->setDeliveryFor(DeliveryServer::DELIVERY_FOR_TEMPLATE_TEST)->setDeliveryObject(new CustomerEmailTemplate);
    check((bool)$server->send($params),'native template preview is supported');$controllerId='campaigns';
    foreach([
        ['campaignUid'=>'campaign-a'],['subscriberUid'=>'subscriber-a'],['campaign'=>new Campaign],
        ['subscriber'=>new stdClass],['campaign'=>false],['campaign'=>[]],['campaignUid'=>0],['subscriberUid'=>false],
    ]as$index=>$partial)$sendBlocked($make(),array_merge($params,$partial),'partial/malformed correlation '.$index);
    $controllerId='other';$sendBlocked($make(),$params+['isTest'=>true,'skipPolicy'=>true],'caller flags outside native route');$controllerId='campaigns';
    $actionId='send';$sendBlocked($make(),$params,'non-preview action');$actionId='test';
    $post=false;$sendBlocked($make(),$params,'GET request');$post=true;
    $appName='backend';$sendBlocked($make(),$params,'different application');$appName='customer';
    $guest=true;$sendBlocked($make(),$params,'guest customer');$guest=false;
    $actorId=11;$sendBlocked($make(),$params,'foreign authenticated customer');$actorId=10;
    $sendBlocked($make()->setDeliveryFor(DeliveryServer::DELIVERY_FOR_CAMPAIGN),$params,'campaign delivery marker');
    $sendBlocked($make()->setDeliveryObject((object)['customer_id'=>10]),$params,'untrusted delivery object shape');
    $foreign=new Campaign;$foreign->customer_id=11;$sendBlocked($make()->setDeliveryObject($foreign),$params,'foreign native delivery object');
    $cli=true;$sendBlocked($make(),$params,'CLI with preview marker');$cli=false;
    $bindingFailure=true;$sendBlocked($make(),$params,'unavailable binding configuration');$bindingFailure=false;
    $disabled=$binding;$disabled['enabled']=false;$db=installBridge([$disabled]);$sendBlocked($make(),$params,'disabled customer binding');
    $unverified=$binding;$unverified['scheduler_manifest']['acceptance']='pending';$db=installBridge([$unverified]);
    $sendBlocked($make(),$params,'unverified scheduler remains blocked');
    $db=installBridge([$binding]);file_put_contents($runtime.'/'.$schedulerFiles[0],$schedulerSource.'// drift');
    $sendBlocked($make(),$params,'changed scheduler source remains blocked');file_put_contents($runtime.'/'.$schedulerFiles[0],$schedulerSource);
    $other=$binding;$other['customer_id']=11;$db=installBridge([$other]);$sendBlocked($make(),$params,'server bound only to different customer');
    $other['bridge_id']='bridge-b';$other['tenant_id']='tenant-b';$other['secret']=str_repeat('b',40);
    $db=installBridge([$binding,$other]);
    check((bool)$make()->send($params),'shared server selects the authenticated customer binding');
    $actorId=11;check((bool)$make()->setDeliveryObject($foreign)->send($params),'second bound customer uses its independent shared-server scope');$actorId=10;
    $db=installBridge([$binding]);
    $db->exec('DROP TABLE magic_smtp_policy_effect');
    $sendBlocked($make(),$params,'unavailable policy schema');
    $db=installBridge([$binding]);
    $server=$make();$server->client->mode='policy_rejected';
    check($server->send($params)===[] && count($server->client->calls)===1
        && in_array('Recipient policy rejected by gateway',$server->getMailer()->logs,true),'normal gateway policy refusal remains a failed preview without retry');
    $server=$make();check((bool)$server->send($params),'initial preview can complete');
    $cli=true;$sendBlocked($server,$params,'reusing the server in a worker does not retain a preview exemption');
    $campaignParams=$params+['campaign'=>new Campaign,'subscriberUid'=>'subscriber-1'];
    $server=$make()->setDeliveryFor(DeliveryServer::DELIVERY_FOR_CAMPAIGN);
    $server->client->beforePost=static function()use($db){check((int)$db->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===1,'campaign proof is durable before synthetic HTTP begins');};
    check((bool)$server->send($campaignParams),'complete campaign recipient retains the normal dispatch path');
    $options=$server->client->calls[0]['options'];
    check(isset($options['headers']['Idempotency-Key']) && ($options['json']['campaign']??'')==='campaign-test'
        && ($options['json']['recipients'][0]['metadata']['subscriber_uid']??'')==='subscriber-1','campaign metadata and idempotency remain unchanged');
    $sendBlocked($make(),$params+['campaign'=>new Campaign],'campaign without subscriber remains blocked');
    $sendBlocked($make(),$params+['subscriberUid'=>'subscriber-1'],'subscriber without campaign remains blocked');
    $db->exec("CREATE TRIGGER stop_dispatch BEFORE INSERT ON magic_smtp_policy_dispatch BEGIN SELECT RAISE(ABORT,'synthetic disk failure'); END");
    $sendBlocked($make(),array_merge($campaignParams,['subscriberUid'=>'subscriber-2']),'campaign dispatch storage failure');
    check((int)$db->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===1,'failed and preview sends cannot create phantom dispatch records');
    $cli=false;$db=installBridge([]);$controllerId='other';
    check((bool)$make()->send($params),'unbound server behavior remains unchanged');
    echo "PASS native interactive policy dispatch acceptance: synthetic HTTP and isolated SQLite only.\n";
}
