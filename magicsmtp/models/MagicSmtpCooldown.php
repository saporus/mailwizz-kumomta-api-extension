<?php declare(strict_types=1);

// Shared by workers using the same endpoint and tenant key. Stores no credential,
// recipient, or message content. Never sleeps while holding the cooldown lock.
final class MagicSmtpCooldown
{
    private $path;
    private $clock;
    private $jitter;

    public function __construct(string $directory, string $endpoint, string $key, ?callable $clock = null, ?callable $jitter = null)
    {
        $this->path = $directory . '/magicsmtp-cooldown-' . hash('sha256', $endpoint . "\0" . $key) . '.json';
        $this->clock = $clock ?? static function (): int { return time(); };
        $this->jitter = $jitter ?? static function (): int { return random_int(0, 10); };
    }

    public function remaining(): int { return $this->snapshot()['remaining']; }

    public function snapshot(): array { return $this->access(null); }

    public static function isRecoveryRefusal(?array $response, int $status): bool
    {
        return $status === 429
            && ($response['error'] ?? null) === 'memory_admission_limited'
            && ($response['reason'] ?? null) === 'recovery_inflight'
            && ($response['retryable'] ?? null) === true
            && (!array_key_exists('accepted', $response) || $response['accepted'] === 0);
    }

    public function defer(string $header, $body, ?array $response = null, int $status = 0): int
    {
        $now = ($this->clock)();
        $headerDelay = ctype_digit(trim($header)) ? (int)$header : (($date = strtotime($header)) !== false ? $date - $now : 0);
        $bodyDelay = is_numeric($body) ? (int)ceil((float)$body) : 0;
        // Only an explicit concurrency refusal may use a short pause. Older APIs,
        // unknown failures, memory pressure and exhausted budgets retain the floor.
        $concurrency = self::isRecoveryRefusal($response, $status);
        $jitter = max(0, min($concurrency ? 2 : 10, ($this->jitter)()));
        $delay = min(900, max($concurrency ? 2 : 60, $headerDelay, $bodyDelay)) + $jitter;
        return $this->access($now + $delay, $concurrency && $delay <= 4)['remaining'];
    }

    private function access(?int $until, bool $shortRetry = false): array
    {
        $file = fopen($this->path, 'c+');
        if (!$file) throw new RuntimeException('cooldown_storage_unavailable');
        try {
            if (!flock($file, LOCK_EX)) throw new RuntimeException('cooldown_lock_unavailable');
            chmod($this->path, 0600);
            $raw = stream_get_contents($file);
            $state = $raw === '' ? ['until' => 0] : json_decode($raw, true);
            if (!is_array($state) || !isset($state['until']) || !is_int($state['until'])) throw new RuntimeException('cooldown_state_invalid');
            if ($until !== null) {
                // An overlapping long/unknown pause must never become a short retry,
                // even as it nears expiry or a sibling reports slot contention.
                $state['short_retry'] = $shortRetry && ($state['until'] <= ($this->clock)() || ($state['short_retry'] ?? false) === true);
                $state['until'] = max($state['until'], $until);
                $data = json_encode($state);
                rewind($file);
                if (!ftruncate($file, 0) || fwrite($file, $data) !== strlen($data) || !fflush($file)) throw new RuntimeException('cooldown_write_failed');
            }
            return ['remaining' => max(0, $state['until'] - ($this->clock)()), 'short_retry' => ($state['short_retry'] ?? false) === true];
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
