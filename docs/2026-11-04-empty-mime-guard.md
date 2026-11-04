# Empty MIME transport guard

## Transport boundary

The connector previously forwarded the string returned by the native mailer without checking emptiness. An API accepting raw MIME requires a nonempty serialized message. This change validates that transport boundary before submission.

Native DummyMailer simulates rendering with a random string whose length can be zero. It provides a synthetic reproduction of invalid output. The guard also covers empty or whitespace-only output from other renderers. It does not attempt to infer why a renderer returned empty content.

## Change

`DeliveryServerMagicSmtpWebApi::send()` rejects native DummyMailer selection before serialization. It then rejects empty or whitespace-only serialized MIME before reading its message ID, recording policy dispatch, or making an HTTP request. Both conditions enter the existing local failure/logging path with actionable error text.

Nonempty MIME is forwarded byte for byte. The implementation keeps the existing hooks, idempotency identity, suppression bridge, retry behavior and quota accounting. It does not rebuild content or substitute fallback text.

## Local verification

PHP 8.2.30 synthetic fixtures passed on 4 November.

- Send fixture passed 24 assertions, including 15 new guard and MIME-preservation assertions.
- Short retry passed 51 assertions.
- Native interactive-policy fixture passed 75 output checks.
- Transient admission fixture and eight idempotency assertions passed.
- PHP syntax validation passed.

The initial source-contract fixture expected release 1.2.0 while the existing bootstrap declares 1.3.0. Its version assertion failed and its other 15 assertions passed. The stale expectation was then corrected to the existing 1.3.0 release, and a fresh run passed all 16 assertions. The original failed receipt remains preserved. This guard does not change the release declaration.

The baseline model has SHA-256 `15f6c34edb58ff73a880b250893f5e4b5e9ad57018d24c24f89635e9612b7918`. The local candidate has SHA-256 `1964a22adcbdd4f4b4a34023b44fa7b408487890a54283cf8d7eca8dc090dcec`.

Detailed local receipts are in the task workspace as `content-errors-transport-tests-20261104.json` and the corresponding test logs. The tests use synthetic clients and isolated temporary state. They do not exercise live delivery.

The corrected complete result is `content-errors-transport-tests-final-20261104.json`. MailWizz 2.7.3 and the official 2.8.1 update both expose `Mailer::getActiveMailer(): string`. Read-only source checks found identical Mailer component SHA-256 `306855640a1f972d2139df54e69a90ddeca9d2ab8b4bd67624e7347f70c81983`, with each version constant verified separately. This checks the added method dependency and does not certify every MailWizz workflow.

A broader run passed all 22 tracked standalone PHP fixtures that run without private native sources or operator arguments. Initial curl/OpenSSL dependency failures were preserved, then corrected by enabling the installed PHP extensions for those fixture invocations. The signed-bounce fixture requires a separately hash-pinned private native FBL source and remains outside this standalone result. The native minute-quota, scheduler, retry-bridge, operator-demo and Node/PHP peer workflows are also separate acceptance steps. The complete inventory and logs are recorded in `content-errors-all-standalone-php-final-20261104.json`.

The same send fixture was also run against the baseline model in a disposable local directory. All eight empty/whitespace/DummyMailer guard assertions failed because the old transport submitted the synthetic invalid payloads. The candidate passes those eight cases. `content-errors-transport-baseline-repro-20261104.json` binds both model hashes and preserves the failed baseline.

## Acceptance and rollout boundary

The combined MCA/transport fixture passed 68 checks with synthetic mailer and HTTP clients. Source-pinned MailWizz 2.7.3 fixtures passed 121 scheduler, 150 Move and 65 readiness checks, plus 282 isolated HTTP preview checks. The final native content/MIME fixture passed 128 checks, including HTTP and HTTPS tracking-domain transforms, without invoking delivery or transport.

Existing and newly created isolated demo accounts were verified through normal customer login and real campaign pages: subject/HTML/text save and reload, ten-variant previews, guest protection, invalid CSRF rejection, cross-owner denial and expiry enforcement. Both owned fixtures were expired after acceptance while preserving their saved data. Authoritative final checks found zero delivery logs, no real delivery servers and disabled sending and AI. Client-shared demos were not modified.

Deployment used verified backups and the supported extension lifecycle. Source acceptance was refreshed only for the qualified command/model changes, with preserved historical evidence. Managed-connection fingerprints and scoped scheduler pins were separately revalidated; their policy, identity, secrets, scopes and routing were preserved. Detailed operational evidence remains in the private deployment project.

There is no schema migration. Deployment requires backing up the existing extension model and verifying the expected source hash. Rollback restores that exact model file and revalidates any source-pinned acceptance store without deleting its history. Historical campaign results, completed-campaign state, suppression records, quotas and reservations require no changes. No failed recipient is automatically retried by this patch.
