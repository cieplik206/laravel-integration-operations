# Changelog

All notable changes to this project will be documented in this file.

## 0.2.0 - 2026-08-26

- Add the durable PostgreSQL operation runtime with encrypted payloads,
  idempotent acceptance, leases, heartbeats, recovery, reconciliation, manual
  resolution, scoped queries, and operational commands.
- Add the single-effect boundary, managed mutation identity checks, and the
  authoritative writer-fence cutover guard.
- Add Redis-backed, scope-aware rate limiting and circuit breaking.
- Require PDO PostgreSQL explicitly and commit the dependency lock used by the
  compatibility gate.

## 0.1.0 - 2026-08-25

- Add the installable Laravel 13 RT-1 foundation with auto-discovery and
  boot-without-I/O verification against unreachable PostgreSQL and Redis.
- Freeze Provider SPI 0.1, terminal contracts, the future kernel persistence
  schema blueprint, and provider-neutral read-only and single-effect fixtures.
- Add immutable versioned operation receipts, snapshots, scopes, contexts,
  safe failures, result envelopes, retry instructions, and reconciliation
  outcomes.
- Add canonical JSON, SHA-256 hashing, domain-separated versioned HMAC lookup,
  redacted key-ring storage, a UTC clock port, and canonical ULID generation.
- Add framework-neutral operation extension contracts and a public conformance
  kit with semantic manifest, binding, codec, and result validation.
- Add a one-way trusted registry for exact final singleton extensions, with
  fail-closed binding metadata checks and kernel-owned runtime construction.

RT-2 persistence, migrations, the operation state-machine executor, queues,
leases, scheduled retries, and runtime reconciliation orchestration are not part
of this release.
