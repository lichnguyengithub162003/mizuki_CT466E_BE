<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
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
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\Client\Request;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Cache::flush();
    config()->set([
        'services.ghn.base_url' => 'https://ghn.test/shiip/public-api',
        'services.ghn.token' => 'shipping-test-token',
        'services.ghn.shop_id' => '123456',
        'services.ghn.timeout_seconds' => 10,
        'services.ghn.connect_timeout_seconds' => 5,
        'shipping.quote_ttl_minutes' => 10,
        'shipping.package.default_length_cm' => 20,
        'shipping.package.default_width_cm' => 15,
        'shipping.package.default_height_cm' => 10,
        'shipping.package.max_dimension_cm' => 200,
        'shipping.package.max_weight_grams' => 30_000,
        'shipping.package.max_insurance_value' => 5_000_000,
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{
 *     user: User,
 *     branch: Branch,
 *     cart: Cart,
 *     item: CartItem,
 *     variant: ProductVariant,
 *     inventory: BranchInventory,
 *     address: UserAddress
 * }
 */
function createShippingQuoteContext(): array
{
    $token = Str::lower(Str::random(10));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'SQ'.Str::upper($token),
        'name' => 'Shipping '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kieu, Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Shipping '.$token,
        'slug' => 'shipping-category-'.$token,
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Shipping '.$token,
        'slug' => 'shipping-brand-'.$token,
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Shipping Product '.$token,
        'slug' => 'shipping-product-'.$token,
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Default',
        'sku' => 'SHIP-'.Str::upper($token),
        'attributes' => ['capacity' => '50 ml'],
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 250,
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
        'branch_id' => $branch->id,
    ]);
    $item = CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);
    $address = UserAddress::factory()->create([
        'user_id' => $user->id,
        'province_code' => 'CT',
        'ghn_province_id' => 220,
        'ghn_district_id' => 1444,
        'ghn_ward_code' => '21010',
    ]);

    return compact('user', 'branch', 'cart', 'item', 'variant', 'inventory', 'address');
}

function fakeShippingQuoteGhn(int $shippingFee = 30_000): void
{
    Http::fake(function (Request $request) use ($shippingFee) {
        if ($request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/available-services') {
            return Http::response([
                'code' => 200,
                'data' => [
                    ['service_id' => 53321, 'short_name' => 'Bulky', 'service_type_id' => 5],
                    ['service_id' => 53320, 'short_name' => 'Light', 'service_type_id' => 2],
                ],
            ]);
        }

        if ($request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/fee') {
            return Http::response([
                'code' => 200,
                'data' => [
                    'total' => $shippingFee,
                    'service_fee' => $shippingFee,
                    'insurance_fee' => 0,
                    'expected_delivery_time' => '2026-08-03T23:59:59+07:00',
                ],
            ]);
        }

        return Http::response(['code' => 404, 'data' => []], 404);
    });
}

function shippingQuoteCacheKey(string $quoteToken): string
{
    return 'shipping.quote.'.hash('sha256', $quoteToken);
}

test('authenticated customer obtains an authoritative opaque shipping quote', function (): void {
    $context = createShippingQuoteContext();
    fakeShippingQuoteGhn(30_000);
    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
        'shipping_fee' => 1,
        'weight' => 1,
        'service_id' => 1,
    ])->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.shipping_fee', 30_000)
        ->assertJsonPath('data.service_id', 53320)
        ->assertJsonPath('data.service_type_id', 2)
        ->assertJsonPath('data.expected_delivery_time', '2026-08-03T23:59:59+07:00')
        ->assertJsonPath('message', 'Lấy phí vận chuyển thành công!');

    $quoteToken = $response->json('data.quote_token');

    expect($quoteToken)->toBeString()->toMatch('/\A[a-f0-9]{64}\z/')
        ->and(Cache::has(shippingQuoteCacheKey($quoteToken)))->toBeTrue();

    Http::assertSentCount(2);
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/available-services'
        && $request['shop_id'] === 123456
        && $request['from_district'] === 1442
        && $request['to_district'] === 1444
        && ! $request->hasHeader('ShopId')
    );
    Http::assertSent(fn (Request $request): bool => $request->url() === 'https://ghn.test/shiip/public-api/v2/shipping-order/fee'
        && $request->hasHeader('ShopId', '123456')
        && $request['weight'] === 500
        && $request['length'] === 20
        && $request['items'][0]['quantity'] === 2
        && $request['insurance_value'] === 300000
    );
});

