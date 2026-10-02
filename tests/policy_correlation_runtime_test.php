<?php declare(strict_types=1);
define('MW_PATH',__DIR__);
require dirname(__DIR__).'/magicsmtp/MagicSmtpPolicyRuntime.php';
function check($v,$name){if(!$v)throw new RuntimeException($name);echo 'PASS '.$name."\n";}
$pdo=new PDO('sqlite::memory:');$pdo->setAttribute(PDO::ATTR_ERRMODE,PDO::ERRMODE_EXCEPTION);
$database=new class($pdo){public $pdo,$tablePrefix='';function __construct($p){$this->pdo=$p;}function getPdoInstance(){return $this->pdo;}function setActive($v){}};
class Yii{static function app(){return (object)['db'=>$GLOBALS['database']];}}
$binding=['bridge_id'=>'bridge','tenant_id'=>'tenant','customer_id'=>10,'server_ids'=>[42],'secret'=>str_repeat('x',40),'enabled'=>true];
function app_param($key,$default=null){return $key==='magicsmtp.policyBridges'?[$GLOBALS['binding']]:$default;}
class CampaignDeliveryLog{const STATUS_SUCCESS='success';static function model(){return new static();}function tableName(){return '{{campaign_delivery_log}}';}}
class CampaignDeliveryLogArchive extends CampaignDeliveryLog{function tableName(){return '{{campaign_delivery_log_archive}}';}}
$pdo->exec('CREATE TABLE campaign(campaign_id INTEGER,campaign_uid TEXT,customer_id INTEGER,list_id INTEGER);CREATE TABLE list_subscriber(subscriber_id INTEGER,subscriber_uid TEXT,list_id INTEGER,email TEXT,status TEXT);CREATE TABLE campaign_delivery_log(campaign_id INTEGER,subscriber_id INTEGER,server_id INTEGER,email_message_id TEXT,status TEXT);CREATE TABLE campaign_delivery_log_archive(campaign_id INTEGER,subscriber_id INTEGER,server_id INTEGER,email_message_id TEXT,status TEXT);CREATE TABLE email_blacklist(email TEXT,reason TEXT)');
$pdo->exec("INSERT INTO campaign VALUES(101,'campaign-a',10,1),(102,'campaign-b',11,2),(103,'campaign-c',10,1);INSERT INTO list_subscriber VALUES(1,'subscriber-a',1,'User@Example.test','confirmed'),(2,'subscriber-b',2,'User@Example.test','blacklisted');INSERT INTO campaign_delivery_log VALUES(101,1,42,'<message@example.test>','success'),(102,2,42,'<message@example.test>','success');INSERT INTO email_blacklist VALUES('user@example.test','independent complaint')");
$store=MagicSmtpPolicyRuntime::bridge()->store();$store->install();
$data=['recipient'=>'user@example.test','message_id'=>'message@example.test','campaign_id'=>''];
check(MagicSmtpPolicyRuntime::correlate($binding,$data)['campaign_uid']==='campaign-a','missing campaign derives unique customer-owned dispatch');
$data['campaign_id']='101';check(MagicSmtpPolicyRuntime::correlate($binding,$data)['campaign_uid']==='campaign-a','numeric campaign ID resolves through trusted native lookup');
$data['campaign_id']='campaign-a';check(MagicSmtpPolicyRuntime::correlate($binding,$data)['subscriber_uid']==='subscriber-a','campaign UID remains supported');
$data['campaign_id']='102';check(MagicSmtpPolicyRuntime::correlate($binding,$data)===null,'numeric campaign ID cannot cross customers');
$data['campaign_id']='';$bad=$binding;$bad['server_ids']=[99];check(MagicSmtpPolicyRuntime::correlate($bad,$data)===null,'delivery server must be explicitly authorized');
$data['recipient']='other@example.test';check(MagicSmtpPolicyRuntime::correlate($binding,$data)===null,'recipient must match original dispatch');$data['recipient']='user@example.test';
$pdo->exec("UPDATE campaign_delivery_log SET status='giveup' WHERE campaign_id=101");check(MagicSmtpPolicyRuntime::correlate($binding,$data)===null,'non-success native log is not historical dispatch proof');
$pdo->exec("INSERT INTO campaign_delivery_log_archive VALUES(101,1,42,'message@example.test','success')");check(MagicSmtpPolicyRuntime::correlate($binding,$data)['campaign_uid']==='campaign-a','successful archived delivery is supported');
$pdo->exec("UPDATE campaign_delivery_log SET status='success' WHERE campaign_id=101");check(MagicSmtpPolicyRuntime::correlate($binding,$data)['campaign_uid']==='campaign-a','same dispatch in current and archive is not false ambiguity');
$pdo->exec("INSERT INTO campaign_delivery_log VALUES(103,1,42,'message@example.test','success')");check(MagicSmtpPolicyRuntime::correlate($binding,$data)===null,'multiple customer campaigns with same original identity are ambiguous');
$data['campaign_id']='103';check(MagicSmtpPolicyRuntime::correlate($binding,$data)['campaign_uid']==='campaign-c','explicit proven campaign can resolve ambiguity');
check((int)$pdo->query('SELECT COUNT(*) FROM email_blacklist')->fetchColumn()===1,'native blacklist is preserved');
echo "PASS historical native dispatch correlation, synthetic database only\n";
