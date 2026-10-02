# Signed recipient policy bridge — 1.2.0

This release adds an explicit recipient-policy channel. A delivery error may remain a native MailWizz soft bounce while a customer rule independently places that recipient on a temporary hold or permanent policy suppression. Native blacklist, unsubscribe, complaint and subscriber states remain authoritative and are never removed or changed by policy release.

## Compatibility and activation gate

The supported scheduler baseline for this release is **MailWizz 2.7.3**, verified against read-only copies fetched from the existing servermail2 installation on 2026-10-02. The installed `apps/init.php` reports `MW_VERSION = 2.7.3`. Source retrieval did not execute application code, change campaign/cron state, install the extension or send mail. Existing native retry and other local fixes are retained in candidate copies.

MailWizz 2.8.1 is supported by earlier transport-only features, but **this recipient-policy scheduler is not yet approved for 2.8.1 or another version**. The preparer and readiness probe reject unsupported versions. Custom `console_send_campaigns_command_count_subscribers` or `console_send_campaigns_command_find_subscribers` overrides also fail readiness because they bypass the proven paths.

The signed `recipient.policy_probe` returns `holdSchedulerVerified` and `permanentPolicyVerified` only when the schema is readable, configured tenant/customer/server binding is valid, the exact installed core hashes match the acceptance receipt, and the supported version and selector hooks match. The sender must require both verified capabilities and matching tenant/customer/server IDs before enabling policy rules. Copying this extension alone does not enable policy synchronization.

With `magicsmtp.policyBridges` absent, policy code does not open the database, record dispatch, add report columns, restrict selectors or block campaign completion. The disabled-feature fixture verifies these boundaries. The normal transport behavior remains available; dedicated policy callbacks fail closed until explicitly configured. Legacy bounce callbacks intentionally default missing/unknown `bounce_type` to soft, and correlation checks now include the delivery server and recipient.

The read-only servermail2 rollout inventory found extension **1.1.5**, with no policy-binding key in the common configuration files. The scoped prepared rollout changes six policy extension files and the two verified scheduler files; live form files, the controller and old backup files remain untouched. The updated Web API model also includes the previously committed bounce-capability fix (`getBounceServerNotSupported`), which correctly identifies its registered DSWH feedback path. The private rollout helper checks original/candidate hashes, preserves file ownership/mode and complete extension backups, and has passed local apply/rollback plus a failure injected after seven replacements. Schema installation and explicit bridge binding remain separate steps; no production installation or activation is implied by this acceptance.

## Administrator setup

1. Preserve the complete current extension, database and both scheduler files. Use the normal MailWizz backend extension **Update** or **Enable** workflow to create the three additive `magic_smtp_policy_*` tables, honoring the existing database table prefix. No callbacks create tables. No existing subscriber data is migrated.
2. Prepare candidate scheduler copies from the installed application. This command **does not edit the application**:

   ```sh
   php patches/prepare-policy-scheduler.php /path/to/mailwizz /private/new-policy-candidate
   php -l /private/new-policy-candidate/apps/console/commands/SendCampaignsCommand.php
   php -l /private/new-policy-candidate/apps/common/components/db/behaviors/CampaignQueueTableBehavior.php
   php tests/policy_scheduler_runtime_test.php /private/new-policy-candidate
   ```

   The runtime test requires PDO SQLite. On the local Windows PHP installation, add `-d extension=php_pdo_sqlite.dll`. The test executes the actual candidate command and queue-table methods against disposable synthetic records; it does not bootstrap the production application, use its database or send mail. A successful test writes `acceptance: passed` and its test-file hash to the candidate manifest. Before that, readiness remains false.