test('shipping quote requires authentication and customer role', function (): void {
    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => 1])
        ->assertUnauthorized();

    $staff = User::factory()->create(['role' => UserRole::Cashier]);
    $this->actingAs($staff)
        ->postJson('/api/v1/customer/shipping/quote', ['address_id' => 1])
        ->assertForbidden();
});

test('shipping quote enforces address ownership and complete GHN mappings', function (): void {
    $context = createShippingQuoteContext();
    $otherAddress = UserAddress::factory()->create([
        'ghn_district_id' => 1444,
        'ghn_ward_code' => '21010',
    ]);
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => $otherAddress->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['address_id']]]);

    $context['address']->update(['ghn_ward_code' => null]);
    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => $context['address']->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['address_id']]]);

    Http::assertNothingSent();
});

test('shipping quote rejects missing branch mapping empty cart and invalid product weight', function (): void {
    $context = createShippingQuoteContext();
    $this->actingAs($context['user']);

    $context['branch']->update(['ghn_district_id' => 0]);
    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => $context['address']->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['branch_id']]]);

    $context['branch']->update(['ghn_district_id' => 1442]);
    $context['item']->delete();
    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => $context['address']->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['cart']]]);

    $context['item'] = CartItem::query()->create([
        'cart_id' => $context['cart']->id,
        'product_variant_id' => $context['variant']->id,
        'quantity' => 1,
    ]);
    $context['variant']->update(['weight' => 0]);
    $this->postJson('/api/v1/customer/shipping/quote', ['address_id' => $context['address']->id])
        ->assertUnprocessable()
        ->assertJsonStructure(['data' => ['errors' => ['weight']]]);

    Http::assertNothingSent();
});

test('GHN provider failures return a normalized secret-safe validation error', function (): void {
    $context = createShippingQuoteContext();
    Http::fake(Http::response([
        'code' => 500,
        'message' => 'shipping-test-token 123456 raw provider failure',
        'data' => [],
    ], 500));
    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping']]]);

    expect($response->getContent())
        ->not->toContain('shipping-test-token')
        ->not->toContain('123456')
        ->not->toContain('raw provider failure');
});

test('delivery checkout requires a valid unexpired quote owned by the customer', function (): void {
    $context = createShippingQuoteContext();
    $this->actingAs($context['user']);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    fakeShippingQuoteGhn();
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $other = User::factory()->create(['role' => UserRole::Customer]);
    $otherAddress = UserAddress::factory()->create([
        'user_id' => $other->id,
        'ghn_district_id' => 1444,
        'ghn_ward_code' => '21010',
    ]);
    $this->actingAs($other)->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $otherAddress->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    CarbonImmutable::setTestNow(now()->addMinutes(11));
    $this->actingAs($context['user'])->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);
});

test('quote becomes stale after cart item variant branch or address changes', function (): void {
    $context = createShippingQuoteContext();
    fakeShippingQuoteGhn();
    $this->actingAs($context['user']);
    $checkout = fn (string $token) => $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'cash',
    ]);
    $quote = fn (): string => $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $quantityToken = $quote();
    $context['item']->update(['quantity' => 3]);
    $checkout($quantityToken)->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    $variantToken = $quote();
    $context['variant']->update(['weight' => 300]);
    $checkout($variantToken)->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    $addressToken = $quote();
    $context['address']->update(['ghn_ward_code' => '21011']);
    $checkout($addressToken)->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    $branchToken = $quote();
    $newBranch = Branch::query()->create([
        'code' => 'SQOTHER',
        'name' => 'Other shipping branch',
        'phone' => '02923887777',
        'address' => 'Cai Rang, Can Tho',
        'province_code' => 'CT',
        'ghn_district_id' => 1443,
        'ghn_ward_code' => '22010',
        'is_active' => true,
    ]);
    $context['cart']->update(['branch_id' => $newBranch->id]);
    $checkout($branchToken)->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['shipping_quote_token']]]);

    $this->assertDatabaseCount('orders', 0);
});

test('delivery checkout stores fee includes it in payment and consumes quote only after success', function (): void {
    $context = createShippingQuoteContext();
    fakeShippingQuoteGhn(30_000);
    $this->actingAs($context['user']);
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'shipping_fee' => 1,
        'payment_method' => 'vnpay',
    ])->assertCreated()
        ->assertJsonPath('data.shipping_fee', 30_000)
        ->assertJsonPath('data.subtotal', 300_000)
        ->assertJsonPath('data.total_amount', 330_000)
        ->assertJsonPath('data.status', 'pending');

    $order = Order::query()->findOrFail($response->json('data.id'));
    $payment = Payment::query()->where('order_id', $order->id)->sole();

    expect($order->shipping_fee)->toBe(30_000)
        ->and($order->total_amount)->toBe(330_000)
        ->and($order->status)->toBe(OrderStatus::Pending)
        ->and($payment->amount)->toBe(330_000)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(3)
        ->and(Cache::has(shippingQuoteCacheKey($token)))->toBeFalse();
    $this->assertDatabaseCount('cart_items', 0);
    Http::assertSentCount(2);
});

