<?php

use App\Enums\UserRole;
use App\Models\Brand;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, other_user: User, branch: Branch, other_branch: Branch, cart: Cart}
 */
function createCartPromotionContext(bool $selectBranch = true): array
{
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $otherUser = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'VP'.$token,
        'name' => 'Mizuki Voucher '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $otherBranch = Branch::query()->create([
        'code' => 'VO'.$token,
        'name' => 'Mizuki Other '.$token,
        'phone' => '02923888889',
        'address' => 'Cái Răng, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21013',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Voucher '.$token,
        'slug' => 'voucher-category-'.strtolower($token),
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Voucher Brand '.$token,
        'slug' => 'voucher-brand-'.strtolower($token),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Voucher Product '.$token,
        'slug' => 'voucher-product-'.strtolower($token),
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'VOUCHER-'.$token,
        'attributes' => ['capacity' => '50 ml'],
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20,
        'reserved_quantity' => 0,
        'reorder_level' => 2,
    ]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'branch_id' => $selectBranch ? $branch->id : null,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    return compact('user', 'branch', 'cart') + [
        'other_user' => $otherUser,
        'other_branch' => $otherBranch,
    ];
}

/**
 * @param array<string, mixed> $overrides
 */
function createCartPromotion(Branch $branch, array $overrides = []): Promotion
{
    $token = Str::upper(Str::random(8));
    $promotion = Promotion::query()->create(array_merge([
        'code' => 'PROMO'.$token,
        'name' => 'Voucher '.$token,
        'description' => 'Voucher dùng trong kiểm thử.',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'max_discount_amount' => 100_000,
        'minimum_order_amount' => 100_000,
        'usage_limit' => 100,
        'usage_count' => 0,
        'per_user_limit' => 2,
        'applies_to' => 'order',
        'scope' => null,
        'rules' => null,
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ], $overrides));
    $promotion->branches()->attach($branch->id);

    return $promotion;
}

test('a customer can list promotions available for the current cart', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch']);
    $this->actingAs($context['user']);

    $this->getJson('/api/v1/customer/cart/promotions')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.code', $promotion->code)
        ->assertJsonPath('data.0.estimated_discount_amount', 30_000);
});

test('listing promotions without a selected branch returns an empty successful response', function (): void {
    $context = createCartPromotionContext(false);
    createCartPromotion($context['branch']);
    $this->actingAs($context['user']);

    $this->getJson('/api/v1/customer/cart/promotions')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonCount(0, 'data')
        ->assertJsonPath('message', 'Vui lòng chọn chi nhánh để xem voucher khả dụng!');
});

test('a customer can apply a valid promotion and receive the correct discount', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch']);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => strtolower($promotion->code)])
        ->assertOk()
        ->assertJsonPath('data.applied_promotion.code', $promotion->code)
        ->assertJsonPath('data.total_before_discount', 300_000)
        ->assertJsonPath('data.discount_amount', 30_000)
        ->assertJsonPath('data.total_after_discount', 270_000);

    $this->assertDatabaseHas('carts', [
        'id' => $context['cart']->id,
        'promotion_id' => $promotion->id,
    ]);
});

test('an expired promotion is rejected with a specific error', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch'], [
        'starts_at' => now()->subDays(2),
        'ends_at' => now()->subDay(),
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $promotion->code])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Voucher đã hết hạn');
});

test('a promotion for another branch is rejected with a specific error', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['other_branch']);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $promotion->code])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Voucher không áp dụng cho chi nhánh đã chọn');
});

test('a promotion requiring a higher cart value is rejected', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch'], ['minimum_order_amount' => 500_000]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $promotion->code])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Đơn hàng chưa đạt giá trị tối thiểu 500.000 đ');
});

test('an exhausted promotion is rejected based on recorded usages', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch'], ['usage_limit' => 1, 'usage_count' => 0]);
    $order = Order::query()->create([
        'order_number' => 'ORD-'.Str::upper(Str::random(10)),
        'user_id' => $context['other_user']->id,
        'branch_id' => $context['branch']->id,
        'payment_method' => 'cash',
        'subtotal' => 300_000,
        'discount_amount' => 30_000,
        'shipping_fee' => 0,
        'total_amount' => 270_000,
    ]);
    PromotionUsage::query()->create([
        'promotion_id' => $promotion->id,
        'user_id' => $context['other_user']->id,
        'order_id' => $order->id,
        'promotion_code' => $promotion->code,
        'promotion_name' => $promotion->name,
        'discount_amount' => 30_000,
        'used_at' => now(),
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $promotion->code])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Voucher đã hết lượt sử dụng');
});

test('a personal promotion belonging to another user is rejected', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch'], [
        'scope' => ['user_ids' => [$context['other_user']->id]],
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $promotion->code])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Voucher này không được cấp cho tài khoản của bạn');
});

test('applying a new promotion replaces the previous promotion', function (): void {
    $context = createCartPromotionContext();
    $first = createCartPromotion($context['branch'], [
        'discount_type' => 'fixed_amount',
        'discount_value' => 20_000,
    ]);
    $second = createCartPromotion($context['branch'], [
        'discount_type' => 'percentage',
        'discount_value' => 15,
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $first->code])->assertOk();
    $this->postJson('/api/v1/customer/cart/promotion', ['code' => $second->code])
        ->assertOk()
        ->assertJsonPath('data.applied_promotion.code', $second->code)
        ->assertJsonPath('data.discount_amount', 45_000);

    $this->assertDatabaseHas('carts', [
        'id' => $context['cart']->id,
        'promotion_id' => $second->id,
    ]);
});

test('a customer can remove an applied promotion', function (): void {
    $context = createCartPromotionContext();
    $promotion = createCartPromotion($context['branch']);
    $context['cart']->update(['promotion_id' => $promotion->id]);
    $this->actingAs($context['user']);

    $this->deleteJson('/api/v1/customer/cart/promotion')
        ->assertOk()
        ->assertJsonPath('data.applied_promotion', null)
        ->assertJsonPath('data.discount_amount', 0)
        ->assertJsonPath('data.total_after_discount', 300_000);

    $this->assertDatabaseHas('carts', [
        'id' => $context['cart']->id,
        'promotion_id' => null,
    ]);
});

test('removing a promotion when none is applied returns not found', function (): void {
    $context = createCartPromotionContext();
    $this->actingAs($context['user']);

    $this->deleteJson('/api/v1/customer/cart/promotion')
        ->assertNotFound()
        ->assertJsonPath('message', 'Giỏ hàng chưa áp dụng voucher nào');
});

test('guests cannot access cart promotion endpoints', function (): void {
    $this->getJson('/api/v1/customer/cart/promotions')->assertUnauthorized();
    $this->postJson('/api/v1/customer/cart/promotion', ['code' => 'MIZUKI10'])->assertUnauthorized();
    $this->deleteJson('/api/v1/customer/cart/promotion')->assertUnauthorized();
});

test('internal staff cannot access cart promotion endpoints', function (): void {
    $staff = User::factory()->create(['role' => UserRole::Cashier]);
    $this->actingAs($staff);

    $this->getJson('/api/v1/customer/cart/promotions')->assertForbidden();
    $this->postJson('/api/v1/customer/cart/promotion', ['code' => 'MIZUKI10'])->assertForbidden();
    $this->deleteJson('/api/v1/customer/cart/promotion')->assertForbidden();
});
