# Changelog

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
