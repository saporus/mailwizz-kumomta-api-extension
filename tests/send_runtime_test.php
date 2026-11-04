<?php declare(strict_types=1);

namespace GuzzleHttp {
    class Client
    {
        public static $mode = 'success';
        public $lastUrl;
        public $lastOptions = array();
        public $calls = 0;

        public function post($url, array $options)
        {
            $this->calls++;
            $this->lastUrl = $url;
            $this->lastOptions = $options;
            if (self::$mode === 'retryable-503') {
                throw new \GuzzleHttp\Exception\RequestException(
                    'HTTP 503',
                    new Response(503, '{"error":"inject_rejected","retryable":true}')
                );
            }
            if (self::$mode === 'permanent-400') {
                throw new \GuzzleHttp\Exception\RequestException(
                    'HTTP 400',
                    new Response(400, '{"error":"invalid_request","retryable":false}')
                );
            }
            return new Response(200, 'OK');
        }
    }

    class Response
    {
        private $status;
        private $body;

        public function __construct($status, $body)
        {
            $this->status = $status;
            $this->body = $body;
        }

        public function getStatusCode()
        {
            return $this->status;
        }

        public function getHeaderLine($name) { return "60"; }

        public function getBody()
        {
            return $this->body;
        }
    }
}

namespace GuzzleHttp\Exception {
    class RequestException extends \Exception
    {
        private $response;

        public function __construct($message, $response = null)
        {
            parent::__construct($message);
            $this->response = $response;
        }

        public function getResponse()
        {
            return $this->response;
        }
    }
}

namespace {
    define('MW_PATH', __DIR__);
    $runtime = sys_get_temp_dir() . '/magic-release-send-' . bin2hex(random_bytes(8));
    mkdir($runtime, 0700);
    register_shutdown_function(static function () use ($runtime) {
        foreach (glob($runtime . '/*') as $file) unlink($file);
        rmdir($runtime);
    });
    $cli = true;
    $policyLookups = 0;
    function is_cli() { return $GLOBALS['cli']; }
    function app_param($key, $default = null) {
        $GLOBALS['policyLookups']++;
        return $default;
    }
    class Yii { static function getPathOfAlias($alias) { return $GLOBALS['runtime']; } }


    class FakeMailer
    {
        public $log = '';
        public $activeMailer = 'SwiftMailer';
        public $rawMessageOverride = null;
        public $renderError = null;
        public $compiled = 0;
        public $messageIdReads = 0;

        public function getActiveMailer() { return $this->activeMailer; }

        public function findEmailAndName($mailbox)
        {
            if (is_array($mailbox)) {
                $email = (string)array_key_first($mailbox);
                return array($email, (string)$mailbox[$email]);
            }
            return array((string)$mailbox, '');
        }

        public function getEmailMessage(array $params)
        {
            $this->compiled++;
            if ($this->renderError !== null) throw new \Exception($this->renderError);
            if ($this->rawMessageOverride !== null) return $this->rawMessageOverride;
            return "From: Sender <sender@example.test>\r\nTo: Recipient <recipient@example.test>\r\nMessage-ID: <mailwizz-id@example.test>\r\n\r\n" . $params['body'];
        }

        public function getEmailMessageId()
        {
            $this->messageIdReads++;
            return '<mailwizz-id@example.test>';
        }

        public function addLog($message)
        {
            $this->log .= (string)$message;
        }
    }

    class DeliveryServer
    {
        public $hostname = 'https://enterprise.example/ui/api/ingest/email';
        public $password = 'tenant-api-key';
        public $timeout = 30;
        public $usage = 0;
        protected $mailer;

        public function __construct()
        {
            $this->mailer = new FakeMailer();
        }

        public function getParamsArray(array $params)
        {
            return $params;
        }

        public function getMailer()
        {
            return $this->mailer;
        }

        public function logUsage()
        {
            $this->usage++;
        }
    }

