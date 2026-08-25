<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Context;

use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class IntegrationContextConstraints
{
    /** @var list<string> */
    public array $reservedKeyFragments;

    /**
     * @param  list<string>  $reservedKeyFragments
     */
    public function __construct(
        public int $maximumAttributes = 24,
        public int $maximumBytes = 4096,
        public int $maximumKeyBytes = 64,
        public int $maximumStringBytes = 512,
        public int $maximumCorrelationIdBytes = 255,
        array $reservedKeyFragments = [
            'token',
            'password',
            'secret',
            'credential',
            'authorization',
            'api_key',
            'email',
            'tax_id',
            'nip',
            'pesel',
            'phone',
            'address',
        ],
    ) {
        if ($maximumAttributes < 1 || $maximumBytes < 1 || $maximumKeyBytes < 1 || $maximumStringBytes < 1 || $maximumCorrelationIdBytes < 1) {
            throw new InvalidArgumentException('Integration context limits must be positive.');
        }

        $this->reservedKeyFragments = ImmutableValueSanitizer::stringList(
            $reservedKeyFragments,
            'Integration context reserved key fragments',
        );

        if ($this->reservedKeyFragments === []) {
            throw new InvalidArgumentException('Integration context reserved key fragments must not be empty.');
        }

        foreach ($this->reservedKeyFragments as $fragment) {
            if ($fragment === '' || preg_match('/^[a-z][a-z0-9_]*$/D', $fragment) !== 1) {
                throw new InvalidArgumentException('Integration context reserved key fragments are invalid.');
            }
        }
    }
}
