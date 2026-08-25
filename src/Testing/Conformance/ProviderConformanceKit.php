<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Testing\Conformance;

use Cieplik206\IntegrationOperations\Contracts\OperationDefinitionProvider;
use Cieplik206\IntegrationOperations\Contracts\OperationResultCodec;
use Cieplik206\IntegrationOperations\Registry\OperationDefinitionValidator;
use Cieplik206\IntegrationOperations\ValueObjects\EncodedResult;

/** @api */
final readonly class ProviderConformanceKit
{
    public function __construct(
        private OperationDefinitionValidator $validator = new OperationDefinitionValidator,
    ) {}

    /**
     * @param  class-string<OperationDefinitionProvider>  $provider
     * @param  callable(class-string, class-string): (class-string|null)  $effectiveConcrete
     */
    public function inspect(string $provider, callable $effectiveConcrete): ConformanceReport
    {
        $violations = [];
        $seenTuples = [];
        /** @var array<string, class-string<OperationResultCodec>> $resultCodecs */
        $resultCodecs = [];
        $definitionCount = 0;

        foreach ($provider::definitions() as $definition) {
            $definitionCount++;
            $tuple = $definition->registryKey();

            if (! $definition->provider->equals($provider::provider())) {
                $violations[] = "{$tuple}: provider key does not match the definition provider";
            }

            if (array_key_exists($tuple, $seenTuples)) {
                $violations[] = "{$tuple}: duplicate provider/operation/handler tuple";
            }

            $seenTuples[$tuple] = true;

            foreach ($this->validator->violations($definition) as $violation) {
                $violations[] = "{$tuple}: {$violation}";
            }

            foreach ($definition->extensionPoints() as $name => $extensionPoint) {
                $reference = $extensionPoint['reference'];

                if ($reference === null) {
                    continue;
                }

                $concrete = $effectiveConcrete($reference->serviceId, $extensionPoint['contract']);

                if ($concrete === null || $concrete !== $reference->serviceId) {
                    $violations[] = "{$tuple}: {$name} has no exact final self-binding";

                    continue;
                }

                if ($name === 'result_codec') {
                    /** @var class-string<OperationResultCodec> $codec */
                    $codec = $concrete;

                    if ($codec::schemaVersion() !== $definition->versions->resultSchema) {
                        $violations[] = "{$tuple}: result codec schema version does not match the definition";
                    }

                    $resultType = $codec::resultType();

                    if (! EncodedResult::isValidResultType($resultType)) {
                        $violations[] = "{$tuple}: result codec type is invalid";

                        continue;
                    }

                    $resultEnvelope = "{$resultType}|{$codec::schemaVersion()}";
                    $registeredCodec = $resultCodecs[$resultEnvelope] ?? null;

                    if ($registeredCodec !== null && $registeredCodec !== $codec) {
                        $violations[] = "{$tuple}: result codec envelope {$resultEnvelope} collides with another codec";
                    }

                    $resultCodecs[$resultEnvelope] = $codec;
                }
            }
        }

        if ($definitionCount === 0) {
            $violations[] = 'provider declares no operation definitions';
        }

        return new ConformanceReport($violations);
    }
}
