<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
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
use App\Models\UserAddress;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Support\Facades\Event;

uses(RefreshDatabase::class);

/** @return array{user: User, branch: Branch, cart: Cart, variant: ProductVariant, inventory: BranchInventory} */
function createOrderCheckoutContext(bool $withItem = true, bool $selectBranch = true): array
{
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'OR'.$token,
        'name' => 'Mizuki Order '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Order '.$token,
        'slug' => 'order-category-'.strtolower($token),
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Order Brand '.$token,
        'slug' => 'order-brand-'.strtolower($token),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Order Product '.$token,
        'slug' => 'order-product-'.strtolower($token),
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'ORDER-'.$token,
        'attributes' => ['capacity' => '50 ml'],
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 10,
        'reserved_quantity' => 1,
        'reorder_level' => 2,
    ]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'branch_id' => $selectBranch ? $branch->id : null,
    ]);

    if ($withItem) {
        CartItem::query()->create([
            'cart_id' => $cart->id,
            'product_variant_id' => $variant->id,
            'quantity' => 2,
        ]);
    }

    return compact('user', 'branch', 'cart', 'variant', 'inventory');
}

function createExistingCustomerOrder(User $user, Branch $branch, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_number' => 'MZ-'.Str::upper(Str::random(12)),
        'user_id' => $user->id,
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
    ], $overrides));
}

test('customer can create an order from a valid cart and cart items are cleared', function (): void {
    $context = createOrderCheckoutContext();
    Event::fake([OrderPlaced::class]);
    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])
        ->assertCreated()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.delivery_method', 'pickup')
        ->assertJsonPath('data.payment_method', 'cash')
        ->assertJsonPath('data.subtotal', 300_000)
        ->assertJsonPath('data.total_amount', 300_000)
        ->assertJsonPath('data.items.0.product_name', $context['variant']->product->name)
        ->assertJsonPath('data.items.0.unit_price', 150_000)
        ->assertJsonPath('data.items.0.quantity', 2);

    $orderId = $response->json('data.id');
    $this->assertDatabaseHas('orders', ['id' => $orderId, 'payment_method' => 'cash']);
    $this->assertDatabaseCount('cart_items', 0);
    $this->assertDatabaseHas('carts', ['id' => $context['cart']->id, 'promotion_id' => null]);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(3);
    Event::assertDispatched(OrderPlaced::class, fn (OrderPlaced $event): bool => $event->order->id === $orderId);
});

test('delivery checkout snapshots an address belonging to the customer', function (): void {
    $context = createOrderCheckoutContext();
    $address = UserAddress::factory()->create([
        'user_id' => $context['user']->id,
        'recipient_name' => 'Nguyễn Mizuki',
        'recipient_phone' => '0901234567',
        'province' => 'Cần Thơ',
        'district' => 'Ninh Kiều',
        'ward' => 'An Khánh',
        'hamlet' => 'Tổ 3',
        'address_line' => '123 Đường 3/2',
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $address->id,
        'payment_method' => 'vnpay',
    ])
        ->assertCreated()
        ->assertJsonPath('data.delivery_method', 'delivery')
        ->assertJsonPath('data.delivery_address.recipient_name', 'Nguyễn Mizuki')
        ->assertJsonPath('data.delivery_address.full_address', '123 Đường 3/2, Tổ 3, An Khánh, Ninh Kiều, Cần Thơ');
});

test('checkout with a promotion records real usage and increments the cached count', function (): void {
    $context = createOrderCheckoutContext();
    $promotion = Promotion::query()->create([
        'code' => 'ORDER10',
        'name' => 'Order 10%',
        'discount_type' => 'percentage',
        'discount_value' => 10,
        'max_discount_amount' => 100_000,
        'minimum_order_amount' => 100_000,
        'usage_limit' => 100,
        'usage_count' => 0,
        'per_user_limit' => 1,
        'applies_to' => 'order',
        'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(),
        'is_active' => true,
    ]);
    $promotion->branches()->attach($context['branch']->id);
    $context['cart']->update(['promotion_id' => $promotion->id]);
    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'wallet',
    ])
        ->assertCreated()
        ->assertJsonPath('data.applied_promotion.code', 'ORDER10')
        ->assertJsonPath('data.discount_amount', 30_000)
        ->assertJsonPath('data.total_amount', 270_000);

    expect($promotion->refresh()->usage_count)->toBe(1);
    $this->assertDatabaseHas('promotion_usages', [
        'promotion_id' => $promotion->id,
        'user_id' => $context['user']->id,
        'order_id' => $response->json('data.id'),
        'discount_amount' => 30_000,
    ]);
});

