<?php declare(strict_types=1);
if (!defined('MW_PATH')) {
    exit('No direct script access allowed');
}

/**
 * DeliveryServerMagicSmtpWebApi - Magic SMTP Web API delivery server for MailWizz.
 *
 * Facilitates campaign dispatches via KumoMTA's HTTP API injection endpoint.
 *
 * Author: Omni Knoweth
 * Installation: Place in apps/common/models/ and register in DeliveryServer::getTypesMapping().
 */
require_once __DIR__ . '/MagicSmtpCooldown.php';
require_once dirname(__DIR__) . '/MagicSmtpPolicyRuntime.php';

class DeliveryServerMagicSmtpWebApi extends DeliveryServer
{
    /** API bounces and complaints arrive through the registered DSWH webhook. */
    public function getBounceServerNotSupported(): bool
    {
        return true;
    }

    /**
     * @var string egress pool override
     */
    public $egress_pool;

    /**
     * @var int disable SSL verification (0 = No, 1 = Yes)
     */
    public $disable_ssl = 0;

    /**
     * @var string
     */
    protected $serverType = 'magic-smtp-web-api';

    /**
     * Internal type identifier
     * @var string
     */
    protected $_type = 'magic-smtp-web-api';

    /**
     * Required by Yii's ActiveRecord pattern.
     *
     * @param string $className
     * @return DeliveryServerMagicSmtpWebApi
     */
    public static function model($className = __CLASS__)
    {
        return parent::model($className);
    }

    /**
     * @return string the delivery server type identifier
     */
    public function getType()
    {
        return $this->_type;
    }

    /**
     * Some MailWizz versions call getServerType() instead of getType().
     * @return string
     */
    public function getServerType()
    {
        return $this->_type;
    }

    /**
     * Display name shown in dropdown lists and admin tables.
     * @return string
     */
    public static function getName()
    {
        return Yii::t('delivery_servers', 'Magic SMTP Web API');
    }

    /**
     * Help/description text shown on the delivery server form page.
     * Appends Magic SMTP Web API-specific webhook configuration instructions.
     */
    public function getHelpText()
    {
        $desc = parent::getHelpText();
        $callbackUrl = $this->getDswhUrl();

        $magicHelp  = '<div class="alert alert-info" style="margin-top: 15px;">';
        $magicHelp .= '<strong>Magic SMTP Web API Integration:</strong><br>';
        $magicHelp .= '1. Enter the Enterprise email injection endpoint (e.g. <code>https://your-enterprise-host.example/ui/api/ingest/email</code>) in the API URL field.<br>';
        $magicHelp .= '2. Paste your <strong>Tenant API Key</strong> (Kumo UI portal &rarr; API Keys, starts with <code>magicsmtp_tp_</code>) into the API Key field. No Kumo username/password needed.<br>';
        $magicHelp .= '3. Optionally specify an Egress Pool name to override routing.<br>';
        $magicHelp .= '4. In Kumo UI Tenant Portal, configure a Webhook pointing to the URL below:<br>';
        $magicHelp .= '<code>' . CHtml::encode($callbackUrl) . '</code><br>';
        $magicHelp .= '5. Ensure you select the <strong>Kumo (Default)</strong> payload format when creating the webhook.';
        $magicHelp .= '</div>';

        return $desc . $magicHelp;
    }

    /**
     * Rules for validation.
     *
     * @return array
     */
    public function rules()
    {
        $rules = [
            ['hostname', 'required'],
            ['username, password, egress_pool, disable_ssl', 'safe'],
            ['disable_ssl', 'in', 'range' => [0, 1]],
        ];
        return CMap::mergeArray($rules, parent::rules());
    }

    /**
     * Attribute labels.
     *
     * @return array
     */
    public function attributeLabels()
    {
        $labels = [
            'hostname'     => Yii::t('servers', 'API URL'),
            'username'     => Yii::t('servers', 'API Username (optional / unused)'),
            'password'     => Yii::t('servers', 'Tenant API Key'),
            'egress_pool'  => Yii::t('servers', 'Egress Pool Override'),
            'disable_ssl'  => Yii::t('servers', 'Disable SSL Verification'),
        ];
        return CMap::mergeArray(parent::attributeLabels(), $labels);
    }

