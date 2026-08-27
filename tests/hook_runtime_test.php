<?php declare(strict_types=1);

define('MW_PATH', __DIR__);

class ExtensionInit
{
}

class DeliveryServerMagicSmtp
{
    public static $processedServerId;
    public static $processedRequest;
    public $server_id;

    public function handleCallback($request)
    {
        self::$processedServerId = $this->server_id;
        self::$processedRequest = $request;
    }
}

function request()
{
    return 'test-request';
}

require dirname(__DIR__) . '/magicsmtp/MagicsmtpExt.php';

$extension = new MagicsmtpExt();
$server = (object)array(
    'type'      => 'magic-smtp-web-api',
    'server_id' => 42,
);

$map = $extension->_registerDswhProcessor(array(), $server, new stdClass());

if (!isset($map['magic-smtp-web-api']) || !is_callable($map['magic-smtp-web-api'])) {
    fwrite(STDERR, '[FAIL] DSWH callback was not registered.' . PHP_EOL);
    exit(1);
}

call_user_func_array($map['magic-smtp-web-api'], array($server, new stdClass()));

if (DeliveryServerMagicSmtp::$processedServerId !== 42) {
    fwrite(STDERR, '[FAIL] DSWH callback did not preserve the delivery-server ID.' . PHP_EOL);
    exit(1);
}

if (DeliveryServerMagicSmtp::$processedRequest !== 'test-request') {
    fwrite(STDERR, '[FAIL] DSWH callback did not forward the current request.' . PHP_EOL);
    exit(1);
}

echo '[PASS] DSWH callback registration and dispatch.' . PHP_EOL;
