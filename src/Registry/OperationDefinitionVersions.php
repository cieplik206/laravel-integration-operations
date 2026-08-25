<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use InvalidArgumentException;

/** @api */
final readonly class OperationDefinitionVersions
{
    public function __construct(
        public int $payloadSchema,
        public int $handler,
        public int $resultSchema,
    ) {
        if ($payloadSchema < 1 || $handler < 1 || $resultSchema < 1) {
            throw new InvalidArgumentException('Operation definition versions must be positive.');
        }
    }

    /** @return array{payload_schema: int, handler: int, result_schema: int} */
    public function toArray(): array
    {
        return [
            'payload_schema' => $this->payloadSchema,
            'handler' => $this->handler,
            'result_schema' => $this->resultSchema,
        ];
    }
}
