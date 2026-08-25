<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance;

/**
 * Provider-neutral semantic checks that JSON Schema cannot express portably.
 *
 * @api
 */
final class ProviderManifestConformanceValidator
{
    /**
     * @param  array<string, mixed>  $manifest
     * @return list<string>
     */
    public function violations(array $manifest): array
    {
        $provider = $manifest['provider'] ?? null;
        $operations = $manifest['operations'] ?? null;

        if (! is_string($provider) || ! is_array($operations) || ! array_is_list($operations)) {
            return ['manifest provider or operations are invalid'];
        }

        $violations = [];
        $seenTuples = [];

        foreach ($operations as $index => $operation) {
            if (! is_array($operation)) {
                $violations[] = "operations[{$index}] is invalid";

                continue;
            }

            $operationType = $operation['operation_type'] ?? null;
            $versions = $operation['versions'] ?? null;
            $handlerVersion = is_array($versions) ? ($versions['handler'] ?? null) : null;

            if (! is_string($operationType) || ! is_int($handlerVersion)) {
                $violations[] = "operations[{$index}] identity is invalid";

                continue;
            }

            if (! str_starts_with($operationType, "{$provider}.")) {
                $violations[] = "operations[{$index}] operation_type does not have provider prefix '{$provider}.'";
            }

            $tuple = "{$provider}|{$operationType}|{$handlerVersion}";

            if (array_key_exists($tuple, $seenTuples)) {
                $violations[] = "operations[{$index}] duplicates tuple {$tuple}";
            }

            $seenTuples[$tuple] = true;
        }

        return $violations;
    }
}
