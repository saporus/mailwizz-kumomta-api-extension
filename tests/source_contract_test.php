<?php declare(strict_types=1);

$root = dirname(__DIR__);
$bootstrap = file_get_contents($root . '/magicsmtp/MagicsmtpExt.php');
$smtpModel = file_get_contents($root . '/magicsmtp/models/DeliveryServerMagicSmtp.php');
$webApiModel = file_get_contents($root . '/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php');

$checks = array(
    'release version is 1.1.2' => strpos($bootstrap, "public \$version = '1.1.2';") !== false,
    'DSWH filter is registered' => strpos($bootstrap, "addFilter('dswh_process_map'") !== false,
    'DSWH processor callback is mapped' => strpos($bootstrap, "array(\$this, '_processDswhWebhook')") !== false,
    'MailWizz core controller is not modified' => strpos($bootstrap, 'file_put_contents') === false,
    'MailWizz core controller is not bundled' => !file_exists($root . '/magicsmtp/DswhController.php'),
    'SMTP model uses the standard DSWH route' => strpos($smtpModel, "getFrontendUrl('dswh/' . (int)\$this->server_id)") !== false,
    'Web API model uses the standard DSWH route' => strpos($webApiModel, "getFrontendUrl('dswh/' . (int)\$this->server_id)") !== false,
    'Webhook JSON uses MailWizz renderer' => strpos($smtpModel, 'controller()->renderJson(') !== false,
    'Webhook JSON no longer writes headers directly' => strpos($smtpModel, "header('Content-Type: application/json')") === false,
);

$failed = array();
foreach ($checks as $description => $passed) {
    echo ($passed ? '[PASS] ' : '[FAIL] ') . $description . PHP_EOL;
    if (!$passed) {
        $failed[] = $description;
    }
}

if ($failed) {
    fwrite(STDERR, PHP_EOL . count($failed) . ' source contract check(s) failed.' . PHP_EOL);
    exit(1);
}

echo PHP_EOL . 'All source contract checks passed.' . PHP_EOL;
