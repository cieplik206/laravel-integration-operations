<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Contracts\OperationControl;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Enums\ManualResolutionDecision;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\ResolveManualOperation;
use Cieplik206\IntegrationOperations\ValueObjects\SafeOperationFailure;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Throwable;

/** @internal */
final class ResolveIntegrationOperationCommand extends Command
{
    protected $signature = 'integration-operations:resolve
        {operation : Operation ULID}
        {decision : reconcile, fail-permanently, or cancel}
        {--provider= : Required provider key}
        {--connection= : Required connection key}
        {--reason= : Required machine-readable audit reason}
        {--failure-code= : Required safe code for fail-permanently}
        {--failure-summary= : Required safe summary for fail-permanently}
        {--actor-category=operator : Audit actor category}
        {--actor-reference= : Optional actor reference stored only as an HMAC}';

    protected $description = 'Apply one audited manual-review decision without exposing a generic force-retry path';

    public function handle(Application $app): int
    {
        try {
            $command = $this->command();
            $operations = $app->make(OperationQuery::class);
            $before = $operations->within($command->scope)->find($command->operationId);

            if ($before === null || $before->status() !== OperationStatus::ManualReview) {
                $this->components->warn('The operation is not visible in manual review inside the requested scope.');

                return self::INVALID;
            }

            $receipt = $app->make(OperationControl::class)->resolveManual($command);
            $after = $operations->within($command->scope)->find($command->operationId);
        } catch (InvalidArgumentException) {
            $this->components->error('Scope, operation ID, decision, evidence, reason, or actor is invalid.');

            return self::INVALID;
        } catch (Throwable) {
            $this->components->error('Manual resolution could not be completed safely.');

            return self::FAILURE;
        }

        if (! $receipt->operationId->equals($command->operationId) || $after === null) {
            $this->components->error('Manual resolution could not be verified safely.');

            return self::FAILURE;
        }

        $this->components->info("Manual resolution finished with status: {$after->status()->value}.");

        return self::SUCCESS;
    }

    private function command(): ResolveManualOperation
    {
        $provider = $this->option('provider');
        $connection = $this->option('connection');
        $operation = $this->argument('operation');
        $reason = $this->option('reason');
        $actorCategory = $this->option('actor-category');
        $actorReference = $this->option('actor-reference');

        if (! is_string($provider) || $provider === ''
            || ! is_string($connection) || $connection === ''
            || ! is_string($operation)
            || ! is_string($reason) || $reason === ''
            || ! is_string($actorCategory) || $actorCategory === ''
            || ($actorReference !== null && ! is_string($actorReference))) {
            throw new InvalidArgumentException;
        }

        $decision = $this->decision();

        return new ResolveManualOperation(
            scope: IntegrationScope::of($provider, $connection),
            operationId: new OperationId($operation),
            decision: $decision,
            reasonCode: $reason,
            actor: new OperationActor($actorCategory, $actorReference),
            safeFailure: $this->safeFailure($decision),
        );
    }

    private function decision(): ManualResolutionDecision
    {
        return match ($this->argument('decision')) {
            'reconcile' => ManualResolutionDecision::Reconcile,
            'fail-permanently' => ManualResolutionDecision::ConfirmFailed,
            'cancel' => ManualResolutionDecision::Cancel,
            default => throw new InvalidArgumentException,
        };
    }

    private function safeFailure(ManualResolutionDecision $decision): ?SafeOperationFailure
    {
        $code = $this->option('failure-code');
        $summary = $this->option('failure-summary');

        if ($decision !== ManualResolutionDecision::ConfirmFailed) {
            if ($code !== null || $summary !== null) {
                throw new InvalidArgumentException;
            }

            return null;
        }

        if (! is_string($code) || ! is_string($summary)) {
            throw new InvalidArgumentException;
        }

        return new SafeOperationFailure($code, $summary);
    }
}
