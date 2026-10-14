# Changelog

## 1.3.4 - 2026-10-14
- Require three distinct cleanup-certified blocks within 24 hours and five or fewer posts before cleanup-mode enforcement.
- Preserve the existing two-event, ten-post ban-only threshold and separate cleanup evidence from ordinary ban evidence.
- Bind staged and persisted events to the active evidence pool so cleanup-only certificates cannot satisfy ban-only enforcement.
- Verify fresh install, public 1.3.2 upgrade, uninstall, native restoration, receipts, concurrent workers and transaction rollback on phpBB 3.3.19 with PHP 8.3 and MariaDB/InnoDB.

## 1.3.3 (unreleased; included in 1.3.4)

- Add a separate Off-by-default repeat-spammer cleanup setting.
- Use phpBB's reversible native soft-deletion API for up to five approved public replies after a confirmed seven-day ban.
- Exclude topic starters, private messages, inaccessible/password-protected forums, and other members' content; record native reasons and durable per-post receipts.
- Add a native fixture scenario for accepted posts, legitimate-reply preservation, restoration, and retry behavior.

## 1.3.2

- Add Off-by-default native seven-day bans after two server-certified spam blocks within 24 hours.
- Persist deduplicated evidence at request cleanup and perform reversible native bans in a separate cron task.
- Check current permissions, settings and transactional tables; commit native bans and lifetime action receipts together.


## 1.3.1 - 2026-09-18

- Route lifecycle and bootstrap requests through the resilient public API path.

## 1.3.0 - 2026-09-11

- Replace health and endpoint-catalogue routing with deterministic GeoDNS
  fallback and keep regional routing locked unless global fallback is enabled.
- Limit standard-plan heartbeat attempts to hourly while retaining ten-minute
  Pro/MultiMod check-ins.

## 1.2.7 - 2026-09-07

- First release licensed under `GPL-2.0-or-later`, replacing the package's
  former version-2-only grant.
- Add the current complete GPLv2 text and same-licence contribution terms while
  preserving the hosted-service and trademark boundary.
