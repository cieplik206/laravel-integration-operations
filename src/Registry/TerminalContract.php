<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use Cieplik206\IntegrationOperations\Enums\EffectState;
use Cieplik206\IntegrationOperations\Enums\OperationDisposition;
use Cieplik206\IntegrationOperations\Enums\OperationStatus;
use Cieplik206\IntegrationOperations\Enums\ResultAvailability;
use Cieplik206\IntegrationOperations\Support\ImmutableValueSanitizer;
use InvalidArgumentException;

/** @api */
final readonly class TerminalContract
{
    /** @var list<EffectState> */
    public array $effectStates;

    /** @var list<ResultAvailability> */
    public array $resultAvailabilities;

    /**
     * @param  list<EffectState>  $effectStates
     * @param  list<ResultAvailability>  $resultAvailabilities
     */
    public function __construct(
        public OperationStatus $status,
        public OperationDisposition $disposition,
        array $effectStates,
        array $resultAvailabilities,
    ) {
        $this->effectStates = ImmutableValueSanitizer::enumList(
            $effectStates,
            EffectState::class,
            'Terminal contract effect states',
        );
        $this->resultAvailabilities = ImmutableValueSanitizer::enumList(
            $resultAvailabilities,
            ResultAvailability::class,
            'Terminal contract result availabilities',
        );

        if ($this->effectStates === [] || $this->resultAvailabilities === []) {
            throw new InvalidArgumentException('Terminal contract values must not be empty.');
        }

        if (! $disposition->isTerminal() || $status->disposition() !== $disposition) {
            throw new InvalidArgumentException('Terminal contract status and disposition are inconsistent.');
        }

        if (count(array_unique($this->effectStates, SORT_REGULAR)) !== count($this->effectStates)
            || count(array_unique($this->resultAvailabilities, SORT_REGULAR)) !== count($this->resultAvailabilities)) {
            throw new InvalidArgumentException('Terminal contract values must be unique.');
        }
    }
}
