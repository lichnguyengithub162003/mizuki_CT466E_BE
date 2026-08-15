<?php

use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductFavorite;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createFavoriteProduct(string $name = 'Serum phục hồi'): Product
{
    static $sequence = 0;
    $sequence++;
    $token = Str::lower(Str::random(8));

    $category = Category::query()->create([
        'name' => 'Danh mục yêu thích '.$token,
        'slug' => 'favorite-category-'.$token,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Thương hiệu yêu thích '.$token,
        'slug' => 'favorite-brand-'.$token,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => $name,
        'slug' => Str::slug($name).'-favorite-'.$sequence,
        'is_active' => true,
        'is_featured' => false,
    ]);

    ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'FAVORITE-'.Str::upper(Str::random(10)),
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => "products/{$product->slug}.jpg",
        'alt_text' => $name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);

    return $product;
}

function createFavoriteBranch(): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => 'FAV-'.$token,
        'name' => 'Favorite branch '.$token,
        'phone' => '02920000000',
        'address' => 'Cần Thơ',
        'province_code' => '92',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '22001',
        'is_active' => true,
    ]);
}

function createFavoriteInventory(
    Product $product,
    int $quantity,
    int $reservedQuantity = 0,
    int $reorderLevel = 0,
    ?Branch $branch = null,
): BranchInventory {
    $variant = $product->variants()->firstOrFail();
    $branch ??= createFavoriteBranch();

    return BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'reserved_quantity' => $reservedQuantity,
        'reorder_level' => $reorderLevel,
    ]);
}

test('an authenticated customer can add list and remove a favorite', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct();
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/favorites', ['product_id' => $product->id])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.minimum_price', 150_000)
        ->assertJsonPath('data.original_price', 200_000)
        ->assertJsonPath('data.stock_state', 'sold-out')
        ->assertJsonPath('message', 'Đã thêm vào yêu thích!');

    $this->assertDatabaseHas('product_favorites', [
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    $this->getJson('/api/v1/customer/favorites')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $product->id)
        ->assertJsonPath('data.0.primary_image_url', "products/{$product->slug}.jpg")
        ->assertJsonPath('data.0.brand.id', $product->brand_id)
        ->assertJsonPath('data.0.brand.name', $product->brand->name)
        ->assertJsonPath('data.0.brand.slug', $product->brand->slug)
        ->assertJsonPath('data.0.original_price', 200_000)
        ->assertJsonPath('data.0.stock_state', 'sold-out')
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonStructure(['success', 'data', 'message', 'meta' => ['pagination']]);

    $this->deleteJson("/api/v1/customer/favorites/{$product->id}")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('message', 'Đã bỏ yêu thích!');

    $this->assertDatabaseMissing('product_favorites', [
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);
});

test('favorite listing exposes catalog price and stock states without removing existing fields', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $available = createFavoriteProduct('Sản phẩm còn hàng');
    $lowStock = createFavoriteProduct('Sản phẩm sắp hết');
    $soldOut = createFavoriteProduct('Sản phẩm hết hàng');
    $discontinued = createFavoriteProduct('Sản phẩm ngừng bán');
    $branch = createFavoriteBranch();

    createFavoriteInventory($available, quantity: 15, reservedQuantity: 2, reorderLevel: 20, branch: $branch);
    createFavoriteInventory($lowStock, quantity: 7, reservedQuantity: 4, reorderLevel: 0, branch: $branch);
    createFavoriteInventory($soldOut, quantity: 5, reservedQuantity: 5, reorderLevel: 0, branch: $branch);
    createFavoriteInventory($discontinued, quantity: 20, reorderLevel: 0, branch: $branch);
    $lowStock->variants()->update(['sale_price' => null]);
    $discontinued->update(['is_active' => false]);

    foreach ([$available, $lowStock, $soldOut, $discontinued] as $product) {
        ProductFavorite::query()->create([
            'user_id' => $user->id,
            'product_id' => $product->id,
        ]);
    }

    $response = $this->actingAs($user)
        ->getJson("/api/v1/customer/favorites?branch_id={$branch->id}")
        ->assertOk()
        ->assertJsonCount(4, 'data')
        ->assertJsonStructure(['data' => [[
            'id',
            'name',
            'slug',
            'primary_image_url',
            'minimum_price',
            'brand' => ['id', 'name', 'slug'],
            'original_price',
            'stock_state',
        ]]]);

    $favorites = collect($response->json('data'))->keyBy('id');

    expect($favorites[$available->id]['brand'])->toBe([
        'id' => $available->brand->id,
        'name' => $available->brand->name,
        'slug' => $available->brand->slug,
    ])->and($favorites[$available->id]['original_price'])->toBe(200_000)
        ->and($favorites[$available->id]['stock_state'])->toBe('available')
        ->and($favorites[$lowStock->id]['minimum_price'])->toBe(200_000)
        ->and($favorites[$lowStock->id]['original_price'])->toBeNull()
        ->and($favorites[$lowStock->id]['stock_state'])->toBe('low-stock')
        ->and($favorites[$soldOut->id]['stock_state'])->toBe('sold-out')
        ->and($favorites[$discontinued->id]['stock_state'])->toBe('discontinued');
});

