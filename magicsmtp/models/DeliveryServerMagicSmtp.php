<?php

/**
 * DeliveryServerMagicSmtp - Magic SMTP delivery server for MailWizz.
 *
 * Extends the standard SMTP server with KumoMTA-specific webhook processing
 * for bounce and complaint (FBL) handling.
 *
 * Author: Omni Knoweth
 * Installation: Place in apps/common/models/ and register in DeliveryServer::getTypesMapping().
 */
class DeliveryServerMagicSmtp extends DeliveryServerSmtp
{
    /**
     * @var string
     */
    protected $serverType = 'magic-smtp';

    // Internal type identifier — matches the key in getTypesMapping()
    protected $_type = 'magic-smtp';

    /**
     * Returns the model static instance.
     * Required by Yii's ActiveRecord pattern for static method chaining.
     *
     * @param string $className
     * @return DeliveryServerMagicSmtp
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
        return Yii::t('delivery_servers', 'Magic SMTP');
    }

    /**
     * Help/description text shown on the delivery server form page.
     * Appends Magic SMTP-specific webhook configuration instructions.
     */
    public function getHelpText()
    {
        $desc = parent::getHelpText();
        $callbackUrl = $this->getCallbackUrl();

        $magicHelp  = '<div class="alert alert-info" style="margin-top: 15px;">';
        $magicHelp .= '<strong>Magic SMTP Integration:</strong><br>';
        $magicHelp .= '1. Configure your SMTP settings above as you would for any standard KumoMTA tenant listener.<br>';
        $magicHelp .= '2. In Kumo UI Tenant Portal, configure a Webhook pointing to the URL below:<br>';
        $magicHelp .= '<code>' . CHtml::encode($callbackUrl) . '</code><br>';
        $magicHelp .= '3. Ensure you select the <strong>Kumo (Default)</strong> payload format when creating the webhook.';
        $magicHelp .= '</div>';

        return $desc . $magicHelp;
    }

    /**
     * Get the webhook callback URL for this server instance.
     * @return string
     */
    public function getCallbackUrl()
    {
        return Yii::app()->apps->getAppUrl(
            'frontend',
            'delivery-servers/callback/' . $this->getType(),
            true
        );
    }

    /**
     * Process incoming webhook callbacks from KumoMTA.
     *
     * Expects JSON payloads with structure:
     * {
     *   "event_type": "bounce|complaint",
     *   "event_id": "...",
     *   "data": {
     *     "recipient": "user@example.com",
     *     "bounce_type": "hard|soft",
     *     "response_code": 550,
     *     "response_text": "..."
     *   }
     * }
     *
     * @param CHttpRequest $request
     * @return void
     */
    public function handleCallback(CHttpRequest $request)
    {
        $rawBody = file_get_contents('php://input');
        if (empty($rawBody)) {
            $this->outputWebhookResponse(false, 'Empty payload');
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['event_type'])) {
            $this->outputWebhookResponse(false, 'Invalid payload format');
            return;
        }

        $eventType = strtolower(trim($payload['event_type']));
        $eventData = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

        if (empty($eventData['recipient'])) {
            $this->outputWebhookResponse(false, 'Missing recipient email address');
            return;
        }

        $recipient    = trim($eventData['recipient']);
        $messageId    = isset($payload['event_id']) ? $payload['event_id'] : '';
        if (isset($eventData['message_id']) && !empty($eventData['message_id'])) {
            $messageId = $eventData['message_id'];
        }
        $responseText = isset($eventData['response_text']) ? $eventData['response_text'] : 'Magic SMTP Webhook Callback';

        if ($eventType === 'bounce') {
            $bounceType = isset($eventData['bounce_type']) ? strtolower($eventData['bounce_type']) : 'hard';
            $code = isset($eventData['response_code']) ? (int)$eventData['response_code'] : 550;

            $this->processBounce(array(
                'email'       => $recipient,
                'message_id'  => $messageId,
                'bounce_type' => ($bounceType === 'soft')
                    ? CampaignBounceLog::BOUNCE_SOFT
                    : CampaignBounceLog::BOUNCE_HARD,
                'bounce_code' => $code,
                'bounce_raw'  => $responseText,
            ));

            $this->outputWebhookResponse(true, 'Bounce registered');
            return;
        }

