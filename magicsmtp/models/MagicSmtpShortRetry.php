<?php declare(strict_types=1);

/** Bounded waiting for explicitly refused, never-admitted recovery requests only. */
final class MagicSmtpShortRetry
{
    private $guard;
    private $clock;
    private $sleep;
    private $deadline;

    public function __construct(callable $guard, ?callable $clock = null, ?callable $sleep = null)
    {
        $this->guard = $guard;
        $this->clock = $clock ?? static function (): float { return hrtime(true) / 1000000000; };
        $this->sleep = $sleep ?? static function (): void { usleep(1000000); };
        $this->deadline = ($this->clock)() + 12;
    }

    public function wait(MagicSmtpCooldown $cooldown): bool
    {
        while (true) {
            // Eligibility must also win over abort paths: code 99 on an elapsed
            // budget or a new long cooldown could otherwise resume a paused batch.
            $this->checkEligibility();
            try { $state = $cooldown->snapshot(); }
            catch (Throwable $e) { throw new Exception('Short retry coordination is unavailable.', 99, $e); }
            if (!$state['remaining']) {
                if (($this->clock)() >= $this->deadline) return false;
                return true;
            }
            if (!$state['short_retry'] || $state['remaining'] > 4 || ($this->clock)() + $state['remaining'] >= $this->deadline) return false;
            ($this->sleep)();
        }
    }

    public function checkEligibility(): void { ($this->guard)(); }

    /** Called by the normal campaign worker, with freshly loaded native records. */
    public static function assertEligible($server, $originalCampaign, $originalSubscriber): void
    {
        try {
            $campaign = Campaign::model()->findByPk((int)$originalCampaign->campaign_id);
            if (!$campaign || !in_array((string)$campaign->status, [Campaign::STATUS_PROCESSING, Campaign::STATUS_SENDING], true)) {
                throw new Exception('Campaign is no longer sending; short retry stopped.', 98);
            }
            $subscriber = ListSubscriber::model()->findByPk((int)$originalSubscriber->subscriber_id);
            if (!$subscriber || (int)$subscriber->list_id !== (int)$campaign->list_id
                || (string)$subscriber->subscriber_uid !== (string)$originalSubscriber->subscriber_uid
                || (string)$subscriber->email !== (string)$originalSubscriber->email
                || !$subscriber->getIsConfirmed()
                || $subscriber->getIsBlacklisted(['checkZone' => EmailBlacklist::CHECK_ZONE_CAMPAIGN])
                || CustomerSuppressionListEmail::isSubscriberListedByCampaign($subscriber, $campaign)
                || (!empty($campaign->group_id) && CampaignGroupBlockSubscriber::model()->countByAttributes(['group_id' => (int)$campaign->group_id, 'subscriber_id' => (int)$subscriber->subscriber_id]))
                || MagicSmtpPolicyRuntime::effective($campaign, $subscriber)
                || !$server->canSendToDomainOf($subscriber->email)
                || $server->getIsOverQuota()
                || $campaign->customer->getIsOverQuota()) {
                throw new Exception('Recipient or sending policy changed; short retry stopped.', 98);
            }
        } catch (Throwable $e) {
            // Code 98 also makes the outer worker reload a possibly changed pause.
            throw new Exception('Short retry eligibility could not be confirmed: ' . $e->getMessage(), 98, $e);
        }
    }
}
