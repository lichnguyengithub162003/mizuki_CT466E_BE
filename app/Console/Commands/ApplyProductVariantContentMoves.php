<?php

namespace App\Console\Commands;

use App\Services\Import\ProductVariantContentMoveApplyService;
use Illuminate\Console\Command;
use Throwable;

final class ApplyProductVariantContentMoves extends Command
{
    protected $signature = 'products:apply-variant-content-moves
                            {--plan=storage/app/reports/product-variant-content-move-plan.json : Latest approved T1.9B plan}
                            {--group=* : Explicit ready group identifier}
                            {--apply : Persist the validated content move and normalization}';

    protected $description = 'Validate or apply approved Product-to-Variant content move plans';

    public function handle(ProductVariantContentMoveApplyService $service): int
    {
        try {
            $result = $service->execute(
                $this->absolutePath((string) $this->option('plan')),
                array_map('strval', (array) $this->option('group')),
                (bool) $this->option('apply'),
            );
        } catch (Throwable $exception) {
            $this->error('Variant content apply failed: '.$exception->getMessage());

            return self::FAILURE;
        }

        $this->info($result['dry_run'] ? 'Product–Variant content apply dry-run' : 'Product–Variant content applied');
        foreach ([
            'Groups requested' => 'groups_requested', 'Groups validated' => 'groups_validated',
            'Groups normalized' => 'groups_normalized', 'Already normalized' => 'already_normalized',
            'Canonical names updated' => 'canonical_names_updated',
            'Product specification keys removed' => 'product_specification_keys_removed',
            'Images attributed' => 'images_attributed', 'Variants reparented' => 'variants_reparented',
            'Order items transferred' => 'order_items_transferred', 'Products retired' => 'products_retired',
        ] as $label => $key) {
            $this->line($label.': '.$result[$key]);
        }
        $this->table(
            ['Group', 'Status', 'Name', 'Specs', 'Images', 'Variants', 'Retired'],
            array_map(static fn (array $group): array => [
                $group['group_identifier'], $group['status'], $group['canonical_names_updated'],
                $group['product_specification_keys_removed'], $group['images_attributed'],
                $group['variants_reparented'], $group['products_retired'],
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
