<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\TerminalProofKind;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class TerminalOutcomeContract
{
    private const int MaximumPairs = 16;

    /** @var list<TerminalOutcomePair> */
    public array $pairs;

    /** @param list<TerminalOutcomePair> $pairs */
    public function __construct(array $pairs)
    {
        $pairs = ImmutableValueSanitizer::objectList(
            $pairs,
            TerminalOutcomePair::class,
            'Terminal outcome pairs',
        );

        if ($pairs === [] || count($pairs) > self::MaximumPairs) {
            throw new InvalidArgumentException('Terminal outcome contract must contain a bounded non-empty pair list.');
        }

        $keys = [];

        foreach ($pairs as $pair) {
            if (isset($keys[$pair->key()])) {
                throw new InvalidArgumentException('Terminal outcome contract pairs must be typed and unique.');
            }

            $keys[$pair->key()] = true;
        }

        usort($pairs, static fn (TerminalOutcomePair $left, TerminalOutcomePair $right): int => $left->key() <=> $right->key());
        $this->pairs = $pairs;
    }

    public function allows(TerminalOutcomePair $candidate, TerminalProofKind $proofKind): bool
    {
        foreach ($this->pairs as $pair) {
            if ($pair->key() === $candidate->key() && in_array($proofKind, $pair->proofKinds, true)) {
                return true;
            }
        }

        return false;
    }
}