    /**
     * Attribute help texts.
     *
     * @return array
     */
    public function attributeHelpTexts()
    {
        $texts = [
            'hostname'     => Yii::t('servers', 'The Enterprise email injection endpoint (e.g. https://your-enterprise-host.example/ui/api/ingest/email).'),
            'username'     => Yii::t('servers', 'Not required with API-key auth. The tenant is resolved from the API key.'),
            'password'     => Yii::t('servers', 'Your Tenant API Key from the Kumo UI portal (starts with magicsmtp_tp_). This is the only credential required.'),
            'egress_pool'  => Yii::t('servers', 'Optional. Specify a static egress pool name to override default routing (e.g., pool-1). Leave empty to use default routing.'),
            'disable_ssl'  => Yii::t('servers', 'Select Yes to disable SSL certificate verification (useful for self-signed certificates or local testing over HTTP).'),
        ];
        return CMap::mergeArray(parent::attributeHelpTexts(), $texts);
    }

    /**
     * Attribute placeholders.
     *
     * @return array
     */
    public function attributePlaceholders()
    {
        $placeholders = [
            'hostname'     => 'https://your-enterprise-host.example/ui/api/ingest/email',
            'username'     => 'not required',
            'password'     => 'magicsmtp_tp_... (tenant API key)',
            'egress_pool'  => 'e.g. pool-1',
        ];
        return CMap::mergeArray(parent::attributePlaceholders(), $placeholders);
    }

    /**
     * Options list for Disable SSL Verification.
     *
     * @return array
     */
    public function getDisableSslOptions(): array
    {
        return [
            0 => Yii::t('app', 'No'),
            1 => Yii::t('app', 'Yes'),
        ];
    }

    /**
     * Save custom options to meta_data.
     */
    protected function beforeSave()
    {
        $this->modelMetaData->getModelMetaData()->add('egress_pool', (string)$this->egress_pool);
        $this->modelMetaData->getModelMetaData()->add('disable_ssl', (int)$this->disable_ssl);
        return parent::beforeSave();
    }

    /**
     * Load custom options from meta_data.
     */
    protected function afterConstruct()
    {
        parent::afterConstruct();
        $this->egress_pool = (string)$this->modelMetaData->getModelMetaData()->itemAt('egress_pool');
        $this->disable_ssl = (int)$this->modelMetaData->getModelMetaData()->itemAt('disable_ssl');
    }

    /**
     * Load custom options from meta_data after find.
     */
    protected function afterFind()
    {
        $this->egress_pool = (string)$this->modelMetaData->getModelMetaData()->itemAt('egress_pool');
        $this->disable_ssl = (int)$this->modelMetaData->getModelMetaData()->itemAt('disable_ssl');
        parent::afterFind();
    }

    /**
     * Configure form fields layout dynamically.
     *
     * @param array $fields
     * @return array
     */
    public function getFormFieldsDefinition(array $fields = []): array
    {
        $form = new CActiveForm();
        $fields = parent::getFormFieldsDefinition(CMap::mergeArray([
            'port'                    => null,
            'protocol'                => null,
            'signing_enabled'         => null,
            'max_connection_messages' => null,
            'bounce_server_id'        => null,
            'force_sender'            => null,
        ], $fields));

        $newFields = [];
        foreach ($fields as $id => $definition) {
            $newFields[$id] = $definition;
            if ($id === 'password') {
                $newFields['egress_pool'] = [
                    'visible'   => true,
                    'fieldHtml' => $form->textField($this, 'egress_pool', $this->fieldDecorator->getHtmlOptions('egress_pool')),
                ];
                $newFields['disable_ssl'] = [
                    'visible'   => true,
                    'fieldHtml' => $form->dropDownList($this, 'disable_ssl', $this->getDisableSslOptions(), $this->fieldDecorator->getHtmlOptions('disable_ssl')),
                ];
            }
        }
        return $newFields;
    }

    /**
     * Instantiate Guzzle HTTP client with appropriate configurations.
     *
     * @return GuzzleHttp\Client
     */
    public function getClient(): GuzzleHttp\Client
    {
        static $client;
        if ($client !== null) {
            return $client;
        }

        $config = [
            'timeout' => (int)$this->timeout,
        ];

        if ((int)$this->disable_ssl === 1) {
            $config['verify'] = false;
        }

        return $client = new GuzzleHttp\Client($config);
    }

