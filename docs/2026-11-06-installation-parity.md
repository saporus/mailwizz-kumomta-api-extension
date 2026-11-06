# Installation parity verification — 6 November 2026

The existing MailWizz 2.8.1 / PHP 8.3 installation was upgraded from extension 1.1.6 to the complete 1.3.0 content-guard release. The comparison installation runs MailWizz 2.7.3. Both now match all nineteen published release-file hashes, including the Web API model's empty-MIME guard. Version labels alone were insufficient: two older form views on the comparison installation lacked the published validation error summary; only those two lines were restored there.

Archive: `magicsmtp-1.3.0-content-guard-20261104.zip`, SHA-256 `8ee19c066d8d2578b0bfb369cc5f9d55846134f09b015fe5e8e52f096c5d1509`. No release-source or archive rebuild was necessary.

## Scope and qualification

Twenty-three synthetic PHP suites passed on the actual target PHP 8.3.36 runtime. They cover transport, MIME rejection, idempotency, shared cooldown, bounded retries, quota accounting, callbacks, signed bounce/complaint persistence, isolated connection state, ownership and expiry. Network delivery functions were disabled in the parent fixture processes; HTTP, customers and ledgers use synthetic adapters. Eight child processes in the signed-bounce test produced the expected single durable effect.

The target's eighteen relevant native quota/ledger methods match the previously accepted 2.7.3 methods after newline normalization. An exact-source-pinned private 2.8.1 native quota fixture passed twelve checks; native Mailer and feedback-loop interfaces also matched their accepted pins. The installed 2.8.1 code-99 retry bridge was retained byte-for-byte. Licensed native sources and private fixtures are excluded from this repository.

The supported extension Update lifecycle installed its additive policy/connect tables, stored version 1.3.0 and retained enabled state. Thirteen actual application checks passed afterward. Both existing administrator extension pages showed enabled version 1.3.0. All delivery-server configuration, credentials, ownership and quotas, campaign configuration/assignments, cron entries and the separate daily-news configuration remained unchanged. No test email, recipient replay, campaign send, service restart or quota/cache reset occurred.

This is installation and no-send transport compatibility evidence, not end-to-end delivery proof. Optional signed-policy scheduling and self-service pairing remain unconfigured and enrollment disabled on 2.8.1. Their explicit 2.7.3 scheduler guard was preserved. Do not advertise those workflows as qualified on 2.8.1.

## Demo boundary

The release's existing and newly created isolated demo adapters passed the normal runtime/controller fixture again, including durable edits and denied execution/expiry paths. Earlier normal-login 2.7.3 demo evidence remains in the linked release documentation. No client-shared demo account was changed or reopened. The separate expired 2.8.1 News Drafts test workspaces remained unchanged. Fresh normal-login existing/new demo acceptance for optional 2.8.1 connection pairing remains unqualified; this deployment does not activate that feature or claim its acceptance.

## Preserved failures and reconciliation

- Two CLI callback fixtures initially emitted diagnostics before changing the response status. A buffered runner resolved the test harness issue; application source was unchanged and original logs retained.
- The signed-bounce fixture initially requested PDO SQLite again in child processes although the target already loads it. A private fixture copy removed that duplicate extension-loading argument; all fifty-two signed-bounce assertions then passed. The public fixture and native source were unchanged.
- Native Update completed and stored 1.3.0 before a later verification step failed because its restrictive PHP invocation blocked the local Redis socket. Current durable state was inspected first. No lifecycle replay occurred; read-only application checks with normal local cache access passed.
- An initial form-patch guard did not account for indentation. It stopped before changing either form. A line-aware exact comparison was used, retaining the original backup.

## Migration and rollback

The upgrade adds only extension-owned policy/connect tables, initially without bindings or enrollment. It does not migrate subscribers, campaign content, credentials or delivery limits.

Private deployment receipts retain the complete old extension, affected configuration, original cron and exact release manifest. The target used an atomic directory exchange, preserving the old tree outside the public root. Rollback requires comparing current authority and data first: restore the saved extension tree and its previous version option only when no newer binding or enrollment depends on the new runtime. Keep audit/evidence tables; never restore an old subscriber database or delete generated drafts. For the comparison installation, rollback restores only the two backed-up form views after checking their current hashes.

Private operational evidence is intentionally kept outside this public repository. No customer records, credentials, proprietary application source or raw deployment configuration is published.
