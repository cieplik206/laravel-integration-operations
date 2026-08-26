<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionVersions;
use InvalidArgumentException;

/** @api */
final readonly class AcceptOperation
{
    public function __construct(
        public IntegrationScope $scope,
        public OperationType $operationType,
        public OperationDefinitionVersions $versions,
        public IntentIdentity $intent,
        public CanonicalObject $payload,
        public IntegrationContext $context,
        public int $priority = 0,
    ) {
        if (! $operationType->belongsTo($scope->provider)) {
            throw new InvalidArgumentException('Accepted operation type does not belong to its provider scope.');
        }

        if (strlen($operationType->value) > 191) {
            throw new InvalidArgumentException('Accepted operation type exceeds its storage limit.');
        }

        if ($priority < -32768 || $priority > 32767) {
            throw new InvalidArgumentException('Operation priority must fit a signed small integer.');
        }
    }
}
