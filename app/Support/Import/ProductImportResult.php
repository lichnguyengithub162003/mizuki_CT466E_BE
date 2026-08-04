<?php

namespace App\Support\Import;

final readonly class ProductImportResult
{
    /**
     * @param  array<string, mixed>  $data
     */
    public function __construct(
        private array $data,
    ) {}

    public function get(string $key): mixed
    {
        return $this->data[$key] ?? null;
    }

    /**
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        return $this->data;
    }

    /**
     * @return array<string, mixed>
     */
    public function summary(): array
    {
        $summary = $this->data;
        unset($summary['planned_records']);

        foreach (['quarantine_examples', 'failure_examples', 'sample_mappings', 'imported_mappings'] as $key) {
            if (isset($summary[$key]) && is_array($summary[$key])) {
                $summary[$key] = array_slice($summary[$key], 0, 5);
            }
        }

        return $summary;
    }
}
