<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\VersionedHmacDigest;
use InvalidArgumentException;
use LogicException;

/** @internal */
final readonly class DatabaseWriterFenceAliasSet
{
    /** @var array<int, string> */
    private array $digestsByKeyVersion;

    /**
     * @param  array<array-key, string>  $digestsByKeyVersion
     */
    public function __construct(array $digestsByKeyVersion)
    {
        $validated = [];

        foreach ($digestsByKeyVersion as $keyVersion => $digest) {
            if (! is_int($keyVersion)
                || $keyVersion < 1
                || preg_match('/^[a-f0-9]{64}$/D', $digest) !== 1) {
                throw new InvalidArgumentException('Writer-fence alias set is invalid.');
            }

            $validated[$keyVersion] = $digest;
        }

        $this->digestsByKeyVersion = $validated;
    }

    public function isEmpty(): bool
    {
        return $this->digestsByKeyVersion === [];
    }

    /** @param list<VersionedHmacDigest> $digests */
    public static function fromDigests(array $digests): self
    {
        $byVersion = [];

        foreach ($digests as $digest) {
            if (array_key_exists($digest->keyVersion, $byVersion)) {
                throw new InvalidArgumentException('Writer-fence alias versions must be unique.');
            }

            $byVersion[$digest->keyVersion] = $digest->hex;
        }

        ksort($byVersion, SORT_NUMERIC);

        return new self($byVersion);
    }

    public static function empty(): self
    {
        return new self([]);
    }

    public function matches(int $keyVersion, string $digest): bool
    {
        $expected = $this->digestsByKeyVersion[$keyVersion] ?? null;

        return is_string($expected) && hash_equals($expected, $digest);
    }

    public function containsKeyVersion(int $keyVersion): bool
    {
        return array_key_exists($keyVersion, $this->digestsByKeyVersion);
    }

    public function isSubsetOf(self $other): bool
    {
        foreach ($this->digestsByKeyVersion as $keyVersion => $digest) {
            if (! $other->matches($keyVersion, $digest)) {
                return false;
            }
        }

        return true;
    }

    /** @return list<VersionedHmacDigest> */
    public function missingFrom(self $present): array
    {
        $missing = [];

        foreach ($this->digestsByKeyVersion as $keyVersion => $digest) {
            if ($present->matches($keyVersion, $digest)) {
                continue;
            }

            $missing[] = new VersionedHmacDigest(
                $keyVersion,
                LookupHmacDomain::Cohort,
                $digest,
            );
        }

        return $missing;
    }

    public function equals(self $other): bool
    {
        if (array_keys($this->digestsByKeyVersion) !== array_keys($other->digestsByKeyVersion)) {
            return false;
        }

        foreach ($this->digestsByKeyVersion as $keyVersion => $digest) {
            if (! $other->matches($keyVersion, $digest)) {
                return false;
            }
        }

        return true;
    }

    /** @return array{aliases: string} */
    public function __debugInfo(): array
    {
        return ['aliases' => '[REDACTED]'];
    }

    /** @return never */
    public function __serialize(): array
    {
        throw new LogicException('Writer-fence alias sets cannot be serialized.');
    }

    /** @param array<string, mixed> $properties */
    public static function __set_state(array $properties): never
    {
        throw new LogicException('Writer-fence alias sets cannot be exported.');
    }

    public function __clone(): void
    {
        throw new LogicException('Writer-fence alias sets cannot be cloned.');
    }
}
