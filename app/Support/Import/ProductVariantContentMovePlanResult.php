<?php

namespace App\Support\Import;

use JsonException;

final readonly class ProductVariantContentMovePlanResult
{
    /** @param array<string, int> $summary @param list<array<string, mixed>> $groups */
    public function __construct(private array $summary, private array $groups) {}

    /** @return array{summary: array<string, int>, groups: list<array<string, mixed>>} */
    public function toArray(): array
    {
        return ['summary' => $this->summary, 'groups' => $this->groups];
    }

    /** @throws JsonException */
    public function toJson(): string
    {
        return json_encode(
            $this->toArray(),
            JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
        ).PHP_EOL;
    }
}
