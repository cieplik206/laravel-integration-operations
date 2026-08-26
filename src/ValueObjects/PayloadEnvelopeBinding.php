<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use InvalidArgumentException;

/** @api */
final readonly class PayloadEnvelopeBinding
{
    public function __construct(
        public string $kind,
        public OperationId $operationId,
        public int $revision,
        public int $schemaVersion,
    ) {
        if (! in_array($kind, ['payload', 'context', 'result', 'local_reference'], true)) {
            throw new InvalidArgumentException('Payload envelope kind is invalid.');
        }

        if ($revision < 1 || $schemaVersion < 1) {
            throw new InvalidArgumentException('Payload envelope revision and schema version must be positive.');
        }
    }
}
