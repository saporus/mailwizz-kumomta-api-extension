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

    private $feedbackBinding;
    private $feedbackData;

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
        $this->feedbackBinding = null;
        $this->feedbackData = null;
        $rawBody = $request->getRawBody();
        if (strlen($rawBody) > 2097152) {
            $this->outputWebhookResponse(false, 'Payload too large', 413);
            return;
        }
        if (empty($rawBody)) {
            $this->outputWebhookResponse(false, 'Empty payload', 400);
            return;
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload) || !isset($payload['event_type']) || !is_string($payload['event_type'])) {
            $this->outputWebhookResponse(false, 'Invalid payload format', 400);
            return;
        }

        $eventType = strtolower(trim($payload['event_type']));
        $eventData = isset($payload['data']) && is_array($payload['data']) ? $payload['data'] : array();

        if (strpos($eventType, 'recipient.policy_') === 0) {
            require_once dirname(__DIR__) . '/MagicSmtpPolicyRuntime.php';
            try {
                // Yii 1 CHttpRequest has no getHeader(). FPM exposes this header
                // through SERVER; leave the raw body unchanged for HMAC checks.
                $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
                if (!is_string($signature)) $signature = '';
                $result = MagicSmtpPolicyRuntime::callback((int)$this->server_id, $rawBody, $signature);
                controller()->renderJson($result);
            } catch (Throwable $e) {
                $code = in_array((int)$e->getCode(), [401,403,409], true) ? (int)$e->getCode() : 422;
                // Do not expose raw request bodies, signatures, SQL or credentials.
                $this->outputWebhookResponse(false, $code === 401 ? 'Invalid policy signature' : ($code === 403 ? 'Policy bridge binding rejected' : ($code === 409 ? 'Policy bridge is not ready or dispatch is unmatched' : 'Policy event could not be processed')), $code);
            }
            return;
        }

        if (in_array($eventType, ['bounce', 'complaint'], true)) {
            require_once __DIR__ . '/MagicSmtpBounceIngress.php';
            try {
                $bindings = function_exists('app_param') ? app_param('magicsmtp.policyBridges', []) : [];
                if (!is_array($bindings)) throw new RuntimeException('Invalid feedback configuration', 403);
                $signature = $_SERVER['HTTP_X_WEBHOOK_SIGNATURE'] ?? '';
                $this->feedbackBinding = MagicSmtpBounceIngress::authenticate((int)$this->server_id, $bindings, $payload, $rawBody, is_string($signature) ? $signature : '');
                if ($this->feedbackBinding) $this->feedbackData = MagicSmtpBounceIngress::feedbackData($payload);
            } catch (Throwable $failure) {
                $status = in_array((int)$failure->getCode(), [401, 403, 422], true) ? (int)$failure->getCode() : 403;
                $this->outputWebhookResponse(false, $status === 401 ? 'Invalid feedback signature' : ($status === 403 ? 'Feedback binding rejected' : 'Invalid feedback payload'), $status);
                return;
            }
        }

        if (empty($eventData['recipient']) || !is_string($eventData['recipient']) || strlen($eventData['recipient']) > 320) {
            $this->outputWebhookResponse(false, 'Missing or invalid recipient email address', 422);
            return;
        }

        $recipient    = trim($eventData['recipient']);
        $messageId    = isset($payload['event_id']) ? $payload['event_id'] : '';
        if (isset($eventData['message_id']) && !empty($eventData['message_id'])) {
            $messageId = $eventData['message_id'];
        }
        $responseText = isset($eventData['response_text']) ? $eventData['response_text'] : 'Magic SMTP Webhook Callback';

        if ($eventType === 'bounce') {
            if (!is_string($messageId) || strlen($messageId) > 512 || !is_string($responseText)
                || (isset($eventData['bounce_type']) && !is_string($eventData['bounce_type']))
                || (isset($eventData['response_code']) && !is_int($eventData['response_code']) && !(is_string($eventData['response_code']) && preg_match('/^-?[0-9]{1,4}$/D', $eventData['response_code'])))) {
                $this->outputWebhookResponse(false, 'Invalid bounce payload', 422);
                return;
            }
            $bounceType = isset($eventData['bounce_type']) ? strtolower($eventData['bounce_type']) : 'soft';
            $code = isset($eventData['response_code']) ? (int)$eventData['response_code'] : 0;

            try {
                $processed = $this->processBounce(array(
                    'email'       => $recipient,
                    'message_id'  => $messageId,
                    'bounce_type' => ($bounceType === 'hard')
                        ? CampaignBounceLog::BOUNCE_HARD
                        : CampaignBounceLog::BOUNCE_SOFT,
                    'bounce_code' => $code,
                    'bounce_raw'  => $responseText,
                ));
            } catch (Throwable $failure) {
                // No raw callback, database error, address or credential output.
                $this->outputWebhookResponse(false, 'Bounce could not be recorded', 503);
                return;
            }
            if ($processed !== true) {
                $this->outputWebhookResponse(false, 'Bounce did not match an eligible delivery', 409);
                return;
            }

            $this->outputWebhookResponse(true, 'Bounce registered');
            return;
        }

        if ($eventType === 'complaint') {
            if (!is_string($messageId) || strlen($messageId) > 512 || !is_string($responseText)) {
                $this->outputWebhookResponse(false, 'Invalid complaint payload', 422);
                return;
            }
            try {
                $processed = $this->processComplaint(array('email' => $recipient, 'message_id' => $messageId, 'complaint_raw' => $responseText));
            } catch (Throwable $failure) {
                $this->outputWebhookResponse(false, 'Complaint could not be recorded', 503);
                return;
            }
            if ($this->feedbackBinding !== null && $processed !== true) {
                $this->outputWebhookResponse(false, 'Complaint did not match an eligible delivery', 409);
                return;
            }

            $this->outputWebhookResponse(true, 'Complaint registered');
            return;
        }

        // Unsupported events must not be marked delivered by an HTTP-only sender.
        $this->outputWebhookResponse(false, 'Unsupported event type', 422);
    }

    /**
     * Get the webhook callback URL for this server type.
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

    /**
     * Process bounce event by message ID
     * @param array $params
     * @return bool
     */
    public function processBounce(array $params)
    {
        if ($this->feedbackBinding !== null) {
            $proof = $this->signedFeedbackProof();
            if (!$proof) return false;
            return $this->persistCorrelatedBounce($params, $proof);
        }

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
        $criteria->addCondition('server_id = :magic_server_id');
        $criteria->params[':magic_server_id'] = (int)$this->server_id;

        $deliveryLog = CampaignDeliveryLogHelper::findByCriteria($criteria);
        if (empty($deliveryLog)) {
            return false;
        }

        $campaign = Campaign::model()->findByPk((int)$deliveryLog->campaign_id);
        if (empty($campaign)) {
            return false;
        }

        return $this->persistCorrelatedBounce($params, [
            'campaign_id' => (int)$campaign->campaign_id,
            'list_id' => (int)$campaign->list_id,
            'subscriber_id' => (int)$deliveryLog->subscriber_id,
        ]);
    }

    /** Signed callbacks serialize on a transactional native subscriber row. */
    protected function persistCorrelatedBounce(array $params, array $proof): bool
    {
        if ($this->feedbackBinding === null) return $this->persistLockedBounce($params, $proof);
        $transaction = $this->beginFeedbackTransaction($proof);
        try {
            $processed = $this->persistLockedBounce($params, $proof);
            if ($processed) $transaction->commit(); else $transaction->rollback();
            return $processed;
        } catch (Throwable $failure) {
            if ($transaction->getActive()) $transaction->rollback();
            throw $failure;
        }
    }

    private function persistLockedBounce(array $params, array $proof): bool
    {
        // Fresh reads under the lock protect duplicate and partial-effect retries.
        $campaign = Campaign::model()->findByPk((int)$proof['campaign_id']);
        if (!$campaign || (int)$campaign->list_id !== (int)$proof['list_id']) return false;
        if ($this->feedbackBinding !== null && ((int)$campaign->customer_id !== $this->feedbackBinding['customer_id']
            || (string)$campaign->campaign_uid !== $proof['campaign_uid']
            || !in_array($proof['server_id'], $this->feedbackBinding['server_ids'], true))) return false;
        $subscriber = ListSubscriber::model()->findByAttributes([
            'list_id'       => $campaign->list_id,
            'subscriber_id' => (int)$proof['subscriber_id'],
        ]);
        if (empty($subscriber)) {
            return false;
        }
        if (strcasecmp(trim((string)$subscriber->email), trim((string)$params['email'])) !== 0) return false;
        if ($this->feedbackBinding !== null && (string)$subscriber->subscriber_uid !== $proof['subscriber_uid']) return false;

        $existing = CampaignBounceLog::model()->findByAttributes([
            'campaign_id'   => (int)$campaign->campaign_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);
        if ($existing) {
            // A prior soft result is not proof that this hard event was applied.
            if ($params['bounce_type'] === CampaignBounceLog::BOUNCE_HARD && $existing->bounce_type !== CampaignBounceLog::BOUNCE_HARD) return false;
            if ($existing->bounce_type === CampaignBounceLog::BOUNCE_HARD) $this->ensureHardBounceProtection($subscriber, (string)$existing->message);
            return true;
        }
        // A repeated hard bounce may already have blacklisted this subscriber.
        // Only an existing exact correlated bounce can bypass current status.
        if ($subscriber->status !== ListSubscriber::STATUS_CONFIRMED) return false;

        $bounceLog = new CampaignBounceLog();
        $bounceLog->campaign_id     = (int)$campaign->campaign_id;
        $bounceLog->subscriber_id   = (int)$subscriber->subscriber_id;
        $bounceLog->message         = !empty($params['bounce_raw']) ? $params['bounce_raw'] : 'Magic SMTP Bounce';
        $bounceLog->bounce_type     = ($params['bounce_type'] === CampaignBounceLog::BOUNCE_HARD) ? CampaignBounceLog::BOUNCE_HARD : CampaignBounceLog::BOUNCE_SOFT;
        if (!$bounceLog->save()) throw new RuntimeException('Bounce log persistence failed');

        if ($bounceLog->bounce_type == CampaignBounceLog::BOUNCE_HARD) {
            $this->ensureHardBounceProtection($subscriber, (string)$bounceLog->message);
        }

        return true;
    }

    /** A persisted bounce can outlive a failed blacklist write; retry that step. */
    protected function ensureHardBounceProtection($subscriber, string $reason): void
    {
        if ($subscriber->status === ListSubscriber::STATUS_BLACKLISTED) return;
        if ($subscriber->status !== ListSubscriber::STATUS_CONFIRMED || !$subscriber->addToBlacklist($reason)) {
            throw new RuntimeException('Hard bounce protection is incomplete');
        }
        // Native addToBlacklist can update only its in-memory model after a
        // failed status write. A fresh durable read is required before ACK.
        $stored = ListSubscriber::model()->findByAttributes([
            'list_id' => (int)$subscriber->list_id,
            'subscriber_id' => (int)$subscriber->subscriber_id,
        ]);
        if (!$stored || $stored->status !== ListSubscriber::STATUS_BLACKLISTED) {
            throw new RuntimeException('Hard bounce protection is not persisted');
        }
    }

    /**
     * Process complaint event by message ID
     * @param array $params
     * @return bool
     */
    public function processComplaint(array $params)
    {
        if ($this->feedbackBinding !== null) return $this->processSignedComplaint($params);
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
        $criteria->addCondition('server_id = :magic_server_id');
        $criteria->params[':magic_server_id'] = (int)$this->server_id;

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
        if (strcasecmp(trim((string)$subscriber->email), trim((string)$params['email'])) !== 0) return false;

        /** @var OptionCronProcessFeedbackLoopServers $fbl */
        $fbl = container()->get(OptionCronProcessFeedbackLoopServers::class);
        $fbl->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);

        return true;
    }

    private function signedFeedbackProof(): ?array
    {
        $connection = Yii::app()->db;
        $connection->setActive(true);
        return MagicSmtpBounceIngress::correlate($connection->getPdoInstance(), (string)$connection->tablePrefix,
            [CampaignDeliveryLog::model()->tableName(), CampaignDeliveryLogArchive::model()->tableName()], $this->feedbackBinding, $this->feedbackData);
    }

    private function beginFeedbackTransaction(array $proof)
    {
        $connection = Yii::app()->db;
        if ($connection->getCurrentTransaction()) throw new RuntimeException('Feedback transaction is already active');
        $transaction = $connection->beginTransaction();
        try {
            $prefix = (string)$connection->tablePrefix;
            if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new RuntimeException('Invalid feedback table prefix');
            $pdo = $connection->getPdoInstance();
            $lock = $pdo->prepare('SELECT subscriber_id FROM '.$prefix.'list_subscriber WHERE subscriber_id=? AND list_id=?'.($pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' FOR UPDATE' : ''));
            if (!$lock || !$lock->execute([$proof['subscriber_id'], $proof['list_id']]) || !$lock->fetchColumn()) throw new RuntimeException('Feedback subscriber is unavailable');
            return $transaction;
        } catch (Throwable $failure) {
            if ($transaction->getActive()) $transaction->rollback();
            throw $failure;
        }
    }

    /** Native configurable action, with durable evidence and recoverable partial effects. */
    private function processSignedComplaint(array $params): bool
    {
        $proof = $this->signedFeedbackProof();
        if (!$proof) return false;
        $transaction = null;
        $nativeActionAttempted = false;
        try {
            $transaction = $this->beginFeedbackTransaction($proof);
            $campaign = Campaign::model()->findByPk($proof['campaign_id']);
            $subscriber = ListSubscriber::model()->findByAttributes(['list_id' => $proof['list_id'], 'subscriber_id' => $proof['subscriber_id']]);
            if (!$campaign || !$subscriber || (int)$campaign->customer_id !== $this->feedbackBinding['customer_id']
                || (int)$campaign->list_id !== $proof['list_id'] || (string)$campaign->campaign_uid !== $proof['campaign_uid']
                || (string)$subscriber->subscriber_uid !== $proof['subscriber_uid']
                || strcasecmp(trim((string)$subscriber->email), trim((string)$params['email'])) !== 0) {
                $transaction->rollback(); return false;
            }
            $action = container()->get(OptionCronProcessFeedbackLoopServers::class);
            $unsubscribe = $action->getSubscriberActionIsUnsubscribe();
            if (!$unsubscribe && !$action->getSubscriberActionIsBlacklist()) {
                // Delete destroys the correlation evidence; do not invent a durable receipt.
                throw new RuntimeException('Configured complaint action has no durable proof');
            }
            $ownBlacklist = !$unsubscribe && $campaign->customer && $campaign->customer->getGroupOption('lists.can_use_own_blacklist', 'no') === 'yes';
            $attributes = ['campaign_id' => $proof['campaign_id'], 'subscriber_id' => $proof['subscriber_id']];
            $expectedStatus = $unsubscribe ? ListSubscriber::STATUS_UNSUBSCRIBED : ListSubscriber::STATUS_BLACKLISTED;
            if ($subscriber->status === $expectedStatus && CampaignComplainLog::model()->findByAttributes($attributes)
                && (!$unsubscribe || CampaignTrackUnsubscribe::model()->findByAttributes($attributes))
                && (!$ownBlacklist || CustomerEmailBlacklist::model()->findByAttributes(['customer_id' => $this->feedbackBinding['customer_id'], 'email' => $subscriber->email]))) {
                $transaction->commit(); return true;
            }
            $nativeActionAttempted = true;
            $action->takeActionAgainstSubscriberWithCampaign($subscriber, $campaign);
            $fresh = ListSubscriber::model()->findByAttributes(['list_id' => $proof['list_id'], 'subscriber_id' => $proof['subscriber_id']]);
            if (!$fresh || $fresh->status !== $expectedStatus) throw new RuntimeException('Complaint protection is not persisted');
            if ($ownBlacklist && !CustomerEmailBlacklist::model()->findByAttributes(['customer_id' => $this->feedbackBinding['customer_id'], 'email' => $fresh->email])) {
                throw new RuntimeException('Customer complaint protection is not persisted');
            }
            // The native unsubscribe action returns early for an already-unsubscribed
            // subscriber. Repair only the exact authenticated campaign's missing logs.
            if ($unsubscribe && !CampaignTrackUnsubscribe::model()->findByAttributes($attributes)) {
                $track = new CampaignTrackUnsubscribe();
                $track->campaign_id = $proof['campaign_id']; $track->subscriber_id = $proof['subscriber_id'];
                $track->note = 'Unsubscribed via signed Magic SMTP feedback';
                $track->ip_address = (string)request()->getUserHostAddress();
                $track->user_agent = StringHelper::truncateLength((string)request()->getUserAgent(), 255);
                if (!$track->save(false)) throw new RuntimeException('Complaint unsubscribe tracking failed');
            }
            if (!CampaignComplainLog::model()->findByAttributes($attributes)) {
                $log = new CampaignComplainLog();
                $log->campaign_id = $proof['campaign_id']; $log->subscriber_id = $proof['subscriber_id'];
                $log->message = EmailBlacklist::ABUSE_COMPLAINT_REASON;
                if (!$log->save(false)) throw new RuntimeException('Complaint tracking failed');
            }
            if (!CampaignComplainLog::model()->findByAttributes($attributes)
                || ($unsubscribe && !CampaignTrackUnsubscribe::model()->findByAttributes($attributes))) throw new RuntimeException('Complaint tracking is not persisted');
            $transaction->commit();
            return true;
        } catch (Throwable $failure) {
            if ($transaction && $transaction->getActive()) $transaction->rollback();
            if ($nativeActionAttempted && $transaction && !$transaction->getActive()) {
                // Native saveStatus adjusts a derived cache before its SQL write.
                // Mark only this list for rebuild after rollback, never mid-transaction.
                try { Lists::flushSubscribersCountCacheByListsIds([$proof['list_id']]); } catch (Throwable $cacheFailure) {}
            }
            throw $failure;
        }
    }

    /**
     * Output a JSON response and terminate the request.
     *
     * @param bool   $ok
     * @param string $message
     * @param int    $statusCode
     */
    protected function outputWebhookResponse($ok, $message, $statusCode = 200)
    {
        controller()->renderJson(array(
            'ok'      => (bool)$ok,
            'message' => (string)$message,
            'server'  => isset($this->server_id) ? $this->server_id : 0,
            'type'    => $this->getType(),
        ), $statusCode);
    }
}