3. Inspect the candidate diff. During an explicitly authorized maintenance window, with affected sending workers quiesced and the original paused/sending state recorded, run:

   ```sh
   php patches/apply-policy-scheduler.php dry-run /path/to/mailwizz /private/new-policy-candidate /private/new-policy-backup
   php patches/apply-policy-scheduler.php apply /path/to/mailwizz /private/new-policy-candidate /private/new-policy-backup
   ```

   The utility checks current source hashes, candidate hashes, installed version and the exact acceptance-test version. It creates a fresh private backup and restores files already written if replacement fails. It does not change cron, quotas, campaign states or subscriber records. Apply from the application owner account. Do not resume campaigns that were already paused.
4. In the application's administrator-controlled custom parameters, add `magicsmtp.policyBridges`. Use the existing dedicated Kumo webhook secret; do not use the sending API key. The following is a **masked example**, not a usable secret:

   ```php
   'magicsmtp.policyBridges' => [
       [
           'bridge_id' => 'bridge-id-from-the-tenant-policy-settings',
           'tenant_id' => 'tenant-id-from-the-tenant-policy-settings',
           'customer_id' => 123,
           'server_ids' => [42, 43],
           'secret' => 'REPLACE_WITH_THE_EXISTING_WEBHOOK_SECRET',
           'enabled' => true,
           'scheduler_manifest' => json_decode(
               file_get_contents('/private/new-policy-candidate/policy-scheduler-manifest.json'),
               true
           ),
       ],
   ],
   ```

   A delivery server may be shared by distinct tenant/customer bindings. Each `(tenant, server)` and `(customer, server)` pair must be unique, and each bridge ID is unique. The bounded callback tenant selects a configured key; the complete raw-body HMAC must verify before any database access. Sending selects the binding through the trusted campaign customer's ID plus the server, never a callback-supplied customer ID. Unbound customers remain unaffected. The administrator explicitly authorizes each listed server for its bound customer, and dispatch correlation verifies campaign ownership. The config and manifest must be outside the public web directory, readable only by the application/administrators. Never log their secret or put a real configuration in this repository. Preserve the verified manifest in a stable protected location for runtime use.
5. Configure the dedicated policy webhook for the existing `/dswh/{authorized-server-id}` URL using the **Kumo/default JSON format**, the identical secret, and the exact bridge ID. Run the signed nonce probe and inspect the returned identity/capabilities before activation. An unsigned or unbound policy event is rejected.

## Callback contract

Send JSON with `Content-Type: application/json` and:

```text
X-Webhook-Signature: lowercase hex HMAC-SHA256(secret, exact raw JSON bytes)
```

The body is not reserialized for validation. The native request reader handles header names case-insensitively. Request bodies over 2 MiB are rejected. Policy effects require this shape (sample values are synthetic):

```json
{
  "event_id": "stable-policy-event-id",
  "webhook_event_id": "stable-policy-event-id",
  "event_type": "recipient.policy_suppressed",
  "tenant": "tenant-a",
  "timestamp": 1800000000000,
  "data": {
    "bridge_id": "bridge-a",
    "recipient": "person@example.test",
    "policy_effect_id": "stable-effect-id",
    "action": "temporary",
    "expires_at": 1800003600000,
    "rule_id": "rule-a",
    "rule_revision": 1,
    "message_id": "original-mailwizz-message-id@example.test",
    "campaign_id": "mailwizz-campaign-uid",
    "subscriber_uid": "mailwizz-subscriber-uid",
    "reason": "Customer mailbox-full policy",
    "evidence": {
      "event_key": "source-event-id",
      "provider": "yahoo",
      "response_code": 552,
      "response_text": "Mailbox full",
      "source": "smtp"
    }
  }
}
```

