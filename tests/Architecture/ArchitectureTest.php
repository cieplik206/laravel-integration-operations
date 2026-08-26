<?php

declare(strict_types=1);
use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Registry\ManagedMutationIdentityContract;
use Cieplik206\IntegrationOperations\Registry\OperationDefinition;
use Cieplik206\IntegrationOperations\Registry\TerminalContract;
use Cieplik206\IntegrationOperations\Testing\Conformance\ConformanceReport;
use Cieplik206\IntegrationOperations\Testing\Conformance\ProviderConformanceFailed;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;

/** @return list<string> */
function kernelPhpSources(): array
{
    $paths = [];
    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator(dirname(__DIR__, 2).'/src', FilesystemIterator::SKIP_DOTS),
    );

    foreach ($iterator as $file) {
        if ($file instanceof SplFileInfo && $file->getExtension() === 'php') {
            $paths[] = $file->getPathname();
        }
    }

    sort($paths, SORT_STRING);

    return $paths;
}

it('uses strict types and the canonical package namespace in every source file', function (): void {
    foreach (kernelPhpSources() as $path) {
        $source = file_get_contents($path);

        expect($source)->not->toBeFalse()
            ->and((string) $source)->toContain('declare(strict_types=1);')
            ->toContain('namespace Cieplik206\IntegrationOperations');
    }
});

it('does not import providers, applications, or HTTP clients into the kernel', function (): void {
    $source = implode("\n", array_map(
        fn (string $path): string => (string) file_get_contents($path),
        kernelPhpSources(),
    ));

    expect($source)
        ->not->toContain('use App\\')
        ->not->toContain('Fakturownia\\')
        ->not->toContain('Saloon\\')
        ->not->toContain('GuzzleHttp\\');
});

it('keeps the public conformance kit independent from Pest', function (): void {
    $path = dirname(__DIR__, 2).'/src/Testing/Conformance';
    $source = '';

    foreach (kernelPhpSources() as $sourcePath) {
        if (str_starts_with($sourcePath, $path)) {
            $source .= (string) file_get_contents($sourcePath);
        }
    }

    expect($source)->not->toContain('Pest\\')->not->toContain('PHPUnit\\');
});

it('does not export runtime lease capabilities through public API or conformance sources', function (): void {
    $conformancePath = dirname(__DIR__, 2).'/src/Testing/Conformance';
    $exportedSource = '';

    foreach (kernelPhpSources() as $sourcePath) {
        $source = (string) file_get_contents($sourcePath);

        if (str_starts_with($sourcePath, $conformancePath) || str_contains($source, '/** @api */')) {
            $exportedSource .= $source;
        }
    }

    foreach ([
        'OperationLeaseManager',
        'DatabaseOperationLeaseManager',
        'LeaseClaim',
        'LeaseRecoveryCursor',
        'LeaseRecoveryBatch',
        'LeaseRecoveryIncident',
    ] as $internalSymbol) {
        expect($exportedSource)->not->toContain($internalSymbol);
    }
});

it('declares the ULID implementation directly and no provider transport dependency', function (): void {
    $composer = json_decode(
        (string) file_get_contents(dirname(__DIR__, 2).'/composer.json'),
        true,
        flags: JSON_THROW_ON_ERROR,
    );

    expect($composer)->toHaveKey('require.symfony/uid', '^7.4|^8.0')
        ->toHaveKey('require.illuminate/container', '^13.0')
        ->toHaveKey('require-dev.symfony/var-dumper', '^7.4|^8.0')
        ->and($composer['require'])->not->toHaveKeys(['saloonphp/saloon', 'guzzlehttp/guzzle'])
        ->and($composer)->toHaveKey('autoload.psr-4.Cieplik206\\IntegrationOperations\\', 'src/')
        ->toHaveKey(
            'scripts.test:pgsql',
            'pest --fail-on-skipped --fail-on-empty-test-suite tests/Feature/Postgres',
        )
        ->toHaveKey('scripts.check.4', '@test:pgsql')
        ->toHaveKey('extra.laravel.providers', [
            'Cieplik206\\IntegrationOperations\\IntegrationOperationsServiceProvider',
        ]);
});

it('routes every immutable array holder through the shared bounded sanitizer', function (string $class): void {
    if (! class_exists($class)) {
        throw new RuntimeException("Immutable array holder {$class} does not exist.");
    }

    $reflection = new ReflectionClass($class);
    $path = $reflection->getFileName();

    if ($path === false) {
        throw new RuntimeException("Unable to inspect immutable array holder {$class}.");
    }

    $source = file_get_contents($path);

    expect($source)->not->toBeFalse()
        ->and((string) $source)->toContain('ImmutableValueSanitizer::');
})->with([
    IntegrationContext::class,
    IntegrationContextConstraints::class,
    CanonicalObject::class,
    OperationDefinition::class,
    ManagedMutationIdentityContract::class,
    TerminalContract::class,
    ConformanceReport::class,
    ProviderConformanceFailed::class,
    EncodedResult::class,
]);
