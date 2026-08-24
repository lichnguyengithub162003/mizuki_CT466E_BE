<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\Shipment;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Repositories\WalletTransactionRepository;
use App\Services\PaymentService;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function createOrderAdminBranch(string $prefix = 'OA'): Branch
{
    $token = Str::upper(Str::random(8));

    return Branch::query()->create([
        'code' => $prefix.$token,
        'name' => 'Mizuki Order '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

function createAdminManagedOrder(
    Branch $branch,
    User $customer,
    OrderStatus $status = OrderStatus::Pending,
    ?string $orderNumber = null,
): Order {
    return Order::query()->create([
        'order_number' => $orderNumber ?? 'MZ-'.Str::upper(Str::random(12)),
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => $status,
        'subtotal' => 300_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 300_000,
        'placed_at' => now(),
    ]);
}

function createAdminManagedRefund(Order $order, User $customer, string $status = 'requested'): Refund
{
    return Refund::query()->create([
        'refund_number' => 'RF-'.Str::upper(Str::random(12)),
        'order_id' => $order->id,
        'user_id' => $customer->id,
        'status' => $status,
        'requested_amount' => $order->total_amount,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm bị hư hỏng',
        'evidence_paths' => ['refund-evidence/proof.jpg'],
    ]);
}

test('guest and customer cannot access admin order and refund endpoints', function (): void {
    $paths = [
        ['GET', '/api/v1/admin/orders'],
        ['GET', '/api/v1/admin/orders/1'],
        ['POST', '/api/v1/admin/orders/1/confirm'],
        ['POST', '/api/v1/admin/orders/1/process'],
        ['POST', '/api/v1/admin/orders/1/complete'],
        ['GET', '/api/v1/admin/refunds'],
        ['GET', '/api/v1/admin/refunds/1'],
        ['POST', '/api/v1/admin/refunds/1/approve'],
        ['POST', '/api/v1/admin/refunds/1/reject'],
        ['POST', '/api/v1/admin/refunds/1/wallet-payout'],
    ];

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertUnauthorized();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertForbidden();
    }
});

test('super admin pays an approved refund into a lazily created wallet idempotently', function (): void {
    $branch = createOrderAdminBranch('WP');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $originalOrderStatus = $order->status;
    $refund = createAdminManagedRefund($order, $customer, 'approved');
    $refund->update([
        'approved_amount' => 275_000,
        'reviewed_at' => now(),
    ]);
    $paymentCount = Payment::query()->count();
    $this->actingAs($admin);

    $firstPayoutResponse = $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.status', 'refunded')
        ->assertJsonPath('data.order.id', $order->id)
        ->assertJsonPath('data.order.order_number', $order->order_number)
        ->assertJsonPath('data.order.status', $originalOrderStatus->value)
        ->assertJsonPath('data.order.total_amount', $order->total_amount)
        ->assertJsonPath('data.wallet_transaction.type', 'refund')
        ->assertJsonPath('data.wallet_transaction.direction', 'credit')
        ->assertJsonPath('data.wallet_transaction.amount', 275_000)
        ->assertJsonPath('data.wallet_transaction.balance_after', 275_000)
        ->assertJsonPath('data.wallet_transaction.reference', $refund->refund_number)
        ->assertJsonPath('data.status_label', 'Đã hoàn tiền')
        ->assertJsonPath('data.allowed_actions', []);

    $wallet = Wallet::query()->where('user_id', $customer->id)->firstOrFail();
    $refund->refresh();
    $transaction = WalletTransaction::query()->findOrFail($refund->wallet_transaction_id);

    expect($wallet->balance)->toBe(275_000)
        ->and($refund->status)->toBe('refunded')
        ->and($refund->refunded_at)->not->toBeNull()
        ->and($transaction->wallet_id)->toBe($wallet->id)
        ->and($transaction->order_id)->toBe($order->id)
        ->and($transaction->created_by_user_id)->toBe($admin->id)
        ->and($transaction->reference)->toBe($refund->refund_number)
        ->and($transaction->description)->toContain($order->order_number)
        ->and($order->refresh()->status)->toBe($originalOrderStatus)
        ->and(Payment::query()->count())->toBe($paymentCount);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertOk()
        ->assertJsonPath('data.order', $firstPayoutResponse->json('data.order'))
        ->assertJsonPath('data.wallet_transaction.id', $transaction->id);

    expect($wallet->refresh()->balance)->toBe(275_000)
        ->and(WalletTransaction::query()->where('reference', $refund->refund_number)->count())->toBe(1);
});

test('branch manager can payout only refunds from their own branch', function (): void {
    $ownBranch = createOrderAdminBranch('WO');
    $otherBranch = createOrderAdminBranch('WX');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $ownRefund = createAdminManagedRefund(
        createAdminManagedOrder($ownBranch, $customer, OrderStatus::Delivered),
        $customer,
        'approved',
    );
    $ownRefund->update(['approved_amount' => 100_000]);
    $otherRefund = createAdminManagedRefund(
        createAdminManagedOrder($otherBranch, $customer, OrderStatus::Delivered),
        $customer,
        'approved',
    );
    $otherRefund->update(['approved_amount' => 50_000]);
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/refunds/{$ownRefund->id}/wallet-payout")
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded');

    $this->postJson("/api/v1/admin/refunds/{$otherRefund->id}/wallet-payout")
        ->assertNotFound();

    expect($otherRefund->refresh()->status)->toBe('approved')
        ->and($otherRefund->wallet_transaction_id)->toBeNull();
});

test('requested and rejected refunds cannot be paid into a wallet', function (string $status): void {
    $branch = createOrderAdminBranch('WS');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = createAdminManagedRefund(
        createAdminManagedOrder($branch, $customer, OrderStatus::Delivered),
        $customer,
        $status,
    );
    $refund->update(['approved_amount' => $status === 'rejected' ? null : 100_000]);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.status.0',
            'Chỉ yêu cầu hoàn tiền đã duyệt mới có thể chi trả vào ví',
        );

    $this->assertDatabaseMissing('wallet_transactions', ['order_id' => $refund->order_id]);
})->with(['requested', 'rejected']);

test('approved refund requires a positive approved amount before wallet payout', function (?int $amount): void {
    $branch = createOrderAdminBranch('WA');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = createAdminManagedRefund(
        createAdminManagedOrder($branch, $customer, OrderStatus::Delivered),
        $customer,
        'approved',
    );
    $refund->update(['approved_amount' => $amount]);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.approved_amount.0', 'Số tiền được duyệt phải lớn hơn 0');

    $this->assertDatabaseMissing('wallet_transactions', ['order_id' => $refund->order_id]);
})->with([null, 0]);

test('wallet payout rolls back the balance when ledger creation fails', function (): void {
    $branch = createOrderAdminBranch('WR');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $wallet = Wallet::query()->create(['user_id' => $customer->id, 'balance' => 25_000]);
    $refund = createAdminManagedRefund(
        createAdminManagedOrder($branch, $customer, OrderStatus::Delivered),
        $customer,
        'approved',
    );
    $refund->update(['approved_amount' => 125_000]);
    $this->mock(WalletTransactionRepository::class)
        ->shouldReceive('createTransaction')
        ->once()
        ->andThrow(new RuntimeException('Simulated ledger failure'));
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));
    $this->withoutExceptionHandling();

    expect(fn () => $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout"))
        ->toThrow(RuntimeException::class, 'Simulated ledger failure');

    expect($wallet->refresh()->balance)->toBe(25_000)
        ->and($refund->refresh()->status)->toBe('approved')
        ->and($refund->wallet_transaction_id)->toBeNull()
        ->and($refund->refunded_at)->toBeNull();
    $this->assertDatabaseMissing('wallet_transactions', ['order_id' => $refund->order_id]);
});

