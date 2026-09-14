<?php

namespace App\Console\Commands;

use App\Services\Import\ProductOwnedEngagementApplyService;
use Illuminate\Console\Command;
use Throwable;

final class ReconcileProductOwnedEngagement extends Command
{
    protected $signature = 'products:reconcile-owned-engagement
                            {--manifest=storage/app/reports/product-variant-normalization.json : T1.1 manifest path}
                            {--content-report=storage/app/reports/product-variant-content-reconciliation.json : T1.5 report path}
                            {--engagement-report=storage/app/reports/product-owned-engagement-reconciliation.json : T1.6 report path}
                            {--group=* : Explicit candidate group identifier}
                            {--apply : Persist the approved reconciliation}';

    protected $description = 'Prepare product-owned engagement for Product–Variant normalization';

    public function handle(ProductOwnedEngagementApplyService $service): int
    {
        try {
            $result = $service->execute(
                $this->absolutePath((string) $this->option('manifest')),
                $this->absolutePath((string) $this->option('content-report')),
                $this->absolutePath((string) $this->option('engagement-report')),
                array_map('strval', (array) $this->option('group')),
                (bool) $this->option('apply'),
            );
        } catch (Throwable $exception) {
            $this->error('Engagement reconciliation failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run'] ? 'Product-owned engagement dry-run' : 'Product-owned engagement apply');
        foreach ([
            'Groups requested' => 'groups_requested',
            'Groups validated' => 'groups_validated',
            'Groups reconciled' => 'groups_reconciled',
            'Already reconciled' => 'already_reconciled',
            'Records attributed' => 'records_attributed',
            'Records deduplicated' => 'records_deduplicated',
            'Records transferred' => 'records_transferred',
        ] as $label => $key) {
            $this->line($label.': '.$result[$key]);
        }
        $this->table(
            [
                'Group', 'Status', 'R attributed', 'R dedup', 'R moved',
                'Q dedup', 'Q moved', 'Answers kept', 'F dedup', 'F moved', 'Blockers',
            ],
            array_map(static fn (array $group): array => [
                $group['group_identifier'],
                $group['status'],
                $group['reviews_attributed'],
                $group['reviews_deduplicated'],
                $group['reviews_transferred'],
                $group['questions_deduplicated'],
                $group['questions_transferred'],
                $group['answers_preserved'],
                $group['favorites_deduplicated'],
                $group['favorites_transferred'],
                implode(', ', $group['blockers']),
            ], $result['groups']),
        );
        if ($result['dry_run']) {
            $this->comment('Dry-run complete: no database records were changed');
        }

        return self::SUCCESS;
    }

    private function absolutePath(string $path): string
    {
        $path = trim($path);
        if (preg_match('/^(?:[A-Za-z]:[\\\\\/]|[\\\\\/]{2})/', $path) === 1) {
            return $path;
        }

        return base_path(str_replace(['/', '\\'], DIRECTORY_SEPARATOR, $path));
    }
}
