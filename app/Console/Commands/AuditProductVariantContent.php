<?php

namespace App\Console\Commands;

use App\Services\Import\ProductVariantContentReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class AuditProductVariantContent extends Command
{
    protected $signature = 'products:audit-variant-content
                            {--manifest=storage/app/reports/product-variant-normalization.json : T1.1 manifest path}
                            {--output=storage/app/reports/product-variant-content-reconciliation.json : Reconciliation report path}';

    protected $description = 'Audit product-level content for Product–Variant normalization candidates';

    public function handle(ProductVariantContentReconciliationService $service): int
    {
        $manifest = trim((string) $this->option('manifest'));
        $output = trim((string) $this->option('output'));

        if ($manifest === '' || $output === '') {
            $this->error('The --manifest and --output options must not be empty');

            return self::INVALID;
        }

        try {
            $result = $service->audit($this->absolutePath($manifest));
            $outputPath = $this->absolutePath($output);
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $result->toJson());
        } catch (Throwable $exception) {
            $this->error('Unable to create content reconciliation report: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = $result->toArray()['summary'];
        $this->info('Product–Variant content reconciliation audit');
        $this->line('Candidate groups analyzed: '.$summary['total_candidate_groups_analyzed']);
        $this->line('Already normalized: '.$summary['already_normalized']);
        $this->line('Merge ready: '.$summary['merge_ready']);
        $this->line('Merge after variant content move: '.$summary['merge_after_variant_content_move']);
        $this->line('Manual review: '.$summary['manual_review']);
        $this->line('Do not merge: '.$summary['do_not_merge']);
        $this->line('Report: '.$outputPath);
        $this->comment('Audit complete: no database records were changed');

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
