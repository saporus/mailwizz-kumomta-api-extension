<?php declare(strict_types=1);
require_once __DIR__.'/../magicsmtp/models/MagicSmtpMinuteQuota.php';
class MagicSmtpCooldown{public $until;function __construct(float$until){$this->until=$until;}function snapshot():array{return['remaining'=>max(0,(int)ceil($this->until-$GLOBALS['clock'])),'short_retry'=>true];}}
require_once __DIR__.'/../magicsmtp/models/MagicSmtpShortRetry.php';
function check($ok,string$label):void{if(!$ok)throw new RuntimeException($label);echo"PASS $label\n";}
$clock=0.0;$guards=0;$queries=0;
$cache=new class{function set(...$v){throw new RuntimeException('Getter must not publish');}};
$mutex=new class{function acquire(...$v){return true;}function release(...$v){}};
$quota=new MagicSmtpMinuteQuota($cache,$mutex,function()use(&$queries):array{++$queries;return['quota_now'=>120.25,'quota_used'=>0];},static function():float{return 0.0;});
$retry=new MagicSmtpShortRetry(function()use(&$guards,$quota):void{++$guards;if($quota->remaining('synthetic-native-key',450,false)!==450)throw new RuntimeException('Quota changed');},function()use(&$clock):float{return$clock;},function()use(&$clock):void{$clock+=1.0;});
// Initial shared cooldown, then each of the three permitted pre-admission refusals.
foreach([2,4,4,1]as$duration)check($retry->wait(new MagicSmtpCooldown($clock+$duration)),'normal one-second wait preserves eligibility before retry');
$retry->checkEligibility();
check($clock===11.0&&$guards===16&&$queries===16,'nominal longest four wait windows plus terminal guard use 16 strict quota reads');
$clock=0.0;$guards=0;
$retry=new MagicSmtpShortRetry(function()use(&$guards):void{++$guards;},function()use(&$clock):float{return$clock;},function()use(&$clock):void{$clock+=0.5;});
check($retry->wait(new MagicSmtpCooldown(2.0))&&$guards===5,'interrupted/shortened sleeps show guard calls are not an independent hard counter limit');
echo"PASS synthetic clock/quota only; HTTP attempt cap remains tested by short_retry_runtime_test.php\n";
