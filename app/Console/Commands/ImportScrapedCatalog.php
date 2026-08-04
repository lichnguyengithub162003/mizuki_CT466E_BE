<?php

namespace App\Console\Commands;

use App\Services\Import\ProductJsonImportService;
use App\Support\Import\ProductImportResult;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Storage;
use JsonException;
use Throwable;

class ImportScrapedCatalog extends Command
{
    protected $signature = 'mizuki:import-scraped-data
                            {--source=hasaki : Source name or project-relative JSON path}
                            {--dry-run : Validate and plan without database or public-storage writes}
                            {--limit= : Maximum source records to process}
                            {--update-existing : Update records already imported from the source}
                            {--skip-images : Use the stable Mizuki fallback image}
                            {--default-weight=500 : Development shipping weight in grams}';

    protected $description = 'Import a frontend-ready catalog from normalized crawler JSON';

    public function handle(ProductJsonImportService $service): int
    {
        $limit = $this->positiveIntegerOption('limit', nullable: true);
        $weight = $this->positiveIntegerOption('default-weight');

        if ($limit === false || $weight === false) {
            $this->error('The limit and default weight options must be positive integers.');

            return self::INVALID;
        }

        $path = $this->sourcePath((string) $this->option('source'));

        try {
            $dryRun = (bool) $this->option('dry-run');
            $result = $service->processCatalogFile(
                path: $path,
                offset: 0,
                limit: $limit,
                defaultWeight: $weight,
                sourceDirectory: dirname($path),
                updateExisting: (bool) $this->option('update-existing'),
                skipImages: (bool) $this->option('skip-images'),
                dryRun: $dryRun,
            );
            $reportPath = $this->writeReport($result, $path, $dryRun);
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->renderSummary($result, $reportPath);

        return self::SUCCESS;
    }

    private function sourcePath(string $source): string
    {
        $relative = $source === '' || $source === 'hasaki'
            ? 'data-import/hasaki/all-products.json'
            : $source;
        $path = str_starts_with($relative, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\/]/', $relative) === 1
                ? $relative
                : base_path($relative);

        return $path;
    }

    private function positiveIntegerOption(string $name, bool $nullable = false): int|false|null
    {
        $value = $this->option($name);

        if ($nullable && ($value === null || $value === '')) {
            return null;
        }

        return filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1]]);
    }

    /** @throws JsonException */
    private function writeReport(ProductImportResult $result, string $source, bool $dryRun): string
    {
        $path = 'import-reports/catalog-'.now()->format('Ymd-His').'.json';
        $payload = [
            'generated_at' => now()->toIso8601String(),
            'mode' => $dryRun ? 'dry-run' : 'write',
            'source' => str_replace(base_path().DIRECTORY_SEPARATOR, '', $source),
            'result' => $result->summary(),
        ];
        Storage::disk('local')->put(
            $path,
            json_encode($payload, JSON_PRETTY_PRINT | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR),
        );

        return Storage::disk('local')->path($path);
    }

    private function renderSummary(ProductImportResult $result, string $reportPath): void
    {
        $data = $result->toArray();
        $this->info(($data['transaction_result'] ?? null) === null
            ? 'Mizuki scraped catalog dry-run'
            : 'Mizuki scraped catalog import');
        $this->table(['Metric', 'Count'], [
            ['source_total', $data['source_total']],
            ['selected', $data['selected']],
            ['valid', $data['valid']],
            ['quarantined', $data['quarantined']],
            ['failed', $data['failed']],
            ['duplicate_source_ids', $data['duplicate_source_ids']],
        ]);

        if (isset($data['write_counters'])) {
            foreach ($data['write_counters'] as $entity => $counts) {
                $this->line(sprintf(
                    '%s: created=%d updated=%d unchanged=%d',
                    $entity,
                    $counts['created'] ?? 0,
                    $counts['updated'] ?? 0,
                    $counts['unchanged'] ?? 0,
                ));
            }
            $this->line('Skipped existing records: '.($data['skipped_existing_records'] ?? 0));
            $this->line('Mizuki seed inventory created: '.($data['inventory']['created'] ?? 0));
        }

        $this->line(sprintf(
            'Peak memory: %.2f MiB',
            ((int) ($data['peak_memory_bytes'] ?? memory_get_peak_usage(true))) / 1024 / 1024,
        ));
        $this->line('Machine-readable report: '.$reportPath);

        if (($data['quarantine_examples'] ?? []) !== []) {
            $this->warn('Invalid records were skipped; inspect the machine-readable report.');
        }
    }
}
