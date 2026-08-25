<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Events\OrderPlaced;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Promotion;
use App\Models\PromotionUsage;
use App\Models\Review;
use App\Models\Shipment;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    $this->withHeader('Idempotency-Key', 'checkout-test-'.Str::uuid());
});

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

test('checkout preview returns authoritative pickup totals without creating an order', function (): void {
    $context = createOrderCheckoutContext();
    $promotion = Promotion::query()->create([
        'code' => 'PREVIEW10',
        'name' => 'Preview 10%',
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

    $this->postJson('/api/v1/customer/orders/preview', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertOk()
        ->assertJsonPath('data.delivery_method', 'pickup')
        ->assertJsonPath('data.branch.id', $context['branch']->id)
        ->assertJsonPath('data.address_id', null)
        ->assertJsonPath('data.promotion.code', 'PREVIEW10')
        ->assertJsonPath('data.subtotal', 300_000)
        ->assertJsonPath('data.discount_amount', 30_000)
        ->assertJsonPath('data.shipping_fee', 0)
        ->assertJsonPath('data.total_amount', 270_000)
        ->assertJsonPath('data.expected_delivery_time', null)
        ->assertJsonPath('data.payment_methods.0.value', 'cash')
        ->assertJsonPath('data.payment_methods.1.value', 'wallet')
        ->assertJsonPath('data.payment_methods.2.value', 'vnpay')
        ->assertJsonPath('data.selected_payment_method', 'cash');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('promotion_usages', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($promotion->refresh()->usage_count)->toBe(0)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(1);
    Http::assertNothingSent();
});

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
    $this->assertDatabaseHas('payments', [
        'order_id' => $orderId,
        'user_id' => $context['user']->id,
        'method' => 'cash',
        'status' => 'pending',
        'amount' => 300_000,
    ]);
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
        'province_code' => 'CT',
        'ghn_province_id' => 220,
        'ghn_district_id' => 1444,
        'ghn_ward_code' => '21010',
    ]);
    config()->set([
        'services.ghn.base_url' => 'https://ghn.test/shiip/public-api/v2',
        'services.ghn.token' => 'checkout-token',
        'services.ghn.shop_id' => '123456',
    ]);
    Http::fake([
        '*/shipping-order/available-services' => Http::response([
            'code' => 200,
            'data' => [[
                'service_id' => 53320,
                'short_name' => 'Light',
                'service_type_id' => 2,
            ]],
        ]),
        '*/shipping-order/fee' => Http::response([
            'code' => 200,
            'data' => ['total' => 30_000],
        ]),
    ]);
    $this->actingAs($context['user']);
    $quoteToken = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $address->id,
    ])->assertOk()->json('data.quote_token');

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $address->id,
        'shipping_quote_token' => $quoteToken,
        'payment_method' => 'vnpay',
    ])
        ->assertCreated()
        ->assertJsonPath('data.delivery_method', 'delivery')
        ->assertJsonPath('data.delivery_address.recipient_name', 'Nguyễn Mizuki')
        ->assertJsonPath('data.delivery_address.full_address', '123 Đường 3/2, Tổ 3, An Khánh, Ninh Kiều, Cần Thơ');

    $this->assertDatabaseHas('payments', [
        'order_id' => $response->json('data.id'),
        'user_id' => $context['user']->id,
        'method' => 'vnpay',
        'status' => 'pending',
        'amount' => 330_000,
    ]);
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
        'payment_method' => 'cash',
    ])
        ->assertCreated()
        ->assertJsonPath('data.applied_promotion.code', 'ORDER10')
        ->assertJsonPath('data.discount_amount', 30_000)
        ->assertJsonPath('data.total_amount', 270_000);

    expect($promotion->refresh()->usage_count)->toBe(1);
    $this->assertDatabaseHas('payments', [
        'order_id' => $response->json('data.id'),
        'user_id' => $context['user']->id,
        'method' => 'cash',
        'status' => 'pending',
        'amount' => 270_000,
    ]);
    $this->assertDatabaseHas('promotion_usages', [
        'promotion_id' => $promotion->id,
        'user_id' => $context['user']->id,
        'order_id' => $response->json('data.id'),
        'discount_amount' => 30_000,
    ]);
});

test('wallet checkout creates and pays the order atomically when balance is sufficient', function (): void {
    $context = createOrderCheckoutContext();
    $wallet = Wallet::query()->create([
        'user_id' => $context['user']->id,
        'balance' => 500_000,
    ]);

    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'wallet',
    ])
        ->assertCreated()
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.payment_method', 'wallet')
        ->assertJsonPath('data.total_amount', 300_000);

    $orderId = $response->json('data.id');
    $payment = Payment::query()->where('order_id', $orderId)->sole();
    $transaction = WalletTransaction::query()->sole();

    expect($wallet->refresh()->balance)->toBe(200_000)
        ->and($payment->status->value)->toBe('paid')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->wallet_transaction_id)->toBe($transaction->id)
        ->and($transaction->type->value)->toBe('order_payment')
        ->and($transaction->direction->value)->toBe('debit')
        ->and($transaction->amount)->toBe(300_000)
        ->and($transaction->balance_after)->toBe(200_000)
        ->and($transaction->order_id)->toBe($orderId)
        ->and($transaction->reference)->toBe($payment->payment_number)
        ->and($transaction->created_by_user_id)->toBe($context['user']->id)
        ->and(Order::query()->findOrFail($orderId)->status)->toBe(OrderStatus::Pending)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(3);

    $this->assertDatabaseCount('cart_items', 0);
});

