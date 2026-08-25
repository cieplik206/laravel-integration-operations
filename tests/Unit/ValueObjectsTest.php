<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeOperationResult;
use Cieplik206\IntegrationOperations\Testing\Conformance\Fakes\FakeProviderExtensions;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\OperationReceipt;
use Cieplik206\IntegrationOperations\ValueObjects\OperationSnapshot;
use Cieplik206\IntegrationOperations\ValueObjects\OperationType;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;

class FailingFixtureResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.operation_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        throw new RuntimeException('Fixture decode failed.');
    }
}

final class MismatchedFixtureResultCodec extends FailingFixtureResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.different_result';
    }
}

final class ThrowingMetadataFixtureResultCodec extends FailingFixtureResultCodec
{
    public static function resultType(): string
    {
        throw new RuntimeException('Untrusted codec metadata failed.');
    }
}

final class MutableOperationResult implements OperationResult
{
    public string $value = 'mutable';

    public function resultType(): string
    {
        return 'fixture.mutable_result';
    }
}

final class MutableNestedResultState
{
    public string $value = 'mutable';
}

final readonly class NestedMutableOperationResult implements OperationResult
{
    public function __construct(public MutableNestedResultState $state) {}

    public function resultType(): string
    {
        return 'fixture.nested_mutable_result';
    }
}

final readonly class FloatingOperationResult implements OperationResult
{
    public function __construct(public float $value) {}

    public function resultType(): string
    {
        return 'fixture.floating_result';
    }
}

final readonly class ArrayOperationResult implements OperationResult
{
    /** @param array<mixed> $values */
    public function __construct(public array $values) {}

    public function resultType(): string
    {
        return 'fixture.array_result';
    }
}

final readonly class DifferentDecodedOperationResult implements OperationResult
{
    public function resultType(): string
    {
        return 'fixture.different_decoded_result';
    }
}

final readonly class ThrowingResultTypeOperationResult implements OperationResult
{
    public function resultType(): string
    {
        throw new RuntimeException('Untrusted decoded result type failed.');
    }
}

final class ReturningFixtureResultCodec implements OperationResultCodec
{
    public function __construct(private readonly OperationResult $result) {}

    public static function resultType(): string
    {
        return 'fixture.operation_result';
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        return $this->result;
    }
}

function fixtureOperationId(): OperationId
{
    return new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV');
}

it('round-trips an immutable versioned receipt', function (): void {
    $receipt = new OperationReceipt(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        false,
    );
    $restored = OperationReceipt::fromArray($receipt->toArray());

    expect($restored->equals($receipt))->toBeTrue()
        ->and($restored->toArray())->toHaveKey('version', 1)
        ->and((new ReflectionClass($receipt))->isReadOnly())->toBeTrue();
});

it('derives disposition and terminality from status', function (): void {
    $codec = new FakeProviderExtensions;
    $result = new FakeOperationResult('remote-1');
    $pending = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Pending,
        ResultAvailability::NotReady,
        null,
        IntegrationContext::make(),
    );
    $succeeded = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Succeeded,
        ResultAvailability::Available,
        $result,
        IntegrationContext::make(),
        encodedResult: $codec->encode($result),
    );

    expect($pending->disposition->value)->toBe('in_progress')
        ->and($pending->isTerminal())->toBeFalse()
        ->and($succeeded->disposition->value)->toBe('succeeded')
        ->and($succeeded->isTerminal())->toBeTrue()
        ->and(OperationSnapshot::fromArray(
            $succeeded->toArray(),
            new IntegrationContextConstraints,
            fn (string $resultType, int $schemaVersion): FakeProviderExtensions => $codec,
        )->equals($succeeded))->toBeTrue()
        ->and(OperationSnapshot::fromArray(
            $succeeded->toArray(),
            new IntegrationContextConstraints,
            fn (string $resultType, int $schemaVersion): FakeProviderExtensions => $codec,
        )->result)->toBeInstanceOf(FakeOperationResult::class);
});

