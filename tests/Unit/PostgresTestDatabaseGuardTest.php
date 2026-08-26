<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Tests\Support\PostgresTestDatabaseGuard;

it('allows destructive migration only for explicitly opted-in dedicated test databases', function (string $database): void {
    expect(function () use ($database): void {
        PostgresTestDatabaseGuard::assertFreshIsAllowed($database, '1');
    })
        ->not->toThrow(RuntimeException::class);
})->with([
    'named test database' => 'integration_operations_rt2_test',
    'testing database' => 'integration_operations_testing',
]);

it('rejects unsafe database names or missing destructive opt-in', function (string $database, ?string $allowFresh): void {
    expect(function () use ($database, $allowFresh): void {
        PostgresTestDatabaseGuard::assertFreshIsAllowed($database, $allowFresh);
    })
        ->toThrow(RuntimeException::class);
})->with([
    'production-looking database' => ['newerpms', '1'],
    'empty database' => ['', '1'],
    'default database' => ['postgres', '1'],
    'test name without package prefix' => ['some_test', '1'],
    'missing opt-in' => ['integration_operations_rt2_test', null],
    'false opt-in' => ['integration_operations_rt2_test', '0'],
]);

it('rejects a connection to a database other than the guarded configuration', function (): void {
    expect(function (): void {
        PostgresTestDatabaseGuard::assertConnectedDatabase(
            'integration_operations_rt2_test',
            'newerpms',
        );
    })->toThrow(RuntimeException::class);
});
