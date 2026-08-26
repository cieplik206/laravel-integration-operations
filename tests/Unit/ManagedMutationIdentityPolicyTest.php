<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Exceptions\LocalReferenceRequired;
use Cieplik206\IntegrationOperations\Exceptions\ManagedMutationIdentityRejected;
use Cieplik206\IntegrationOperations\Runtime\ManagedMutationIdentityPolicy;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeReadDefinitionProvider;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeSingleEffectDefinitionProvider;
use Cieplik206\IntegrationOperations\ValueObjects\IntentIdentity;
use Cieplik206\IntegrationOperations\ValueObjects\LocalReference;

it('requires a stable local reference only for managed mutations', function (): void {
    $singleEffectDefinitions = iterator_to_array(FakeSingleEffectDefinitionProvider::definitions(), false);
    $readOnlyDefinitions = iterator_to_array(FakeReadDefinitionProvider::definitions(), false);
    $singleEffect = $singleEffectDefinitions[0] ?? throw new LogicException('Missing single-effect definition fixture.');
    $readOnly = $readOnlyDefinitions[0] ?? throw new LogicException('Missing read-only definition fixture.');
    $policy = new ManagedMutationIdentityPolicy;

    expect(fn () => $policy->assertSatisfiedBy(
        $singleEffect,
        new IntentIdentity('fixture_resource', 'default'),
    ))->toThrow(LocalReferenceRequired::class, 'Managed mutation operations require a local reference.');

    expect(fn () => $policy->assertSatisfiedBy(
        $singleEffect,
        new IntentIdentity(
            'foreign_resource',
            'default',
            new LocalReference('fixture_resource', 'resource:42'),
        ),
    ))->toThrow(ManagedMutationIdentityRejected::class)
        ->and(fn () => $policy->assertSatisfiedBy(
            $singleEffect,
            new IntentIdentity(
                'fixture_resource',
                'default',
                new LocalReference('foreign_resource', 'resource:42'),
            ),
        ))->toThrow(ManagedMutationIdentityRejected::class)
        ->and(fn () => $policy->assertSatisfiedBy(
            $singleEffect,
            new IntentIdentity(
                'fixture_resource',
                'undeclared',
                new LocalReference('fixture_resource', 'resource:42'),
            ),
        ))->toThrow(ManagedMutationIdentityRejected::class);

    $policy->assertSatisfiedBy(
        $singleEffect,
        new IntentIdentity(
            'fixture_resource',
            'default',
            new LocalReference('fixture_resource', 'resource:42'),
        ),
    );
    $policy->assertSatisfiedBy(
        $singleEffect,
        new IntentIdentity(
            'fixture_resource',
            'secondary',
            new LocalReference('fixture_resource', 'resource:42'),
        ),
    );
    $policy->assertSatisfiedBy(
        $readOnly,
        new IntentIdentity('catalog_record', 'fetch'),
    );
});
