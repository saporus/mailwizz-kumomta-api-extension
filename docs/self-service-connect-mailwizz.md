# Self-service Connect MailWizz

## Scope and normal workflow

This feature adds `magic_smtp_connect/index` to the normal customer and backend applications, using the existing extension controller, native layout, navigation and Bootstrap classes. A customer needs native delivery-server management access; a subaccount also needs `canManageServers()`. Backend operators need both extension-update and delivery-server-update permissions and explicitly select the customer. Customer requests cannot select a different customer ID.

An installation administrator first provisions six additive extension tables, a protected AES-256-GCM encryption key and an approved scheduler profile, then enables enrollment on the normal backend page. The fixed fallback settings file is outside the webroot at `private/magicsmtp-policy/connect-settings.json`; a protected `magicsmtp.connect` application parameter is supported for other installations. Customer enrollment never edits executable configuration or the existing static binding.

The customer chooses an eligible callback delivery server and creates a private, ten-minute pairing code. They paste it into the normal MagicSMTP tenant's Connect MailWizz panel, inspect the discovered servers, select additions, and finish verification. New connections initially authorize no servers. The existing administrator-managed binding can be adopted only with its exact tenant, bridge ID, secret and existing server IDs preserved. There is no remove, disconnect or secret-rotation operation in this release.

For day-to-day setup, an installation administrator opens **Connect MailWizz** in the normal backend and enables customer connections once. The customer then opens **Connect MailWizz** in their normal customer navigation and creates a code. A MagicSMTP tenant owner opens the normal Suppression Rules connection panel, pastes the code, discovers their servers, adds the intended servers and completes signed verification. Additional eligible servers use that same shared callback and connection; they do not each need a new tenant webhook. Both applications must confirm the explicit added server scope.

If an unused code expires, create a new code. If a request timed out or the UI shows a partial operation, use the normal status/reconciliation action first: a timeout does not prove nothing changed. An operation can be restarted only after a signed terminal uncommitted/not-found result. Never replace a working callback URL or secret to resolve an uncertain operation. Existing server scope remains active while additions are pending.

New additions must be customer-owned, enabled Magic SMTP Web API servers with verified TLS and the exact approved Kumo API base. Global/shared servers, SMTP servers and direct-provider servers cannot acquire new authority through this workflow. Previously authorized static servers remain grandfathered when adopting that exact static binding. Multiple sending keys are supported when identity proofs show the same tenant and API base. Inventory eligibility is preliminary; selected credentials are verified before preparation, then ownership, status, URL and credential fingerprints are checked again at commit.

## Durable protocol and execution boundaries

The existing DSWH endpoint handles the exact management envelope `event_type: integration.mailwizz`, version 1. Initial pairing uses a single-use opaque token bound to native customer, callback endpoint, API base and tenant. Sending credentials are used only against the approved read-only `/mailwizz-integration/identity` endpoint. They do not grant management access.

Subsequent requests use `X-Webhook-Signature` over the exact raw request body. Responses use `X-Magic-Integration-Signature` over the exact raw response body and echo the action, operation ID and nonce. Pairing secrets are encrypted at rest with per-connection authenticated context. Tokens are stored as hashes; audit records contain identifiers and counts, never credentials or raw envelopes.

Preparation claims a durable operation before bounded identity calls, then records a candidate without altering the active binding. Commit is an atomic, revision-checked, add-only update. Fresh identity proofs are at most sixty seconds old, and exact stored credentials/ownership are rechecked. The five-second preparation transport budget is shared across distinct keys; DNS resolution itself remains subject to the platform resolver's blocking behavior. The HTTP adapter pins public DNS addresses, verifies TLS and peer IP, disallows redirects/proxies and special/private address ranges, and bounds responses to 8 KiB.

Exact request retries return the stored result; changed requests cannot reuse an operation ID. Status resolves lost pair/commit responses, and failed or expired uncommitted preparation returns a signed terminal state. Unknown operation IDs cannot inherit a connection's committed state. Disabling enrollment blocks new pairing/preparation/commit while retaining active bindings, status and exact completed-operation replay.

Both signed bounce/complaint ingress and policy handling use the same effective binding loader. Conflicting or changed protected authority fails closed. The policy runtime fingerprints effective bindings and rebuilds its cached bridge when authority changes, so long-running workers recognize authorized additions without restarting. The native scheduler manifest continues to verify the unchanged native campaign command and queue behavior. Sending limits, campaigns, suppression classification and queues are outside the change.

The initial implementation bounds inventory/selection and total managed connections at 100. Pairing refuses a 101st managed connection before it could affect the effective binding loader. Pairing-code issuance is limited to ten per customer per hour.

## Demo behavior and acceptance

