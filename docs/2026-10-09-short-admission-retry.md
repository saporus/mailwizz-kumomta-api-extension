# Bounded MailWizz admission retry, 9 October 2026

## Problem and scope

Campaign 328's read-only diagnosis found that explicit HTTP 429 `memory_admission_limited` / `recovery_inflight` responses created a shared 2-4 second cooldown, but exception 99 ended all current subscriber workers until a later minute cron. The API's inspected memory admission implementation returns this refusal before calling its send callback. This change permits a narrowly bounded in-worker retry of that proven pre-admission refusal.

This record distinguishes local source, isolated target-runtime acceptance and the authorized scoped release. No accounts, campaigns, credentials, queue rows or schedules are changed by the release helpers. The 2027 commercial programme remains paused.

## Behavior

- A normal campaign worker supplies the native eligibility guard. CLI calls without that guard, interactive sends, and sends without stable campaign/subscriber idempotency remain on the existing failure path.
- Only HTTP 429 with exact error/reason and boolean `retryable=true` qualifies. Contradictory nonzero/invalid `accepted`, unknown failures, pressure/rate limits, 5xx, connection failures and timeouts are never retried inline.
- The worker makes at most three additional HTTP attempts. A monotonic 12-second budget limits when another attempt can start; an already-started HTTP call retains its configured request timeout. No cooldown file lock is held while sleeping.
- The original serialized MIME, message ID, recipient, idempotency key, endpoint and credentials are reused. Hooks and MIME generation run once per invocation; usage is logged only on success. The existing policy dispatch proof is recorded once.
- Shared cooldown state adds only a `short_retry` boolean. Old state without provenance remains fail-closed. A long pause cannot become eligible merely because only a few seconds remain, and an overlapping longer pause stops waiting.
- During each one-second wait and immediately before another attempt, fresh campaign and subscriber records are checked for pause/deletion, recipient/list changes, confirmation, blacklist, campaign suppression, campaign group blocking, recipient policy, domain policy and quota.
- Eligibility abort uses native code 98. Both nested core catches now preserve it so the outer native handler reloads a paused campaign instead of changing it to sending. Longer failures still use code 99. No terminal giveup is fabricated by either short-wait abort.

## Local validation

Fourteen standalone extension fixtures passed with local PHP 8.2.30 / PDO SQLite, including the existing cooldown, transient send, idempotency, forms, hooks, delivery-report, callback, policy-store and correlation checks. The new short retry and guard tests cover normal recovery, sibling cooldown, bounded attempts, unchanged payload, timeout after refusal, changed suppression/pause and storage failure. All network clients are synthetic.

`short_retry_bridge_runtime_test.php` executes the exact prepared private core send/catch blocks and native outer pause handler. Both 98 and 99 propagation and paused-state preservation passed. `policy_scheduler_runtime_test.php` passed against the combined candidate: regular/queue/autoresponder selectors, hold expiry, permanent suppression, no false completion, unchanged queue rows, and existing/new isolated policy schema compatibility. Syntax checks and `git diff --check` passed.

Independent review identified and corrected combined pause/abort paths. Eligibility now runs before every wait exit, including a sibling's longer cooldown, elapsed retry budget or storage failure, and after a terminal transient HTTP result before code 99. Combined pause plus long cooldown/deadline/storage, pause during final timeout/attempt cap, and a slow eligibility check crossing the deadline all pass. The reviewer reran the three new PHP fixtures plus the cooldown fixture against the final source. Eight disposable local deployment scenarios also passed: dry-run/apply/exact rollback, injected failure after each of five replacements, changed binding guard, and changed candidate source.

The candidate was prepared from the same core hash captured in the diagnosis:

- Original `SendCampaignsCommand.php`: `c79081a773d0bcf0b00a5f9d63adbad748afc876f52e9f77e5297162a8b3c122`.
- Candidate `SendCampaignsCommand.php`: `4886275fe4cf16b66a699cee1ee0b377b92706e2abd8d80711f388159263a60f`.
- Unchanged `CampaignQueueTableBehavior.php`: `5fea83a34a648f6971b99aa4994e888214381aa05fcb73c6ce8d27175e59ae8f`.

## Demo acceptance

No account fields, UI routes, schema, sample collections, seed data or demo expiry rules change. Synthetic existing-demo/new-demo/normal-tenant cooldown identities remain isolated, and the scheduler fixture verifies existing/new isolated policy stores. Every HTTP call in these checks uses a stub. This is not evidence of normal customer-login UI acceptance for separate existing and newly created demo accounts; that remains an explicit release acceptance item. No client-shared demo account was touched.

