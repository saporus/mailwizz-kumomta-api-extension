<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
class DeliveryServerSmtp{}
// Installed Yii 1 exposes getRawBody(), not getHeader().
class CHttpRequest {public $raw;function __construct($data){$this->raw=json_encode($data);}function getRawBody(){return $this->raw;}}
class CampaignBounceLog{const BOUNCE_SOFT='soft',BOUNCE_HARD='hard';}
$policyBindings=[];function app_param($name,$default=null){return $name==='magicsmtp.policyBridges'?$GLOBALS['policyBindings']:$default;}
// Match BaseController::renderJson: its default status overrides earlier http_response_code calls.
$renderer=new class{public $data;function renderJson($data,$statusCode=200){http_response_code($statusCode);$this->data=$data;}};function controller(){return $GLOBALS['renderer'];}
require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtp.php';
class TestSmtp extends DeliveryServerMagicSmtp{public $server_id=42,$bounce;function processBounce(array $params){$this->bounce=$params;return true;}}
function check($v,$label){if(!$v)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
$server=new TestSmtp();
foreach([null,'unknown','soft','hard'] as $value){$data=['event_type'=>'bounce','data'=>['recipient'=>'synthetic@example.test','message_id'=>'original@example.test']];if($value!==null)$data['data']['bounce_type']=$value;$server->handleCallback(new CHttpRequest($data));check($server->bounce['bounce_type']===($value==='hard'?'hard':'soft'),'legacy classification '.($value??'missing').' remains conservative');}
$server->handleCallback(new CHttpRequest(['event_type'=>'recipient.policy_probe','tenant'=>'tenant','data'=>['bridge_id'=>'bridge','nonce'=>'nonce']]));
check(http_response_code()===403 && $renderer->data['ok']===false,'unconfigured policy endpoint fails closed with HTTP403');
check(strpos(json_encode($renderer->data),'secret')===false,'callback errors expose no secret');
$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']=['malformed'];
$server->handleCallback(new CHttpRequest(['event_type'=>'recipient.policy_probe','tenant'=>'tenant','data'=>['bridge_id'=>'bridge','nonce'=>'nonce']]));
check(http_response_code()===403,'non-string signature header remains a rejected unconfigured request');
unset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
$request=new CHttpRequest([]);$request->raw=str_repeat('x',2097153);$server->handleCallback($request);
check(http_response_code()===413 && $renderer->data['message']==='Payload too large','oversized callback status survives JSON renderer');
$server->handleCallback(new CHttpRequest(['event_type'=>'bounce','data'=>['recipient'=>'synthetic@example.test','message_id'=>'original@example.test','bounce_type'=>'soft']]));
check(http_response_code()===200 && $renderer->data['ok']===true,'ordinary bounce keeps its normal HTTP200 response');
$pdo=new PDO('sqlite::memory:');$store=new MagicSmtpPolicyStore($pdo);$store->install();
$binding=['bridge_id'=>'bridge','tenant_id'=>'tenant','customer_id'=>1,'server_ids'=>[42],'secret'=>str_repeat('s',40),'enabled'=>true];
$policyBindings=[$binding];
$bridge=new MagicSmtpPolicyBridge($store,$policyBindings,static function(){return null;},static function(){return true;});
$property=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bridge');$property->setAccessible(true);$property->setValue(null,$bridge);
$fingerprint=new ReflectionProperty(MagicSmtpPolicyRuntime::class,'bindingFingerprint');$fingerprint->setAccessible(true);$fingerprint->setValue(null,hash('sha256',json_encode($policyBindings,JSON_THROW_ON_ERROR)));
$request=new CHttpRequest(['event_id'=>'probe','event_type'=>'recipient.policy_probe','tenant'=>'tenant','timestamp'=>(int)(microtime(true)*1000),'data'=>['bridge_id'=>'bridge','nonce'=>'signed-nonce']]);
$request->raw.="\n";
$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']=hash_hmac('sha256',$request->raw,$binding['secret']);
$server->handleCallback($request);
check(http_response_code()===200 && $renderer->data['nonce']==='signed-nonce','FPM signature header authenticates exact raw bytes through real callback');
$request->raw.=' ';$server->handleCallback($request);
check(http_response_code()===401,'modified raw bytes fail the real callback signature check');
unset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);$server->handleCallback($request);
check(http_response_code()===401,'missing FPM signature header is rejected');
$_SERVER['HTTP_X_WEBHOOK_SIGNATURE']=['malformed'];$server->handleCallback($request);
check(http_response_code()===401,'non-string FPM signature header is rejected');
unset($_SERVER['HTTP_X_WEBHOOK_SIGNATURE']);
echo "PASS policy callback routing and legacy missing bounce type\n";
