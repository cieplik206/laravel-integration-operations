<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Enums;

/**
 * The kernel selects the proof kind from durable runtime evidence. Provider
 * callbacks may only declare which kinds a definition permits.
 *
 * @api
 */
enum TerminalProofKind: string
{
    case Execute = 'execute';
    case Poll = 'poll';
    case Reconcile = 'reconcile';
    case SealedProviderEvidence = 'sealed_provider_evidence';
    case Operator = 'operator';
    case PreEffect = 'pre_effect';
}