test('super admin sees all branches and can filter orders by status and keyword', function (): void {
    $firstBranch = createOrderAdminBranch('SA');
    $secondBranch = createOrderAdminBranch('SB');
    $firstCustomer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Nguyễn Minh Hạ',
        'email' => 'minhha@example.test',
    ]);
    $secondCustomer = User::factory()->create(['role' => UserRole::Customer]);
    $matched = createAdminManagedOrder(
        $firstBranch,
        $firstCustomer,
        OrderStatus::Pending,
        'MZ-SEARCH-001',
    );
    createAdminManagedOrder($secondBranch, $secondCustomer, OrderStatus::Confirmed);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.pagination.total', 2);

    $this->getJson('/api/v1/admin/orders?status=pending&keyword=Minh%20Hạ')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $matched->id);

    $this->getJson("/api/v1/admin/orders?branch_id={$secondBranch->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.branch.id', $secondBranch->id);
});

test('branch manager only sees own branch and cross branch details return 404', function (): void {
    $ownBranch = createOrderAdminBranch('OWN');
    $otherBranch = createOrderAdminBranch('OTH');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $ownOrder = createAdminManagedOrder($ownBranch, $customer);
    $otherOrder = createAdminManagedOrder($otherBranch, $customer);
    $ownRefund = createAdminManagedRefund($ownOrder, $customer);
    $otherRefund = createAdminManagedRefund($otherOrder, $customer);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $this->actingAs($manager);

    $this->getJson('/api/v1/admin/orders')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownOrder->id);

    $this->getJson("/api/v1/admin/orders?branch_id={$otherBranch->id}")
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownOrder->id);

    $this->getJson("/api/v1/admin/orders/{$otherOrder->id}")->assertNotFound();
    $this->getJson("/api/v1/admin/refunds/{$otherRefund->id}")->assertNotFound();
    $this->getJson('/api/v1/admin/refunds')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $ownRefund->id);
    $this->getJson("/api/v1/admin/refunds/{$ownRefund->id}")
        ->assertOk()
        ->assertJsonPath('data.branch.id', $ownBranch->id);
});

