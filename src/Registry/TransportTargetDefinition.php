<?php

declare(strict_types=1);

namespace Cieplik206\IntegrationOperations\Registry;

use InvalidArgumentException;

/** @api */
final readonly class TransportTargetDefinition
{
    private const int MaximumPlaceholders = 8;

    /** @var list<string> */
    public array $placeholderNames;

    public function __construct(
        public string $targetId,
        public string $transport,
        public string $method,
        public string $targetTemplate,
    ) {
        if (preg_match('/^[a-z][a-z0-9_.:-]{0,63}$/D', $targetId) !== 1
            || preg_match('/^[a-z][a-z0-9_.-]{0,31}$/D', $transport) !== 1
            || preg_match('/^[A-Z]{1,16}$/D', $method) !== 1
            || preg_match('//u', $targetTemplate) !== 1
            || strlen($targetTemplate) > 191
            || ! str_starts_with($targetTemplate, '/')
            || str_starts_with($targetTemplate, '//')
            || str_contains($targetTemplate, '\\')
            || str_contains($targetTemplate, '%')
            || str_contains($targetTemplate, '?')
            || str_contains($targetTemplate, '#')) {
            throw new InvalidArgumentException('Transport target definition is invalid.');
        }

        $placeholderNames = [];

        foreach ($this->segments() as $segment) {
            if ($segment === '.' || $segment === '..') {
                throw new InvalidArgumentException('Transport target definition is invalid.');
            }

            if (preg_match('/^[A-Za-z0-9_.-]+$/D', $segment) === 1) {
                continue;
            }

            $placeholder = self::placeholderSegment($segment);

            if ($placeholder === null) {
                throw new InvalidArgumentException('Transport target placeholder is invalid.');
            }

            $placeholderName = $placeholder['name'];

            if (in_array($placeholderName, $placeholderNames, true)) {
                throw new InvalidArgumentException('Transport target placeholders must be unique.');
            }

            $placeholderNames[] = $placeholderName;
        }

        if (count($placeholderNames) > self::MaximumPlaceholders) {
            throw new InvalidArgumentException('Transport target has too many placeholders.');
        }

        $this->placeholderNames = $placeholderNames;
    }

    /** @param array<string, string> $parameters */
    public function render(array $parameters): string
    {
        $parameterNames = array_keys($parameters);
        sort($parameterNames, SORT_STRING);
        $expectedNames = $this->placeholderNames;
        sort($expectedNames, SORT_STRING);

        if ($parameterNames !== $expectedNames) {
            throw new InvalidArgumentException('Transport target parameters do not match its placeholders.');
        }

        $renderedSegments = [];

        foreach ($this->segments() as $segment) {
            $placeholder = self::placeholderSegment($segment);

            if ($placeholder === null) {
                $renderedSegments[] = $segment;

                continue;
            }

            $value = $parameters[$placeholder['name']];

            if ($value === ''
                || $value === '.'
                || $value === '..'
                || strlen($value) > 191
                || preg_match('//u', $value) !== 1
                || preg_match('/[\x00-\x1f\x7f]/', $value) === 1
                || strpbrk($value, '/\\%?#') !== false) {
                throw new InvalidArgumentException('Transport target parameter is invalid.');
            }

            $renderedSegments[] = rawurlencode($value).$placeholder['suffix'];
        }

        $rendered = '/'.implode('/', $renderedSegments);

        if (strlen($rendered) > 2_048) {
            throw new InvalidArgumentException('Rendered transport target exceeds its byte limit.');
        }

        return $rendered;
    }

    /** @return array{name: string, suffix: string}|null */
    private static function placeholderSegment(string $segment): ?array
    {
        if (preg_match('/^\{([a-z][a-z0-9_]{0,63})\}([A-Za-z0-9_.-]*)$/D', $segment, $matches) !== 1) {
            return null;
        }

        return ['name' => $matches[1], 'suffix' => $matches[2]];
    }

    /** @return list<string> */
    private function segments(): array
    {
        if ($this->targetTemplate === '/') {
            return [];
        }

        return explode('/', substr($this->targetTemplate, 1));
    }
}
