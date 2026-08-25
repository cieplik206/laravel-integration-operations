# Laravel Integration Operations

Installable boilerplate for a shared Laravel kernel that will own the technical
lifecycle of durable provider operations.

This repository currently contains package metadata, Laravel auto-discovery,
quality tooling, and a no-op service provider only. It does not implement an
operation state machine, persistence, queues, retries, reconciliation, or any
provider-specific behavior yet.

## Planned boundary

The kernel will provide provider-agnostic integration mechanics such as:

- durable operation receipts and status queries;
- idempotent intent registration;
- leases, retries, recovery, and reconciliation lifecycle;
- operation attempts and transition audit;
- provider handler registration and technical telemetry.

The package must never depend on Fakturownia, Allegro, or application domain
models. Provider semantics belong to provider SDKs, while cross-provider
business workflows remain in the consuming application.

## Requirements

- PHP 8.4 or newer;
- Laravel 13.

Additional PHP, Laravel, and database versions will be declared only after they
are covered by CI.

## Development setup

    composer install
    composer validate --strict --no-check-publish
    composer test
    composer analyse
    vendor/bin/pint --test src tests
    composer audit

## Development installation

Until the first tagged release, Composer exposes the default branch as
`dev-main`:

    composer require cieplik206/laravel-integration-operations:dev-main

Production deployment should eventually use tagged releases and a committed
`composer.lock`, not a moving development branch.

## License

The package is released under the MIT License in LICENSE.md.