Demo workspaces use this same customer route and view. Operator-registered synthetic workspaces are bound to a native customer UID and administrator expiry, require zero sending quota, disabled system-server use and no real owned delivery servers. Demo pairing uses `DEMO-MAILWIZZ-PAIR`, matching the normal MagicSMTP demo panel. Sample connection edits are saved durably in the isolated workspace. These actions cannot create a real grant, managed connection, credential or external request.

Schema installation is additive and preserves existing demo edits. The operator acceptance helper creates only uniquely named QA accounts, their restricted groups and synthetic state. It uses direct scoped inserts to avoid notification hooks; credentials are saved only in root-private receipts. It never resets or extends a shared demo account. Existing-workspace acceptance is scheduled before code activation, and new-workspace acceptance after activation.

Local acceptance as frozen on 2026-10-09:

| Evidence | Result |
| --- | --- |
| PHP service/store security and recovery | 13 scenarios, 268 checks passed |
| Stored-key HTTPS transport | 118 checks passed; zero external requests |
| Actual runtime/controller/view with native adapters | 38 checks passed, including existing/new isolated demo save/reload, expiry, CSRF, permissions and escaping |
| Policy bridge refresh in one worker process | 7 checks passed, including dispatch recording after a binding addition |
| Real Node service to real PHP service/PolicyBridge | Parent-reported 142 checks across six synthetic scenarios, including new/static adoption and lost results |
| Existing policy, correlation, callback, send and transient retry suites | Passed |
| Publication and rollback fixtures | 18 scenarios passed, including interrupted mixed state, target/guard drift, late CAS and exclusive creation |

The native-adapter fixtures are not browser logins or real demo-account acceptance. Target PHP 8.1 validation subsequently passed all thirteen suites plus eight PHP processes handling 160 duplicate callbacks. Schema preparation passed at 16:57:12 UTC on 2026-10-09 with enrollment disabled and zero managed connections. Its protected settings candidate hash is `c4c65414361dc88cdbd304606fe891adc0ee63b582a5da15c703e9a7716b13c0`; no key is recorded here. Normal backend/customer pages, actual isolated existing/new demo accounts and live signed pairing remain explicit release gates. No synthetic test constitutes mail sending or provider delivery evidence.

## Initial release and recovery

Frozen bundle: `.qa/connect-release-20261009-v1`; manifest SHA-256 `92d7ab8e9f38bd8b68d6f381cafb86f5bd9cb483b30769923f37e0e92e72558b`. Private QA files and native source captures are excluded from Git. The strict SSH wrapper is `patches/connect-ssh.py`; it accepts an explicit manifest pin, verifies every uploaded artifact, uses the established host key and refuses an existing upload stage.

Private target receipts (not committed) are `.qa/connect-20261009/magicsmtp-connect-20261009-v1-upload-test-1791565018557340000-receipt.json` and `.qa/connect-20261009/magicsmtp-connect-20261009-v1-prepare-1791565032476863700-receipt.json`. The manifest pins each of the nine app files, all uploaded test/helper artifacts and the untouched native/static guards. The separately reviewed settings SHA links schema preparation to later activation without recording the encryption key.

Release sequence:

1. `upload-test PIN`: create the private stage exclusively; run pinned PHP 8.1 synthetic suites, concurrency checks, lint and read-only CAS checks.
2. `prepare PIN`: install six additive tables with enrollment disabled; stage a new random encryption key and a copy of the current approved scheduler profile. It does not publish settings or change static bindings.
3. Create the exact operator-owned existing QA fixture through the reviewed CLI helper.
4. `apply PIN`: CAS-publish six dependencies and three entrypoint/runtime files, with verified backups. It performs native application initialization plus an explicit installed-runtime binding check. No service restart is required.
5. `activate PIN --settings-sha256 SHA`: verify all nine published hashes, exact private candidate hash, zero managed connections and no application-parameter override; exclusively publish settings outside the webroot. Enrollment remains disabled.
6. Enable customer connections on the normal backend page, complete normal-page and demo acceptance, then pair/verify the intended tenant through its normal UI.

The stage is `/var/tmp/magicsmtp-connect-20261009-v1`; app backups are `/root/magicsmtp-connect-backup-20261009-v1`. The original static binding file, shared secret, callback URL and scheduler files are guards, not publication targets. The app publication and settings activation share a nonblocking private release lock. Unknown or interrupted results require read-only reconciliation before another action.

Before settings activation, rollback can restore the three modified files and remove the six new files from any verified baseline/candidate mixture. It preserves tables and prepared secrets/audit evidence. After settings activation, this initial publisher refuses code rollback: use `disable-enrollment PIN` to stop new setup while preserving active authority, or deploy a separately reviewed compatible fix. Do not remove the loader, database or encryption key after a managed connection exists. There is no automatic historical callback replay or campaign restart.

The separate 2027 commercial programme remains paused; this release covers only the explicitly authorized self-service connection feature.
