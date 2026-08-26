# Laravel Integration Operations

Provider-neutral Laravel foundation for durable integration operations and
versioned provider SDKs.

Version 0.1 provides the RT-1 boundary:

- immutable, versioned receipts, snapshots, scopes, contexts, failures, and
  encoded results;
- canonical JSON, SHA-256 content hashes, domain-separated HMAC lookup digests,
  a versioned secret key ring, a UTC clock, and canonical ULIDs;
- framework-neutral handler, retry, failure-classification, reconciliation,
  projection, and result-codec contracts;
- a trusted boot-only operation definition registry that freezes after Laravel
  boots without constructing provider services or performing database, cache,
  credential, or HTTP I/O;
- a public provider conformance kit, strict Provider SPI 0.1 schema, and
  read-only and single-effect reference definitions.

## Contracts

- [Provider SPI 0.1](docs/provider-spi-0.1.md) defines the provider/kernel
  boundary, single-effect rule, reconciliation vocabulary, and terminal
  contracts.
- [Kernel Schema Specification 0.1](docs/schema-0.1.md) freezes the future
  persistence blueprint without shipping migrations or running DDL.
- [Provider SPI JSON Schema](contracts/provider-spi-0.1.schema.json) validates
  provider-neutral conformance manifests for both a read-only operation and a
  single-effect operation.

The JSON artifacts are conformance specifications, not executable runtime
configuration. Never hydrate service references or class names from database
rows, queue payloads, requests, or other persisted data.

## Scope boundary

Version 0.1 intentionally does not ship the RT-2 runtime: there is no operation
persistence, migration, state-machine executor, queue worker, lease manager,
retry scheduler, or runtime reconciliation orchestration yet. The schema
specification describes that future boundary but does not activate it.

The kernel does not depend on Fakturownia, Allegro, Saloon, or application
domain models. Provider semantics belong to provider SDKs, while cross-provider
business workflows remain in the consuming application.

## Requirements

- PHP 8.4 or newer;
- Laravel 13;
- PHP PDO and PDO PostgreSQL extensions;
- PostgreSQL, with PostgreSQL 17 used by the blocking release gate.

## Installation

    composer require cieplik206/laravel-integration-operations:^0.1

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
