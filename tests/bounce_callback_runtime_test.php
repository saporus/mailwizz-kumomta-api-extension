<?php declare(strict_types=1);
/** Actual callback/model logic with isolated in-memory adapters; never sends. */
define('MW_PATH', __DIR__);
class DeliveryServerSmtp { public $server_id = 445; }
class CHttpRequest {
    public $raw;
    function __construct(array $payload) { $this->raw = json_encode($payload); }
    function getRawBody() { return $this->raw; }
}
class CDbCriteria { public $params = [], $conditions = []; function addCondition($condition) { $this->conditions[] = $condition; } }
class CampaignDeliveryLog { const STATUS_SUCCESS = 'success'; }
class CampaignDeliveryLogHelper {
    static $rows = [];
    static function findByCriteria($criteria) {
        if (!in_array('server_id = :magic_server_id', $criteria->conditions, true)) throw new RuntimeException('Server guard removed');
        foreach (self::$rows as $row) {
            if ($row->server_id !== $criteria->params[':magic_server_id'] || $row->status !== $criteria->params['status']) continue;
            if (in_array($row->email_message_id, [$criteria->params['email_message_id'], $criteria->params['email_message_id_bracket']], true)) return $row;
        }
        return null;
    }
}
class Campaign {
    static $rows = [];
    static function model() { return new self(); }
    function findByPk($id) { return self::$rows[$id] ?? null; }
}
class ListSubscriber {
    const STATUS_CONFIRMED = 'confirmed', STATUS_BLACKLISTED = 'blacklisted';
    static $rows = [], $blacklists = 0, $blacklistMode = 'ok';
    public $list_id, $subscriber_id, $status, $email;
    static function model() { return new self(); }
    function findByAttributes($attributes) {
        foreach (self::$rows as $row) {
            $matches = true;
            foreach ($attributes as $key => $value) if ($row->$key !== $value) $matches = false;
            if ($matches) return clone $row;
        }
        return null;
    }
    function addToBlacklist($reason) {
        ++self::$blacklists;
        if (self::$blacklistMode === 'false') return false;
        if (self::$blacklistMode === 'throw') throw new RuntimeException('Synthetic private blacklist failure');
        $this->status = 'blacklisted';
        if (self::$blacklistMode === 'memory_only') return true;
        foreach (self::$rows as $row) if ($row->subscriber_id === $this->subscriber_id) $row->status = 'blacklisted';
        if (self::$blacklistMode === 'throw_after_write') throw new RuntimeException('Synthetic failure after protection persisted');
        return true;
    }
}
class CampaignBounceLog {
    const BOUNCE_HARD = 'hard', BOUNCE_SOFT = 'soft';
    static $rows = [], $saveMode = 'ok';
    public $campaign_id, $subscriber_id, $message, $bounce_type;
    static function model() { return new self(); }
    function findByAttributes($attributes) {
        foreach (self::$rows as $row) if ($row->campaign_id === $attributes['campaign_id'] && $row->subscriber_id === $attributes['subscriber_id']) return clone $row;
        return null;
    }
    function save() {
        if (self::$saveMode === 'false') return false;
        if (self::$saveMode === 'throw') throw new RuntimeException('Synthetic private SQL/password/recipient must not escape');
        self::$rows[] = clone $this; return true;
    }
}
$renderer = new class { public $data; function renderJson($data, $status = 200) { $this->data = $data; http_response_code($status); } };
function controller() { return $GLOBALS['renderer']; }
require dirname(__DIR__) . '/magicsmtp/models/DeliveryServerMagicSmtp.php';
function check(bool $value, string $label): void { if (!$value) throw new RuntimeException($label); echo "PASS $label\n"; }
function resetFixture(): DeliveryServerMagicSmtp {
    $subscriber = new ListSubscriber();
    $subscriber->list_id = 22; $subscriber->subscriber_id = 33; $subscriber->status = 'confirmed'; $subscriber->email = 'synthetic@example.test';
    ListSubscriber::$rows = [$subscriber]; ListSubscriber::$blacklists = 0; ListSubscriber::$blacklistMode = 'ok';
    Campaign::$rows = [328 => (object)['campaign_id' => 328, 'list_id' => 22]];
    CampaignDeliveryLogHelper::$rows = [(object)['server_id' => 445, 'status' => 'success', 'email_message_id' => '<synthetic-message@example.test>', 'campaign_id' => 328, 'subscriber_id' => 33]];
    CampaignBounceLog::$rows = []; CampaignBounceLog::$saveMode = 'ok';
    return new DeliveryServerMagicSmtp();
}
function callback($server, array $overrides = []): array {
    $data = array_merge(['recipient' => 'synthetic@example.test', 'message_id' => 'synthetic-message@example.test', 'bounce_type' => 'soft', 'response_text' => 'Synthetic provider result'], $overrides);
    $server->handleCallback(new CHttpRequest(['event_type' => 'bounce', 'event_id' => 'synthetic-event', 'data' => $data]));
    return ['status' => http_response_code(), 'body' => controller()->data];
}
$server = resetFixture();
foreach (['' => 400, '{' => 400, '{"event_type":"bounce","data":{"recipient":[]}}' => 422, '{"event_type":"other","data":{"recipient":"synthetic@example.test"}}' => 422] as $raw => $status) {
    $request = new CHttpRequest([]); $request->raw = $raw; $server->handleCallback($request);
    check(http_response_code() === $status && !controller()->data['ok'] && !CampaignBounceLog::$rows, 'malformed or unsupported callback uses non-success HTTP status ' . $status);
}
$server = resetFixture(); $server->server_id = 433; $result = callback($server);
check($result['status'] === 409 && !$result['body']['ok'] && !CampaignBounceLog::$rows && !ListSubscriber::$blacklists, 'wrong callback server cannot claim persistence or change suppression');
foreach (['bounce_type', 'response_code', 'response_text', 'message_id'] as $field) {
    $server = resetFixture(); $result = callback($server, [$field => ['malformed']]);
    check($result['status'] === 422 && !$result['body']['ok'] && !CampaignBounceLog::$rows, 'malformed ' . $field . ' fails without an exception or write');
}
$server = resetFixture(); $result = callback($server, ['message_id' => 'unknown@example.test']);
check($result['status'] === 409 && !$result['body']['ok'], 'unmatched message is not acknowledged as registered');
$server = resetFixture(); $result = callback($server, ['recipient' => 'different@example.test']);
check($result['status'] === 409 && !CampaignBounceLog::$rows, 'exact recipient correlation retained');
$server = resetFixture(); ListSubscriber::$rows[0]->status = 'unsubscribed'; $result = callback($server);
check($result['status'] === 409 && !CampaignBounceLog::$rows, 'unconfirmed subscriber cannot create a new bounce');
foreach (['false', 'throw'] as $mode) {
    $server = resetFixture(); CampaignBounceLog::$saveMode = $mode; $result = callback($server, ['bounce_type' => 'hard']);
    check($result['status'] === 503 && !$result['body']['ok'] && !CampaignBounceLog::$rows && !ListSubscriber::$blacklists, 'failed save ' . $mode . ' cannot blacklist or acknowledge success');
    check($result['body']['message'] === 'Bounce could not be recorded', 'failed save ' . $mode . ' exposes no internal error');
}
foreach (['callback-fixture'] as $namespace) {
    $server = resetFixture(); $result = callback($server);
    check($result['status'] === 200 && $result['body']['ok'] && count(CampaignBounceLog::$rows) === 1 && !ListSubscriber::$blacklists, $namespace . ' soft persistence uses actual callback');
    $result = callback($server);
    check($result['status'] === 200 && count(CampaignBounceLog::$rows) === 1, $namespace . ' soft duplicate creates no new row');
    $server = resetFixture(); $result = callback($server, ['bounce_type' => 'hard']);
    check($result['status'] === 200 && $result['body']['ok'] && count(CampaignBounceLog::$rows) === 1 && ListSubscriber::$blacklists === 1, $namespace . ' hard persistence precedes blacklist');
    $result = callback($server, ['bounce_type' => 'hard']);
    check($result['status'] === 200 && $result['body']['ok'] && count(CampaignBounceLog::$rows) === 1 && ListSubscriber::$blacklists === 1, $namespace . ' already blacklisted exact duplicate remains acknowledged once');
}
foreach (['false', 'throw', 'memory_only', 'throw_after_write'] as $mode) {
    $server = resetFixture(); ListSubscriber::$blacklistMode = $mode; $result = callback($server, ['bounce_type' => 'hard']);
    check($result['status'] === 503 && !$result['body']['ok'] && count(CampaignBounceLog::$rows) === 1, 'partial blacklist ' . $mode . ' remains unacknowledged with durable bounce evidence');
    ListSubscriber::$blacklistMode = 'ok'; $result = callback($server, ['bounce_type' => 'hard']);
    check($result['status'] === 200 && $result['body']['ok'] && count(CampaignBounceLog::$rows) === 1 && ListSubscriber::$rows[0]->status === 'blacklisted', 'partial blacklist ' . $mode . ' retry proves durable protection without another bounce row');
}
$server = resetFixture(); callback($server); $result = callback($server, ['bounce_type' => 'hard']);
check($result['status'] === 409 && !ListSubscriber::$blacklists && count(CampaignBounceLog::$rows) === 1, 'existing soft row cannot falsely acknowledge a later hard event');
echo "PASS isolated callback fixtures; no network, live account, UI, database or replay acceptance\n";
