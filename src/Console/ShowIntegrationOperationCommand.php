<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Contracts\OperationQuery;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use InvalidArgumentException;
use Throwable;

/** @internal */
final class ShowIntegrationOperationCommand extends Command
{
    protected $signature = 'integration-operations:show
        {operation : Operation ULID}
        {--provider= : Required provider key}
        {--connection= : Required connection key}';

    protected $description = 'Show safe metadata for one integration operation inside an exact provider connection scope';

    public function handle(Application $app): int
    {
        try {
            $scope = $this->scope();
            $operation = $this->argument('operation');

            if (! is_string($operation)) {
                throw new InvalidArgumentException;
            }

            $operations = $app->make(OperationQuery::class);
            $snapshot = $operations->within($scope)->find(new OperationId($operation));
        } catch (InvalidArgumentException) {
            $this->components->error('Provider, connection, or operation ID is invalid.');

            return self::INVALID;
        } catch (Throwable) {
            $this->components->error('Integration operation metadata could not be read safely.');

            return self::FAILURE;
        }

        if ($snapshot === null) {
            $this->components->warn('No operation is visible inside the requested scope.');

            return self::INVALID;
        }

        $failure = $snapshot->safeFailure();
        $this->table(['field', 'value'], [
            ['operation_id', $snapshot->operationId->value],
            ['provider', $snapshot->provider()->value],
            ['connection', $snapshot->connectionKey()->value],
            ['operation_type', $snapshot->operationType()->value],
            ['status', $snapshot->status()->value],
            ['disposition', $snapshot->disposition()->value],
            ['result_availability', $snapshot->resultAvailability()->value],
            ['safe_failure_code', $failure->code ?? '-'],
            ['safe_failure_summary', $failure->summary ?? '-'],
        ]);

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
}