- `message_id` is the original MailWizz RFC Message-ID, not the remote provider's unrelated message ID. Current Web API sends supply `campaign_id` as the MailWizz campaign UID. Historical numeric campaign IDs are accepted only after a trusted native lookup confirms the customer/dispatch. An omitted campaign ID can be derived from an otherwise unique original-message/recipient/customer/authorized-server match. Ambiguous matches are rejected. `subscriber_uid` is optional additional proof.
- `action` is exactly `permanent` or `temporary`. Temporary expiry is integer Unix milliseconds. Permanent expiry is `null`. `rule_revision` is an integer at least 1.
- Releases use `recipient.policy_released`, a new stable event ID, and the **same effect ID, recipient, message and campaign identity**. They may also include `release_reason`. Release tombstones prevent delayed suppression messages from reviving an already released effect. Releasing one effect cannot remove another overlapping effect.
- `recipient.policy_probe` instead carries `data: {bridge_id, nonce}`. The response echoes `nonce` (and the legacy alias `challenge`) with `bridgeId`, `tenantId`, `customerId`, `serverIds`, `version`, `permanentPolicyVerified`, `holdSchedulerVerified`, and `capabilities`. A probe creates no recipient effects or receipts. `data.challenge` is accepted for compatibility.
- Event IDs are deduplicated per bridge, with the raw-body hash retained. Reuse with different content is rejected. Future timestamps beyond five minutes are rejected. Older authenticated deliveries are allowed because durable callback retries may arrive later; receipt/effect idempotency prevents replay side effects.
- Successful effect callbacks return `{ok:true,eventId,effectId,bridgeId,tenantId,state}`. `state` is the committed current `active` or `released` state, including duplicate and ignored deliveries (which also include `duplicate:true` or `ignored:true`). A repeated suppression after release reports `released`; a receipt with a missing effect fails closed. The sender must match all identity fields and expected state before marking synchronization complete; an unrelated HTTP 200 is insufficient.
- Strong proof is a durable pre-submission dispatch recorded by the Web API sender, or a unique current/archive delivery-log match scoped to an authorized server, customer-owned campaign, exact campaign UID and recipient. Unmatched or ambiguous events have no effects. A callback arriving before a legacy SMTP delivery log can be retried after that log exists.
- Policy handling never calls native bounce processing. An earlier native soft-bounce record does not prevent a later policy effect. The legacy bounce parser now treats absent/unknown `bounce_type` as soft rather than manufacturing a hard bounce. Its message lookup also checks server and recipient.

## Scheduling and display

Both ordinary/segmented campaigns and temporary queue tables apply recipient policy exclusions **before LIMIT/OFFSET**. Other eligible recipients continue. Pending counts exclude permanent suppressions but retain temporary holds. A separate completion guard prevents campaigns with held pending work being marked sent. Queue rows and autoresponder due dates are retained. A last-moment recipient check handles a policy change after selection; a permanent race uses MailWizz's existing `suppressed` delivery status, while a hold creates no delivery/giveup record.

Expiry only makes the recipient eligible for the existing scheduler; it never changes a campaign from paused to sending. Release only changes an extension effect. Native blacklist/unsubscribe/complaint controls remain in the normal send path. The normal campaign delivery report adds a **Recipient policy** column displaying **Policy suppressed** or **Policy held**, the reason and hold deadline. It does not rename a provider's SMTP result, fabricate delivery or turn a policy decision into an invalid-mailbox claim.

## Verification and demo boundaries

The scoped inert production release on servermail2 completed at **2026-10-02 05:29:23 UTC**, using source commit `6c7305d8c22352f57dc9d3aea1d3ea8f126f1853` (including the shared-delivery-server follow-up to `2c5be0a`). Exact MailWizz 2.7.3 source/configuration baseline checks passed before replacing six extension files and the two tested scheduler files. Other extension files, configuration, cron and campaign state were preserved. The three additive InnoDB policy tables were installed and remained empty.

All 12 standalone extension fixtures, the actual private 2.7.3 scheduler fixture and eight candidate PHP lints passed on servermail2 PHP 8.1.33. Apply/rollback, injected replacement failure, changed source/configuration and backup-fsync failure rehearsals passed. The missing SQLite test module was extracted privately for the fixture process; no package, system PHP setting or service was changed. A real application bootstrap with explicitly loaded policy classes confirmed source 1.2.0 with bindings absent and policy methods inert; backend/customer login GETs returned HTTP 200. This did not verify normal extension registration. No binding, webhook or rule was configured by that file/schema release and no message was sent.

