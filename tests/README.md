# Runtime checks

Recipient-policy source 1.2.0 adds `policy_bridge_runtime_test.php`, `policy_callback_runtime_test.php`, `policy_correlation_runtime_test.php` and `policy_disabled_runtime_test.php`. The disabled fixture proves that absent bindings do not access the database, change selectors/reports/completion or record dispatch. Store/correlation fixtures require PDO SQLite. On the local Windows runtime use `php -d extension=php_pdo_sqlite.dll tests/policy_bridge_runtime_test.php` (likewise for correlation). The separately invoked `policy_scheduler_runtime_test.php PRIVATE_CANDIDATE_DIRECTORY` executes actual privately prepared MailWizz scheduler files. See [policy installation and acceptance](../docs/recipient-policy-bridge-1.2.0.md). Never publish the licensed candidate source used by that fixture.

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

The cooldown and transient-send fixtures verify shared-worker pacing, tenant isolation, header/date/body delays, memory versus concurrency backpressure, no HTTP during cooldown, code 99, interactive error logging, stable idempotency and success after expiry. All HTTP clients are synthetic stubs.
