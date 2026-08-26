<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class FakeAuthoritativeLegacyDefinitionProvider implements OperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return FakeAuthoritativeDefinitionProvider::provider();
    }

    public static function definitions(): iterable
    {
        $extensions = FakeAuthoritativeLegacyProviderExtensions::class;

        yield OperationDefinition::readOnly(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.read'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, FailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, RetryPolicy::class),
            resultCodec: new ServiceReference($extensions, OperationResultCodec::class),
            outcomeProjector: new ServiceReference($extensions, OutcomeProjector::class),
        );

        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.ensure'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'fixture_resource',
                localReferenceType: 'fixture_resource',
                semanticSlots: ['poll'],
            ),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, FailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference($extensions, ReconciliationStrategy::class),
            resultCodec: new ServiceReference($extensions, OperationResultCodec::class),
            outcomeProjector: new ServiceReference($extensions, OutcomeProjector::class),
        );

        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType('fixture_authoritative.resource.reverse'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            managedMutationIdentity: new ManagedMutationIdentityContract(
                resourceType: 'fixture_resource',
                localReferenceType: 'fixture_resource',
                semanticSlots: ['reverse'],
            ),
            handler: new ServiceReference($extensions, OperationHandler::class),
            failureClassifier: new ServiceReference($extensions, FailureClassifier::class),
            retryPolicy: new ServiceReference($extensions, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference($extensions, ReconciliationStrategy::class),
            resultCodec: new ServiceReference($extensions, OperationResultCodec::class),
            outcomeProjector: new ServiceReference($extensions, OutcomeProjector::class),
        );
    }
}
