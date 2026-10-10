# Runtime checks

Recipient-policy source 1.2.0 adds `policy_bridge_runtime_test.php`, `policy_callback_runtime_test.php`, `policy_correlation_runtime_test.php` and `policy_disabled_runtime_test.php`. The disabled fixture proves that absent bindings do not access the database, change selectors/reports/completion or record dispatch. Store/correlation fixtures require PDO SQLite. On the local Windows runtime use `php -d extension=php_pdo_sqlite.dll tests/policy_bridge_runtime_test.php` (likewise for correlation). The separately invoked `policy_scheduler_runtime_test.php PRIVATE_CANDIDATE_DIRECTORY` executes actual privately prepared MailWizz scheduler files. See [policy installation and acceptance](../docs/recipient-policy-bridge-1.2.0.md). Never publish the licensed candidate source used by that fixture.

`policy_interactive_test_runtime_test.php` exercises native campaign/template preview context through the real extension send method, with synthetic HTTP and isolated SQLite. It covers authenticated POST routes, native delivery purpose/object ownership, customer-scoped bindings, scheduler readiness and source drift, partial campaign context, CLI retry semantics, storage failure, unchanged campaign proof/idempotency, and gateway policy refusal. Run it with PDO SQLite as above. Its temporary scheduler marker files are synthetic; it neither loads licensed native application sources nor sends email. This change does not exempt server-validation routes, ordinary web sends or campaign workers. Kumo's normal admission and demo execution restrictions remain in force; see the [native preview correction and acceptance record](../docs/2026-10-10-native-preview-policy-dispatch.md).

Run each standalone fixture with PHP 8.1 or newer:

```sh
php tests/source_contract_test.php
php tests/hook_runtime_test.php
php tests/idempotency_runtime_test.php
php tests/send_runtime_test.php
php tests/delivery_report_clarity_test.php
php tests/cooldown_runtime_test.php
php tests/transient_send_runtime_test.php
php tests/short_retry_runtime_test.php
php tests/short_retry_guard_runtime_test.php
```

The short-retry fixtures cover a maximum of three extra attempts, exact MIME/key/recipient/endpoint reuse, a 12-second retry-start budget, shared cooldown provenance, changed pause/suppression/quota checks, and no inline replay after ambiguous HTTP outcomes. To check the real private MailWizz 2.7.3 catch blocks, prepare a candidate with `patches/prepare-short-retry.php` and run `php tests/short_retry_bridge_runtime_test.php PRIVATE_CANDIDATE_DIRECTORY`. Also rerun the policy scheduler fixture using the candidate's updated command hash and unchanged queue behavior. See [the scoped release record](../docs/2026-10-09-short-admission-retry.md).

The delivery-report fixture verifies successful Magic SMTP tooltip rendering, preservation of failed and non-Magic server outcomes, keyboard accessibility attributes, escaping, route scope, and no row mutations. It does not send email or connect to a database.

The minute-quota change adds `minute_quota_runtime_test.php`, `minute_quota_guard_budget_test.php`, `minute_quota_native_runtime_test.php PRIVATE_DELIVERY_SERVER_SOURCE` and `minute_quota_concurrency_test.py PRIVATE_DELIVERY_SERVER_SOURCE PRIVATE_SEND_CAMPAIGNS_SOURCE`. The latter two require hash-pinned private licensed sources, never committed. Two concurrency scenarios each use eight real PHP processes with an isolated SQLite ledger and no network calls. `minute_quota_deploy_test.py` covers fifteen isolated publication/recovery scenarios; Windows can use `--fixture-parent C:\Users\kload\Documents\Codex` to keep fixture paths short. See [release evidence and remaining acceptance gaps](../docs/2026-10-09-minute-quota-boundary-candidate.md). Synthetic demo labels are fixture namespaces, not normal tenant-login acceptance.

The cooldown and transient-send fixtures verify shared-worker pacing, tenant isolation, header/date/body delays, memory versus concurrency backpressure, no HTTP during cooldown, code 99, interactive error logging, stable idempotency and success after expiry. All HTTP clients are synthetic stubs.