The subsequent signed capability-probe attempt failed and both hosts rolled back their binding changes without creating policy effects. A normal extension-manager load then identified source version 1.2.0 with registry version 1.1.5 and `getMustUpdate() === true`. MailWizz skips enabled extensions requiring an update, leaving the DSWH processor unregistered and producing an empty HTTP 200 response. After an authorized source upgrade, run MailWizz's normal `extensionsManager->updateExtension('magicsmtp')` lifecycle, then verify registration in a fresh normal frontend and console bootstrap. Do not substitute a direct version-row update or explicit class imports for that acceptance check.

Policy callback errors pass the status directly to `BaseController::renderJson`, whose default status would otherwise replace an earlier `http_response_code()` value with 200. The focused callback fixture models this real renderer behavior and verifies an unconfigured bridge returns structured HTTP 403, oversized input returns 413, and ordinary bounce responses remain HTTP 200. The lifecycle and deployed-framework corrections below now have live acceptance evidence; configured bridge readiness remains separate.

The private deployment receipt is `outputs/kumo-memory-20260914/mailwizz-policy-production-release-20261002.receipt.json` in the parent workspace; the exact installed file hashes and final helper hashes are recorded there. Durable originals and the remote receipt are under `/root/magicsmtp-policy-backup-20261002-6c7305d`. The production manifest SHA-256 is `2c080de154366c8d9c1cf71f090e7c48e357fa259a6c294b6aac150154853e30`. Private full core files and backups are not included in this repository. Live bridge activation still requires an explicitly configured tenant/customer/server binding and successful signed capability probe.

Run every existing extension fixture plus `policy_bridge_runtime_test.php`. Run `policy_scheduler_runtime_test.php` separately with the prepared private source directory; it requires the customer's licensed MailWizz source and is not replaced by a bundled imitation.

Coverage includes raw-byte signatures, missing signatures, tenant/customer/server/recipient/campaign isolation, stable ID replay/conflict, probe gating, ambiguous bindings, permanent/temporary overlap, release tombstones, passive expiry, durable reopen, rollback after a failed effect write, and the actual candidate scheduler's ordinary/queue/autoresponder selection and completion behavior. Native blacklist and campaign records are never mutated by the policy store.

Fixtures use isolated synthetic records and no network clients or live keys. No client-shared demo account was modified. The Kumo UI feature must separately pass the normal-login existing/new demo workflows with synthetic bridge probes/callbacks and external execution disabled; extension fixtures alone are not evidence of that UI acceptance. A new standalone MailWizz demo page is not introduced.

## Rollback

Do not remove the scheduler while active recipient policies still rely on it. During an authorized maintenance window, first prevent new submissions from affected campaigns without resuming previously paused campaigns, disable new rule activation at the tenant policy source, and review active effects/outstanding callbacks. Preserve the policy tables and bridge bindings until that state is reconciled.

The guarded reverse command requires the current installed files still match the tested candidate and the backups still match their original hashes:

```sh
php patches/apply-policy-scheduler.php rollback /path/to/mailwizz /private/new-policy-candidate /private/new-policy-backup
```

Restore the complete previous extension only after the affected sending paths remain safely quiesced or another verified suppression mechanism is in place. Never delete policy receipts/effects as part of routine rollback, clear a native blacklist, reset subscriber statuses, or auto-resume campaigns. Re-run compatibility testing and nonce probes after any MailWizz upgrade or scheduler customization.

Full licensed source copies, generated candidate files, test databases and local receipts remain in ignored/private `.qa/` or private operational output directories. Commit only this extension, our tools/tests/documentation, and narrowly scoped patches; do not publish proprietary MailWizz core source.
## Deployed-framework callback compatibility follow-up

The normal extension lifecycle must be updated alongside source installation: MailWizz skips an enabled extension whose stored version is older than its source version. The October 2 release verification now checks actual frontend FPM and console registration without importing extension classes manually.

