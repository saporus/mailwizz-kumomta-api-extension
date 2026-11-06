<?php declare(strict_types=1);
/** Explicit, hash-guarded deployment utility. dry-run never writes the application. */
if (PHP_SAPI!=='cli' || count($argv)!==5 || !in_array($argv[1],['dry-run','apply','rollback'],true)) {
    fwrite(STDERR,"Usage: php apply-policy-scheduler-281.php dry-run|apply|rollback MAILWIZZ_ROOT CANDIDATE_DIRECTORY BACKUP_DIRECTORY\n");exit(2);
}
[$script,$mode,$root,$candidate,$backup]=$argv;
$root=realpath($root);$candidate=realpath($candidate);
if (!$root || !$candidate) throw new RuntimeException('Application and candidate directories must exist');
if ($mode==='rollback') {
    $settings=dirname($root).'/private/magicsmtp-policy/connect-settings.json';
    if (file_exists($settings)||is_link($settings)) throw new RuntimeException('Configured connection authority prevents scheduler rollback; verify an equivalent safeguard and safe handover first');
    // Read configuration only; never bootstrap or disclose protected parameters.
    foreach(['common','console','frontend','customer','backend']as$app){
        foreach(glob($root.'/apps/'.$app.'/config/*.php')?:[]as$config){
            $source=file_get_contents($config);
            if($source===false||preg_match('/magicsmtp\.(?:connect|policyBridges)/',$source))throw new RuntimeException('Protected application authority requires explicit safe-handover review before rollback');
        }
    }
}
function stagePolicy281Source(string $source,string $target,string $kind):string {
    clearstatcache(true,$target);$state=stat($target);
    if($state===false)throw new RuntimeException('Target metadata unavailable');
    $tmp=$target.'.magic-'.$kind.'-'.bin2hex(random_bytes(8));
    if(!copy($source,$tmp))throw new RuntimeException('Could not stage source');
    try{
        clearstatcache(true,$tmp);
        if(fileowner($tmp)!==$state['uid']&&!chown($tmp,$state['uid']))throw new RuntimeException('Could not preserve source owner');
        if(filegroup($tmp)!==$state['gid']&&!chgrp($tmp,$state['gid']))throw new RuntimeException('Could not preserve source group');
        if(!chmod($tmp,$state['mode']&0777))throw new RuntimeException('Could not preserve source permissions');
        clearstatcache(true,$tmp);$actual=stat($tmp);
        if($actual['uid']!==$state['uid']||$actual['gid']!==$state['gid']||($actual['mode']&0777)!==($state['mode']&0777))throw new RuntimeException('Staged source metadata differs');
        return$tmp;
    }catch(Throwable$e){@unlink($tmp);throw$e;}
}
$manifest=json_decode((string)file_get_contents($candidate.'/policy-scheduler-manifest.json'),true,16,JSON_THROW_ON_ERROR);
$files=['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'];
if (($manifest['contract']??'')!=='magic-smtp-policy-scheduler-v1' || ($manifest['acceptance']??'')!=='passed'
    || ($manifest['mailwizz_version']??'')!=='2.8.1' || !hash_equals((string)($manifest['test_sha256']??''),hash_file('sha256',dirname(__DIR__).'/tests/policy_scheduler_281_runtime_test.php'))) throw new RuntimeException('Exact candidate acceptance is required');
$qualified=[
    'apps/console/commands/SendCampaignsCommand.php'=>'96091aba524927bc6b0dd1b5f7ac241243a4a69180695ab546a1d5122c7353a2',
    'apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'=>'5fea83a34a648f6971b99aa4994e888214381aa05fcb73c6ce8d27175e59ae8f',
];
if(($manifest['qualification_profile']??'')!=='mailwizz-2.8.1-policy-20261106'||!is_array($manifest['sha256']??null)||count($manifest['sha256'])!==count($qualified))throw new RuntimeException('Unqualified 2.8.1 profile');
foreach($qualified as$file=>$sha)if(($manifest['sha256'][$file]??null)!==$sha)throw new RuntimeException('Unqualified 2.8.1 source');
if (!preg_match('/define\([\'\"]MW_VERSION[\'\"],\s*[\'\"]2\.8\.1[\'\"]\)/',(string)file_get_contents($root.'/apps/init.php'))) throw new RuntimeException('Installed application version changed');
foreach($files as $file) {
    $current=hash_file('sha256',$root.'/'.$file);
    $expected=$mode==='rollback'?($manifest['sha256'][$file]??''):($manifest['base_sha256'][$file]??'');
    if (!hash_equals($expected,$current)) throw new RuntimeException('Installed source differs: '.$file);
    if (!hash_equals($manifest['sha256'][$file],hash_file('sha256',$candidate.'/'.$file))) throw new RuntimeException('Candidate source differs: '.$file);
    if ($mode==='rollback' && (!is_file($backup.'/'.$file) || !hash_equals($manifest['base_sha256'][$file],hash_file('sha256',$backup.'/'.$file)))) throw new RuntimeException('Verified rollback copy is missing');
}
if ($mode==='dry-run') {echo "PASS exact current hashes, candidate hashes, version and scheduler acceptance. No changes.\n";exit(0);}
if ($mode==='apply') {
    if (file_exists($backup)) throw new RuntimeException('Backup directory must be new');
    foreach($files as $file) {mkdir(dirname($backup.'/'.$file),0700,true);if(!copy($root.'/'.$file,$backup.'/'.$file))throw new RuntimeException('Could not preserve original');}
    file_put_contents($backup.'/policy-scheduler-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
}
$written=[];
try {
    foreach($files as $file) {
        $target=$root.'/'.$file;$source=($mode==='rollback'?$backup:$candidate).'/'.$file;
        $tmp=stagePolicy281Source($source,$target,'policy');
        if (!rename($tmp,$target)) {@unlink($tmp);throw new RuntimeException('Could not replace source');}
        $written[]=$file;
    }
} catch(Throwable $e) {
    // Restore only files this invocation wrote, from already verified copies.
    foreach(array_reverse($written) as $file) {
        $source=($mode==='rollback'?$candidate:$backup).'/'.$file;$target=$root.'/'.$file;
        $tmp=stagePolicy281Source($source,$target,'restore');
        if(!rename($tmp,$target)){@unlink($tmp);throw new RuntimeException('Automatic source restoration failed; retain maintenance state');}
    }
    throw $e;
}
echo "PASS ".$mode."; preserve the backup and policy effect tables. No campaign, cron or subscriber state was changed.\n";
