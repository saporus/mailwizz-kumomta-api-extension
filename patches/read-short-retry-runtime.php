<?php declare(strict_types=1);
// Normal application classes under a read-only transaction; no send/update calls.
try {
    chdir('/home/admin/web/servermail2.com/public_html');
    define('MW_APP_NAME','console');define('MW_RETURN_APP_INSTANCE',true);
    $app=require 'apps/init.php';$db=Yii::app()->getDb();
    $db->createCommand('SET SESSION MAX_EXECUTION_TIME=3000')->execute();
    $db->createCommand('SET SESSION TRANSACTION READ ONLY')->execute();$tx=$db->beginTransaction();
    $manager=$app->getComponent('extensionsManager');$manager->loadAllExtensions();
    $extension=$manager->getExtensionInstance('magicsmtp');
    if(!$extension||!class_exists('DeliveryServerMagicSmtpWebApi',false)||!class_exists('MagicSmtpPolicyRuntime',false))throw new RuntimeException('class load');
    $bridge=MagicSmtpPolicyRuntime::bridge();$checks=[];
    foreach([433,434,436,442,444]as$id){
        $binding=$bridge?$bridge->bindingForServer($id,'tenant-4e049403a560073e',1):null;
        $checks[]=['serverId'=>$id,'schedulerVerified'=>(bool)($binding&&MagicSmtpPolicyRuntime::schedulerVerified($binding))];
    }
    $out=['ok'=>true,'readOnly'=>true,'at'=>gmdate('c'),'mailwizzVersion'=>MW_VERSION,'sourceVersion'=>$extension->version,'registryVersion'=>$extension->getDatabaseVersion(),'mustUpdate'=>$extension->getMustUpdate(),'normalClassesLoaded'=>true,'bindingCount'=>count(app_param('magicsmtp.policyBridges',[])),'checks'=>$checks];
    $tx->rollback();echo json_encode($out,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES);
}catch(Throwable $e){if(isset($tx)&&$tx->getActive())$tx->rollback();echo json_encode(['ok'=>false,'error'=>get_class($e),'code'=>(string)$e->getCode()]);exit(1);}
