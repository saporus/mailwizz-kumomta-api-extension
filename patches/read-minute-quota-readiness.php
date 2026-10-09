<?php declare(strict_types=1);

/** Read-only server445 query/callsite acceptance; no application bootstrap. */
define('MW_PATH', '/home/admin/web/servermail2.com/public_html');
try {
    $config = require MW_PATH . '/apps/common/config/main-custom.php';
    $connection = $config['components']['db']; $prefix = $connection['tablePrefix'];
    if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix)) throw new RuntimeException('prefix');
    $db = new PDO($connection['connectionString'], $connection['username'], $connection['password'], [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]);
    $db->exec('SET SESSION MAX_EXECUTION_TIME=1000');
    $db->exec('SET SESSION TRANSACTION READ ONLY'); $db->beginTransaction();
    function readRows(string $sql, array $params = []): array {
        global $db; $statement = $db->prepare($sql); $statement->execute($params); return $statement->fetchAll(PDO::FETCH_ASSOC);
    }
    $out = ['ok' => true, 'readOnly' => true, 'applicationBootstrap' => false, 'at' => gmdate('c')];
    $out['campaign'] = readRows('SELECT campaign_id,campaign_uid,status FROM ' . $prefix . 'campaign WHERE campaign_id=328');
    if (($out['campaign'][0]['campaign_uid'] ?? '') !== 'nj855ymroyc89') throw new RuntimeException('scope');
    $out['serverLimits'] = readRows('SELECT server_id,minute_quota,hourly_quota,daily_quota,monthly_quota FROM ' . $prefix . 'delivery_server WHERE server_id=445');
    $out['databaseClock'] = readRows('SELECT NOW(6) local_now,UTC_TIMESTAMP(6) utc_now,@@session.time_zone session_timezone,@@session.sql_mode sql_mode');
    $table = $prefix . 'delivery_server_usage_log';
    $sql = 'SELECT UNIX_TIMESTAMP(NOW(6)) AS quota_now, COUNT(*) AS quota_used FROM ' . $table . ' WHERE server_id = :serverId AND date_added >= DATE_FORMAT(NOW(), "%Y-%m-%d %H:%i:00") AND date_added < DATE_FORMAT(NOW() + INTERVAL 1 MINUTE, "%Y-%m-%d %H:%i:00")';
    $out['indexes'] = readRows('SHOW INDEX FROM ' . $table);
    $out['queryPlan'] = readRows('EXPLAIN ' . $sql, [':serverId' => 445]);
    $out['snapshotSamples'] = [];
    for ($i = 0; $i < 5; ++$i) {
        $start = hrtime(true); $rows = readRows($sql, [':serverId' => 445]);
        $out['snapshotSamples'][] = ['milliseconds' => round((hrtime(true) - $start) / 1e6, 3), 'count' => (int)$rows[0]['quota_used']];
    }
    $db->rollBack();
    $out['php'] = ['version' => PHP_VERSION, 'cliOpcache' => ini_get('opcache.enable_cli'), 'validateTimestamps' => ini_get('opcache.validate_timestamps')];
    $out['nativeSha256'] = hash_file('sha256', MW_PATH . '/apps/common/models/DeliveryServer.php');
    // Only PHP source paths/line numbers are returned; never source or values.
    $out['callsiteScan'] = ['complete' => true, 'files' => 0, 'bytes' => 0, 'matches' => []];
    $start = hrtime(true);
    foreach (['apps/common/models', 'apps/console', 'apps/extensions'] as $relative) {
        $root = MW_PATH . '/' . $relative;
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file->isLink() || !$file->isFile() || strtolower($file->getExtension()) !== 'php') continue;
            if (++$out['callsiteScan']['files'] > 20000 || ($out['callsiteScan']['bytes'] += $file->getSize()) > 134217728 || (hrtime(true) - $start) / 1e9 > 20) {
                $out['callsiteScan']['complete'] = false; break 2;
            }
            foreach (file($file->getPathname()) as $line => $text) {
                if (strpos($text, 'decreaseMinuteQuota') !== false) $out['callsiteScan']['matches'][] = ['path' => substr($file->getPathname(), strlen(MW_PATH) + 1), 'line' => $line + 1];
            }
        }
    }
    $out['callsiteScan']['scope'] = ['apps/common/models', 'apps/console', 'apps/extensions'];
    echo json_encode($out, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES);
} catch (Throwable $failure) {
    if (isset($db) && $db->inTransaction()) $db->rollBack();
    echo json_encode(['ok' => false, 'readOnly' => true, 'error' => get_class($failure), 'code' => (string)$failure->getCode()]); exit(1);
}
