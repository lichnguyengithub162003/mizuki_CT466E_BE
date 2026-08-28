<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Cart;
use App\Models\CartItem;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductImage;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/**
 * @return array{
 *     user: User,
 *     other_user: User,
 *     branch: Branch,
 *     brand: Brand,
 *     product: Product,
 *     variant: ProductVariant,
 *     inventory: BranchInventory,
 *     cart: Cart
 * }
 */
function m4OrderContext(): array
{
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create([
        'name' => 'Khách Mizuki',
        'phone' => '09'.random_int(10000000, 99999999),
        'role' => UserRole::Customer,
    ]);
    $otherUser = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'M4'.$token,
        'name' => 'Mizuki M4 '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'M4 '.$token,
        'slug' => 'm4-category-'.Str::lower($token),
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'M4 Brand '.$token,
        'slug' => 'm4-brand-'.Str::lower($token),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'M4 Product '.$token,
        'slug' => 'm4-product-'.Str::lower($token),
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => '50 ml',
        'sku' => 'M4-'.$token,
        'attributes' => ['capacity' => '50 ml'],
        'price' => 200_000,
        'sale_price' => 150_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'image_url' => '/storage/catalog/products/m4/1.webp',
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 10,
        'reserved_quantity' => 0,
    ]);
    $cart = Cart::query()->create([
        'user_id' => $user->id,
        'branch_id' => $branch->id,
    ]);
    CartItem::query()->create([
        'cart_id' => $cart->id,
        'product_variant_id' => $variant->id,
        'quantity' => 2,
    ]);

    return compact(
        'user',
        'otherUser',
        'branch',
        'brand',
        'product',
        'variant',
        'inventory',
        'cart',
    ) + ['other_user' => $otherUser];
}

/** @param array<string, mixed> $overrides */
function m4CreateOrder(array $context, array $overrides = []): Order
{
    return Order::query()->create(array_merge([
        'order_number' => 'MZ-M4-'.Str::upper(Str::random(12)),
        'user_id' => $context['user']->id,
        'customer_name' => $context['user']->name,
        'customer_phone' => $context['user']->phone,
        'branch_id' => $context['branch']->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Pending,
        'subtotal' => 300_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 300_000,
        'placed_at' => now(),
    ], $overrides));
}

function m4CreatePayment(Order $order, PaymentStatus $status): Payment
{
    return Payment::query()->create([
        'payment_number' => 'PM-M4-'.Str::upper(Str::random(12)),
        'order_id' => $order->id,
        'user_id' => $order->user_id,
        'method' => PaymentMethod::VNPay,
        'status' => $status,
        'amount' => $order->total_amount,
        'provider' => 'vnpay',
        'paid_at' => $status === PaymentStatus::Paid ? now() : null,
        'failed_at' => $status === PaymentStatus::Failed ? now() : null,
    ]);
}

test('new pickup checkout stores authoritative snapshots and exposes pricing contract', function (): void {
    $context = m4OrderContext();
    $this->actingAs($context['user']);

    $response = $this->withHeader('Idempotency-Key', 'm4-checkout-'.Str::uuid())
        ->postJson('/api/v1/customer/orders', [
            'delivery_method' => 'pickup',
            'payment_method' => 'cash',
        ])
        ->assertCreated()
        ->assertJsonPath('data.pickup_customer.name', $context['user']->name)
        ->assertJsonPath('data.pickup_customer.phone', $context['user']->phone)
        ->assertJsonPath('data.pickup_customer.address', null)
        ->assertJsonPath('data.shipment', null)
        ->assertJsonPath('data.shipping_fee', 0)
        ->assertJsonPath('data.product_discount_amount', 0)
        ->assertJsonPath('data.shipping_discount_amount', 0)
        ->assertJsonPath('data.voucher_discount_amount', 0)
        ->assertJsonPath('data.total', 300_000)
        ->assertJsonPath('data.items.0.product_id', $context['product']->id)
        ->assertJsonPath('data.items.0.product_slug', $context['product']->slug)
        ->assertJsonPath('data.items.0.brand.id', $context['brand']->id)
        ->assertJsonPath('data.items.0.brand.name', $context['brand']->name)
        ->assertJsonPath('data.items.0.brand.slug', $context['brand']->slug)
        ->assertJsonPath('data.items.0.original_unit_price', 200_000)
        ->assertJsonPath('data.items.0.final_unit_price', 150_000)
        ->assertJsonPath('data.items.0.unit_price', 150_000)
        ->assertJsonPath('data.items.0.line_total', 300_000)
        ->assertJsonPath('data.items.0.image_url', '/storage/catalog/products/m4/1.webp')
        ->assertJsonPath('data.available_actions.can_cancel', true)
        ->assertJsonPath('data.available_actions.can_repurchase', false);

    $orderId = $response->json('data.id');
    $this->assertDatabaseHas('orders', [
        'id' => $orderId,
        'customer_name' => $context['user']->name,
        'customer_phone' => $context['user']->phone,
    ]);
    $this->assertDatabaseHas('order_items', [
        'order_id' => $orderId,
        'product_id' => $context['product']->id,
        'product_slug' => $context['product']->slug,
        'brand_id' => $context['brand']->id,
        'brand_name' => $context['brand']->name,
        'brand_slug' => $context['brand']->slug,
        'original_unit_price' => 200_000,
        'unit_price' => 150_000,
    ]);

    expect($response->json('data.product_discount_amount'))->toBe(0)
        ->and($response->json('data.shipping_discount_amount'))->toBe(0)
        ->and($response->json('data.voucher_discount_amount'))->toBe(0);

    $this->getJson('/api/v1/customer/orders')
        ->assertOk()
        ->assertJsonPath('data.0.product_discount_amount', 0)
        ->assertJsonPath('data.0.shipping_discount_amount', 0)
        ->assertJsonPath('data.0.voucher_discount_amount', 0)
        ->assertJsonPath('data.0.total', 300_000)
        ->assertJsonPath('data.0.available_actions.can_cancel', true);
});

