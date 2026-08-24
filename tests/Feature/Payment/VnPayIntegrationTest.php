<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Services\PaymentService;
use Carbon\CarbonImmutable;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    config([
        'vnpay.tmn_code' => 'MIZUKITEST',
        'vnpay.hash_secret' => 'test-vnpay-secret',
        'vnpay.payment_url' => 'https://sandbox.vnpayment.vn/paymentv2/vpcpay.html',
        'vnpay.return_url' => 'http://localhost:5173/payment/vnpay/return',
        'vnpay.version' => '2.1.0',
        'vnpay.command' => 'pay',
        'vnpay.order_type' => 'other',
        'vnpay.locale' => 'vn',
        'vnpay.currency' => 'VND',
        'vnpay.expire_minutes' => 15,
        'vnpay.timezone' => 'Asia/Ho_Chi_Minh',
    ]);
});

afterEach(function (): void {
    CarbonImmutable::setTestNow();
});

/** @return array{user: User, order: Order, payment: Payment} */
function createVnPayContext(
    PaymentMethod $method = PaymentMethod::VNPay,
    PaymentStatus $status = PaymentStatus::Pending,
    int $amount = 175_000,
): array {
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'VN'.$token,
        'name' => 'Mizuki VNPay '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-VNP-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => $method,
        'status' => OrderStatus::Pending,
        'subtotal' => $amount,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => $amount,
        'placed_at' => now(),
    ]);
    $payment = app(PaymentService::class)->createForOrder($order, $status);

    return compact('user', 'order', 'payment');
}

/** @param array<string, mixed> $params */
function signVnPayFixture(array $params): array
{
    unset($params['vnp_SecureHash'], $params['vnp_SecureHashType']);
    $signing = array_filter(
        $params,
        static fn (mixed $value, string $key): bool => str_starts_with($key, 'vnp_')
            && is_scalar($value)
            && (string) $value !== '',
        ARRAY_FILTER_USE_BOTH,
    );
    ksort($signing);
    $hashData = implode('&', array_map(
        static fn (string $key, mixed $value): string => urlencode($key).'='.urlencode((string) $value),
        array_keys($signing),
        array_values($signing),
    ));
    $params['vnp_SecureHash'] = hash_hmac('sha512', $hashData, 'test-vnpay-secret');

    return $params;
}

/** @return array<string, string> */
function validVnPayCallback(Payment $payment, array $overrides = []): array
{
    return signVnPayFixture(array_merge([
        'vnp_Amount' => (string) ($payment->amount * 100),
        'vnp_BankCode' => 'NCB',
        'vnp_BankTranNo' => 'VNP14567890',
        'vnp_CardType' => 'ATM',
        'vnp_OrderInfo' => "Thanh toan {$payment->payment_number}",
        'vnp_PayDate' => '20260725153000',
        'vnp_ResponseCode' => '00',
        'vnp_TmnCode' => 'MIZUKITEST',
        'vnp_TransactionNo' => '14567890',
        'vnp_TransactionStatus' => '00',
        'vnp_TxnRef' => $payment->payment_number,
    ], $overrides));
}

/** @param array<string, mixed> $params */
function vnpayQuery(array $params): string
{
    return http_build_query($params, '', '&', PHP_QUERY_RFC3986);
}

test('customer creates a correctly signed VNPay URL only for their own pending VNPay order', function (): void {
    CarbonImmutable::setTestNow(CarbonImmutable::parse('2026-07-25 08:30:00', 'UTC'));
    $context = createVnPayContext();

    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/vnpay")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.payment_number', $context['payment']->payment_number);

    $paymentUrl = $response->json('data.payment_url');
    parse_str((string) parse_url($paymentUrl, PHP_URL_QUERY), $params);

    expect($params)
        ->toHaveKeys([
            'vnp_Version', 'vnp_Command', 'vnp_TmnCode', 'vnp_Amount',
            'vnp_CurrCode', 'vnp_TxnRef', 'vnp_OrderInfo', 'vnp_OrderType',
            'vnp_Locale', 'vnp_ReturnUrl', 'vnp_IpAddr', 'vnp_CreateDate',
            'vnp_ExpireDate', 'vnp_SecureHash',
        ])
        ->and($params['vnp_Amount'])->toBe('17500000')
        ->and($params['vnp_TxnRef'])->toBe($context['payment']->payment_number)
        ->and($params['vnp_CreateDate'])->toBe('20260725153000')
        ->and($params['vnp_ExpireDate'])->toBe('20260725154500');

    $providedHash = $params['vnp_SecureHash'];
    unset($params['vnp_SecureHash']);
    expect(signVnPayFixture($params)['vnp_SecureHash'])->toBe($providedHash)
        ->and(json_encode($response->json()))->not->toContain('test-vnpay-secret');

    $otherCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $this->actingAs($otherCustomer)
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/vnpay")
        ->assertNotFound();
});

