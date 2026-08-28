<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPortalReadBranch(string $prefix = 'APR'): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix . $token,
        'name' => 'Mizuki Portal ' . $token,
        'phone' => '02923999999',
        'email' => strtolower($token) . '@mizuki.test',
        'address' => 'C?n Tho',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

/**
 * @return array{
 *     category: Category,
 *     brand: Brand,
 *     product: Product,
 *     variant: ProductVariant
 * }
 */
function createPortalReadCatalog(): array
{
    $token = strtolower(Str::random(8));

    $category = Category::query()->create([
        'name' => 'Portal Category ' . $token,
        'slug' => 'portal-category-' . $token,
        'sort_order' => 1,
        'is_active' => true,
    ]);

    $brand = Brand::query()->create([
        'name' => 'Portal Brand ' . $token,
        'slug' => 'portal-brand-' . $token,
        'follower_count' => 0,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Portal Product ' . $token,
        'slug' => 'portal-product-' . $token,
        'is_active' => true,
        'is_featured' => false,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'PORTAL-' . Str::upper($token),
        'price' => 100_000,
        'sale_price' => 90_000,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ]);

    return compact('category', 'brand', 'product', 'variant');
}

test('super admin can read all new admin portal collections', function (): void {
    $branch = createPortalReadBranch();
    $catalog = createPortalReadCatalog();

    BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 10,
        'reserved_quantity' => 2,
        'reorder_level' => 3,
    ]);

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Portal Customer',
    ]);

    User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'name' => 'Portal Technician',
    ]);

    Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => $catalog['product']->id,
        'rating' => 5,
        'title' => 'Portal Review',
        'comment' => 'Focused admin portal read test.',
        'is_visible' => true,
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin);

    $this->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonStructure([
            'data' => [
                'summary' => [
                    'revenue',
                    'orders',
                    'pending_orders',
                    'appointments',
                    'pending_refunds',
                    'customers',
                ],
                'revenue_series',
                'payment_methods',
                'top_products',
            ],
        ]);

    $this->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'id' => $customer->id,
            'name' => 'Portal Customer',
        ]);

    $this->getJson('/api/v1/admin/products')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'id' => $catalog['product']->id,
            'name' => $catalog['product']->name,
        ]);

    $this->getJson('/api/v1/admin/categories')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'id' => $catalog['category']->id,
            'name' => $catalog['category']->name,
        ]);

    $this->getJson('/api/v1/admin/brands')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'id' => $catalog['brand']->id,
            'name' => $catalog['brand']->name,
        ]);

    $this->getJson('/api/v1/admin/inventory')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'quantity' => 10,
            'reserved_quantity' => 2,
            'available_quantity' => 8,
        ]);

    $this->getJson('/api/v1/admin/branches')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'id' => $branch->id,
            'code' => $branch->code,
        ]);

    $this->getJson('/api/v1/admin/staff')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'name' => 'Portal Technician',
            'role' => UserRole::Technician->value,
        ]);

    $this->getJson('/api/v1/admin/reviews')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonFragment([
            'title' => 'Portal Review',
            'rating' => 5,
            'is_visible' => true,
        ]);
});

test('super admin can read new admin portal detail endpoints', function (): void {
    $branch = createPortalReadBranch('APD');
    $catalog = createPortalReadCatalog();

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Portal Detail Customer',
    ]);

    $staff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $branch->id,
        'name' => 'Portal Detail Technician',
    ]);

    $review = Review::query()->create([
        'user_id' => $customer->id,
        'product_id' => $catalog['product']->id,
        'rating' => 4,
        'title' => 'Portal Detail Review',
        'comment' => 'Detail endpoint test.',
        'is_visible' => true,
    ]);

    $admin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($admin);

    $this->getJson("/api/v1/admin/customers/{$customer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $customer->id);

    $this->getJson("/api/v1/admin/products/{$catalog['product']->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $catalog['product']->id)
        ->assertJsonPath('data.variant_count', 1)
        ->assertJsonPath('data.variants.0.id', $catalog['variant']->id);

    $this->getJson("/api/v1/admin/categories/{$catalog['category']->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $catalog['category']->id);

    $this->getJson("/api/v1/admin/brands/{$catalog['brand']->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $catalog['brand']->id);

    $this->getJson("/api/v1/admin/branches/{$branch->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $branch->id);

    $this->getJson("/api/v1/admin/staff/{$staff->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $staff->id);

    $this->getJson("/api/v1/admin/reviews/{$review->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $review->id);
});
