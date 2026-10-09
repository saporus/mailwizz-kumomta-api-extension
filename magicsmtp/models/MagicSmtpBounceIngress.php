<?php declare(strict_types=1);
defined('MW_PATH') or exit('No direct script access allowed');

/** Authentication is pure: no application bootstrap, database or side effects. */
final class MagicSmtpBounceIngress
{
    private static function field(array $data, string $name, int $limit): string
    {
        $value = $data[$name] ?? null;
        if (!is_string($value) || trim($value) === '' || strlen($value) > $limit || preg_match('/[\x00-\x1f\x7f]/', $value)) {
            throw new InvalidArgumentException('Invalid feedback field', 422);
        }
        return trim($value);
    }

    /** Null means genuinely unbound legacy endpoint, never a failed binding. */
    public static function authenticate(int $endpoint, array $bindings, array $event, string $raw, string $signature): ?array
    {
        $candidates = [];
        foreach ($bindings as $binding) {
            if (!is_array($binding) || !is_array($binding['server_ids'] ?? null)
                || !is_int($binding['customer_id'] ?? null) || $binding['customer_id'] < 1
                || !is_string($binding['tenant_id'] ?? null) || $binding['tenant_id'] === ''
                || !is_string($binding['secret'] ?? null) || strlen($binding['secret']) < 32) {
                throw new RuntimeException('Feedback binding rejected', 403);
            }
            foreach ($binding['server_ids'] as $id) if (!is_int($id) || $id < 1) throw new RuntimeException('Feedback binding rejected', 403);
            if (in_array($endpoint, $binding['server_ids'], true)) $candidates[] = $binding;
        }
        if (!$candidates) return null;
        $tenant = self::field($event, 'tenant', 128);
        $matches = array_values(array_filter($candidates, static function (array $binding) use ($tenant): bool { return $binding['tenant_id'] === $tenant; }));
        if (count($matches) !== 1 || ($matches[0]['enabled'] ?? null) !== true) throw new RuntimeException('Feedback binding rejected', 403);
        $binding = $matches[0];
        if (!preg_match('/^[a-fA-F0-9]{64}$/D', $signature)
            || !hash_equals(hash_hmac('sha256', $raw, $binding['secret']), strtolower($signature))) {
            throw new RuntimeException('Invalid feedback signature', 401);
        }
        return $binding;
    }

    public static function feedbackData(array $event): array
    {
        self::field($event, 'webhook_event_id', 128); // event_id can be the original Message-ID.
        $data = $event['data'] ?? [];
        if (!is_array($data)) throw new InvalidArgumentException('Invalid feedback data', 422);
        $data['campaign_id'] = self::field($data, 'campaign_id', 128);
        $data['message_id'] = trim(self::field($data, 'message_id', 512), '<>');
        $data['recipient'] = strtolower(self::field($data, 'recipient', 320));
        if ($data['message_id'] === '' || !filter_var($data['recipient'], FILTER_VALIDATE_EMAIL)) throw new InvalidArgumentException('Invalid feedback tuple', 422);
        return $data;
    }

    /** Read two bounded sets. Archive copies of the same tuple are one proof. */
    public static function correlate(PDO $pdo, string $prefix, array $tables, array $binding, array $data): ?array
    {
        if (!preg_match('/^[A-Za-z0-9_]*$/D', $prefix) || count($tables) !== 2) throw new RuntimeException('Invalid feedback tables');
        $matches = [];
        $mysql = $pdo->getAttribute(PDO::ATTR_DRIVER_NAME) === 'mysql';
        foreach ($tables as $table) {
            $table = str_replace(['{{', '}}'], [$prefix, ''], $table);
            if (!preg_match('/^[A-Za-z0-9_]+$/D', $table)) throw new RuntimeException('Invalid feedback table');
            $sql = 'SELECT '.($mysql ? '/*+ MAX_EXECUTION_TIME(3000) */ ' : '').'DISTINCT d.server_id,c.customer_id,c.campaign_id,c.campaign_uid,c.list_id,s.subscriber_id,s.subscriber_uid FROM '.$table.' d'.($mysql ? ' FORCE INDEX (email_message_id)' : '')
                .' INNER JOIN '.$prefix.'campaign c ON c.campaign_id=d.campaign_id'
                .' INNER JOIN '.$prefix.'list_subscriber s ON s.subscriber_id=d.subscriber_id AND s.list_id=c.list_id'
                .' WHERE d.status=? AND (d.email_message_id=? OR d.email_message_id=?) AND c.customer_id=? AND LOWER(TRIM(s.email))=?'
                .' AND (c.campaign_uid=? OR c.campaign_id=?) AND d.server_id IN ('.implode(',', array_fill(0, count($binding['server_ids']), '?')).') LIMIT 2';
            $params = array_merge(['success', $data['message_id'], '<'.$data['message_id'].'>', $binding['customer_id'], $data['recipient'], $data['campaign_id'], ctype_digit($data['campaign_id']) ? (int)$data['campaign_id'] : -1], $binding['server_ids']);
            $query = $pdo->prepare($sql);
            if (!$query || !$query->execute($params)) throw new RuntimeException('Feedback lookup failed');
            foreach ($query->fetchAll(PDO::FETCH_ASSOC) as $row) {
                foreach (['server_id', 'customer_id', 'campaign_id', 'list_id', 'subscriber_id'] as $key) $row[$key] = (int)$row[$key];
                $matches[json_encode($row)] = $row;
            }
        }
        return count($matches) === 1 ? array_values($matches)[0] : null;
    }
}
