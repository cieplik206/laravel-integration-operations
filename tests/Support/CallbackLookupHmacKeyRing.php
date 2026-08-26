<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Cieplik206\IntegrationOperations\Contracts\LookupHmacKeyRing;
use Closure;

final class CallbackLookupHmacKeyRing implements LookupHmacKeyRing
{
    private bool $called = false;

    /** @param Closure(): void $beforeFirstUse */
    public function __construct(
        private readonly LookupHmacKeyRing $delegate,
        private readonly Closure $beforeFirstUse,
    ) {}

    public function activeVersion(): int
    {
        $this->invokeOnce();

        return $this->delegate->activeVersion();
    }

    public function readableVersions(): array
    {
        $this->invokeOnce();

        return $this->delegate->readableVersions();
    }

    public function hmacSha256(int $version, string $message): string
    {
        $this->invokeOnce();

        return $this->delegate->hmacSha256($version, $message);
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
