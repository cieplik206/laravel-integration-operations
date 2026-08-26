<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Console;

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Contracts\PayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Persistence\KernelDatabase;
use Cieplik206\IntegrationOperations\Registry\DefinitionRegistry;
use Cieplik206\IntegrationOperations\Runtime\ConfiguredIntegrationScopes;
use Illuminate\Console\Command;
use Illuminate\Contracts\Foundation\Application;
use Throwable;

/** @internal */
final class DoctorIntegrationOperationsCommand extends Command
{
    /** @var list<string> */
    private const array REQUIRED_TABLES = [
        'integration_operation_intents',
        'integration_operations',
        'integration_operation_payloads',
        'integration_operation_results',
        'integration_operation_attempts',
        'integration_operation_transitions',
    ];

    protected $signature = 'integration-operations:doctor';

    protected $description = 'Verify integration operation infrastructure without revealing credentials or payloads';

    public function handle(Application $app): int
    {
        $checks = [];
        $failed = false;

        $this->check($checks, $failed, 'database', function () use ($app): string {
            $database = $app->make(KernelDatabase::class);

            $connection = $database->connection();

            if ($connection->getDriverName() !== 'pgsql') {
                throw new \LogicException;
            }

            $schema = $connection->getSchemaBuilder();

            foreach (self::REQUIRED_TABLES as $table) {
                if (! $schema->hasTable($table)) {
                    throw new \LogicException;
                }
            }

            return 'PostgreSQL and required kernel tables are available.';
        });
        $this->check($checks, $failed, 'registries', function () use ($app): string {
            $definitions = $app->make(DefinitionRegistry::class);
            if (! $definitions->isFrozen()) {
                throw new \LogicException;
            }

            return sprintf(
                '%d runtime definitions are frozen.',
                count($definitions->all()),
            );
        });
        $this->check($checks, $failed, 'cryptography', function () use ($app): string {
            $hmacKeys = $app->make(LookupHmacKeyRing::class);
            $encryptionKeys = $app->make(PayloadEncryptionKeyRing::class);

            if (! in_array($hmacKeys->activeVersion(), $hmacKeys->readableVersions(), true)
                || ! in_array($encryptionKeys->activeVersion(), $encryptionKeys->readableVersions(), true)) {
                throw new \LogicException;
            }

            return sprintf(
                'Active HMAC v%d and payload encryption v%d (%s) are readable.',
                $hmacKeys->activeVersion(),
                $encryptionKeys->activeVersion(),
                $encryptionKeys->cipher(),
            );
        });
        $this->check($checks, $failed, 'scheduler', function () use ($app): string {
            $configuredScopes = $app->make(ConfiguredIntegrationScopes::class);
            $config = $app->make('config');

            $enabled = $config->get('integration-operations.scheduler.enabled', true);

            if (! is_bool($enabled)) {
                throw new \LogicException;
            }

            $scopes = $configuredScopes->all();

            if ($enabled && $scopes === []) {
                throw new \LogicException;
            }

            return $enabled
                ? sprintf('%d provider connection scopes are allowlisted.', count($scopes))
                : 'Scheduler is explicitly disabled.';
        });

        $this->table(['check', 'status', 'detail'], $checks);

        if ($failed) {
            $this->components->error('Integration operations doctor found blocking configuration or infrastructure errors.');

            return self::FAILURE;
        }

        $this->components->info('Integration operations infrastructure is ready.');

        return self::SUCCESS;
    }

    /**
     * @param  list<array{string, string, string}>  $checks
     * @param  callable(): string  $probe
     */
    private function check(array &$checks, bool &$failed, string $name, callable $probe): void
    {
        try {
            $checks[] = [$name, 'ok', $probe()];
        } catch (Throwable) {
            $failed = true;
            $checks[] = [$name, 'failed', 'The check failed; sensitive failure details were withheld.'];
        }
    }
}