    class ArrayHelper
    {
        public static function hasKeys(array $params, array $keys)
        {
            foreach ($keys as $key) {
                if (!array_key_exists($key, $params)) {
                    return false;
                }
            }
            return true;
        }
    }

    class HookStub
    {
        public function applyFilters($name, $params, $server)
        {
            return $params;
        }

        public function doAction($name, $params, $server, $sent)
        {
        }
    }

    function hooks()
    {
        static $hooks;
        if (!$hooks) {
            $hooks = new HookStub();
        }
        return $hooks;
    }

    require dirname(__DIR__) . '/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';

    class TestableSendDeliveryServer extends DeliveryServerMagicSmtpWebApi
    {
        public $fakeClient;

        public function __construct()
        {
            parent::__construct();
            $this->fakeClient = new \GuzzleHttp\Client();
        }

        public function getClient(): \GuzzleHttp\Client
        {
            return $this->fakeClient;
        }
    }

    $server = new TestableSendDeliveryServer();
    $result = $server->send(array(
        'from' => array('sender@example.test' => 'Sender'),
        'to' => array('recipient@example.test' => 'Recipient'),
        'subject' => 'Idempotency test',
        'body' => 'Test body',
        'campaignUid' => 'campaign-123',
        'subscriberUid' => 'subscriber-456',
    ));

    $options = $server->fakeClient->lastOptions;
    $expectedKey = 'mailwizz:send:v1:' . hash('sha256', "campaign-123\0subscriber-456");
    $checks = array(
        'send succeeds through the fake API' => $result === array('message_id' => 'mailwizz-id@example.test'),
        'tenant API key is preserved' => ($options['headers']['x-tenant-api-key'] ?? null) === 'tenant-api-key',
        'idempotency header is attached' => ($options['headers']['Idempotency-Key'] ?? null) === $expectedKey,
        'idempotency body fallback matches the header' => ($options['json']['IdempotencyKey'] ?? null) === $expectedKey,
        'campaign UID is forwarded' => ($options['json']['campaign'] ?? null) === 'campaign-123',
        'subscriber UID is forwarded as recipient metadata' => ($options['json']['recipients'][0]['metadata']['subscriber_uid'] ?? null) === 'subscriber-456',
    );

