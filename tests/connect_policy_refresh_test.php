<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
require dirname(__DIR__).'/magicsmtp/MagicSmtpPolicyRuntime.php';
$pdo=new PDO('sqlite::memory:');$database=new class($pdo){public $pdo,$tablePrefix='';function __construct($pdo){$this->pdo=$pdo;}function setActive($v){}function getPdoInstance(){return $this->pdo;}};
class Yii{static function app(){return (object)['db'=>$GLOBALS['database']];}}
$bindings=[['bridge_id'=>'bridge','tenant_id'=>'tenant','customer_id'=>1,'server_ids'=>[433],'secret'=>str_repeat('s',40),'enabled'=>true]];
function app_param($key,$default=null){return $key==='magicsmtp.policyBridges'?$GLOBALS['bindings']:$default;}
function check($v,$label){if(!$v)throw new RuntimeException($label);$GLOBALS['checks']=($GLOBALS['checks']??0)+1;}
$first=MagicSmtpPolicyRuntime::bridge();$first->store()->install();check($first->bindingForServer(445,null,1)===null,'unbound server absent initially');check(MagicSmtpPolicyRuntime::bridge()===$first,'unchanged authority reuses bridge');
$bindings[0]['server_ids'][]=445;$second=MagicSmtpPolicyRuntime::bridge();check($first!==$second,'worker refreshes effective binding without restart');check($second->bindingForServer(445,null,1)['bridge_id']==='bridge','new server authorized in same worker');
$ready=new ReflectionProperty(MagicSmtpPolicyBridge::class,'schedulerReady');$ready->setAccessible(true);$ready->setValue($second,static function(){return true;});
MagicSmtpPolicyRuntime::recordDispatch((object)['server_id'=>445],['campaign'=>(object)['customer_id'=>1,'campaign_uid'=>'campaign-new'],'subscriberUid'=>'subscriber-new'],'synthetic@example.test','<message-new@example.test>');
check((int)$pdo->query('SELECT COUNT(*) FROM magic_smtp_policy_dispatch')->fetchColumn()===1,'new server dispatch enters policy ledger in same process');
$bindings[0]['secret']=str_repeat('t',40);check(MagicSmtpPolicyRuntime::bridge()!==$second,'secret authority change refreshes cached bridge');$bindings=[];check(MagicSmtpPolicyRuntime::bridge()===null,'empty authority clears cache');
echo json_encode(['ok'=>true,'checks'=>$checks,'externalRequests'=>0])."\n";