test('favorites and product listing resolve the same product state per selected branch', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct('Sản phẩm tồn kho theo chi nhánh');
    $availableBranch = createFavoriteBranch();
    $lowStockBranch = createFavoriteBranch();

    createFavoriteInventory($product, quantity: 12, reorderLevel: 20, branch: $availableBranch);
    createFavoriteInventory($product, quantity: 3, reorderLevel: 0, branch: $lowStockBranch);
    ProductFavorite::query()->create([
        'user_id' => $user->id,
        'product_id' => $product->id,
    ]);

    foreach ([
        $availableBranch->id => 'available',
        $lowStockBranch->id => 'low-stock',
    ] as $branchId => $expectedState) {
        $favoriteState = $this->actingAs($user)
            ->getJson("/api/v1/customer/favorites?branch_id={$branchId}")
            ->assertOk()
            ->assertJsonPath('data.0.stock_state', $expectedState)
            ->json('data.0.stock_state');
        $listingState = $this->getJson("/api/v1/products?branch_id={$branchId}")
            ->assertOk()
            ->assertJsonPath('data.0.id', $product->id)
            ->assertJsonPath('data.0.availability.stock_state', $expectedState)
            ->json('data.0.availability.stock_state');

        expect($favoriteState)->toBe($listingState);
    }
});

test('guest is rejected from all favorite endpoints', function (): void {
    $product = createFavoriteProduct();

    $this->getJson('/api/v1/customer/favorites')->assertUnauthorized();
    $this->postJson('/api/v1/customer/favorites', ['product_id' => $product->id])->assertUnauthorized();
    $this->deleteJson("/api/v1/customer/favorites/{$product->id}")->assertUnauthorized();
});

test('adding the same product twice returns conflict without creating a duplicate', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct('Kem dưỡng ẩm');
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/favorites', ['product_id' => $product->id])
        ->assertCreated();

    $this->postJson('/api/v1/customer/favorites', ['product_id' => $product->id])
        ->assertStatus(409)
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Sản phẩm đã có trong danh sách yêu thích');

    expect(ProductFavorite::query()
        ->where('user_id', $user->id)
        ->where('product_id', $product->id)
        ->count())->toBe(1);
});

test('removing a product that is not favorited returns 404', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct('Kem chống nắng');
    $this->actingAs($user);

    $this->deleteJson("/api/v1/customer/favorites/{$product->id}")
        ->assertNotFound()
        ->assertJsonPath('success', false)
        ->assertJsonPath('message', 'Không tìm thấy sản phẩm trong danh sách yêu thích');
});

test('customers cannot view or remove another customer favorites', function (): void {
    $customerA = User::factory()->create(['role' => UserRole::Customer]);
    $customerB = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct('Mặt nạ dưỡng da');

    ProductFavorite::query()->create([
        'user_id' => $customerB->id,
        'product_id' => $product->id,
    ]);

    $this->actingAs($customerA);

    $this->getJson('/api/v1/customer/favorites')
        ->assertOk()
        ->assertJsonCount(0, 'data');

    $this->deleteJson("/api/v1/customer/favorites/{$product->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('product_favorites', [
        'user_id' => $customerB->id,
        'product_id' => $product->id,
    ]);
});
