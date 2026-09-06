<?php

namespace App\Console\Commands;

use App\Services\Admin\AdminMediaService;
use Illuminate\Console\Command;

class CleanupStagingMedia extends Command
{
    protected $signature = 'media:cleanup-staging {--batch=100 : Maximum records per database batch}';

    protected $description = 'Expire abandoned staging uploads and retry pending owned-media cleanup';

    public function handle(AdminMediaService $media): int
    {
        $batchSize = max(1, min(1000, (int) $this->option('batch')));
        $result = $media->cleanup($batchSize);

        $this->info(sprintf(
            'Staging cleanup complete: expired=%d, deleted=%d, failed=%d',
            $result['expired'],
            $result['deleted'],
            $result['failed'],
        ));

        return $result['failed'] === 0 ? self::SUCCESS : self::FAILURE;
    }
}