test('refund list supports status and keyword filters within admin scope', function (): void {
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'email' => 'refund.search@example.test',
    ]);
    $requestedOrder = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $approvedOrder = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $refundedOrder = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $requested = createAdminManagedRefund($requestedOrder, $customer);
    createAdminManagedRefund($approvedOrder, $customer, 'approved');
    $refunded = createAdminManagedRefund($refundedOrder, $customer, 'refunded');
    $otherBranch = createOrderAdminBranch('RFB');
    $otherOrder = createAdminManagedOrder($otherBranch, $customer, OrderStatus::Delivered);
    createAdminManagedRefund($otherOrder, $customer);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/refunds')
        ->assertOk()
        ->assertJsonCount(4, 'data');

    $this->getJson('/api/v1/admin/refunds?status=requested&keyword=refund.search')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonFragment(['id' => $requested->id]);

    $this->getJson('/api/v1/admin/refunds?status=refunded')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $refunded->id)
        ->assertJsonPath('data.0.status_label', 'Đã hoàn tiền')
        ->assertJsonPath('data.0.allowed_actions', []);
});

test('admin refund filters reject unsupported contract values', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/refunds?status=unknown')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?branch_id=0')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?per_page=101')->assertUnprocessable();
});

test('admin confirms only pending orders and dispatches status event after commit', function (): void {
    Event::fake([OrderStatusUpdated::class]);
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $pending = createAdminManagedOrder($branch, $customer);
    $confirmed = createAdminManagedOrder($branch, $customer, OrderStatus::Confirmed);
    app(PaymentService::class)->createForOrder($pending, PaymentStatus::Pending);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/orders/{$pending->id}/confirm")
        ->assertOk()
        ->assertJsonPath('data.status', 'confirmed');

    expect($pending->refresh()->status)->toBe(OrderStatus::Confirmed);
    Event::assertDispatched(
        OrderStatusUpdated::class,
        fn (OrderStatusUpdated $event): bool => $event->order->id === $pending->id
            && $event->previousStatus === OrderStatus::Pending,
    );

    $this->postJson("/api/v1/admin/orders/{$confirmed->id}/confirm")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Chỉ có thể xác nhận đơn hàng đang chờ xác nhận');
});

test('admin order filters reject unsupported contract values', function (): void {
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/orders?status=unknown')->assertUnprocessable();
    $this->getJson('/api/v1/admin/orders?branch_id=0')->assertUnprocessable();
    $this->getJson('/api/v1/admin/orders?per_page=101')->assertUnprocessable();
});

test('admin order detail exposes payment delivery shipment and allowed actions', function (): void {
    $branch = createOrderAdminBranch('OD');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Processing);
    $order->update([
        'fulfillment_method' => 'shipping',
        'user_address_id' => null,
        'recipient_name' => 'Mizuki Customer',
        'recipient_phone' => '0900000000',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'shipping_address' => '123 Test Street, Can Tho',
        'shipping_fee' => 30_000,
        'total_amount' => 330_000,
        'note' => 'Giao trong giờ hành chính',
    ]);
    $payment = app(PaymentService::class)->createForOrder($order, PaymentStatus::Paid);
    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-ADMIN-001',
        'status' => 'ready_to_pick',
        'shipping_fee' => 30_000,
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson("/api/v1/admin/orders/{$order->id}")
        ->assertOk()
        ->assertJsonPath('data.payment.id', $payment->id)
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.delivery_address.address_id', null)
        ->assertJsonPath('data.delivery_address.recipient_name', 'Mizuki Customer')
        ->assertJsonPath('data.delivery_address.full_address', '123 Test Street, Can Tho')
        ->assertJsonPath('data.shipment.tracking_code', 'GHN-ADMIN-001')
        ->assertJsonPath('data.shipment.status', 'ready_to_pick')
        ->assertJsonPath('data.allowed_actions.0', 'shipment_label')
        ->assertJsonPath('data.allowed_actions.1', 'cancel_shipment')
        ->assertJsonPath('data.note', 'Giao trong giờ hành chính');
});

