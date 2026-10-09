# Connect MailWizz native demo acceptance — 2026-10-09

The normal MailWizz customer interface was verified on `servermail2.com` using two newly provisioned, isolated operator-owned QA accounts. One workspace existed before feature activation; the other was created afterward. No client-shared demo account was reset, extended or modified. The wider 2027 commercial programme remains paused.

## Fixtures and execution boundaries

| Fixture | Customer / group | Initial workspace | Final retained workspace |
| --- | --- | --- | --- |
| Existing at activation | 14 / 13 | Edited label, connected stage, revision 3 | Edited through the normal form, connected stage, revision 5 |
| New after activation | 15 / 14 | Fresh sample, new stage, revision 0 | Independently edited through the normal form, connected stage, revision 1 |

The receipt-bound `tests/connect_live_demo.php` helper created unique customer/group identities without native email notification hooks. Each account had a two-hour maximum native/demo expiry, sending quota zero, system delivery servers disabled and maximum owned delivery/bounce/feedback/monitor servers zero. The native quota resolver confirmed sending was disabled. Read-back found zero real delivery servers, pairing grants and external connections for each account.

Passwords were generated privately and used only in the normal customer sign-in form, with Remember me unchecked. Credentials were retained only in protected temporary receipts, never in this document or browser screenshots. No list, campaign, subscriber, real sending key, real webhook, payment or sending transaction was created.

## Browser verification

External Chrome used the real customer login, dashboard/sidebar and `/customer/magic_smtp_connect/index` page with the application's standard layout, styles and fonts.

1. The existing workspace retained its pre-activation edited label and connected sample server after deployment and extension update.
2. Create pairing code displayed the documented synthetic `DEMO-MAILWIZZ-PAIR` value without creating a real pairing grant.
3. Saving `Existing demo saved after deployment` through the normal form succeeded. Normal GET navigation and reload retained the label and connected sample.
4. The new customer signed in separately, opened the same sidebar page, generated the synthetic code and saved `New demo connection saved and reloaded`. GET navigation and reload retained the independent value.
5. Returning to the existing customer preserved its separate workspace. The two accounts did not overwrite each other's sample or edits.
6. Expiring only the receipt-bound new QA account caused a normal page reload to return native Error 403, `Customer access is inactive or expired.` Its stored synthetic data remained intact.
7. A targeted regression check after the demo runtime correction used **Save sample connection → Create pairing code → normal GET → reload** for the existing customer. The server stayed **Included in existing binding** and the edited label remained unchanged. Read-back confirmed connected stage, revision 5 and no real grants/connections.
8. Only the receipt-bound existing QA account was then expired. Its normal page reload also returned native Error 403. Both temporary QA accounts remain expired; neither was deleted or reset.

## Defect found and corrected

Generating a new synthetic pairing code initially changed an already-connected demo to `code_ready`, displaying its server as available again. The demo runtime now preserves connected state when issuing another synthetic code. The correction was deployed at 17:07:32 UTC, then verified through the ordinary page workflow above. This defect affected synthetic display state; it did not change a live bridge.

Target validation accompanying the correction reported 42 native-adapter and 268 core checks passing. Browser acceptance independently verified the resulting visible behavior and persistent workspace state; it is not a substitute for those tests.

## Evidence and cleanup

Local evidence directory: `outputs/mailwizz-selfservice-20261009` under the Magic Developer workspace.

- `mailwizz-existing-demo.png`: existing workspace after normal save/reload.
- `mailwizz-new-demo.png`: new workspace after its independent save/reload.
- `mailwizz-existing-demo-code-preserved.png`: connected state retained after synthetic code generation and reload.
- `mailwizz-expired-demo.png` and `mailwizz-existing-demo-expired.png`: native expiry denial for each fixture.
- Sanitized `demo-create-*`, `demo-verify-*` and `demo-expire-*` JSON receipts record the exact identities, quotas, synthetic state and zero real resources.

Read-only process inventory found no remaining Node process running the owned `serve-recipient-policy-demo-qa` harness. No unrelated local preview or service was stopped. Administrator and live Kumo browser sessions were preserved.

The account helper is explicitly scoped to its receipt-owned QA identities. Cleanup shortens expiry while preserving audit evidence and synthetic edits. Feature rollback must not restore an old database over newer data or delete these records; use the release's code/settings rollback procedure.

These checks establish native UI/demo persistence, migration compatibility and execution boundaries. They do not establish live message delivery, provider acceptance or readiness of the paused 2027 programme. Real customer connection and bridge acceptance are documented separately in the deployment record.
