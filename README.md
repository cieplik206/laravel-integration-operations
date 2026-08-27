# Laravel Integration Operations

Provider-neutral Laravel foundation for durable integration operations and
versioned provider SDKs.

Version 0.3 provides the durable RT-1/RT-2 boundary:

- immutable, versioned receipts, snapshots, scopes, contexts, failures, and
  encoded results;
- canonical JSON, SHA-256 content hashes, domain-separated HMAC lookup digests,
  a versioned secret key ring, a UTC clock, and canonical ULIDs;
- framework-neutral handler, retry, failure-classification, polling,
  reconciliation, projection, compensation, and result-codec contracts;
- a trusted boot-only operation definition registry that freezes after Laravel
  boots without constructing provider services or performing database, cache,
  credential, or HTTP I/O;
- an authoritative boot-frozen Provider SPI v2 registry, poll-first and
  immediate-execute operation lanes, terminal proof contracts, and a public
  provider conformance kit;
- PostgreSQL persistence for encrypted payloads and results, idempotent intent
  acceptance, single-effect boundaries, leases, recovery, scoped queries,
  dispatch cursors, relations, and compensation acceptance;
- Redis-backed scope limiters and redacted PSR-3 lifecycle telemetry.

## Contracts

- [Provider SPI 0.1](docs/provider-spi-0.1.md) defines the provider/kernel
  boundary, single-effect rule, reconciliation vocabulary, and terminal
  contracts.
- [Kernel Schema Specification 0.1](docs/schema-0.1.md) freezes the future
  persistence blueprint without shipping migrations or running DDL.
- [Operations runbook](docs/operations-runbook.md) defines scoped diagnosis,
  manual review, incident response, and N/N-1 rolling deployment.
- [Provider SPI JSON Schema](contracts/provider-spi-0.1.schema.json) validates
  provider-neutral conformance manifests for both a read-only operation and a
  single-effect operation.

The JSON artifacts are conformance specifications, not executable runtime
configuration. Never hydrate service references or class names from database
rows, queue payloads, requests, or other persisted data.

## Scope boundary

The kernel owns provider-neutral technical lifecycle only. It persists and
executes registered operations, but provider SDKs still own transport details,
failure semantics, reconciliation algorithms, codecs, and projection plans.
Consuming applications own cross-provider business workflows.

The kernel does not depend on Fakturownia, Allegro, Saloon, or application
domain models. Provider semantics belong to provider SDKs, while cross-provider
business workflows remain in the consuming application.

## Requirements

- PHP 8.4 or newer;
- Laravel 13;
- PHP PDO and PDO PostgreSQL extensions;
- PostgreSQL, with PostgreSQL 17 used by the blocking release gate.

## Installation

    composer require cieplik206/laravel-integration-operations:^0.3

Laravel discovers the package service provider automatically. Provider SDKs
must register their operation definitions and exact final singleton extension
classes during application boot; the registry rejects later registration or a
changed container binding.

## Development setup

    composer install
    composer check

## Durable runtime

Publish the package migrations and configure an independent payload-encryption
key before accepting operations. Provider packages register immutable operation
definitions; consuming applications receive receipts and scoped snapshots
without importing lease or recovery internals.

## License

The package is released under the MIT License in [LICENSE.md](LICENSE.md).
