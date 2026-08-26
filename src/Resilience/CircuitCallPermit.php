<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Resilience;

use InvalidArgumentException;
use LogicException;
use SensitiveParameter;
use SensitiveParameterValue;

/** @api */
final readonly class CircuitCallPermit
{
    private SensitiveParameterValue $sensitiveToken;

    /** @internal */
    public function __construct(
        public string $scopeFingerprint,
        public RemoteCallKind $kind,
        public string $policyFingerprint,
        public int $generation,
        public int $expiresAtMilliseconds,
        #[SensitiveParameter]
        string $token,
    ) {
        if (preg_match('/^[a-f0-9]{64}$/D', $scopeFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $policyFingerprint) !== 1
            || preg_match('/^[a-f0-9]{64}$/D', $token) !== 1
            || $generation < 1 || $expiresAtMilliseconds < 1) {
            throw new InvalidArgumentException('Circuit call permit is invalid.');
        }

        $this->sensitiveToken = new SensitiveParameterValue($token);
    }

    /** @internal */
    public function tokenHash(): string
    {
        $token = $this->sensitiveToken->getValue();

        if (! is_string($token)) {
            throw new LogicException('Circuit call permit token storage is corrupted.');
        }

        return hash('sha256', "integration-operations:circuit-call:v1\0".$token);
    }

    /** @return array{scope: string, kind: string, policy: string, generation: int, expires_at_ms: int, token: string} */
    public function __debugInfo(): array
    {
        return [
            'scope' => $this->scopeFingerprint,
            'kind' => $this->kind->value,
            'policy' => $this->policyFingerprint,
            'generation' => $this->generation,
            'expires_at_ms' => $this->expiresAtMilliseconds,
            'token' => '[REDACTED]',
        ];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Circuit call permits cannot be serialized.');
    }

    /** @param array<string, mixed> $data */
    public function __unserialize(array $data): never
    {
        throw new LogicException('Circuit call permits cannot be unserialized.');
    }

    public function __wakeup(): never
    {
        throw new LogicException('Circuit call permits cannot be unserialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Circuit call permits cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Circuit call permits cannot be cloned.');
    }
}
