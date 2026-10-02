<?php declare(strict_types=1);
/** Explicit, hash-guarded deployment utility. dry-run never writes the application. */
if (PHP_SAPI!=='cli' || count($argv)!==5 || !in_array($argv[1],['dry-run','apply','rollback'],true)) {
    fwrite(STDERR,"Usage: php apply-policy-scheduler.php dry-run|apply|rollback MAILWIZZ_ROOT CANDIDATE_DIRECTORY BACKUP_DIRECTORY\n");exit(2);
}
[$script,$mode,$root,$candidate,$backup]=$argv;
$root=realpath($root);$candidate=realpath($candidate);
if (!$root || !$candidate) throw new RuntimeException('Application and candidate directories must exist');
$manifest=json_decode((string)file_get_contents($candidate.'/policy-scheduler-manifest.json'),true,16,JSON_THROW_ON_ERROR);
$files=['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'];
if (($manifest['contract']??'')!=='magic-smtp-policy-scheduler-v1' || ($manifest['acceptance']??'')!=='passed'
    || ($manifest['mailwizz_version']??'')!=='2.7.3' || !hash_equals((string)($manifest['test_sha256']??''),hash_file('sha256',dirname(__DIR__).'/tests/policy_scheduler_runtime_test.php'))) throw new RuntimeException('Exact candidate acceptance is required');
if (!preg_match('/define\([\'\"]MW_VERSION[\'\"],\s*[\'\"]2\.7\.3[\'\"]\)/',(string)file_get_contents($root.'/apps/init.php'))) throw new RuntimeException('Installed application version changed');
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
        $tmp=$target.'.magic-policy-'.bin2hex(random_bytes(8));
        if (!copy($source,$tmp)) throw new RuntimeException('Could not stage source');
        chmod($tmp,fileperms($target)&0777);
        if (!rename($tmp,$target)) {@unlink($tmp);throw new RuntimeException('Could not replace source');}
        $written[]=$file;
    }
} catch(Throwable $e) {
    // Restore only files this invocation wrote, from already verified copies.
    foreach(array_reverse($written) as $file) {
        $source=($mode==='rollback'?$candidate:$backup).'/'.$file;$target=$root.'/'.$file;
        $tmp=$target.'.magic-restore-'.bin2hex(random_bytes(8));
        if(!copy($source,$tmp)||!rename($tmp,$target)) throw new RuntimeException('Automatic source restoration failed; retain maintenance state');
    }
    throw $e;
}
echo "PASS ".$mode."; preserve the backup and policy effect tables. No campaign, cron or subscriber state was changed.\n";
