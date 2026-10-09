<?php declare(strict_types=1);
namespace GuzzleHttp {
    class Client {
        public function post($url, $options) {
            $GLOBALS['requests'][] = [$url, $options];
            $next = array_shift($GLOBALS['responses']);
            if ($next === 'timeout') throw new \GuzzleHttp\Exception\ConnectException('ambiguous timeout');
            if ($next instanceof \Exception) throw $next;
            return new \FixtureResponse(200, []);
        }
    }
}
namespace GuzzleHttp\Exception {
    class RequestException extends \Exception {
        private $response;
        function __construct($response) { parent::__construct('fixture refusal'); $this->response = $response; }
        function getResponse() { return $this->response; }
    }
    class ConnectException extends \Exception {}
}
namespace {
    define('MW_PATH', __DIR__);
    $cli = true; $now = 1800000000; $requests = []; $responses = []; $sleeps = 0; $onSleep = null;
    $runtime = sys_get_temp_dir() . '/magic-short-retry-' . bin2hex(random_bytes(8)); mkdir($runtime, 0700);
    function is_cli() { return $GLOBALS['cli']; }
    function hooks() { return new class { function applyFilters($n, $p, $s) { $GLOBALS['filters']++; if(!empty($GLOBALS['removeIdentity']))unset($p['subscriberUid']); return $p; } function doAction(...$v) {} }; }
    class Yii { static function getPathOfAlias($v) { return $GLOBALS['runtime']; } }
    class ArrayHelper { static function hasKeys($v, $keys) { return !array_diff($keys, array_keys($v)); } }
    class FixtureResponse {
        private $status, $data, $header;
        function __construct($status, $data, $header = '2') { $this->status = $status; $this->data = $data; $this->header = $header; }
        function getStatusCode() { return $this->status; }
        function getBody() { return json_encode($this->data); }
        function getHeaderLine($v) { return $this->header; }
    }
    class DeliveryServer {
        public $hostname = 'https://fixture.invalid', $password = 'synthetic-key', $timeout = 5, $usage = 0, $mailer;
        function __construct() { $this->mailer = new class {
            public $compiled = 0, $logs = [];
            function findEmailAndName($v) { return [$v, 'Fixture']; }
            function getEmailMessage($v) { return 'MIME-generation-' . ++$this->compiled; }
            function getEmailMessageId() { return '<stable@fixture.invalid>'; }
            function addLog($v) { $this->logs[] = $v; }
        }; }
        function getParamsArray($p) { return $p; }
        function getMailer() { return $this->mailer; }
        function logUsage() { $this->usage++; }
    }
    require dirname(__DIR__) . '/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
    class TestServer extends DeliveryServerMagicSmtpWebApi {
        public $guard;
        function createCooldown(): MagicSmtpCooldown { return cooldown($this->password); }
        function createShortRetry(): MagicSmtpShortRetry {
            return new MagicSmtpShortRetry($this->guard, static function () { return (float)$GLOBALS['now']; }, static function () {
                $GLOBALS['now']++; $GLOBALS['sleeps']++;
                if ($GLOBALS['onSleep']) ($GLOBALS['onSleep'])();
            });
        }
    }
    function cooldown($key = 'synthetic-key') { return new MagicSmtpCooldown($GLOBALS['runtime'], 'https://fixture.invalid', $key, static function () { return $GLOBALS['now']; }, static function () { return 0; }); }
    function resetFixture() {
        foreach (glob($GLOBALS['runtime'] . '/*') as $p) unlink($p);
        $GLOBALS['requests'] = []; $GLOBALS['responses'] = []; $GLOBALS['sleeps'] = 0; $GLOBALS['filters'] = 0; $GLOBALS['onSleep'] = null; $GLOBALS['cli'] = true; $GLOBALS['removeIdentity'] = false;
        $server = new TestServer(); $server->guard = static function () {}; $server->setShortRetryGuard($server->guard); return $server;
    }
    function busy($change = [], $status = 429, $header = '2') { return new \GuzzleHttp\Exception\RequestException(new FixtureResponse($status, array_merge(['error'=>'memory_admission_limited', 'reason'=>'recovery_inflight', 'retryable'=>true, 'retryAfter'=>2], $change), $header)); }
    function check($v, $name) { if (!$v) throw new \RuntimeException($name); echo "PASS $name\n"; }
    function expectCode($server, $params, $code) { try { $server->send($params); } catch (\Exception $e) { check($e->getCode() === $code, 'safe worker code ' . $code); return; } throw new \RuntimeException('Expected exception'); }
    $params = ['from'=>'sender@fixture.invalid','to'=>'recipient@fixture.invalid','subject'=>'Synthetic','body'=>'Synthetic','campaignUid'=>'campaign-test','subscriberUid'=>'subscriber-test'];
    try {
        $server = resetFixture(); $responses = [busy(), null]; $result = $server->send($params);
        check($result === ['message_id'=>'stable@fixture.invalid'] && count($requests) === 2 && $sleeps === 2, 'short refusal recovers inside same worker');
        check($requests[0] === $requests[1] && $server->mailer->compiled === 1 && $filters === 1 && $server->usage === 1, 'exact MIME recipient key credentials and hooks preserved; usage once');
        $server = resetFixture(); $responses=[busy(),null]; $onSleep=static function()use($server){$server->hostname='https://changed.invalid';$server->password='changed-synthetic-key';}; $server->send($params);
        check($requests[0]===$requests[1],'endpoint and credentials stay frozen even if the server object changes during wait');
        $server = resetFixture(); cooldown()->defer('2', 2, ['error'=>'memory_admission_limited','reason'=>'recovery_inflight','retryable'=>true],429); $responses=[null]; $server->send($params);
        check($sleeps === 2 && count($requests) === 1, 'sibling waits for proven short shared cooldown before first request');
        $server = resetFixture(); $responses=array_fill(0,4,busy()); expectCode($server,$params,99);
        check(count($requests) === 4 && $sleeps === 6 && $server->usage === 0, 'at most three retries then code99 without false success');
        foreach ([busy(['reason'=>'memory_cutoff']),busy(['reason'=>'recovery_rate_limited']),busy(['reason'=>'unknown']),busy(['retryable'=>1]),busy(['accepted'=>1]),busy([],503),busy([],429,'60'),'timeout'] as $failure) {
            $server=resetFixture(); $responses=[$failure]; expectCode($server,$params,99); check(count($requests)===1 && $sleeps===0, 'long unknown accepted contradictory and ambiguous failures are never retried');
        }
        $server=resetFixture(); $responses=[busy(),'timeout']; expectCode($server,$params,99); check(count($requests)===2 && $sleeps===2, 'timeout after refusal is never followed by another request');
        foreach(['timeout'=>2,'cap'=>4] as $mode=>$requestCount){
            $server=resetFixture();$responses=$mode==='timeout'?[busy(),'timeout']:array_fill(0,4,busy());
            $server->guard=static function()use($requestCount){if(count($GLOBALS['requests'])>=$requestCount)throw new \Exception('paused during final request',98);};$server->setShortRetryGuard($server->guard);
            expectCode($server,$params,98);check(count($requests)===$requestCount,'pause during final '.$mode.' preserves98 without another POST');
        }
        $server=resetFixture(); cooldown()->defer('60',60); $now+=58; $responses=[null]; expectCode($server,$params,99); check(!$requests && !$sleeps,'near-expiry long pause cannot masquerade as short cooldown');
        $server=resetFixture(); $responses=[busy(),null]; $onSleep=static function(){ cooldown()->defer('60',60); }; expectCode($server,$params,99); check(count($requests)===1 && $sleeps===1,'concurrent longer pressure pause aborts wait');
        $server=resetFixture(); $responses=[busy(),null]; $server->guard=static function(){if($GLOBALS['sleeps'])throw new \Exception('paused',98);}; $server->setShortRetryGuard($server->guard); expectCode($server,$params,98); check(count($requests)===1,'pause or suppression during wait prevents resubmission');
        foreach(['long','deadline','storage'] as $change){
            $server=resetFixture();$responses=[busy(),null];$server->guard=static function(){if($GLOBALS['sleeps'])throw new \Exception('paused',98);};$server->setShortRetryGuard($server->guard);
            $onSleep=static function()use($change){if($change==='long')cooldown()->defer('60',60);elseif($change==='deadline')$GLOBALS['now']+=15;else file_put_contents(glob($GLOBALS['runtime'].'/*')[0],'invalid');};
            expectCode($server,$params,98);check(count($requests)===1,'pause wins over concurrent '.$change.' abort and cannot resume campaign');
        }
        $server=resetFixture(); $responses=[busy(),null]; $onSleep=static function(){ $GLOBALS['now']+=15; }; expectCode($server,$params,99); check(count($requests)===1,'wall-clock budget bounds scheduling delays');
        $server=resetFixture(); $responses=[busy(),null]; $server->guard=static function(){if($GLOBALS['sleeps']>=2)$GLOBALS['now']+=15;}; $server->setShortRetryGuard($server->guard); expectCode($server,$params,99); check(count($requests)===1,'slow eligibility guard cannot start a retry beyond the monotonic deadline');
        $server=resetFixture(); $cli=false; $responses=[busy()]; check($server->send($params)===[] && count($requests)===1 && !$sleeps,'interactive sends never enter worker wait');
        $server=resetFixture(); $removeIdentity=true; $responses=[busy()]; expectCode($server,$params,99); check(count($requests)===1 && !$sleeps,'hook removing recipient identity cannot enable unkeyed retry');
        $server=resetFixture(); unset($params['subscriberUid']); $responses=[busy()]; expectCode($server,$params,99); check(count($requests)===1 && !$sleeps,'missing stable recipient identity cannot enable retry');
        $server=resetFixture(); $busy=['error'=>'memory_admission_limited','reason'=>'recovery_inflight','retryable'=>true]; cooldown('existing-demo-isolated')->defer('2',2,$busy,429);
        check(cooldown('new-demo-isolated')->remaining()===0 && cooldown()->remaining()===0,'synthetic existing new demo and normal tenant cooldown isolation');
        echo "PASS all HTTP is synthetic; no demo account or production state changed\n";
    } finally { foreach(glob($runtime.'/*') as $p) unlink($p); rmdir($runtime); }
}