test('legacy order item falls back to product identity but never fabricates original price', function (): void {
    $context = m4OrderContext();
    $order = m4CreateOrder($context);
    $order->items()->create([
        'product_variant_id' => $context['variant']->id,
        'product_name' => $context['product']->name,
        'variant_name' => $context['variant']->name,
        'sku' => $context['variant']->sku,
        'variant_attributes' => $context['variant']->attributes,
        'unit_price' => 150_000,
        'quantity' => 2,
        'line_total' => 300_000,
    ]);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.items.0.product_id', $context['product']->id)
        ->assertJsonPath('data.items.0.product_slug', $context['product']->slug)
        ->assertJsonPath('data.items.0.brand.id', $context['brand']->id)
        ->assertJsonPath('data.items.0.original_unit_price', null)
        ->assertJsonPath('data.items.0.final_unit_price', 150_000);
});

test('shipment contract exposes stable label and authoritative current warehouse only', function (): void {
    $context = m4OrderContext();
    $order = m4CreateOrder($context, [
        'fulfillment_method' => 'shipping',
        'payment_method' => PaymentMethod::VNPay,
        'shipping_fee' => 30_000,
        'total_amount' => 330_000,
    ]);
    m4CreatePayment($order, PaymentStatus::Paid);
    $order->items()->create([
        'product_variant_id' => $context['variant']->id,
        'product_id' => $context['product']->id,
        'product_slug' => $context['product']->slug,
        'brand_id' => $context['brand']->id,
        'brand_name' => $context['brand']->name,
        'brand_slug' => $context['brand']->slug,
        'product_name' => $context['product']->name,
        'variant_name' => $context['variant']->name,
        'sku' => $context['variant']->sku,
        'variant_attributes' => $context['variant']->attributes,
        'original_unit_price' => 200_000,
        'unit_price' => 150_000,
        'quantity' => 2,
        'line_total' => 300_000,
    ]);
    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-M4-001',
        'status' => 'in_transit',
        'shipping_fee' => 30_000,
        'provider_response' => [
            'CurrentWarehouse' => ['Id' => 12, 'Name' => 'Kho Cần Thơ'],
            'Message' => 'arbitrary text is not a location',
        ],
        'shipped_at' => now(),
    ]);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.shipment.id', $shipment->id)
        ->assertJsonPath('data.shipment.status_label', 'Đang vận chuyển')
        ->assertJsonPath('data.shipment.tracking_code', 'GHN-M4-001')
        ->assertJsonPath('data.shipment.current_location', 'Kho Cần Thơ')
        ->assertJsonPath('data.items.0.brand.id', $context['brand']->id)
        ->assertJsonPath('data.items.0.image_url', '/storage/catalog/products/m4/1.webp')
        ->assertJsonPath('data.items.0.original_unit_price', 200_000)
        ->assertJsonPath('data.items.0.final_unit_price', 150_000)
        ->assertJsonPath('data.available_actions.can_track', true)
        ->assertJsonPath('data.available_actions.can_retry_payment', false);
});

