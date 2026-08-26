# Changelog

All notable changes to this project will be documented in this file.

## 0.3.4 - 2026-08-26

- Add the authoritative `applied_in_progress` reconciliation outcome for a
  single remote write whose effect is confirmed but whose provider workflow is
  still non-terminal.
- Atomically move that outcome from reconciliation back to durable observation
  polling without reopening the write boundary or consuming a second send.

## 0.3.3 - 2026-08-26

- Carry a bounded immutable provider observation on polling and authoritative
  reconciliation outcomes so provider projectors can persist raw non-terminal
  states atomically with the kernel lifecycle transition.
- Keep every existing outcome factory call backwards compatible by making the
  observation an optional final argument.

## 0.3.2 - 2026-08-26

- Add a trusted provider observation projector that applies validated polling
  and reconciliation projection plans inside the kernel transaction.
- Keep empty observation plans backwards compatible while requiring an exact
  projector binding for every declared observation target.

## 0.3.1 - 2026-08-26

- Allow a successful single-effect handler to release its execution lease and
  continue through the authoritative durable polling lane without persisting a
  premature terminal result.
- Preserve the original polling deadline, switch the durable poll purpose from
  preflight to observation, and prove the write-to-poll transition against the
  PostgreSQL runtime.

## 0.3.0 - 2026-08-26

- Add the authoritative Provider SPI v2 registry with typed activation,
  transport, polling, reconciliation, projection, result-envelope,
  compensation, and terminal-proof contracts.
- Add poll-first operation lanes, durable poll state and leases, bounded
  dispatch cursors, relation persistence, compensation acceptance, and scoped
  authoritative queries.
- Execute authoritative failure classification and reconciliation policies,
  including terminal provider rejection as `failed + applied + available`
  without blind write retries.
- Add append-only authoritative runtime persistence, safe rollback guards,
  result-availability and terminal-proof evidence, and redacted lifecycle
  telemetry through PSR-3.
- Harden the release workflow by requiring signed commits and annotated signed
  tags from the committed release signer allowlist.

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