The server-side Kumo demo execution boundary must remain enabled. Existing and new demo workspaces continue using their normal tenant pages, durable isolated data and administrator expiry/access restrictions. This retry path must not enable production execution for demos.

## Deployment and rollback contract

Re-read the current target core/extension and policy-binding manifest hashes before release. Prepare the candidate from that exact private source using `php patches/prepare-short-retry.php MAILWIZZ_ROOT NEW_OUTPUT_DIRECTORY`; context/version drift requires review. This preparer only writes a new local candidate and never modifies its input. Its current support is the inspected MailWizz 2.7.3 build. Do not apply this prepared core to MailWizz 2.8.1.

Deploy only these runtime files, retaining target ownership/modes and backups:

1. `apps/extensions/magicsmtp/models/MagicSmtpShortRetry.php` (new helper; install before the referencing model).
2. `apps/extensions/magicsmtp/models/MagicSmtpCooldown.php`.
3. `apps/extensions/magicsmtp/models/DeliveryServerMagicSmtpWebApi.php`.
4. Prepared `apps/console/commands/SendCampaignsCommand.php`.

The configured recipient-policy scheduler manifest also pins the command hash. Preserve its current bindings, secrets, tenant scope and unchanged queue hash; update only the verified command hash/acceptance evidence after candidate policy scheduler acceptance. A stale manifest intentionally fails closed and must not be bypassed. The three extension files do not independently enable short retries until the normal worker installs its guard.

Run target PHP lint, the no-network fixtures, the real candidate bridge test and the policy scheduler fixture. Verify separate existing/new isolated demos through their normal login, then perform a bounded read-only postflight on accepted admissions, short refusal counts, worker continuity, paused campaigns, policy callbacks and uncertain outcomes. A higher acceptance rate is not proof of destination delivery. Never retry uncertain accepted jobs manually.

Rollback restores the backed-up three extension files, command and matching policy scheduler manifest together; remove the new helper only after restoring the older referencing model. Keep current recipient logs, policy effects, idempotency/dispatch ledgers, queue rows, credentials, cooldown state and all databases. Do not restore a database snapshot or clear cooldowns to accelerate recovery. No release version/tag was created by this local task.

## Reviewed local release bundle and current campaign state

The final reviewed private bundle is `.qa/short-retry-release-20261009-v3`; earlier v1/v2 bundles are superseded. Its `release-manifest.json` SHA-256 is `5bcd3841b47db00e4f81f5795c18bd9b1ea5f7d37aaefb78e8eddef9ef5ae8db`; `deploy-short-retry.py` SHA-256 is `dde853e1fc4a705b81e4f163e5435fb74ce63d78c51e2fa6e9addda6d72c23e9`. The bundle includes isolated PHP QA source and the licensed private candidate for target-runtime checks. It must remain private.

The explicit helper syntax is `python3 STAGE/deploy-short-retry.py dry-run|apply|rollback STAGE BACKUP MANIFEST_SHA256`. It pins all five target files and the untouched init, queue behavior, main-custom configuration and private binding. Apply/rollback require a quiet sending-worker window and perform no cron, campaign or database changes. Backup verification, atomic replacement, ownership/mode preservation, post-write hashing and automatic restoration after a replacement failure are included.

A fresh verified SSH read at 09:33:17 UTC confirmed the core, extension and protected manifest baselines. The exact installed policy manifest is `/home/admin/web/servermail2.com/private/magicsmtp-policy/scheduler-2.7.3-20261002-tenant45-v3.json`; its baseline SHA-256 is `d812789236ff4a0e873dfd47f965aa7a0ca62c0fe8f4c2cbbde50311aca09af4`. The protected bridge include and main-custom configuration remain untouched.

A second bounded read at 09:40:22 UTC found campaign 328 paused, last updated at 09:32:31 UTC, with latest successful submission logs at 09:32:05 UTC. Its queue held 679,132 rows, all with failures zero. There were no active sending workers; the minute cron entries remained enabled and route stdout to `/dev/null`. This explains the later admission gap without identifying who or what paused the campaign. Preserve that pause; deployment cannot claim measured throughput recovery from this campaign unless its owner separately resumes it. Receipts are `.qa/short-retry-20261009/live-baseline.json` and `campaign-status.json`.

## Private target acceptance

