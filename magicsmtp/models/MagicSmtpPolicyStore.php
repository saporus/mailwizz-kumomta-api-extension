<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');

/** Extension-owned records. Never writes MailWizz subscriber or blacklist state. */
final class MagicSmtpPolicyStore
{
    private $pdo;
    private $prefix;
    public function __construct(PDO $pdo, string $prefix = '')
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new InvalidArgumentException('Invalid table prefix');
        $this->pdo = $pdo;
        $this->prefix = $prefix;
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
    }
    public function table(string $name): string { return $this->prefix . 'magic_smtp_policy_' . $name; }
    public function query(string $sql, array $params = []): PDOStatement
    {
        $stmt = $this->pdo->prepare($sql);
        $stmt->execute($params);
        return $stmt;
    }
    /** Explicit installation only; webhook/probe never creates or changes schema. */
    public function install(): void
    {
        $suffix = $this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql' ? ' ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_bin' : '';
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('dispatch') . ' (
            dispatch_key VARCHAR(64) PRIMARY KEY, lookup_key VARCHAR(64) NOT NULL, bridge_id VARCHAR(128) NOT NULL,
            customer_id INTEGER NOT NULL, server_id INTEGER NOT NULL, recipient VARCHAR(320) NOT NULL,
            message_id VARCHAR(512) NOT NULL, campaign_uid VARCHAR(128) NOT NULL, subscriber_uid VARCHAR(128) NOT NULL,
            created_at BIGINT NOT NULL)' . $suffix);
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('receipt') . ' (
            receipt_key VARCHAR(64) PRIMARY KEY, bridge_id VARCHAR(128) NOT NULL, event_id VARCHAR(128) NOT NULL,
            body_hash VARCHAR(64) NOT NULL, effect_id VARCHAR(128) NOT NULL, received_at BIGINT NOT NULL)' . $suffix);
        $this->query('CREATE TABLE IF NOT EXISTS ' . $this->table('effect') . ' (
            effect_key VARCHAR(64) PRIMARY KEY, bridge_id VARCHAR(128) NOT NULL, effect_id VARCHAR(128) NOT NULL,
            customer_id INTEGER NOT NULL, recipient VARCHAR(320) NOT NULL, action VARCHAR(16) NOT NULL,
            expires_at BIGINT NULL, state VARCHAR(16) NOT NULL, event_at BIGINT NOT NULL,
            rule_id VARCHAR(128) NOT NULL, rule_revision BIGINT NOT NULL, reason TEXT NOT NULL,
            message_id VARCHAR(512) NOT NULL, campaign_uid VARCHAR(128) NOT NULL, updated_at BIGINT NOT NULL)' . $suffix);
        // MySQL lacks CREATE INDEX IF NOT EXISTS; installation checks the existing schema first.
        $index = $this->table('effect_recipient');
        if ($this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql') {
            $exists = $this->query('SHOW INDEX FROM ' . $this->table('effect') . ' WHERE Key_name = ?', [$index])->fetch();
            if (!$exists) $this->query('CREATE INDEX ' . $index . ' ON ' . $this->table('effect') . ' (customer_id,recipient(128),state)');
            $index=$this->table('dispatch_lookup');
            if (!$this->query('SHOW INDEX FROM '.$this->table('dispatch').' WHERE Key_name = ?',[$index])->fetch()) $this->query('CREATE INDEX '.$index.' ON '.$this->table('dispatch').' (lookup_key)');
        } else {
            $this->query('CREATE INDEX IF NOT EXISTS ' . $index . ' ON ' . $this->table('effect') . ' (customer_id,recipient,state)');
            $this->query('CREATE INDEX IF NOT EXISTS '.$this->table('dispatch_lookup').' ON '.$this->table('dispatch').' (lookup_key)');
        }
    }
    public function ready(): bool
    {
        foreach (['dispatch', 'receipt', 'effect'] as $name) $this->query('SELECT 1 FROM ' . $this->table($name) . ' WHERE 1=0');
        return true;
    }
    public function recordDispatch(array $binding, int $serverId, string $recipient, string $messageId, string $campaignUid, string $subscriberUid, int $now): void
    {
        $key = hash('sha256', implode("\0", [$binding['bridge_id'], $binding['customer_id'], $serverId, $recipient, $messageId, $campaignUid, $subscriberUid]));
        $lookup=hash('sha256',implode("\0",[$binding['bridge_id'],$binding['customer_id'],$recipient,$messageId]));
        if ($this->query('SELECT dispatch_key FROM ' . $this->table('dispatch') . ' WHERE dispatch_key=?', [$key])->fetch()) return;
        try {
            $this->query('INSERT INTO ' . $this->table('dispatch') . ' (dispatch_key,lookup_key,bridge_id,customer_id,server_id,recipient,message_id,campaign_uid,subscriber_uid,created_at) VALUES (?,?,?,?,?,?,?,?,?,?)',
                [$key,$lookup,$binding['bridge_id'],$binding['customer_id'],$serverId,$recipient,$messageId,$campaignUid,$subscriberUid,$now]);
        } catch (PDOException $e) {
            if (!$this->query('SELECT dispatch_key FROM ' . $this->table('dispatch') . ' WHERE dispatch_key=?', [$key])->fetch()) throw $e;
        }
    }
    public function resolveDispatch(array $binding, array $data): ?array
    {
        $ids = array_values($binding['server_ids']);
        $lookup=hash('sha256',implode("\0",[$binding['bridge_id'],$binding['customer_id'],$data['recipient'],$data['message_id']]));
        $sql = 'SELECT DISTINCT campaign_uid,subscriber_uid FROM ' . $this->table('dispatch') . ' WHERE lookup_key=? AND bridge_id=? AND customer_id=? AND recipient=? AND message_id=? AND server_id IN (' . implode(',', array_fill(0,count($ids),'?')) . ')';
        $params=array_merge([$lookup,$binding['bridge_id'],$binding['customer_id'],$data['recipient'],$data['message_id']],$ids);
        if (!empty($data['campaign_id'])) {$sql.=' AND campaign_uid=?';$params[]=$data['campaign_id'];}
        if (!empty($data['subscriber_uid'])) {$sql.=' AND subscriber_uid=?';$params[]=$data['subscriber_uid'];}
        $rows=$this->query($sql.' LIMIT 2',$params)->fetchAll(PDO::FETCH_ASSOC);
        return count($rows)===1?$rows[0]:null;
    }
    /** Receipt and effect commit together. Release tombstones cannot be revived by delayed suppressions. */
    public function apply(array $binding, array $event, string $rawBody, int $now): array
    {
        $d=$event['data']; $receiptKey=hash('sha256',$binding['bridge_id']."\0".$event['event_id']);
        $effectKey=hash('sha256',$binding['bridge_id']."\0".$d['policy_effect_id']); $bodyHash=hash('sha256',$rawBody);
        $ack=['ok'=>true,'eventId'=>$event['event_id'],'effectId'=>$d['policy_effect_id'],
            'bridgeId'=>$binding['bridge_id'],'tenantId'=>$binding['tenant_id']];
        $this->pdo->beginTransaction();
        try {
            $receipt=$this->query('SELECT body_hash FROM '.$this->table('receipt').' WHERE receipt_key=?',[$receiptKey])->fetch(PDO::FETCH_ASSOC);
            if ($receipt) {
                if (!hash_equals($receipt['body_hash'],$bodyHash)) throw new RuntimeException('Event ID was reused with different content');
                $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
                $current=$this->query('SELECT state FROM '.$this->table('effect').' WHERE effect_key=?'.$lock,[$effectKey])->fetch(PDO::FETCH_ASSOC);
                if (!$current || !in_array($current['state'],['active','released'],true)) throw new RuntimeException('Policy receipt has no committed effect');
                $this->pdo->commit(); return $ack+['duplicate'=>true,'state'=>$current['state']];
            }
            $this->query('INSERT INTO '.$this->table('receipt').' (receipt_key,bridge_id,event_id,body_hash,effect_id,received_at) VALUES (?,?,?,?,?,?)',[$receiptKey,$binding['bridge_id'],$event['event_id'],$bodyHash,$d['policy_effect_id'],$now]);
            $lock=$this->pdo->getAttribute(PDO::ATTR_DRIVER_NAME)==='mysql'?' FOR UPDATE':'';
            $row=$this->query('SELECT * FROM '.$this->table('effect').' WHERE effect_key=?'.$lock,[$effectKey])->fetch(PDO::FETCH_ASSOC);
            if ($row && ((int)$row['customer_id']!==$binding['customer_id'] || $row['recipient']!==$d['recipient'] || $row['message_id']!==$d['message_id'] || $row['campaign_uid']!==$d['campaign_id'])) throw new RuntimeException('Effect identity conflict');
            $released=$event['event_type']==='recipient.policy_released';
            $state=$released?'released':'active';
            if ($row && ($row['state']==='released' || (int)$row['event_at']>(int)$event['timestamp'])) {
                $this->pdo->commit(); return $ack+['ignored'=>true,'state'=>$row['state']];
            }
            $values=[$d['action'],$d['expires_at'],$state,$event['timestamp'],$d['rule_id'],$d['rule_revision'],$d['reason'],$now];
            if ($row) {
                $this->query('UPDATE '.$this->table('effect').' SET action=?,expires_at=?,state=?,event_at=?,rule_id=?,rule_revision=?,reason=?,updated_at=? WHERE effect_key=?',array_merge($values,[$effectKey]));
            } else {
                $this->query('INSERT INTO '.$this->table('effect').' (action,expires_at,state,event_at,rule_id,rule_revision,reason,updated_at,effect_key,bridge_id,effect_id,customer_id,recipient,message_id,campaign_uid) VALUES (?,?,?,?,?,?,?,?,?,?,?,?,?,?,?)',array_merge($values,[$effectKey,$binding['bridge_id'],$d['policy_effect_id'],$binding['customer_id'],$d['recipient'],$d['message_id'],$d['campaign_id']]));
            }
            $this->pdo->commit(); return $ack+['state'=>$state];
        } catch (Throwable $e) { if ($this->pdo->inTransaction()) $this->pdo->rollBack(); throw $e; }
    }
    public function effective(int $customerId, string $recipient, int $now): ?array
    {
        $rows=$this->query('SELECT * FROM '.$this->table('effect')." WHERE customer_id=? AND recipient=? AND state='active' AND (action='permanent' OR expires_at>?) ORDER BY CASE WHEN action='permanent' THEN 0 ELSE 1 END, expires_at DESC",[$customerId,$recipient,$now])->fetchAll(PDO::FETCH_ASSOC);
        return $rows[0]??null;
    }
}
