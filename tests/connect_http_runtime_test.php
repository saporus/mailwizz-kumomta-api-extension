<?php declare(strict_types=1);
define('MW_PATH',dirname(__DIR__));
require_once dirname(__DIR__).'/magicsmtp/models/MagicSmtpConnectHttp.php';
$checks=0;
function check($actual,$expected,string $label=''):void {global $checks;$checks++;if($actual!==$expected)throw new RuntimeException($label.' expected '.var_export($expected,true).' got '.var_export($actual,true));}
function denied(callable $call,string $message):void {global $checks;try{$call();}catch(RuntimeException $e){$checks++;if(strpos($e->getMessage(),$message)===false)throw $e;return;}throw new RuntimeException('Expected denial '.$message);}

foreach(['8.8.8.8','1.1.1.1','51.77.133.158','192.0.1.1','2001:4860:4860::8888','2606:4700:4700::1111']as$ip)check(MagicSmtpConnectHttp::publicAddress($ip),true,$ip);
foreach(['0.1.2.3','10.1.2.3','100.64.0.1','100.127.255.254','127.0.0.1','169.254.169.254','172.16.0.1','172.31.255.254','192.168.1.1','192.0.0.9','192.0.2.2','192.88.99.1','198.18.0.1','198.19.255.254','198.51.100.1','203.0.113.1','224.0.0.1','255.255.255.255','::','::1','::ffff:8.8.8.8','::ffff:808:808','fc00::1','fd00::1','fe80::1','ff02::1','64:ff9b::808:808','2001::1','2001:2::1','2001:db8::1','2002:0808:0808::1','3ffe::1','3fff::1','fe80::1%lo0','nonsense']as$ip)check(MagicSmtpConnectHttp::publicAddress($ip),false,$ip);
check(MagicSmtpConnectHttp::apiBase('https://kumo.example.com/ui/api/ingest/email'),'https://kumo.example.com/ui/api');
check(MagicSmtpConnectHttp::apiBase('https://KUMO.EXAMPLE.COM:443/ui/api/ingest/email'),'https://kumo.example.com/ui/api');
foreach(['http://kumo.example.com/ui/api/ingest/email','https://127.0.0.1/ui/api/ingest/email','https://2130706433/ui/api/ingest/email','https://[::1]/ui/api/ingest/email','https://user:pass@kumo.example.com/ui/api/ingest/email','https://kumo.example.com:444/ui/api/ingest/email','https://localhost/ui/api/ingest/email','https://kumo.example.com./ui/api/ingest/email','https://-host.example.com/ui/api/ingest/email','https://bad..example.com/ui/api/ingest/email','https://kumo.example.com/ui/api/ingest/email?token=secret','https://kumo.example.com/ui/api/ingest/email#fragment','https://kumo.example.com/a/../ui/api/ingest/email','https://kumo.example.com/%2e%2e/ui/api/ingest/email','https://kumo.example.com\\@127.0.0.1/ui/api/ingest/email'," https://kumo.example.com/ui/api/ingest/email",'https://kumo.example.com//ui/api/ingest/email']as$url)check(MagicSmtpConnectHttp::apiBase($url),'',$url);

$base='https://kumo.example.com/ui/api';$nonce='synthetic_nonce_0123456';$key='magicsmtp_tp_'.str_repeat('a',40);
$server=['apiBaseUrl'=>$base,'tlsVerified'=>true,'key'=>$key];$clock=1000.0;$addresses=['8.8.8.8'];$requests=[];$optionsSeen=[];$responseOverride=null;
$client=MagicSmtpConnectHttp::createClient(static function(string $host)use(&$addresses):array {check($host,'kumo.example.com');return $addresses;},static function(string $url,array $options)use(&$requests,&$optionsSeen,&$responseOverride,$nonce,$base):array {
    $requests[]=$url;$optionsSeen=$options;return $responseOverride??['status'=>200,'body'=>json_encode(['ok'=>true,'nonce'=>$nonce,'apiBaseUrl'=>$base,'tenantId'=>'synthetic-a','keyId'=>'key-a']),'peerIp'=>'8.8.8.8'];
},static function()use(&$clock):float{return $clock;});
$result=$client($server,$base,$nonce,[$base]);check($result['tenantId'],'synthetic-a');check(count($requests),1);
check($requests[0],$base.'/mailwizz-integration/identity');check($optionsSeen[CURLOPT_RESOLVE],['kumo.example.com:443:8.8.8.8']);
check($optionsSeen[CURLOPT_FOLLOWLOCATION],false);check($optionsSeen[CURLOPT_MAXREDIRS],0);check($optionsSeen[CURLOPT_SSL_VERIFYPEER],true);
check($optionsSeen[CURLOPT_SSL_VERIFYHOST],2);check($optionsSeen[CURLOPT_SSLVERSION],CURL_SSLVERSION_TLSv1_2);check($optionsSeen[CURLOPT_PROTOCOLS],CURLPROTO_HTTPS);
check($optionsSeen[CURLOPT_PROXY],'');check($optionsSeen[CURLOPT_NOPROXY],'*');check($optionsSeen[CURLOPT_TIMEOUT_MS],8000);check($optionsSeen[CURLOPT_CONNECTTIMEOUT_MS],3000);
check($optionsSeen[CURLOPT_HTTPHEADER][3],'X-Tenant-Api-Key: '.$key);

