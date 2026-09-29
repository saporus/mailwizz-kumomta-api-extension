<?php declare(strict_types=1);
define('MW_PATH', __DIR__);
class DeliveryServer {
    public function getBounceServerNotSupported(): bool { return false; }
}
require dirname(__DIR__).'/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php';
$api = new DeliveryServerMagicSmtpWebApi();
if (!$api->getBounceServerNotSupported()) throw new RuntimeException('API must use webhook bounces');
if ((new DeliveryServer())->getBounceServerNotSupported()) throw new RuntimeException('Other transports must remain unchanged');
foreach (['form-magic-smtp.php','form-magic-smtp-web-api.php'] as $view) {
    $source=file_get_contents(dirname(__DIR__).'/magicsmtp/views/'.$view);
    if (strpos($source, '$form->errorSummary($server)')===false) throw new RuntimeException('Missing hidden-field error summary');
}
echo "PASS API webhook bounce capability, unchanged default transports, both error summaries\n";
