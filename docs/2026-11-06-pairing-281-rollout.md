# MailWizz 2.8.1 connection pairing — 6 November 2026

The owner authorized qualification and activation of connection pairing on the existing OmniOneSend installation while preserving sending limits and suppression. Extension 1.3.1 adds a separately inspected, source-pinned 2.8.1 scheduler profile; it does not bypass readiness by accepting an arbitrary version number. The existing 2.7.3 installation is outside this deployment and remains unchanged.

## Qualification and deployment

The [2.8.1 scheduler profile](policy-scheduler-281.md) covers native regular/segmented campaigns, autoresponders and queue processing. Checks after selection and rendering preserve native blacklist/unsubscribe/suppression handling. Late temporary holds and unavailable checks raise retry code 99 before send-group claims or quota debit. Permanent effects retain native suppressed outcomes. Existing downstream Kumo admission and suppression are unchanged.

Independent review passed. The target PHP 8.3.36 ran all twelve relevant fixture suites successfully, including 56 assertions against actual candidate native sending-loop/selector/completion methods with synthetic resource adapters. Pairing service/security checks cover 268 assertions, HTTPS/SSRF 118, normal runtime/controller/demo adapters 42, binding refresh 7 and the real Node/PHP protocol 142 assertions across six synthetic scenarios. No live recipient or email was used.

Seven target POSIX checks additionally verified the public deployment helper in a disposable private fixture: apply retained original UID/GID/mode, configured-authority rollback was refused without changes, and safe rollback restored exact original bytes and metadata. Live application sources remained unchanged during this tool test.

The frozen extension-only 1.3.1 archive has nineteen files and SHA-256 `7ec7be5cc99757afff1de49e56527e54d80ffe61e48a9083a0b7065f35443465`. Its runtime SHA-256 is `25a2d27812319fa7ab8b85e47f6027ad7b5727548fb0d186fc5361f32838b4f4`. Licensed native candidates and private credentials are excluded from the public package/repository.

Source publication completed at 17:05:10 UTC. Preflight required no active sending campaigns/workers, no existing connection authority and unchanged protected configuration. The runtime dependency was installed before its two native callers, followed by an atomic extension-tree exchange and the supported native Update lifecycle to 1.3.1. Source backups, the exact prior tree, original cron and a publication receipt were retained. A fresh installation-specific encryption key and the accepted scheduler profile were published atomically outside the public root with private permissions. Enrollment remained disabled for browser acceptance.

All 358 existing delivery-server configurations, credentials, quotas and ownership hashes, native campaign settings/assignments/status counts, cron entries and daily-news settings remained unchanged. Only the two expressly qualified native scheduler files changed among the protected application sources. No service restart, sending, campaign activation, recipient replay, quota reset or suppression-rule change occurred.

## Normal demo acceptance and activation

Two receipt-owned QA accounts were created with separate customer groups, zero sending quota, no system-server use, no real delivery servers and a maximum two-hour expiry. One edited synthetic workspace existed before publication; the other was created afterward. Existing expired News Drafts QA accounts and all client-shared demos were untouched.

The acceptance runner uses separate ephemeral headless Chrome contexts and ordinary customer sign-in, navigation, forms and CSS. It does not touch the user's Chrome profile or customer session. Requests are restricted to the installation origin; synthetic pairing cannot issue a real grant or contact an external service. The initial browser pass stopped on the native welcome tour before any feature POST. The runner was adjusted to dismiss that tour through its normal Close for now control; the original failure is retained.

Both fixtures passed six positive normal-page checks, nine negative/isolation checks and two expiry checks each. These cover the native guided tour, fresh login, sidebar/forms, synthetic pairing, save/reload, preservation of an existing connected sample, missing/invalid CSRF, customer attempts at administrator actions, foreign-customer input, independent workspace readback, guest/backend access denial and expired session/form rejection. Native readback confirmed durable separate labels and revisions, zero real servers/grants/connections, and enforced zero sending. Both owned accounts were then expired while preserving their saved state.

The existing administrator Chrome tab was used to click **Enable customer connections** once. The normal page reported that connections were enabled and existing bindings preserved. Independent durable verification at **17:14:19 UTC / 18:14:19 Lagos** confirmed source/database version 1.3.1, enrollment enabled, exact scheduler readiness, all nineteen package hashes and both native profile hashes. There were zero real pairing grants, managed connections, operations and policy effects; the audit contained eight synthetic demo actions and the administrator enable action. All protected delivery, campaign, cron and News Drafts configuration still matched.

The scoped compatibility and enrollment task is complete. No particular customer has been paired or granted server authority by this task. Actual customer pairing and real email delivery remain separate actions.

## Recovery and boundaries

Private operational receipts live under the installation's root-only `magic-smtp-pairing-20261106` directory and the local private task workspace. Never rerun a consumed publication or lifecycle operator after an uncertain result: inspect its phase and current durable state first.

Disabling enrollment stops new setup while retaining encrypted connection secrets, active bindings, suppression evidence and reconciliation history. Do not remove the key, loader or scheduler enforcement once a connection depends on it. Native rollback helpers refuse configured authority and preserve file ownership; any handover must supply equivalent suppression protection. Never restore an old subscriber database or delete copied contacts/drafts as rollback.

Enabling installation enrollment does not automatically pair a customer, authorize delivery servers or enable suppression rules. A customer still needs native server-management permission, an owned enabled Magic SMTP Web API server with verified TLS, an identity proof and explicit server selection in the normal MagicSMTP tenant panel. Non-Magic API server types are not converted. The current inventory bound remains one hundred servers per customer. This qualification does not prove real recipient delivery or recall after transport acceptance.
