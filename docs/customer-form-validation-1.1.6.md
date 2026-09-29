# Customer form validation correction

## Cause and fix
The Web API form omits mailbox bounce-server selection because it uses DSWH webhooks. The customer controller nevertheless enforced its mandatory bounce-server policy because the custom transport did not advertise this capability. Version 1.1.6 overrides `getBounceServerNotSupported(): bool` only for the API model. Other transports and customer policies remain unchanged. Both custom views render the framework's escaped error summary so hidden-field errors are visible.

## Verification
- Eight PHP regression suites passed on PHP 8.3, including new API capability and error-summary assertions.
- Real MailWizz 2.8.1 model validation passed with a synthetic endpoint/key, mandatory-bounce callback, and no database save or outbound send.
- User-authorized real customer create submission succeeded and redirected to the saved update form.
- Extension registration updated to 1.1.6. No campaign state or tenant execution policy was changed.
- No end-to-end delivery or webhook test was performed. SaaS demo integration was not rerun: this correction changes only standalone MailWizz validation/presentation, not demo tenant APIs or resources.

## Deployment and rollback
Back up the full installed extension. Deploy all files from `magicsmtp`, preserve application ownership, run PHP lint, and use MailWizz's extension update lifecycle. After updating, verify in a fresh application request because an already-bootstrapped process may retain the previous class map. No database schema migration is needed. To roll back, restore the complete prior extension and its recorded extension version through the normal management workflow. Saved delivery-server records need not be deleted. Retain the matching MailWizz core retry bridge across deployment; check it again after each core upgrade.
