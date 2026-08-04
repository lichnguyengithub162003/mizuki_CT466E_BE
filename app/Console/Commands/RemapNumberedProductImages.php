<?php

namespace App\Console\Commands;

use App\Services\Import\NumberedProductImageRemapService;
use Illuminate\Console\Command;
use Throwable;

class RemapNumberedProductImages extends Command
{
    protected $signature = 'catalog:remap-numbered-images
        {--source=hasaki : Imported product source}
        {--dry-run : Report changes without database or filesystem writes}
        {--product= : Limit processing to one source external ID}
        {--delete-obsolete : Delete safe unreferenced hashed files after DB reconciliation}';

    protected $description = 'Remap imported product galleries to numbered local image files';

    public function handle(NumberedProductImageRemapService $service): int
    {
        try {
            $result = $service->execute(
                source: (string) $this->option('source'),
                externalId: $this->option('product') === null
                    ? null
                    : (string) $this->option('product'),
                dryRun: (bool) $this->option('dry-run'),
                deleteObsolete: (bool) $this->option('delete-obsolete'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run')
            ? 'Numbered product image remap dry-run'
            : 'Numbered product image remap');
        $this->table(['Metric', 'Count'], collect($result)
            ->except('manifest_paths')
            ->map(fn (mixed $value, string $key): array => [$key, $value])
            ->values()
            ->all());

        foreach ($result['manifest_paths'] as $manifestPath) {
            $this->line('Deletion manifest: '.$manifestPath);
        }

        return $result['failures'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
