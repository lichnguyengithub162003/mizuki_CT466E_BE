<?php

namespace App\Console\Commands;

use App\Services\Import\ProductVariantNormalizationApplyService;
use Illuminate\Console\Command;
use Throwable;

class NormalizeProductVariants extends Command
{
    protected $signature = 'products:normalize-variants
        {--manifest=storage/app/reports/product-variant-normalization.json : T1.1 manifest path}
        {--group=* : Approved T1.1 group identifier; repeat to select multiple groups}
        {--apply : Apply the validated merge; omission is dry-run}';

    protected $description = 'Validate or apply allowlisted Product-to-Variant normalization groups';

    public function handle(ProductVariantNormalizationApplyService $service): int
    {
        try {
            $result = $service->execute(
                manifestPath: (string) $this->option('manifest'),
                groupIdentifiers: array_values($this->option('group')),
                apply: (bool) $this->option('apply'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run']
            ? 'Product–Variant normalization dry-run'
            : 'Product–Variant normalization applied');
        $this->table(['Metric', 'Count'], collect($result)
            ->except(['dry_run', 'groups'])
            ->map(fn (mixed $value, string $key): array => [$key, $value])
            ->values()->all());
        $this->table(['Group', 'Status'], collect($result['groups'])
            ->map(fn (array $group): array => [$group['group_identifier'], $group['status']])
            ->all());

        return self::SUCCESS;
    }
}
