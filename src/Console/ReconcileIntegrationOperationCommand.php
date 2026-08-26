<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Contracts\OperationProcessor;
use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Throwable;

/** @internal */
final class ReconcileIntegrationOperationCommand extends Command
{
    protected $signature = 'integration-operations:reconcile
        {operation : Operation ULID}
        {--provider= : Required provider key}
        {--connection= : Required connection key}';

    protected $description = 'Run the registered reconciliation strategy for one uncertain scoped operation';

    public function handle(Application $app): int
    {
        try {
            $scope = $this->scope();
            $operationId = $this->operationId();
            $operations = $app->make(OperationQuery::class);
            $before = $operations->within($scope)->find($operationId);

            if ($before === null || $before->status() !== OperationStatus::Uncertain) {
                $this->components->warn('The operation is not visible as uncertain inside the requested scope.');

                return self::INVALID;
            }

            $app->make(OperationProcessor::class)->process($operationId);
            $after = $operations->within($scope)->find($operationId);
        } catch (InvalidArgumentException) {
            $this->components->error('Provider, connection, or operation ID is invalid.');

            return self::INVALID;
        } catch (Throwable) {
            $this->components->error('Integration operation reconciliation could not be completed safely.');

            return self::FAILURE;
        }

        if ($after === null) {
            $this->components->error('Integration operation reconciliation could not be verified safely.');

            return self::FAILURE;
        }

        $this->components->info("Reconciliation finished with status: {$after->status()->value}.");

        return self::SUCCESS;
    }

    private function scope(): IntegrationScope
    {
        $provider = $this->option('provider');
        $connection = $this->option('connection');

        if (! is_string($provider) || $provider === '' || ! is_string($connection) || $connection === '') {
            throw new InvalidArgumentException;
        }

        return IntegrationScope::of($provider, $connection);
    }

    private function operationId(): OperationId
    {
        $operation = $this->argument('operation');

        if (! is_string($operation)) {
            throw new InvalidArgumentException;
        }

        return new OperationId($operation);
    }
}
