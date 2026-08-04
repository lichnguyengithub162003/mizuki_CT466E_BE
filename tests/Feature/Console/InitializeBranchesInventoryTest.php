<?php

use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Repositories\BranchInventoryInitializationRepository;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;

uses(RefreshDatabase::class);

/** @return list<ProductVariant> */
function initializerVariants(int $count): array
{
    $category = Category::query()->create([
        'name' => 'Initializer Category',
        'slug' => 'initializer-category',
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Initializer Brand',
        'slug' => 'initializer-brand',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Initializer Product',
        'slug' => 'initializer-product',
        'is_active' => true,
    ]);

    return array_map(
        fn (int $index): ProductVariant => ProductVariant::query()->create([
            'product_id' => $product->id,
            'name' => "Variant {$index}",
            'sku' => "INIT-SKU-{$index}",
            'price' => 100_000 + $index,
            'weight' => 500,
            'is_active' => true,
        ]),
        range(1, $count),
    );
}

test('dry run reports planned records without writing branches or inventory', function (): void {
    initializerVariants(2);

    $this->artisan('mizuki:initialize-branches-inventory', ['--dry-run' => true])
        ->expectsOutput('Mizuki branch and inventory initialization dry-run')
        ->assertSuccessful();

    expect(Branch::query()->count())->toBe(0)
        ->and(BranchInventory::query()->count())->toBe(0);
});

test('creates six persistent branches with complete profiles and business hours', function (): void {
    initializerVariants(1);

    $this->artisan('mizuki:initialize-branches-inventory')->assertSuccessful();

    $branches = Branch::query()->get();
    $actualCodes = $branches->pluck('code')->sort()->values()->all();
    $expectedCodes = [
        'MZ-NK-01',
        'MZ-CR-01',
        'MZ-BT-01',
        'MZ-OM-01',
        'MZ-TN-01',
        'MZ-VL-01',
    ];
    sort($expectedCodes);

    expect($branches)->toHaveCount(6)
        ->and($actualCodes)->toBe($expectedCodes)
        ->and($branches->every(fn (Branch $branch): bool => $branch->is_active
            && $branch->supportsRetail()
            && $branch->phone !== ''
            && $branch->address !== ''
            && $branch->province_code !== ''
            && $branch->ghn_district_id > 0
            && $branch->ghn_ward_code !== ''
            && ! str_contains($branch->name, 'Clinic')))->toBeTrue()
        ->and($branches->every(fn (Branch $branch): bool => $branch->businessHours()->count() === 7))->toBeTrue()
        ->and(Schema::hasColumn('branches', 'slug'))->toBeFalse()
        ->and(Schema::hasColumn('branches', 'latitude'))->toBeFalse()
        ->and(Schema::hasColumn('branches', 'supports_pickup'))->toBeFalse();

    $this->getJson('/api/v1/clinics')
        ->assertOk()
        ->assertJsonCount(6, 'data');
});

test('adopts legacy branches and preserves their ids and relationships', function (): void {
    $legacyRetail = Branch::query()->create([
        'code' => 'DEV-CT',
        'name' => 'Old Development Branch',
        'branch_type' => 'store',
        'phone' => '02920000001',
        'address' => 'Old address',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $legacyService = Branch::query()->create([
        'code' => 'DEV-CLINIC-CT',
        'name' => 'Old Development Service Branch',
        'branch_type' => 'hybrid',
        'phone' => '02920000002',
        'address' => 'Old address',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);

    $this->artisan('mizuki:initialize-branches-inventory')->assertSuccessful();

    expect(Branch::query()->where('code', 'MZ-NK-01')->value('id'))->toBe($legacyRetail->id)
        ->and(Branch::query()->where('code', 'MZ-CR-01')->value('id'))->toBe($legacyService->id)
        ->and(Branch::query()->count())->toBe(6)
        ->and(Branch::query()->where('name', 'like', '%Clinic%')->exists())->toBeFalse();
});

test('rerun preserves operational stock and only backfills missing pairs', function (): void {
    $variants = initializerVariants(3);

    $this->artisan('mizuki:initialize-branches-inventory')->assertSuccessful();

    $branch = Branch::query()->where('code', 'MZ-NK-01')->sole();
    $preserved = BranchInventory::query()
        ->where('branch_id', $branch->id)
        ->where('product_variant_id', $variants[0]->id)
        ->sole();
    $preserved->update(['quantity' => 97, 'reserved_quantity' => 4]);
    $missing = BranchInventory::query()
        ->where('branch_id', $branch->id)
        ->where('product_variant_id', $variants[1]->id)
        ->sole();
    $missing->delete();
    $expectedInitialQuantity = app(BranchInventoryInitializationRepository::class)
        ->deterministicInitialQuantity($branch->code, $variants[1]->id, $variants[1]->sku);
    $branchIds = Branch::query()->orderBy('id')->pluck('id')->all();

    $this->artisan('mizuki:initialize-branches-inventory')->assertSuccessful();

    $preserved->refresh();
    $recreated = BranchInventory::query()
        ->where('branch_id', $branch->id)
        ->where('product_variant_id', $variants[1]->id)
        ->sole();

    expect($preserved->quantity)->toBe(97)
        ->and($preserved->reserved_quantity)->toBe(4)
        ->and($recreated->quantity)->toBe($expectedInitialQuantity)
        ->and($recreated->reserved_quantity)->toBe(0)
        ->and(Branch::query()->orderBy('id')->pluck('id')->all())->toBe($branchIds)
        ->and(BranchInventory::query()->count())->toBe(18)
        ->and(BranchInventory::query()->where('quantity', '<', 0)->exists())->toBeFalse()
        ->and(BranchInventory::query()->where('reserved_quantity', '<', 0)->exists())->toBeFalse();

    $duplicates = BranchInventory::query()
        ->select(['branch_id', 'product_variant_id'])
        ->groupBy(['branch_id', 'product_variant_id'])
        ->havingRaw('COUNT(*) > 1')
        ->get();

    expect($duplicates)->toBeEmpty();
});

test('deterministic initial quantity covers all ranges and never becomes negative', function (): void {
    $repository = app(BranchInventoryInitializationRepository::class);
    $quantities = [];

    foreach (range(1, 300) as $variantId) {
        $quantity = $repository->deterministicInitialQuantity(
            'MZ-NK-01',
            $variantId,
            "INIT-SKU-{$variantId}",
        );
        $quantities[] = $quantity;

        expect($repository->deterministicInitialQuantity(
            'MZ-NK-01',
            $variantId,
            "INIT-SKU-{$variantId}",
        ))->toBe($quantity);
    }

    expect(min($quantities))->toBe(0)
        ->and(collect($quantities)->contains(fn (int $quantity): bool => $quantity >= 1 && $quantity <= 5))->toBeTrue()
        ->and(collect($quantities)->contains(fn (int $quantity): bool => $quantity >= 6 && $quantity <= 50))->toBeTrue()
        ->and(max($quantities))->toBeLessThanOrEqual(50);
});

test('branch option limits initialization to one stable code', function (): void {
    initializerVariants(2);

    $this->artisan('mizuki:initialize-branches-inventory', ['--branch' => 'MZ-VL-01'])
        ->assertSuccessful();

    expect(Branch::query()->sole()->code)->toBe('MZ-VL-01')
        ->and(BranchInventory::query()->count())->toBe(2);

    $this->artisan('mizuki:initialize-branches-inventory', ['--branch' => 'UNKNOWN'])
        ->assertFailed();
});
