<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Contracts;

use Cieplik206\IntegrationOperations\ValueObjects\IntegrationScope;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseClaim;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryBatch;
use Cieplik206\IntegrationOperations\ValueObjects\LeaseRecoveryCursor;
use Cieplik206\IntegrationOperations\ValueObjects\OperationId;

/** @internal */
interface OperationLeaseManager
{
    public function claim(OperationId $operationId, string $owner): ?LeaseClaim;

    public function heartbeat(LeaseClaim $claim): ?LeaseClaim;

    public function recoverExpired(
        IntegrationScope $scope,
        int $limit = 100,
        int $scanLimit = 500,
        ?LeaseRecoveryCursor $after = null,
    ): LeaseRecoveryBatch;
}
