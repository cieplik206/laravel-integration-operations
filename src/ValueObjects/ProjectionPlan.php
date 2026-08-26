<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\ValueObjects;

use Cieplik206\IntegrationOperations\Crypto\CanonicalJsonV1;
use Cieplik206\IntegrationOperations\Crypto\CanonicalObject;
use Cieplik206\IntegrationOperations\Registry\ProjectionContract;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class ProjectionPlan
{
    private const int MaximumMutations = 8;

    private const int MaximumPlanBytes = 262_144;

    /** @var list<ProjectionMutation> */
    public array $mutations;

    /** @param list<ProjectionMutation> $mutations */
    public function __construct(
        public int $schemaVersion,
        array $mutations,
    ) {
        if ($schemaVersion < 1 || $schemaVersion > 65_535) {
            throw new InvalidArgumentException('Projection plan schema version is invalid.');
        }

        $mutations = ImmutableValueSanitizer::objectList(
            $mutations,
            ProjectionMutation::class,
            'Projection plan mutations',
        );

        if (count($mutations) > self::MaximumMutations) {
            throw new InvalidArgumentException('Projection plan has too many mutations.');
        }

        $addresses = [];
        $ordered = [];
        $canonicalJson = new CanonicalJsonV1;

        foreach ($mutations as $mutation) {
            $address = $mutation->targetId.'|'.$canonicalJson->encode(new CanonicalObject($mutation->identity));

            if (isset($addresses[$address])) {
                throw new InvalidArgumentException('Projection plan contains a duplicate mutation address.');
            }

            $addresses[$address] = true;
            $ordered[] = ['address' => $address, 'mutation' => $mutation];
        }

        usort($ordered, static fn (array $left, array $right): int => $left['address'] <=> $right['address']);
        $mutations = [];
        $encoded = [];

        foreach ($ordered as $item) {
            $mutation = $item['mutation'];
            $mutations[] = $mutation;
            $encoded[] = [
                'target' => $mutation->targetId,
                'identity' => $mutation->identity,
                'expected_version' => $mutation->expectedVersion,
                'values' => $mutation->values,
            ];
        }

        if (strlen($canonicalJson->encode([
            'schema_version' => $this->schemaVersion,
            'mutations' => $encoded,
        ])) > self::MaximumPlanBytes) {
            throw new InvalidArgumentException('Projection plan exceeds its byte limit.');
        }

        $this->mutations = $mutations;
    }

    public function isCompatibleWith(ProjectionContract $contract): bool
    {
        if ($this->schemaVersion !== $contract->schemaVersion) {
            return false;
        }

        foreach ($this->mutations as $mutation) {
            if (! in_array($mutation->targetId, $contract->targetIds, true)) {
                return false;
            }
        }

        return true;
    }
}
