<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Queries;

use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Illuminate\Container\Container;

/** @internal */
final readonly class DatabaseScopedOperationQueryFactory
{
    public function __construct(
        private KernelDatabase $database,
        private BoundPayloadEnvelopeCodec $envelopes,
        private IntegrationContextConstraints $contextConstraints,
        private DefinitionRegistry $definitions,
        private Container $container,
    ) {}

    public function make(IntegrationScopeSet $allowedScopes): DatabaseScopedOperationQuery
    {
        return new DatabaseScopedOperationQuery(
            $allowedScopes,
            $this->database,
            $this->envelopes,
            $this->contextConstraints,
            $this->definitions,
            $this->container,
        );
    }
}
