<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Runtime;

use Cieplik206\IntegrationOperations\Contracts\UlidFactory;
use Cieplik206\IntegrationOperations\Crypto\HmacSha256;
use Cieplik206\IntegrationOperations\Enums\LookupHmacDomain;
use Cieplik206\IntegrationOperations\ValueObjects\OperationActor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;
use Illuminate\Database\Connection;
use InvalidArgumentException;

/** @internal */
final readonly class DatabaseTransitionRecorder
{
    public function __construct(
        private UlidFactory $ulids,
        private HmacSha256 $hmac,
    ) {}

    public function record(
        Connection $connection,
        OperationId $operationId,
        StateTransition $transition,
        int $expectedRowVersion,
        int $resultingRowVersion,
        string $reasonCode,
        ?OperationActor $actor = null,
        ?string $occurredAt = null,
    ): void {
        if (preg_match('/^[a-z][a-z0-9_.-]{0,127}$/D', $reasonCode) !== 1) {
            throw new InvalidArgumentException('Operation transition reason code is invalid.');
        }

        $actorReference = $actor?->reference();
        $actorDigest = $actorReference === null
            ? null
            : $this->hmac->digest(LookupHmacDomain::Actor, $actorReference);

        $connection->table('integration_operation_transitions')->insert([
            'id' => $this->ulids->generate()->value,
            'operation_id' => $operationId->value,
            'sequence' => $resultingRowVersion,
            'from_status' => $transition->fromStatus?->value,
            'to_status' => $transition->toStatus->value,
            'from_disposition' => $transition->fromDisposition?->value,
            'to_disposition' => $transition->toDisposition->value,
            'from_effect_state' => $transition->fromEffectState?->value,
            'to_effect_state' => $transition->toEffectState->value,
            'reason_code' => $reasonCode,
            'actor_category' => $actor->category ?? 'system',
            'actor_reference_hmac' => $actorDigest?->hex,
            'actor_hmac_key_version' => $actorDigest?->keyVersion,
            'expected_row_version' => $expectedRowVersion,
            'resulting_row_version' => $resultingRowVersion,
            'created_at' => $occurredAt ?? $connection->raw('CURRENT_TIMESTAMP'),
        ]);
    }
}
