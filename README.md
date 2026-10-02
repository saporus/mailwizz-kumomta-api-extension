# MailWizz to KumoMTA API Extension

This MailWizz extension submits campaign messages directly to the Omni Knoweth Enterprise KumoMTA API. It removes the SMTP submission hop between MailWizz and KumoMTA while KumoMTA continues to perform final SMTP delivery to recipient mail servers.

The repository is public so MailWizz operators can inspect the extension before installing it.

## Enterprise platform requirement

This is **not** a standalone connector for a standard KumoMTA installation. It requires:

- access to the Omni Knoweth Enterprise SaaS platform for KumoMTA;
- an Enterprise API ingestion endpoint; and
- a tenant API key issued by the platform.

The Enterprise SaaS backend is not included in this repository.

## Benefits

- Direct MailWizz to KumoMTA API submission
- Duplicate-safe campaign/subscriber submission keys for ambiguous HTTP timeouts
- Faster handoff from the campaign application to the delivery engine
- Lower application-server SMTP connection and queue overhead
- Tenant-aware API authentication
- Optional egress-pool routing override
- KumoMTA bounce and complaint webhook processing

This extension does not guarantee inbox placement or eliminate SMTP from final delivery.

## Verified compatibility

- MailWizz 2.7.3
- PHP 8.1 for both PHP-FPM and CLI/cron processing
- Extension version 1.1.5

Other MailWizz or PHP combinations have not yet been included in the verified compatibility matrix.

Additional installation verification (2026-09-29): MailWizz 2.8.1 with PHP 8.3 passed all synthetic extension regression suites, real-application class/type registration, runtime permissions, enabled-extension UI, and API configuration-form checks. This is no-send compatibility verification, not end-to-end delivery validation. Use the matching 2.8.1 retry bridge below.

## Installation

### Source update 1.2.0: signed recipient policy synchronization

Customer delivery-error rules can now synchronize permanent suppression or temporary holds independently of MailWizz's bounce label. This requires an explicitly bound and signed policy webhook plus the verified MailWizz 2.7.3 scheduler bridge. A readiness probe blocks activation until compatibility is proven. Policy release preserves native blacklists and paused campaigns. See [installation, callback contract, acceptance and rollback](docs/recipient-policy-bridge-1.2.0.md) before enabling this feature. The earlier transport compatibility matrix does not extend this new scheduler feature to other MailWizz versions.

### Source update 1.1.6: customer form validation

Version 1.1.6 declares that the Web API connector receives bounce events through its DSWH webhook, so customer accounts requiring mailbox bounce servers can save it without an invisible `bounce_server_id` error. Both custom forms now render an error summary. SMTP transport requirements and customer group policies are unchanged. Deploy the complete `magicsmtp` folder from this source revision and update the extension through MailWizz; older 1.1.5 release archives do not contain this fix.

Customer create submission and subsequent saved update-form rendering were verified on MailWizz 2.8.1 / PHP 8.3, in addition to synthetic regression tests and real-model validation without sending email. Saving a delivery server is not a delivery or webhook test.

No SaaS demo resources, credentials, migration, or production-execution permissions are changed. Synthetic tests do not use real API keys or send email. Existing and new tenant demo workspace integration was not rerun for this standalone MailWizz-only correction; those platform boundaries remain unchanged.

