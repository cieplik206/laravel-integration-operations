<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Enums\LeasePurpose;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

/** @return array{invalid_argument: bool, public_text_redacted: bool, complete_trace_redacted: bool} */
function invalidLeaseClaimSecurityReport(): array
{
    $token = hash('sha256', 'unique-invalid-lease-capability');

    try {
        new LeaseClaim(
            new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
            IntegrationScope::of('fixture_catalog', 'tenant:1'),
            LeasePurpose::Execute,
            'worker:1',
            $token,
            0,
        );
    } catch (Throwable $failure) {
        return [
            'invalid_argument' => $failure instanceof InvalidArgumentException,
            'public_text_redacted' => ! str_contains((string) $failure, $token),
            'complete_trace_redacted' => ! str_contains(var_export($failure->getTrace(), true), $token),
        ];
    }

    throw new RuntimeException('The invalid lease claim fixture did not fail.');
}

it('never exposes the lease capability through diagnostic or serialization surfaces', function (): void {
    $token = str_repeat('a', 64);
    $claim = new LeaseClaim(
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        LeasePurpose::Execute,
        'worker:1',
        $token,
        2,
    );

    ob_start();
    var_dump($claim);
    $dump = ob_get_clean();

    expect($dump)->toBeString()
        ->not->toContain($token)
        ->and(var_export($claim, true))->not->toContain($token)
        ->and(json_encode($claim, JSON_THROW_ON_ERROR))->not->toContain($token)
        ->and(fn () => serialize($claim))->toThrow(LogicException::class)
        ->and(fn () => clone $claim)->toThrow(LogicException::class)
        ->and($claim->withRowVersion(3)->rowVersion)->toBe(3);
});

it('redacts the lease capability from constructor exceptions and traces', function (): void {
    expect(invalidLeaseClaimSecurityReport())->toBe([
        'invalid_argument' => true,
        'public_text_redacted' => true,
        'complete_trace_redacted' => true,
    ]);
});
