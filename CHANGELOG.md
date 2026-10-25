# Changelog

## Unreleased — 5.x

### Compatibility

- Support Laravel/Illuminate 10–13 with framework-specific PHP minimums: 8.1 for Laravel 10, 8.2 for 11/12, and 8.3 for 13.
- Use matching Testbench 8–11 and PHPUnit 10/11 test environments. Legacy framework advisory findings remain visible; compatibility does not extend upstream security support.

### Added

- Explicit context hashing, bounded/database consistency policies, central model/query policies and sanitized query inspection.
- Token-owned fill locks with bounded waits and ownership-checked publication; optional synchronous refresh before expiry.
- `CacheEvent` monitoring, consumer testing helpers, and doctor/install/outbox commands.
- Optional table-dependency generations with explicit physical-table write maps. Unknown writes and outer commits invalidate globally. Dormant table metadata expires safely through random token recreation.
- Durable outbox transaction recording, optional migration, bounded replay/retry and malformed-record handling. Application participation and scheduling are explicit.
- Scalar IN queries and allowlisted top-level relationship row caching, with inherited context and capped TTL. Pivot/join and nested eager reads bypass caching.
- Verified TLS and named ACL authentication for Predis and phpredis.
- Real-Redis regression tests, executable test/lint scripts, a framework/PHP CI matrix and a reproducible benchmark harness.

### Changed

- Caching requires explicit enablement. Zero TTL bypasses; negative/overflow TTLs are rejected. Previously published configuration may retain `enabled=true` and must be reviewed.
- SHA-256 query identity includes model, connection/database context, scoped/projected SQL, typed bindings and context hash. TTL and generation distinguish stored entries. Previous query keys become unreachable.
- Raw-attribute JSON v2 replaces presentation-array/compressed payloads. `max_payload_bytes` defaults to 1 MiB; compression, `hash_algo` and relation-depth settings no longer affect storage. Legacy/corrupt payloads are misses.
- Namespace generations replace granular row/index invalidation by default. Legacy invalidators and row/model flush selectors remain global. Old entries expire by TTL; a flush is logical invalidation, not physical deletion.
- Transactions, locks, complex/raw/volatile queries, replicas and base-query result callbacks bypass caching. Aggregate and cursor calls remain database paths.
- Redis failures use client-lifetime fallback with sanitized one-time warnings. Strict mode continues throwing after failure. Database/programming errors propagate independently.
- Remove reflective invocation of arbitrary model methods during deletion; preserve application-defined macros.

### Upgrading from 4.x

Choose a new unique prefix and coordinate worker deployment. Review published configuration and the application's acceptable staleness, external-writer invalidation and Redis isolation policies before enabling caching. Use a fresh prefix when changing invalidation modes. Restart long-running workers after configuration changes or Redis recovery, and flush before re-enabling a previously disabled namespace.

Plan separate cleanup of old permanent keys/indexes: generation invalidation does not scan them or securely erase stored data. Declared legacy index getters remain available but no longer drive query invalidation.

Database writes and Redis invalidation are not atomic. Outbox provides eventual invalidation only for explicitly participating transactions and requires successful replay. Keep correctness-critical reads on the database path when stale windows are unacceptable.
