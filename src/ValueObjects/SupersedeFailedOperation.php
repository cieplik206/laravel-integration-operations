<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

/** @api */
final readonly class SupersedeFailedOperation
{
    public function __construct(
        public OperationId $expectedCurrentOperationId,
        public AcceptOperation $correctedOperation,
        public OperationActor $actor = new OperationActor('application'),
    ) {}
}
