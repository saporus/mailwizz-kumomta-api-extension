# Native campaign and template previews with recipient-policy bindings

Status: published to servermail2.com at 08:00:45 UTC on 2026-10-10 and independently read back at 08:01:27 UTC. A user-triggered live preview remains unverified. The 2027 commercial programme remains paused.

## Problem and verified native call sites

A manual campaign test on a policy-bound Magic SMTP delivery server returned `Temporary sending delay: recipient policy dispatch coordination is unavailable.` The native campaign test does not pass campaign/subscriber identifiers to `send()`: it sets the server's native `DELIVERY_FOR_CAMPAIGN_TEST` purpose and delivery object instead. The extension unconditionally requested a campaign-recipient dispatch record and failed before making its HTTP submission. Changing the API key's direct/relay selection cannot resolve this local correlation mismatch.

Read-only native-source evidence was captured on 2026-10-10 at 07:42–07:44 UTC. The source remains private and is not included in this repository.

| Native source under `apps/` | SHA-256 | Relevant contract |
| --- | --- | --- |
| `customer/controllers/CampaignsController.php` | `8b2eccd57e83cf88c74061f9cf7fa659b00882f54da8d461eff98705d69faca1` | `actionTest` starts at 1828, builds test params without correlation identifiers, and calls native campaign-test purpose/object at 2190; the displayed error with mailer log is at 2195. Bulk test uses the same purpose/object at 2957. |
| `customer/controllers/TemplatesController.php` | `b8285866999fe161cc8afaaeca59103b8d9fca6e0bc0c83d1e41ebcc5229cf77` | `actionTest` starts at 409; template-test purpose/object are set before `send()` at 493. |
| `common/models/DeliveryServer.php` | `a173922750c477558ac5302d5355ce68a5993f5ab2f8e639b944a41cf019bfef` | Native delivery-purpose/object getters and setters; default empty `campaignUid` and `subscriberUid` in `getParamsArray()`. |

The installed pre-change `MagicSmtpPolicyRuntime.php` hash was `7777568dee3e7b6117a585be1025d3c1c039540f3301fc710f47917f1176f083`. The unchanged delivery model hash was `15f6c34edb58ff73a880b250893f5e4b5e9ad57018d24c24f89635e9612b7918`.

## Narrow correction

Only `magicsmtp/MagicSmtpPolicyRuntime.php` changes production behavior. It recognizes customer POST actions `campaigns/test`, `campaigns/bulk_action` and `templates/test` only when all of these are established:

- The request is not a CLI worker; the customer is authenticated.
- The native delivery-purpose marker and real Campaign or CustomerEmailTemplate object match the action.
- The delivery object's customer equals the authenticated customer and has an enabled binding for that server.
- The params contain no campaign/subscriber correlation. Partial or malformed correlation is not treated as a preview.
- Binding loading, policy-store schema checks and the existing scheduler acceptance/version/source-hash checks succeed.

A recognized preview continues through the existing API submission path without creating a fictitious campaign recipient, dispatch proof or campaign idempotency key. Tenant identity still comes from the API key, and the gateway continues to enforce recipient policy and all normal admission checks. No request flag, email subject or header can opt in. The exception is recomputed for every send and is not retained when a server object is reused.

Campaign workers and ordinary sends retain their existing fail-closed correlation, durable dispatch proof and retry behavior. This hotfix intentionally does not add an exception for delivery-server validation routes or other manual/system email paths. It adds no retries and does not resend prior failed or uncertain messages.

## Local verification

On PHP 8.2.30 with PDO SQLite, `tests/policy_interactive_test_runtime_test.php` passes using the real extension model, runtime, bridge and store, a synthetic HTTP client, an isolated SQLite database and temporary synthetic scheduler marker files. No licensed native code, customer data, provider requests or real sending are involved.

The fixture covers campaign and template tests, bulk test context, direct/relay API-key preservation, exact one-request submission, no phantom dispatch/idempotency identities, unchanged gateway policy refusal, shared-server customer scoping, wrong routes/purpose/object/owner, unauthenticated/non-POST requests, malformed/partial correlation, CLI code 99, reused objects, unavailable binding/schema, scheduler acceptance/hash drift, valid campaign proof before HTTP and campaign write failure.

All eight existing targeted suites pass: `policy_bridge_runtime_test.php`, `policy_callback_runtime_test.php`, `policy_correlation_runtime_test.php`, `policy_disabled_runtime_test.php`, `send_runtime_test.php`, `transient_send_runtime_test.php`, `short_retry_runtime_test.php`, and `connect_policy_refresh_test.php`. PHP lint and `git diff --check` pass. Independent source review and rerun of the new fixture also pass.

## Demo boundaries

No demo UI, account fields or seed structure changes are required. Existing/new isolated demos continue using normal login and pages. The independent reviewer reran the Kumo existing/new recipient-policy, MailWizz-connection, relay and normal-login demo suites; they pass, including durable isolated state, expiry restrictions and external-send rejection. The normal-login fixture uses localhost only. These automated results do not claim a fresh production browser preview or live email acceptance. No client-shared demo account was modified.

## Publication and rollback

Only the runtime file was published. Its verified SHA-256 is `e11e9eaa76f0c5f6d60fb20b5d9554c76624a6cf35b4ff22b4de912036a00bb0`; uid/gid 1003, mode 0644 and extended attributes were preserved. Seventeen unchanged native/extension source hashes were checked before and after the atomic replacement. The original file and publication evidence are retained privately under `/root/magicsmtp-policy-runtime-backup-20261010-v1`.

All nine focused PHP fixtures passed with installed PHP 8.1.33 in a network-isolated namespace before publication. The private publisher's thirteen atomic-write/diff/concurrency fault tests passed locally. No licensed MailWizz source was uploaded or committed. An initial staging attempt created only an empty private `before.php` placeholder; its exact state was inspected and reconciled before completing the upload. Production was not touched by that staging error.

Postflight loaded the installed extension under MailWizz 2.7.3 in a read-only database transaction: the policy schema and enabled customer binding remained healthy and scheduler verification passed. The PHP-FPM master remained active with the same PID; inspected PHP configuration hashes were unchanged and timestamps preceded the master start. Source configuration enables timestamp revalidation every two seconds. This supports expected activation on the next web request; it is not a direct observation of cached opcode or live email acceptance. No service restart was needed or performed.

Manifest SHA-256: `26f87f2cc153f24a334f73fc4cb8c31a3f205d6af24e7fa37184be26797c7253`; publisher SHA-256: `f2dc477f809f647e934ad5cda27cd44cdb27e91c0b2a53f45ac84750053894c6`. Private receipts and the immutable bundle remain in the parent workspace's `outputs/direct-policy-coordination-20261010/`. No schema migration, campaign change, API-key change or Kumo engine change was performed.

Rollback restores only the saved runtime file and its metadata. The pinned private publisher at `/var/tmp/magicsmtp-policy-runtime-20261010-v1/publish-policy-runtime.py` supports `--mode rollback --manifest-sha256 26f87f2cc153f24a334f73fc4cb8c31a3f205d6af24e7fa37184be26797c7253`; inspect current state and authorize rollback before invoking it. Preserve all current policy/dispatch records, account settings, accepted messages, queues and audit evidence. A user-triggered preview that has already been accepted must not be resent automatically.
