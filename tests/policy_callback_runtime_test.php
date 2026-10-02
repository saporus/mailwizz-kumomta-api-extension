<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
class DeliveryServerSmtp{}
class CHttpRequest {public $raw;function __construct($data){$this->raw=json_encode($data);}function getRawBody(){return $this->raw;}function getHeader($name,$default=''){return $default;}}
class CampaignBounceLog{const BOUNCE_SOFT='soft',BOUNCE_HARD='hard';}
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
$request=new CHttpRequest([]);$request->raw=str_repeat('x',2097153);$server->handleCallback($request);
check(http_response_code()===413 && $renderer->data['message']==='Payload too large','oversized callback status survives JSON renderer');
$server->handleCallback(new CHttpRequest(['event_type'=>'bounce','data'=>['recipient'=>'synthetic@example.test','message_id'=>'original@example.test','bounce_type'=>'soft']]));
check(http_response_code()===200 && $renderer->data['ok']===true,'ordinary bounce keeps its normal HTTP200 response');
echo "PASS policy callback routing and legacy missing bounce type\n";
