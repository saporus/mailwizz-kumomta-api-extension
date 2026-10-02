<?php declare(strict_types=1);
/** Prepare reviewable candidate copies only. Never modifies the supplied MailWizz root. */
if (PHP_SAPI!=='cli' || count($argv)!==3) { fwrite(STDERR,"Usage: php prepare-policy-scheduler.php MAILWIZZ_ROOT EMPTY_OUTPUT_DIRECTORY\n"); exit(2); }
$input=realpath($argv[1]); $output=$argv[2];
if (!$input || file_exists($output)) throw new RuntimeException('A valid input and a new output directory are required');
$init=(string)file_get_contents($input.'/apps/init.php');
if (!preg_match('/define\([\'\"]MW_VERSION[\'\"],\s*[\'\"]([^\'\"]+)[\'\"]\)/',$init,$version) || $version[1]!=='2.7.3') throw new RuntimeException('Only the verified MailWizz 2.7.3 scheduler is supported by this release');
$files=['apps/console/commands/SendCampaignsCommand.php','apps/common/components/db/behaviors/CampaignQueueTableBehavior.php'];
$result=[];$baseHashes=[];
function replaceOnce(string $body,string $needle,string $replacement): string {
    if (substr_count($body,$needle)!==1) throw new RuntimeException('Unsupported or already patched scheduler source; exact context did not match');
    return str_replace($needle,$replacement,$body);
}
foreach($files as $file) {
    $original=(string)file_get_contents($input.'/'.$file);$baseHashes[$file]=hash('sha256',$original);
    $body=str_replace("\r\n","\n",$original);
    if (strpos($body,'MAGIC_SMTP_POLICY_SCHEDULER_V1')!==false) throw new RuntimeException('Already patched');
    if (basename($file)==='SendCampaignsCommand.php') {
        // Custom count/find overrides bypass these standard paths. Refuse them while policy is configured.
        foreach(['count','find'] as $kind) {
            $needle="if (hooks()->hasFilters('console_send_campaigns_command_".$kind."_subscribers')) {";
            $body=replaceOnce($body,$needle,$needle."\n            if (class_exists('MagicSmtpPolicyRuntime', false) && MagicSmtpPolicyRuntime::bridge()) throw new RuntimeException('Recipient policy is incompatible with custom subscriber selector overrides');");
        }
        $needle='return (int)$campaign->countSubscribers($criteria);';
        $body=replaceOnce($body,$needle,"// MAGIC_SMTP_POLICY_SCHEDULER_V1: holds remain pending; permanent effects do not.\n        if (class_exists('MagicSmtpPolicyRuntime', false)) MagicSmtpPolicyRuntime::criteria(\$criteria, \$campaign, false);\n        ".$needle);
        $needle='return $campaign->findSubscribers($offset, $limit, $criteria);';
        $body=replaceOnce($body,$needle,"if (class_exists('MagicSmtpPolicyRuntime', false)) MagicSmtpPolicyRuntime::criteria(\$criteria, \$campaign, true);\n        ".$needle);
        $needle="protected function markCampaignSent(Campaign \$campaign)\n    {";
        $body=replaceOnce($body,$needle,$needle."\n        if (class_exists('MagicSmtpPolicyRuntime', false) && !MagicSmtpPolicyRuntime::canComplete(\$campaign)) return false;");
        $needle='foreach ($subscribers as $index => $subscriber) {';
        // Only the sending loop, not cleanup or queue population.
        $at=strpos($body,'protected function processSubscribersLoop(');
        if ($at===false) throw new RuntimeException('Unsupported sending loop');
        $pos=strpos($body,$needle,$at);
        if ($pos===false) throw new RuntimeException('Unsupported sending loop body');
        $insert="\n            // Recheck after selection; an intervening hold must not send or consume the queue row.\n            if (class_exists('MagicSmtpPolicyRuntime', false) && (\$policy = MagicSmtpPolicyRuntime::effective(\$campaign, \$subscriber))) {\n                if (\$policy['action'] === 'permanent') \$this->logDelivery(\$subscriber, 'Policy suppressed: ' . \$policy['reason'], CampaignDeliveryLog::STATUS_SUPPRESSED, '', \$server, \$campaign);\n                continue;\n            }";
        $body=substr_replace($body,$needle.$insert,$pos,strlen($needle));
    } else {
        $needle='$row = $query->queryRow();';
        $body=replaceOnce($body,$needle,"// MAGIC_SMTP_POLICY_SCHEDULER_V1: do not delete or reschedule held queue rows.\n        if (class_exists('MagicSmtpPolicyRuntime', false)) MagicSmtpPolicyRuntime::queueQuery(\$query, \$owner, \$tableName, false);\n        ".$needle);
        $needle="\$query->order('subscriber_id ASC')->offset(\$offset)->limit(\$limit);";
        $body=replaceOnce($body,$needle,"if (class_exists('MagicSmtpPolicyRuntime', false)) MagicSmtpPolicyRuntime::queueQuery(\$query, \$owner, \$tableName, true);\n        ".$needle);
    }
    $result[$file]=$body;
}
mkdir($output,0700,true);
$manifest=['contract'=>'magic-smtp-policy-scheduler-v1','mailwizz_version'=>$version[1],'acceptance'=>'pending','base_sha256'=>$baseHashes,'sha256'=>[]];
foreach($result as $file=>$body) {
    $path=$output.'/'.$file; if (!is_dir(dirname($path))) mkdir(dirname($path),0700,true);
    file_put_contents($path,$body); $manifest['sha256'][$file]=hash('sha256',$body);
}
file_put_contents($output.'/policy-scheduler-manifest.json',json_encode($manifest,JSON_PRETTY_PRINT|JSON_UNESCAPED_SLASHES)."\n");
echo "Prepared candidate copies. Original MailWizz files are unchanged. Acceptance remains pending.\n";