test('wallet checkout with insufficient balance rolls back every checkout write', function (): void {
    $context = createOrderCheckoutContext();
    $wallet = Wallet::query()->create([
        'user_id' => $context['user']->id,
        'balance' => 100_000,
    ]);
    $promotion = Promotion::query()->create([
        'code' => 'WALLETFAIL',
        'name' => 'Wallet insufficient',
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

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'wallet',
    ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.balance.0',
            'Số tiền trong ví không đủ để thanh toán đơn hàng này!',
        );

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
    $this->assertDatabaseCount('promotion_usages', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($wallet->refresh()->balance)->toBe(100_000)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(1)
        ->and($promotion->refresh()->usage_count)->toBe(0)
        ->and($context['cart']->refresh()->promotion_id)->toBe($promotion->id);
});

test('repeating checkout with the same idempotency key returns the original order', function (): void {
    $context = createOrderCheckoutContext();
    $idempotencyKey = 'retry-'.Str::uuid();
    $wallet = Wallet::query()->create([
        'user_id' => $context['user']->id,
        'balance' => 700_000,
    ]);
    Event::fake([OrderPlaced::class]);
    $this->actingAs($context['user'])
        ->withHeader('Idempotency-Key', $idempotencyKey);
    $payload = [
        'delivery_method' => 'pickup',
        'payment_method' => 'wallet',
    ];

    $first = $this->postJson('/api/v1/customer/orders', $payload)->assertCreated();
    $second = $this->postJson('/api/v1/customer/orders', $payload)->assertCreated();

    expect($second->json('data.id'))->toBe($first->json('data.id'))
        ->and($second->json('data.order_number'))->toBe($first->json('data.order_number'))
        ->and($wallet->refresh()->balance)->toBe(400_000)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(3);
    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', 1);
    $this->assertDatabaseCount('wallet_transactions', 1);
    $this->assertDatabaseHas('orders', [
        'id' => $first->json('data.id'),
        'checkout_idempotency_key_hash' => hash('sha256', $idempotencyKey),
    ]);
    Event::assertDispatchedTimes(OrderPlaced::class, 1);
});

test('database uniqueness closes the concurrent checkout idempotency race', function (): void {
    $context = createOrderCheckoutContext(false);
    $keyHash = hash('sha256', 'concurrent-'.Str::uuid());
    $requestHash = hash('sha256', 'same-checkout-payload');

    // Model two workers that both passed preflight; the unique index is the final race guard.
    createExistingCustomerOrder($context['user'], $context['branch'], [
        'checkout_idempotency_key_hash' => $keyHash,
        'checkout_request_hash' => $requestHash,
    ]);

    expect(fn (): Order => createExistingCustomerOrder(
        $context['user'],
        $context['branch'],
        [
            'checkout_idempotency_key_hash' => $keyHash,
            'checkout_request_hash' => $requestHash,
        ],
    ))->toThrow(QueryException::class);
});

test('checkout rejects a reused idempotency key with a different payload', function (): void {
    $context = createOrderCheckoutContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertCreated();

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'vnpay',
    ])->assertUnprocessable()->assertJsonPath(
        'data.errors.idempotency_key.0',
        'Mã chống trùng này đã được dùng cho một yêu cầu đặt hàng khác',
    );

    $this->assertDatabaseCount('orders', 1);
    $this->assertDatabaseCount('payments', 1);
});

