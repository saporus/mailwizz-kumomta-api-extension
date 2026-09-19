<?php declare(strict_types=1);
require dirname(__DIR__) . '/magicsmtp/models/MagicSmtpCooldown.php';
function check($condition): void { if (!$condition) throw new RuntimeException('assertion_failed'); }
$dir = sys_get_temp_dir() . '/magic-cooldown-' . bin2hex(random_bytes(8)); mkdir($dir, 0700);
$now = 1800000000;
$clock = static function () use (&$now): int { return $now; };
$zero = static function (): int { return 0; };
try {
    $a = new MagicSmtpCooldown($dir, 'https://fixture.invalid', 'synthetic-key', $clock, $zero);
    $b = new MagicSmtpCooldown($dir, 'https://fixture.invalid', 'synthetic-key', $clock, $zero);
    $other = new MagicSmtpCooldown($dir, 'https://fixture.invalid', 'other-synthetic-key', $clock, $zero);
    check($a->remaining() === 0); check($a->defer('120', 60) === 120); check($b->remaining() === 120); check($other->remaining() === 0);
    check($b->defer('60', null) === 120); $now += 121; check($a->remaining() === 0);
    check($a->defer(gmdate('D, d M Y H:i:s \G\M\T', $now + 180), 60) === 180);
    $now += 181; check($a->defer('invalid', -1) === 60); $now += 61;
    $busy = ['error'=>'memory_admission_limited', 'reason'=>'recovery_inflight', 'retryable'=>true];
    check($a->defer('2', 2, $busy, 429) === 2); check($b->remaining() === 2);
    $now += 3; check($a->remaining() === 0);
    check($a->defer('120', 2, $busy, 429) === 120);
    check($b->defer('2', 2, $busy, 429) === 120); // Cannot shorten existing pressure pause.
    $now += 121;
    foreach (['memory_cutoff','recovery_rate_limited','storage_unavailable','unknown'] as $reason) {
        check($a->defer('2', 2, array_merge($busy, ['reason'=>$reason]), 429) === 60); $now += 61;
    }
    check($a->defer('2', 2, $busy, 503) === 60); $now += 61;
    check($a->defer('2', 2, array_merge($busy, ['retryable'=>false]), 429) === 60); $now += 61;
    check($a->defer('2', 2) === 60); $now += 61;
    $jittered = new MagicSmtpCooldown($dir, 'https://fixture.invalid', 'synthetic-key', $clock, static function (): int { return 10; });
    check($jittered->defer('2', 2, $busy, 429) === 4); $now += 5;
    check($a->defer('999999', 999999) === 900);
    foreach (glob($dir . '/*') as $p) check(strpos(file_get_contents($p), 'synthetic-key') === false);
    echo "PASS shared cooldown, tenant isolation, retry header/date/body, floor/cap, expiry and secret-free storage\n";
} finally { foreach (glob($dir . '/*') as $p) unlink($p); rmdir($dir); }