    $params = array(
        'from' => array('sender@example.test' => 'Sender'),
        'to' => array('recipient@example.test' => 'Recipient'),
        'subject' => 'Synthetic rendering checks',
        'body' => 'Synthetic body',
        'campaignUid' => 'campaign-123',
        'subscriberUid' => 'subscriber-456',
    );
    foreach (array(true, false) as $cli) {
        foreach (array('' => '', 'whitespace' => " \r\n\t") as $label => $raw) {
            $invalid = new TestableSendDeliveryServer();
            $invalid->getMailer()->rawMessageOverride = $raw;
            $policyLookups = 0;
            $result = $invalid->send($params);
            $checks[($cli ? 'CLI' : 'interactive') . ' rejects ' . ($label ?: 'empty') . ' MIME before dispatch'] =
                $result === array() && $invalid->fakeClient->calls === 0 && $invalid->usage === 0
                && $invalid->getMailer()->messageIdReads === 0 && $policyLookups === 0
                && strpos($invalid->getMailer()->log, 'empty MIME content') !== false;
        }
        foreach (array('', 'random dummy output') as $raw) {
            $dummy = new TestableSendDeliveryServer();
            $dummy->getMailer()->activeMailer = 'DummyMailer';
            $dummy->getMailer()->rawMessageOverride = $raw;
            $policyLookups = 0;
            $result = $dummy->send($params);
            $checks[($cli ? 'CLI' : 'interactive') . ' rejects simulation mailer output length ' . strlen($raw)] =
                $result === array() && $dummy->fakeClient->calls === 0 && $dummy->usage === 0
                && $dummy->getMailer()->compiled === 0 && $policyLookups === 0
                && strpos($dummy->getMailer()->log, 'simulation mailer') !== false;
        }
    }
    $cli = true;
    $mimeCases = array(
        'plain text zero' => "From: sender@example.test\r\nTo: recipient@example.test\r\nContent-Type: text/plain\r\n\r\n0",
        'Unicode HTML' => "From: sender@example.test\r\nContent-Type: text/html; charset=UTF-8\r\n\r\n<p>Héllo 世界</p>",
        'attachment multipart' => "From: sender@example.test\r\nContent-Type: multipart/mixed; boundary=fixture\r\n\r\n--fixture\r\nContent-Type: application/octet-stream\r\nContent-Disposition: attachment; filename=fixture.bin\r\nContent-Transfer-Encoding: base64\r\n\r\nAAECAw==\r\n--fixture--\r\n",
    );
    foreach (array('SwiftMailer', 'SymfonyMailer') as $mailerName) {
        foreach ($mimeCases as $label => $raw) {
            $valid = new TestableSendDeliveryServer();
            $valid->getMailer()->activeMailer = $mailerName;
            $valid->getMailer()->rawMessageOverride = $raw;
            $policyLookups = 0;
            $result = $valid->send($params);
            $checks[$mailerName . ' preserves ' . $label . ' byte for byte'] =
                $result === array('message_id' => 'mailwizz-id@example.test') && $valid->fakeClient->calls === 1
                && $valid->fakeClient->lastOptions['json']['content'] === $raw && $valid->usage === 1
                && $valid->getMailer()->compiled === 1 && $policyLookups > 0
                && $valid->fakeClient->lastOptions['headers']['Idempotency-Key'] === $expectedKey;
        }
    }
    $invalid = new TestableSendDeliveryServer();
    $invalid->getMailer()->renderError = 'Synthetic MIME rendering failure';
    $policyLookups = 0;
    $checks['renderer exceptions remain local failures without HTTP or dispatch'] =
        $invalid->send($params) === array() && $invalid->fakeClient->calls === 0 && $policyLookups === 0
        && $invalid->usage === 0 && $invalid->getMailer()->log === 'Synthetic MIME rendering failure';

    \GuzzleHttp\Client::$mode = 'retryable-503';
    $retryException = null;
    try {
        $server->send(array(
            'from' => array('sender@example.test' => 'Sender'),
            'to' => array('recipient@example.test' => 'Recipient'),
            'subject' => 'Retryable failure test',
            'body' => 'Test body',
            'campaignUid' => 'campaign-123',
            'subscriberUid' => 'subscriber-456',
        ));
    } catch (\Exception $e) {
        $retryException = $e;
    }
    $checks['retryable HTTP 503 propagates code 99'] = $retryException instanceof \Exception && $retryException->getCode() === 99;
    $checks['retryable failure retains a useful message'] = $retryException instanceof \Exception && strpos($retryException->getMessage(), 'inject_rejected') !== false;

    foreach (glob($runtime . '/*') as $file) unlink($file);
    \GuzzleHttp\Client::$mode = 'permanent-400';
    $permanentResult = $server->send(array(
        'from' => array('sender@example.test' => 'Sender'),
        'to' => array('recipient@example.test' => 'Recipient'),
        'subject' => 'Permanent failure test',
        'body' => 'Test body',
        'campaignUid' => 'campaign-123',
        'subscriberUid' => 'subscriber-456',
    ));
    $checks['permanent HTTP 400 remains a normal failed result'] = $permanentResult === array();

    $failed = array();
    foreach ($checks as $description => $passed) {
        echo ($passed ? '[PASS] ' : '[FAIL] ') . $description . PHP_EOL;
        if (!$passed) {
            $failed[] = $description;
        }
    }

    if ($failed) {
        fwrite(STDERR, PHP_EOL . count($failed) . ' send runtime check(s) failed.' . PHP_EOL);
        exit(1);
    }

    echo PHP_EOL . 'All send runtime checks passed.' . PHP_EOL;
}
