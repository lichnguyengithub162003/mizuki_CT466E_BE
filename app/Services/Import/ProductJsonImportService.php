<?php

namespace App\Services\Import;

use App\Models\Product;
use App\Repositories\Import\ProductImportRepository;
use App\Support\Import\ProductHtmlSanitizer;
use App\Support\Import\ProductImportResult;
use App\Support\Import\ProductJsonMapper;
use Generator;
use Illuminate\Support\Str;
use JsonException;
use RuntimeException;
use UnexpectedValueException;

class ProductJsonImportService
{
    public function __construct(
        private readonly ProductImportRepository $repository,
        private readonly ProductJsonMapper $mapper,
        private readonly ProductHtmlSanitizer $htmlSanitizer,
        private readonly ProductImageImportService $imageImporter,
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
     * @return array{source: string, total: int, updated: int, unchanged: int, conflicts: int, dry_run: bool}
     */
    public function refreshImportedProductSlugs(
        string $source,
        bool $dryRun,
        int $batchSize = 200,
    ): array {
        $source = trim($source);

        if ($source === '') {
            throw new UnexpectedValueException('Import source must not be empty.');
        }

        $result = [
            'source' => $source,
            'total' => 0,
            'updated' => 0,
            'unchanged' => 0,
            'conflicts' => 0,
            'dry_run' => $dryRun,
        ];
        $afterId = 0;
        $limit = max(1, min(500, $batchSize));

        while (true) {
            $products = $this->repository->sourceProductsAfter($source, $afterId, $limit);

            if ($products->isEmpty()) {
                break;
            }

            $afterId = (int) $products->last()->id;
            $updates = [];

            foreach ($products as $product) {
                $result['total']++;
                $slug = $this->mapper->productSlug(
                    (string) $product->name,
                    (string) $product->external_id,
                );

                if ($slug === '') {
                    $result['conflicts']++;

                    continue;
                }

                $updates[(int) $product->id] = $slug;
            }

            if ($dryRun) {
                $owners = $this->repository->slugOwnerIds(array_values($updates));

                foreach ($updates as $productId => $slug) {
                    /** @var Product $product */
                    $product = $products->firstWhere('id', $productId);

                    if ($product->slug === $slug) {
                        $result['unchanged']++;
                    } elseif (isset($owners[$slug]) && $owners[$slug] !== $productId) {
                        $result['conflicts']++;
                    } else {
                        $result['updated']++;
                    }
                }
            } else {
                $batchResult = $this->repository->updateImportedProductSlugs($source, $updates);
                $result['updated'] += $batchResult['updated'];
                $result['unchanged'] += $batchResult['unchanged'];
                $result['conflicts'] += $batchResult['conflicts'];
            }

            unset($products, $updates);
        }

        return $result;
    }

    public function processCatalogFile(
        string $path,
        int $offset,
        ?int $limit,
        int $defaultWeight,
        string $sourceDirectory,
        bool $updateExisting,
        bool $skipImages,
        bool $dryRun,
        int $chunkSize = 25,
    ): ProductImportResult {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException('Product source file is missing or unreadable.');
        }

        if ($offset < 0 || ($limit !== null && $limit < 1)) {
            throw new UnexpectedValueException('Offset and limit are invalid.');
        }

        if ($defaultWeight < 1 || $defaultWeight > 100_000) {
            throw new UnexpectedValueException(
                'Default weight must be an integer between 1 and 100000 grams.',
            );
        }

        $this->repository->disableQueryLog();
        $scan = $this->scanCatalogIdentities($path);
        $summary = [
            'source_total' => $scan['source_total'],
            'offset' => $offset,
            'limit' => $limit,
            'selected' => 0,
            'valid' => 0,
            'quarantined' => 0,
            'failed' => 0,
            'duplicate_source_ids' => $scan['duplicate_source_ids'],
            'duplicate_product_slugs' => $scan['duplicate_product_slugs'],
            'duplicate_skus' => $scan['duplicate_skus'],
            'duplicate_barcodes' => count($scan['duplicate_barcodes']),
            'warnings' => [],
            'quality' => [
                'invalid_barcode' => 0,
                'duplicate_barcode' => count($scan['duplicate_barcodes']),
                'existing_barcode_conflict' => 0,
                'missing_weight_policy' => 0,
                'products_without_source_variant_groups' => 0,
                'low_resolution_images' => 0,
            ],
            'plans' => [],
            'quarantine_examples' => [],
            'failure_examples' => [],
            'sample_mappings' => [],
        ];
        $writeCounters = [];
        $importedMappings = [];
        $inventory = ['created' => 0, 'unchanged' => 0, 'quantity_per_row' => 0];
        $storedImages = 0;
        $processedValidRecords = 0;
        $skippedExistingRecords = 0;
        $chunk = [];
        $boundedChunkSize = max(1, min(50, $chunkSize));

        foreach ($this->streamJsonArray($path) as $index => $record) {
            if ($index < $offset || ($limit !== null && $summary['selected'] >= $limit)) {
                unset($record);

                continue;
            }

            $summary['selected']++;
            $item = $this->mapper->map($record);
            $item['source_index'] = $index;

            if ($item['status'] === 'valid' && isset($scan['duplicate_reasons'][$index])) {
                $item['status'] = 'quarantined';
                $item['reason'] = implode(',', $scan['duplicate_reasons'][$index]);
            } elseif (
                $item['status'] === 'valid'
                && $item['variant']['barcode'] !== null
                && isset($scan['duplicate_barcodes'][$item['variant']['barcode']])
            ) {
                $item['variant']['barcode'] = null;
                $item['warnings'][] = 'duplicate_barcode_dropped';
            }

            if ($item['status'] === 'valid') {
                $summary['valid']++;

                foreach ($item['warnings'] as $warning) {
                    $summary['warnings'][$warning] = ($summary['warnings'][$warning] ?? 0) + 1;
                }

                if ($item['metadata']['variant_options'] === []) {
                    $summary['quality']['products_without_source_variant_groups']++;
                }

                foreach ($item['images'] as $image) {
                    if (str_contains($image['image_url'], '_img_80x80_')) {
                        $summary['quality']['low_resolution_images']++;
                    }
                }

                if (count($summary['sample_mappings']) < 5) {
                    $summary['sample_mappings'][] = $this->mappingSummary($item);
                }

                $chunk[] = $item;

                if (count($chunk) >= $boundedChunkSize) {
                    $chunkResult = $this->processCatalogChunk(
                        $chunk,
                        $defaultWeight,
                        $sourceDirectory,
                        $updateExisting,
                        $skipImages,
                        $dryRun,
                    );
                    $this->mergeCatalogChunkResult(
                        $chunkResult,
                        $summary,
                        $writeCounters,
                        $importedMappings,
                        $inventory,
                        $storedImages,
                        $processedValidRecords,
                        $skippedExistingRecords,
                    );
                    $chunk = [];
                    unset($chunkResult, $item, $record);
                    gc_collect_cycles();
                }
            } elseif ($item['status'] === 'quarantined') {
                $summary['quarantined']++;

                if (count($summary['quarantine_examples']) < 5) {
                    $summary['quarantine_examples'][] = $this->issueSummary($item);
                }
            } else {
                $summary['failed']++;

                if (count($summary['failure_examples']) < 5) {
                    $summary['failure_examples'][] = $this->issueSummary($item);
                }
            }

            unset($item, $record);
        }

        if ($chunk !== []) {
            $chunkResult = $this->processCatalogChunk(
                $chunk,
                $defaultWeight,
                $sourceDirectory,
                $updateExisting,
                $skipImages,
                $dryRun,
            );
            $this->mergeCatalogChunkResult(
                $chunkResult,
                $summary,
                $writeCounters,
                $importedMappings,
                $inventory,
                $storedImages,
                $processedValidRecords,
                $skippedExistingRecords,
            );
            $chunk = [];
            unset($chunkResult);
            gc_collect_cycles();
        }

        $summary['quality']['invalid_barcode'] = $summary['warnings']['invalid_barcode'] ?? 0;
        $summary['quality']['missing_weight_policy'] = $dryRun
            ? ($summary['warnings']['missing_weight_policy'] ?? 0)
            : 0;

        if (! $dryRun) {
            $summary['write_counters'] = $writeCounters;
            $summary['imported_mappings'] = array_slice($importedMappings, 0, 5);
            $summary['inventory'] = $inventory;
            $summary['stored_images'] = $storedImages;
            $summary['processed_valid_records'] = $processedValidRecords;
            $summary['skipped_existing_records'] = $skippedExistingRecords;
            $summary['default_weight'] = $defaultWeight;
            $summary['transaction_result'] = 'committed_in_chunks';
        }

        unset($scan);
        gc_collect_cycles();
        $summary['peak_memory_bytes'] = memory_get_peak_usage(true);

        return new ProductImportResult($summary);
    }

