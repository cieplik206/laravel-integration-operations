<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Support;

use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

/** @internal */
final class OperationResultInvariant
{
    public static function assertImmutable(OperationResult $result): void
    {
        if (! EncodedResult::isValidResultType($result->resultType())) {
            throw new InvalidArgumentException('Operation result type is invalid.');
        }

        ImmutableValueSanitizer::assertDeeplyImmutable($result, 'Operation result');
    }
}
