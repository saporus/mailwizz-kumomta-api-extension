# Runtime checks

Run each standalone fixture with PHP 8.1 or newer:

```sh
php tests/source_contract_test.php
php tests/hook_runtime_test.php
php tests/idempotency_runtime_test.php
php tests/send_runtime_test.php
php tests/delivery_report_clarity_test.php
php tests/cooldown_runtime_test.php
php tests/transient_send_runtime_test.php
```

The delivery-report fixture verifies successful Magic SMTP tooltip rendering, preservation of failed and non-Magic server outcomes, keyboard accessibility attributes, escaping, route scope, and no row mutations. It does not send email or connect to a database.

The cooldown and transient-send fixtures verify shared-worker pacing, tenant isolation, header/date/body delays, memory versus concurrency backpressure, no HTTP during cooldown, code 99, interactive error logging, stable idempotency and success after expiry. All HTTP clients are synthetic stubs.
