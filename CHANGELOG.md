# Changelog

## 1.1.2 - 2026-08-28

- Register webhook processing through MailWizz's `dswh_process_map` filter.
- Use MailWizz's standard `/dswh/{delivery-server-id}` callback URL.
- Remove all runtime modification of MailWizz's `DswhController.php`.
- Render webhook responses through `controller()->renderJson()`.
- Document the callback migration required when upgrading from 1.1.1.

## 1.1.1 - 2026-08-27

- Publish the extension source for transparent review.
- Set the verified MailWizz compatibility floor to 2.7.3.
- Document PHP 8.1 web and CLI/cron verification.
- State the Omni Knoweth Enterprise SaaS dependency explicitly.
- Remove the unnecessary bundled copy of MailWizz's `DswhController.php`.
- Preserve the existing webhook compatibility method without redistributing MailWizz core source.

## 1.1.0

- Add direct KumoMTA Web API message submission.
- Add tenant API-key authentication.
- Add optional egress-pool metadata.
- Add KumoMTA webhook processing for bounce and complaint events.