1. Back up your MailWizz files and database.
2. Download `magicsmtp-1.1.5.zip` from the [latest GitHub release](https://github.com/saporus/mailwizz-kumomta-api-extension/releases/latest).
3. In MailWizz, open **Backend > Extend > Extensions**.
4. Upload the release ZIP and enable **Magic SMTP Web API Delivery Server**.
5. Create a delivery server of type **Magic SMTP Web API**.

### Temporary API failure retry bridge

Version 1.1.4 introduced, and 1.1.5 retains, handling that propagates temporary transport failures and HTTP 408/425/429/5xx responses with MailWizz's existing safe-retry exception code. MailWizz 2.7.3 catches delivery-server exceptions inside `SendCampaignsCommand`, so the small reviewed bridge in [`patches/mailwizz-2.7.3-transient-retry.patch`](patches/mailwizz-2.7.3-transient-retry.patch) is also required for temporary API failures to stop the current batch instead of becoming terminal giveups.

Apply the patch only to a matching MailWizz 2.7.3 installation after taking a backup, run PHP lint on the result, and keep the backup for rollback. Recheck this integration after every MailWizz upgrade. Permanent non-retryable rejections continue through MailWizz's normal failure path.

MailWizz 2.8.1 retains the same inner exception catch and also requires this bridge. Use [`patches/mailwizz-2.8.1-transient-retry.patch`](patches/mailwizz-2.8.1-transient-retry.patch) only for the matching official 2.8.1 source. Verify with `patch --dry-run --fuzz=0 -p0`, apply it from the MailWizz root, and run PHP lint. Keep the unpatched file and full pre-upgrade file/database backups. The bridge does not change campaign states, quotas, tenant API credentials, or demo execution permissions. No proprietary MailWizz application files are included in this repository.

6. Enter the Enterprise API endpoint and tenant API key supplied by Omni Knoweth.
7. Keep SSL verification enabled in production.
8. Configure the displayed MailWizz webhook URL in the Enterprise KumoMTA UI.
9. Validate the delivery server with a controlled test before assigning it to campaigns.

### MailWizz webhook integration

The extension registers its processor through MailWizz's supported `dswh_process_map` filter. The generated callback uses MailWizz's standard `/dswh/{delivery-server-id}` endpoint. No MailWizz core file is read, patched, or replaced.

### Upgrading from 1.1.1

Version 1.1.2 does not modify or automatically clean an existing MailWizz core file. If version 1.1.1 previously inserted the legacy `actionMagicsmtp()` method, switch the Enterprise webhook to the new callback URL displayed by the delivery server first. Restore the MailWizz controller only from a trusted backup or matching official MailWizz package after confirming the standard DSWH callback works.

## Retry pacing and upgrade to 1.1.5

This release packages the later retry and delivery-report fixes together. Upgrade the entire `magicsmtp` directory from the release ZIP, including `models/MagicSmtpCooldown.php`; do not copy only the API model. Keep a complete backup of the current extension first, including any local customizations. The extension runtime directory must be writable by both MailWizz web and cron workers under the same application user. Cooldown files contain only an expiry time and are mode 0600.

`Retry-After` seconds or HTTP-date and the JSON retry delay are honored with a 900-second base cap and up to 10 seconds jitter. Memory pressure, minute-budget exhaustion and unknown errors keep a 60-second minimum. Only an explicit retryable HTTP 429 `memory_admission_limited` / `recovery_inflight` response uses a two-second minimum plus up to two seconds jitter. A longer API delay or existing cooldown is preserved. This changes submission retry pacing, not ISP delivery limits or the server's memory guard.

Campaign workers retain exception code 99 and the recipient stays retryable through the required MailWizz bridge. Interactive forms receive a temporary-delay log and an empty send result instead of the campaign exception; no successful send is fabricated. No worker sleeps during cooldown. Corrupt or unavailable cooldown storage prevents submission.

Rollback restores the complete previous extension, including its helper files. Existing cooldown files can remain in the MailWizz runtime directory. This release does not migrate a database, modify campaigns, change provider limits, or apply the MailWizz core retry bridge automatically.

### Demo compatibility

The Enterprise platform's normal tenant-login demo workspaces continue to use isolated synthetic data with production execution disabled server-side. There are no new tenant resources or demo migrations in this extension release. Existing and newly created isolated workspace tests cover save/reload persistence, nested routes, expiry, isolation, and production-route rejection. MailWizz retry tests use fake HTTP clients and synthetic credentials; no test emails are sent. Do not configure the standalone MailWizz extension with real credentials for a prospect demo or treat local retry fixtures as a separate demo UI.

## Configuration fields

- **API URL:** Enterprise email-ingestion endpoint
- **API Username:** unused for tenant-key authentication
- **Tenant API Key:** secret key issued by the Enterprise platform
- **Egress Pool Override:** optional static routing-pool name
- **Disable SSL Verification:** leave set to **No** in production

Never publish or commit a real tenant API key.

## Source verification

Run the source contract checks with:

```bash
php tests/source_contract_test.php
php tests/hook_runtime_test.php
php tests/idempotency_runtime_test.php
php tests/send_runtime_test.php
php tests/delivery_report_clarity_test.php
php tests/cooldown_runtime_test.php
php tests/transient_send_runtime_test.php
php tests/customer_form_test.php
```

The checks confirm that the supported DSWH hook and JSON renderer are used, that callback dispatch preserves the delivery-server ID, that campaign sends receive stable opaque idempotency keys, and that no MailWizz core controller is bundled or modified.

## Links

- [Video demonstration](https://youtu.be/-MAi0nyaYCU)
- [Enterprise KumoMTA UI](https://go.magicsmtp.com/ui/)
- [Omni Knoweth](https://www.omniknoweth.com/)

## Disclosure

This extension is developed and maintained by Omni Knoweth. The public repository contains the MailWizz extension only; access to the required Enterprise SaaS platform is provided separately.

## Source notice

Copyright 2026 Omni Knoweth. The source is published for transparency and use with the Omni Knoweth Enterprise SaaS platform. MailWizz and KumoMTA are trademarks of their respective owners. Omni Knoweth is not affiliated with MailWizz or KumoMTA.
