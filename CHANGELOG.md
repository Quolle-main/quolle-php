# Changelog

All notable changes to this package. Versions follow [Semantic Versioning](https://semver.org).

## 1.1.0 — 2026-10-29

### Added
- `QuolleException::$errorCode`: the API's machine-readable error code.
- `type: marketing` documented.

### Changed
- Quota limits are no longer retried. `daily_quota_exceeded` and `warmup_limit_exceeded` (and any 429 whose `Retry-After` is over 60 seconds) are thrown at once with their code, instead of the client waiting, possibly for hours, before failing. Ordinary rate limits are still retried.
- **Requires PHP 8.1+** (was 7.4). 7.4 and 8.0 no longer get security fixes; Composer keeps projects on older PHP at 1.0.x.
- A 2xx response that isn't JSON now throws instead of returning `[]`.

### Fixed
- Request bodies with invalid UTF-8 used to be sent as `false`; they now throw (`JSON_THROW_ON_ERROR`).
- Empty `variables` / `metadata` are sent as `{}` instead of `[]`, which the API rejected.

## 1.0.0

First release, with attachments.
