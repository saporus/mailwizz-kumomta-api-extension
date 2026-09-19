<?php declare(strict_types=1);

// Shared by workers using the same endpoint and tenant key. Stores no credential,
// recipient, or message content. Never sleeps while holding a worker or lock.
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

    public function remaining(): int { return $this->access(null); }

    public function defer(string $header, $body, ?array $response = null, int $status = 0): int
    {
        $now = ($this->clock)();
        $headerDelay = ctype_digit(trim($header)) ? (int)$header : (($date = strtotime($header)) !== false ? $date - $now : 0);
        $bodyDelay = is_numeric($body) ? (int)ceil((float)$body) : 0;
        // Only an explicit concurrency refusal may use a short pause. Older APIs,
        // unknown failures, memory pressure and exhausted budgets retain the floor.
        $concurrency = $status === 429
            && ($response['error'] ?? null) === 'memory_admission_limited'
            && ($response['reason'] ?? null) === 'recovery_inflight'
            && ($response['retryable'] ?? null) === true;
        $jitter = max(0, min($concurrency ? 2 : 10, ($this->jitter)()));
        return $this->access($now + min(900, max($concurrency ? 2 : 60, $headerDelay, $bodyDelay)) + $jitter);
    }

    private function access(?int $until): int
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
                $state['until'] = max($state['until'], $until);
                $data = json_encode($state);
                rewind($file);
                if (!ftruncate($file, 0) || fwrite($file, $data) !== strlen($data) || !fflush($file)) throw new RuntimeException('cooldown_write_failed');
            }
            return max(0, $state['until'] - ($this->clock)());
        } finally { flock($file, LOCK_UN); fclose($file); }
    }
}
