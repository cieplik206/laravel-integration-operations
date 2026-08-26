<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\ReplacePendingOperation;

it('keeps integration context outside the pending replacement command', function (): void {
    $parameters = array_map(
        static fn (ReflectionParameter $parameter): string => $parameter->getName(),
        (new ReflectionClass(ReplacePendingOperation::class))->getConstructor()?->getParameters() ?? [],
    );

    expect($parameters)->toBe([
        'scope',
        'expectedCurrentOperationId',
        'expectedPayloadRevision',
        'payload',
        'actor',
    ]);

    $command = new ReplacePendingOperation(
        IntegrationScope::of('fixture_catalog', 'tenant:1'),
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        1,
        new CanonicalObject(['record' => 42]),
    );

    expect($command->expectedPayloadRevision)->toBe(1);
});

it('redacts the actor reference from all diagnostic surfaces', function (): void {
    $reference = 'operator-secret-reference';
    $actor = new OperationActor('operator', $reference);

    ob_start();
    var_dump($actor);
    $dump = ob_get_clean();

    expect($dump)->toBeString()
        ->not->toContain($reference)
        ->and(var_export($actor, true))->not->toContain($reference)
        ->and(json_encode($actor, JSON_THROW_ON_ERROR))->not->toContain($reference)
        ->and(fn () => serialize($actor))->toThrow(LogicException::class)
        ->and(fn () => clone $actor)->toThrow(LogicException::class);
});
