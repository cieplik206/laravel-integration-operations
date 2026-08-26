<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Tests\Support;

use Closure;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Throwable;

final class RecordingExceptionHandler implements ExceptionHandler
{
    /** @var list<Throwable> */
    public array $reported = [];

    /** @param null|Closure(Throwable): void $onReport */
    public function __construct(
        private readonly ExceptionHandler $delegate,
        private readonly ?Closure $onReport = null,
    ) {}

    public function report(Throwable $e): void
    {
        $this->reported[] = $e;

        if ($this->onReport !== null) {
            ($this->onReport)($e);
        }
    }

    public function shouldReport(Throwable $e): bool
    {
        return true;
    }

    public function render(mixed $request, Throwable $e): mixed
    {
        return $this->delegate->render($request, $e);
    }

    public function renderForConsole(mixed $output, Throwable $e): void
    {
        $this->delegate->renderForConsole($output, $e);
    }
}
