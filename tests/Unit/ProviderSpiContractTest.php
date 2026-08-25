<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\IntegrationOperationsServiceProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\ProviderManifestConformanceValidator;
use Cieplik206\IntegrationOperations\Tests\Support\JsonSchemaSubsetValidator;

/** @return array<string, mixed> */
function readProviderSpiJson(string $path): array
{
    $contents = file_get_contents($path);

    expect($contents)->not->toBeFalse();

    $decoded = json_decode((string) $contents, true, flags: JSON_THROW_ON_ERROR);

    expect($decoded)->toBeArray();

    /** @var array<string, mixed> $decoded */
    return $decoded;
}

/**
 * @param  array<string, mixed>  $manifest
 * @return array<string, mixed>
 */
function firstProviderSpiOperation(array $manifest): array
{
    $operations = $manifest['operations'] ?? null;

    expect($operations)->toBeArray()->toHaveCount(1);

    $operation = $operations[0] ?? null;

    expect($operation)->toBeArray();

    /** @var array<string, mixed> $operation */
    return $operation;
}

dataset('provider SPI conformance fixtures', [
    'read-only catalog lookup' => [
        __DIR__.'/../Fixtures/Conformance/fixture-catalog-record-fetch.json',
        'fixture_catalog',
        'fixture_catalog.record.fetch',
        0,
    ],
    'single-effect message delivery' => [
        __DIR__.'/../Fixtures/Conformance/fixture-dispatch-message-deliver.json',
        'fixture_dispatch',
        'fixture_dispatch.message.deliver',
        1,
    ],
]);

it('publishes a strict versioned provider SPI schema', function (): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');

    expect($schema)
        ->toHaveKey('$schema', 'https://json-schema.org/draft/2020-12/schema')
        ->toHaveKey('$id', 'https://github.com/cieplik206/laravel-integration-operations/contracts/provider-spi-0.1.schema.json')
        ->toHaveKey('additionalProperties', false)
        ->toHaveKey('$defs.terminalContracts.required', ['succeeded', 'failed', 'cancelled'])
        ->toHaveKey('$defs.succeededTerminal.properties.terminal.const', true)
        ->toHaveKey('$defs.failedTerminal.properties.terminal.const', true)
        ->toHaveKey('$defs.cancelledTerminal.properties.terminal.const', true);
});

it('validates each provider-neutral operation against the JSON schema', function (
    string $fixturePath,
    string $expectedProvider,
    string $expectedOperationType,
    int $expectedRemoteWrites,
): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');
    $manifest = readProviderSpiJson($fixturePath);
    $errors = (new JsonSchemaSubsetValidator)->validate($manifest, $schema);
    $operation = firstProviderSpiOperation($manifest);

    expect($errors)->toBe([])
        ->and($manifest)->toHaveKey('contract', 'cieplik206.integration-operations.provider-spi')
        ->toHaveKey('spi_version', '0.1')
        ->toHaveKey('provider', $expectedProvider)
        ->and($operation)->toHaveKey('operation_type', $expectedOperationType)
        ->toHaveKey('effect.max_remote_writes', $expectedRemoteWrites);

    expect($expectedOperationType)->toStartWith("{$expectedProvider}.");
})->with('provider SPI conformance fixtures');

it('enforces the read-only and single-effect boundaries', function (): void {
    $readOperation = firstProviderSpiOperation(readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-catalog-record-fetch.json',
    ));
    $writeOperation = firstProviderSpiOperation(readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-dispatch-message-deliver.json',
    ));

    expect($readOperation)
        ->toHaveKey('effect', ['max_remote_writes' => 0, 'boundary' => 'forbidden'])
        ->toHaveKey('retry.mode', 'read_safe')
        ->toHaveKey('retry.ambiguous_effect_action', 'not_applicable')
        ->toHaveKey('reconciliation', ['mode' => 'none', 'results' => []])
        ->and($readOperation['extension_points'])
        ->not->toContain('reconciliation_strategy');

    expect($writeOperation)
        ->toHaveKey('effect', ['max_remote_writes' => 1, 'boundary' => 'required'])
        ->toHaveKey('retry.mode', 'effect_aware')
        ->toHaveKey('retry.ambiguous_effect_action', 'reconcile')
        ->toHaveKey('retry.safe_retry_evidence', ['request_not_started'])
        ->toHaveKey('reconciliation.mode', 'required')
        ->toHaveKey('reconciliation.results', [
            'found_exact',
            'absent_conclusive',
            'inconclusive',
            'ambiguous_matches',
        ])
        ->and($writeOperation['extension_points'])
        ->toContain('reconciliation_strategy');
});

it('keeps terminal outcomes immutable and excludes ambiguous effects', function (
    string $fixturePath,
    string $expectedProvider,
    string $expectedOperationType,
    int $expectedRemoteWrites,
): void {
    $operation = firstProviderSpiOperation(readProviderSpiJson($fixturePath));
    $terminalContracts = $operation['terminal_contracts'] ?? null;

    expect($terminalContracts)->toBeArray()->toHaveKeys(['succeeded', 'failed', 'cancelled']);

    /** @var array<string, array<string, mixed>> $terminalContracts */
    foreach ($terminalContracts as $status => $contract) {
        expect($contract)
            ->toHaveKey('status', $status)
            ->toHaveKey('disposition', $status)
            ->toHaveKey('terminal', true)
            ->and($contract['effect_states'])
            ->not->toContain('possibly_applied');
    }

    $expectedSuccessEffect = $expectedRemoteWrites === 0 ? 'not_started' : 'applied';

    expect($terminalContracts['succeeded'])
        ->toHaveKey('effect_states', [$expectedSuccessEffect])
        ->toHaveKey('result_availability', ['available'])
        ->and($terminalContracts['failed'])
        ->toHaveKey('result_availability', ['not_applicable'])
        ->and($terminalContracts['cancelled'])
        ->toHaveKey('effect_states', ['not_started'])
        ->toHaveKey('result_availability', ['not_applicable']);
})->with('provider SPI conformance fixtures');

