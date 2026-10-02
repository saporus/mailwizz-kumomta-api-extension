<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');
require_once __DIR__ . '/MagicSmtpPolicyStore.php';

/** Signed policy callbacks are deliberately separate from bounce classification. */
final class MagicSmtpPolicyBridge
{
    const VERSION = '1.2.0';
    private $store;
    private $bindings;
    private $clock;
    private $correlate;
    private $schedulerReady;
    public function __construct(MagicSmtpPolicyStore $store, array $bindings, callable $correlate, callable $schedulerReady, ?callable $clock = null)
    {
        $this->store=$store; $this->bindings=$bindings; $this->correlate=$correlate;
        $this->schedulerReady=$schedulerReady; $this->clock=$clock??static function(){return (int)floor(microtime(true)*1000);};
        $seen=[];
        foreach ($bindings as $b) {
            if (empty($b['bridge_id']) || empty($b['tenant_id']) || empty($b['customer_id']) || empty($b['server_ids']) || strlen((string)($b['secret']??''))<32
                || !is_int($b['customer_id']) || $b['customer_id']<1 || !is_array($b['server_ids'])) throw new InvalidArgumentException('Invalid policy bridge binding');
            foreach ($b['server_ids'] as $id) {
                if (!is_int($id) || $id<1 || isset($seen[$id])) throw new InvalidArgumentException('Delivery server has an ambiguous policy bridge binding');
                $seen[$id]=true;
            }
        }
    }
    public static function recipient(string $value): string { return strtolower(trim($value)); }
    public static function messageId(string $value): string { return trim(trim($value), '<>'); }
    public function store(): MagicSmtpPolicyStore { return $this->store; }
    public function bindings(): array { return $this->bindings; }
    public function now(): int { return ($this->clock)(); }
    public function bindingForServer(int $id): ?array
    {
        foreach ($this->bindings as $b) if (in_array($id,$b['server_ids'],true)) return $b;
        return null;
    }
    private function stringField(array $source,string $name,int $limit=128): string
    {
        $v=$source[$name]??null;
        if (!is_string($v) || trim($v)==='' || strlen($v)>$limit || preg_match('/[\x00-\x1f\x7f]/',$v)) throw new InvalidArgumentException('Invalid '.$name);
        return trim($v);
    }
    public function receive(int $serverId, string $rawBody, string $signature): array
    {
        $binding=$this->bindingForServer($serverId);
        if (!$binding || empty($binding['enabled'])) throw new RuntimeException('Policy bridge is not enabled',403);
        if (strlen($rawBody)>2097152 || !preg_match('/^[a-fA-F0-9]{64}$/D',$signature)
            || !hash_equals(hash_hmac('sha256',$rawBody,$binding['secret']),strtolower($signature))) throw new RuntimeException('Invalid policy signature',401);
        $event=json_decode($rawBody,true,32,JSON_THROW_ON_ERROR);
        if (!is_array($event)) throw new InvalidArgumentException('Invalid policy payload');
        $type=$this->stringField($event,'event_type');
        if (!in_array($type,['recipient.policy_suppressed','recipient.policy_released','recipient.policy_probe'],true)) throw new InvalidArgumentException('Unsupported policy event');
        if ($this->stringField($event,'tenant')!==(string)$binding['tenant_id']) throw new RuntimeException('Policy tenant does not match bridge',403);
        $event['event_id']=$this->stringField($event,'event_id');
        if (isset($event['webhook_event_id']) && $event['webhook_event_id']!==$event['event_id']) throw new InvalidArgumentException('Conflicting event IDs');
        if (!isset($event['timestamp']) || !is_int($event['timestamp']) || $event['timestamp']<1 || $event['timestamp']>$this->now()+300000) throw new InvalidArgumentException('Invalid event timestamp');
        $data=$event['data']??null;
        if (!is_array($data) || $this->stringField($data,'bridge_id')!==$binding['bridge_id']) throw new RuntimeException('Policy bridge does not match',403);
        $this->store->ready();
        $ready=(bool)($this->schedulerReady)($binding);
        if ($type==='recipient.policy_probe') {
            $nonce=$this->stringField($data,isset($data['nonce'])?'nonce':'challenge');
            return ['ok'=>true,'nonce'=>$nonce,'challenge'=>$nonce, 'bridgeId'=>$binding['bridge_id'],
                'tenantId'=>$binding['tenant_id'],'customerId'=>$binding['customer_id'],'serverIds'=>$binding['server_ids'],
                'version'=>self::VERSION,'permanentPolicyVerified'=>$ready,'holdSchedulerVerified'=>$ready,
                'capabilities'=>$ready?['recipient.policy_suppressed','recipient.policy_released','temporary_holds']:[]];
        }
        // No permanent or temporary policy may activate on an unproved scheduler.
        if (!$ready) throw new RuntimeException('Policy scheduler compatibility is not verified',409);
        $data['recipient']=self::recipient($this->stringField($data,'recipient',320));
        if (!filter_var($data['recipient'],FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid recipient');
        foreach (['policy_effect_id','rule_id'] as $key) $data[$key]=$this->stringField($data,$key);
        if (isset($data['campaign_id']) && $data['campaign_id']!==null && $data['campaign_id']!=='') {
            if (is_int($data['campaign_id'])) $data['campaign_id']=(string)$data['campaign_id'];
            $data['campaign_id']=$this->stringField($data,'campaign_id');
        } else {$data['campaign_id']='';}
        $data['message_id']=self::messageId($this->stringField($data,'message_id',512));
        $data['reason']=$this->stringField($data,'reason',4096);
        if (!in_array($data['action']??null,['permanent','temporary'],true)) throw new InvalidArgumentException('Invalid policy action');
        $data['expires_at']=$data['expires_at']??null;
        if ($data['action']==='temporary' && (!is_int($data['expires_at']) || $data['expires_at']<1)) throw new InvalidArgumentException('Temporary policy requires an expiry');
        if ($data['action']==='permanent' && $data['expires_at']!==null) throw new InvalidArgumentException('Permanent policy cannot expire');
        if (!is_int($data['rule_revision']??null) || $data['rule_revision']<1) throw new InvalidArgumentException('Invalid rule revision');
        if (isset($data['subscriber_uid'])) $data['subscriber_uid']=$this->stringField($data,'subscriber_uid');
        $proof=$this->store->resolveDispatch($binding,$data);
        if (!$proof) $proof=($this->correlate)($binding,$data);
        if (!is_array($proof) || empty($proof['campaign_uid']) || empty($proof['subscriber_uid'])) throw new RuntimeException('Policy event has no unambiguous authorized dispatch',409);
        $data['campaign_id']=(string)$proof['campaign_uid'];
        $data['subscriber_uid']=(string)$proof['subscriber_uid'];
        $event['data']=$data;
        return $this->store->apply($binding,$event,$rawBody,$this->now());
    }
    public function recordDispatch(int $serverId,int $customerId,string $recipient,string $messageId,string $campaignUid,string $subscriberUid): void
    {
        $binding=$this->bindingForServer($serverId);
        if (!$binding || empty($binding['enabled'])) return;
        if ($customerId!==$binding['customer_id'] || !($this->schedulerReady)($binding)) throw new RuntimeException('Policy dispatch binding is not ready');
        if (!$campaignUid || !$subscriberUid) throw new RuntimeException('Policy dispatch requires campaign and subscriber correlation');
        $this->store->recordDispatch($binding,$serverId,self::recipient($recipient),self::messageId($messageId),$campaignUid,$subscriberUid,$this->now());
    }
}
