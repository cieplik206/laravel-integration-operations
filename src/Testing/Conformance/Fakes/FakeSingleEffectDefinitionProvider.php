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
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ProviderKey;

final class FakeSingleEffectDefinitionProvider implements OperationDefinitionProvider
{
    public static function provider(): ProviderKey
    {
        return new ProviderKey('fixture_dispatch');
    }

    public static function definitions(): iterable
    {
        yield OperationDefinition::singleEffect(
            provider: self::provider(),
            operationType: new OperationType('fixture_dispatch.message.deliver'),
            versions: new OperationDefinitionVersions(1, 1, 1),
            handler: new ServiceReference(FakeProviderExtensions::class, OperationHandler::class),
            failureClassifier: new ServiceReference(FakeProviderExtensions::class, FailureClassifier::class),
            retryPolicy: new ServiceReference(FakeProviderExtensions::class, RetryPolicy::class),
            reconciliationStrategy: new ServiceReference(FakeProviderExtensions::class, ReconciliationStrategy::class),
            resultCodec: new ServiceReference(FakeProviderExtensions::class, OperationResultCodec::class),
            outcomeProjector: new ServiceReference(FakeProviderExtensions::class, OutcomeProjector::class),
        );
    }
}
