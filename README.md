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
- Faster handoff from the campaign application to the delivery engine
- Lower application-server SMTP connection and queue overhead
- Tenant-aware API authentication
- Optional egress-pool routing override
- KumoMTA bounce and complaint webhook processing

This extension does not guarantee inbox placement or eliminate SMTP from final delivery.

## Verified compatibility

- MailWizz 2.7.3
- PHP 8.1 for both PHP-FPM and CLI/cron processing
- Extension version 1.1.1

Other MailWizz or PHP combinations have not yet been included in the verified compatibility matrix.

## Installation

1. Back up your MailWizz files and database.
2. Download `magicsmtp-1.1.1.zip` from the [latest GitHub release](https://github.com/saporus/mailwizz-kumomta-api-extension/releases/latest).
3. In MailWizz, open **Backend > Extend > Extensions**.
4. Upload the release ZIP and enable **Magic SMTP Web API Delivery Server**.
5. Create a delivery server of type **Magic SMTP Web API**.
6. Enter the Enterprise API endpoint and tenant API key supplied by Omni Knoweth.
7. Keep SSL verification enabled in production.
8. Configure the displayed MailWizz webhook URL in the Enterprise KumoMTA UI.
9. Validate the delivery server with a controlled test before assigning it to campaigns.

### MailWizz controller compatibility note

MailWizz 2.7.3 does not expose the legacy `dswh/magicsmtp` webhook action by default. When the extension first runs, it inserts only the extension-owned `actionMagicsmtp()` method into MailWizz's frontend `DswhController.php` if that method is missing.

The repository does not include or redistribute MailWizz's controller source. A MailWizz update may replace the small inserted method; after an update, verify the extension and webhook endpoint again.

## Configuration fields

- **API URL:** Enterprise email-ingestion endpoint
- **API Username:** unused for tenant-key authentication
- **Tenant API Key:** secret key issued by the Enterprise platform
- **Egress Pool Override:** optional static routing-pool name
- **Disable SSL Verification:** leave set to **No** in production

Never publish or commit a real tenant API key.

## Links

- [Video demonstration](https://youtu.be/-MAi0nyaYCU)
- [Enterprise KumoMTA UI](https://go.magicsmtp.com/ui/)
- [Omni Knoweth](https://www.omniknoweth.com/)

## Disclosure

This extension is developed and maintained by Omni Knoweth. The public repository contains the MailWizz extension only; access to the required Enterprise SaaS platform is provided separately.

## Source notice

Copyright 2026 Omni Knoweth. The source is published for transparency and use with the Omni Knoweth Enterprise SaaS platform. MailWizz and KumoMTA are trademarks of their respective owners. Omni Knoweth is not affiliated with MailWizz or KumoMTA.
