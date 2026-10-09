# Minute quota boundary release, 9 October 2026

## Evidence and scope

At13:31:35UTC campaign328 was still sending:450 successes at13:23,25,27,29,31 (2250/10minutes,225/minute), three giveups and one suppressed result. Its queue held644566 rows, all failures zero. Server445 retained minute450/hour15000/day400000/month9000000/second0/pause0. The exact native DeliveryServer source hash remains `a173922750c477558ac5302d5355ce68a5993f5ab2f8e639b944a41cf019bfef`.

Native SQL counts the current calendar minute, while native decrement caches remaining quota for60seconds after every usage log. A zero written at xx:11 can block the next cron at xx+1:01. This candidate changes only MagicSMTP extension behavior, preserving every configured limit. The15000/hour cap permits an average250/minute, only about11% above225/minute; no large sustained throughput gain is promised. This is separate from the native-selector/provider-filter investigation.

Runtime files: `magicsmtp/models/MagicSmtpMinuteQuota.php` (new) and `magicsmtp/models/DeliveryServerMagicSmtpWebApi.php`. Vendor code, policy manifest, short-recovery retry, campaigns, credentials, schedules and quota settings are unchanged. The exact two-file release was applied at14:15:21UTC under the user's scoped deployment authorization. No production cache was cleared, campaign paused/resumed, service restarted, test mail sent or request retried. The2027 programme remains paused.

## Behavior and safety

Both extension minute methods retain the native key and mutex. The getter strictly counts native durable usage, so stale positive cache data after a failed write cannot grant extra allowance in the extension's final check. One SQL statement returns both database `NOW(6)` and its current-calendar-minute count; PHP timezone does not select the period. Invalid clocks/counts or database/lock failures return zero. A count crossing a minute boundary is retried at most once.

The writer recounts after native `logUsage()` saves, or `undoLogUsage()` deletes, the row. There is no second cold-cache debit or refund into a new minute for an old-minute undo. It publishes the existing integer with TTL rounded down to the remaining minute. TTL0 is never passed to Yii, where it would mean forever. A subsecond boundary or slow cache write invalidates instead. Cache failures return zero; new extension checks continue enforcing the durable count even when stale cached data remains.

Concurrency relies on the unchanged **outer per-server usage mutex** in the native worker, covering final quota check and optimistic usage reservation before release for network submission. The helper's own mutex alone is not a send reservation. No independent quota counter is introduced. Other quota periods stay native; a disabled minute quota returns the native unlimited sentinel without an extra query.

## Remaining limits

- Native `pickServer()` checks base DeliveryServer objects before the extension. Existing zero cache can delay cutover until its old TTL expires (up to60seconds under the observed writer). A native base cold miss immediately before a boundary can still write its own30second zero across the boundary. The exact case is reproduced in tests. No guarantee of sending on every cron minute and no selector bypass is claimed.
- Healthy mixed workers share the same counter/lock, but old workers retain old caching. Extension-only changes do not universally protect old/native readers against cache-storage failure. Any release needs a quiet-worker window and observation, without clearing quota state.
- `decreaseMinuteQuota($by)` now follows the durable ledger. The inspected native callers are logUsage(default1) and undoLogUsage(-1), both verified. Arbitrary third-party cache-only debits/refunds via this public method are not preserved. The14:11:43UTC target scan covered all872PHP files in common models, console and extensions and found only those native callers plus the method definition; no other caller was found in that scope.
- Authoritative checks add indexed current-minute SQL counts. At14:11:43UTC the exact query used the covering `server_date` range index, with an estimated450 rows; five real counts took0.225–0.273ms. This closes the basic MySQL syntax/index/latency check, not sustained worker-load acceptance. Local SQLite remains synthetic evidence, and normal demo-account acceptance remains outstanding.
- Recorded submission success is not destination delivery or inbox placement.

## Local verification

All 16 standalone PHP fixtures pass, including existing send, short-retry/guard, cooldown, forms/hooks, idempotency and recipient-policy checks. The minute helper covers rollover, legacy zero/stale positive, post-log cold recount, current/old-minute undo, invalid clock/count, DB/cache failure, lock wait, slow query/write, unlimited quotas and PHP timezone differences.

`minute_quota_native_runtime_test.php` reads exact hash-pinned licensed methods in memory and executes them with the actual extension against isolated adapters. It checks log/undo dispatch,450 acceptance, hourly-limit rejection and the remaining native30second cold-zero behavior. No vendor source is committed.

`minute_quota_concurrency_test.py` runs eight real PHP processes in each of two disposable scenarios: all new and four old/four new. They execute the exact pinned installed core reservation block with a shared SQLite ledger and file mutex/cache. Each scenario attempts640 fixture submissions:450 accepted/450 usage rows,190 quota denials, zero network calls. Independent review reran both successfully. This is healthy synthetic contention evidence, not target load or mixed-worker storage-failure acceptance.

