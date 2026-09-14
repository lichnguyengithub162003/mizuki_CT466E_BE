<?php

namespace App\Console\Commands;

use App\Services\Import\ProductOwnedEngagementReconciliationService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class AuditProductOwnedEngagement extends Command
{
    protected $signature = 'products:audit-owned-engagement
                            {--manifest=storage/app/reports/product-variant-normalization.json : T1.1 manifest path}
                            {--content-report=storage/app/reports/product-variant-content-reconciliation.json : T1.5 report path}
                            {--output=storage/app/reports/product-owned-engagement-reconciliation.json : T1.6 report path}';

    protected $description = 'Audit review, question and favorite consolidation for normalization candidates';

    public function handle(ProductOwnedEngagementReconciliationService $service): int
    {
        $paths = array_map(
            fn (string $option): string => trim((string) $this->option($option)),
            ['manifest', 'content-report', 'output'],
        );
        if (in_array('', $paths, true)) {
            $this->error('Manifest, content report and output paths must not be empty');

            return self::INVALID;
        }

        try {
            $result = $service->audit($this->absolutePath($paths[0]), $this->absolutePath($paths[1]));
            $output = $this->absolutePath($paths[2]);
            File::ensureDirectoryExists(dirname($output));
            File::put($output, $result->toJson());
        } catch (Throwable $exception) {
            $this->error('Unable to create engagement reconciliation report: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = $result->toArray()['summary'];
        $this->info('Product-owned engagement reconciliation audit');
        $this->line('Groups with engagement: '.$summary['total_groups_with_reviews_questions_or_favorites']);
        $this->line('Automatically resolvable: '.$summary['groups_that_can_be_resolved_automatically']);
        $this->line('Dedup only: '.$summary['groups_requiring_dedup_only']);
        $this->line('Variant attribution: '.$summary['groups_requiring_variant_attribution']);
        $this->line('Manual conflicts: '.$summary['genuine_manual_conflicts']);
        $this->line('Report: '.$output);
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
