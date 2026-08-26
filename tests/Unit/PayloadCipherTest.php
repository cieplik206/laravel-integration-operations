<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Crypto\BoundPayloadEnvelopeCodec;
use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Crypto\ConfigPayloadEncryptionKeyRing;
use Cieplik206\IntegrationOperations\Crypto\LaravelPayloadCipher;
use Cieplik206\IntegrationOperations\Crypto\PayloadReencrypter;
use Cieplik206\IntegrationOperations\Crypto\Sha256ContentHasher;
use Cieplik206\IntegrationOperations\Exceptions\PayloadDecryptionFailed;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Cieplik206\IntegrationOperations\ValueObjects\PayloadEnvelopeBinding;

function encryptionKey(string $seed): string
{
    return 'base64:'.base64_encode(hash('sha256', $seed, true));
}

/** @param array<int, string> $keys */
function payloadCipher(int $activeVersion, array $keys): LaravelPayloadCipher
{
    return new LaravelPayloadCipher(
        new ConfigPayloadEncryptionKeyRing($activeVersion, 'AES-256-GCM', $keys),
        new Sha256ContentHasher,
    );
}

it('encrypts authenticated envelopes bound to their operation metadata', function (): void {
    $cipher = payloadCipher(1, [1 => encryptionKey('current')]);
    $codec = new BoundPayloadEnvelopeCodec($cipher, new CanonicalJsonV1);
    $binding = new PayloadEnvelopeBinding(
        'payload',
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        1,
        3,
    );
    $body = new CanonicalObject(['secret_value' => 'never-log-this', 'count' => 2]);

    $envelope = $codec->encrypt($binding, $body);

    expect($envelope->cipher)->toBe('AES-256-GCM')
        ->and($envelope->ciphertext)->not->toContain('never-log-this')
        ->and($codec->decrypt($envelope, $binding)->values)->toEqual($body->values);
});

it('fails closed for ciphertext corruption and envelope swapping without leaking plaintext', function (): void {
    $cipher = payloadCipher(1, [1 => encryptionKey('current')]);
    $codec = new BoundPayloadEnvelopeCodec($cipher, new CanonicalJsonV1);
    $binding = new PayloadEnvelopeBinding(
        'context',
        new OperationId('01ARZ3NDEKTSV4RRFFQ69G5FAV'),
        1,
        1,
    );
    $envelope = $codec->encrypt($binding, new CanonicalObject(['value' => 'sensitive-plaintext']));
    $corrupted = new EncryptedEnvelope(
        $envelope->keyVersion,
        $envelope->cipher,
        $envelope->ciphertext.'x',
        $envelope->contentDigest,
    );
    $wrongCipher = new EncryptedEnvelope(
        $envelope->keyVersion,
        'AES-128-GCM',
        $envelope->ciphertext,
        $envelope->contentDigest,
    );
    $unknownKey = new EncryptedEnvelope(
        999,
        $envelope->cipher,
        $envelope->ciphertext,
        $envelope->contentDigest,
    );

    foreach ([
        fn () => $codec->decrypt($corrupted, $binding),
        fn () => $codec->decrypt($wrongCipher, $binding),
        fn () => $codec->decrypt($unknownKey, $binding),
        fn () => $codec->decrypt($envelope, new PayloadEnvelopeBinding(
            'payload',
            $binding->operationId,
            1,
            1,
        )),
        fn () => $codec->decrypt($envelope, new PayloadEnvelopeBinding(
            'context',
            new OperationId('01BX5ZZKBKACTAV9WEVGEMMVRZ'),
            1,
            1,
        )),
    ] as $decrypt) {
        try {
            $decrypt();
            $this->fail('A corrupted or swapped envelope was accepted.');
        } catch (PayloadDecryptionFailed $failure) {
            expect($failure->getMessage())->not->toContain('sensitive-plaintext')
                ->and($failure->getPrevious())->toBeNull();
        }
    }
});

it('reads old encryption versions and re-encrypts without exposing key material', function (): void {
    $oldCipher = payloadCipher(1, [1 => encryptionKey('old')]);
    $oldEnvelope = $oldCipher->encrypt('logical-plaintext');
    $rotatedCipher = payloadCipher(2, [
        1 => encryptionKey('old'),
        2 => encryptionKey('new'),
    ]);

    $rotatedEnvelope = new PayloadReencrypter($rotatedCipher)->reencrypt($oldEnvelope);

    expect($rotatedCipher->decrypt($oldEnvelope))->toBe('logical-plaintext')
        ->and($rotatedEnvelope->keyVersion)->toBe(2)
        ->and($rotatedCipher->decrypt($rotatedEnvelope))->toBe('logical-plaintext')
        ->and($rotatedEnvelope->ciphertext)->not->toBe($oldEnvelope->ciphertext)
        ->and($rotatedCipher->__debugInfo())->not->toHaveKey('keys');

    expect(fn () => serialize($rotatedCipher))->toThrow(LogicException::class)
        ->and(fn () => clone $rotatedCipher)->toThrow(LogicException::class);
});

it('rejects missing, malformed, and wrong-length encryption keys', function (array $keys): void {
    expect(fn () => new ConfigPayloadEncryptionKeyRing(1, 'AES-256-GCM', $keys))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'missing active key' => [[]],
    'not base64 tagged' => [[1 => str_repeat('x', 32)]],
    'invalid base64' => [[1 => 'base64:not-base64!']],
    'wrong key length' => [[1 => 'base64:'.base64_encode('short')]],
]);

it('redacts key material from every PHP object inspection path', function (): void {
    $rawKey = str_repeat('Q', 32);
    $ring = new ConfigPayloadEncryptionKeyRing(1, 'AES-256-GCM', [
        1 => 'base64:'.base64_encode($rawKey),
    ]);
    $cipher = new LaravelPayloadCipher($ring, new Sha256ContentHasher);

    ob_start();
    var_dump($ring, $cipher);
    $dump = (string) ob_get_clean();

    foreach ([$dump, var_export($ring, true), var_export($cipher, true), json_encode($ring), json_encode($cipher)] as $inspection) {
        expect($inspection)->not->toContain($rawKey)
            ->not->toContain(base64_encode($rawKey));
    }

    expect(fn () => serialize($ring))->toThrow(LogicException::class)
        ->and(fn () => clone $ring)->toThrow(LogicException::class)
        ->and(fn () => serialize($cipher))->toThrow(LogicException::class)
        ->and(fn () => clone $cipher)->toThrow(LogicException::class);
});