test('wallet checkout validates and debits the final total including shipping fee', function (): void {
    $context = createShippingQuoteContext();
    $wallet = Wallet::query()->create(['user_id' => $context['user']->id, 'balance' => 330_000]);
    fakeShippingQuoteGhn(30_000);
    $this->actingAs($context['user']);
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'wallet',
    ])->assertCreated()->assertJsonPath('data.total_amount', 330_000);

    $payment = Payment::query()->where('order_id', $response->json('data.id'))->sole();
    $ledger = WalletTransaction::query()->sole();
    expect($wallet->refresh()->balance)->toBe(0)
        ->and($payment->amount)->toBe(330_000)
        ->and($payment->status)->toBe(PaymentStatus::Paid)
        ->and($ledger->amount)->toBe(330_000)
        ->and($ledger->balance_after)->toBe(0);
});

test('insufficient wallet balance rolls back checkout and leaves quote retryable', function (): void {
    $context = createShippingQuoteContext();
    $wallet = Wallet::query()->create(['user_id' => $context['user']->id, 'balance' => 300_000]);
    fakeShippingQuoteGhn(30_000);
    $this->actingAs($context['user']);
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'wallet',
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['balance']]]);

    $this->assertDatabaseCount('orders', 0);
    $this->assertDatabaseCount('payments', 0);
    $this->assertDatabaseCount('wallet_transactions', 0);
    $this->assertDatabaseCount('promotion_usages', 0);
    $this->assertDatabaseCount('cart_items', 1);
    expect($wallet->refresh()->balance)->toBe(300_000)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(1)
        ->and(Cache::has(shippingQuoteCacheKey($token)))->toBeTrue();
});

test('failed stock checkout leaves quote retryable and rolls back writes', function (): void {
    $context = createShippingQuoteContext();
    fakeShippingQuoteGhn();
    $this->actingAs($context['user']);
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');
    $context['inventory']->update(['quantity' => 2, 'reserved_quantity' => 1]);

    $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'cash',
    ])->assertUnprocessable()->assertJsonStructure(['data' => ['errors' => ['stock']]]);

    expect(Cache::has(shippingQuoteCacheKey($token)))->toBeTrue()
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(1);
    $this->assertDatabaseCount('orders', 0);
});

test('promotion discount is applied before adding authoritative shipping fee', function (): void {
    $context = createShippingQuoteContext();
    $promotion = Promotion::query()->create([
        'code' => 'SHIP10',
        'name' => 'Shipping discount regression',
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
    fakeShippingQuoteGhn(30_000);
    $this->actingAs($context['user']);
    $token = $this->postJson('/api/v1/customer/shipping/quote', [
        'address_id' => $context['address']->id,
    ])->assertOk()->json('data.quote_token');

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'delivery',
        'address_id' => $context['address']->id,
        'shipping_quote_token' => $token,
        'payment_method' => 'cash',
    ])->assertCreated()
        ->assertJsonPath('data.subtotal', 300_000)
        ->assertJsonPath('data.discount_amount', 30_000)
        ->assertJsonPath('data.shipping_fee', 30_000)
        ->assertJsonPath('data.total_amount', 300_000);

    $this->assertDatabaseHas('promotion_usages', [
        'promotion_id' => $promotion->id,
        'order_id' => $response->json('data.id'),
        'discount_amount' => 30_000,
    ]);
});

test('pickup checkout remains fee free and ignores an irrelevant quote field', function (): void {
    $context = createShippingQuoteContext();
    $this->actingAs($context['user']);

    $response = $this->postJson('/api/v1/customer/orders', [
        'delivery_method' => 'pickup',
        'shipping_quote_token' => str_repeat('a', 64),
        'payment_method' => 'cash',
    ])->assertCreated()
        ->assertJsonPath('data.shipping_fee', 0)
        ->assertJsonPath('data.total_amount', 300_000);

    $this->assertDatabaseHas('payments', [
        'order_id' => $response->json('data.id'),
        'amount' => 300_000,
    ]);
    Http::assertNothingSent();
});
