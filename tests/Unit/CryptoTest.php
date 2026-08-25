<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\ConfigLookupHmacKeyRing;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Crypto\Sha256ContentHasher;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Symfony\Component\VarDumper\Cloner\VarCloner;
use Symfony\Component\VarDumper\Dumper\CliDumper;

it('creates deterministic domain-separated versioned HMAC digests', function (): void {
    $ring = new ConfigLookupHmacKeyRing(2, [
        1 => str_repeat('a', 32),
        2 => 'base64:'.base64_encode(str_repeat('b', 32)),
    ]);
    $hmac = new HmacSha256($ring, new CanonicalJsonV1);

    $intent = $hmac->digest(LookupHmacDomain::Intent, 'same-material');
    $payload = $hmac->digest(LookupHmacDomain::Payload, 'same-material');
    $readable = $hmac->readableDigests(LookupHmacDomain::Intent, 'same-material');

    expect($intent->keyVersion)->toBe(2)
        ->and($intent->hex)->toHaveLength(64)
        ->and($intent->hex)->not->toBe($payload->hex)
        ->and(array_map(fn ($digest): int => $digest->keyVersion, $readable))->toBe([1, 2])
        ->and($hmac->digestCanonical(LookupHmacDomain::Payload, ['b' => 2, 'a' => 1])->hex)
        ->toBe($hmac->digestCanonical(LookupHmacDomain::Payload, ['a' => 1, 'b' => 2])->hex);
});

it('keeps raw HMAC key material out of debugging and serialization', function (): void {
    $secret = str_repeat('never-print-this-key-', 2);
    $ring = new ConfigLookupHmacKeyRing(1, [1 => $secret]);
    $dumpStream = fopen('php://memory', 'w+');
    ob_start();
    var_dump($ring);
    $nativeDump = ob_get_clean();

    if ($dumpStream === false) {
        throw new RuntimeException('Unable to open the in-memory dump stream.');
    }

    if ($nativeDump === false) {
        throw new RuntimeException('Unable to capture the native object dump.');
    }

    (new CliDumper)->dump((new VarCloner)->cloneVar($ring), $dumpStream);
    rewind($dumpStream);
    $symfonyDump = stream_get_contents($dumpStream);

    if ($symfonyDump === false) {
        throw new RuntimeException('Unable to read the in-memory Symfony dump.');
    }

    expect(get_class_methods($ring))->not->toContain('keyFor')
        ->and($nativeDump)->not->toContain($secret)
        ->and(var_export($ring, true))->not->toContain($secret)
        ->and($symfonyDump)->not->toContain($secret)
        ->and(fn (): string => serialize($ring))->toThrow(LogicException::class)
        ->and(fn (): ConfigLookupHmacKeyRing => clone $ring)->toThrow(LogicException::class);
});

it('requires independent HMAC keys with at least 32 bytes', function (): void {
    expect(fn (): ConfigLookupHmacKeyRing => new ConfigLookupHmacKeyRing(1, [1 => 'short']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): ConfigLookupHmacKeyRing => new ConfigLookupHmacKeyRing(2, [1 => str_repeat('a', 32)]))
        ->toThrow(InvalidArgumentException::class);
});

it('hashes ciphertext and artifacts with plain content SHA-256', function (): void {
    expect((new Sha256ContentHasher)->hash('abc')->hex)
        ->toBe('ba7816bf8f01cfea414140de5dae2223b00361a396177a9cb410ff61f20015ad');
});
