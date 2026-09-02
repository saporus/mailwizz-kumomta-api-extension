<?php declare(strict_types=1);

define('MW_PATH', __DIR__);

class DeliveryServer
{
}

require dirname(__DIR__) . '/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';

class TestableDeliveryServerMagicSmtpWebApi extends DeliveryServerMagicSmtpWebApi
{
    public function idempotencyKey(array $params): string
    {
        return $this->buildIdempotencyKey($params);
    }
}

$server = new TestableDeliveryServerMagicSmtpWebApi();
$first = $server->idempotencyKey(array(
    'campaignUid' => 'campaign-123',
    'subscriberUid' => 'subscriber-456',
));
$repeat = $server->idempotencyKey(array(
    'campaignUid' => 'campaign-123',
    'subscriberUid' => 'subscriber-456',
));
$otherSubscriber = $server->idempotencyKey(array(
    'campaignUid' => 'campaign-123',
    'subscriberUid' => 'subscriber-789',
));
$objectCampaign = $server->idempotencyKey(array(
    'campaign' => (object)array('campaign_uid' => 'campaign-123'),
    'subscriberUid' => 'subscriber-456',
));

$checks = array(
    'stable for the same campaign recipient' => $first === $repeat,
    'uses the campaign object fallback' => $first === $objectCampaign,
    'changes for a different recipient' => $first !== $otherSubscriber,
    'uses the expected opaque format' => preg_match('/^mailwizz:send:v1:[a-f0-9]{64}$/', $first) === 1,
    'does not expose the campaign UID' => strpos($first, 'campaign-123') === false,
    'does not expose the subscriber UID' => strpos($first, 'subscriber-456') === false,
    'requires a campaign UID' => $server->idempotencyKey(array('subscriberUid' => 'subscriber-456')) === '',
    'requires a subscriber UID' => $server->idempotencyKey(array('campaignUid' => 'campaign-123')) === '',
);

$failed = array();
foreach ($checks as $description => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$passed) {
        $failed[] = $description;
    }
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . count($failed) . ' idempotency check(s) failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'All idempotency runtime checks passed.' . PHP_EOL;
