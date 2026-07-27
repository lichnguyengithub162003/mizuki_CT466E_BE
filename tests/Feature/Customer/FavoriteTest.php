<?php

use App\Enums\UserRole;
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

test('an authenticated customer can add list and remove a favorite', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $product = createFavoriteProduct();
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/favorites', ['product_id' => $product->id])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.id', $product->id)
        ->assertJsonPath('data.minimum_price', 150_000)
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
