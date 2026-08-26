<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\AuthoritativeReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ReconciliationResult;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Registry\ProjectionContract;
use Cieplik206\IntegrationOperations\Registry\ResultEnvelopeDescriptor;
use Cieplik206\IntegrationOperations\Registry\ServiceReference;
use Cieplik206\IntegrationOperations\Registry\TerminalOutcomePair;
use Cieplik206\IntegrationOperations\Registry\TransportTargetDefinition;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionMutation;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationObservation;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryAfterSeconds;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Illuminate\Encryption\Encrypter;

final readonly class AuthoritativeFixtureResult implements OperationResult
{
    public function resultType(): string
    {
        return AuthoritativeFixtureResultCodec::resultType();
    }
}

final class AuthoritativeFixtureResultCodec implements OperationResultCodec
{
    public static function resultType(): string
    {
        return 'fixture.authoritative_result';
    }

    public static function schemaVersion(): int
    {
        return 2;
    }

    public function encode(OperationResult $result): EncodedResult
    {
        return new EncodedResult(self::resultType(), self::schemaVersion(), []);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        return new AuthoritativeFixtureResult;
    }
}

final class AuthoritativeFixtureProjectionPlanner implements OutcomeProjectionPlanner
{
    public function plan(ProjectionInput $input): ProjectionPlan
    {
        return new ProjectionPlan(1, []);
    }
}

final class AuthoritativeFixtureObservationPlanner implements ObservationProjectionPlanner
{
    public function plan(ObservationProjectionInput $input): ObservationProjectionPlan
    {
        return new ObservationProjectionPlan(1, []);
    }
}

it('constructs globally legal terminal proof sets without enum object coercion', function (): void {
    $outcome = new TerminalOutcomePair(
        OperationStatus::Succeeded,
        EffectState::Applied,
        ResultAvailability::Available,
        [TerminalProofKind::Reconcile, TerminalProofKind::Execute],
    );

    expect($outcome->proofKinds)->toBe([
        TerminalProofKind::Execute,
        TerminalProofKind::Reconcile,
    ]);
});

it('permits a provider rejection observed before the SDK starts a remote write', function (): void {
    $outcome = new TerminalOutcomePair(
        OperationStatus::Failed,
        EffectState::NotStarted,
        ResultAvailability::Available,
        [TerminalProofKind::Poll, TerminalProofKind::Reconcile],
    );

    expect($outcome->proofKinds)->toBe([
        TerminalProofKind::Poll,
        TerminalProofKind::Reconcile,
    ]);
});

it('binds projection plans to a frozen schema and target allowlist', function (): void {
    $contract = new ProjectionContract(
        new ServiceReference(AuthoritativeFixtureProjectionPlanner::class, OutcomeProjectionPlanner::class),
        7,
        ['fixture.second', 'fixture.first'],
    );
    $first = new ProjectionMutation('fixture.first', ['id' => '2'], null, ['value' => 'second']);
    $second = new ProjectionMutation('fixture.second', ['id' => '1'], 3, ['value' => 'first']);
    $plan = new ProjectionPlan(7, [$second, $first]);

    expect($contract->targetIds)->toBe(['fixture.first', 'fixture.second'])
        ->and(array_map(static fn (ProjectionMutation $mutation): string => $mutation->targetId, $plan->mutations))
        ->toBe(['fixture.first', 'fixture.second'])
        ->and($plan->isCompatibleWith($contract))->toBeTrue()
        ->and((new ProjectionPlan(8, [$first]))->isCompatibleWith($contract))->toBeFalse()
        ->and((new ProjectionPlan(7, [
            new ProjectionMutation('fixture.foreign', ['id' => '1'], null, ['value' => 'foreign']),
        ]))->isCompatibleWith($contract))->toBeFalse();

    expect(fn () => new ProjectionPlan(7, [$first, $first]))
        ->toThrow(InvalidArgumentException::class);
});

it('gives observation projection plans the same bounds ordering and compatibility checks', function (): void {
    $contract = new ProjectionContract(
        new ServiceReference(AuthoritativeFixtureObservationPlanner::class, ObservationProjectionPlanner::class),
        3,
        ['fixture.observation'],
    );
    $mutation = new ProjectionMutation('fixture.observation', ['id' => '1'], null, ['state' => 'seen']);
    $plan = new ObservationProjectionPlan(3, [$mutation]);

    expect($plan->isCompatibleWith($contract))->toBeTrue()
        ->and((new ObservationProjectionPlan(4, [$mutation]))->isCompatibleWith($contract))->toBeFalse();
});