An actual signed no-binding probe exposed a second compatibility gap: the installed Yii 1 `CHttpRequest` has no `getHeader()` method. The handler now reads the FPM `HTTP_X_WEBHOOK_SIGNATURE` string and verifies it against the unchanged raw request bytes. Non-string or absent headers become an empty signature and are rejected. Callback fixtures deliberately omit the nonexistent method and cover real callback authentication, raw-body tampering, missing/malformed headers, and correct HTTP error status through MailWizz's JSON renderer.

Source tests are distinct from live activation evidence. The no-binding endpoint must return structured HTTP 403 before configuration, and a configured signed nonce probe must succeed before any rule activation. No recipient policy is created by these probes.

On October 2 the guarded normal lifecycle repair installed `b68ae9b65893b88f1e1b1fd6c38450f35c605566` and invoked `extensionsManager->updateExtension('magicsmtp')`. Fresh real frontend FPM and console checks verified source/registry 1.2.0, `mustUpdate=false`, registered DSWH processing, normally loaded classes and absent bindings. The registry update timestamp was **08:00:17 UTC**. Originals and receipts remain under `/root/magicsmtp-policy-lifecycle-backup-20261002-b68ae9b`.

The subsequent one-file header correction from `79d1760dc6d64aef3e8e62bb1f7948656fc902fb` was installed with callback SHA-256 `ef989ddae4a2fc78e45105ae0b0b65679c27e794945a6a8048f6617c5d2a6d8d`; its prior source and receipt remain under `/root/magicsmtp-policy-header-backup-20261002-79d1760`. Twelve standalone PHP suites and six isolated Linux replacement/rollback/drift scenarios passed. At **08:12:14 UTC**, an actual signed no-binding request to the existing server-433 callback returned structured **HTTP 403** with `ok=false` and `Policy bridge binding rejected`. The existing signing secret stayed on the Kumo host and was not emitted. No policy effects, binding, rules or email sends were created. Normal FPM/console verification preserved all three empty policy tables and campaign state.

Operational evidence in the parent workspace: `mailwizz-policy-lifecycle-apply-20261002.result.json`, `mailwizz-policy-lifecycle-verify-20261002.result.json`, `mailwizz-policy-header-apply-20261002.result.json`, `mailwizz-policy-header-fixtures-20261002.result.json` and `recipient-policy-negative-probe-20261002.result.txt`. The guarded header rollback restores only its saved model source; it does not revert extension registration or delete tables. Reverting to that pre-header source would restore the known callback compatibility defect and therefore requires deliberate review. The lifecycle rollback separately restores its saved source and prior registry version without deleting tables. Neither rollback is a default response to unrelated provider events.

The temporary missing-handler interval also produced empty-200 acknowledgements for ordinary bounce callbacks. Read-only reconciliation is required before replay: match exact original dispatch, customer, server, campaign and recipient, then verify a missing native bounce record. Preserve original webhook jobs and any existing native protections. Replaying an ordinary callback must not replay native delivery ingestion or create recipient-policy history. HTTP success alone is insufficient evidence of a repaired native bounce record.

The bounded October 2 **05:28:00–08:00:20 UTC** audit found **113** empty-200 bounce acknowledgements on the existing customer-1/server-433 integration. Indexed native current/archive dispatch checks proved **47** unique recipient/campaign matches with no native bounce record: **17 originally hard and 30 originally soft**. All 47 belong to campaign 305, which remains paused, and their subscribers were confirmed at inspection. The other **66** did not establish an original native dispatch match and are excluded from repair. This is not evidence that those 66 recipients should be suppressed.

A repair was **prepared and verified read-only, not executed**. A fresh cross-host preflight at **08:21:45 UTC** reproduced the exact 47 missing records. Eight isolated transaction/idempotency/drift fixtures passed. Its reviewed design inserts deterministic child webhook jobs and a tenant audit atomically, preserving the original payload, original acknowledgement and bounce classification. The existing retry worker performs delivery; there is no native event re-ingestion or delivery-total replay. It rejects stale MailWizz proof, changed source payloads, other replay records, disabled hooks, tenant/scope drift and another pending callback for the same dispatch. Hard callbacks retain normal MailWizz hard-bounce behavior, including existing blacklist semantics. Completion requires reading the actual persisted MailWizz bounce records, not just HTTP 200 responses.