Synthetic existing-demo/new-demo/normal-tenant labels mean isolated fixture namespaces only. They are **not normal demo-account login/UI/persistence/expiry verification**. No account schema, samples, pages or expiry rules changed and no client-shared demo account was touched. Ordinary existing/new demo acceptance remains a release gap; production execution must stay disabled server-side.

Private receipts live in `.qa/quota-minute-20261009/`: `standalone-tests.json`, `native-test.txt`, `concurrency-test.json`, `target-readiness-receipt.json`. The live read is `.qa/short-retry-20261009/post-resume-133136.json`. Parent owns the combined Kumo documentation and required memory records.

The guard-budget fixture checks the existing retry contract without changing it: at most three extra HTTP attempts, a 12-second retry-start budget, and normal one-second sleeps. Initial shared wait plus three refusal waits and the terminal guard can make 16 eligibility checks within 11 seconds under those normal sleeps. There is no independent hard guard-call counter: an interrupted/shortened sleep can increase check frequency, which the fixture also demonstrates. Each new minute check uses one SQL snapshot, at most two if a minute transition invalidates the first. Query latency is therefore a required target acceptance item.

Fifteen disposable deployment scenarios pass: dry-run/apply/verify/exact rollback, injected failures after either replacement, changed guard/candidate, missing/mismatched target acceptance, abrupt interruption at every publication prefix, interrupted rollback, backup drift, unknown target contents, late target drift and exclusive new-file publication. Recovery accepts only verified baseline/candidate mixtures and exact pinned backups, restores the previous model first, and can resume repeatedly. Unexpected content is preserved for manual reconciliation. Receipt: `deploy-test.json`.

```sh
php tests/minute_quota_runtime_test.php
php tests/minute_quota_native_runtime_test.php PRIVATE/DeliveryServer.php
python tests/minute_quota_concurrency_test.py PRIVATE/DeliveryServer.php PRIVATE/SendCampaignsCommand.php
```

Windows concurrency tests load `php_pdo_sqlite.dll`; other hosts can pass `--php` and `--pdo-sqlite` (empty if already loaded). Every fixture disables external execution through synthetic send adapters.

## Release and rollback plan

Only the two extension files are intended targets. Install the helper before the referencing model; preserve ownership/modes with exact before/after hashes and scoped backups. No service restart is needed for new CLI workers; existing workers must finish and any web opcode-cache configuration must be checked before claiming web activation. The native core and scheduler-policy hashes stay unchanged, so no policy-manifest update is required.

Rollback restores the previous model before removing the new helper. Preserve databases, usage logs, queues, all quota settings, effects, idempotency/dispatch ledgers and cache state. Old code understands the shared integer cache, which expires normally. Never restore a database or clear quota/cooldown state to speed up rollout. Local preparation, isolated target tests, dry-run, apply and rollback must remain separate explicit modes.

The local preparer is `patches/prepare-minute-quota.py NEW_PRIVATE_OUTPUT_DIRECTORY`. It freezes both runtime files, reviewed helpers, the isolated fixtures and private native references into a new directory, with a manifest pin. Licensed references remain under ignored `.qa` output; they are never committed. `test-target-minute-quota.py` is limited to private fixtures and PHP 8.1 lint using the already-verified private PDO SQLite module; it does not bootstrap MailWizz, install packages, access a production database or send traffic. It also runs the fifteen isolated release/recovery scenarios, including Linux exclusive-file creation. `deploy-minute-quota.py` has separate `dry-run`, `apply`, `verify`, `reconcile`, and `rollback` modes. Apply additionally requires a successful candidate-matching PHP 8.1 receipt, exact two-target compare-and-swap checks, seven untouched guards, a nonexistent backup path and a quiet sending-worker window. It rechecks the target immediately before each publication and creates the new helper exclusively using Linux `renameat2(RENAME_NOREPLACE)`. Existing-file publication still requires a serialized administrative release window; it is not a kernel-level compare-and-swap against an unrelated simultaneous administrator.

After any uncertain apply, run read-only `reconcile` first, never repeat apply. A valid backup and only baseline/candidate states permit the exact `rollback` command even after abrupt interruption. Unknown target contents or changed backup/guard hashes require manual review; recovery refuses to overwrite them. The rollback can itself resume from an interrupted baseline/candidate mixture and never rolls recovered files forward again.

Retained private target stage: `/var/tmp/magicsmtp-minute-quota-20261009-v1`. Retained verified backup: `/root/magicsmtp-minute-quota-backup-20261009-v1`. Commands take the final locally reviewed manifest SHA-256 as `PIN`:

