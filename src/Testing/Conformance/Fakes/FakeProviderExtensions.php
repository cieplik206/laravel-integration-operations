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

final class FakeProviderExtensions implements FailureClassifier, OperationHandler, OperationResultCodec, OutcomeProjector, ReconciliationStrategy, RetryPolicy
{
    public static bool $failOnConstruction = false;

    public static int $constructionAttempts = 0;

    public function __construct()
    {
        self::$constructionAttempts++;

        if (self::$failOnConstruction) {
            throw new \RuntimeException('Provider extension must not be constructed during application boot.');
        }
    }

    public function execute(OperationExecution $operation): ExecutionOutcome
    {
        return new ExecutionOutcome(new FakeOperationResult('executed'));
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
            new FakeOperationResult('reconciled'),
            'fixture.exact_match',
        );
    }

    public function project(OperationView $operation, ExecutionOutcome $outcome): void {}

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
        if (! $result instanceof FakeOperationResult) {
            throw new InvalidArgumentException('Fake codec received an unsupported result.');
        }

        return new EncodedResult(self::resultType(), 1, ['value' => $result->value]);
    }

    public function decode(EncodedResult $result): OperationResult
    {
        $value = $result->payload['value'] ?? null;

        if ($result->resultType !== self::resultType() || $result->schemaVersion !== 1 || ! is_string($value)) {
            throw new InvalidArgumentException('Fake encoded result is invalid.');
        }

        return new FakeOperationResult($value);
    }
}