test('checkout requires an idempotency key header', function (): void {
    $context = createOrderCheckoutContext();
    $this->actingAs($context['user'])->withHeader('Idempotency-Key', '');

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath(
        'data.errors.idempotency_key.0',
        'Thiếu mã chống trùng khi đặt hàng',
    );

    $this->assertDatabaseCount('orders', 0);
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

test('checkout rejects a cart item when its variant became inactive', function (): void {
    $context = createOrderCheckoutContext();
    $context['variant']->update(['is_active' => false]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath(
        'data.errors.cart.0',
        "Biến thể {$context['variant']->name} của sản phẩm {$context['variant']->product->name} đã ngừng bán",
    );

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1);
});

test('checkout rejects a cart item when its parent product became inactive', function (): void {
    $context = createOrderCheckoutContext();
    $context['variant']->product->update(['is_active' => false]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath(
        'data.errors.cart.0',
        "Sản phẩm {$context['variant']->product->name} đã ngừng bán",
    );

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1);
});

test('pickup checkout rejects the selected branch when it became inactive', function (): void {
    $context = createOrderCheckoutContext();
    $context['branch']->update(['is_active' => false]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonPath(
        'data.errors.branch_id.0',
        'Chi nhánh đã chọn hiện không hoạt động',
    );

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
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
    $this->postJson('/api/v1/customer/orders/preview', [])->assertUnauthorized();
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

    $this->postJson('/api/v1/customer/orders/preview', [
        'delivery_method' => 'pickup',
        'payment_method' => 'bank_transfer',
    ])->assertUnprocessable()
        ->assertJsonPath('data.errors.payment_method.0', 'Phương thức thanh toán không hợp lệ');

    $this->assertDatabaseCount('orders', 0);
});

test('payment failure rolls back customer order and checkout writes', function (): void {
    $context = createOrderCheckoutContext();
    $paymentService = Mockery::mock(PaymentService::class);
    $paymentService->shouldReceive('createForOrder')
        ->once()
        ->andThrow(new RuntimeException('Simulated payment persistence failure'));
    $this->app->instance(PaymentService::class, $paymentService);
    $this->actingAs($context['user']);
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'payment_method' => 'cash',
    ]))->toThrow(RuntimeException::class, 'Simulated payment persistence failure');

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($context['inventory']->refresh()->reserved_quantity)->toBe(1);
});
test('customer order list excludes other customers orders and validates filters', function (): void {
    $context = createOrderCheckoutContext(false);
    $ownOrder = createExistingCustomerOrder($context['user'], $context['branch']);
    $otherUser = User::factory()->create(['role' => UserRole::Customer]);
    $otherOrder = createExistingCustomerOrder($otherUser, $context['branch']);
    $this->actingAs($context['user']);

    $this->getJson('/api/v1/customer/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownOrder->id)
        ->assertJsonMissing(['id' => $otherOrder->id]);

    $this->getJson('/api/v1/customer/orders?status=unknown')
        ->assertUnprocessable();

    $this->getJson('/api/v1/customer/orders?per_page=101')
        ->assertUnprocessable();
});

test('customer order detail preserves delivery snapshot and exposes shipment tracking', function (): void {
    $context = createOrderCheckoutContext(false);

    $order = createExistingCustomerOrder($context['user'], $context['branch'], [
        'fulfillment_method' => 'shipping',
        'user_address_id' => null,
        'recipient_name' => 'Mizuki Customer',
        'recipient_phone' => '0900000000',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'shipping_address' => '123 Test Street, Can Tho',
        'shipping_fee' => 30_000,
        'total_amount' => 130_000,
    ]);

    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHNTEST123',
        'status' => 'in_transit',
        'shipping_fee' => 30_000,
        'expected_delivery_at' => now()->addDay(),
        'shipped_at' => now(),
    ]);

    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.delivery_method', 'delivery')
        ->assertJsonPath('data.delivery_address.address_id', null)
        ->assertJsonPath('data.delivery_address.recipient_name', 'Mizuki Customer')
        ->assertJsonPath('data.delivery_address.full_address', '123 Test Street, Can Tho')
        ->assertJsonPath('data.shipment.provider', 'ghn')
        ->assertJsonPath('data.shipment.tracking_code', 'GHNTEST123')
        ->assertJsonPath('data.shipment.status', 'in_transit');
});

test('customer order detail exposes backend derived product review eligibility', function (): void {
    $context = createOrderCheckoutContext(false);
    $order = createExistingCustomerOrder($context['user'], $context['branch'], [
        'status' => OrderStatus::Delivered,
    ]);
    $item = $order->items()->create([
        'product_variant_id' => $context['variant']->id,
        'product_name' => $context['variant']->product->name,
        'variant_name' => $context['variant']->name,
        'sku' => $context['variant']->sku,
        'variant_attributes' => $context['variant']->attributes,
        'unit_price' => 100_000,
        'quantity' => 1,
        'line_total' => 100_000,
    ]);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $item->id)
        ->assertJsonPath('data.items.0.can_review', true)
        ->assertJsonPath('data.items.0.review', null);

    $review = Review::query()->create([
        'user_id' => $context['user']->id,
        'product_id' => $context['variant']->product_id,
        'product_variant_id' => $context['variant']->id,
        'order_item_id' => $item->id,
        'rating' => 5,
        'title' => 'Rất tốt',
        'comment' => 'Sản phẩm phù hợp',
        'is_visible' => true,
    ]);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.can_review', false)
        ->assertJsonPath('data.items.0.review.id', $review->id)
        ->assertJsonPath('data.items.0.review.rating', 5)
        ->assertJsonPath('data.items.0.review.title', 'Rất tốt');

    $secondOrder = createExistingCustomerOrder($context['user'], $context['branch'], [
        'status' => OrderStatus::Delivered,
    ]);
    $secondItem = $secondOrder->items()->create([
        'product_variant_id' => $context['variant']->id,
        'product_name' => $context['variant']->product->name,
        'variant_name' => $context['variant']->name,
        'sku' => $context['variant']->sku,
        'variant_attributes' => $context['variant']->attributes,
        'unit_price' => 100_000,
        'quantity' => 1,
        'line_total' => 100_000,
    ]);

    $this->getJson("/api/v1/customer/orders/{$secondOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.id', $secondItem->id)
        ->assertJsonPath('data.items.0.can_review', false)
        ->assertJsonPath('data.items.0.review', null);
});