        if ($eventType === 'complaint') {
            $this->processComplaint(array(
                'email'         => $recipient,
                'message_id'    => $messageId,
                'complaint_raw' => $responseText,
            ));

            $this->outputWebhookResponse(true, 'Complaint registered');
            return;
        }

        // Unrecognised event type — acknowledge but log
        $this->outputWebhookResponse(false, 'Unsupported event type: ' . $eventType);
    }

    /**
     * Get the webhook callback URL for this server type.
     * @return string
     */
    public function getDswhUrl(): string
    {
        /** @var OptionUrl $optionUrl */
        $optionUrl = container()->get(OptionUrl::class);

        $url = $optionUrl->getFrontendUrl('dswh/magicsmtp');
        if (is_cli()) {
            return $url;
        }
        if (request()->getIsSecureConnection() && parse_url($url, PHP_URL_SCHEME) == 'http') {
            $url = substr_replace($url, 'https', 0, 4);
        }
        return $url;
    }

    /**
     * Process bounce event by message ID
     * @param array $params
     * @return bool
     */
    public function processBounce(array $params)
    {
        $messageId = str_replace(['<', '>'], '', $params['message_id']);
        if (empty($messageId)) {
            return false;
        }

        $criteria = new CDbCriteria();
        $criteria->addCondition('(`email_message_id` = :email_message_id OR `email_message_id` = :email_message_id_bracket) AND `status` = :status');
        $criteria->params = [
            'email_message_id'          => (string)$messageId,
            'email_message_id_bracket'  => '<' . (string)$messageId . '>',
            'status'                    => CampaignDeliveryLog::STATUS_SUCCESS,
        ];

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return false;
        }

        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return false;
        }

        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => $campaign->list_id,
            'subscriber_id' => $deliveryLog->subscriber_id,
            'status'        => ListSubscriber::STATUS_CONFIRMED,
        ]);
        if (empty($subscriber)) {
            return false;
        }

        $count = CampaignBounceLog::model()->countByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);
        if (!empty($count)) {
            return true;
        }

        $bounceLog = new CampaignBounceLog();
        $bounceLog->campaign_id     = (int)$campaign->campaign_id;
        $bounceLog->subscriber_id   = (int)$subscriber->subscriber_id;
        $bounceLog->message         = !empty($params['bounce_raw']) ? $params['bounce_raw'] : 'Magic SMTP Bounce';
        $bounceLog->bounce_type     = ($params['bounce_type'] === CampaignBounceLog::BOUNCE_SOFT) ? CampaignBounceLog::BOUNCE_SOFT : CampaignBounceLog::BOUNCE_HARD;
        $bounceLog->save();

        if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
            $subscriber->addToBlacklist($bounceLog->message);
        }

        return true;
    }

    /**
     * Process complaint event by message ID
     * @param array $params
     * @return bool
     */
    public function processComplaint(array $params)
    {
        $messageId = str_replace(['<', '>'], '', $params['message_id']);
        if (empty($messageId)) {
            return false;
        }

        $criteria = new CDbCriteria();
        $criteria->addCondition('(`email_message_id` = :email_message_id OR `email_message_id` = :email_message_id_bracket) AND `status` = :status');
        $criteria->params = [
            'email_message_id'          => (string)$messageId,
            'email_message_id_bracket'  => '<' . (string)$messageId . '>',
            'status'                    => CampaignDeliveryLog::STATUS_SUCCESS,
        ];

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return false;
        }

        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return false;
        }

        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => $campaign->list_id,
            'subscriber_id' => $deliveryLog->subscriber_id,
            'status'        => ListSubscriber::STATUS_CONFIRMED,
        ]);
        if (empty($subscriber)) {
            return false;
        }

        /** @var OptionCronProcessFeedbackLoopServers $fbl */
        $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
        $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);

        return true;
    }

    /**
     * Output a JSON response and terminate the request.
     *
     * @param bool   $ok
     * @param string $message
     */
    protected function outputWebhookResponse($ok, $message)
    {
        header('Content-Type: application/json');
        echo json_encode(array(
            'ok'      => (bool)$ok,
            'message' => (string)$message,
            'server'  => isset($this->server_id) ? $this->server_id : 0,
            'type'    => $this->getType(),
        ));
        Yii::app()->end();
    }
}
