<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createPortalAuthorizationBranch(string $prefix = 'APA'): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix . $token,
        'name' => 'Mizuki Authorization ' . $token,
        'phone' => '02923666666',
        'email' => strtolower($token) . '@mizuki.test',
        'address' => 'C?n Tho',
        'province_code' => '710',
        'ghn_district_id' => 1572,
        'ghn_ward_code' => '550113',
        'is_active' => true,
    ]);
}

function createPortalAuthorizationOrder(Branch $branch, User $customer): Order
{
    return Order::query()->create([
        'order_number' => 'MZ-AUTH-' . Str::upper(Str::random(10)),
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Pending,
        'subtotal' => 100_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 100_000,
        'placed_at' => now(),
    ]);
}

/**
 * @return array{
 *     product: Product,
 *     variant: ProductVariant
 * }
 */
function createPortalAuthorizationCatalog(): array
{
    $token = strtolower(Str::random(8));

    $category = Category::query()->create([
        'name' => 'Auth Category ' . $token,
        'slug' => 'auth-category-' . $token,
        'is_active' => true,
    ]);

    $brand = Brand::query()->create([
        'name' => 'Auth Brand ' . $token,
        'slug' => 'auth-brand-' . $token,
        'is_active' => true,
    ]);

    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Auth Product ' . $token,
        'slug' => 'auth-product-' . $token,
        'is_active' => true,
        'is_featured' => false,
    ]);

    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'AUTH-' . Str::upper($token),
        'price' => 100_000,
        'weight' => 100,
        'sort_order' => 0,
        'is_active' => true,
    ]);

    return compact('product', 'variant');
}

test('guest and non admin roles cannot access new admin portal endpoints', function (): void {
    $paths = [
        '/api/v1/admin/dashboard',
        '/api/v1/admin/customers',
        '/api/v1/admin/products',
        '/api/v1/admin/categories',
        '/api/v1/admin/brands',
        '/api/v1/admin/inventory',
        '/api/v1/admin/branches',
        '/api/v1/admin/staff',
        '/api/v1/admin/reviews',
    ];

    foreach ($paths as $path) {
        $this->getJson($path)->assertUnauthorized();
    }

    foreach ([UserRole::Customer, UserRole::Cashier, UserRole::Technician] as $role) {
        $user = User::factory()->create(['role' => $role]);
        $this->actingAs($user);

        foreach ($paths as $path) {
            $this->getJson($path)->assertForbidden();
        }
    }
});

test('branch manager sees only own operational branch data', function (): void {
    $ownBranch = createPortalAuthorizationBranch('APO');
    $otherBranch = createPortalAuthorizationBranch('APX');

    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);

    $ownCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Own Branch Customer',
    ]);

    $otherCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Other Branch Customer',
    ]);

    createPortalAuthorizationOrder($ownBranch, $ownCustomer);
    createPortalAuthorizationOrder($otherBranch, $otherCustomer);

    $ownStaff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $ownBranch->id,
        'name' => 'Own Branch Technician',
    ]);

    $otherStaff = User::factory()->create([
        'role' => UserRole::Technician,
        'branch_id' => $otherBranch->id,
        'name' => 'Other Branch Technician',
    ]);

    $catalog = createPortalAuthorizationCatalog();

    $ownInventory = BranchInventory::query()->create([
        'branch_id' => $ownBranch->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 10,
        'reserved_quantity' => 1,
        'reorder_level' => 2,
    ]);

    $otherInventory = BranchInventory::query()->create([
        'branch_id' => $otherBranch->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 20,
        'reserved_quantity' => 2,
        'reorder_level' => 3,
    ]);

    $this->actingAs($manager);

    $this->getJson('/api/v1/admin/dashboard')
        ->assertOk()
        ->assertJsonPath('data.summary.orders', 1)
        ->assertJsonPath('data.summary.pending_orders', 1)
        ->assertJsonPath('data.summary.customers', 1);

    $this->getJson('/api/v1/admin/customers')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $ownCustomer->id,
            'name' => 'Own Branch Customer',
        ])
        ->assertJsonMissing([
            'id' => $otherCustomer->id,
            'name' => 'Other Branch Customer',
        ]);

    $this->getJson("/api/v1/admin/customers/{$ownCustomer->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $ownCustomer->id);

    $this->getJson("/api/v1/admin/customers/{$otherCustomer->id}")
        ->assertNotFound();

    $this->getJson('/api/v1/admin/inventory')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $ownInventory->id,
            'quantity' => 10,
        ])
        ->assertJsonMissing([
            'id' => $otherInventory->id,
            'quantity' => 20,
        ]);

    $this->getJson("/api/v1/admin/inventory/{$otherInventory->id}/transactions")
        ->assertNotFound();

    $this->postJson("/api/v1/admin/inventory/{$otherInventory->id}/adjust", [
        'quantity_delta' => 1,
        'reason' => 'Cross branch adjustment must fail',
    ])->assertNotFound();

    $this->getJson('/api/v1/admin/branches')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $ownBranch->id,
            'code' => $ownBranch->code,
        ])
        ->assertJsonMissing([
            'id' => $otherBranch->id,
            'code' => $otherBranch->code,
        ]);

    $this->getJson("/api/v1/admin/branches/{$otherBranch->id}")
        ->assertNotFound();

    $this->patchJson("/api/v1/admin/branches/{$otherBranch->id}", [
        'phone' => '02923111111',
    ])->assertNotFound();

    $this->getJson('/api/v1/admin/staff')
        ->assertOk()
        ->assertJsonFragment([
            'id' => $ownStaff->id,
            'name' => 'Own Branch Technician',
        ])
        ->assertJsonMissing([
            'id' => $otherStaff->id,
            'name' => 'Other Branch Technician',
        ]);

    $this->getJson("/api/v1/admin/staff/{$otherStaff->id}")
        ->assertNotFound();

    $this->patchJson("/api/v1/admin/staff/{$otherStaff->id}", [
        'name' => 'Cross Branch Mutation',
    ])->assertNotFound();

    expect($otherInventory->refresh()->quantity)->toBe(20)
        ->and($otherBranch->refresh()->phone)->toBe('02923666666')
        ->and($otherStaff->refresh()->name)->toBe('Other Branch Technician');
});

test('branch manager cannot access reviews outside branch review scope while super admin can', function (): void {
    $branch = createPortalAuthorizationBranch('APR');

    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);

    $customer = User::factory()->create([
        'role' => UserRole::Customer,
    ]);

    $review = Review::query()->create([
        'user_id' => $customer->id,
        'rating' => 5,
        'title' => 'Unscoped Review',
        'comment' => 'This review has no order item or appointment branch association.',
        'is_visible' => true,
    ]);

    $this->actingAs($manager);

    $this->getJson('/api/v1/admin/reviews')
        ->assertOk()
        ->assertJsonMissing([
            'id' => $review->id,
            'title' => 'Unscoped Review',
        ]);

    $this->getJson("/api/v1/admin/reviews/{$review->id}")
        ->assertNotFound();

    $this->patchJson("/api/v1/admin/reviews/{$review->id}", [
        'is_visible' => false,
    ])->assertNotFound();

    expect($review->refresh()->is_visible)->toBeTrue();

    $superAdmin = User::factory()->create([
        'role' => UserRole::SuperAdmin,
    ]);

    $this->actingAs($superAdmin);

    $this->getJson("/api/v1/admin/reviews/{$review->id}")
        ->assertOk()
        ->assertJsonPath('data.id', $review->id);
});
