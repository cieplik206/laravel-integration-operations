<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Queries;

use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\AuthoritativeDefinitionRegistry;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScopeSet;
use Illuminate\Container\Container;

/** @internal */
final readonly class DatabaseAuthoritativeScopedOperationQueryFactory
{
    public function __construct(
        private KernelDatabase $database,
        private BoundPayloadEnvelopeCodec $envelopes,
        private IntegrationContextConstraints $contextConstraints,
        private AuthoritativeDefinitionRegistry $definitions,
        private Container $container,
    ) {}

    public function make(IntegrationScopeSet $allowedScopes): DatabaseAuthoritativeScopedOperationQuery
    {
        return new DatabaseAuthoritativeScopedOperationQuery(
            $allowedScopes,
            $this->database,
            $this->envelopes,
            $this->contextConstraints,
            $this->definitions,
            $this->container,
        );
    }
}
