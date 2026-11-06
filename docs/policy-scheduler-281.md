# MailWizz 2.8.1 scheduler qualification profile

The `mailwizz-2.8.1-policy-20261106` profile is a separate inspected port. It does not broaden the existing 2.7.3 readiness rule or accept arbitrary 2.8.1 scheduler files. The legacy preparer, applier and runtime fixture remain unchanged.

The new preparer accepts only the two exact inspected 2.8.1 inputs, preserving the installation's existing code-99 temporary retry bridge:

| File | Input SHA-256 | Qualified output SHA-256 |
| --- | --- | --- |
| `apps/console/commands/SendCampaignsCommand.php` | `0d8c8fa8ffde8707213f0eeb0086263a8717b635d41c306e6b52aae2c13ecf90` | `96091aba524927bc6b0dd1b5f7ac241243a4a69180695ab546a1d5122c7353a2` |
| `apps/common/components/db/behaviors/CampaignQueueTableBehavior.php` | `2977899adbdab782ac27e8984111653c28206de2282e40ddd4b155e1d5965a6a` | `5fea83a34a648f6971b99aa4994e888214381aa05fcb73c6ce8d27175e59ae8f` |

The runtime requires version 2.8.1, that exact profile identifier, accepted manifest state, both exact output hashes and the normal scheduler markers. Hash-map ordering has no significance. Additional files, missing paths, altered sources, a different version/profile, pending acceptance or custom subscriber-selector hooks fail readiness.

## Sending behavior

Native count and selection retain the same policy composition: temporary holds remain pending, permanent effects are excluded, selection filters before LIMIT/OFFSET, and held queue rows prevent premature campaign completion. Native list/segment restrictions, autoresponder due dates and ordinary suppression checks remain in place.

The 2.8.1 sending loop checks policy after selection and again after message rendering and the native before-send hook. The second check occurs **before** send-group cache claims, mutex acquisition and optimistic quota logging. A new temporary hold, unreadable policy state or changed readiness throws native retry code 99. It creates no delivery/success/giveup record, spends no quota and leaves queue state intact. A permanent effect uses the native suppressed outcome. Binding and customer scope remain explicit; unbound customers do not acquire an additional policy-store check. Native quota limits and accounting methods are not changed.

This closes the local rendering interval before quota. It is not cancellation or recall after transport acceptance. The normal Kumo admission and suppression checks remain authoritative downstream. The 2.7.3 worker behavior is unchanged.

## Preparation and evidence

```sh
php patches/prepare-policy-scheduler-281.php PRIVATE_NATIVE_ROOT NEW_PRIVATE_CANDIDATE
php tests/policy_scheduler_281_runtime_test.php NEW_PRIVATE_CANDIDATE
php patches/apply-policy-scheduler-281.php dry-run PRIVATE_NATIVE_ROOT NEW_PRIVATE_CANDIDATE NEW_PRIVATE_BACKUP
```

The fixture executes the complete candidate native sending-loop method and actual count/find/completion/queue methods against isolated SQLite and synthetic resource adapters. It does not bootstrap a production application or perform network delivery. Its 56 assertions cover regular/segment composition, queue/autoresponder due dates, pagination, hold expiry/release, existing native restrictions, late temporary/permanent effects, database failures, changed readiness, quota/queue preservation, a successful synthetic retry and exact-profile rejection. Late-effect cases set a nonzero send-group ID to demonstrate that the added checks precede group claims.

Local qualification and dry-run passed. Actual target PHP execution, normal-login existing/new demo workflows and the installation rollout must be recorded separately by the release coordinator; local fixture success alone is not live activation evidence. The real native sources, candidate copies and private acceptance receipts are never published in this repository.

The [6 November rollout](2026-11-06-pairing-281-rollout.md) records the subsequent passing target PHP 8.3.36 qualification, normal-login existing/new demo acceptance and verified administrator activation. Those observed results are separate from this local fixture.

## Applying and rollback

After exact-source qualification and the release's deployment preflight, the dedicated applier supports `apply` and `rollback` in place of `dry-run`. The manifest must include the current fixture hash and exact qualified output hashes. The helper preserves and verifies original UID, GID and permissions for publication and failure restoration. It refuses rollback when the protected pairing settings file exists or application configuration mentions pairing/policy authority; an equivalent safeguard and explicit safe-handover review are required first. Never use the legacy 2.7.3 applier for this profile.

Pairing enrollment remains a separate administrator action. A successful source update does not automatically authorize any delivery server, create a tenant binding, enable suppression rules or send messages. Disabling enrollment prevents new connections while preserving existing authority and completed-operation reconciliation. Before rolling back scheduler files or runtime, verify that no active connection depends on them and retain suppression/audit evidence. Do not restore subscriber databases or remove native protections.