    /**
     * Build a stable, opaque idempotency key for a campaign/subscriber send.
     *
     * MailWizz can execute the same recipient again after an ambiguous HTTP
     * timeout. The Enterprise API scopes this key to the tenant and retains
     * the first result, preventing a retry from injecting a duplicate message.
     * Direct/test sends without both identifiers intentionally remain
     * non-idempotent because they do not represent a campaign recipient.
     *
     * @param array $params
     * @return string
     */
    protected function buildIdempotencyKey(array $params): string
    {
        $campaignUid = '';
        if (!empty($params['campaignUid'])) {
            $campaignUid = trim((string)$params['campaignUid']);
        } elseif (isset($params['campaign']) && is_object($params['campaign']) && !empty($params['campaign']->campaign_uid)) {
            $campaignUid = trim((string)$params['campaign']->campaign_uid);
        }

        $subscriberUid = !empty($params['subscriberUid']) ? trim((string)$params['subscriberUid']) : '';
        if ($campaignUid === '' || $subscriberUid === '') {
            return '';
        }

        return 'mailwizz:send:v1:' . hash('sha256', $campaignUid . "\0" . $subscriberUid);
    }

    /**
     * Sends the email campaign via KumoMTA injection HTTP API.
     *
     * @param array $params
     * @return array
     */
    protected function temporaryAdmissionFailure(string $message): array
    {
        // Campaign workers require code 99 to retain the recipient in the queue.
        if (is_cli()) {
            throw new Exception($message, 99);
        }
        // Interactive send forms already render mailer logs on an empty result.
        $this->getMailer()->addLog($message);
        return [];
    }

