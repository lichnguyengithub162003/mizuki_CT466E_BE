<?php

namespace App\Console\Commands;

use App\Services\Import\ProductVariantNormalizationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class PlanProductVariantNormalization extends Command
{
    protected $signature = 'products:plan-variant-normalization
                            {--source=hasaki : Imported product source to analyze}
                            {--output=storage/app/reports/product-variant-normalization.json : Manifest output path}';

    protected $description = 'Create a read-only product-to-variant normalization candidate manifest';

    public function handle(ProductVariantNormalizationService $service): int
    {
        $source = trim((string) $this->option('source'));
        $output = trim((string) $this->option('output'));

        if ($source === '' || $output === '') {
            $this->error('The --source and --output options must not be empty');

            return self::INVALID;
        }

        $path = $this->absolutePath($output);

        try {
            $result = $service->plan($source);
            File::ensureDirectoryExists(dirname($path));
            File::put($path, $result->toJson());
        } catch (Throwable $exception) {
            $this->error('Unable to create normalization manifest: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = $result->toArray()['summary'];
        $this->info('Product–Variant normalization dry-run');
        $this->line('Source: '.$source);
        $this->line('Products scanned: '.$summary['products_scanned']);
        $this->line('Candidate groups: '.$summary['candidate_groups']);
        $this->line('Auto safe: '.$summary['auto_safe']);
        $this->line('Manual review: '.$summary['manual_review']);
        $this->line('Rejected: '.$summary['rejected']);
        $this->line('Products involved: '.$summary['products_involved']);
        $this->line('Groups with order/review/favorite conflicts: '.$summary['groups_with_order_review_favorite_conflicts']);
        $this->line('Manifest: '.$path);
        $this->comment('Dry-run complete: no database records were changed');

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return $path;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }
}
