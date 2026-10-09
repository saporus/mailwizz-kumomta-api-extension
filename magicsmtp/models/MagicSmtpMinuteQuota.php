<?php declare(strict_types=1);

/** Native minute usage remains authoritative; no separate allowance counter. */
final class MagicSmtpMinuteQuota
{
    private $cache;
    private $mutex;
    private $snapshot;
    private $monotonic;

    public function __construct($cache, $mutex, callable $snapshot, ?callable $monotonic = null)
    {
        $this->cache = $cache;
        $this->mutex = $mutex;
        $this->snapshot = $snapshot;
        $this->monotonic = $monotonic ?? static function (): float { return hrtime(true) / 1e9; };
    }

    public function remaining(string $key, int $limit, bool $publish, bool $useMutex = true): int
    {
        if ($limit <= 0) return PHP_INT_MAX;
        $locked = false;
        try {
            if ($useMutex) {
                if (!$this->mutex->acquire($key, $publish ? 60 : 5)) return 0;
                $locked = true;
            }
            // NOW(6) and COUNT come from one SQL statement, so the clock and
            // usage window agree even when PHP uses a different timezone.
            for ($attempt = 0; $attempt < 2; ++$attempt) {
                $started = ($this->monotonic)();
                $snapshot = ($this->snapshot)();
                $elapsed = max(0.0, ($this->monotonic)() - $started);
                if (!is_array($snapshot) || !isset($snapshot['quota_now'], $snapshot['quota_used']) ||
                    !is_numeric($snapshot['quota_now']) || !is_numeric($snapshot['quota_used'])) return 0;
                $now = (float)$snapshot['quota_now'];
                $used = (float)$snapshot['quota_used'];
                if (!is_finite($now) || $now <= 0 || !is_finite($used) || $used < 0 || floor($used) !== $used) return 0;
                $secondsLeft = 60.0 - fmod($now, 60.0) - $elapsed;
                if ($secondsLeft <= 0.0) continue;
                $left = (int)max(0, $limit - $used);
                if (!$publish) return $left;
                // Native logUsage saves before this call; undoLogUsage deletes
                // first. Recounting includes that change, without a second
                // debit or a refund for an undo from an earlier minute.
                $ttl = (int)floor($secondsLeft);
                if ($ttl < 1) {
                    // Yii interprets TTL zero as forever. Never publish it.
                    $this->cache->delete($key);
                    if ($this->cache->get($key) !== false) return 0;
                } else {
                    if (!$this->cache->set($key, $left, $ttl)) {
                        $this->cache->delete($key);
                        return 0;
                    }
                    // A slow backend can start its TTL after our observation.
                    // Invalidate when that latency consumes the rounding margin.
                    $afterWrite = 60.0 - fmod($now, 60.0) - max(0.0, ($this->monotonic)() - $started);
                    if ($ttl > (int)floor($afterWrite)) {
                        $this->cache->delete($key);
                        return 0;
                    }
                }
                return $left;
            }
            return 0;
        } catch (Throwable $failure) {
            // A missing/failed clock, count, lock or cache never grants quota.
            return 0;
        } finally {
            if ($locked) $this->mutex->release($key);
        }
    }
}
