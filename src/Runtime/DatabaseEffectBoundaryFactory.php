<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\WriterFenceResolver;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\ContainerBindingInspector;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;

/** @internal */
final readonly class DatabaseEffectBoundaryFactory
{
    public function __construct(
        private KernelDatabase $database,
        private DefinitionRegistry $definitions,
        private ContainerBindingInspector $bindings,
        private DatabaseWriterFenceAuthority $writerFenceAuthority,
        private WriterFenceResolver $configuredWriterFences,
        private HmacSha256 $hmac,
        private OperationStateMachine $stateMachine,
        private DatabaseTransitionRecorder $transitions,
        private LeaseTimingPolicy $timing,
    ) {}

    public function make(LeaseClaimHandle $lease): DatabaseEffectBoundary
    {
        return new DatabaseEffectBoundary(
            $lease,
            $this->database,
            $this->definitions,
            $this->bindings,
            $this->writerFenceAuthority,
            $this->configuredWriterFences,
            $this->hmac,
            $this->stateMachine,
            $this->transitions,
            $this->timing,
        );
    }
}