test('available actions follow order refund and VNPay payment state independently', function (): void {
    $context = m4OrderContext();
    $retryOrder = m4CreateOrder($context, [
        'payment_method' => PaymentMethod::VNPay,
    ]);
    $payment = m4CreatePayment($retryOrder, PaymentStatus::Failed);
    $this->actingAs($context['user']);

    $this->getJson("/api/v1/customer/orders/{$retryOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.available_actions.can_retry_payment', true);

    $payment->update([
        'status' => PaymentStatus::Paid,
        'paid_at' => now(),
        'failed_at' => null,
    ]);
    $retryOrder->update(['status' => OrderStatus::Delivered]);

    $this->getJson("/api/v1/customer/orders/{$retryOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.available_actions.can_cancel', false)
        ->assertJsonPath('data.available_actions.can_request_refund', true)
        ->assertJsonPath('data.available_actions.can_retry_payment', false);

    Refund::query()->create([
        'refund_number' => 'RF-M4-'.Str::upper(Str::random(10)),
        'order_id' => $retryOrder->id,
        'user_id' => $context['user']->id,
        'status' => 'requested',
        'requested_amount' => 300_000,
        'reason_type' => 'product_quality',
        'reason' => 'Không phù hợp',
        'evidence_paths' => [],
    ]);

    $this->getJson("/api/v1/customer/orders/{$retryOrder->id}")
        ->assertOk()
        ->assertJsonPath('data.available_actions.can_request_refund', false);
});

test('customer cancellation stores and returns its authoritative actor', function (): void {
    $context = m4OrderContext();
    $order = m4CreateOrder($context);
    $order->items()->create([
        'product_variant_id' => $context['variant']->id,
        'product_name' => $context['product']->name,
        'variant_name' => $context['variant']->name,
        'sku' => $context['variant']->sku,
        'unit_price' => 150_000,
        'quantity' => 2,
        'line_total' => 300_000,
    ]);
    $context['inventory']->update(['reserved_quantity' => 2]);
    $this->actingAs($context['user']);

    $this->postJson("/api/v1/customer/orders/{$order->id}/cancel", [
        'reason_type' => 'changed_mind',
    ])
        ->assertOk()
        ->assertJsonPath('data.cancellation_requested_by', 'customer')
        ->assertJsonPath('data.cancellation_requester_name', $context['user']->name)
        ->assertJsonPath('data.cancellation_requester_type', 'customer')
        ->assertJsonPath('data.cancellation.requested_by', 'customer')
        ->assertJsonPath('data.cancellation.requester_name', $context['user']->name);

    $order->refresh();
    expect($order->cancellation_requested_by_user_id)->toBe($context['user']->id)
        ->and($order->cancellation_requested_at)->not->toBeNull();
});

test('refund lifecycle timestamps and authoritative monetary fields are consistent', function (): void {
    $context = m4OrderContext();
    $wallet = Wallet::query()->create(['user_id' => $context['user']->id, 'balance' => 250_000]);
    $this->actingAs($context['user']);

    foreach (['requested', 'approved', 'rejected', 'refunded'] as $status) {
        $order = m4CreateOrder($context, [
            'status' => OrderStatus::Delivered,
            'subtotal' => 300_000,
            'discount_amount' => 50_000,
            'total_amount' => 250_000,
        ]);
        $walletTransaction = null;

        if ($status === 'refunded') {
            $walletTransaction = WalletTransaction::query()->create([
                'transaction_number' => 'WR-M4-'.Str::upper(Str::random(10)),
                'wallet_id' => $wallet->id,
                'order_id' => $order->id,
                'type' => WalletTransactionType::Refund,
                'direction' => WalletTransactionDirection::Credit,
                'amount' => 240_000,
                'balance_after' => 250_000,
                'reference' => 'RF-M4',
            ]);
        }

        $refund = Refund::query()->create([
            'refund_number' => 'RF-M4-'.Str::upper(Str::random(10)),
            'order_id' => $order->id,
            'user_id' => $context['user']->id,
            'wallet_transaction_id' => $walletTransaction?->id,
            'status' => $status,
            'requested_amount' => 250_000,
            'approved_amount' => in_array($status, ['approved', 'refunded'], true) ? 240_000 : null,
            'reason_type' => 'product_quality',
            'reason' => 'Không phù hợp',
            'evidence_paths' => [],
            'review_note' => $status === 'requested' ? null : 'Đã xử lý',
            'reviewed_at' => $status === 'requested' ? null : now(),
            'refunded_at' => $status === 'refunded' ? now() : null,
        ]);

        $response = $this->getJson("/api/v1/customer/orders/{$order->id}")
            ->assertOk()
            ->assertJsonPath('data.refund.id', $refund->id)
            ->assertJsonPath('data.refund.order_number', $order->order_number)
            ->assertJsonPath('data.refund.requested_at', $refund->created_at->toISOString())
            ->assertJsonPath('data.refund.product_value', 300_000)
            ->assertJsonPath('data.refund.voucher_discount_amount', 50_000);

        if ($status === 'approved') {
            $response->assertJsonPath('data.refund.accepted_at', $refund->reviewed_at->toISOString());
        } elseif ($status === 'rejected') {
            $response->assertJsonPath('data.refund.rejected_at', $refund->reviewed_at->toISOString());
        } elseif ($status === 'refunded') {
            $response
                ->assertJsonPath('data.refund.payment_destination', 'wallet')
                ->assertJsonPath('data.refund.payment_destination_label', 'Ví Mizuki')
                ->assertJsonPath('data.refund.received_amount', 240_000);
        } else {
            $response
                ->assertJsonPath('data.refund.accepted_at', null)
                ->assertJsonPath('data.refund.rejected_at', null)
                ->assertJsonPath('data.refund.payment_destination', null)
                ->assertJsonPath('data.refund.received_amount', null);
        }
    }
});

test('customer cannot read another customers enriched order contract', function (): void {
    $context = m4OrderContext();
    $order = m4CreateOrder($context);
    $this->actingAs($context['other_user']);

    $this->getJson("/api/v1/customer/orders/{$order->id}")->assertNotFound();
});
