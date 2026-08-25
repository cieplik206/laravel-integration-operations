<?php

declare(strict_types=1);

test('the package development environment uses the supported PHP version', function (): void {
    expect(PHP_VERSION_ID)->toBeGreaterThanOrEqual(80400);
});