Private prepared evidence: `mailwizz-callback-gap-accepted-20261002.json` SHA-256 `7124626a750a64677a85f9c124fdd802864c20d8f3b5a7f6f71cf5d2a364bf6b`, `recipient-policy-callback-gap-repair-20261002.py` SHA-256 `44882d795b3ae4d44c167fc5c92f3b8ce0d846eb15ec280d0b2c6e71013555fe`, and `recipient-policy-callback-gap-repair-preflight-20261002.result.json`. No contact addresses or payloads were printed or persisted in the local proof. No callback replay, policy bridge activation, rule activation or historical policy application followed this preflight.

## Tenant45 live bridge verification, October2 at22:11UTC

After the user confirmed tenant45 as the intended sending integration, the bridge was configured on the already verified servermail2 MailWizz2.7.3/source-and-registry1.2.0 installation. Fresh one-way credential association proved all five active MagicSMTP API servers433,434,436,442,444 belong to Kumo `clietn_trulymagical45` (`tenant-4e049403a560073e`) and MailWizz customer1. No keys or delivery-server assignments changed. Inactive server440 belongs to tenant43 and was excluded; the mistakenly selected tenant44 draft and saved webhook remained untouched.

Bridge `rpb-58702af2-dde8-44e7-ac86-07bccd0ae772` uses the existing signed `https://servermail2.com/dswh/433` callback. Its URL, signing secret, native payload format and existing bounce/complaint subscriptions were preserved; the two policy subscriptions were appended. A guarded private configuration include contains the exact tenant/customer/five-server binding and accepted scheduler manifest. It is outside the public web root, mode0600 in a mode0700 directory accessible to the existing FPM application owner. The source/header/lifecycle repairs documented above were not replaced.

An actual signed nonce-only request returned HTTP200 with the exact bridge, tenant, customer, all five servers, version1.2.0 and both verified policy capabilities. This is **positive configured-bridge evidence**, distinct from the earlier unbound403. The normal console postflight confirmed `schedulerReady=true`; both source and registry remain1.2.0 with `mustUpdate=false` and registered DSWH handling. Independent22:13UTC read-back found zero policy dispatch, receipt and effect rows, zero Kumo effects, and both saved Kumo rules disabled. No email, real recipient-policy effect, historical application, callback-gap replay or service restart occurred. Kumo's separate deployed-engine readiness gate remained off, so the rule was not activated.

MailWizz effects are intentionally customer-scoped. A future verified effect from an authorized tenant45 dispatch excludes that recipient throughout customer1, including its other delivery servers. Bridge-specific correlation and release preserve independent effects and native blacklist/unsubscribe protections. This behavior is not server-only suppression.

Fifty-three isolated operational cases passed before configuration: eight Linux apply/rollback/failure fixtures, 23 runtime/identity contract cases, 13 Kumo transaction/scope/preservation cases and nine no-network two-host orchestration cases. The release introduced no new demo UI/schema. The root coordinator reran `check-recipient-policy.mjs` and `check-recipient-policy-demo.mjs`; both passed, covering existing/new isolated durable workspaces, roles, tenant scope, preview/replay/expiry and zero external execution. No new browser demo QA was performed in this release and no client-shared demo was changed. The existing normal-login acceptance remains separately documented in the Kumo UI project; its normal administrator page was verified to show MailWizz ready for tenant45.

