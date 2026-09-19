<?php declare(strict_types=1);

namespace GuzzleHttp {
    class Client
    {
        public static $mode = 'success';
        public $lastUrl;
        public $lastOptions = array();

        public function post($url, array $options)
        {
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
    function is_cli() { return true; }
    class Yii { static function getPathOfAlias($alias) { return $GLOBALS['runtime']; } }


    class FakeMailer
    {
        public $log = '';

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
            return "From: Sender <sender@example.test>\r\nTo: Recipient <recipient@example.test>\r\nMessage-ID: <mailwizz-id@example.test>\r\n\r\n" . $params['body'];
        }

        public function getEmailMessageId()
        {
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