it('rejects contract drift through the schema validator', function (): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-dispatch-message-deliver.json',
    );
    $manifest['spi_version'] = '0.2';
    $manifest['credential'] = 'must-not-be-accepted';
    $operation = firstProviderSpiOperation($manifest);
    $operation['effect'] = ['max_remote_writes' => 1, 'boundary' => 'forbidden'];
    $manifest['operations'] = [$operation];

    $errors = (new JsonSchemaSubsetValidator)->validate($manifest, $schema);

    expect($errors)
        ->toContain('$: unexpected property credential')
        ->toContain('$.spi_version: value does not match const')
        ->toContain('$.operations[0]: expected exactly one matching schema branch');
});

it('rejects removal of every required single-effect extension point', function (string $extensionPoint): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-dispatch-message-deliver.json',
    );
    $operation = firstProviderSpiOperation($manifest);
    $operation['extension_points'] = array_values(array_filter(
        $operation['extension_points'],
        fn (mixed $candidate): bool => $candidate !== $extensionPoint,
    ));
    $manifest['operations'] = [$operation];

    expect((new JsonSchemaSubsetValidator)->validate($manifest, $schema))
        ->toContain('$.operations[0]: expected exactly one matching schema branch');
})->with([
    'operation_handler',
    'failure_classifier',
    'retry_policy',
    'reconciliation_strategy',
    'result_codec',
    'outcome_projector',
]);

it('requires succeeded operations to expose exactly one available result', function (): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-dispatch-message-deliver.json',
    );
    $operation = firstProviderSpiOperation($manifest);
    $operation['terminal_contracts']['succeeded']['result_availability'] = ['available', 'not_applicable'];
    $manifest['operations'] = [$operation];

    expect((new JsonSchemaSubsetValidator)->validate($manifest, $schema))
        ->not->toBe([]);

    $operation['terminal_contracts']['succeeded']['result_availability'] = ['not_applicable'];
    $manifest['operations'] = [$operation];

    expect((new JsonSchemaSubsetValidator)->validate($manifest, $schema))
        ->not->toBe([]);
});

it('forbids not-applied as a terminal read-only failure effect', function (): void {
    $schema = readProviderSpiJson(dirname(__DIR__, 2).'/contracts/provider-spi-0.1.schema.json');
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-catalog-record-fetch.json',
    );
    $operation = firstProviderSpiOperation($manifest);
    $operation['terminal_contracts']['failed']['effect_states'] = ['not_applied'];
    $manifest['operations'] = [$operation];

    expect((new JsonSchemaSubsetValidator)->validate($manifest, $schema))
        ->not->toBe([]);
});

it('semantically rejects a manifest operation without its provider prefix', function (): void {
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-catalog-record-fetch.json',
    );
    $operation = firstProviderSpiOperation($manifest);
    $operation['operation_type'] = 'different_provider.record.fetch';
    $manifest['operations'] = [$operation];

    expect((new ProviderManifestConformanceValidator)->violations($manifest))
        ->toContain("operations[0] operation_type does not have provider prefix 'fixture_catalog.'");
});

it('semantically rejects duplicate provider operation handler tuples with differing definitions', function (): void {
    $manifest = readProviderSpiJson(
        __DIR__.'/../Fixtures/Conformance/fixture-catalog-record-fetch.json',
    );
    $first = firstProviderSpiOperation($manifest);
    $second = $first;
    $second['versions']['result_schema'] = 2;
    $manifest['operations'] = [$first, $second];

    expect((new ProviderManifestConformanceValidator)->violations($manifest))
        ->toContain('operations[1] duplicates tuple fixture_catalog|fixture_catalog.record.fetch|1');
});

it('keeps conformance artifacts free of executable classes and secrets', function (
    string $fixturePath,
): void {
    $contents = file_get_contents($fixturePath);

    expect($contents)->not->toBeFalse();

    $lowercaseContents = strtolower((string) $contents);

    expect($lowercaseContents)
        ->not->toContain('fakturownia')
        ->not->toContain('newerpms')
        ->not->toContain('app\\')
        ->not->toContain('api_key')
        ->not->toContain('credential')
        ->not->toContain('password')
        ->not->toContain('secret')
        ->not->toContain('token');
})->with('provider SPI conformance fixtures');

it('keeps the provider source free of direct external side-effect calls', function (): void {
    $reflection = new ReflectionClass(IntegrationOperationsServiceProvider::class);
    $providerPath = $reflection->getFileName();

    expect($providerPath)->not->toBeFalse();

    $providerSource = file_get_contents((string) $providerPath);

    expect($providerSource)->not->toBeFalse()
        ->and((string) $providerSource)
        ->not->toContain('DB::')
        ->not->toContain('Http::')
        ->not->toContain('Artisan::call')
        ->not->toContain('dispatch(');
});
