# Changelog

## 1.1.5 - 2026-09-19

- Include the production-tested shared Retry-After cooldown, isolated by API endpoint and tenant key, without sleeping campaign workers.
- Retry explicit recovery concurrency refusals after 2-4 seconds when the API requests a short delay; retain longer existing pauses and the 60-second minimum for memory pressure, exhausted budgets, and unknown refusals.
- Keep campaign recipients retryable with exception code 99; handle connection exceptions and display temporary-delay details in interactive forms instead of a generic Error 500.
- Preserve campaign/subscriber idempotency and permanent-error handling. Include the cooldown helper in the installable package.
- Reconcile the stale installed version label with the packaged source. No admission thresholds, campaign state, provider rates or holds are changed.


- Explain successful Magic SMTP submissions in the campaign delivery report with an accessible tooltip: sending-server acceptance is separate from final recipient delivery. Status values, exports, and retry behavior are unchanged; no MailWizz core patch is required.

## 1.1.4 - 2026-09-02

- Propagate temporary API transport failures, explicit retryable responses, and HTTP 408/425/429/5xx responses using MailWizz's safe-retry exception code instead of recording immediate giveups.
- Keep non-retryable API rejections on the existing permanent-failure path.
- Document and include the narrow MailWizz 2.7.3 console-command bridge required to preserve the retry signal.

## 1.1.3 - 2026-08-28

- Add a deterministic, tenant-scoped idempotency key for each campaign/subscriber submission.
- Send the opaque key in both the `Idempotency-Key` header and API body fallback.
- Forward the MailWizz campaign UID to the Enterprise API for message correlation and tracking.
- Add runtime coverage for stable keys, identity separation, fallbacks, identifier privacy, and the final API request contract.

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
