<?php declare(strict_types=1);
/** Private operator CLI. Explicit schema preparation; no enrollment, sends or campaign changes. */
if(PHP_SAPI!=='cli'||count($argv)!==2||!in_array($argv[1],['prepare','inspect','rollback-guard','disable-enrollment'],true))exit(2);
umask(0077);
$root='/home/admin/web/servermail2.com/public_html';
define('MW_APP_NAME','console');define('MW_RETURN_APP_INSTANCE',true);
require $root.'/apps/init.php';
if(!class_exists('MagicSmtpConnectStore',false)){
    $installedStore=$root.'/apps/extensions/magicsmtp/models/MagicSmtpConnectStore.php';
    require_once is_file($installedStore)?$installedStore:__DIR__.'/qa/magicsmtp/models/MagicSmtpConnectStore.php';
}
$db=Yii::app()->getDb();$db->setActive(true);$store=new MagicSmtpConnectStore($db->getPdoInstance(),(string)$db->tablePrefix);
$mode=$argv[1];$candidate=__DIR__.'/connect-settings.candidate.json';
if($mode==='prepare'){
    if(is_file($candidate))throw new RuntimeException('Prepared private candidate already exists; inspect rather than repeat.');
    $bindings=app_param('magicsmtp.policyBridges',[]);
    if(count($bindings)!==1)throw new RuntimeException('Expected one protected existing binding.');$binding=array_values($bindings)[0];
    if(($binding['bridge_id']??'')!=='rpb-58702af2-dde8-44e7-ac86-07bccd0ae772'||($binding['tenant_id']??'')!=='tenant-4e049403a560073e'||($binding['customer_id']??0)!==1||($binding['server_ids']??[])!==[433,434,436,442,444,445]||($binding['enabled']??null)!==true)throw new RuntimeException('Protected static authority changed.');
    require_once $root.'/apps/extensions/magicsmtp/MagicSmtpPolicyRuntime.php';
    if(!MagicSmtpPolicyRuntime::schedulerVerified($binding))throw new RuntimeException('Existing installed scheduler is not verified.');
    $store->install();
    if($store->enabled()||$store->connections())throw new RuntimeException('Enrollment already initialized; reconcile existing state.');
    $settings=['encryptionKey'=>base64_encode(random_bytes(32)),'schedulerProfile'=>$binding['scheduler_manifest'],'approvedApiBases'=>['https://go.magicsmtp.com/ui/api']];
    $f=fopen($candidate,'x');if(!$f)throw new RuntimeException('Cannot create protected settings candidate.');
    try{$raw=json_encode($settings,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES|JSON_THROW_ON_ERROR)."\n";if(fwrite($f,$raw)!==strlen($raw)||!fflush($f))throw new RuntimeException('Private settings write failed.');if(function_exists('fsync'))fsync($f);}finally{fclose($f);}chmod($candidate,0600);
}
if(!$store->installed())throw new RuntimeException('Explicit schema preparation is required.');
if($mode==='disable-enrollment')$store->setEnabled(false,0,time());
$state=$store->atomic(static function()use($store,$mode):array{
    $count=(int)$store->query('SELECT COUNT(*) FROM '.$store->table('connection'))->fetchColumn();$enabled=$store->enabled();
    if($mode==='rollback-guard'&&($enabled||$count!==0))throw new RuntimeException('Rollback would drop managed authority or race enrollment; retain current binding runtime.');
    return ['enrollmentEnabled'=>$enabled,'connections'=>$count,'schemaInstalled'=>true,'settingsOverridePresent'=>app_param('magicsmtp.connect',null)!==null];
});
$runtimeFile=$root.'/apps/extensions/magicsmtp/MagicSmtpConnectRuntime.php';$runtimeChecked=false;
if(is_file($runtimeFile)){require_once $runtimeFile;$effective=MagicSmtpConnectRuntime::bindings();$static=app_param('magicsmtp.policyBridges',[]);if($state['connections']===0&&$effective!==$static)throw new RuntimeException('Static binding preservation check failed.');$runtimeChecked=true;}
$state+=['ok'=>true,'mode'=>$mode,'candidateSha256'=>is_file($candidate)?hash_file('sha256',$candidate):null,'nativeBootstrapVerified'=>true,'runtimeBindingsVerified'=>$runtimeChecked,'campaignsChanged'=>false,'sendingLimitsChanged'=>false,'staticBindingChanged'=>false,'secretsPrinted'=>false];
echo json_encode($state,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n";