    public function importFile(
        string $path,
        int $offset,
        int $limit,
        int $defaultWeight,
    ): ProductImportResult {
        return $this->persistAnalysis(
            $this->analyzeFile($path, $offset, $limit),
            $defaultWeight,
        );
    }

    public function importJson(
        string $json,
        int $offset,
        int $limit,
        int $defaultWeight,
    ): ProductImportResult {
        return $this->persistAnalysis(
            $this->analyzeJson($json, $offset, $limit),
            $defaultWeight,
        );
    }

    public function persistCatalogAnalysis(
        ProductImportResult $analysis,
        int $defaultWeight,
        string $sourceDirectory,
        bool $updateExisting,
        bool $skipImages,
        int $chunkSize = 50,
    ): ProductImportResult {
        $data = $analysis->toArray();
        $records = $data['planned_records'];

        if (! $updateExisting) {
            $records = $this->repository->onlyMissingProducts($records);
        }

        $writeCounters = [];
        $importedMappings = [];
        $skus = [];
        $storedImages = 0;

        foreach (array_chunk($records, max(1, min(100, $chunkSize))) as $chunk) {
            $createdPaths = [];

            try {
                foreach ($chunk as &$record) {
                    $record['metadata']['source_image_urls'] = array_column(
                        $record['images'],
                        'image_url',
                    );
                    $record['images'] = $this->imageImporter->importForRecord(
                        $record,
                        $sourceDirectory,
                        $skipImages,
                        $createdPaths,
                    );
                    $storedImages += count($record['images']);
                    $skus[] = $record['synthetic_sku'];
                }
                unset($record);

                $chunkData = $data;
                $chunkData['planned_records'] = $chunk;
                $chunkData['quarantined'] = 0;
                $chunkData['failed'] = 0;
                $result = $this->persistAnalysis(
                    new ProductImportResult($chunkData),
                    $defaultWeight,
                )->toArray();
                $writeCounters = $this->mergeWriteCounters(
                    $writeCounters,
                    $result['write_counters'],
                );
                $importedMappings = array_slice(array_merge(
                    $importedMappings,
                    $result['imported_mappings'],
                ), 0, 5);
            } catch (\Throwable $exception) {
                $this->imageImporter->rollbackCreatedFiles($createdPaths);

                throw $exception;
            }
        }

        $inventory = $this->repository->transaction(
            fn (): array => $this->repository->seedDevelopmentInventory($skus),
        );
        $data['write_counters'] = $writeCounters;
        $data['imported_mappings'] = $importedMappings;
        $data['inventory'] = $inventory;
        $data['stored_images'] = $storedImages;
        $data['processed_valid_records'] = count($records);
        $data['skipped_existing_records'] = count($data['planned_records']) - count($records);
        $data['default_weight'] = $defaultWeight;
        $data['transaction_result'] = 'committed_in_chunks';

        return new ProductImportResult($data);
    }