test('admin processes only confirmed paid or COD orders within branch scope', function (): void {
    Event::fake([OrderStatusUpdated::class]);
    $ownBranch = createOrderAdminBranch('PO');
    $otherBranch = createOrderAdminBranch('PX');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $ownOrder = createAdminManagedOrder($ownBranch, $customer, OrderStatus::Confirmed);
    $otherOrder = createAdminManagedOrder($otherBranch, $customer, OrderStatus::Confirmed);
    app(PaymentService::class)->createForOrder($ownOrder, PaymentStatus::Pending);
    app(PaymentService::class)->createForOrder($otherOrder, PaymentStatus::Pending);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/orders/{$ownOrder->id}/process")
        ->assertOk()
        ->assertJsonPath('data.status', 'processing')
        ->assertJsonPath('data.allowed_actions.0', 'complete');

    expect($ownOrder->refresh()->status)->toBe(OrderStatus::Processing);
    Event::assertDispatched(
        OrderStatusUpdated::class,
        fn (OrderStatusUpdated $event): bool => $event->order->id === $ownOrder->id
            && $event->previousStatus === OrderStatus::Confirmed,
    );

    $this->postJson("/api/v1/admin/orders/{$otherOrder->id}/process")
        ->assertNotFound();
    $this->postJson("/api/v1/admin/orders/{$ownOrder->id}/process")
        ->assertUnprocessable();
});

test('admin completes pickup COD atomically and rejects manual shipping completion', function (): void {
    Event::fake([OrderStatusUpdated::class]);
    $branch = createOrderAdminBranch('CP');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $pickup = createAdminManagedOrder($branch, $customer, OrderStatus::Processing);
    $pickupPayment = app(PaymentService::class)->createForOrder($pickup, PaymentStatus::Pending);
    $shipping = createAdminManagedOrder($branch, $customer, OrderStatus::Processing);
    $shipping->update(['fulfillment_method' => 'shipping']);
    app(PaymentService::class)->createForOrder($shipping, PaymentStatus::Pending);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/orders/{$pickup->id}/complete")
        ->assertOk()
        ->assertJsonPath('data.status', 'delivered')
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.allowed_actions', []);

    expect($pickup->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($pickupPayment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($pickupPayment->processed_by_user_id)->toBe($manager->id)
        ->and($pickupPayment->paid_at)->not->toBeNull();

    $this->postJson("/api/v1/admin/orders/{$shipping->id}/complete")
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.fulfillment_method.0',
            'Chỉ đơn nhận tại chi nhánh mới hoàn tất thủ công',
        );
});

test('admin approves requested refund with default amount and stores reviewer metadata', function (): void {
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $refund = createAdminManagedRefund($order, $customer);
    $payment = app(PaymentService::class)->createForOrder($order, PaymentStatus::Paid);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve", [
        'review_note' => 'Đã xác minh',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.status_label', 'Đã duyệt')
        ->assertJsonPath('data.allowed_actions', ['wallet_payout'])
        ->assertJsonPath('data.approved_amount', 300_000)
        ->assertJsonPath('data.reviewer.id', $admin->id);

    $refund->refresh();
    expect($refund->reviewed_by_user_id)->toBe($admin->id)
        ->and($refund->reviewed_at)->not->toBeNull()
        ->and($refund->wallet_transaction_id)->toBeNull();
    $this->assertDatabaseCount('wallet_transactions', 0);
    expect($payment->refresh()->status)->toBe(PaymentStatus::Paid);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Yêu cầu hoàn tiền đã được xử lý');
});

test('admin rejects requested refund and cannot process it again', function (): void {
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $refund = createAdminManagedRefund($order, $customer);
    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $branch->id,
    ]);
    $this->actingAs($manager);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/reject", [
        'review_note' => 'Bằng chứng không hợp lệ',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.status_label', 'Đã từ chối')
        ->assertJsonPath('data.allowed_actions', [])
        ->assertJsonPath('data.review_note', 'Bằng chứng không hợp lệ')
        ->assertJsonPath('data.reviewer.id', $manager->id);

    expect($refund->refresh()->reviewed_at)->not->toBeNull();

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/reject", [
        'review_note' => 'Thử xử lý lại',
    ])->assertUnprocessable();
});

test('approved amount cannot exceed requested amount', function (): void {
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $refund = createAdminManagedRefund($order, $customer);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve", [
        'approved_amount' => 300_001,
    ])
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.approved_amount.0',
            'Số tiền duyệt không được vượt quá số tiền yêu cầu',
        );

    expect($refund->refresh()->status)->toBe('requested')
        ->and($refund->reviewed_by_user_id)->toBeNull();
});

test('database prevents multiple refund requests for the same order', function (): void {
    $branch = createOrderAdminBranch('RU');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    createAdminManagedRefund($order, $customer);

    expect(fn (): Refund => createAdminManagedRefund($order, $customer))
        ->toThrow(QueryException::class);
});