it('keeps authoritative observation projection input free from legacy reconciliation outcomes', function (): void {
    $type = (new ReflectionProperty(ObservationProjectionInput::class, 'observation'))->getType();

    if (! $type instanceof ReflectionUnionType) {
        throw new LogicException('Authoritative observation input must use a closed union.');
    }

    $types = array_map(
        static function (ReflectionType $member): string {
            if (! $member instanceof ReflectionNamedType) {
                throw new LogicException('Authoritative observation input union members must be named types.');
            }

            return $member->getName();
        },
        $type->getTypes(),
    );

    expect($types)->toContain(PollOutcome::class, AuthoritativeReconciliationOutcome::class)
        ->not->toContain(ReconciliationOutcome::class);
});

it('carries bounded canonical provider observations through polling and reconciliation outcomes', function (): void {
    $pollObservation = new CanonicalObject([
        'raw_status' => 'processing',
        'details' => ['sequence' => 3],
    ]);
    $reconciliationObservation = new CanonicalObject([
        'raw_status' => 'ok',
        'government_id' => 'KSeF:fixture',
    ]);

    $poll = PollOutcome::wait('provider.processing', null, $pollObservation);
    $reconciliation = AuthoritativeReconciliationOutcome::foundExact(
        new AuthoritativeFixtureResult,
        'provider.accepted',
        $reconciliationObservation,
    );

    expect($poll->providerObservation?->values)->toBe([
        'raw_status' => 'processing',
        'details' => ['sequence' => 3],
    ])->and($reconciliation->providerObservation?->values)->toBe([
        'raw_status' => 'ok',
        'government_id' => 'KSeF:fixture',
    ]);
});

