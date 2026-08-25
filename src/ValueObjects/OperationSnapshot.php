<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Support\OperationResultInvariant;
use InvalidArgumentException;
use Throwable;

/** @api */
final readonly class OperationSnapshot
{
    public const Version = 1;

    public OperationDisposition $disposition;

    public function __construct(
        public OperationId $operationId,
        public IntegrationScope $scope,
        public OperationType $operationType,
        public OperationStatus $status,
        public ResultAvailability $resultAvailability,
        public ?OperationResult $result,
        public IntegrationContext $context,
        public ?SafeOperationFailure $safeFailure = null,
        private ?EncodedResult $encodedResult = null,
    ) {
        if (! $operationType->belongsTo($scope->provider)) {
            throw new InvalidArgumentException('Operation type does not belong to the snapshot provider.');
        }

        $this->disposition = $status->disposition();

        if ($result !== null) {
            OperationResultInvariant::assertImmutable($result);
        }

        $this->assertResultInvariant();
        $this->assertFailureInvariant();
    }

    public function isTerminal(): bool
    {
        return $this->disposition->isTerminal();
    }

    public function operationId(): OperationId
    {
        return $this->operationId;
    }

    public function provider(): ProviderKey
    {
        return $this->scope->provider;
    }

    public function connectionKey(): ConnectionKey
    {
        return $this->scope->connection;
    }

    public function operationType(): OperationType
    {
        return $this->operationType;
    }

    public function status(): OperationStatus
    {
        return $this->status;
    }

    public function disposition(): OperationDisposition
    {
        return $this->disposition;
    }

    public function resultAvailability(): ResultAvailability
    {
        return $this->resultAvailability;
    }

    public function result(): ?OperationResult
    {
        return $this->result;
    }

    public function context(): IntegrationContext
    {
        return $this->context;
    }

    public function safeFailure(): ?SafeOperationFailure
    {
        return $this->safeFailure;
    }

    /**
     * @return array{
     *     version: int,
     *     operation_id: string,
     *     scope: array{version: int, provider: string, connection: string},
     *     operation_type: string,
     *     status: string,
     *     disposition: string,
     *     result_availability: string,
     *     result: array{result_type: string, schema_version: int, payload: array<string, mixed>}|null,
     *     context: array{version: int, correlation_id: string|null, attributes: array<string, bool|int|string|null>},
     *     safe_failure: array{version: int, code: string, summary: string}|null
     * }
     */
    public function toArray(): array
    {
        return [
            'version' => self::Version,
            'operation_id' => $this->operationId->value,
            'scope' => $this->scope->toArray(),
            'operation_type' => $this->operationType->value,
            'status' => $this->status->value,
            'disposition' => $this->disposition->value,
            'result_availability' => $this->resultAvailability->value,
            'result' => $this->encodedResult?->toArray(),
            'context' => $this->context->toArray(),
            'safe_failure' => $this->safeFailure?->toArray(),
        ];
    }

    /**
     * @param  array<string, mixed>  $data
     * @param  (callable(string, int): object)|null  $resolveResultCodec
     */
    public static function fromArray(
        array $data,
        IntegrationContextConstraints $contextConstraints,
        ?callable $resolveResultCodec = null,
    ): self {
        $expectedKeys = [
            'version',
            'operation_id',
            'scope',
            'operation_type',
            'status',
            'disposition',
            'result_availability',
            'result',
            'context',
            'safe_failure',
        ];

        if (array_diff(array_keys($data), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($data)) !== []) {
            throw new InvalidArgumentException('Operation snapshot envelope fields are invalid.');
        }

        if (($data['version'] ?? null) !== self::Version) {
            throw new InvalidArgumentException('Unsupported operation snapshot version.');
        }

        $scope = $data['scope'] ?? null;
        $context = $data['context'] ?? null;
        $result = $data['result'] ?? null;
        $safeFailure = $data['safe_failure'] ?? null;

        if (! is_array($scope) || ! is_array($context) || ($result !== null && ! is_array($result)) || ($safeFailure !== null && ! is_array($safeFailure))) {
            throw new InvalidArgumentException('Operation snapshot envelope is invalid.');
        }

        self::assertExactKeys($scope, ['version', 'provider', 'connection'], 'scope');
        self::assertExactKeys($context, ['version', 'correlation_id', 'attributes'], 'context');

        if (($context['version'] ?? null) !== IntegrationContext::Version) {
            throw new InvalidArgumentException('Unsupported integration context version.');
        }

        if ($result !== null) {
            self::assertExactKeys($result, ['result_type', 'schema_version', 'payload'], 'result');
        }

        if ($safeFailure !== null) {
            self::assertExactKeys($safeFailure, ['version', 'code', 'summary'], 'safe failure');
        }

        self::assertScopeEnvelope($scope);
        self::assertContextEnvelope($context);

        if ($result !== null) {
            self::assertResultEnvelope($result);
        }

        if ($safeFailure !== null) {
            self::assertSafeFailureEnvelope($safeFailure);
        }

        /** @var array{version: int, provider: string, connection: string} $scope */
        /** @var array{version: int, correlation_id: string|null, attributes: array<string, bool|int|string|null>} $context */
        /** @var array{result_type: string, schema_version: int, payload: array<string, mixed>}|null $result */
        /** @var array{version: int, code: string, summary: string}|null $safeFailure */
        $status = self::statusFromEnvelope($data);
        $resultAvailability = self::resultAvailabilityFromEnvelope($data);
        $encodedResult = $result === null ? null : EncodedResult::fromArray($result);
        [$resolvedAvailability, $decodedResult] = self::decodeResult(
            $resultAvailability,
            $encodedResult,
            $resolveResultCodec,
        );

        $snapshot = new self(
            operationId: new OperationId(self::requiredString($data, 'operation_id')),
            scope: IntegrationScope::fromArray($scope),
            operationType: new OperationType(self::requiredString($data, 'operation_type')),
            status: $status,
            resultAvailability: $resolvedAvailability,
            result: $decodedResult,
            context: IntegrationContext::make($context['correlation_id'], $context['attributes'], $contextConstraints),
            safeFailure: $safeFailure === null ? null : SafeOperationFailure::fromArray($safeFailure),
            encodedResult: $encodedResult,
        );

        if (($data['disposition'] ?? null) !== $snapshot->disposition->value) {
            throw new InvalidArgumentException('Operation snapshot disposition does not match its status.');
        }

        return $snapshot;
    }

    public function equals(self $other): bool
    {
        return $this->operationId->equals($other->operationId)
            && $this->scope->equals($other->scope)
            && $this->operationType->equals($other->operationType)
            && $this->status === $other->status
            && $this->resultAvailability === $other->resultAvailability
            && $this->context->equals($other->context)
            && self::encodedResultsEqual($this->encodedResult, $other->encodedResult)
            && self::safeFailuresEqual($this->safeFailure, $other->safeFailure);
    }

    private static function encodedResultsEqual(?EncodedResult $first, ?EncodedResult $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equals($second);
    }

    private static function safeFailuresEqual(?SafeOperationFailure $first, ?SafeOperationFailure $second): bool
    {
        if ($first === null || $second === null) {
            return $first === $second;
        }

        return $first->equals($second);
    }

    private function assertResultInvariant(): void
    {
        if ($this->resultAvailability === ResultAvailability::Available && ($this->result === null || $this->encodedResult === null)) {
            throw new InvalidArgumentException('Available operation result is missing.');
        }

        if (in_array($this->resultAvailability, [ResultAvailability::CodecUnavailable, ResultAvailability::DecodeFailed], true)
            && ($this->result !== null || $this->encodedResult === null)) {
            throw new InvalidArgumentException('Undecodable operation result must retain only its encoded envelope.');
        }

        if (in_array($this->resultAvailability, [ResultAvailability::NotReady, ResultAvailability::NotApplicable], true)
            && ($this->result !== null || $this->encodedResult !== null)) {
            throw new InvalidArgumentException('An operation without a result must not expose a result or envelope.');
        }

        if ($this->result !== null && $this->encodedResult !== null && $this->result->resultType() !== $this->encodedResult->resultType) {
            throw new InvalidArgumentException('Decoded operation result type does not match its envelope.');
        }

        if (! $this->isTerminal() && $this->resultAvailability !== ResultAvailability::NotReady) {
            throw new InvalidArgumentException('A non-terminal snapshot cannot expose a terminal result availability.');
        }

        if ($this->isTerminal() && $this->resultAvailability === ResultAvailability::NotReady) {
            throw new InvalidArgumentException('A terminal snapshot cannot have a not-ready result.');
        }

        $successfulResultAvailabilities = [
            ResultAvailability::Available,
            ResultAvailability::CodecUnavailable,
            ResultAvailability::DecodeFailed,
        ];

        if ($this->status === OperationStatus::Succeeded
            && ! in_array($this->resultAvailability, $successfulResultAvailabilities, true)) {
            throw new InvalidArgumentException('A succeeded operation must retain an available or encoded result.');
        }

        if ($this->status !== OperationStatus::Succeeded
            && in_array($this->resultAvailability, $successfulResultAvailabilities, true)) {
            throw new InvalidArgumentException('Only a succeeded operation may expose or decode a result.');
        }
    }

    private function assertFailureInvariant(): void
    {
        if ($this->status === OperationStatus::Failed && $this->safeFailure === null) {
            throw new InvalidArgumentException('A failed operation must expose a safe failure.');
        }

        if (in_array($this->status, [OperationStatus::Succeeded, OperationStatus::Cancelled], true) && $this->safeFailure !== null) {
            throw new InvalidArgumentException('A succeeded or cancelled operation cannot expose a failure.');
        }
    }

    /** @param array<string, mixed> $data */
    private static function requiredString(array $data, string $key): string
    {
        $value = $data[$key] ?? null;

        if (! is_string($value)) {
            throw new InvalidArgumentException("Operation snapshot field '{$key}' is invalid.");
        }

        return $value;
    }

    /**
     * @param  array<mixed>  $data
     * @param  list<string>  $expectedKeys
     */
    private static function assertExactKeys(array $data, array $expectedKeys, string $field): void
    {
        if (array_diff(array_keys($data), $expectedKeys) !== [] || array_diff($expectedKeys, array_keys($data)) !== []) {
            throw new InvalidArgumentException("Operation snapshot {$field} fields are invalid.");
        }
    }

    /** @param array<string, mixed> $data */
    private static function statusFromEnvelope(array $data): OperationStatus
    {
        $status = OperationStatus::tryFrom(self::requiredString($data, 'status'));

        return $status ?? throw new InvalidArgumentException('Operation snapshot status is invalid.');
    }

    /** @param array<string, mixed> $data */
    private static function resultAvailabilityFromEnvelope(array $data): ResultAvailability
    {
        $availability = ResultAvailability::tryFrom(self::requiredString($data, 'result_availability'));

        return $availability ?? throw new InvalidArgumentException('Operation snapshot result availability is invalid.');
    }

    /** @param array<mixed> $scope */
    private static function assertScopeEnvelope(array $scope): void
    {
        if (($scope['version'] ?? null) !== IntegrationScope::Version
            || ! is_string($scope['provider'] ?? null)
            || ! is_string($scope['connection'] ?? null)) {
            throw new InvalidArgumentException('Operation snapshot scope values are invalid.');
        }
    }

    /** @param array<mixed> $context */
    private static function assertContextEnvelope(array $context): void
    {
        $correlationId = $context['correlation_id'] ?? null;
        $attributes = $context['attributes'] ?? null;

        if (($context['version'] ?? null) !== IntegrationContext::Version
            || ($correlationId !== null && ! is_string($correlationId))
            || ! is_array($attributes)) {
            throw new InvalidArgumentException('Operation snapshot context values are invalid.');
        }

        foreach ($attributes as $key => $value) {
            if (! is_string($key) || (! is_string($value) && ! is_int($value) && ! is_bool($value) && $value !== null)) {
                throw new InvalidArgumentException('Operation snapshot context attributes are invalid.');
            }
        }
    }

    /** @param array<mixed> $result */
    private static function assertResultEnvelope(array $result): void
    {
        $payload = $result['payload'] ?? null;

        if (! is_string($result['result_type'] ?? null)
            || ! is_int($result['schema_version'] ?? null)
            || ! is_array($payload)
            || array_is_list($payload) && $payload !== []) {
            throw new InvalidArgumentException('Operation snapshot result values are invalid.');
        }
    }

    /**
     * @param  (callable(string, int): object)|null  $resolveResultCodec
     * @return array{ResultAvailability, OperationResult|null}
     */
    private static function decodeResult(
        ResultAvailability $availability,
        ?EncodedResult $encodedResult,
        ?callable $resolveResultCodec,
    ): array {
        if ($encodedResult === null) {
            return [$availability, null];
        }

        if (in_array($availability, [ResultAvailability::NotReady, ResultAvailability::NotApplicable], true)) {
            return [$availability, null];
        }

        if (in_array($availability, [ResultAvailability::CodecUnavailable, ResultAvailability::DecodeFailed], true)) {
            return [$availability, null];
        }

        if ($resolveResultCodec === null) {
            return [ResultAvailability::CodecUnavailable, null];
        }

        try {
            $codec = $resolveResultCodec($encodedResult->resultType, $encodedResult->schemaVersion);
        } catch (Throwable) {
            return [ResultAvailability::CodecUnavailable, null];
        }

        if (! $codec instanceof OperationResultCodec) {
            return [ResultAvailability::CodecUnavailable, null];
        }

        try {
            if ($codec::resultType() !== $encodedResult->resultType
                || $codec::schemaVersion() !== $encodedResult->schemaVersion) {
                return [ResultAvailability::CodecUnavailable, null];
            }
        } catch (Throwable) {
            return [ResultAvailability::CodecUnavailable, null];
        }

        try {
            $result = $codec->decode($encodedResult);
            OperationResultInvariant::assertImmutable($result);

            if ($result->resultType() !== $encodedResult->resultType) {
                return [ResultAvailability::DecodeFailed, null];
            }
        } catch (Throwable) {
            return [ResultAvailability::DecodeFailed, null];
        }

        return [ResultAvailability::Available, $result];
    }

    /** @param array<mixed> $safeFailure */
    private static function assertSafeFailureEnvelope(array $safeFailure): void
    {
        if (($safeFailure['version'] ?? null) !== SafeOperationFailure::Version
            || ! is_string($safeFailure['code'] ?? null)
            || ! is_string($safeFailure['summary'] ?? null)) {
            throw new InvalidArgumentException('Operation snapshot safe failure values are invalid.');
        }
    }
}