it('enforces snapshot result and failure invariants', function (): void {
    $arguments = [
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
    ];
    $codec = new FakeProviderExtensions;
    $result = new FakeOperationResult('invalid-pending-result');

    expect(fn (): OperationSnapshot => new OperationSnapshot(
        ...$arguments,
        status: OperationStatus::Pending,
        resultAvailability: ResultAvailability::Available,
        result: $result,
        context: IntegrationContext::make(),
        encodedResult: $codec->encode($result),
    ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationSnapshot => new OperationSnapshot(
            ...$arguments,
            status: OperationStatus::Succeeded,
            resultAvailability: ResultAvailability::NotApplicable,
            result: null,
            context: IntegrationContext::make(),
        ))->toThrow(InvalidArgumentException::class, 'must retain')
        ->and(fn (): OperationSnapshot => new OperationSnapshot(
            ...$arguments,
            status: OperationStatus::Succeeded,
            resultAvailability: ResultAvailability::NotReady,
            result: null,
            context: IntegrationContext::make(),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationSnapshot => new OperationSnapshot(
            ...$arguments,
            status: OperationStatus::Succeeded,
            resultAvailability: ResultAvailability::NotApplicable,
            result: null,
            context: IntegrationContext::make(),
            safeFailure: new SafeOperationFailure('unexpected', 'Must not survive success.'),
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects malformed snapshot envelopes with a domain exception', function (): void {
    $snapshot = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Failed,
        ResultAvailability::NotApplicable,
        null,
        IntegrationContext::make(),
        new SafeOperationFailure('permanent', 'The fake operation failed.'),
    );
    $malformed = $snapshot->toArray();
    $malformed['operation_id'] = ['not-a-string'];

    expect(fn (): OperationSnapshot => OperationSnapshot::fromArray(
        $malformed,
        new IntegrationContextConstraints,
    ))
        ->toThrow(InvalidArgumentException::class);
});

it('normalizes ULIDs to one canonical uppercase identity', function (): void {
    $uppercase = fixtureOperationId();
    $lowercase = new OperationId(strtolower($uppercase->value));

    expect($lowercase->value)->toBe($uppercase->value)
        ->and($lowercase->equals($uppercase))->toBeTrue();
});

it('preserves encoded terminal results across every result availability', function (): void {
    $constraints = new IntegrationContextConstraints;
    $codec = new FakeProviderExtensions;
    $result = new FakeOperationResult('round-trip');
    $available = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Succeeded,
        ResultAvailability::Available,
        $result,
        IntegrationContext::make(),
        encodedResult: $codec->encode($result),
    );
    $availableEnvelope = $available->toArray();

    $decoded = OperationSnapshot::fromArray(
        $availableEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => $codec,
    );
    $missingCodec = OperationSnapshot::fromArray($availableEnvelope, $constraints);
    $mismatchedCodec = OperationSnapshot::fromArray(
        $availableEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => new MismatchedFixtureResultCodec,
    );
    $throwingMetadataCodec = OperationSnapshot::fromArray(
        $availableEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => new ThrowingMetadataFixtureResultCodec,
    );
    $decodeFailed = OperationSnapshot::fromArray(
        $availableEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => new FailingFixtureResultCodec,
    );

    $codecUnavailableEnvelope = $availableEnvelope;
    $codecUnavailableEnvelope['result_availability'] = ResultAvailability::CodecUnavailable->value;
    $preservedCodecUnavailable = OperationSnapshot::fromArray(
        $codecUnavailableEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => $codec,
    );

    $decodeFailedEnvelope = $availableEnvelope;
    $decodeFailedEnvelope['result_availability'] = ResultAvailability::DecodeFailed->value;
    $preservedDecodeFailed = OperationSnapshot::fromArray(
        $decodeFailedEnvelope,
        $constraints,
        fn (string $resultType, int $schemaVersion): OperationResultCodec => $codec,
    );
    $notApplicable = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Failed,
        ResultAvailability::NotApplicable,
        null,
        IntegrationContext::make(),
        new SafeOperationFailure('fixture_failed', 'The fixture operation failed.'),
    );
    $notReady = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Pending,
        ResultAvailability::NotReady,
        null,
        IntegrationContext::make(),
    );

    expect($decoded->resultAvailability)->toBe(ResultAvailability::Available)
        ->and($decoded->result)->toBeInstanceOf(FakeOperationResult::class)
        ->and($decoded->toArray())->toBe($availableEnvelope)
        ->and($missingCodec->resultAvailability)->toBe(ResultAvailability::CodecUnavailable)
        ->and($missingCodec->result)->toBeNull()
        ->and($missingCodec->toArray()['result'])->toBe($availableEnvelope['result'])
        ->and($mismatchedCodec->resultAvailability)->toBe(ResultAvailability::CodecUnavailable)
        ->and($throwingMetadataCodec->resultAvailability)->toBe(ResultAvailability::CodecUnavailable)
        ->and($throwingMetadataCodec->toArray()['result'])->toBe($availableEnvelope['result'])
        ->and($decodeFailed->resultAvailability)->toBe(ResultAvailability::DecodeFailed)
        ->and($preservedCodecUnavailable->toArray())->toBe($codecUnavailableEnvelope)
        ->and($preservedDecodeFailed->toArray())->toBe($decodeFailedEnvelope)
        ->and(OperationSnapshot::fromArray($notApplicable->toArray(), $constraints)->equals($notApplicable))->toBeTrue()
        ->and(OperationSnapshot::fromArray($notReady->toArray(), $constraints)->equals($notReady))->toBeTrue();
});

