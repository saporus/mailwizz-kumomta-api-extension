<?php declare(strict_types=1);
require_once __DIR__.'/../magicsmtp/models/MagicSmtpMinuteQuota.php';
function check($value,string $label):void{if(!$value)throw new RuntimeException($label);echo "PASS $label\n";}
final class MinuteFixture
{
    public $now=120.25,$mono=0.0,$used=0,$calls=0,$delay=0.0,$lockDelay=0.0,$countFails=false,$badSnapshot=null;
    public $value=false,$ttl=null,$expiry=0.0,$setFails=false,$deleteFails=false,$getThrows=false,$setThrows=false,$setDelay=0.0,$lockFails=false,$locks=0,$releases=0,$keys=[];
    public function helper():MagicSmtpMinuteQuota{return new MagicSmtpMinuteQuota($this,$this,function():array{
        ++$this->calls;if($this->countFails)throw new RuntimeException('synthetic count failure');
        if($this->badSnapshot!==null)return $this->badSnapshot;
        $out=['quota_now'=>(string)$this->now,'quota_used'=>(string)$this->used];
        $before=(int)floor($this->now/60);$this->now+=$this->delay;$this->mono+=$this->delay;$this->delay=0.0;
        if((int)floor($this->now/60)!==$before)$this->used=0;
        return $out;
    },function():float{return $this->mono;});}
    public function acquire($key,$timeout){$this->keys[]=$key;if($this->lockFails)return false;++$this->locks;$this->now+=$this->lockDelay;$this->mono+=$this->lockDelay;$this->lockDelay=0;return true;}
    public function release($key){++$this->releases;}
    public function get($key){if($this->getThrows)throw new RuntimeException('synthetic read failure');if($this->now>=$this->expiry)$this->value=false;return $this->value;}
    public function set($key,$value,$ttl){if($this->setThrows)throw new RuntimeException('synthetic write failure');if($this->setFails)return false;$this->now+=$this->setDelay;$this->mono+=$this->setDelay;$this->value=$value;$this->ttl=$ttl;$this->expiry=$this->now+$ttl;return true;}
    public function delete($key){if($this->deleteFails)return false;$this->value=false;return true;}
}
$key=sha1('DeliveryServerGetMinuteQuotaLeft::445');
$f=new MinuteFixture();$f->used=450;$f->helper()->remaining($key,450,true);
check($f->value===0&&$f->ttl===59&&$f->expiry<180,'full minute cache expires before next calendar minute');
$f->now=181;$f->used=0;
check($f->get($key)===false&&$f->helper()->remaining($key,450,false)===450,'next minute has native allowance without skipping a cron minute');
$f=new MinuteFixture();$f->value=0;$f->expiry=240;$f->used=17;
check($f->helper()->remaining($key,450,false)===433&&$f->value===0,'legacy zero cache does not deny the extension final check or require cache clearing');
$f=new MinuteFixture();$f->value=450;$f->expiry=240;$f->used=450;
check($f->helper()->remaining($key,450,false)===0,'legacy stale positive cache cannot grant excess allowance');
$f=new MinuteFixture();$f->used=1;
check($f->helper()->remaining($key,450,true)===449,'cold cache after saved native usage does not double debit');
$f->used=0;
check($f->helper()->remaining($key,450,true)===450,'current-minute undo recount does not double refund');
$f->used=17;$f->now=181;$f->value=100;$f->expiry=240;
check($f->helper()->remaining($key,450,true)===433,'prior-minute undo cannot refund current-minute usage');
$f=new MinuteFixture();$f->used=451;
check($f->helper()->remaining($key,450,false)===0,'over-quota durable usage is clamped at zero');
$f=new MinuteFixture();$f->lockFails=true;
check($f->helper()->remaining($key,450,false)===0&&$f->calls===0&&$f->releases===0,'lock failure grants no quota and does not release another lock');
$f=new MinuteFixture();$f->now=179.75;$f->lockDelay=1;$f->used=0;
check($f->helper()->remaining($key,450,true)===450&&$f->ttl===59,'database window is sampled after a lock wait crosses the boundary');
$f=new MinuteFixture();$f->now=179.75;$f->used=450;$f->delay=0.5;
check($f->helper()->remaining($key,450,true)===450&&$f->calls===2,'count spanning boundary is discarded and retried once for the fresh period');
$f=new MinuteFixture();$f->now=179.8;$f->used=450;
check($f->helper()->remaining($key,450,true)===0&&$f->value===false&&$f->ttl===null,'subsecond publication deletes cache instead of Yii never-expiring TTL zero');
$f=new MinuteFixture();$f->now=179.8;$f->value=12;$f->expiry=200;$f->deleteFails=true;
check($f->helper()->remaining($key,450,true)===0,'failed subsecond invalidation fails closed');
$f=new MinuteFixture();$f->used=450;$f->setDelay=2;
check($f->helper()->remaining($key,450,true)===0&&$f->value===false,'slow cache publication is invalidated before it can carry zero over the boundary');
$f=new MinuteFixture();$f->countFails=true;
check($f->helper()->remaining($key,450,false)===0&&$f->releases===1,'database error fails closed and releases native mutex');
foreach([['quota_now'=>'bad','quota_used'=>0],['quota_now'=>'INF','quota_used'=>0],['quota_now'=>120,'quota_used'=>-1],['quota_now'=>120,'quota_used'=>0.5],[]]as$bad){$f=new MinuteFixture();$f->badSnapshot=$bad;check($f->helper()->remaining($key,450,false)===0,'invalid database snapshot cannot grant quota');}
foreach(['setFails','setThrows']as$failure){$f=new MinuteFixture();$f->$failure=true;$f->value=450;$f->expiry=240;$f->used=450;$f->deleteFails=true;
    check($f->helper()->remaining($key,450,true)===0&&$f->helper()->remaining($key,450,false)===0,'cache write/invalidation failure never makes stale positive allowance authoritative');}
$f=new MinuteFixture();
check($f->helper()->remaining($key,0,false)===PHP_INT_MAX&&$f->calls===0&&$f->locks===0,'unlimited minute configuration stays unlimited without DB or cache work');
$f=new MinuteFixture();$f->used=10;
check($f->helper()->remaining($key,450,false,false)===440&&$f->locks===0&&$f->releases===0,'native caller-owned mutex flag is preserved');
$f=new MinuteFixture();$accepted=0;
for($i=0;$i<460;$i++){if($f->helper()->remaining($key,450,false)<=0)continue;++$f->used;++$accepted;$f->helper()->remaining($key,450,true);}
check($accepted===450&&$f->used===450&&$f->value===0,'serialised workers sharing native ledger/key cannot exceed 450');
check($f->locks===$f->releases&&count(array_unique($f->keys))===1,'all workers retain one native mutex identity and release it');
foreach(['existing-demo','new-demo','normal-tenant']as$workspace){$isolated=new MinuteFixture();$isolated->used=3;check($isolated->helper()->remaining(sha1($workspace),450,true)===447,'isolated synthetic '.$workspace.' has no production execution');}
check($f->used===450,'demo fixture writes do not modify another workspace');
$f=new MinuteFixture();$f->used=3;$prior=date_default_timezone_get();date_default_timezone_set('Pacific/Chatham');$a=$f->helper()->remaining($key,450,true);date_default_timezone_set('America/Los_Angeles');$b=$f->helper()->remaining($key,450,true);date_default_timezone_set($prior);
check($a===447&&$b===447&&$f->ttl===59,'PHP timezone does not shift database calendar-minute accounting');
echo "PASS synthetic ledger/cache/mutex only; no network or account changes\n";