test('failed VNPay payment on a pending order can retry with the existing payment identity', function (): void {
    $context = createVnPayContext(status: PaymentStatus::Failed);
    $paymentId = $context['payment']->id;
    $paymentNumber = $context['payment']->payment_number;

    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/vnpay")
        ->assertOk()
        ->assertJsonPath('data.payment_number', $paymentNumber);

    parse_str((string) parse_url($response->json('data.payment_url'), PHP_URL_QUERY), $params);

    expect($params['vnp_TxnRef'])->toBe($paymentNumber)
        ->and($context['payment']->refresh()->id)->toBe($paymentId)
        ->and($context['payment']->payment_number)->toBe($paymentNumber)
        ->and($context['payment']->status)->toBe(PaymentStatus::Failed)
        ->and($context['payment']->failed_at)->not->toBeNull();
    $this->assertDatabaseCount('payments', 1);
});

test('paid cancelled and refunded VNPay payments cannot create a retry URL', function (PaymentStatus $status): void {
    $context = createVnPayContext(status: $status);

    $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/vnpay")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.payment.0', 'Giao dịch không còn ở trạng thái chờ thanh toán');
})->with([
    'paid' => PaymentStatus::Paid,
    'cancelled' => PaymentStatus::Cancelled,
    'refunded' => PaymentStatus::Refunded,
]);

test('failed VNPay payment cannot retry when its order is no longer pending', function (OrderStatus $status): void {
    $context = createVnPayContext(status: PaymentStatus::Failed);
    $context['order']->update(['status' => $status]);

    $response = $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/vnpay")
        ->assertUnprocessable();

    if ($status === OrderStatus::Cancelled) {
        $response->assertJsonPath('data.errors.order.0', 'Đơn hàng đã hủy, không thể thanh toán');
    } else {
        $response->assertJsonPath('data.errors.order.0', 'Đơn hàng không còn ở trạng thái chờ thanh toán');
    }

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Failed);
    $this->assertDatabaseCount('payments', 1);
})->with([
    'cancelled order' => OrderStatus::Cancelled,
    'confirmed order' => OrderStatus::Confirmed,
]);

test('VNPay URL endpoint enforces authentication role method state amount and cancellation rules', function (): void {
    $vnpay = createVnPayContext();
    $cash = createVnPayContext(PaymentMethod::Cash);

    $this->postJson("/api/v1/customer/orders/{$vnpay['order']->id}/payment/vnpay")
        ->assertUnauthorized();
    $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]))
        ->postJson("/api/v1/customer/orders/{$vnpay['order']->id}/payment/vnpay")
        ->assertForbidden();
    $this->actingAs($cash['user'])
        ->postJson("/api/v1/customer/orders/{$cash['order']->id}/payment/vnpay")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.payment_method.0', 'Đơn hàng không sử dụng phương thức VNPay');

    $vnpay['payment']->update(['status' => PaymentStatus::Paid]);
    $this->actingAs($vnpay['user'])
        ->postJson("/api/v1/customer/orders/{$vnpay['order']->id}/payment/vnpay")
        ->assertUnprocessable();

    $cancelled = createVnPayContext();
    $cancelled['order']->update(['status' => OrderStatus::Cancelled]);
    $this->actingAs($cancelled['user'])
        ->postJson("/api/v1/customer/orders/{$cancelled['order']->id}/payment/vnpay")
        ->assertUnprocessable();

    $mismatch = createVnPayContext();
    $mismatch['payment']->update(['amount' => 1]);
    $this->actingAs($mismatch['user'])
        ->postJson("/api/v1/customer/orders/{$mismatch['order']->id}/payment/vnpay")
        ->assertUnprocessable();
});

test('Return verifies signature payment and amount but remains read only', function (): void {
    $context = createVnPayContext();
    $params = validVnPayCallback($context['payment']);

    $this->getJson('/api/v1/payments/vnpay/return?'.vnpayQuery($params))
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.payment_number', $context['payment']->payment_number)
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.amount', 175_000)
        ->assertJsonPath('data.response_code', '00');

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Pending);

    $params['vnp_SecureHash'] = str_repeat('0', 128);
    $this->getJson('/api/v1/payments/vnpay/return?'.vnpayQuery($params))
        ->assertBadRequest();

    $wrongAmount = validVnPayCallback($context['payment'], ['vnp_Amount' => '1']);
    $this->getJson('/api/v1/payments/vnpay/return?'.vnpayQuery($wrongAmount))
        ->assertBadRequest();

    $missing = validVnPayCallback(
        $context['payment'],
        ['vnp_TxnRef' => 'PAY-NOT-FOUND'],
    );
    $this->getJson('/api/v1/payments/vnpay/return?'.vnpayQuery($missing))
        ->assertBadRequest();

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Pending);
});