The frozen local v4 bundle is `.qa/minute-quota-release-20261009-v4`, manifest pin `8aabf57e3fedc3c96e321df396b811bbaa2411881f38123513e02a81deb78357`. `patches/upload-test-minute-quota-ssh.py` defaults to a local-only plan and provides separate explicit `readiness`, `upload-test`, and `readback` modes, using the existing strict known-host SSH identity. It has no apply or rollback mode. Its read-only readiness probe uses a read-only database transaction and bounded query/source scans without bootstrapping the application. A private stage that already exists is rejected for upload and must be reconciled before any retry.

```sh
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/test-target-minute-quota.py PIN
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/deploy-minute-quota.py dry-run /var/tmp/magicsmtp-minute-quota-20261009-v1 /root/magicsmtp-minute-quota-backup-20261009-v1 PIN
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/deploy-minute-quota.py apply /var/tmp/magicsmtp-minute-quota-20261009-v1 /root/magicsmtp-minute-quota-backup-20261009-v1 PIN
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/deploy-minute-quota.py verify /var/tmp/magicsmtp-minute-quota-20261009-v1 /root/magicsmtp-minute-quota-backup-20261009-v1 PIN
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/deploy-minute-quota.py reconcile /var/tmp/magicsmtp-minute-quota-20261009-v1 /root/magicsmtp-minute-quota-backup-20261009-v1 PIN
python3 /var/tmp/magicsmtp-minute-quota-20261009-v1/deploy-minute-quota.py rollback /var/tmp/magicsmtp-minute-quota-20261009-v1 /root/magicsmtp-minute-quota-backup-20261009-v1 PIN
```

## Target acceptance and deployment evidence

At14:12:38–14:12:48UTC, private PHP8.1.33 acceptance passed all16 standalone tests plus the exact native-method fixture, both eight-process contention scenarios (each640 attempts,450 accepted,190 denied,zero network calls), all15 Linux publication/recovery scenarios, both runtime lints and the exact before/after dry-runs. This includes actual Linux no-overwrite rename success and destination-collision behavior. The test runner did not bootstrap the live application. Receipt: `.qa/quota-minute-20261009/target-upload-test-receipt.json`.

`patches/apply-minute-quota-ssh.py apply` performed the separately approved apply through strict SSH. It verified the remote manifest and executable helper pins, waited read-only for a natural quiet-worker window, and invoked only the reviewed pinned remote command. No worker was paused/killed and no cron was changed. At14:15:20UTC there were no sending workers; apply completed at14:15:21.581UTC. Immediate verify confirmed both installed hashes and seven untouched guards. Campaign328 remained sending before and after, with635557 queue rows and failures0. Minute450/hour15000/day400000/month9000000 caps matched before/after exactly. Receipt: `.qa/quota-minute-20261009/apply-postflight-receipt.json`.

| Runtime file | Installed SHA-256 |
| --- | --- |
| `MagicSmtpMinuteQuota.php` | `d3ff72f0b16a428b9ad9de7916bc226d444bed9c5d7e1aea3a0190d5f193eba1` |
| `DeliveryServerMagicSmtpWebApi.php` | `15f6c34edb58ff73a880b250893f5e4b5e9ad57018d24c24f89635e9612b7918` |

The first following-minute sample at14:16:38UTC recorded no new successful submission or usage row since14:11:11UTC, which predates this deployment. The queue was unchanged. Server445 native usage was0/current minute,2700/current hour,47174/current day, all below configured limits. The bounded log tail contained148 `Cannot find a valid server to send the campaign email` messages and99 RuntimeException shapes; these aggregate log counts are not all explicitly scoped to campaign328. The parent investigation owns that separate native-selector issue. No throughput improvement is claimed from this sample. Receipt: `.qa/short-retry-20261009/post-resume-141640.json`.

The second sample at14:18:42UTC showed450 native successful submissions and450 native usage rows in **each of14:17 and14:18**, consecutive minutes. The queue fell by900 to634657, failures remained0, and campaign328 stayed sending. Current-hour usage was3600 and current-day usage48074, with the minute450 cap respected. This establishes consecutive-minute submission activity for that short interval, not a sustained hourly rate or inbox delivery. The parent also deployed an API worker correction at14:16:04UTC, so the full resumption cannot be attributed to this quota change alone. Receipt: `.qa/short-retry-20261009/post-resume-141844.json`.

At14:21:40UTC this extended to five consecutive450-success minutes (14:17 through14:21), with2250 matching usage rows and queue633307, down2250 from apply. All queue failures stayed0. Current-hour usage4950 remained below15000. Receipt: `.qa/short-retry-20261009/post-resume-142141.json`. Bounce persistence is a separate integration investigation and is not changed by this release.
