<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;

it('canonicalizes maps bytewise while preserving list order', function (): void {
    $canonicalJson = new CanonicalJsonV1;

    expect($canonicalJson->encode([
        'z' => [3, 2, 1],
        'a' => ['z' => 2, 'a' => 1],
        'unicode' => 'zażółć',
    ]))->toBe('{"a":{"a":1,"z":2},"unicode":"zażółć","z":[3,2,1]}')
        ->and($canonicalJson->encode([]))->toBe('[]')
        ->and($canonicalJson->encode(CanonicalObject::empty()))->toBe('{}');
});

it('rejects values outside the canonical JSON subset', function (mixed $value): void {
    expect(fn (): string => (new CanonicalJsonV1)->encode($value))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'float' => 1.5,
    'object' => new stdClass,
]);

it('distinguishes canonical empty lists from explicit empty objects', function (): void {
    $canonicalJson = new CanonicalJsonV1;

    expect($canonicalJson->encode(['value' => []]))->toBe('{"value":[]}')
        ->and($canonicalJson->encode(['value' => CanonicalObject::empty()]))->toBe('{"value":{}}');
});

it('fails closed on referenced, cyclic, invalid UTF-8, deep, and oversized input', function (): void {
    $external = 'before';
    $referenced = ['value' => &$external];
    $cycle = [];
    $cycle['self'] = &$cycle;
    $deep = 'leaf';

    for ($depth = 0; $depth < 66; $depth++) {
        $deep = [$deep];
    }

    $canonicalJson = new CanonicalJsonV1;

    expect(fn (): string => $canonicalJson->encode($referenced))
        ->toThrow(InvalidArgumentException::class, 'references')
        ->and(fn (): string => $canonicalJson->encode($cycle))
        ->toThrow(InvalidArgumentException::class, 'references')
        ->and(fn (): string => $canonicalJson->encode(["invalid\xFF" => 'value']))
        ->toThrow(InvalidArgumentException::class, 'valid UTF-8')
        ->and(fn (): string => $canonicalJson->encode(['value' => "invalid\xFF"]))
        ->toThrow(InvalidArgumentException::class, 'valid UTF-8')
        ->and(fn (): string => $canonicalJson->encode($deep))
        ->toThrow(InvalidArgumentException::class, 'validation bounds')
        ->and(fn (): string => $canonicalJson->encode(array_fill(0, 10_001, 1)))
        ->toThrow(InvalidArgumentException::class, 'validation bounds');
});