foreach([[],['127.0.0.1'],['8.8.8.8','::1'],['64:ff9b::a00:1'],array_fill(0,65,'8.8.8.8')]as$bad){$addresses=$bad;$before=count($requests);denied(static function()use($client,$server,$base,$nonce):void{$client($server,$base,$nonce,[$base]);},$bad&&count($bad)<=64?'public addresses':'lookup unavailable');check(count($requests),$before);}
$addresses=['8.8.8.8'];
foreach([['status'=>302,'body'=>'{}','peerIp'=>'8.8.8.8'],['status'=>200,'body'=>'{}','peerIp'=>'127.0.0.1'],['status'=>200,'body'=>str_repeat('a',8193),'peerIp'=>'8.8.8.8']]as$bad){$responseOverride=$bad;denied(static function()use($client,$server,$base,$nonce):void{$client($server,$base,$nonce,[$base]);},'not accepted');}
foreach(['{}','not json',json_encode(['ok'=>true,'nonce'=>'wrong','apiBaseUrl'=>$base]),json_encode(['ok'=>true,'nonce'=>$nonce,'apiBaseUrl'=>'https://wrong.example/ui/api'])]as$body){$responseOverride=['status'=>200,'body'=>$body,'peerIp'=>'8.8.8.8'];denied(static function()use($client,$server,$base,$nonce):void{$client($server,$base,$nonce,[$base]);},'response rejected');}
$responseOverride=null;$before=count($requests);
denied(static function()use($client,$server,$base,$nonce):void{$client($server,$base,$nonce,[]);},'not approved');
denied(static function()use($client,$server,$base,$nonce):void{$client(array_merge($server,['tlsVerified'=>false]),$base,$nonce,[$base]);},'not approved');
denied(static function()use($client,$server,$base,$nonce):void{$client(array_merge($server,['key'=>"secret\r\nHost: evil"]),$base,$nonce,[$base]);},'not approved');
denied(static function()use($client,$server,$base):void{$client($server,$base,'bad',[$base]);},'not approved');
denied(static function()use($client,$server,$base,$nonce):void{$client($server,$base,$nonce,[$base],30001);},'time budget');check(count($requests),$before);

$noLateRequests=0;$slowClock=1000.0;
$slow=MagicSmtpConnectHttp::createClient(static function(string $host)use(&$slowClock):array{$slowClock+=1.0;return ['8.8.8.8'];},static function()use(&$noLateRequests):array{$noLateRequests++;return [];},static function()use(&$slowClock):float{return $slowClock;});
denied(static function()use($slow,$server,$base,$nonce):void{$slow($server,$base,$nonce,[$base],500);},'timed out');check($noLateRequests,0);

$clock=1000.0;$bounded=MagicSmtpConnectHttp::createClient(static function(string $host)use(&$clock):array{$clock+=0.25;return ['8.8.8.8'];},static function(string $url,array $options)use($nonce,$base):array {
    check($options[CURLOPT_TIMEOUT_MS],750);check($options[CURLOPT_CONNECTTIMEOUT_MS],750);
    return ['status'=>200,'body'=>json_encode(['ok'=>true,'nonce'=>$nonce,'apiBaseUrl'=>$base]),'peerIp'=>'8.8.8.8'];
},static function()use(&$clock):float{return $clock;});
$bounded($server,$base,$nonce,[$base],1000);

$clock=1000.0;$addresses=['2606:4700:4700::1111'];$responseOverride=['status'=>200,'body'=>json_encode(['ok'=>true,'nonce'=>$nonce,'apiBaseUrl'=>$base]),'peerIp'=>'2606:4700:4700:0:0:0:0:1111'];
$client($server,$base,$nonce,[$base]);check($optionsSeen[CURLOPT_RESOLVE],['kumo.example.com:443:[2606:4700:4700::1111]']);check($optionsSeen[CURLOPT_IPRESOLVE],CURL_IPRESOLVE_V6);
echo json_encode(['ok'=>true,'checks'=>$checks,'externalRequests'=>0,'scope'=>'MailWizz stored-credential identity HTTPS transport'],JSON_UNESCAPED_SLASHES).PHP_EOL;
