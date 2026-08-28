<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\User;
use App\Services\PaymentService;
use App\Services\VnPayService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vnpay.expire_minutes' => 15,
        'vnpay.tmn_code' => 'MIZUKITEST',
        'vnpay.hash_secret' => 'test-vnpay-secret',
    ]);
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-27 12:00:00'));
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/**
 * @return array{
 *     order: Order,
 *     payment: Payment,
 *     inventory: BranchInventory,
 *     user: User
 * }
 */
function createExpiringVnPayContext(
    CarbonImmutable $paymentCreatedAt,
    PaymentStatus $paymentStatus = PaymentStatus::Pending,
    OrderStatus $orderStatus = OrderStatus::Pending,
    int $quantity = 2,
): array {
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'EX'.$token,
        'name' => 'Mizuki Expiry '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'Danh mục '.$token,
        'slug' => 'expiry-category-'.Str::lower($token),
        'is_active' => true,
    ]);
    $brand = Brand::query()->create([
        'name' => 'Thương hiệu '.$token,
        'slug' => 'expiry-brand-'.Str::lower($token),
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'Sản phẩm '.$token,
        'slug' => 'expiry-product-'.Str::lower($token),
        'is_active' => true,
        'is_featured' => false,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'Phiên bản tiêu chuẩn',
        'sku' => 'EXP-'.$token,
        'attributes' => [],
        'price' => 100_000,
        'weight' => 100,
        'is_active' => true,
    ]);
    $inventory = BranchInventory::query()->create([
        'branch_id' => $branch->id,
        'product_variant_id' => $variant->id,
        'quantity' => 20,
        'reserved_quantity' => $quantity,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-EXP-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::VNPay,
        'status' => $orderStatus,
        'subtotal' => 200_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 200_000,
        'placed_at' => $paymentCreatedAt,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_name' => 'Sản phẩm VNPay',
        'variant_name' => 'Phiên bản tiêu chuẩn',
        'sku' => $variant->sku,
        'variant_attributes' => [],
        'unit_price' => 100_000,
        'quantity' => $quantity,
        'line_total' => 200_000,
    ]);
    $payment = app(PaymentService::class)->createForOrder($order, $paymentStatus);
    $payment->forceFill([
        'created_at' => $paymentCreatedAt,
        'updated_at' => $paymentCreatedAt,
    ])->saveQuietly();

    return compact('order', 'payment', 'inventory', 'user');
}

test('pending VNPay payment before its derived expiry is not processed', function (): void {
    $context = createExpiringVnPayContext(now()->toImmutable()->subMinutes(14));

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 0')
        ->expectsOutput('Bỏ qua: 0')
        ->expectsOutput('Thất bại: 0')
        ->assertSuccessful();

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(2)
        ->and($context['inventory']->quantity)->toBe(20);
});

test('payments at or beyond expiry cancel pending orders and release only reserved stock', function (): void {
    $atBoundary = createExpiringVnPayContext(now()->toImmutable()->subMinutes(15));
    $overdue = createExpiringVnPayContext(now()->toImmutable()->subMinutes(45));

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 2')
        ->expectsOutput('Bỏ qua: 0')
        ->expectsOutput('Thất bại: 0')
        ->assertSuccessful();

    foreach ([$atBoundary, $overdue] as $context) {
        expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Failed)
            ->and($context['payment']->paid_at)->toBeNull()
            ->and($context['payment']->failed_at)->not->toBeNull()
            ->and($context['payment']->cancelled_at)->toBeNull()
            ->and($context['payment']->refunded_at)->toBeNull()
            ->and($context['order']->refresh()->status)->toBe(OrderStatus::Cancelled)
            ->and($context['order']->cancelled_at)->not->toBeNull()
            ->and($context['order']->cancellation_reason_type)->toBe('payment_expired')
            ->and($context['order']->cancellation_reason)->toBe('Thanh toán VNPay đã hết hạn')
            ->and($context['order']->cancellation_requested_by)->toBe('system')
            ->and($context['order']->cancellation_requested_by_user_id)->toBeNull()
            ->and($context['order']->cancellation_requested_at)->not->toBeNull()
            ->and($context['inventory']->refresh()->reserved_quantity)->toBe(0)
            ->and($context['inventory']->quantity)->toBe(20);
    }
});

test('terminal payments and already cancelled orders are not processed', function (
    PaymentStatus $paymentStatus,
): void {
    $context = createExpiringVnPayContext(
        now()->toImmutable()->subHour(),
        $paymentStatus,
    );

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 0')
        ->assertSuccessful();

    expect($context['payment']->refresh()->status)->toBe($paymentStatus)
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(2);
})->with([
    PaymentStatus::Paid,
    PaymentStatus::Failed,
    PaymentStatus::Cancelled,
    PaymentStatus::Refunded,
]);

test('an already cancelled order is skipped even when its payment remains pending', function (): void {
    $context = createExpiringVnPayContext(
        now()->toImmutable()->subHour(),
        PaymentStatus::Pending,
        OrderStatus::Cancelled,
    );

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 0')
        ->assertSuccessful();

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($context['inventory']->refresh()->reserved_quantity)->toBe(2);
});

test('running expiration twice does not release reserved stock twice', function (): void {
    $context = createExpiringVnPayContext(now()->toImmutable()->subHour());

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 1')
        ->assertSuccessful();
    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 0')
        ->assertSuccessful();

    expect($context['inventory']->refresh()->reserved_quantity)->toBe(0)
        ->and($context['inventory']->quantity)->toBe(20);
});

test('multiple expired orders are isolated when one has inconsistent reservation', function (): void {
    $first = createExpiringVnPayContext(now()->toImmutable()->subHour());
    $broken = createExpiringVnPayContext(now()->toImmutable()->subHour());
    $second = createExpiringVnPayContext(now()->toImmutable()->subHour());
    $broken['inventory']->update(['reserved_quantity' => 1]);

    $this->artisan('payments:expire-vnpay')
        ->expectsOutput('Đã xử lý: 2')
        ->expectsOutput('Thất bại: 1')
        ->assertFailed();

    expect($first['order']->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($second['order']->refresh()->status)->toBe(OrderStatus::Cancelled)
        ->and($broken['order']->refresh()->status)->toBe(OrderStatus::Pending)
        ->and($broken['payment']->refresh()->status)->toBe(PaymentStatus::Pending)
        ->and($broken['inventory']->refresh()->reserved_quantity)->toBe(1);
});

test('successful VNPay IPN arriving after expiration cannot mark payment paid', function (): void {
    $context = createExpiringVnPayContext(now()->toImmutable()->subHour());
    $this->artisan('payments:expire-vnpay')->assertSuccessful();
    $params = [
        'vnp_Amount' => (string) ($context['payment']->amount * 100),
        'vnp_ResponseCode' => '00',
        'vnp_TmnCode' => 'MIZUKITEST',
        'vnp_TransactionNo' => 'LATE123456',
        'vnp_TransactionStatus' => '00',
        'vnp_TxnRef' => $context['payment']->payment_number,
    ];
    $params['vnp_SecureHash'] = app(VnPayService::class)->generateSecureHash($params);

    $this->getJson('/api/v1/payments/vnpay/ipn?'.http_build_query($params))
        ->assertExactJson(['RspCode' => '02', 'Message' => 'Order already confirmed']);
    $this->getJson('/api/v1/payments/vnpay/return?'.http_build_query($params))
        ->assertOk()
        ->assertJsonPath('data.status', 'failed');

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($context['payment']->paid_at)->toBeNull()
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Cancelled);
});
