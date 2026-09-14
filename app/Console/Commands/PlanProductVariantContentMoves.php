<?php

namespace App\Console\Commands;

use App\Services\Import\ProductVariantContentMovePlanningService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Throwable;

final class PlanProductVariantContentMoves extends Command
{
    protected $signature = 'products:plan-variant-content-moves
                            {--content-report=storage/app/reports/product-variant-content-reconciliation.json : Latest T1.5 report path}
                            {--output=storage/app/reports/product-variant-content-move-plan.json : T1.9 plan path}';

    protected $description = 'Plan read-only Product-to-Variant content and image attribution';

    public function handle(ProductVariantContentMovePlanningService $service): int
    {
        $report = trim((string) $this->option('content-report'));
        $output = trim((string) $this->option('output'));
        if ($report === '' || $output === '') {
            $this->error('The --content-report and --output options must not be empty');

            return self::INVALID;
        }

        try {
            $result = $service->audit($this->absolutePath($report));
            $outputPath = $this->absolutePath($output);
            File::ensureDirectoryExists(dirname($outputPath));
            File::put($outputPath, $result->toJson());
        } catch (Throwable $exception) {
            $this->error('Unable to create variant-content move plan: '.$exception->getMessage());

            return self::FAILURE;
        }

        $summary = $result->toArray()['summary'];
        $this->info('Product–Variant content move planning audit');
        $this->line('Groups analyzed: '.$summary['groups_analyzed']);
        $this->line('Ready for apply: '.$summary['ready_for_apply']);
        $this->line('Needs manual rule: '.$summary['needs_manual_rule']);
        $this->line('Blocked: '.$summary['blocked']);
        $this->line('Variant attribute writes proposed: '.$summary['total_variant_attribute_writes_proposed']);
        $this->line('Image rows requiring attribution: '.$summary['total_image_rows_requiring_attribution']);
        $this->line('Report: '.$outputPath);
        $this->comment('Planning complete: no database records were changed');

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