    /**
     * @param  array<string, array<string, int>>  $aggregate
     * @param  array<string, array<string, int>>  $current
     * @return array<string, array<string, int>>
     */
    private function mergeWriteCounters(array $aggregate, array $current): array
    {
        foreach ($current as $entity => $counters) {
            foreach ($counters as $operation => $count) {
                $aggregate[$entity][$operation] = ($aggregate[$entity][$operation] ?? 0) + $count;
            }
        }

        return $aggregate;
    }

    public function persistAnalysis(
        ProductImportResult $analysis,
        int $defaultWeight,
    ): ProductImportResult {
        $data = $analysis->toArray();

        if ($data['quarantined'] > 0 || $data['failed'] > 0) {
            throw new UnexpectedValueException(
                'Selected product batch contains quarantined or failed records.',
            );
        }

        if ($defaultWeight < 1 || $defaultWeight > 100_000) {
            throw new UnexpectedValueException(
                'Default weight must be an integer between 1 and 100000 grams.',
            );
        }

        $records = $data['planned_records'];
        $barcodes = array_values(array_filter(array_map(
            static fn (array $record): ?string => $record['variant']['barcode'],
            $records,
        )));
        $barcodeOwners = $this->repository->barcodeOwners($barcodes);
        $existingBarcodeConflicts = 0;

        foreach ($records as &$record) {
            $barcode = $record['variant']['barcode'];

            if (
                $barcode !== null
                && isset($barcodeOwners[$barcode])
                && $barcodeOwners[$barcode] !== $record['synthetic_sku']
            ) {
                $record['variant']['barcode'] = null;
                $record['warnings'][] = 'existing_barcode_conflict';
                $existingBarcodeConflicts++;
            }

            $record['warnings'] = array_values(array_diff(
                $record['warnings'],
                ['missing_weight_policy'],
            ));
            $record['variant']['weight'] = $defaultWeight;
            $record['variant']['attributes'] = $this->writeAttributes($record);

            foreach (['description', 'ingredients', 'usage_instructions'] as $field) {
                $record['product'][$field] = $this->htmlSanitizer->sanitize(
                    $record['product'][$field],
                );
            }

            $record['product']['specifications'] = array_map(
                static fn (string $value): string => mb_substr(
                    trim(strip_tags($value)),
                    0,
                    1000,
                ),
                $record['product']['specifications'] ?? [],
            );
        }
        unset($record);

        $persistence = $this->repository->transaction(
            fn (): array => $this->repository->persistBatch($records),
        );
        $data['planned_records'] = $records;
        $data['default_weight'] = $defaultWeight;
        $data['transaction_result'] = 'committed';
        $data['quality']['existing_barcode_conflict'] = $existingBarcodeConflicts;
        $data['quality']['missing_weight_policy'] = 0;

        return new ProductImportResult(array_merge($data, $persistence));
    }

