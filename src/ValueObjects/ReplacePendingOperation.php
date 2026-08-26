<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use InvalidArgumentException;

/** @api */
final readonly class ReplacePendingOperation
{
    public function __construct(
        public IntegrationScope $scope,
        public OperationId $expectedCurrentOperationId,
        public int $expectedPayloadRevision,
        public CanonicalObject $payload,
        public OperationActor $actor = new OperationActor('application'),
    ) {
        if ($expectedPayloadRevision < 1) {
            throw new InvalidArgumentException('Expected payload revision must be positive.');
        }
    }
}
