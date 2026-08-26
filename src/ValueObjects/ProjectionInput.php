<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;

/** @api */
final readonly class ProjectionInput
{
    public function __construct(
        public OperationView $operation,
        public OperationResult $result,
        public TerminalOutcomePair $outcome,
    ) {
        OperationResultInvariant::assertImmutable($result);
    }
}