After explicit authorization, the final v3 bundle was staged privately at `/var/tmp/magicsmtp-short-retry-20261009-v3` on the established servermail2 SSH host. At 09:53:56–57 UTC, all sixteen PHP fixture files passed using target PHP 8.1.33, including the exact private core bridge and policy scheduler candidates. Candidate lint and both pre/post five-file dry-runs passed. The isolated SQLite fixtures used the previously available, hash-verified private module; no package was downloaded or installed. There was no application bootstrap, production source change, database write or network mail send during these tests. Campaign 328 remained paused, its queue retained 679,132 rows with failures zero, and no sending workers were active.

The upload manifest SHA-256 is `4108bdef7cd01d68fd6e010bdf091c2d0d537d2b5f1d4e0cab4025351130e7ff`. The sanitized target receipt is `.qa/short-retry-20261009/target-runtime-receipt.json`, with the same receipt retained privately in the remote staging directory. Licensed candidate/core material remains ignored and must not enter version control.

`patches/apply-short-retry-ssh.py apply` invokes only the exact pinned five-file deployment helper, after a fresh paused/quiet readback. It stores pre/post hashes and campaign aggregates, then verifies the configured policy scheduler through normal MailWizz classes under the `admin` worker identity and a read-only database transaction. Its separate `reconcile` mode performs no deployment writes and is the first action after an ambiguous apply result. The private backup is `/root/magicsmtp-short-retry-backup-20261009-v3`; do not retry apply blindly or resume the campaign.

## Authorized release and postflight

The exact v3 release was authorized and applied at **10:01:52 UTC on 9 October 2026**. All five destinations passed their before-hash checks, backup verification, atomic replacement and after-hash checks. File ownership remained UID/GID 1003; PHP files retain mode 0644 and the private scheduler manifest retains mode 0600. The untouched init, queue behavior, main-custom configuration and private binding retained their exact hashes and metadata. No service restart, database write, campaign update or cron change was performed.

| Target | Installed SHA-256 |
| --- | --- |
| `models/MagicSmtpShortRetry.php` | `cb3fd8cdd340b6b473100debb3eac5e98dca6e0eef74003fcb01a48453cc5a9a` |
| `models/MagicSmtpCooldown.php` | `7c76a1137c21b7d5c0931239d5ac9b6dc20fdc4bf891b144a3a66e8b107a5ff6` |
| `models/DeliveryServerMagicSmtpWebApi.php` | `fc9d5e05a963259c46ccfa090dd2921846cfb05427a0e7599b84d7efb025b93d` |
| `apps/console/commands/SendCampaignsCommand.php` | `4886275fe4cf16b66a699cee1ee0b377b92706e2abd8d80711f388159263a60f` |
| Private `scheduler-2.7.3-20261002-tenant45-v3.json` | `ddb7a99734034686aa382081da770480e749f3185e8d5dc41eeaa43692778785` |

Normal MailWizz classes loaded under the `admin` worker identity with FPM configuration/open_basedir and a read-only database transaction. MailWizz remains 2.7.3, extension source/registry remain 1.2.0, `mustUpdate=false`, and `schedulerVerified=true` for servers 433, 434, 436, 442 and 444.

Fresh before/after campaign readbacks at 10:01:51–52 UTC were identical: campaign 328 paused, last updated 09:32:31, latest successful submission log 09:32:05, 679,132 queue rows with failures zero, no active sending workers, minute cron enabled. No measured campaign-throughput improvement is claimed while the campaign remains paused. Normal customer-login demo UI acceptance remains the separate limitation stated above.

The complete sanitized receipt is `.qa/short-retry-20261009/apply-postflight-receipt.json`, also retained at `/var/tmp/magicsmtp-short-retry-20261009-v3/apply-postflight-receipt.json`. The backup has `apply-receipt.json`, the pinned release manifest and exact originals. The reviewed rollback command is:

```sh
python3 /var/tmp/magicsmtp-short-retry-20261009-v3/deploy-short-retry.py rollback /var/tmp/magicsmtp-short-retry-20261009-v3 /root/magicsmtp-short-retry-backup-20261009-v3 5bcd3841b47db00e4f81f5795c18bd9b1ea5f7d37aaefb78e8eddef9ef5ae8db
```

Rollback is available but was not executed. It requires the reviewed quiet worker window and exact installed hashes; it preserves current databases, queues, ledgers, cooldown state and campaign pause. The scoped source/tests/helpers/documentation are committed separately from the private `.qa` material; the parent release record carries the resulting repository commit. No release tag was created.