    public function send(array $params = []): array
    {
        $cooldown = new MagicSmtpCooldown(Yii::getPathOfAlias('common.runtime'), (string)$this->hostname, (string)$this->password);
        try { $wait = $cooldown->remaining(); }
        catch (Throwable $e) { return $this->temporaryAdmissionFailure('Temporary sending delay: retry coordination is unavailable. Please try again in one minute.'); }
        if ($wait > 0) {
            return $this->temporaryAdmissionFailure(sprintf('Sending is temporarily delayed. Retry in %d seconds; campaign recipients remain queued.', $wait));
        }

        $params = (array)hooks()->applyFilters('delivery_server_before_send_email', $this->getParamsArray($params), $this);

        if (!ArrayHelper::hasKeys($params, ['from', 'to', 'subject', 'body'])) {
            return [];
        }

        [$toEmail, $toName]     = $this->getMailer()->findEmailAndName($params['to']);
        [$fromEmail, $fromName] = $this->getMailer()->findEmailAndName($params['from']);

        $sent = [];

        try {
            // NOTE: tenant identity comes from the API key. The panel gateway forces the
            // trusted X-KumoMTA-Tenant tag server-side (and strips any client-supplied
            // one), so we no longer set it here.

            // Compile raw RFC822/MIME message
            $rawMessage = $this->getMailer()->getEmailMessage($params);

            // Fetch unique generated message ID
            $messageId  = $this->getMailer()->getEmailMessageId();

            // Prepare KumoMTA API injection payload (conforming to KumoMTA /api/inject/v1 schema)
            $payload = [
                'envelope_sender' => !empty($params['returnPath']) ? $params['returnPath'] : $fromEmail,
                'recipients'      => [
                    [
                        'email' => $toEmail,
                    ]
                ],
                'content'         => $rawMessage,
            ];

            $campaignUid = '';
            if (!empty($params['campaignUid'])) {
                $campaignUid = trim((string)$params['campaignUid']);
            } elseif (isset($params['campaign']) && is_object($params['campaign']) && !empty($params['campaign']->campaign_uid)) {
                $campaignUid = trim((string)$params['campaign']->campaign_uid);
            }
            if ($campaignUid !== '') {
                $payload['campaign'] = $campaignUid;
            }

            // We pass egress_pool and subscriber_uid in the recipient metadata
            // so they are available in Lua hooks via msg:get_meta('egress_pool') / msg:get_meta('subscriber_uid')
            $extra = [];
            if (!empty($this->egress_pool)) {
                $extra['egress_pool'] = $this->egress_pool;
            }
            if (!empty($params['subscriberUid'])) {
                $extra['subscriber_uid'] = $params['subscriberUid'];
            }
            if (!empty($extra)) {
                $payload['recipients'][0]['metadata'] = $extra;
            }

            $options = [
                'json' => $payload,
            ];

            $idempotencyKey = $this->buildIdempotencyKey($params);
            if ($idempotencyKey !== '') {
                // Send both forms supported by the Enterprise API. The opaque
                // hash avoids exposing MailWizz campaign/subscriber IDs.
                $options['headers']['Idempotency-Key'] = $idempotencyKey;
                $options['json']['IdempotencyKey'] = $idempotencyKey;
            }

            // Authenticate to the panel gateway with the tenant API key (Postmark-style):
            // no Kumo SMTP/Basic credentials needed -- the key identifies the tenant.
            if (!empty($this->password)) {
                $options['headers']['x-tenant-api-key'] = (string)$this->password;
            }

            // Durable, scoped proof precedes submission, including ambiguous HTTP outcomes.
            // Storage failure retains the campaign recipient through the retry bridge.
            try {
                MagicSmtpPolicyRuntime::recordDispatch($this, $params, $toEmail, $messageId);
            } catch (Throwable $policyFailure) {
                return $this->temporaryAdmissionFailure('Temporary sending delay: recipient policy dispatch coordination is unavailable.');
            }

            // Make HTTP POST call to KumoMTA endpoint
            $response = $this->getClient()->post($this->hostname, $options);

            if ($response->getStatusCode() === 200) {
                $this->getMailer()->addLog('OK');

                // Return clean, bracketless Message-ID for campaign logging
                $cleanId = str_replace(['<', '>'], '', $messageId);
                $sent = ['message_id' => $cleanId];
            } else {
                throw new Exception('Invalid response status code from KumoMTA: ' . $response->getStatusCode());
            }
        } catch (GuzzleHttp\Exception\RequestException | GuzzleHttp\Exception\ConnectException $e) {
            $response = method_exists($e, 'getResponse') ? $e->getResponse() : null;
            $statusCode = $response ? (int)$response->getStatusCode() : 0;
            $responseBody = $response ? (string)$response->getBody() : '';
            $responseData = json_decode($responseBody, true);
            $explicitRetryable = is_array($responseData) && !empty($responseData['retryable']);
            $transientStatus = $statusCode === 0 || in_array($statusCode, [408, 425, 429], true) || $statusCode >= 500;

            if ($explicitRetryable || $transientStatus) {
                $detail = is_array($responseData)
                    ? (string)($responseData['error'] ?? $responseData['detail'] ?? $e->getMessage())
                    : $e->getMessage();
                try {
                    $wait = $cooldown->defer($response ? $response->getHeaderLine('Retry-After') : '', is_array($responseData) ? ($responseData['retryAfter'] ?? null) : null, is_array($responseData) ? $responseData : null, $statusCode);
                } catch (Throwable $failure) { $wait = 60; }
                return $this->temporaryAdmissionFailure(sprintf('Sending is temporarily delayed (HTTP %d: %s). Retry in %d seconds; campaign recipients remain queued.', $statusCode, $detail, $wait));
            }

            $this->getMailer()->addLog($e->getMessage());
        } catch (Exception $e) {
            $this->getMailer()->addLog($e->getMessage());
        }

        if ($sent) {
            $this->logUsage();
        }

        hooks()->doAction('delivery_server_after_send_email', $params, $this, $sent);

        return (array)$sent;
    }

    /**
     * Webhook routing for bounces and complaints
     * @return string
     */
    public function getDswhUrl(): string
    {
        /** @var OptionUrl $optionUrl */
        $optionUrl = container()->get(OptionUrl::class);

        $url = $optionUrl->getFrontendUrl('dswh/' . (int)$this->server_id);
        if (is_cli()) {
            return $url;
        }
        if (request()->getIsSecureConnection() && parse_url($url, PHP_URL_SCHEME) == 'http') {
            $url = substr_replace($url, 'https', 0, 4);
        }
        return $url;
    }
}
