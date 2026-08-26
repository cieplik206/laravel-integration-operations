<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\ExecutionOutcome;
use Cieplik206\IntegrationOperations\Contracts\FailureClassifier;
use Cieplik206\IntegrationOperations\Contracts\OperationExecution;
use Cieplik206\IntegrationOperations\Contracts\OperationHandler;
use Cieplik206\IntegrationOperations\Contracts\OperationResult;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Contracts\OperationView;
use Cieplik206\IntegrationOperations\Contracts\OutcomeProjector;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\ReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\RetryPolicy;
use Cieplik206\IntegrationOperations\Enums\FailureDisposition;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;
use Cieplik206\IntegrationOperations\ValueObjects\FailureClassification;
use Cieplik206\IntegrationOperations\ValueObjects\ReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\RetryInstruction;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use InvalidArgumentException;
use Throwable;

final class FakeAuthoritativeLegacyProviderExtensions implements FailureClassifier, OperationHandler, OperationResultCodec, OutcomeProjector, ReconciliationStrategy, RetryPolicy
{
    public static bool $failOnConstruction = false;

    public static int $constructionAttempts = 0;

    public static bool $openEffectBoundary = false;

    public static bool $throwAfterBoundary = false;

    public static bool $awaitPolling = false;

    public function __construct()
    {
        self::$constructionAttempts++;

        if (self::$failOnConstruction) {
            throw new \RuntimeException('Legacy authoritative provider extension must not be constructed during application boot.');
        }
    }

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        if (self::$openEffectBoundary) {
            $operation->effectBoundary()->open();
        }

        if (self::$throwAfterBoundary) {
            throw new \RuntimeException('Fixture transport lost the response after opening the boundary.');
        }

        $result = new FakeAuthoritativeOperationResult('executed');

        return self::$awaitPolling
            ? ExecutionOutcome::awaitPolling($result)
            : new ExecutionOutcome($result);
    }

    public function classify(OperationView $operation, Throwable $failure): FailureClassification
    {
        return new FailureClassification(
            FailureDisposition::Permanent,
            new SafeOperationFailure('fixture_failure', 'A redacted fixture failure occurred.'),
        );
    }

    public function decide(OperationView $operation, FailureClassification $failure): RetryInstruction
    {
        return RetryInstruction::fail();
    }

    public function reconcile(ReconciliationContext $context): ReconciliationOutcome
    {
        return ReconciliationOutcome::foundExact(
            new FakeAuthoritativeOperationResult('reconciled'),
            'fixture.exact_match',
        );
    }

    public function project(OperationView $operation, ExecutionOutcome $outcome): void {}

    public static function resultType(): string
    {
        return FakeAuthoritativeProviderExtensions::resultType();
    }

    public static function schemaVersion(): int
    {
        return FakeAuthoritativeProviderExtensions::schemaVersion();
    }

    public function encode(OperationResult $result): EncodedResult
    {
        if (! $result instanceof FakeAuthoritativeOperationResult) {
            throw new InvalidArgumentException('Legacy authoritative codec received an unsupported result.');
        }

        return new EncodedResult(self::resultType(), self::schemaVersion(), ['value' => $result->value]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        $value = $result->payload['value'] ?? null;

        if ($result->resultType !== self::resultType()
            || $result->schemaVersion !== self::schemaVersion()
            || ! is_string($value)) {
            throw new InvalidArgumentException('Legacy authoritative encoded result is invalid.');
        }

        return new FakeAuthoritativeOperationResult($value);
    }
}
