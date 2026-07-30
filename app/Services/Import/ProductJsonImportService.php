<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductImportRepository;
use App\Support\Import\ProductImportResult;
use App\Support\Import\ProductJsonMapper;
use Generator;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class ProductJsonImportService
{
    public function __construct(
        private readonly ProductImportRepository $repository,
        private readonly ProductJsonMapper $mapper,
    ) {}

    public function analyzeFile(string $path, int $offset = 0, ?int $limit = null): ProductImportResult
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Product source file is missing or unreadable.');
        }

        return $this->analyzeRecords($this->streamJsonArray($path), $offset, $limit);
    }

    public function analyzeJson(string $json, int $offset = 0, ?int $limit = null): ProductImportResult
    {
        try {
            $source = json_decode($json, true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new UnexpectedValueException(
                'Product source JSON is invalid: '.$exception->getMessage(),
                previous: $exception,
            );
        }

        if (! is_array($source) || ! array_is_list($source)) {
            throw new UnexpectedValueException('Product source JSON root must be an array.');
        }

        return $this->analyzeRecords($source, $offset, $limit);
    }

    /**
     * @param  iterable<int, mixed>  $source
     */
    private function analyzeRecords(
        iterable $source,
        int $offset,
        ?int $limit,
    ): ProductImportResult {
        if ($offset < 0 || ($limit !== null && $limit < 1)) {
            throw new UnexpectedValueException('Offset and limit are invalid.');
        }

        $selected = [];
        $sourceTotal = 0;
        $duplicateSourceIds = 0;
        $duplicateProductSlugs = 0;
        $duplicateSkus = 0;
        $seenSourceIds = [];
        $seenProductSlugs = [];
        $seenSkus = [];
        $barcodeCounts = [];

        foreach ($source as $index => $record) {
            if (! is_array($record) || array_is_list($record)) {
                $item = [
                    'status' => 'failed',
                    'reason' => 'record_must_be_an_object',
                    'warnings' => [],
                    'source_id' => '#'.($index + 1),
                    'source_index' => $index,
                ];
            } else {
                $item = $this->mapper->map($record);
                $item['source_index'] = $index;

                if ($item['status'] === 'valid') {
                    $reasons = [];

                    if (isset($seenSourceIds[$item['source_id']])) {
                        $duplicateSourceIds++;
                        $reasons[] = 'duplicate_source_product_id';
                    } else {
                        $seenSourceIds[$item['source_id']] = true;
                    }

                    if (isset($seenProductSlugs[$item['product_slug']])) {
                        $duplicateProductSlugs++;
                        $reasons[] = 'duplicate_product_slug';
                    } else {
                        $seenProductSlugs[$item['product_slug']] = true;
                    }

                    if (isset($seenSkus[$item['synthetic_sku']])) {
                        $duplicateSkus++;
                        $reasons[] = 'duplicate_variant_sku';
                    } else {
                        $seenSkus[$item['synthetic_sku']] = true;
                    }

                    if ($reasons !== []) {
                        $item['status'] = 'quarantined';
                        $item['reason'] = implode(',', $reasons);
                    } elseif ($item['variant']['barcode'] !== null) {
                        $barcode = $item['variant']['barcode'];
                        $barcodeCounts[$barcode] = ($barcodeCounts[$barcode] ?? 0) + 1;
                    }
                }
            }

            if ($index >= $offset && ($limit === null || count($selected) < $limit)) {
                $selected[] = $item;
            }

            $sourceTotal++;
        }

        $duplicateBarcodes = array_filter(
            $barcodeCounts,
            static fn (int $count): bool => $count > 1,
        );

        foreach ($selected as &$item) {
            $barcode = $item['status'] === 'valid' ? $item['variant']['barcode'] : null;

            if ($barcode !== null && isset($duplicateBarcodes[$barcode])) {
                $item['variant']['barcode'] = null;
                $item['warnings'][] = 'duplicate_barcode_dropped';
            }
        }
        unset($item);

        $validRecords = array_values(array_filter(
            $selected,
            static fn (array $item): bool => $item['status'] === 'valid',
        ));
        $quarantined = array_values(array_filter(
            $selected,
            static fn (array $item): bool => $item['status'] === 'quarantined',
        ));
        $failed = array_values(array_filter(
            $selected,
            static fn (array $item): bool => $item['status'] === 'failed',
        ));
        $plans = $this->repository->plan($validRecords);
        $warningCounts = [];

        foreach ($validRecords as $item) {
            foreach ($item['warnings'] as $warning) {
                $warningCounts[$warning] = ($warningCounts[$warning] ?? 0) + 1;
            }
        }

        return new ProductImportResult([
            'source_total' => $sourceTotal,
            'offset' => $offset,
            'limit' => $limit,
            'selected' => count($selected),
            'valid' => count($validRecords),
            'quarantined' => count($quarantined),
            'failed' => count($failed),
            'duplicate_source_ids' => $duplicateSourceIds,
            'duplicate_product_slugs' => $duplicateProductSlugs,
            'duplicate_skus' => $duplicateSkus,
            'duplicate_barcodes' => count($duplicateBarcodes),
            'warnings' => $warningCounts,
            'plans' => $plans,
            'planned_records' => $validRecords,
            'quarantine_examples' => array_slice(array_map(
                $this->issueSummary(...),
                $quarantined,
            ), 0, 5),
            'failure_examples' => array_slice(array_map(
                $this->issueSummary(...),
                $failed,
            ), 0, 5),
            'sample_mappings' => array_slice(array_map(
                static fn (array $item): array => [
                    'source_id' => $item['source_id'],
                    'product_slug' => $item['product_slug'],
                    'sku' => $item['synthetic_sku'],
                    'brand' => $item['brand']['name'],
                    'category' => $item['categories'][array_key_last($item['categories'])]['name'],
                    'price' => $item['variant']['price'],
                    'sale_price' => $item['variant']['sale_price'],
                    'images' => count($item['images']),
                ],
                $validRecords,
            ), 0, 5),
        ]);
    }

    /**
     * Stream a top-level JSON array one object at a time so the real product
     * export remains below PHP's memory limit.
     *
     * @return Generator<int, array<string, mixed>>
     */
    private function streamJsonArray(string $path): Generator
    {
        $handle = fopen($path, 'rb');

        if ($handle === false) {
            throw new RuntimeException('Product source file could not be read.');
        }

        $startedArray = false;
        $closedArray = false;
        $collecting = false;
        $inString = false;
        $escaped = false;
        $depth = 0;
        $buffer = '';
        $index = 0;

        try {
            while (! feof($handle)) {
                $chunk = fread($handle, 8192);

                if ($chunk === false) {
                    throw new RuntimeException('Product source file could not be read.');
                }

                $length = strlen($chunk);

                for ($position = 0; $position < $length; $position++) {
                    $character = $chunk[$position];

                    if (! $startedArray) {
                        if (ctype_space($character)) {
                            continue;
                        }

                        if ($character !== '[') {
                            throw new UnexpectedValueException(
                                'Product source JSON root must be an array.',
                            );
                        }

                        $startedArray = true;

                        continue;
                    }

                    if ($closedArray) {
                        if (! ctype_space($character)) {
                            throw new UnexpectedValueException(
                                'Product source JSON contains trailing data.',
                            );
                        }

                        continue;
                    }

                    if (! $collecting) {
                        if (ctype_space($character) || $character === ',') {
                            continue;
                        }

                        if ($character === ']') {
                            $closedArray = true;

                            continue;
                        }

                        if ($character !== '{') {
                            throw new UnexpectedValueException(
                                'Every product source record must be an object.',
                            );
                        }

                        $collecting = true;
                        $depth = 1;
                        $buffer = '{';

                        continue;
                    }

                    $buffer .= $character;

                    if ($inString) {
                        if ($escaped) {
                            $escaped = false;
                        } elseif ($character === '\\') {
                            $escaped = true;
                        } elseif ($character === '"') {
                            $inString = false;
                        }

                        continue;
                    }

                    if ($character === '"') {
                        $inString = true;
                    } elseif ($character === '{' || $character === '[') {
                        $depth++;
                    } elseif ($character === '}' || $character === ']') {
                        $depth--;
                    }

                    if ($depth !== 0) {
                        continue;
                    }

                    try {
                        $record = json_decode($buffer, true, 512, JSON_THROW_ON_ERROR);
                    } catch (JsonException $exception) {
                        throw new UnexpectedValueException(
                            'Product source JSON is invalid: '.$exception->getMessage(),
                            previous: $exception,
                        );
                    }

                    if (! is_array($record) || array_is_list($record)) {
                        throw new UnexpectedValueException(
                            'Every product source record must be an object.',
                        );
                    }

                    yield $index++ => $record;
                    $collecting = false;
                    $buffer = '';
                }
            }
        } finally {
            fclose($handle);
        }

        if (! $startedArray || ! $closedArray || $collecting) {
            throw new UnexpectedValueException('Product source JSON is incomplete.');
        }
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array{source_id: string, reason: string}
     */
    private function issueSummary(array $item): array
    {
        return [
            'source_id' => (string) ($item['source_id'] ?: '(missing)'),
            'reason' => (string) $item['reason'],
        ];
    }
}
