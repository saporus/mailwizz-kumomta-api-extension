<?php declare(strict_types=1);
require dirname(__DIR__) . '/magicsmtp/models/MagicSmtpShortRetry.php';
function check($v,$label) { if (!$v) throw new RuntimeException($label); echo "PASS $label\n"; }
class Campaign {
    const STATUS_PROCESSING='processing', STATUS_SENDING='sending';
    public $campaign_id=1,$list_id=1,$group_id=0,$status='processing',$customer;
    function __construct() { $this->customer=new class {function getIsOverQuota(){return $GLOBALS['blocked']==='customer_quota';}}; }
    static function model(){return new self();}
    function findByPk($id){if($GLOBALS['blocked']==='campaign_deleted')return null;$fresh=clone $GLOBALS['campaign'];if($GLOBALS['blocked']==='paused')$fresh->status='paused';return $fresh;}
}
class ListSubscriber {
    public $subscriber_id=2,$subscriber_uid='subscriber-2',$list_id=1,$email='synthetic@fixture.invalid';
    static function model(){return new self();}
    function findByPk($id){if($GLOBALS['blocked']==='subscriber_deleted')return null;$fresh=clone $GLOBALS['subscriber'];if($GLOBALS['blocked']==='changed_recipient')$fresh->email='changed@fixture.invalid';if($GLOBALS['blocked']==='changed_list')$fresh->list_id=3;return $fresh;}
    function getIsConfirmed(){return $GLOBALS['blocked']!=='unsubscribed';}
    function getIsBlacklisted($options){return $GLOBALS['blocked']==='blacklisted';}
}
class EmailBlacklist{const CHECK_ZONE_CAMPAIGN='campaign';}
class CustomerSuppressionListEmail{static function isSubscriberListedByCampaign($s,$c){return $GLOBALS['blocked']==='suppressed';}}
class CampaignGroupBlockSubscriber{static function model(){return new self();}function countByAttributes($a){return $GLOBALS['blocked']==='group_block';}}
class MagicSmtpPolicyRuntime{static function effective($c,$s){if($GLOBALS['blocked']==='policy_storage_failure')throw new RuntimeException('storage');return $GLOBALS['blocked']==='policy_hold'?['action'=>'temporary']:null;}}
$server=new class{function canSendToDomainOf($email){return $GLOBALS['blocked']!=='domain_policy';}function getIsOverQuota(){return $GLOBALS['blocked']==='server_quota';}};
$campaign=new Campaign();$subscriber=new ListSubscriber();$blocked='';
MagicSmtpShortRetry::assertEligible($server,$campaign,$subscriber);check(true,'unchanged native campaign recipient remains eligible');
foreach(['paused','campaign_deleted','subscriber_deleted','changed_recipient','changed_list','unsubscribed','blacklisted','suppressed','group_block','policy_hold','policy_storage_failure','domain_policy','server_quota','customer_quota'] as $blocked){
    $campaign->group_id=$blocked==='group_block'?7:0;
    try{MagicSmtpShortRetry::assertEligible($server,$campaign,$subscriber);throw new RuntimeException('guard passed');}
    catch(Exception $e){check($e->getCode()===98,'fresh native guard stops '.$blocked.' and preserves native pause handling');}
}
check($campaign->status==='processing'&&$subscriber->email==='synthetic@fixture.invalid','eligibility checks do not mutate original records');