test('checkout rejects an empty cart with a clear error', function (): void {
    $context = createOrderCheckoutContext(false);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath('data.errors.cart.0', 'Giỏ hàng đang trống');
});

test('checkout rejects a cart without a selected branch', function (): void {
    $context = createOrderCheckoutContext(true, false);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath('data.errors.branch_id.0', 'Vui lòng chọn chi nhánh trước khi đặt hàng');
});

test('delivery checkout requires an address', function (): void {
    $context = createOrderCheckoutContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath('data.errors.address_id.0', 'Vui lòng chọn địa chỉ giao hàng');
});

test('checkout revalidates stock and rolls back every write when stock is insufficient', function (): void {
    $context = createOrderCheckoutContext();
    $context['inventory']->update(['quantity' => 2, 'reserved_quantity' => 1]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath('data.errors.stock.0',
        "Sản phẩm {$context['variant']->product->name} chỉ còn 1 sản phẩm tại chi nhánh đã chọn");

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1);
});

test('checkout rejects a promotion after the customer reaches its per user limit', function (): void {
    $context = createOrderCheckoutContext();
    $promotion = Promotion::query()->create([
        'code' => 'ONCEONLY', 'name' => 'Once', 'discount_type' => 'fixed_amount',
        'discount_value' => 10_000, 'minimum_order_amount' => 0, 'usage_count' => 1,
        'per_user_limit' => 1, 'applies_to' => 'order', 'starts_at' => now()->subDay(),
        'ends_at' => now()->addDay(), 'is_active' => true,
    ]);
    $promotion->branches()->attach($context['branch']->id);
    $oldOrder = createExistingCustomerOrder($context['user'], $context['branch']);
    PromotionUsage::query()->create([
        'promotion_id' => $promotion->id, 'user_id' => $context['user']->id,
        'order_id' => $oldOrder->id, 'promotion_code' => $promotion->code,
        'promotion_name' => $promotion->name, 'discount_amount' => 10_000, 'used_at' => now(),
    ]);
    $context['cart']->update(['promotion_id' => $promotion->id]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup', 'payment_method' => 'cash',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.code.0', 'Bạn đã sử dụng hết số lượt cho voucher này');

    $this->assertDatabaseCount('orders', 1);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1);
});

test('customer order list supports status filtering and newest first ordering', function (): void {
    $context = createOrderCheckoutContext(false);
    createExistingCustomerOrder($context['user'], $context['branch'], ['status' => OrderStatus::Confirmed]);
    $newest = createExistingCustomerOrder($context['user'], $context['branch'], ['status' => OrderStatus::Pending]);
    $this->actingAs($context['user']);

    $this->getJson('/api/v1/customer/orders?status=pending')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $newest->id)
        ->assertJsonPath('meta.pagination.total', 1);
});

test('customer cannot view another customers order', function (): void {
    $context = createOrderCheckoutContext(false);
    $other = User::factory()->create(['role' => UserRole::Customer]);
    $order = createExistingCustomerOrder($other, $context['branch']);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy đơn hàng');
});

test('guest cannot access customer order endpoints', function (): void {
    $this->getJson('/api/v1/customer/orders')->assertUnauthorized();
    $this->postJson('/api/v1/customer/orders', [])->assertUnauthorized();
    $this->getJson('/api/v1/customer/orders/1')->assertUnauthorized();
});

test('customer checkout does not accept the POS-only bank transfer method', function (): void {
    $context = createOrderCheckoutContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'bank_transfer',
    ])
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.payment_method.0', 'Phương thức thanh toán không hợp lệ');

    $this->assertDatabaseCount('orders', 0);
});
