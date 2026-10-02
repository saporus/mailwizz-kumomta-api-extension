<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');
require_once __DIR__.'/models/MagicSmtpPolicyBridge.php';

/** MailWizz adapter; policy effects never change native subscriber/blacklist status. */
final class MagicSmtpPolicyRuntime
{
    private static $bridge;
    public static function bridge(): ?MagicSmtpPolicyBridge
    {
        $bindings=function_exists('app_param')?app_param('magicsmtp.policyBridges',[]):[];
        if (!$bindings) return null;
        if (self::$bridge) return self::$bridge;
        $db=Yii::app()->db; $db->setActive(true);
        $store=new MagicSmtpPolicyStore($db->getPdoInstance(),(string)$db->tablePrefix);
        return self::$bridge=new MagicSmtpPolicyBridge($store,$bindings,[self::class,'correlate'],[self::class,'schedulerVerified']);
    }
    public static function schedulerVerified(array $binding): bool
    {
        $common=Yii::getPathOfAlias('common');
        $root=$common?dirname(dirname($common)):false;
        $manifest=$binding['scheduler_manifest']??null;
        if (!$root || !is_array($manifest) || ($manifest['contract']??'')!=='magic-smtp-policy-scheduler-v1' || ($manifest['acceptance']??'')!=='passed') return false;
        if (!defined('MW_VERSION') || MW_VERSION!=='2.7.3' || ($manifest['mailwizz_version']??'')!==MW_VERSION) return false;
        if (hooks()->hasFilters('console_send_campaigns_command_count_subscribers') || hooks()->hasFilters('console_send_campaigns_command_find_subscribers')) return false;
        $files=['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'];
        foreach ($files as $file) {
            $expected=$manifest['sha256'][$file]??'';
            if (!preg_match('/^[a-f0-9]{64}$/D',$expected) || !is_file($root.'/'.$file) || !hash_equals($expected,hash_file('sha256',$root.'/'.$file))) return false;
            if (strpos((string)file_get_contents($root.'/'.$file),'MAGIC_SMTP_POLICY_SCHEDULER_V1')===false) return false;
        }
        return true;
    }
    public static function correlate(array $binding,array $data): ?array
    {
        $matches=[];
        $ids=$binding['server_ids'];
        $prefix=(string)Yii::app()->db->tablePrefix;
        if (!preg_match('/^[A-Za-z0-9_]*$/D',$prefix)) throw new RuntimeException('Invalid table prefix');
        foreach ([CampaignDeliveryLog::class,CampaignDeliveryLogArchive::class] as $class) {
            $table=str_replace(['{{','}}'],[$prefix,''],$class::model()->tableName());
            if (!preg_match('/^[A-Za-z0-9_]+$/D',$table)) throw new RuntimeException('Invalid delivery log table');
            $sql='SELECT DISTINCT c.campaign_uid,s.subscriber_uid FROM '.$table.' d INNER JOIN '.$prefix.'campaign c ON c.campaign_id=d.campaign_id INNER JOIN '.$prefix.'list_subscriber s ON s.subscriber_id=d.subscriber_id AND s.list_id=c.list_id WHERE d.status=? AND (d.email_message_id=? OR d.email_message_id=?) AND c.customer_id=? AND LOWER(TRIM(s.email))=? AND d.server_id IN ('.implode(',',array_fill(0,count($ids),'?')).')';
            $params=array_merge([CampaignDeliveryLog::STATUS_SUCCESS,$data['message_id'],'<'.$data['message_id'].'>',$binding['customer_id'],$data['recipient']],$ids);
            if (!empty($data['campaign_id'])) {$sql.=' AND (c.campaign_uid=? OR c.campaign_id=?)';$params[]=$data['campaign_id'];$params[]=ctype_digit((string)$data['campaign_id'])?(int)$data['campaign_id']:-1;}
            if (!empty($data['subscriber_uid'])) {$sql.=' AND s.subscriber_uid=?';$params[]=$data['subscriber_uid'];}
            foreach (self::bridge()->store()->query($sql.' LIMIT 2',$params)->fetchAll(PDO::FETCH_ASSOC) as $row) $matches[$row['campaign_uid'].':'.$row['subscriber_uid']]=$row;
        }
        return count($matches)===1?array_values($matches)[0]:null;
    }
    public static function callback(int $serverId,string $raw,string $signature): array
    {
        $bridge=self::bridge();
        if (!$bridge) throw new RuntimeException('Policy bridge is not configured',403);
        return $bridge->receive($serverId,$raw,$signature);
    }
    public static function recordDispatch($server,array $params,string $recipient,string $messageId): void
    {
        $bridge=self::bridge();
        if (!$bridge || !$bridge->bindingForServer((int)$server->server_id)) return;
        $campaign=$params['campaign']??null;
        if (!$campaign && !empty($params['campaignUid'])) $campaign=Campaign::model()->findByAttributes(['campaign_uid'=>$params['campaignUid']]);
        if (!$campaign) throw new RuntimeException('Policy dispatch requires a campaign');
        $bridge->recordDispatch((int)$server->server_id,(int)$campaign->customer_id,$recipient,$messageId,(string)$campaign->campaign_uid,(string)($params['subscriberUid']??''));
    }
    public static function effective($campaign,$subscriber): ?array
    {
        $bridge=self::bridge();
        return $bridge?$bridge->store()->effective((int)$campaign->customer_id,MagicSmtpPolicyBridge::recipient((string)$subscriber->email),$bridge->now()):null;
    }
    /** Safe SQL fragment evaluated before LIMIT/OFFSET, including queue-table selection. */
    public static function exclusion(int $customerId,string $emailExpression,bool $selection=true): string
    {
        $bridge=self::bridge();
        if (!$bridge) return '1=1';
        if (!preg_match('/^[A-Za-z_][A-Za-z0-9_]*\.[A-Za-z_][A-Za-z0-9_]*$/D',$emailExpression)) throw new InvalidArgumentException('Unsafe policy email expression');
        $state=$selection?"(mp.action='permanent' OR mp.expires_at>".$bridge->now().")":"mp.action='permanent'";
        return 'NOT EXISTS (SELECT 1 FROM '.$bridge->store()->table('effect')." mp WHERE mp.customer_id=".$customerId." AND mp.recipient=LOWER(TRIM(".$emailExpression.")) AND mp.state='active' AND ".$state.')';
    }
    public static function criteria($criteria,$campaign,bool $selection)
    {
        $criteria->addCondition(self::exclusion((int)$campaign->customer_id,'t.email',$selection));
        return $criteria;
    }
    public static function queueQuery($query,$campaign,string $queueTable,bool $selection)
    {
        if (!self::bridge()) return $query;
        if (!preg_match('/^\{\{campaign_queue_[0-9]+\}\}$/D',$queueTable)) throw new InvalidArgumentException('Invalid campaign queue table');
        $query->andWhere('subscriber_id IN (SELECT ms.subscriber_id FROM {{list_subscriber}} ms WHERE '.self::exclusion((int)$campaign->customer_id,'ms.email',$selection).')');
        return $query;
    }
    /** Only held, otherwise-pending work blocks completion. Never resumes a paused campaign. */
    public static function canComplete($campaign): bool
    {
        $bridge=self::bridge();
        if (!$bridge) return true;
        $condition='NOT ('.self::exclusion((int)$campaign->customer_id,'t.email',true).') AND '.self::exclusion((int)$campaign->customer_id,'t.email',false);
        if ($campaign->getCanUseQueueTable()) {
            $queue=$campaign->queueTable->getTableName();
            if (!preg_match('/^\{\{campaign_queue_[0-9]+\}\}$/D',$queue)) throw new RuntimeException('Invalid campaign queue table');
            $count=(int)db()->createCommand('SELECT COUNT(*) FROM '.$queue.' q INNER JOIN {{list_subscriber}} t ON t.subscriber_id=q.subscriber_id WHERE t.status=:confirmed AND '.$condition)->queryScalar([':confirmed'=>ListSubscriber::STATUS_CONFIRMED]);
        } else {
            $criteria=new CDbCriteria();
            $criteria->with=['deliveryLogs'=>['select'=>false,'together'=>true,'joinType'=>'LEFT OUTER JOIN','on'=>'deliveryLogs.campaign_id=:magicPolicyCampaign','condition'=>'deliveryLogs.subscriber_id IS NULL','params'=>[':magicPolicyCampaign'=>(int)$campaign->campaign_id]]];
            $criteria->addCondition($condition);
            $count=(int)$campaign->countSubscribers($criteria);
        }
        if (!$count) return true;
        $status=$campaign->getReloadedStatus();
        if (in_array($status,[Campaign::STATUS_PROCESSING,Campaign::STATUS_SENDING],true)) $campaign->saveStatus(Campaign::STATUS_SENDING);
        return false;
    }
    public static function label(array $effect): string { return $effect['action']==='permanent'?'Policy suppressed':'Policy held'; }
    public static function gridProperties(array $properties,$controller): array
    {
        if (!$controller || $controller->getId()!=='campaign_reports' || !$controller->getAction() || $controller->getAction()->getId()!=='delivery' || empty($properties['columns']) || !self::bridge()) return $properties;
        $properties['columns'][]=['header'=>'Recipient policy','type'=>'raw','value'=>static function($row){
            $campaign=Campaign::model()->findByPk((int)$row->campaign_id);
            $subscriber=ListSubscriber::model()->findByPk((int)$row->subscriber_id);
            if (!$campaign || !$subscriber || !($effect=self::effective($campaign,$subscriber))) return '';
            $label=self::label($effect); $detail=$effect['reason'];
            if ($effect['expires_at']) $detail.='; until '.gmdate('Y-m-d H:i:s',(int)floor($effect['expires_at']/1000)).' UTC';
            return CHtml::tag('span',['title'=>$detail,'tabindex'=>0,'aria-label'=>$label.'. '.$detail],CHtml::encode($label));
        }];
        return $properties;
    }
}
