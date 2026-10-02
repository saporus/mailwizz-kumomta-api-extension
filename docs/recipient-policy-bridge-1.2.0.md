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
