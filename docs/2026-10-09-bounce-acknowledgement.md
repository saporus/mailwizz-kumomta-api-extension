# Bounce acknowledgement and routing investigation, 9 October 2026

## Proven mismatch

At14:21:58UTC the engine recorded1124 completed Campaign328 bounce callbacks since14:16, all HTTP200 with `Bounce registered`, sent to `/dswh/433`. At14:21:40UTC MailWizz had zero persisted bounce rows for Campaign328 or any campaign since14:16. This was not evidence of no hard failures.

Ten bounded callback samples were checked against native MailWizz records at14:23:18UTC. Every sample matched one successful server445 delivery, a confirmed subscriber, and the exact normalized recipient hash; none had a bounce row. One was a hard NoMailbox result. The callback handler's server433 restriction rejected these matches, but its caller ignored false and returned a success response. Recipient addresses and credential values were not exported.

The live policy binding at14:25:39UTC was enabled for tenant45/customer1 and servers433,434,436,442,444;445 was absent. The engine reviewer later verified that the decoded runtime credentials of433 and445 both belong to tenant45, with445 using its relay key. Raw stored database password hashes were ciphertext hashes and were not valid API ownership evidence. Runtime credentials were hashed in memory; no values were logged.

## First fix: truthful acknowledgement, strict server scope

Only `magicsmtp/models/DeliveryServerMagicSmtp.php` changes at runtime. A bounce that fails exact server/message/recipient/subscriber correlation returns409 with `ok:false`. A failed save or incomplete hard-bounce protection returns503 with a fixed sanitized message. Empty/malformed envelopes return400; invalid recipient/typed bounce fields and unsupported event types return422. Success200 requires a persisted native bounce and, for hard bounces, a fresh durable subscriber blacklist-status proof.

If bounce persistence succeeds but blacklist processing fails, the durable bounce remains and the response is503. A retry recognizes that existing hard bounce and finishes the missing protection step. It never acknowledges an in-memory-only status change. An already persisted hard bounce with durable blacklisted status returns200 without another bounce row. An existing soft row does not falsely acknowledge a later hard event; that conflict returns409 without automatic reclassification.

Native blacklist behavior was inspected privately: ListSubscriber hash `5e82c3009f06d204d62148ac3eb59f912ad3a7711b1d53132278daad0386daad`, EmailBlacklist hash `7b1c64f0699b300d8309b08fdb62feaec4dacb2abc40b86cd9697cdfecbae35c`. Native code can swallow a status-write error while returning success, which is why fresh persistence proof is required. Licensed sources remain ignored/private.

This first fix does not authorize cross-server matches, change a webhook URL, expand a policy binding, replay completed callbacks, change campaign state or affect the paused2027 programme. Complaint persistence behavior is outside this first change. The pre-existing concurrent find-then-save duplicate race is not resolved by the first patch; signed-routing work must address it before claiming concurrent deduplication.

## Verification and release boundary

`tests/bounce_callback_runtime_test.php` passes29 focused checks using the actual callback/model code with isolated storage adapters. These cover wrong server, message and recipient, subscriber eligibility, save false/exception, hard and soft duplicates, blacklist false/throw/in-memory-only/throw-after-write failures and repair, existing-soft/new-hard conflict, and malformed typed payloads. Policy-callback and DSWH-hook regression fixtures also pass. No network, live subscriber or account is used by these fixtures.

Normal existing/new demo-account login, persistence, expiry and execution-blocking workflows are **not verified** by these callback mocks. They are not labelled as demo-account acceptance. No demo accounts or sample data were changed; parent owns separate actual demo workflow verification and combined Kumo memory notes.

The frozen local package is `.qa/bounce-ack-release-20261009-v3`, runtime SHA `286b0b6eda61e880afbf53df998d255a76a50853a655287000b8486075caea2c`, manifest SHA `13ab0a604748d7c3bd87b699d7b04b8ad6b6711db8009cd30198294081958546`. The previous production baseline was `ef989ddae4a2fc78e45105ae0b0b65679c27e794945a6a8048f6617c5d2a6d8d`.

Private target PHP 8.1.33 validation passed all 29 bounce checks, policy callback and DSWH fixtures, lint, and the one-file/three-guard dry-run. The parent applied this exact package at 14:43:12 UTC and verified the installed hash above. Backup: `/root/magicsmtp-bounce-ack-backup-20261009-v2`. Receipts: `.qa/bounce-ack-20261009/magicsmtp-bounce-ack-20261009-v2-upload-test-receipt.json` and `magicsmtp-bounce-ack-20261009-v2-apply-receipt.json`. No database/configuration/service changes or replay occurred. Parent independently passed synthetic publisher apply/verify/reconcile/rollback, post-publication failure restoration, and target/guard drift rejection. Real subsequent callback status and persistence are separate postflight evidence.

The first private target package exposed a case-sensitive fixture filename error after the bounce and policy tests had passed. Its failed receipt is preserved; no production apply occurred. The corrected package uses a new stage `/var/tmp/magicsmtp-bounce-ack-20261009-v2`, retaining v1 evidence. The target acceptance receipt explicitly records normal demo acceptance as unverified.

`patches/bounce-ack-ssh.py` defaults to a local plan, with separate explicit private test, apply, verify, reconciliation and rollback modes. `patches/deploy-bounce-ack.py` pins the previously reviewed atomic publisher, performs an exact one-file comparison, checks three untouched native/runtime guards, and requires the candidate-matching PHP8.1 receipt. It preserves metadata and backs up to `/root/magicsmtp-bounce-ack-backup-20261009-v2`. No service restart, database restoration, cache clearing or historical replay is part of apply/rollback. After uncertain execution, reconcile first; do not repeat apply. Rollback restores only the previous handler source and preserves current databases/effects.

Private evidence is under `.qa/quota-minute-20261009/` (`callback-correlation-readonly.json`, `runtime-keysha-readonly.json`) and `.qa/bounce-ack-20261009/` (target test/deployment receipts). Engine-side aggregate/ownership receipts live in the parent `outputs/campaign-speed-fix-20261009/` directory.
