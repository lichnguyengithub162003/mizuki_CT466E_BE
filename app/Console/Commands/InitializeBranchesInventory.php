<?php

namespace App\Console\Commands;

use App\Services\BranchInventoryInitializationService;
use Illuminate\Console\Command;
use Throwable;

class InitializeBranchesInventory extends Command
{
    protected $signature = 'mizuki:initialize-branches-inventory
                            {--dry-run : Report missing branches and inventory pairs without writing}
                            {--branch= : Limit initialization to one stable branch code}';

    protected $description = 'Create persistent Mizuki branches and backfill only missing inventory pairs';

    public function handle(BranchInventoryInitializationService $service): int
    {
        try {
            $result = $service->initialize(
                dryRun: (bool) $this->option('dry-run'),
                branchCode: $this->option('branch') === null
                    ? null
                    : (string) $this->option('branch'),
            );
        } catch (Throwable $exception) {
            $this->error($exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run']
            ? 'Mizuki branch and inventory initialization dry-run'
            : 'Mizuki branch and inventory initialization completed');
        $this->line('Unsupported by the current schema: '.implode(', ', $result['unsupported_profile_fields']));
        $this->table(['Metric', 'Count'], [
            ['branches_created', $result['branches']['created']],
            ['branches_updated', $result['branches']['updated']],
            ['branches_unchanged', $result['branches']['unchanged']],
            ['active_branches', $result['active_branches']],
            ['active_variants', $result['active_variants']],
            ['inventory_created', $result['inventory']['created']],
            ['inventory_preserved', $result['inventory']['preserved']],
            ['inventory_expected', $result['integrity']['expected']],
            ['inventory_actual', $result['integrity']['actual']],
            ['inventory_missing', $result['integrity']['missing']],
            ['inventory_duplicates', $result['integrity']['duplicates']],
            ['inventory_negative', $result['integrity']['negative']],
        ]);

        return self::SUCCESS;
    }
}
