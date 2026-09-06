<?php

namespace App\Console\Commands;

use App\Services\Media\CatalogMediaMigrationService;
use Illuminate\Console\Command;
use InvalidArgumentException;
use Throwable;

class MigrateCatalogMedia extends Command
{
    protected $signature = 'media:migrate-catalog
        {--limit=50 : Maximum products to inspect (1-100)}
        {--from-id= : Inclusive first product ID}
        {--to-id= : Inclusive last product ID}
        {--product=* : Restrict to product ID; repeat for a list}
        {--dry-run : Report the plan without uploads or database writes}';

    protected $description = 'Safely migrate a bounded batch of local catalog images to Cloudinary';

    public function handle(CatalogMediaMigrationService $service): int
    {
        try {
            $result = $service->execute(
                limit: $this->integerOption('limit') ?? 50,
                fromId: $this->integerOption('from-id'),
                toId: $this->integerOption('to-id'),
                productIds: $this->productIds(),
                dryRun: (bool) $this->option('dry-run'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run'] ? 'Catalog media migration dry-run' : 'Catalog media migration');
        $this->table(['Metric', 'Count'], collect($result)
            ->except(['dry_run', 'items'])
            ->map(fn (mixed $value, string $key): array => [$key, $value])
            ->values()
            ->all());

        $notableItems = collect($result['items'])
            ->reject(fn (array $item): bool => in_array($item['status'], ['planned', 'migrated'], true));
        if ($notableItems->isNotEmpty()) {
            $this->table(
                ['Image', 'Product', 'Variant', 'Status', 'Message'],
                $notableItems->map(fn (array $item): array => [
                    $item['image_id'],
                    $item['product_id'],
                    $item['variant_id'] ?? '-',
                    $item['status'],
                    $item['message'] ?? '-',
                ])->all(),
            );
        }

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }

    private function integerOption(string $name): ?int
    {
        $value = $this->option($name);
        if ($value === null || $value === '') {
            return null;
        }
        if (filter_var($value, FILTER_VALIDATE_INT) === false) {
            throw new InvalidArgumentException("--{$name} must be an integer.");
        }

        return (int) $value;
    }

    /** @return list<int> */
    private function productIds(): array
    {
        return collect($this->option('product'))
            ->map(function (mixed $value): int {
                if (filter_var($value, FILTER_VALIDATE_INT) === false) {
                    throw new InvalidArgumentException('--product values must be integers.');
                }

                return (int) $value;
            })
            ->unique()
            ->values()
            ->all();
    }
}
