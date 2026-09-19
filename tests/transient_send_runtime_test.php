<?php declare(strict_types=1);
namespace GuzzleHttp {
    class Client {
        public function __construct($options) {}
        public function post($url, $options) {
            $GLOBALS['requests'][] = $options;
            if ($GLOBALS['mode'] === 'connect') throw new \GuzzleHttp\Exception\ConnectException('timeout');
            if ($GLOBALS['mode'] === 'refuse') throw new \GuzzleHttp\Exception\RequestException('refused');
            return new \FixtureResponse(200);
        }
    }
}
namespace GuzzleHttp\Exception {
    class RequestException extends \Exception { public function getResponse() { return new \FixtureResponse(429); } }
    class ConnectException extends \Exception {}
}
namespace {
    define('MW_PATH', __DIR__);
    $cli = true; $mode = 'refuse'; $requests = [];
    $runtime = sys_get_temp_dir() . '/magic-send-fixture-' . bin2hex(random_bytes(8)); mkdir($runtime, 0700);
    function is_cli() { return $GLOBALS['cli']; }
    function hooks() { return new class { function applyFilters($name, $params, $server) { return $params; } function doAction(...$args) {} }; }
    class Yii { static function getPathOfAlias($alias) { return $GLOBALS['runtime']; } }
    class ArrayHelper { static function hasKeys($value, $keys) { return !array_diff($keys, array_keys($value)); } }
    class FixtureResponse {
        private $status;
        function __construct($status) { $this->status = $status; }
        function getStatusCode() { return $this->status; }
        function getBody() { return json_encode($GLOBALS['responseBody'] ?? ['retryable'=>true,'retryAfter'=>60,'error'=>'memory_admission_limited']); }
        function getHeaderLine($name) { return (string)($GLOBALS['responseBody']['retryAfter'] ?? 60); }
    }
    class DeliveryServer {
        public $hostname = 'https://fixture.invalid', $password = 'synthetic-key', $timeout = 5, $usage = 0, $mailer;
        function __construct() { $this->mailer = new class {
            public $logs = [];
            function findEmailAndName($v) { return [$v, 'Fixture']; }
            function getEmailMessage($v) { return 'Synthetic fixture only'; }
            function getEmailMessageId() { return '<fixture@fixture.invalid>'; }
            function addLog($v) { $this->logs[] = $v; }
        }; }
        function getMailer() { return $this->mailer; }
        function getParamsArray($p) { return $p; }
        function logUsage() { $this->usage++; }
    }
    require dirname(__DIR__) . '/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
    function verify($condition) { if (!$condition) throw new \RuntimeException('assertion_failed'); }
    function clearFixtureCooldown() { foreach (glob($GLOBALS['runtime'] . '/*') as $p) unlink($p); }
    $params = ['from'=>'a@fixture.invalid','to'=>'b@fixture.invalid','subject'=>'Fixture','body'=>'Synthetic','campaignUid'=>'fixture-campaign','subscriberUid'=>'fixture-subscriber'];
    try {
        $server = new DeliveryServerMagicSmtpWebApi();
        try { $server->send($params); throw new \RuntimeException('must_throw'); } catch (\Exception $e) { verify($e->getCode() === 99); }
        verify(count($requests) === 1 && $server->usage === 0);
        try { (new DeliveryServerMagicSmtpWebApi())->send($params); throw new \RuntimeException('must_throw'); } catch (\Exception $e) { verify($e->getCode() === 99); }
        verify(count($requests) === 1);
        $cli = false; verify($server->send($params) === []); verify(count($requests) === 1); verify(strpos(end($server->mailer->logs), 'temporarily delayed') !== false);
        clearFixtureCooldown(); verify($server->send($params) === []); verify(count($requests) === 2 && $server->usage === 0);
        foreach (['recovery_inflight'=>[2,4], 'memory_cutoff'=>[60,70], 'recovery_rate_limited'=>[60,70], 'unknown'=>[60,70]] as $reason=>$bounds) {
            clearFixtureCooldown(); $cli = true;
            $responseBody = ['retryable'=>true,'retryAfter'=>2,'error'=>'memory_admission_limited','reason'=>$reason];
            $start = time();
            try { $server->send($params); throw new \RuntimeException('must_throw'); } catch (\Exception $e) { verify($e->getCode() === 99); }
            $state = json_decode(file_get_contents(glob($runtime . '/*')[0]), true);
            verify($state['until'] >= $start + $bounds[0] && $state['until'] <= time() + $bounds[1]);
            verify($server->usage === 0);
        }
        unset($responseBody);
        clearFixtureCooldown(); $cli = true; $mode = 'connect';
        try { $server->send($params); throw new \RuntimeException('must_throw'); } catch (\Exception $e) { verify($e->getCode() === 99); }
        clearFixtureCooldown(); $mode = 'success'; verify($server->send($params)['message_id'] === 'fixture@fixture.invalid');
        verify($server->usage === 1);
        verify(count(array_unique(array_column(array_column($requests, 'headers'), 'Idempotency-Key'))) === 1);
        echo "PASS campaign code99, shared pre-request cooldown, interactive empty result/log instead of 500, connection retry, no false success, stable idempotency and resumed synthetic success\n";
    } finally { clearFixtureCooldown(); rmdir($runtime); }
}