    /**
     * @param  array<string, mixed>  $record
     * @return array<string, string>|null
     */
    private function writeAttributes(array $record): ?array
    {
        $attributes = is_array($record['variant']['attributes'])
            ? $record['variant']['attributes']
            : [];

        foreach ($record['metadata']['specifications'] as $name => $value) {
            if ($name === 'Barcode' || ! is_scalar($value)) {
                continue;
            }

            $key = 'spec_'.Str::slug((string) $name, '_');
            $safeValue = trim(strip_tags((string) $value));

            if ($key !== 'spec_' && $safeValue !== '') {
                $attributes[$key] = mb_substr($safeValue, 0, 500);
            }
        }

        return $attributes === [] ? null : $attributes;
    }

    /**
     * @param  list<array<string, mixed>>  $records
     * @return array<string, mixed>
     */
    private function processCatalogChunk(
        array $records,
        int $defaultWeight,
        string $sourceDirectory,
        bool $updateExisting,
        bool $skipImages,
        bool $dryRun,
    ): array {
        if ($dryRun) {
            return [
                'plans' => $this->repository->plan($records),
                'write_counters' => [],
                'imported_mappings' => [],
                'inventory' => ['created' => 0, 'unchanged' => 0, 'quantity_per_row' => 0],
                'stored_images' => 0,
                'processed' => 0,
                'skipped' => 0,
                'existing_barcode_conflict' => 0,
            ];
        }

        $recordsToWrite = $updateExisting
            ? $records
            : $this->repository->onlyMissingProducts($records);
        $skipped = count($records) - count($recordsToWrite);

        if ($recordsToWrite === []) {
            return [
                'plans' => [],
                'write_counters' => [],
                'imported_mappings' => [],
                'inventory' => ['created' => 0, 'unchanged' => 0, 'quantity_per_row' => 0],
                'stored_images' => 0,
                'processed' => 0,
                'skipped' => $skipped,
                'existing_barcode_conflict' => 0,
            ];
        }

        $createdPaths = [];
        $storedImages = 0;

        try {
            foreach ($recordsToWrite as &$record) {
                $record['metadata']['source_image_urls'] = array_column(
                    $record['images'],
                    'image_url',
                );
                $record['images'] = $this->imageImporter->importForRecord(
                    $record,
                    $sourceDirectory,
                    $skipImages,
                    $createdPaths,
                );
                $storedImages += count($record['images']);
            }
            unset($record);

            $result = $this->persistAnalysis(new ProductImportResult([
                'planned_records' => $recordsToWrite,
                'quarantined' => 0,
                'failed' => 0,
                'quality' => [
                    'existing_barcode_conflict' => 0,
                    'missing_weight_policy' => count($recordsToWrite),
                ],
            ]), $defaultWeight)->toArray();
            $skus = array_column($recordsToWrite, 'synthetic_sku');
            $inventory = $this->repository->transaction(
                fn (): array => $this->repository->seedDevelopmentInventory($skus),
            );
        } catch (\Throwable $exception) {
            $this->imageImporter->rollbackCreatedFiles($createdPaths);

            throw $exception;
        }

        return [
            'plans' => [],
            'write_counters' => $result['write_counters'],
            'imported_mappings' => $result['imported_mappings'],
            'inventory' => $inventory,
            'stored_images' => $storedImages,
            'processed' => count($recordsToWrite),
            'skipped' => $skipped,
            'existing_barcode_conflict' => $result['quality']['existing_barcode_conflict'] ?? 0,
        ];
    }

