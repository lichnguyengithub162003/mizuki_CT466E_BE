<?php

namespace App\Console\Commands;

use App\Services\Import\ProductJsonImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportProductsFromJson extends Command
{
    protected $signature = 'import:products
                            {--dry-run : Analyze products without database or storage writes}
                            {--offset=0 : Zero-based source record offset}
                            {--limit= : Maximum source records to plan}';

    protected $description = 'Analyze product JSON and plan catalog import operations';

    public function handle(ProductJsonImportService $service): int
    {
        if (! $this->option('dry-run')) {
            $this->error('The --dry-run option is required. Product write mode is not implemented.');

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

        $path = base_path('data-import/hasaki/all-products.json');

        try {
            $result = $service->analyzeFile($path, $offset, $limit)->toArray();
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info('Product JSON import dry-run');
        $this->line('Source: '.$path);
        $this->line("Source total: {$result['source_total']}");
        $this->line("Offset: {$result['offset']}");
        $this->line('Limit: '.($result['limit'] ?? 'all'));
        $this->line("Selected records: {$result['selected']}");
        $this->line("Valid: {$result['valid']}");
        $this->line("Quarantined: {$result['quarantined']}");
        $this->line("Failed: {$result['failed']}");
        $this->line("Duplicate source IDs: {$result['duplicate_source_ids']}");
        $this->line("Duplicate product slugs: {$result['duplicate_product_slugs']}");
        $this->line("Duplicate synthetic SKUs: {$result['duplicate_skus']}");
        $this->line("Duplicate optional barcodes dropped: {$result['duplicate_barcodes']}");
        $this->line('Missing weight policy: '.($result['warnings']['missing_weight_policy'] ?? 0));
        $this->comment('Synthetic variant policy: exactly one HS-{productId} variant per valid product.');
        $this->comment('Inventory policy: no branch inventory operations are planned.');

        $this->newLine();
        $this->info('Planned operations');
        $rows = [];

        foreach ($result['plans'] as $entity => $counters) {
            $rows[] = [
                $entity,
                $counters['create'],
                $counters['update'],
                $counters['unchanged'],
            ];
        }

        $this->table(['Entity', 'Create', 'Update', 'Unchanged'], $rows);

        if ($result['sample_mappings'] !== []) {
            $this->newLine();
            $this->info('Sample mappings (maximum 5)');
            $this->table(
                ['sourceId', 'product slug', 'SKU', 'brand', 'category', 'price', 'sale price', 'images'],
                $result['sample_mappings'],
            );
        }

        foreach (['quarantine_examples', 'failure_examples'] as $issues) {
            if ($result[$issues] === []) {
                continue;
            }

            $this->newLine();
            $this->info($issues === 'quarantine_examples'
                ? 'Quarantine examples (maximum 5)'
                : 'Failure examples (maximum 5)');
            $this->table(['sourceId', 'reason'], $result[$issues]);
        }

        $this->newLine();
        $this->comment('Dry-run complete: no database or storage writes were performed.');

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }
}
