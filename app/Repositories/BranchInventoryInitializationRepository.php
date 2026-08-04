<?php

namespace App\Repositories;

use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\BranchInventory;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Support\Facades\DB;

class BranchInventoryInitializationRepository
{
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback);
    }

    /**
     * @param  list<string>  $legacyCodes
     */
    public function findForProfile(string $code, array $legacyCodes = []): ?Branch
    {
        return Branch::query()
            ->withTrashed()
            ->whereIn('code', array_values(array_unique([$code, ...$legacyCodes])))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$code])
            ->first();
    }

    /**
     * @param  array<string, mixed>  $profile
     * @param  list<string>  $legacyCodes
     * @return array{branch: Branch, action: string}
     */
    public function ensureBranch(array $profile, array $legacyCodes): array
    {
        $branch = Branch::query()
            ->withTrashed()
            ->whereIn('code', array_values(array_unique([$profile['code'], ...$legacyCodes])))
            ->orderByRaw('CASE WHEN code = ? THEN 0 ELSE 1 END', [$profile['code']])
            ->lockForUpdate()
            ->first();

        if ($branch === null) {
            return [
                'branch' => Branch::query()->create($profile),
                'action' => 'created',
            ];
        }

        $branch->fill($profile);
        $wasTrashed = $branch->trashed();
        $wasDirty = $branch->isDirty() || $wasTrashed;

        if ($wasTrashed) {
            $branch->restore();
        }

        if ($branch->isDirty()) {
            $branch->save();
        }

        return [
            'branch' => $branch->refresh(),
            'action' => $wasDirty ? 'updated' : 'unchanged',
        ];
    }

    /**
     * @param  list<array{weekday: int, opens_at: string, closes_at: string, is_closed: bool}>  $hours
     */
    public function ensureBusinessHours(Branch $branch, array $hours): void
    {
        foreach ($hours as $attributes) {
            BranchBusinessHour::query()->updateOrCreate(
                ['branch_id' => $branch->id, 'weekday' => $attributes['weekday']],
                [
                    'opens_at' => $attributes['opens_at'],
                    'closes_at' => $attributes['closes_at'],
                    'is_closed' => $attributes['is_closed'],
                ],
            );
        }
    }

    public function activeSellableVariantCount(): int
    {
        return $this->activeSellableVariants()->count();
    }

    /**
     * @return array{created: int, preserved: int}
     */
    public function backfillMissingInventories(Branch $branch, int $chunkSize = 250): array
    {
        $created = 0;
        $preserved = 0;

        $this->activeSellableVariants()
            ->select(['product_variants.id', 'product_variants.sku'])
            ->chunkById($chunkSize, function ($variants) use ($branch, &$created, &$preserved): void {
                $variantIds = $variants->pluck('id')->all();
                $existingIds = BranchInventory::query()
                    ->where('branch_id', $branch->id)
                    ->whereIn('product_variant_id', $variantIds)
                    ->pluck('product_variant_id')
                    ->mapWithKeys(fn (mixed $id): array => [(int) $id => true]);
                $now = now();
                $rows = [];

                foreach ($variants as $variant) {
                    if ($existingIds->has((int) $variant->id)) {
                        $preserved++;

                        continue;
                    }

                    $rows[] = [
                        'branch_id' => $branch->id,
                        'product_variant_id' => $variant->id,
                        'quantity' => $this->deterministicInitialQuantity(
                            $branch->code,
                            (int) $variant->id,
                            (string) $variant->sku,
                        ),
                        'reserved_quantity' => 0,
                        'reorder_level' => 5,
                        'created_at' => $now,
                        'updated_at' => $now,
                    ];
                }

                if ($rows === []) {
                    return;
                }

                $inserted = DB::table('branch_inventories')->insertOrIgnore($rows);
                $created += $inserted;
                $preserved += count($rows) - $inserted;
            });

        return ['created' => $created, 'preserved' => $preserved];
    }

    public function deterministicInitialQuantity(string $branchCode, int $variantId, string $sku): int
    {
        $score = (int) (hexdec(substr(hash('sha256', "{$branchCode}|{$sku}|{$variantId}"), 0, 8)) % 100);

        if ($score < 10) {
            return 0;
        }

        if ($score < 35) {
            return 1 + ($score % 5);
        }

        return 6 + ($score % 45);
    }

    /**
     * @param  list<int>  $branchIds
     * @return array{expected: int, actual: int, missing: int, duplicates: int, negative: int}
     */
    public function inventoryIntegrity(array $branchIds): array
    {
        $variantIds = $this->activeSellableVariants()->pluck('product_variants.id');
        $expected = count($branchIds) * $variantIds->count();
        $base = BranchInventory::query()
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_variant_id', $variantIds);
        $actual = (clone $base)->count();
        $duplicates = DB::table('branch_inventories')
            ->whereIn('branch_id', $branchIds)
            ->whereIn('product_variant_id', $variantIds)
            ->select(['branch_id', 'product_variant_id'])
            ->groupBy(['branch_id', 'product_variant_id'])
            ->havingRaw('COUNT(*) > 1')
            ->get()
            ->count();
        $negative = (clone $base)
            ->where(function ($query): void {
                $query->where('quantity', '<', 0)
                    ->orWhere('reserved_quantity', '<', 0);
            })
            ->count();

        return [
            'expected' => $expected,
            'actual' => $actual,
            'missing' => max(0, $expected - $actual),
            'duplicates' => $duplicates,
            'negative' => $negative,
        ];
    }

    private function activeSellableVariants()
    {
        return ProductVariant::query()
            ->where('product_variants.is_active', true)
            ->whereHas('product', fn ($query) => $query->where('is_active', true));
    }
}
