<?php declare(strict_types=1);
/** Actual MCA hooks and MagicSMTP send method, synthetic mailer/HTTP only. */
namespace GuzzleHttp {
    class Client
    {
        public $requests = [];
        public function post($url, array $options)
        {
            $this->requests[] = ['url' => $url, 'options' => $options];
            return new class {
                public function getStatusCode() { return 200; }
                public function getBody() { return 'OK'; }
                public function getHeaderLine($name) { return ''; }
            };
        }
    }
}
namespace GuzzleHttp\Exception {
    class RequestException extends \Exception {}
    class ConnectException extends \Exception {}
}
namespace {
    define('MW_PATH', __DIR__);
    $runtime = sys_get_temp_dir().'/magic-content-cross-'.bin2hex(random_bytes(8));
    mkdir($runtime, 0700);
    register_shutdown_function(static function() use ($runtime): void {
        foreach (glob($runtime.'/*') as $file) unlink($file);
        rmdir($runtime);
    });
    putenv('MAGIC_CONTENT_ASSISTANT_SPINTAX_SECRET='.str_repeat('ab', 32));
    $cli = true;
    $policyLookups = 0;
    function is_cli() { return $GLOBALS['cli']; }
    function app_param($key, $default = null) { $GLOBALS['policyLookups']++; return $default; }
    class CLogger { const LEVEL_ERROR = 'error'; }
    class Yii
    {
        public static function getPathOfAlias($alias) { return $GLOBALS['runtime']; }
        public static function log($message, $level = 'info', $category = '') {}
        public static function app()
        {
            return (object)['extensionsManager' => new class {
                public function getExtensionInstance($name) { return new class {
                    public function getOption($key, $default = 'yes') { return $default; }
                }; }
            }];
        }
    }
    class CampaignHelper
    {
        const MAGIC_CONTENT_ASSISTANT_PERSONALIZATION_BRIDGE = 'synthetic-contract-fixture';
        public static function getTagFilter() { return new class {
            public function apply($value, $replace) { return $value; }
        }; }
    }
    class CrossMailer
    {
        public $activeMailer = 'SwiftMailer';
        public $empty = false;
        public $compiled = 0;
        public $messageIdReads = 0;
        public $logs = [];
        public function getActiveMailer() { return $this->activeMailer; }
        public function findEmailAndName($mailbox) { return [(string)array_key_first($mailbox), (string)reset($mailbox)]; }
        public function getEmailMessage(array $params)
        {
            $this->compiled++;
            if ($this->empty) return " \r\n\t";
            return "From: sender@example.invalid\r\nTo: recipient@example.invalid\r\nSubject: ".$params['subject']
                ."\r\nMessage-ID: <synthetic-cross@example.invalid>\r\n\r\n".$params['body']."\n--plain--\n".$params['plainText'];
        }
        public function getEmailMessageId() { $this->messageIdReads++; return '<synthetic-cross@example.invalid>'; }
        public function addLog($message) { $this->logs[] = (string)$message; }
    }
    class DeliveryServer
    {
        public $hostname = 'https://synthetic-gateway.example.invalid/ui/api/ingest/email';
        public $password = 'synthetic-key';
        public $timeout = 30;
        public $usage = 0;
        protected $mailer;
        public function __construct() { $this->mailer = new CrossMailer; }
        public function getParamsArray(array $params) { return $params; }
        public function getMailer() { return $this->mailer; }
        public function logUsage() { $this->usage++; }
    }
    class ArrayHelper
    {
        public static function hasKeys(array $params, array $keys) { return count(array_diff($keys, array_keys($params))) === 0; }
    }
    class CrossHooks
    {
        public $calls = 0;
        public function applyFilters($name, $params, $server)
        {
            if ($name !== 'delivery_server_before_send_email') throw new \RuntimeException('Unexpected native filter');
            $this->calls++;
            return \MagicContentAssistantDeliveryHook::processEmail($params, $server);
        }
        public function doAction($name, $params, $server, $sent) {}
    }
    function hooks() { static $hooks; return $hooks ?? ($hooks = new CrossHooks); }
    $mca = getenv('MCA_EXTENSION_ROOT') ?: dirname(__DIR__, 3).'/kumo-samples/magic-content-assistant';
    foreach (['MagicContentAssistantParser', 'MagicContentAssistantCryptoService', 'MagicContentAssistantFrameRenderer', 'MagicContentAssistantDeliveryHook'] as $name) {
        require_once $mca.'/services/'.$name.'.php';
    }
    require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
    class CrossServer extends DeliveryServerMagicSmtpWebApi
    {
        public $client;
        public function __construct() { parent::__construct(); $this->client = new \GuzzleHttp\Client; }
        public function getClient(): \GuzzleHttp\Client { return $this->client; }
    }
    $checks = 0;
    function crossCheck(bool $value, string $label): void
    {
        global $checks;
        if (!$value) throw new \RuntimeException($label);
        $checks++;
    }
    function crossPrepare(CrossServer $server, string $business): array
    {
        $campaign = (object)['campaign_uid' => 'synthetic-campaign', 'template' => (object)['auto_plain_text' => 'no', 'only_plain_text' => 'no']];
        $subscriber = (object)['subscriber_uid' => 'synthetic-subscriber', 'email' => 'recipient@example.invalid'];
        $replace = ['[BUSINESS_NAME]' => $business, '[FNAME]' => 'Synthetic Zoë', '[UNSUBSCRIBE_URL]' => 'https://example.invalid/unsubscribe'];
        $subject = '[SPINTAX: An update for [BUSINESS_NAME] | News for [BUSINESS_NAME]]';
        $body = '<p>[SPINTAX: Hello [FNAME] at [BUSINESS_NAME] | Greetings [FNAME] at [BUSINESS_NAME]]</p><a href="[UNSUBSCRIBE_URL]">Unsubscribe</a>';
        $plain = '[SPINTAX: Hello [BUSINESS_NAME] | Greetings [BUSINESS_NAME]] [UNSUBSCRIBE_URL]';
        MagicContentAssistantDeliveryHook::beginPersonalization(null, $campaign, $subscriber, $server);
        $subject = MagicContentAssistantDeliveryHook::beforeSubjectPersonalization($subject, $campaign, $subscriber, $server, $replace);
        $body = MagicContentAssistantDeliveryHook::beforePersonalization($body, $campaign, $subscriber, $server, 'body', $replace);
        $plain = MagicContentAssistantDeliveryHook::beforePersonalization($plain, $campaign, $subscriber, $server, 'plain_text', $replace);
        $subject = str_replace(array_keys($replace), array_values($replace), $subject);
        $body = str_replace(array_keys($replace), array_values($replace), $body);
        $plain = str_replace(array_keys($replace), array_values($replace), $plain);
        $body = MagicContentAssistantDeliveryHook::beforePersonalization($body, $campaign, $subscriber, $server, 'body', $replace, true);
        $params = ['from' => ['sender@example.invalid' => 'Synthetic sender'], 'to' => ['recipient@example.invalid' => 'Synthetic recipient'],
            'subject' => $subject, 'body' => $body, 'plainText' => $plain, 'campaignUid' => $campaign->campaign_uid,
            'subscriberUid' => $subscriber->subscriber_uid];
        return MagicContentAssistantDeliveryHook::afterPrepareEmail($params, $campaign, $subscriber, $server);
    }
    $businessValues = ['Acme | Retail [Group] \\ QA', 'Literal [SPINTAX: keep | this] as data',
        'Literal unmatched ] [SPINTAX: marker | remains data', 'Unicode Zoë 公司 | [Team] \\'];
    foreach ([true, false] as $cli) {
        foreach ($businessValues as $business) {
            $server = new CrossServer;
            $firstParams = crossPrepare($server, $business);
            $firstResult = $server->send($firstParams);
            $first = $server->client->requests[0]['options'];
            $secondResult = $server->send(crossPrepare($server, $business));
            $second = $server->client->requests[1]['options'];
            crossCheck($firstResult === $secondResult && isset($firstResult['message_id']), 'both synthetic attempts complete');
            crossCheck($first['json']['content'] === $second['json']['content'], 'same campaign/subscriber retains byte-identical MIME');
            crossCheck($first['headers']['Idempotency-Key'] === $second['headers']['Idempotency-Key'], 'same idempotency identity retained');
            crossCheck(substr_count($first['json']['content'], $business) === 3, 'literal custom field preserved in subject HTML and manual text');
            crossCheck(strpos($first['json']['content'], '__MCAF') === false, 'private framing tokens never reach MIME');
            crossCheck(strpos($first['json']['content'], '[BUSINESS_NAME]') === false
                && strpos($first['json']['content'], 'https://example.invalid/unsubscribe') !== false, 'native replacements and unsubscribe survive');
            crossCheck(count($server->client->requests) === 2 && $server->usage === 2, 'one synthetic HTTP call per explicit attempt');
        }
        $server = new CrossServer;
        $params = crossPrepare($server, 'Synthetic company');
        $params['body'] .= '[SPINTAX: changed | late]';
        $policyLookups = 0;
        try { $server->send($params); throw new \RuntimeException('Expected modified preparation refusal'); }
        catch (\Exception $error) { crossCheck(strpos($error->getMessage(), 'changed after preparation') !== false, 'late content change refused'); }
        crossCheck(!$server->client->requests && $server->getMailer()->compiled === 0 && $policyLookups === 0, 'late-change refusal precedes MIME policy proof and HTTP');
        $server = new CrossServer;
        MagicContentAssistantDeliveryHook::beginPersonalization();
        $params['subject'] = 'Synthetic'; $params['body'] = '[SPINTAX: only one]'; $params['plainText'] = '';
        try { $server->send($params); throw new \RuntimeException('Expected malformed authored content refusal'); }
        catch (\Exception $error) { crossCheck(strpos($error->getMessage(), 'rendered safely') !== false, 'malformed authored content remains fail-closed'); }
        crossCheck(!$server->client->requests && $server->getMailer()->compiled === 0, 'malformed content makes zero HTTP calls');
        foreach (['DummyMailer', 'empty'] as $mode) {
            $server = new CrossServer;
            $params = crossPrepare($server, 'Acme | Retail [Group] \\ QA');
            if ($mode === 'DummyMailer') $server->getMailer()->activeMailer = 'DummyMailer';
            else $server->getMailer()->empty = true;
            $policyLookups = 0;
            crossCheck($server->send($params) === [] && !$server->client->requests && $server->usage === 0
                && $server->getMailer()->messageIdReads === 0 && $policyLookups === 0, 'transport guard rejects '.$mode.' after MCA preparation');
        }
    }
    echo json_encode(['ok' => true, 'checks' => $checks, 'actualMcaAndMagicSmtpClasses' => true,
        'nativePersonalizationEmulated' => true, 'nativeMailerAndCoreVerified' => false,
        'externalRequests' => 0, 'productionDataAccess' => 0], JSON_PRETTY_PRINT)."\n";
}
