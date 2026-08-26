<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use InvalidArgumentException;

/** @api */
final readonly class ResultEnvelopeDescriptor
{
    public const int HardMaximumPlaintextBytes = EncodedResult::HardMaximumCanonicalBytes;

    public const int HardMaximumCiphertextBytes = 524_288;

    public function __construct(
        public ServiceReference $resultCodec,
        public string $resultType,
        public int $schemaVersion,
        public int $maximumPlaintextBytes,
        public int $maximumCiphertextBytes,
    ) {
        if (! $resultCodec->targets(OperationResultCodec::class)
            || ! EncodedResult::isValidResultType($resultType)
            || strlen($resultType) > 191
            || $schemaVersion < 1
            || $schemaVersion > 65_535
            || $maximumPlaintextBytes < 1
            || $maximumPlaintextBytes > self::HardMaximumPlaintextBytes
            || $maximumCiphertextBytes < self::minimumAesGcmCiphertextBytes($maximumPlaintextBytes)
            || $maximumCiphertextBytes > self::HardMaximumCiphertextBytes) {
            throw new InvalidArgumentException('Result envelope descriptor is invalid.');
        }
    }

    public static function minimumAesGcmCiphertextBytes(int $plaintextBytes): int
    {
        if ($plaintextBytes < 0 || $plaintextBytes > self::HardMaximumPlaintextBytes) {
            throw new InvalidArgumentException('Result plaintext byte count is invalid.');
        }

        $encodedCiphertextBytes = 4 * intdiv($plaintextBytes + 2, 3);
        $emptyEnvelopeBytes = strlen('{"iv":"","value":"","mac":"","tag":""}');
        $jsonBytes = $emptyEnvelopeBytes
            + 16
            + $encodedCiphertextBytes
            + 24;

        return 4 * intdiv($jsonBytes + 2, 3);
    }
}
