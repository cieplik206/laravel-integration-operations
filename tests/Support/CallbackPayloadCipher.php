<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\PayloadCipher;
use Cieplik206\IntegrationOperations\ValueObjects\EncryptedEnvelope;
use Closure;

final class CallbackPayloadCipher implements PayloadCipher
{
    private bool $called = false;

    /** @param Closure(): void $beforeFirstUse */
    public function __construct(
        private readonly PayloadCipher $delegate,
        private readonly Closure $beforeFirstUse,
    ) {}

    public function encrypt(string $plaintext): EncryptedEnvelope
    {
        $this->invokeOnce();

        return $this->delegate->encrypt($plaintext);
    }

    public function decrypt(EncryptedEnvelope $envelope): string
    {
        $this->invokeOnce();

        return $this->delegate->decrypt($envelope);
    }

    private function invokeOnce(): void
    {
        if ($this->called) {
            return;
        }

        $this->called = true;
        ($this->beforeFirstUse)();
    }
}
