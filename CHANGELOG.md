# Changelog

All notable changes to this package. Versions follow [Semantic Versioning](https://semver.org).

## 1.2.0 — 2026-10-29

### Added
- `$quolle->domains`: `list`, `create`, `get`, `records`, `verify`, `remove`. Add a sending domain, get its DNS records, check DNS on demand, and see verification and warm-up state.
- `$quolle->apiKeys`: `list`, `create`, `remove`. Create keys (full access or sending-only, optionally limited to some domains, optionally expiring) and revoke them.
- `$quolle->webhooks` manages endpoints as well as verifying deliveries: `list`, `create`, `get`, `update`, `remove`, `test`, `deliveries`, `redeliver`, `rotateSecret`, `events`.

These need a full-access API key; a sending-only key gets `restricted_api_key`. Requires Quolle API support for API-key access to `/v1/keys` and `/v1/webhooks` (live from 29 Oct 2026).

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