it('maps every invalid typed codec result to decode failed while preserving its envelope', function (): void {
    $codec = new FakeProviderExtensions;
    $result = new FakeOperationResult('encoded-source');
    $snapshot = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Succeeded,
        ResultAvailability::Available,
        $result,
        IntegrationContext::make(),
        encodedResult: $codec->encode($result),
    );
    $envelope = $snapshot->toArray();
    $external = 'before';
    $referenced = ['value' => &$external];
    $decodedResults = [
        'mutable result' => new MutableOperationResult,
        'floating result' => new FloatingOperationResult(1.5),
        'referenced result' => new ArrayOperationResult($referenced),
        'wrong result type' => new DifferentDecodedOperationResult,
        'throwing result type' => new ThrowingResultTypeOperationResult,
    ];

    foreach ($decodedResults as $invalidResult) {
        $restored = OperationSnapshot::fromArray(
            $envelope,
            new IntegrationContextConstraints,
            fn (string $_resultType, int $_schemaVersion): OperationResultCodec => new ReturningFixtureResultCodec($invalidResult),
        );

        expect($restored->resultAvailability)->toBe(ResultAvailability::DecodeFailed)
            ->and($restored->result)->toBeNull()
            ->and($restored->toArray()['result'])->toBe($envelope['result']);
    }
});

it('rejects mutable operation results and nested mutable references at every holder boundary', function (): void {
    $mutable = new MutableOperationResult;
    $nested = new NestedMutableOperationResult(new MutableNestedResultState);
    $floating = new FloatingOperationResult(1.5);

    expect(fn (): ExecutionOutcome => new ExecutionOutcome($mutable))
        ->toThrow(InvalidArgumentException::class, 'final readonly')
        ->and(fn (): ReconciliationOutcome => ReconciliationOutcome::foundExact($nested, 'fixture.found'))
        ->toThrow(InvalidArgumentException::class, 'final readonly')
        ->and(fn (): OperationSnapshot => new OperationSnapshot(
            fixtureOperationId(),
            IntegrationScope::of('fixture_catalog', 'primary'),
            new OperationType('fixture_catalog.record.fetch'),
            OperationStatus::Succeeded,
            ResultAvailability::Available,
            $nested,
            IntegrationContext::make(),
            encodedResult: new EncodedResult($nested->resultType(), 1, ['value' => 'immutable-copy']),
        ))->toThrow(InvalidArgumentException::class, 'final readonly')
        ->and(fn (): ExecutionOutcome => new ExecutionOutcome($floating))
        ->toThrow(InvalidArgumentException::class, 'canonical immutable');
});

it('rejects external references, self-cycles, and invalid UTF-8 keys in readonly result arrays', function (): void {
    $externalValue = 'before';
    $referenced = ['value' => &$externalValue];
    $cycle = [];
    $cycle['self'] = &$cycle;
    $invalidUtf8Key = "invalid\xFF";

    expect(fn (): ExecutionOutcome => new ExecutionOutcome(new ArrayOperationResult($referenced)))
        ->toThrow(InvalidArgumentException::class, 'must not contain references')
        ->and(fn (): ExecutionOutcome => new ExecutionOutcome(new ArrayOperationResult($cycle)))
        ->toThrow(InvalidArgumentException::class, 'must not contain references')
        ->and(fn (): ExecutionOutcome => new ExecutionOutcome(new ArrayOperationResult([$invalidUtf8Key => 'value'])))
        ->toThrow(InvalidArgumentException::class, 'keys must contain valid UTF-8');

    $externalValue = 'after';

    expect($referenced['value'])->toBe('after');
});

it('compares snapshot context and encoded result maps canonically', function (): void {
    $result = new FakeOperationResult('canonical');
    $first = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Succeeded,
        ResultAvailability::Available,
        $result,
        IntegrationContext::make(attributes: ['beta' => 2, 'alpha' => 1]),
        encodedResult: new EncodedResult($result->resultType(), 1, ['beta' => 2, 'alpha' => 1]),
    );
    $second = new OperationSnapshot(
        fixtureOperationId(),
        IntegrationScope::of('fixture_catalog', 'primary'),
        new OperationType('fixture_catalog.record.fetch'),
        OperationStatus::Succeeded,
        ResultAvailability::Available,
        $result,
        IntegrationContext::make(attributes: ['alpha' => 1, 'beta' => 2]),
        encodedResult: new EncodedResult($result->resultType(), 1, ['alpha' => 1, 'beta' => 2]),
    );

    expect($first->toArray())->not->toBe($second->toArray())
        ->and($first->equals($second))->toBeTrue();
});

it('rejects unsafe failure summaries and malformed public envelopes', function (): void {
    expect(fn (): SafeOperationFailure => new SafeOperationFailure('remote_failure', "raw response\nAuthorization: secret"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): SafeOperationFailure => new SafeOperationFailure('remote_failure', "raw response\u{2028}forged"))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): OperationReceipt => OperationReceipt::fromArray([
            'version' => 1,
            'operation_id' => ['wrong'],
            'scope' => IntegrationScope::of('fixture_catalog', 'primary')->toArray(),
            'operation_type' => 'fixture_catalog.record.fetch',
            'was_already_registered' => false,
        ]))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationScope => IntegrationScope::fromArray([
            'version' => 1,
            'provider' => 'fixture_catalog',
            'connection' => 'primary',
            'unexpected' => true,
        ]))->toThrow(InvalidArgumentException::class);
});
