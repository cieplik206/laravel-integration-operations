<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeFailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeRetryPolicy;
use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationPayloadCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjectionPlanner;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\Enums\ReconciliationTrigger;
use Cieplik206\IntegrationOperations\ValueObjects\ClassifiedFailure;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use InvalidArgumentException;
use Throwable;

final class FakeAuthoritativeProviderExtensions implements AuthoritativeFailureClassifier, AuthoritativeRetryPolicy, OperationHandler, OperationPayloadCodec, OperationResultCodec, OutcomeProjectionPlanner
{
    public static bool $failOnConstruction = false;

    public static int $constructionAttempts = 0;

    public static bool $classifyAsUncertain = false;

    public function __construct()
    {
        self::$constructionAttempts++;

        if (self::$failOnConstruction) {
            throw new \RuntimeException('Authoritative provider extension must not be constructed during application boot.');
        }
    }

    public static function schemaVersion(): int
    {
        return 1;
    }

    public static function resultType(): string
    {
        return 'fixture.authoritative_operation_result';
    }

    public function canonicalize(CanonicalObject $payload): CanonicalObject
    {
        return $payload;
    }

    public function writeActivationSlot(CanonicalObject $payload): string
    {
        return 'default';
    }

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        return new ExecutionOutcome(new FakeAuthoritativeOperationResult('executed'));
    }

    public function classify(OperationView $operation, Throwable $failure): ClassifiedFailure
    {
        if (self::$classifyAsUncertain) {
            return new ClassifiedFailure(
                FailureDisposition::Uncertain,
                new SafeOperationFailure('fixture_outcome_unknown', 'The fixture outcome is unknown.'),
                ReconciliationTrigger::LostResponse,
            );
        }

        return new ClassifiedFailure(
            FailureDisposition::Permanent,
            new SafeOperationFailure('fixture_failure', 'A redacted fixture failure occurred.'),
        );
    }

    public function decide(OperationView $operation, ClassifiedFailure $failure): RetryInstruction
    {
        if ($failure->disposition === FailureDisposition::Uncertain) {
            return RetryInstruction::reconcile();
        }

        return RetryInstruction::fail();
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof FakeAuthoritativeOperationResult) {
            throw new InvalidArgumentException('Fake authoritative codec received an unsupported result.');
        }

        return new EncodedResult(self::resultType(), self::schemaVersion(), ['value' => $result->value]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        $value = $result->payload['value'] ?? null;

        if ($result->resultType !== self::resultType()
            || $result->schemaVersion !== self::schemaVersion()
            || ! is_string($value)) {
            throw new InvalidArgumentException('Fake authoritative encoded result is invalid.');
        }

        return new FakeAuthoritativeOperationResult($value);
    }

    public function plan(ProjectionInput $input): ProjectionPlan
    {
        return new ProjectionPlan(1, []);
    }
}
