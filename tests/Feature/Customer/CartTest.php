<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{product: Product, variant: ProductVariant, branch: Branch, inventory: BranchInventory}
 */
function createCartProduct(int $quantity = 10, int $reservedQuantity = 2): array
{
    static $sequence = 0;
    $sequence++;
    $token = Str::lower(Str::random(8));

    $category = Category::query()->create([
        'name' => 'Danh mục giỏ hàng '.$token,
        'slug' => 'cart-category-'.$token,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Thương hiệu giỏ hàng '.$token,
        'slug' => 'cart-brand-'.$token,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Sản phẩm giỏ hàng '.$sequence,
        'slug' => 'cart-product-'.$token,
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'CART-'.Str::upper(Str::random(10)),
        'attributes' => ['capacity' => '50 ml'],
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => "products/{$product->slug}.jpg",
        'alt_text' => $product->name,
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    $branch = Branch::query()->create([
        'code' => 'CART'.$sequence,
        'name' => 'Mizuki Cart '.$sequence,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => $quantity,
        'reserved_quantity' => $reservedQuantity,
        'reorder_level' => 2,
    ]);

    return compact('product', 'variant', 'branch', 'inventory');
}

test('viewing a new cart returns an empty cart and creates it automatically', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($user);

    $this->getJson('/api/v1/customer/cart')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.branch', null)
        ->assertJsonCount(0, 'data.items')
        ->assertJsonPath('data.total_quantity', 0)
        ->assertJsonPath('data.total_amount', 0);

    $this->assertDatabaseHas('carts', ['user_id' => $user->id]);
});

test('a customer can add a new product variant to an empty cart', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/cart/items', [
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 2,
    ])
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.variant.id', $catalog['variant']->id)
        ->assertJsonPath('data.items.0.quantity', 2)
        ->assertJsonPath('data.items.0.variant.effective_price', 150_000)
        ->assertJsonPath('data.items.0.subtotal', 300_000)
        ->assertJsonPath('data.total_quantity', 2)
        ->assertJsonPath('data.total_amount', 300_000)
        ->assertJsonPath('message', 'Đã thêm vào giỏ hàng!');
});

test('adding the same variant again increases quantity without a duplicate row', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $this->actingAs($user);

    $payload = ['product_variant_id' => $catalog['variant']->id, 'quantity' => 2];
    $this->postJson('/api/v1/customer/cart/items', $payload)->assertOk();
    $this->postJson('/api/v1/customer/cart/items', $payload)
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 4);

    expect(CartItem::query()->where('product_variant_id', $catalog['variant']->id)->count())->toBe(1);
});

test('a customer can update cart item quantity', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $item = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 1,
    ]);
    $this->actingAs($user);

    $this->patchJson("/api/v1/customer/cart/items/{$item->id}", ['quantity' => 3])
        ->assertOk()
        ->assertJsonPath('data.items.0.quantity', 3)
        ->assertJsonPath('message', 'Đã cập nhật giỏ hàng!');

    $this->assertDatabaseHas('cart_items', ['id' => $item->id, 'quantity' => 3]);
});

test('a customer can remove an item from their cart', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $cart = Cart::query()->create(['user_id' => $user->id]);
    $item = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 1,
    ]);
    $this->actingAs($user);

    $this->deleteJson("/api/v1/customer/cart/items/{$item->id}")
        ->assertOk()
        ->assertJsonCount(0, 'data.items')
        ->assertJsonPath('message', 'Đã xóa sản phẩm khỏi giỏ hàng!');

    $this->assertDatabaseMissing('cart_items', ['id' => $item->id]);
});

test('selecting a branch keeps items and exposes stock warnings', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct(quantity: 4, reservedQuantity: 2);
    $cart = Cart::query()->create(['user_id' => $user->id]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 3,
    ]);
    $this->actingAs($user);

    $this->patchJson('/api/v1/customer/cart/branch', ['branch_id' => $catalog['branch']->id])
        ->assertOk()
        ->assertJsonPath('data.branch.id', $catalog['branch']->id)
        ->assertJsonCount(1, 'data.items')
        ->assertJsonPath('data.items.0.available_quantity', 2)
        ->assertJsonPath('data.items.0.stock_warning', true)
        ->assertJsonPath('message', 'Đã chọn chi nhánh cho giỏ hàng!');
});

test('quantity exceeding selected branch stock is rejected', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct(quantity: 5, reservedQuantity: 2);
    Cart::query()->create([
        'user_id' => $user->id,
        'branch_id' => $catalog['branch']->id,
    ]);
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/cart/items', [
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 4,
    ])
        ->assertUnprocessable()
        ->assertJsonPath('success', false)
        ->assertJsonPath('data.errors.quantity.0', 'Chi nhánh đã chọn chỉ còn 3 sản phẩm');

    $this->assertDatabaseCount('cart_items', 0);
});

test('customers cannot update or remove another customer cart items', function (): void {
    $customerA = User::factory()->create(['role' => UserRole::Customer]);
    $customerB = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $cartB = Cart::query()->create(['user_id' => $customerB->id]);
    $itemB = CartItem::query()->create([
        'cart_id' => $cartB->id,
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 1,
    ]);
    $this->actingAs($customerA);

    $this->patchJson("/api/v1/customer/cart/items/{$itemB->id}", ['quantity' => 2])
        ->assertNotFound();
    $this->deleteJson("/api/v1/customer/cart/items/{$itemB->id}")
        ->assertNotFound();

    $this->assertDatabaseHas('cart_items', [
        'id' => $itemB->id,
        'cart_id' => $cartB->id,
        'quantity' => 1,
    ]);
});

test('guest is rejected from all cart endpoints', function (): void {
    $catalog = createCartProduct();

    $this->getJson('/api/v1/customer/cart')->assertUnauthorized();
    $this->postJson('/api/v1/customer/cart/items', [
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 1,
    ])->assertUnauthorized();
    $this->patchJson('/api/v1/customer/cart/items/1', ['quantity' => 1])->assertUnauthorized();
    $this->deleteJson('/api/v1/customer/cart/items/1')->assertUnauthorized();
    $this->patchJson('/api/v1/customer/cart/branch', ['branch_id' => $catalog['branch']->id])->assertUnauthorized();
});

test('cart always reflects the current variant price', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $catalog = createCartProduct();
    $this->actingAs($user);

    $this->postJson('/api/v1/customer/cart/items', [
        'product_variant_id' => $catalog['variant']->id,
        'quantity' => 2,
    ])->assertOk();

    $catalog['variant']->forceFill([
        'price' => 300_000,
        'sale_price' => 250_000,
    ])->save();

    $this->getJson('/api/v1/customer/cart')
        ->assertOk()
        ->assertJsonPath('data.items.0.variant.price', 300_000)
        ->assertJsonPath('data.items.0.variant.sale_price', 250_000)
        ->assertJsonPath('data.items.0.variant.effective_price', 250_000)
        ->assertJsonPath('data.items.0.subtotal', 500_000)
        ->assertJsonPath('data.total_amount', 500_000);
});
