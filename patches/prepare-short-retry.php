<?php declare(strict_types=1);
/** Prepare a private candidate only; do not edit the licensed input tree. */
if (PHP_SAPI !== 'cli' || count($argv) !== 3) { fwrite(STDERR, "Usage: php prepare-short-retry.php MAILWIZZ_ROOT NEW_OUTPUT_DIRECTORY\n"); exit(2); }
$root = realpath($argv[1]); $output = $argv[2];
if (!$root || file_exists($output)) throw new RuntimeException('Valid input and new output directory required');
$init = (string)file_get_contents($root . '/apps/init.php');
if (!preg_match('/define\([\'\"]MW_VERSION[\'\"],\s*[\'\"]2\.7\.3[\'\"]\)/', $init)) throw new RuntimeException('Only inspected MailWizz 2.7.3 is supported');
$path = 'apps/console/commands/SendCampaignsCommand.php';
$original = (string)file_get_contents($root . '/' . $path);
$body = str_replace("\r\n", "\n", $original);
if (strpos($body, 'MAGIC_SMTP_SHORT_RETRY_V1') !== false) throw new RuntimeException('Already patched');
function replaceShortRetryOnce(string $body, string $needle, string $replacement): string {
    if (substr_count($body, $needle) !== 1) throw new RuntimeException('Source context mismatch; review required');
    return str_replace($needle, $replacement, $body);
}
$body = replaceShortRetryOnce($body, 'if ((int)$e->getCode() === 99) {', 'if (in_array((int)$e->getCode(), [98, 99], true)) {');
$body = replaceShortRetryOnce($body, 'throw new Exception($e->getMessage(), 99);', 'throw new Exception($e->getMessage(), (int)$e->getCode() === 98 ? 98 : 99, $e);');
$needle = '                    $server->disableLogUsage();';
$body = replaceShortRetryOnce($body, $needle, $needle . <<<'PHP'

                    // MAGIC_SMTP_SHORT_RETRY_V1: normal worker checks run during bounded admission waits.
                    if (method_exists($server, 'setShortRetryGuard')) {
                        $server->setShortRetryGuard(static function () use ($server, $campaign, $subscriber): void {
                            MagicSmtpShortRetry::assertEligible($server, $campaign, $subscriber);
                        });
                    }
PHP
);
$target = $output . '/' . $path;
if (!mkdir(dirname($target), 0700, true)) throw new RuntimeException('Cannot create candidate directory');
if (file_put_contents($target, $body) !== strlen($body)) throw new RuntimeException('Cannot write candidate');
$manifest = ['contract' => 'magic-smtp-short-retry-v1', 'mailwizz_version' => '2.7.3', 'base_sha256' => [$path => hash('sha256', $original)], 'sha256' => [$path => hash('sha256', $body)], 'acceptance' => 'pending', 'policy_scheduler_manifest_refresh_required' => true];
file_put_contents($output . '/short-retry-manifest.json', json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n");
echo json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
