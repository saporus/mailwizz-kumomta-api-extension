# Self-service Connect MailWizz

## Scope and normal workflow

This feature adds `magic_smtp_connect/index` to the normal customer and backend applications, using the existing extension controller, native layout, navigation and Bootstrap classes. A customer needs native delivery-server management access; a subaccount also needs `canManageServers()`. Backend operators need both extension-update and delivery-server-update permissions and explicitly select the customer. Customer requests cannot select a different customer ID.

An installation administrator first provisions six additive extension tables, a protected AES-256-GCM encryption key and an approved scheduler profile, then enables enrollment on the normal backend page. The fixed fallback settings file is outside the webroot at `private/magicsmtp-policy/connect-settings.json`; a protected `magicsmtp.connect` application parameter is supported for other installations. Customer enrollment never edits executable configuration or the existing static binding.

The customer chooses an eligible callback delivery server and creates a private, ten-minute pairing code. They paste it into the normal MagicSMTP tenant's Connect MailWizz panel, inspect the discovered servers, select additions, and finish verification. New connections initially authorize no servers. The existing administrator-managed binding can be adopted only with its exact tenant, bridge ID, secret and existing server IDs preserved. There is no remove, disconnect or secret-rotation operation in this release.

For day-to-day setup, an installation administrator opens **Connect MailWizz** in the normal backend and enables customer connections once. The customer then opens **Connect MailWizz** in their normal customer navigation and creates a code. A MagicSMTP tenant owner opens the normal Suppression Rules connection panel, pastes the code, discovers their servers, adds the intended servers and completes signed verification. Additional eligible servers use that same shared callback and connection; they do not each need a new tenant webhook. Both applications must confirm the explicit added server scope.

If an unused code expires, create a new code. If a request timed out or the UI shows a partial operation, use the normal status/reconciliation action first: a timeout does not prove nothing changed. An operation can be restarted only after a signed terminal uncommitted/not-found result. Never replace a working callback URL or secret to resolve an uncertain operation. Existing server scope remains active while additions are pending.

New additions must be customer-owned, enabled Magic SMTP Web API servers with verified TLS and the exact approved Kumo API base. Global/shared servers, SMTP servers and direct-provider servers cannot acquire new authority through this workflow. Previously authorized static servers remain grandfathered when adopting that exact static binding. Multiple sending keys are supported when identity proofs show the same tenant and API base. Inventory eligibility is preliminary; selected credentials are verified before preparation, then ownership, status, URL and credential fingerprints are checked again at commit.

The live customer-1 account currently resolves `servers.max_delivery_servers=0`, with system-server sending allowed. Its customer-page restriction matches the native delivery-server controller, even though six previously provisioned API servers belong to the account. The backend operator can explicitly pair that customer on its behalf. No customer entitlement was changed to bypass the restriction; future self-service customers need delivery-server management enabled in their normal MailWizz plan.

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

The native-adapter fixtures are not browser logins or real demo-account acceptance. Target PHP 8.1 validation subsequently passed all thirteen suites plus eight PHP processes handling 160 duplicate callbacks. Schema preparation passed at 16:57:12 UTC on 2026-10-09 with enrollment disabled and zero managed connections. Its protected settings candidate hash is `c4c65414361dc88cdbd304606fe891adc0ee63b582a5da15c703e9a7716b13c0`; no key is recorded here. Separate normal backend/customer browser checks, actual isolated existing/new demo-account workflows and live signed pairing have now passed. The [native demo acceptance record](connect-native-demo-acceptance-20261009.md) documents saved/reloaded state, the corrected code-generation behavior and expiry denial for both QA accounts. Both temporary accounts were expired without deleting or resetting their data. No synthetic test constitutes mail sending or provider delivery evidence.

## Initial release and recovery

Frozen bundle: `.qa/connect-release-20261009-v1`; manifest SHA-256 `92d7ab8e9f38bd8b68d6f381cafb86f5bd9cb483b30769923f37e0e92e72558b`. Private QA files and native source captures are excluded from Git. The strict SSH wrapper is `patches/connect-ssh.py`; it accepts an explicit manifest pin, verifies every uploaded artifact, uses the established host key and refuses an existing upload stage.

Private target receipts (not committed) are `.qa/connect-20261009/magicsmtp-connect-20261009-v1-upload-test-1791565018557340000-receipt.json` and `.qa/connect-20261009/magicsmtp-connect-20261009-v1-prepare-1791565032476863700-receipt.json`. The manifest pins each of the nine app files, all uploaded test/helper artifacts and the untouched native/static guards. The separately reviewed settings SHA links schema preparation to later activation without recording the encryption key.