    /**
     * @param  array<string, mixed>  $chunkResult
     * @param  array<string, mixed>  $summary
     * @param  array<string, array<string, int>>  $writeCounters
     * @param  list<array<string, mixed>>  $importedMappings
     * @param  array{created: int, unchanged: int, quantity_per_row: int}  $inventory
     */
    private function mergeCatalogChunkResult(
        array $chunkResult,
        array &$summary,
        array &$writeCounters,
        array &$importedMappings,
        array &$inventory,
        int &$storedImages,
        int &$processedValidRecords,
        int &$skippedExistingRecords,
    ): void {
        $summary['plans'] = $this->mergeWriteCounters($summary['plans'], $chunkResult['plans']);
        $writeCounters = $this->mergeWriteCounters($writeCounters, $chunkResult['write_counters']);
        $importedMappings = array_slice(array_merge(
            $importedMappings,
            $chunkResult['imported_mappings'],
        ), 0, 5);
        $inventory['created'] += $chunkResult['inventory']['created'];
        $inventory['unchanged'] += $chunkResult['inventory']['unchanged'];
        $inventory['quantity_per_row'] = max(
            $inventory['quantity_per_row'],
            $chunkResult['inventory']['quantity_per_row'],
        );
        $storedImages += $chunkResult['stored_images'];
        $processedValidRecords += $chunkResult['processed'];
        $skippedExistingRecords += $chunkResult['skipped'];
        $summary['quality']['existing_barcode_conflict'] += $chunkResult['existing_barcode_conflict'];
    }

    /**
     * @param  array<string, mixed>  $item
     * @return array<string, mixed>
     */
    private function mappingSummary(array $item): array
    {
        return [
            'source_id' => $item['source_id'],
            'product_slug' => $item['product_slug'],
            'sku' => $item['synthetic_sku'],
            'brand' => $item['brand']['name'],
            'category' => $item['categories'][array_key_last($item['categories'])]['name'],
            'price' => $item['variant']['price'],
            'sale_price' => $item['variant']['sale_price'],
            'images' => count($item['images']),
        ];
    }

    /**
     * @return array{
     *     source_total: int,
     *     duplicate_source_ids: int,
     *     duplicate_product_slugs: int,
     *     duplicate_skus: int,
     *     duplicate_reasons: array<int, list<string>>,
     *     duplicate_barcodes: array<string, true>
     * }
     */
    private function scanCatalogIdentities(string $path): array
    {
        $seenSourceIds = [];
        $seenProductSlugs = [];
        $seenSkus = [];
        $barcodeCounts = [];
        $duplicateReasons = [];
        $duplicateSourceIds = 0;
        $duplicateProductSlugs = 0;
        $duplicateSkus = 0;
        $sourceTotal = 0;

        foreach ($this->streamJsonArray($path) as $index => $record) {
            $item = $this->mapper->map($record);

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
                    $duplicateReasons[$index] = $reasons;
                } elseif ($item['variant']['barcode'] !== null) {
                    $barcode = $item['variant']['barcode'];
                    $barcodeCounts[$barcode] = ($barcodeCounts[$barcode] ?? 0) + 1;
                }
            }

            $sourceTotal++;
            unset($item, $record);
        }

        $duplicateBarcodes = [];

        foreach ($barcodeCounts as $barcode => $count) {
            if ($count > 1) {
                $duplicateBarcodes[$barcode] = true;
            }
        }

        return [
            'source_total' => $sourceTotal,
            'duplicate_source_ids' => $duplicateSourceIds,
            'duplicate_product_slugs' => $duplicateProductSlugs,
            'duplicate_skus' => $duplicateSkus,
            'duplicate_reasons' => $duplicateReasons,
            'duplicate_barcodes' => $duplicateBarcodes,
        ];
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

        $productsWithoutSourceVariantGroups = count(array_filter(
            $validRecords,
            static fn (array $item): bool => $item['metadata']['variant_options'] === [],
        ));
        $lowResolutionImages = 0;

        foreach ($validRecords as $item) {
            foreach ($item['images'] as $image) {
                if (str_contains($image['image_url'], '_img_80x80_')) {
                    $lowResolutionImages++;
                }
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
            'quality' => [
                'invalid_barcode' => $warningCounts['invalid_barcode'] ?? 0,
                'duplicate_barcode' => count($duplicateBarcodes),
                'existing_barcode_conflict' => 0,
                'missing_weight_policy' => $warningCounts['missing_weight_policy'] ?? 0,
                'products_without_source_variant_groups' => $productsWithoutSourceVariantGroups,
                'low_resolution_images' => $lowResolutionImages,
            ],
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