Private parent-workspace evidence is in `outputs/kumo-memory-20260914/mailwizz-tenant45-binding-v3-20261002`: apply receipt `recipient-policy-binding-v3-apply-20261002-221108.json` SHA-256 `fe1f72cf8da9fa239bb202dc085663cdacaf7a2ea03f93d4e780a95fbe94365a`; independent read-back `recipient-policy-binding-v3-readback-20261002-221313.json` SHA-256 `39653560529819ab12b577480b63b2952997590998e231bb825c629e5f3815b8`. MailWizz original configuration/metadata is preserved at `/root/magicsmtp-policy-binding-backup-20261002-tenant45-v3`; Kumo's original subscription string/fingerprint is at `/root/recipient-policy-binding-20261002-tenant45-v3.json`. Secrets were transferred only in memory through authenticated SSH stdin and were never written to local receipts.

Guarded rollback restores MailWizz before removing the corresponding Kumo bridge and added subscriptions. It refuses changed source/configuration/scope, modified hook credentials or URLs, active rules, or existing policy effects/receipts. Keep dispatch records, effects and all native protections. Do not rerun the application command as a rollback or remove enforcement after policies become active.

## Tenant45 Comcast rule activation and final read-back

At **23:23:10.304 UTC on October 2**, the coordinator saved tenant45's existing **Comcast Not our Customer** rule through the normal administrator page as enabled revision **2**. Rule `rpr-bec4339a-3805-4ef6-a92e-50924e811b3b` remains tenant-scoped, provider `comcast`, final `bounce` events only, with **all** conditions required: SMTP code equals `550` and case-insensitive literal response text contains `not our customer`. Its action is permanent suppression, with scope `future_and_queued`. The stored `active_since` is `1790983390304`; this establishes the subsequent-event cutoff and does not backdate the rule. The duration field retained by the shared form does not expire a permanent effect.

An independent two-host read-only check at **23:24:05 UTC** confirmed that exact enabled revision, the deployed engine readiness flag, and the still-verified bridge `rpb-58702af2-dde8-44e7-ac86-07bccd0ae772`. MailWizz remains version **2.7.3**, with extension source and registry **1.2.0**, normal class/DSWH registration, and verified scheduler compatibility. Customer **1** and servers **433, 434, 436, 442, 444** still match the accepted binding. The original callback URL, signing secret, payload format and bounce/complaint subscriptions remain unchanged. No additional capability probe was sent by this final check.

The mistakenly selected tenant44 rule `rpr-eab11768-d812-4ad9-9938-da6b396f1310` remains disabled revision **1**, with no bridge. At read-back, tenant45/rule effects, tenant44 effects, historical applies for this rule, and queued policy webhook jobs were all **zero**. MailWizz policy dispatch, receipt and effect counts were also **zero**. These results establish configuration and activation, not a live recipient suppression or successful delivery of a real policy callback. Customer-wide MailWizz effect scope remains as documented above.

The coordinator also reported a successful historical preview after its query correction: **1,000 records scanned, zero matches, scan incomplete**. The preview was never applied. This partial sample does not establish that all retained history has no matches. The separate 47-record ordinary-bounce repair remains prepared and unexecuted.

Private evidence: `outputs/kumo-memory-20260914/mailwizz-tenant45-binding-v3-20261002/recipient-policy-tenant45-activation-readback-20261002-232405.json`, SHA-256 **`741e35a3a335fbed4da95316a3b7cd66056e6646d2ddbdd7d4749c0854c3fd8d`**. The reviewed read-only wrapper SHA-256 is `69e86a28c8fbb58cb7bfba447117fc3008c66c91ea4e9170d23404640e68b26b`. It used existing authenticated SSH, pinned the installed MailWizz verifier, preserved all identity/configuration checks, and observed actual policy counts instead of assuming the install-time zero-count condition. It performed no remote configuration writes, HTTP requests, test sends, or campaign changes and returned no recipient addresses or credentials.

With the rule active, do not use the zero-effects bridge installation rollback automatically. Disabling this rule stops subsequent matches; releasing committed effects is a separate audited action. Preserve the bridge, scheduler, queued-mail enforcement and policy records while any effects or outstanding callbacks depend on them. This activation changed no demo code or accounts; the existing/new isolated demo acceptance reported above remains the applicable application evidence.
