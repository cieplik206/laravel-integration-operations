<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance\Fakes;

use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationContext;
use Cieplik206\IntegrationOperations\Contracts\AuthoritativeReconciliationStrategy;
use Cieplik206\IntegrationOperations\Contracts\ObservationProjectionPlanner;
use Cieplik206\IntegrationOperations\Contracts\PollingContext;
use Cieplik206\IntegrationOperations\Contracts\PollingStrategy;
use Cieplik206\IntegrationOperations\ValueObjects\AuthoritativeReconciliationOutcome;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionInput;
use Cieplik206\IntegrationOperations\ValueObjects\ObservationProjectionPlan;
use Cieplik206\IntegrationOperations\ValueObjects\PollOutcome;

final class FakeAuthoritativePollingExtensions implements AuthoritativeReconciliationStrategy, ObservationProjectionPlanner, PollingStrategy
{
    public static bool $failOnConstruction = false;

    public static int $constructionAttempts = 0;

    public static bool $sendRequiredOnce = false;

    public static ?AuthoritativeReconciliationOutcome $reconciliationOutcome = null;

    public function __construct()
    {
        self::$constructionAttempts++;

        if (self::$failOnConstruction) {
            throw new \RuntimeException('Authoritative polling extension must not be constructed during application boot.');
        }
    }

    public function poll(PollingContext $context): PollOutcome
    {
        if (self::$sendRequiredOnce) {
            self::$sendRequiredOnce = false;

            return PollOutcome::sendRequired('fixture.send_required');
        }

        return PollOutcome::completed(
            new FakeAuthoritativeOperationResult('polled'),
            'fixture.poll_completed',
        );
    }

    public function plan(ObservationProjectionInput $input): ObservationProjectionPlan
    {
        return new ObservationProjectionPlan(1, []);
    }

    public function reconcile(AuthoritativeReconciliationContext $context): AuthoritativeReconciliationOutcome
    {
        return self::$reconciliationOutcome
            ?? AuthoritativeReconciliationOutcome::inconclusive('fixture.inconclusive');
    }
}
