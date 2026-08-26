<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\TerminalContract;
use Cieplik206\IntegrationOperations\Testing\Conformance\ConformanceReport;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\ProviderConformanceFailed;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;

function immutableArrayFixtureDefinition(): OperationDefinition
{
    foreach (FakeSingleEffectDefinitionProvider::definitions() as $definition) {
        return $definition;
    }

    throw new RuntimeException('Single-effect fixture definition is missing.');
}

/**
 * @param  class-string  $class
 * @param  list<mixed>  $arguments
 */
function constructHostileImmutableArrayHolder(string $class, array $arguments): object
{
    return (new ReflectionClass($class))->newInstanceArgs($arguments);
}

/**
 * @param  array<mixed>  $values
 */
function constructImmutableArrayHolder(string $holder, array $values): object
{
    $definition = immutableArrayFixtureDefinition();

    return match ($holder) {
        'encoded result' => new EncodedResult('fixture.immutable_result', 1, ['nested' => $values]),
        'canonical object' => new CanonicalObject(['nested' => $values]),
        'integration context' => IntegrationContext::make(attributes: $values),
        'context constraints' => constructHostileImmutableArrayHolder(IntegrationContextConstraints::class, [
            24,
            4096,
            64,
            512,
            255,
            $values,
        ]),
        'terminal effect states' => constructHostileImmutableArrayHolder(TerminalContract::class, [
            OperationStatus::Succeeded,
            OperationDisposition::Succeeded,
            $values,
            [ResultAvailability::Available],
        ]),
        'terminal result availabilities' => constructHostileImmutableArrayHolder(TerminalContract::class, [
            OperationStatus::Succeeded,
            OperationDisposition::Succeeded,
            [EffectState::Applied],
            $values,
        ]),
        'definition retry evidence' => constructHostileImmutableArrayHolder(OperationDefinition::class, [
            $definition->provider,
            $definition->operationType,
            $definition->versions,
            $definition->maximumRemoteWrites,
            $definition->managedMutationIdentity,
            $definition->boundaryMode,
            $definition->retryMode,
            $values,
            $definition->ambiguousEffectAction,
            $definition->reconciliationResults,
            $definition->succeeded,
            $definition->failed,
            $definition->cancelled,
            $definition->handler,
            $definition->failureClassifier,
            $definition->retryPolicy,
            $definition->reconciliationStrategy,
            $definition->resultCodec,
            $definition->outcomeProjector,
        ]),
        'definition reconciliation results' => constructHostileImmutableArrayHolder(OperationDefinition::class, [
            $definition->provider,
            $definition->operationType,
            $definition->versions,
            $definition->maximumRemoteWrites,
            $definition->managedMutationIdentity,
            $definition->boundaryMode,
            $definition->retryMode,
            $definition->safeRetryEvidence,
            $definition->ambiguousEffectAction,
            $values,
            $definition->succeeded,
            $definition->failed,
            $definition->cancelled,
            $definition->handler,
            $definition->failureClassifier,
            $definition->retryPolicy,
            $definition->reconciliationStrategy,
            $definition->resultCodec,
            $definition->outcomeProjector,
        ]),
        'managed mutation semantic slots' => constructHostileImmutableArrayHolder(ManagedMutationIdentityContract::class, [
            'fixture_resource',
            'fixture_resource',
            $values,
        ]),
        'conformance report' => constructHostileImmutableArrayHolder(ConformanceReport::class, [$values]),
        'conformance failure' => constructHostileImmutableArrayHolder(ProviderConformanceFailed::class, [$values]),
        default => throw new InvalidArgumentException("Unknown immutable array holder '{$holder}'."),
    };
}

function immutableArrayHolderUsesMap(string $holder): bool
{
    return in_array($holder, ['integration context'], true);
}

function immutableArrayHolderSample(string $holder): mixed
{
    return match ($holder) {
        'terminal effect states' => EffectState::Applied,
        'terminal result availabilities' => ResultAvailability::Available,
        'definition reconciliation results' => ReconciliationResult::FoundExact,
        'managed mutation semantic slots' => 'default',
        'context constraints' => 'token',
        'definition retry evidence' => 'request_not_started',
        'conformance report', 'conformance failure' => 'fixture violation',
        default => 'before',
    };
}

/** @return list<string> */
function immutableArrayHolderNames(): array
{
    return [
        'encoded result',
        'canonical object',
        'integration context',
        'context constraints',
        'terminal effect states',
        'terminal result availabilities',
        'definition retry evidence',
        'definition reconciliation results',
        'managed mutation semantic slots',
        'conformance report',
        'conformance failure',
    ];
}

it('rejects external references at every immutable array holder boundary', function (string $holder): void {
    $external = immutableArrayHolderSample($holder);

    if (immutableArrayHolderUsesMap($holder)) {
        $values = ['value' => &$external];
    } else {
        $values = [&$external];
    }

    expect(fn (): object => constructImmutableArrayHolder($holder, $values))
        ->toThrow(InvalidArgumentException::class, 'arrays must not contain references');
})->with(immutableArrayHolderNames());

it('rejects self-referential arrays at every immutable array holder boundary', function (string $holder): void {
    $values = [];

    if (immutableArrayHolderUsesMap($holder)) {
        $values['self'] = &$values;
    } else {
        $values[] = &$values;
    }

    expect(fn (): object => constructImmutableArrayHolder($holder, $values))
        ->toThrow(InvalidArgumentException::class, 'arrays must not contain references');
})->with(immutableArrayHolderNames());

it('bounds depth at every immutable array holder boundary', function (string $holder): void {
    $deep = immutableArrayHolderSample($holder);

    for ($depth = 0; $depth < 66; $depth++) {
        $deep = [$deep];
    }

    $values = immutableArrayHolderUsesMap($holder) ? ['value' => $deep] : [$deep];

    expect(fn (): object => constructImmutableArrayHolder($holder, $values))
        ->toThrow(InvalidArgumentException::class, 'validation bounds');
})->with(immutableArrayHolderNames());

it('bounds node counts at every immutable array holder boundary', function (string $holder): void {
    $oversized = array_fill(0, 10_001, immutableArrayHolderSample($holder));
    $values = immutableArrayHolderUsesMap($holder) ? ['value' => $oversized] : $oversized;

    expect(fn (): object => constructImmutableArrayHolder($holder, $values))
        ->toThrow(InvalidArgumentException::class, 'validation bounds');
})->with(immutableArrayHolderNames());

it('rejects control characters in immutable diagnostic and policy string lists', function (): void {
    expect(fn (): ConformanceReport => new ConformanceReport(["forged\nviolation"]))
        ->toThrow(InvalidArgumentException::class, 'printable single-line')
        ->and(fn (): IntegrationContextConstraints => new IntegrationContextConstraints(
            reservedKeyFragments: ["token\rforged"],
        ))->toThrow(InvalidArgumentException::class, 'printable single-line');
});
