<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPortalMutationBranch(string $prefix = 'APM'): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix . $token,
        'name' => 'Mizuki Mutation ' . $token,
        'phone' => '02923730101',
        'email' => strtolower($token) . '@mizuki.test',
        'address' => 'C?n Tho',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

test('super admin can create and update category brand and product with variant', function (): void {
    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin);

    $categoryResponse = $this->postJson('/api/v1/admin/categories', [
        'name' => 'Portal Mutation Category',
        'slug' => 'portal-mutation-category',
        'sort_order' => 10,
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Portal Mutation Category');

    $categoryId = $categoryResponse->json('data.id');

    $this->patchJson("/api/v1/admin/categories/{$categoryId}", [
        'name' => 'Portal Mutation Category Updated',
        'sort_order' => 11,
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Portal Mutation Category Updated')
        ->assertJsonPath('data.sort_order', 11)
        ->assertJsonPath('data.is_active', false);

    $brandResponse = $this->postJson('/api/v1/admin/brands', [
        'name' => 'Portal Mutation Brand',
        'slug' => 'portal-mutation-brand',
        'description' => 'Mutation brand',
        'is_active' => true,
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Portal Mutation Brand');

    $brandId = $brandResponse->json('data.id');

    $this->patchJson("/api/v1/admin/brands/{$brandId}", [
        'name' => 'Portal Mutation Brand Updated',
        'is_active' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Portal Mutation Brand Updated')
        ->assertJsonPath('data.is_active', false);

    $productResponse = $this->postJson('/api/v1/admin/products', [
        'category_id' => $categoryId,
        'brand_id' => $brandId,
        'name' => 'Portal Mutation Product',
        'slug' => 'portal-mutation-product',
        'short_description' => 'Mutation product',
        'is_active' => true,
        'is_featured' => false,
        'variants' => [
            [
                'name' => 'Default',
                'sku' => 'PORTAL-MUTATION-SKU',
                'barcode' => 'PORTALMUTATION',
                'price' => 100_000,
                'sale_price' => 90_000,
                'weight' => 100,
                'sort_order' => 0,
                'is_active' => true,
            ],
        ],
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.name', 'Portal Mutation Product')
        ->assertJsonPath('data.variant_count', 1);

    $productId = $productResponse->json('data.id');
    $variantId = $productResponse->json('data.variants.0.id');

    $this->patchJson("/api/v1/admin/products/{$productId}", [
        'name' => 'Portal Mutation Product Updated',
        'is_active' => false,
        'is_featured' => true,
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Portal Mutation Product Updated')
        ->assertJsonPath('data.is_active', false)
        ->assertJsonPath('data.is_featured', true)
        ->assertJsonPath('data.variant_count', 1)
        ->assertJsonPath('data.variants.0.id', $variantId);

    $this->patchJson("/api/v1/admin/products/{$productId}", [
        'variants' => [
            [
                'id' => $variantId,
                'name' => 'Default',
                'sku' => 'PORTAL-MUTATION-SKU',
                'barcode' => 'PORTALMUTATION',
                'price' => 120_000,
                'sale_price' => 110_000,
                'weight' => 100,
                'sort_order' => 0,
                'is_active' => true,
            ],
        ],
    ])
        ->assertOk()
        ->assertJsonPath('data.variants.0.id', $variantId)
        ->assertJsonPath('data.variants.0.price', 120_000)
        ->assertJsonPath('data.variants.0.sale_price', 110_000)
        ->assertJsonPath('data.variants.0.effective_price', 110_000);
});

test('super admin can adjust inventory and immutable ledger records the operator', function (): void {
    $branch = createPortalMutationBranch('API');
    $token = strtolower(Str::random(8));

    $category = Category::query()->create([
        'name' => 'Inventory Category ' . $token,
        'slug' => 'inventory-category-' . $token,
        'is_active' => true,
    ]);

    $brand = Brand::query()->create([
        'name' => 'Inventory Brand ' . $token,
        'slug' => 'inventory-brand-' . $token,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Inventory Product ' . $token,
        'slug' => 'inventory-product-' . $token,
        'is_active' => true,
        'is_featured' => false,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'INV-' . Str::upper($token),
        'price' => 100_000,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ]);

    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 18,
        'reserved_quantity' => 1,
        'reorder_level' => 5,
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjust", [
        'quantity_delta' => 1,
        'reason' => 'Focused inventory adjustment test',
    ])
        ->assertOk()
        ->assertJsonPath('data.quantity', 19)
        ->assertJsonPath('data.reserved_quantity', 1)
        ->assertJsonPath('data.available_quantity', 18);

    $transaction = InventoryTransaction::query()
        ->where('branch_inventory_id', $inventory->id)
        ->latest('id')
        ->firstOrFail();

    expect($transaction->quantity_delta)->toBe(1)
        ->and($transaction->quantity_after)->toBe(19)
        ->and($transaction->reserved_quantity_delta)->toBe(0)
        ->and($transaction->reserved_quantity_after)->toBe(1)
        ->and($transaction->note)->toBe('Focused inventory adjustment test')
        ->and($transaction->performed_by_user_id)->toBe($admin->id);

    $this->getJson("/api/v1/admin/inventory/{$inventory->id}/transactions")
        ->assertOk()
        ->assertJsonPath('data.0.id', $transaction->id)
        ->assertJsonPath('data.0.quantity_delta', 1)
        ->assertJsonPath('data.0.operator.id', $admin->id);

    $this->postJson("/api/v1/admin/inventory/{$inventory->id}/adjust", [
        'quantity_delta' => -100,
        'reason' => 'Must reject negative stock',
    ])->assertUnprocessable();

    expect($inventory->refresh()->quantity)->toBe(19);
});

test('super admin can update branch staff and review moderation', function (): void {
    $branch = createPortalMutationBranch('APS');

    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'name' => 'Portal Staff Original',
    ]);

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    $review = Review::query()->create([
        'user_id' => $customer->id,
        'rating' => 4,
        'title' => 'Portal Moderation',
        'comment' => 'Moderation mutation test.',
        'is_visible' => true,
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin);

    $this->patchJson("/api/v1/admin/branches/{$branch->id}", [
        'phone' => '02923730109',
    ])
        ->assertOk()
        ->assertJsonPath('data.phone', '02923730109');

    $this->patchJson("/api/v1/admin/staff/{$staff->id}", [
        'name' => 'Portal Staff Updated',
    ])
        ->assertOk()
        ->assertJsonPath('data.name', 'Portal Staff Updated')
        ->assertJsonPath('data.role', UserRole::Technician->value);

    $this->patchJson("/api/v1/admin/reviews/{$review->id}", [
        'is_visible' => false,
    ])
        ->assertOk()
        ->assertJsonPath('data.is_visible', false)
        ->assertJsonPath('data.moderated_by.id', $admin->id);

    $review->refresh();

    expect($review->is_visible)->toBeFalse()
        ->and($review->moderated_by_user_id)->toBe($admin->id)
        ->and($review->moderated_at)->not->toBeNull();
});
