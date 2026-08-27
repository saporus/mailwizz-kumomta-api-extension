# Security

## Reporting a vulnerability

Please do not publish tenant API keys, live endpoint credentials, or vulnerability details in a public issue.

Report security concerns privately through the contact options at https://www.omniknoweth.com/.

## Deployment guidance

- Keep SSL verification enabled in production.
- Store tenant API keys only in the MailWizz delivery-server configuration.
- Restrict access to the MailWizz backend and Enterprise KumoMTA UI.
- Rotate a tenant API key immediately if it is exposed.
- Back up MailWizz before installation and revalidate the webhook after MailWizz upgrades.
