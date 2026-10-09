<?php declare(strict_types=1);
/** Execute the exact private candidate's nested send catch and native pause handler. */
$candidate=$argv[1]??'';
$manifestPath=$candidate.'/short-retry-manifest.json';
if(!is_file($manifestPath))throw new RuntimeException('Pass prepared short-retry candidate directory');
$manifest=json_decode(file_get_contents($manifestPath),true,16,JSON_THROW_ON_ERROR);
$path='apps/console/commands/SendCampaignsCommand.php';
if(!hash_equals($manifest['sha256'][$path],hash_file('sha256',$candidate.'/'.$path)))throw new RuntimeException('Candidate hash mismatch');
$source=file_get_contents($candidate.'/'.$path);
function section(string $source,string $start,string $end):string{$a=strpos($source,$start);$b=$a===false?false:strpos($source,$end,$a);if($a===false||$b===false)throw new RuntimeException('Candidate context missing');return substr($source,$a,$b-$a);}
$send=section($source,'            $canHaveQuota   = $server->getCanHaveQuota();','            // end 1.5.3');
$handler=section($source,'            $code = (int)$e->getCode();','            return $code;').' return $code;';
function check($v,$label){if(!$v)throw new RuntimeException($label);echo "PASS $label\n";}
class Yii{static function log(...$v){}}
class CLogger{const LEVEL_ERROR='error';}
class Campaign{
    const STATUS_PROCESSING='processing',STATUS_SENDING='sending';
    public $campaign_id=1,$campaign_uid='fixture-campaign',$status='processing',$storedStatus='paused';
    function getReloadedStatus(){return $this->storedStatus;}
    function saveStatus(){ $this->storedStatus=$this->status; }
}
class MagicSmtpShortRetry{static function assertEligible(...$v){}}
class MutexFixture{function acquire(...$v){return true;}function release(...$v){}}
function mutex(){return new MutexFixture();}
class WorkerFixtureBase{function stdout(...$v){}function checkCampaignOverMaxBounceRate(...$v){}function checkCampaignOverMaxComplaintRate(...$v){}}
eval('class CandidateWorker extends WorkerFixtureBase { function send($server,$campaign,$subscriber){$mustSend=true;$emailParams=[];'.$send.'return $sent;} function handle($e,$campaign,$customer){$canChangeCampaignStatus=true;$maxBounceRate=0;$maxComplaintRate=0;'.$handler.'}}');
$server=new class{
    public $code=98,$guard=null,$server_id=1;
    function getCanHaveQuota(){return false;}function getCanLogUsage(){return false;}function disableLogUsage(){}function enableLogUsage(){}
    function setShortRetryGuard($guard){$this->guard=$guard;}
    function send($p){throw new Exception('fixture transient',$this->code);}
};
$worker=new CandidateWorker();$campaign=new Campaign();$subscriber=(object)[];$customer=new class{function getIsOverQuota(){return false;}};
foreach([98,99] as $code){$server->code=$code;try{$worker->send($server,$campaign,$subscriber);throw new RuntimeException('must throw');}catch(Exception $e){check($e->getCode()===$code,'both nested native send catches preserve code '.$code);if($code===98){check($worker->handle($e,$campaign,$customer)===98&&$campaign->storedStatus==='paused','actual outer native handler reloads and preserves paused status');}}}
check(is_callable($server->guard),'normal campaign worker installs native eligibility guard');
echo "PASS exact private candidate code, synthetic objects, no network or account changes\n";