test('successful IPN atomically marks payment paid and stores only sanitized provider data', function (): void {
    $context = createVnPayContext();
    $originalOrderStatus = $context['order']->status;
    $params = validVnPayCallback($context['payment']);
    $params['vnp_SecureHashType'] = 'SHA512';
    $params = signVnPayFixture($params);

    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($params))
        ->assertOk()
        ->assertExactJson(['RspCode' => '00', 'Message' => 'Confirm Success']);

    $payment = $context['payment']->refresh();
    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->provider)->toBe('vnpay')
        ->and($payment->transaction_reference)->toBe('14567890')
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->failed_at)->toBeNull()
        ->and($payment->cancelled_at)->toBeNull()
        ->and($payment->refunded_at)->toBeNull()
        ->and($payment->provider_response)->toHaveKey('vnp_Amount')
        ->and($payment->provider_response)->not->toHaveKeys(['vnp_SecureHash', 'vnp_SecureHashType'])
        ->and($context['order']->refresh()->status)->toBe($originalOrderStatus);

    $this->actingAs($context['user'])
        ->getJson("/api/v1/customer/orders/{$context['order']->id}/payment")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.provider', 'vnpay')
        ->assertJsonPath('data.provider_transaction_id', '14567890')
        ->assertJsonMissingPath('data.provider_response');
});

test('failed IPN marks only pending payment failed and a later valid success may recover it', function (): void {
    $context = createVnPayContext();
    $failed = validVnPayCallback($context['payment'], [
        'vnp_ResponseCode' => '24',
        'vnp_TransactionStatus' => '02',
    ]);

    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($failed))
        ->assertExactJson(['RspCode' => '00', 'Message' => 'Confirm Success']);
    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Failed)
        ->and($context['payment']->paid_at)->toBeNull()
        ->and($context['payment']->failed_at)->not->toBeNull()
        ->and($context['payment']->refunded_at)->toBeNull();

    $success = validVnPayCallback($context['payment']);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($success))
        ->assertExactJson(['RspCode' => '00', 'Message' => 'Confirm Success']);
    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($context['payment']->paid_at)->not->toBeNull()
        ->and($context['payment']->failed_at)->toBeNull()
        ->and($context['payment']->refunded_at)->toBeNull();
});

test('IPN is idempotent and never downgrades paid or reopens refunded payments', function (): void {
    $paid = createVnPayContext();
    $success = validVnPayCallback($paid['payment']);

    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($success))
        ->assertJsonPath('RspCode', '00');
    $paidAt = $paid['payment']->refresh()->paid_at?->toISOString();
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($success))
        ->assertExactJson(['RspCode' => '02', 'Message' => 'Order already confirmed']);
    expect($paid['payment']->refresh()->paid_at?->toISOString())->toBe($paidAt);

    $failedCallback = validVnPayCallback($paid['payment'], [
        'vnp_ResponseCode' => '24',
        'vnp_TransactionStatus' => '02',
    ]);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($failedCallback))
        ->assertJsonPath('RspCode', '02');
    expect($paid['payment']->refresh()->status)->toBe(PaymentStatus::Paid);

    $refunded = createVnPayContext();
    $refunded['payment']->update([
        'status' => PaymentStatus::Refunded,
        'refunded_at' => now(),
    ]);
    $refundedAt = $refunded['payment']->refresh()->refunded_at?->toISOString();
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery(validVnPayCallback($refunded['payment'])))
        ->assertJsonPath('RspCode', '02');
    expect($refunded['payment']->refresh()->status)->toBe(PaymentStatus::Refunded)
        ->and($refunded['payment']->refunded_at?->toISOString())->toBe($refundedAt)
        ->and($refunded['payment']->paid_at)->toBeNull();
});

test('IPN rejects invalid signature amount payment method and duplicate gateway reference without writes', function (): void {
    $context = createVnPayContext();
    $invalidSignature = validVnPayCallback($context['payment']);
    $invalidSignature['vnp_SecureHash'] = str_repeat('f', 128);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($invalidSignature))
        ->assertExactJson(['RspCode' => '97', 'Message' => 'Invalid signature']);

    $wrongMerchant = validVnPayCallback($context['payment'], ['vnp_TmnCode' => 'OTHER']);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($wrongMerchant))
        ->assertExactJson(['RspCode' => '99', 'Message' => 'Unknown error']);

    $wrongAmount = validVnPayCallback($context['payment'], ['vnp_Amount' => '100']);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery($wrongAmount))
        ->assertExactJson(['RspCode' => '04', 'Message' => 'Invalid amount']);

    $cash = createVnPayContext(PaymentMethod::Cash);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery(validVnPayCallback($cash['payment'])))
        ->assertExactJson(['RspCode' => '01', 'Message' => 'Order not found']);

    $other = createVnPayContext();
    $other['payment']->update(['transaction_reference' => '14567890']);
    $this->getJson('/api/v1/payments/vnpay/ipn?'.vnpayQuery(validVnPayCallback($context['payment'])))
        ->assertExactJson(['RspCode' => '99', 'Message' => 'Unknown error']);

    expect($context['payment']->refresh()->status)->toBe(PaymentStatus::Pending);
});
