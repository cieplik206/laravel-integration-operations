<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Retention\OperationRetentionPolicy;

it('enforces the minimum retention ordering and terminal tombstone lifetime', function (): void {
    expect(fn () => new OperationRetentionPolicy(0, 365, 1825, 500))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationRetentionPolicy(30, 29, 1825, 500))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationRetentionPolicy(30, 365, 1824, 500))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn () => new OperationRetentionPolicy(30, 365, 1825, 5001))
        ->toThrow(InvalidArgumentException::class);
});

it('calculates all retention cutoffs from one immutable instant', function (): void {
    $policy = new OperationRetentionPolicy(30, 365, 1825, 500);
    $now = new DateTimeImmutable('2035-08-27 12:00:00+00:00');

    expect($policy->rawPayloadCutoff($now)->format(DATE_ATOM))->toBe('2035-07-28T12:00:00+00:00')
        ->and($policy->attemptDiagnosticsCutoff($now)->format(DATE_ATOM))->toBe('2034-08-27T12:00:00+00:00')
        ->and($policy->terminalTombstoneCutoff($now)->format(DATE_ATOM))->toBe('2030-08-28T12:00:00+00:00')
        ->and($now->format(DATE_ATOM))->toBe('2035-08-27T12:00:00+00:00');
});
