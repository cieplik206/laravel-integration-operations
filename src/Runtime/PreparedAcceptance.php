<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\AcceptOperation;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use Cieplik206\IntegrationOperations\ValueObjects\WriterFence;

/** @internal */
final readonly class PreparedAcceptance
{
    /**
     * @param  list<VersionedHmacDigest>  $intentDigests
     * @param  list<VersionedHmacDigest>  $localReferenceDigests
     * @param  list<VersionedHmacDigest>  $payloadFingerprintDigests
     * @param  list<VersionedHmacDigest>  $contextDigests
     * @param  list<VersionedHmacDigest>  $correlationDigests
     * @param  list<VersionedHmacDigest>  $cohortDigests
     */
    public function __construct(
        public AcceptOperation $command,
        public OperationDefinition $definition,
        public WriterFence $writerFence,
        public OperationId $candidateIntentId,
        public OperationId $candidateOperationId,
        public array $intentDigests,
        public array $localReferenceDigests,
        public array $payloadFingerprintDigests,
        public EncryptedEnvelope $payloadEnvelope,
        public EncryptedEnvelope $contextEnvelope,
        public ?EncryptedEnvelope $localReferenceEnvelope,
        public array $contextDigests,
        public array $correlationDigests,
        public array $cohortDigests,
        public ?VersionedHmacDigest $activeCohortDigest,
    ) {}

    /** @param list<VersionedHmacDigest> $digests */
    public function digestForVersion(array $digests, int $keyVersion): ?VersionedHmacDigest
    {
        foreach ($digests as $digest) {
            if ($digest->keyVersion === $keyVersion) {
                return $digest;
            }
        }

        return null;
    }
}
