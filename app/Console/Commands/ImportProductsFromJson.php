<?php

namespace App\Console\Commands;

use App\Services\Import\ProductJsonImportService;
use App\Support\Import\ProductImportResult;
use Illuminate\Console\Command;
use Throwable;

class ImportProductsFromJson extends Command
{
    protected $signature = 'import:products
                            {--dry-run : Analyze products without database or storage writes}
                            {--force : Run controlled write mode without confirmation}
                            {--offset=0 : Zero-based source record offset}
                            {--limit= : Maximum source records to plan or import}
                            {--default-weight= : Required variant weight in grams for write mode}';

    protected $description = 'Analyze or import a controlled product JSON batch';

    public function handle(ProductJsonImportService $service): int
    {
        $dryRun = (bool) $this->option('dry-run');
        $force = (bool) $this->option('force');

        if ($dryRun && $force) {
            $this->error('The --dry-run and --force options cannot be used together.');

            return self::INVALID;
        }

        $offset = filter_var(
            $this->option('offset'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 0]],
        );
        $limitOption = $this->option('limit');
        $limit = $limitOption === null
            ? null
            : filter_var(
                $limitOption,
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1]],
            );

        if ($offset === false || $limit === false) {
            $this->error('The --offset must be zero or greater and --limit must be a positive integer.');

            return self::INVALID;
        }

        $defaultWeight = null;

        if (! $dryRun) {
            if ($limit === null || $limit > 50) {
                $this->error('Write mode requires --limit between 1 and 50.');

                return self::INVALID;
            }

            $defaultWeight = filter_var(
                $this->option('default-weight'),
                FILTER_VALIDATE_INT,
                ['options' => ['min_range' => 1, 'max_range' => 100_000]],
            );

            if ($defaultWeight === false) {
                $this->error('Write mode requires --default-weight between 1 and 100000 grams.');

                return self::INVALID;
            }
        }

        $path = base_path('data-import/hasaki/all-products.json');

        try {
            $analysis = $service->analyzeFile($path, $offset, $limit);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        if ($dryRun) {
            $this->writeAnalysis($analysis, $path);
            $this->newLine();
            $this->comment('Dry-run complete: no database or storage writes were performed.');

            return $analysis->get('failed') > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (! $force && ! $this->confirm(
            "Import {$analysis->get('valid')} products from offset {$offset} with default weight {$defaultWeight}g?",
        )) {
            $this->warn('Product import cancelled: no database or storage writes were performed.');

            return self::SUCCESS;
        }

        try {
            $result = $service->persistAnalysis($analysis, $defaultWeight);
        } catch (Throwable $exception) {
            $this->error('Product import failed and was rolled back: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->writeAnalysis($result, $path);
        $this->writePersistence($result);

        return self::SUCCESS;
    }

    private function writeAnalysis(ProductImportResult $result, string $path): void
    {
        $data = $result->toArray();
        $this->info($data['transaction_result'] ?? null
            ? 'Product JSON controlled write'
            : 'Product JSON import dry-run');
        $this->line('Source: '.$path);
        $this->line("Source total: {$data['source_total']}");
        $this->line("Offset: {$data['offset']}");
        $this->line('Limit: '.($data['limit'] ?? 'all'));
        $this->line("Selected records: {$data['selected']}");
        $this->line("Valid: {$data['valid']}");
        $this->line("Quarantined: {$data['quarantined']}");
        $this->line("Failed: {$data['failed']}");
        $this->line("Duplicate source IDs: {$data['duplicate_source_ids']}");
        $this->line("Duplicate product slugs: {$data['duplicate_product_slugs']}");
        $this->line("Duplicate synthetic SKUs: {$data['duplicate_skus']}");
        $this->line("Duplicate optional barcodes dropped: {$data['duplicate_barcodes']}");
        $this->line("Invalid optional barcodes: {$data['quality']['invalid_barcode']}");
        $this->line("Existing barcode conflicts: {$data['quality']['existing_barcode_conflict']}");
        $this->line("Missing weight policy: {$data['quality']['missing_weight_policy']}");
        $this->line(
            "Products without source variant groups: {$data['quality']['products_without_source_variant_groups']}",
        );
        $this->line("Low-resolution images: {$data['quality']['low_resolution_images']}");
        $this->comment('Synthetic variant policy: exactly one HS-{productId} variant per valid product.');
        $this->comment('Inventory policy: no branch inventory operations are planned.');

        if (($data['transaction_result'] ?? null) !== null) {
            return;
        }

        $this->newLine();
        $this->info('Planned operations');
        $rows = [];

        foreach ($data['plans'] as $entity => $counters) {
            $rows[] = [$entity, $counters['create'], $counters['update'], $counters['unchanged']];
        }

        $this->table(['Entity', 'Create', 'Update', 'Unchanged'], $rows);

        if ($data['sample_mappings'] !== []) {
            $this->newLine();
            $this->info('Sample mappings (maximum 5)');
            $this->table(
                ['sourceId', 'product slug', 'SKU', 'brand', 'category', 'price', 'sale price', 'images'],
                $data['sample_mappings'],
            );
        }
    }

    private function writePersistence(ProductImportResult $result): void
    {
        $data = $result->toArray();
        $this->line("Default weight: {$data['default_weight']}g");
        $this->line("Transaction result: {$data['transaction_result']}");
        $this->newLine();
        $this->info('Write result');
        $rows = [];

        foreach ($data['write_counters'] as $entity => $counters) {
            $rows[] = [
                $entity,
                $counters['created'],
                $counters['updated'],
                $counters['restored'] ?? 0,
                $counters['unchanged'],
                $counters['stale_skipped'] ?? 0,
            ];
        }

        $this->table(
            ['Entity', 'Created', 'Updated', 'Restored', 'Unchanged', 'Stale skipped'],
            $rows,
        );

        if ($data['imported_mappings'] !== []) {
            $this->newLine();
            $this->info('Imported mappings (maximum 5)');
            $this->table(
                ['sourceId', 'product ID', 'product slug', 'variant ID', 'SKU'],
                $data['imported_mappings'],
            );
        }

        $this->comment('Controlled product import committed successfully.');
    }
}
