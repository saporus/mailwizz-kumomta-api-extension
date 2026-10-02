<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
class DeliveryServerSmtp{}
class CHttpRequest {public $raw;function __construct($data){$this->raw=json_encode($data);}function getRawBody(){return $this->raw;}function getHeader($name,$default=''){return $default;}}
class CampaignBounceLog{const BOUNCE_SOFT='soft',BOUNCE_HARD='hard';}
$renderer=new class{public $data;function renderJson($data){$this->data=$data;}};function controller(){return $GLOBALS['renderer'];}
require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtp.php';
class TestSmtp extends DeliveryServerMagicSmtp{public $server_id=42,$bounce;function processBounce(array $params){$this->bounce=$params;return true;}}
function check($v,$label){if(!$v)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
$server=new TestSmtp();
foreach([null,'unknown','soft','hard'] as $value){$data=['event_type'=>'bounce','data'=>['recipient'=>'synthetic@example.test','message_id'=>'original@example.test']];if($value!==null)$data['data']['bounce_type']=$value;$server->handleCallback(new CHttpRequest($data));check($server->bounce['bounce_type']===($value==='hard'?'hard':'soft'),'legacy classification '.($value??'missing').' remains conservative');}
$server->handleCallback(new CHttpRequest(['event_type'=>'recipient.policy_probe','tenant'=>'tenant','data'=>['bridge_id'=>'bridge','nonce'=>'nonce']]));
check(http_response_code()===403 && $renderer->data['ok']===false,'unconfigured policy endpoint fails closed with HTTP403');
check(strpos(json_encode($renderer->data),'secret')===false,'callback errors expose no secret');
echo "PASS policy callback routing and legacy missing bounce type\n";
