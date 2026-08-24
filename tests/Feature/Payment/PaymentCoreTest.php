<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\User;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

/** @return array{user: User, branch: Branch, order: Order} */
function createPaymentCoreOrder(?User $user = null): array
{
    $token = Str::upper(Str::random(8));
    $user ??= User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'PAY'.$token,
        'name' => 'Mizuki Payment '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-PAY-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Pending,
        'subtotal' => 250_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 250_000,
        'placed_at' => now(),
    ]);

    return compact('user', 'branch', 'order');
}

test('createForOrder is idempotent and payment numbers are unique and readable', function (): void {
    $first = createPaymentCoreOrder();
    $second = createPaymentCoreOrder();
    $service = app(PaymentService::class);

    $firstPayment = $service->createForOrder($first['order']);
    $samePayment = $service->createForOrder($first['order']);
    $secondPayment = $service->createForOrder($second['order']);

    expect($samePayment->id)->toBe($firstPayment->id)
        ->and($firstPayment->payment_number)->toMatch('/^PAY-\d{14}-[A-Z0-9]{8}$/')
        ->and($secondPayment->payment_number)->not->toBe($firstPayment->payment_number)
        ->and($firstPayment->amount)->toBe($first['order']->total_amount)
        ->and($firstPayment->method)->toBe($first['order']->payment_method)
        ->and($firstPayment->user_id)->toBe($first['user']->id);
    $this->assertDatabaseCount('payments', 2);
    $this->assertDatabaseCount('orders', 2);
});

test('customer can view only the payment belonging to their own order', function (): void {
    $owner = createPaymentCoreOrder();
    $otherUser = User::factory()->create(['role' => UserRole::Customer]);
    $payment = app(PaymentService::class)->createForOrder($owner['order']);

    $this->actingAs($owner['user'])
        ->getJson("/api/v1/customer/orders/{$owner['order']->id}/payment")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.payment_number', $payment->payment_number)
        ->assertJsonPath('data.method', 'cash')
        ->assertJsonPath('data.status', 'pending')
        ->assertJsonPath('data.amount', 250_000)
        ->assertJsonMissingPath('data.provider_response');

    $this->actingAs($otherUser)
        ->getJson("/api/v1/customer/orders/{$owner['order']->id}/payment")
        ->assertNotFound()
        ->assertJsonPath('message', 'Không tìm thấy thông tin thanh toán');
});

test('guest and internal staff cannot access customer payment endpoint', function (): void {
    $context = createPaymentCoreOrder();
    app(PaymentService::class)->createForOrder($context['order']);

    $this->getJson("/api/v1/customer/orders/{$context['order']->id}/payment")
        ->assertUnauthorized();

    $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]))
        ->getJson("/api/v1/customer/orders/{$context['order']->id}/payment")
        ->assertForbidden();
});

test('creating the payment does not change order status', function (): void {
    $context = createPaymentCoreOrder();

    $payment = app(PaymentService::class)->createForOrder(
        $context['order'],
        PaymentStatus::Pending,
    );

    expect($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->paid_at)->toBeNull()
        ->and($payment->failed_at)->toBeNull()
        ->and($payment->cancelled_at)->toBeNull()
        ->and($payment->refunded_at)->toBeNull()
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Pending);
});

test('payment status transition matrix permits only supported lifecycle changes', function (): void {
    expect(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Paid))->toBeTrue()
        ->and(PaymentStatus::Pending->canTransitionTo(PaymentStatus::Failed))->toBeTrue()
        ->and(PaymentStatus::Failed->canTransitionTo(PaymentStatus::Paid))->toBeTrue()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Failed))->toBeFalse()
        ->and(PaymentStatus::Paid->canTransitionTo(PaymentStatus::Pending))->toBeFalse()
        ->and(PaymentStatus::Refunded->canTransitionTo(PaymentStatus::Paid))->toBeFalse()
        ->and(PaymentStatus::Cancelled->canTransitionTo(PaymentStatus::Paid))->toBeFalse();
});

test('an initially paid internal payment has consistent timestamps without completing the order', function (): void {
    $context = createPaymentCoreOrder();

    $payment = app(PaymentService::class)->createForOrder(
        $context['order'],
        PaymentStatus::Paid,
    );

    expect($payment->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->failed_at)->toBeNull()
        ->and($payment->cancelled_at)->toBeNull()
        ->and($payment->refunded_at)->toBeNull()
        ->and($context['order']->refresh()->status)->toBe(OrderStatus::Pending);

    $this->actingAs($context['user'])
        ->getJson("/api/v1/customer/orders/{$context['order']->id}/payment")
        ->assertOk()
        ->assertJsonPath('data.status', 'paid')
        ->assertJsonPath('data.status_label', 'Đã thanh toán');
});