it('accepts only canonical transport templates and renders exact scalar parameter maps', function (): void {
    $target = new TransportTargetDefinition(
        'invoice.read',
        'https',
        'GET',
        '/invoices/{invoice_id}/status/{status_id}',
    );

    expect($target->placeholderNames)->toBe(['invoice_id', 'status_id'])
        ->and($target->render(['invoice_id' => 'FV 1', 'status_id' => 'gotowe']))
        ->toBe('/invoices/FV%201/status/gotowe')
        ->and($target->render(['invoice_id' => '１', 'status_id' => '．']))
        ->toBe('/invoices/%EF%BC%91/status/%EF%BC%8E');

    foreach (['/{', '/x{y}', '/{x}/../tail', '//evil.example', '/x?secret=1', '/x#fragment'] as $template) {
        expect(fn () => new TransportTargetDefinition('invalid.target', 'https', 'GET', $template))
            ->toThrow(InvalidArgumentException::class);
    }

    foreach (['.', '..', '/', '\\', '%2f', '?x', '#x'] as $value) {
        expect(fn () => $target->render(['invoice_id' => $value, 'status_id' => 'ok']))
            ->toThrow(InvalidArgumentException::class);
    }

    expect(fn () => $target->render(['invoice_id' => '1']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => $target->render(['invoice_id' => '1', 'status_id' => 'ok', 'extra' => 'x']))
        ->toThrow(InvalidArgumentException::class);
});

it('bounds encoded result metadata before canonical JSON allocation', function (): void {
    expect(fn () => new EncodedResult(str_repeat('a', 192), 1, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EncodedResult('fixture.result', 65_536, []))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new EncodedResult('fixture.result', 1, ['value' => str_repeat('x', 262_145)]))
        ->toThrow(InvalidArgumentException::class);

    $child = new CanonicalObject(['value' => str_repeat('x', 100_000)]);

    expect(fn () => new EncodedResult('fixture.result', 1, [
        'first' => $child,
        'second' => $child,
        'third' => $child,
    ]))->toThrow(InvalidArgumentException::class);
});

it('rejects a forged cyclic canonical object before normalization', function (): void {
    $reflection = new ReflectionClass(CanonicalObject::class);
    $cyclic = $reflection->newInstanceWithoutConstructor();
    $values = $reflection->getProperty('values');
    $values->setValue($cyclic, ['self' => $cyclic]);

    expect(fn () => new EncodedResult('fixture.result', 1, ['cyclic' => $cyclic]))
        ->toThrow(InvalidArgumentException::class);
});

it('derives a ciphertext floor that contains both supported Laravel GCM envelopes', function (string $cipher): void {
    $plaintext = str_repeat('x', ResultEnvelopeDescriptor::HardMaximumPlaintextBytes);
    $key = Encrypter::generateKey($cipher);
    $encrypted = (new Encrypter($key, $cipher))->encryptString($plaintext);
    $minimum = ResultEnvelopeDescriptor::minimumAesGcmCiphertextBytes(strlen($plaintext));

    expect(strlen($encrypted))->toBe($minimum)
        ->and($minimum)->toBeLessThanOrEqual(ResultEnvelopeDescriptor::HardMaximumCiphertextBytes);

    $reference = new ServiceReference(AuthoritativeFixtureResultCodec::class, OperationResultCodec::class);

    $descriptor = new ResultEnvelopeDescriptor(
        $reference,
        AuthoritativeFixtureResultCodec::resultType(),
        AuthoritativeFixtureResultCodec::schemaVersion(),
        strlen($plaintext),
        $minimum,
    );

    expect($descriptor->maximumCiphertextBytes)->toBe($minimum)
        ->and(fn () => new ResultEnvelopeDescriptor(
            $reference,
            AuthoritativeFixtureResultCodec::resultType(),
            AuthoritativeFixtureResultCodec::schemaVersion(),
            strlen($plaintext),
            $minimum - 1,
        ))->toThrow(InvalidArgumentException::class);
})->with(['aes-128-gcm', 'aes-256-gcm']);

it('represents provider rejection without a caller-selected effect state', function (): void {
    $outcome = AuthoritativeReconciliationOutcome::providerRejected(
        new AuthoritativeFixtureResult,
        new SafeOperationFailure('provider_rejected', 'Provider rejected the operation.'),
        'provider.rejected',
    );

    expect($outcome->result)->toBe(AuthoritativeReconciliationResult::ProviderRejected)
        ->and($outcome->operationResult)->toBeInstanceOf(AuthoritativeFixtureResult::class)
        ->and($outcome->safeFailure)->not->toBeNull()
        ->and(array_key_exists('effectState', get_object_vars($outcome)))->toBeFalse();
});

it('keeps authoritative failure provenance and relative retry hints typed and disjoint', function (): void {
    $failure = new SafeOperationFailure('transport_timeout', 'The transport timed out.');
    $uncertain = new ClassifiedFailure(
        FailureDisposition::Uncertain,
        $failure,
        ReconciliationTrigger::LostResponse,
    );
    $retryable = new ClassifiedFailure(
        FailureDisposition::RetryableRead,
        $failure,
        ReconciliationTrigger::Unknown,
        new RetryAfterSeconds(15),
    );

    expect($uncertain->reconciliationTrigger)->toBe(ReconciliationTrigger::LostResponse)
        ->and($retryable->retryAfter?->value)->toBe(15)
        ->and(array_keys(get_object_vars($uncertain)))->not->toContain('terminalResult')
        ->and(fn () => new ClassifiedFailure(
            FailureDisposition::Permanent,
            $failure,
            ReconciliationTrigger::LostResponse,
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn () => new ClassifiedFailure(
            FailureDisposition::Permanent,
            $failure,
            ReconciliationTrigger::Unknown,
            new RetryAfterSeconds(15),
        ))->toThrow(InvalidArgumentException::class);
});

it('normalizes prior reconciliation observation instants to UTC without renumbering gaps', function (): void {
    $observation = new ReconciliationObservation(
        3,
        ReconciliationResult::Inconclusive,
        'provider.complete_empty',
        new DateTimeImmutable('2026-08-26T12:30:45.123456+02:00'),
    );

    expect($observation->observationNumber)->toBe(3)
        ->and($observation->observedAt->format('Y-m-d\TH:i:s.uP'))->toBe('2026-08-26T10:30:45.123456+00:00')
        ->and(fn () => new ReconciliationObservation(
            0,
            ReconciliationResult::Inconclusive,
            'provider.complete_empty',
            new DateTimeImmutable,
        ))->toThrow(InvalidArgumentException::class);
});
