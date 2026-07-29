<?php

namespace App\Console\Commands;

use App\Services\Import\ClinicServiceJsonImportService;
use Illuminate\Console\Command;
use Throwable;

class ImportClinicServicesFromJson extends Command
{
    protected $signature = 'import:clinic-services
                            {--dry-run : Analyze source without database or storage writes}
                            {--branch= : Target Mizuki clinic branch ID}
                            {--force : Skip write-mode confirmation}';

    protected $description = 'Analyze or import clinic services from JSON';

    public function handle(ClinicServiceJsonImportService $service): int
    {
        $branchId = filter_var(
            $this->option('branch'),
            FILTER_VALIDATE_INT,
            ['options' => ['min_range' => 1]],
        );

        if ($branchId === false) {
            $this->error('The --branch option is required and must be a positive integer.');

            return self::INVALID;
        }

        $branch = $service->eligibleBranch($branchId);

        if ($branch === null) {
            $this->error('Branch not found, inactive, or does not support clinic services.');

            return self::FAILURE;
        }

        try {
            $result = $service->analyzeFile(
                base_path('data-import/hasaki/clinic-services.json'),
                $branch,
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($this->option('dry-run') ? 'Clinic service JSON import dry-run' : 'Clinic service JSON import write-mode');
        $this->line("Branch: {$branch->id} - {$branch->name}");
        $this->line("Valid records: {$result['valid']}");
        $this->line("Total records: {$result['total']}");
        $this->line("Valid planned inserts: {$result['planned_inserts']}");
        $this->line("Valid planned updates: {$result['planned_updates']}");
        $this->line("Quarantined: {$result['quarantined']}");
        $this->line("Failed: {$result['failed']}");
        $this->line("Duplicate source IDs: {$result['duplicate_source_ids']}");
        $this->line("Duplicate slugs: {$result['duplicate_slugs']}");
        $this->line("Planned branch attachments: {$result['planned_branch_attachments']}");
        $this->line('Service upsert key: slug');
        $this->line('Branch service key: branch_id + service_id');
        $this->line('Default future capacity: 1');

        /** @var array<string, int> $durationSources */
        $durationSources = $result['duration_sources'];
        $this->newLine();
        $this->info('Duration sources');
        $this->line("Numeric: {$durationSources['numeric']}");
        $this->line("Safely parsed: {$durationSources['safely_parsed']}");
        $this->line("Range: {$durationSources['range']}");
        $this->line("Unparseable: {$durationSources['unparseable']}");

        if ($result['planned_samples'] !== []) {
            $this->newLine();
            $this->info('Sample planned mappings (maximum 5)');
            $this->table(
                ['sourceId', 'sku', 'operation', 'slug', 'name', 'duration', 'price', 'category', 'attach'],
                $result['planned_samples'],
            );
        }

        if ($result['quarantine_examples'] !== []) {
            $this->newLine();
            $this->info('Quarantine examples (maximum 5)');
            $this->table(['sourceId', 'reason'], $result['quarantine_examples']);
        }

        /** @var array<string, array<int, string>> $quarantinedByReason */
        $quarantinedByReason = $result['quarantined_by_reason'];

        foreach ($quarantinedByReason as $reason => $sourceIds) {
            $this->line($reason.': '.implode(', ', $sourceIds));
        }

        if ($result['failure_examples'] !== []) {
            $this->newLine();
            $this->info('Failure examples (maximum 5)');
            $this->table(['sourceId', 'reason'], $result['failure_examples']);
        }

        $this->newLine();

        if ($this->option('dry-run')) {
            $this->comment('Dry-run complete: no database or storage writes were performed.');

            return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
        }

        if (! $this->option('force') && ! $this->confirm(
            "Import {$result['valid']} valid clinic services into branch {$branch->id}?",
        )) {
            $this->warn('Import cancelled: no database writes were performed.');
            $this->writeSummary($result + ['rolled_back' => false]);

            return self::SUCCESS;
        }

        try {
            $result = $service->persistAnalysis($result, $branch);
        } catch (Throwable $exception) {
            $this->error('Import failed: '.$exception->getMessage());
            $this->writeSummary($result + ['rolled_back' => true]);

            return self::FAILURE;
        }

        $this->writeSummary($result);

        return $result['failed'] > 0 ? self::FAILURE : self::SUCCESS;
    }

    /** @param array<string, mixed> $result */
    private function writeSummary(array $result): void
    {
        $this->newLine();
        $this->info('Write summary');
        $this->line('Created services: '.($result['created_services'] ?? 0));
        $this->line('Updated services: '.($result['updated_services'] ?? 0));
        $this->line('Unchanged services: '.($result['unchanged_services'] ?? 0));
        $this->line('Created branch-service links: '.($result['created_branch_service_links'] ?? 0));
        $this->line('Updated branch-service links: '.($result['updated_branch_service_links'] ?? 0));
        $this->line('Unchanged branch-service links: '.($result['unchanged_branch_service_links'] ?? 0));
        $this->line('Rolled back: '.(($result['rolled_back'] ?? false) ? 'yes' : 'no'));
    }
}
