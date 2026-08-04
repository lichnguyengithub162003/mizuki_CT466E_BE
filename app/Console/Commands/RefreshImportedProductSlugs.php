<?php

namespace App\Console\Commands;

use App\Services\Import\ProductJsonImportService;
use Illuminate\Console\Command;
use Throwable;

class RefreshImportedProductSlugs extends Command
{
    protected $signature = 'mizuki:refresh-imported-product-slugs
        {--source=hasaki : Imported product source}
        {--dry-run : Report changes without writing them}';

    protected $description = 'Refresh customer-facing slugs for imported products';

    public function handle(ProductJsonImportService $service): int
    {
        try {
            $result = $service->refreshImportedProductSlugs(
                (string) $this->option('source'),
                (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run']
            ? 'Imported product slug refresh dry-run'
            : 'Imported product slug refresh');
        $this->table(['Metric', 'Count'], [
            ['total', $result['total']],
            ['updated', $result['updated']],
            ['unchanged', $result['unchanged']],
            ['conflicts', $result['conflicts']],
        ]);

        return $result['conflicts'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