Release sequence:

1. `upload-test PIN`: create the private stage exclusively; run pinned PHP 8.1 synthetic suites, concurrency checks, lint and read-only CAS checks.
2. `prepare PIN`: install six additive tables with enrollment disabled; stage a new random encryption key and a copy of the current approved scheduler profile. It does not publish settings or change static bindings.
3. Create the exact operator-owned existing QA fixture through the reviewed CLI helper.
4. `apply PIN`: CAS-publish six dependencies and three entrypoint/runtime files, with verified backups. It performs native application initialization plus an explicit installed-runtime binding check. No service restart is required.
5. `activate PIN --settings-sha256 SHA`: verify all nine published hashes, exact private candidate hash, zero managed connections and no application-parameter override; exclusively publish settings outside the webroot. Enrollment remains disabled.
6. Use the native backend extension **Update** action for `magicsmtp` (1.2.0 to 1.3.0). MailWizz keeps the new extension routes unavailable until its stored extension version is updated. The extension update performs only additive/idempotent policy and connection schema installation; it preserves existing rows and demo edits.
7. Enable customer connections on the normal backend page, complete normal-page and demo acceptance, then pair/verify the intended tenant through its normal UI.

The stage is `/var/tmp/magicsmtp-connect-20261009-v1`; app backups are `/root/magicsmtp-connect-backup-20261009-v1`. The original static binding file, shared secret, callback URL and scheduler files are guards, not publication targets. The app publication and settings activation share a nonblocking private release lock. Unknown or interrupted results require read-only reconciliation before another action.

Before settings activation, rollback can restore the three modified files and remove the six new files from any verified baseline/candidate mixture. It preserves tables and prepared secrets/audit evidence. After settings activation, this initial publisher refuses code rollback: use `disable-enrollment PIN` to stop new setup while preserving active authority, or deploy a separately reviewed compatible fix. Do not remove the loader, database or encryption key after a managed connection exists. There is no automatic historical callback replay or campaign restart.

The separate 2027 commercial programme remains paused; this release covers only the explicitly authorized self-service connection feature.

## Live verification and demo correction

The nine app files were published at 16:58:37 UTC on 2026-10-09; exact hash verification passed. Protected settings were then activated with enrollment still disabled. The native backend extension update to 1.3.0 and Enable action completed through the normal admin pages. A code generated for callback server 433 was pasted into the normal MagicSMTP tenant-owner panel; pairing and signed verification completed with the existing customer and six-server scope preserved.

At 17:04:18 UTC, a bounded read-only native proof found exactly one active managed connection at revision 1, customer 1 and servers 433, 434, 436, 442, 444, 445. The decrypted overlay secret matched the original static secret, and scheduler verification passed. The native policy bridge class remains version 1.2.0; the enclosing extension is version 1.3.0. A single observation measured the scoped connection query at 0.220 ms and effective binding load at 0.735 ms; these are observations, not a performance benchmark. Receipt: `.qa/connect-managed-readiness.json`; collector: `patches/connect-readiness.php`.

Browser acceptance found one synthetic-state mismatch: generating another code changed an already connected demo back to available. A one-line runtime correction preserves connected state and revision. The focused native-adapter suite now passes 42 checks; the 268-check service suite also passes. Its separate one-file release manifest is `d4bcb8d7865445c378e2e54c3d095083eb761548a209863dadd8dac2e436ded1`; runtime SHA changes from `8ef78b9f5df9b6fdae3ba82ae41448dc6ef1a99c0689aded6b6d44bfbff86b53` to `087e58a692c5c6d2e45957f1e11afc5116b342349ab3b51109303e9f0fd30e88`. It retains the deployed binding loader and all authority/configuration guards. The private backup is `/root/magicsmtp-connect-demo-state-backup-20261009-v1`; its explicit rollback restores the previous managed runtime, never the prefeature static-only loader.

The correction passed both isolated target PHP suites and was applied/verified at 17:07:32 UTC. The single post-correction native check at 17:08:10 UTC again proved revision 1, the original six IDs, an unchanged overlay secret and verified scheduler. It observed 0.197 ms for the scoped connection query and 0.720 ms for binding load. The final redacted receipt is `.qa/connect-managed-readiness-20261009T170810Z.json`. No database, settings, quota or service changes accompanied the correction.

After a subsequent code release, the initial publisher's strict pins intentionally reject changed code. If enrollment must be disabled while preserving current authority, verify the original pinned `connect-installation.php` helper, then use its explicit `disable-enrollment` mode; do not bypass a changed-code guard by restoring the prefeature loader. This mode only changes enrollment and appends its audit entry.
