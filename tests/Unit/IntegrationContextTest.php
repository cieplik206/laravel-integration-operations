<?php

declare(strict_types=1);

use Cieplik206\IntegrationOperations\Context\IntegrationContext;
use Cieplik206\IntegrationOperations\Context\IntegrationContextCodec;
use Cieplik206\IntegrationOperations\Context\IntegrationContextConstraints;

it('encodes and decodes a deterministic versioned context envelope', function (): void {
    $context = IntegrationContext::make(
        correlationId: 'workflow:01ARZ3NDEKTSV4RRFFQ69G5FAV:issue',
        attributes: [
            'workflow_id' => '01ARZ3NDEKTSV4RRFFQ69G5FAV',
            'step' => 'issue_invoice',
            'attempt' => 1,
            'canary' => true,
        ],
    );
    $codec = new IntegrationContextCodec;
    $encoded = $codec->encode($context);

    expect($encoded)->toBe('{"attributes":{"attempt":1,"canary":true,"step":"issue_invoice","workflow_id":"01ARZ3NDEKTSV4RRFFQ69G5FAV"},"correlation_id":"workflow:01ARZ3NDEKTSV4RRFFQ69G5FAV:issue","version":1}')
        ->and($codec->decode($encoded)->equals($context))->toBeTrue();
});

it('rejects reserved, nested, floating point, oversized, and excessive attributes', function (): void {
    expect(fn (): IntegrationContext => IntegrationContext::make(attributes: ['api_token' => 'secret']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationContext => IntegrationContext::make(attributes: ['email_hash' => 'x']))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationContext => IntegrationContext::make(attributes: ['nested' => ['not' => 'allowed']]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationContext => IntegrationContext::make(attributes: ['ratio' => 1.5]))
        ->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationContext => IntegrationContext::make(
            attributes: ['large' => str_repeat('a', 33)],
            constraints: new IntegrationContextConstraints(maximumStringBytes: 32),
        ))->toThrow(InvalidArgumentException::class)
        ->and(fn (): IntegrationContext => IntegrationContext::make(
            attributes: ['one' => 1, 'two' => 2],
            constraints: new IntegrationContextConstraints(maximumAttributes: 1),
        ))->toThrow(InvalidArgumentException::class);
});

it('rejects control characters that could corrupt logs or correlation headers', function (string $correlationId, string $value): void {
    expect(fn (): IntegrationContext => IntegrationContext::make($correlationId, ['workflow_id' => $value]))
        ->toThrow(InvalidArgumentException::class);
})->with([
    'newline' => ["workflow\nforged", 'safe'],
    'carriage return' => ["workflow\rforged", 'safe'],
    'attribute control byte' => ['safe', "workflow\x00forged"],
    'attribute delete byte' => ['safe', "workflow\x7fforged"],
    'unicode line separator' => ['safe', "workflow\u{2028}forged"],
]);

it('does not let context influence operation identity material', function (): void {
    $first = IntegrationContext::make('run:1', ['workflow_id' => '100']);
    $second = IntegrationContext::make('run:2', ['workflow_id' => '100']);

    expect($first->equals($second))->toBeFalse();
});

it('revalidates an existing context against the codec configured limits', function (): void {
    $context = IntegrationContext::make('workflow:long-enough-to-exceed-the-deployment-limit');
    $codec = new IntegrationContextCodec(new IntegrationContextConstraints(maximumBytes: 64));

    expect(fn (): string => $codec->encode($context))
        ->toThrow(InvalidArgumentException::class, 'encoded byte limit');
});

it('requires workflow helpers to honor deployment context constraints', function (): void {
    expect(fn (): IntegrationContext => IntegrationContext::forWorkflow(
        '01ARZ3NDEKTSV4RRFFQ69G5FAV',
        'issue',
        new IntegrationContextConstraints(maximumCorrelationIdBytes: 16),
    ))->toThrow(InvalidArgumentException::class, 'correlation ID');
});
