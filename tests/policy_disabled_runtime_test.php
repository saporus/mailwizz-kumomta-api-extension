<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
function app_param($key,$default=null){return $default;}
class Yii{static function app(){throw new RuntimeException('Disabled policy must not open the database');}}
require dirname(__DIR__).'/magicsmtp/MagicSmtpPolicyRuntime.php';
function check($value,$label){if(!$value)throw new RuntimeException($label);echo 'PASS '.$label."\n";}
check(MagicSmtpPolicyRuntime::bridge()===null,'absent bindings leave bridge disabled without database access');
MagicSmtpPolicyRuntime::recordDispatch(new stdClass(),[],'fixture@example.test','original');
check(MagicSmtpPolicyRuntime::effective(null,null)===null,'absent bindings have no recipient effect');
check(MagicSmtpPolicyRuntime::exclusion(1,'t.email')==='1=1','ordinary selector remains unrestricted');
$query=new stdClass();check(MagicSmtpPolicyRuntime::queueQuery($query,null,'ignored',true)===$query,'queue-table selector is unchanged');
check(MagicSmtpPolicyRuntime::canComplete(null)===true,'ordinary campaign completion remains available');
$controller=new class{function getId(){return 'campaign_reports';}function getAction(){return new class{function getId(){return 'delivery';}};}};
$grid=['columns'=>['status']];check(MagicSmtpPolicyRuntime::gridProperties($grid,$controller)===$grid,'normal delivery-report columns unchanged without bindings');
try{MagicSmtpPolicyRuntime::callback(1,'{}','');throw new RuntimeException('Unconfigured callback accepted');}catch(RuntimeException $e){check($e->getCode()===403,'unconfigured policy callback is rejected');}
echo "PASS disabled feature is inert; no database, campaign, native blacklist or network operations\n";
