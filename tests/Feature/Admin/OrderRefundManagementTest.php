<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\Brand;
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
use App\Repositories\WalletTransactionRepository;
use App\Services\PaymentService;
use App\Services\Shipping\GhnClient;
use Illuminate\Database\QueryException;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

beforeEach(function (): void {
    Storage::fake('public');
    Storage::disk('public')->put('qa/admin-order-item.webp', 'image');
    Storage::disk('public')->put('refund-evidence/proof.jpg', 'image');
});

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

test('admin shipping queue is filtered by shipment presence and canonical status', function (): void {
    $branch = createOrderAdminBranch('SQ');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $withoutShipment = createAdminManagedOrder($branch, $customer, OrderStatus::Processing, 'MZ-NO-SHIPMENT');
    $withShipment = createAdminManagedOrder($branch, $customer, OrderStatus::Processing, 'MZ-WITH-SHIPMENT');
    $withoutShipment->update(['fulfillment_method' => 'shipping']);
    $withShipment->update(['fulfillment_method' => 'shipping']);
    Shipment::query()->create([
        'order_id' => $withShipment->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-QUEUE-001',
        'status' => 'in_transit',
        'shipping_fee' => 30_000,
    ]);

    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $response = $this->actingAs($admin)
        ->getJson('/api/v1/admin/orders?shipping_only=1&shipment_status=in_transit')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $withShipment->id)
        ->assertJsonPath('data.0.shipment.status_label', 'Đang vận chuyển');

    expect(collect($response->json('data'))->pluck('id'))->not->toContain($withoutShipment->id);
});

test('admin order list sorts server side and composes with shipping filters', function (): void {
    $branch = createOrderAdminBranch('SO');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $oldest = createAdminManagedOrder($branch, $customer, OrderStatus::Processing, 'MZ-SORT-OLD');
    $newest = createAdminManagedOrder($branch, $customer, OrderStatus::Processing, 'MZ-SORT-NEW');
    $oldest->update(['fulfillment_method' => 'shipping', 'created_at' => now()->subDay()]);
    $newest->update(['fulfillment_method' => 'shipping']);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)
        ->getJson('/api/v1/admin/orders?shipping_only=1&sort=oldest&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.id', $oldest->id)
        ->assertJsonPath('meta.pagination.total', 2);
    $this->getJson('/api/v1/admin/orders?shipping_only=1&sort=newest&per_page=1')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newest->id);
});

test('guest and customer cannot access admin order and refund endpoints', function (): void {
    $paths = [
        ['GET', '/api/v1/admin/orders'],
        ['GET', '/api/v1/admin/orders/counts'],
        ['GET', '/api/v1/admin/orders/1'],
        ['POST', '/api/v1/admin/orders/1/confirm'],
        ['POST', '/api/v1/admin/orders/1/process'],
        ['POST', '/api/v1/admin/orders/1/complete'],
        ['GET', '/api/v1/admin/refunds'],
        ['GET', '/api/v1/admin/refunds/counts'],
        ['GET', '/api/v1/admin/refunds/1'],
        ['GET', '/api/v1/admin/refunds/1/evidence'],
        ['POST', '/api/v1/admin/refunds/1/approve'],
        ['POST', '/api/v1/admin/refunds/1/reject'],
        ['POST', '/api/v1/admin/refunds/1/wallet-payout'],
        ['POST', '/api/v1/admin/refunds/1/manual-settlement'],
        ['POST', '/api/v1/admin/refunds/1/return/receive'],
        ['POST', '/api/v1/admin/refunds/1/return/restock'],
        ['POST', '/api/v1/admin/refunds/1/return/not-restockable'],
        ['POST', '/api/v1/admin/refunds/1/return/reject-inspection'],
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
    $order->update(['payment_method' => PaymentMethod::Wallet]);
    app(PaymentService::class)->createForOrder($order, PaymentStatus::Paid);
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
        $ownOrder = createAdminManagedOrder($ownBranch, $customer, OrderStatus::Delivered),
        $customer,
        'approved',
    );
    $ownOrder->update(['payment_method' => PaymentMethod::Wallet]);
    app(PaymentService::class)->createForOrder($ownOrder, PaymentStatus::Paid);
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

test('paid VNPAY refund keeps its authoritative original transaction destination', function (): void {
    $branch = createOrderAdminBranch('MS');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $order->update(['payment_method' => PaymentMethod::VNPay]);
    app(PaymentService::class)->createForOrder($order, PaymentStatus::Paid);
    $refund = createAdminManagedRefund($order, $customer, 'approved');
    $refund->update(['approved_amount' => 300_000, 'reviewed_at' => now()]);
    $this->actingAs($admin);

    $this->getJson("/api/v1/admin/refunds/{$refund->id}")
        ->assertOk()
        ->assertJsonPath('data.next_action', 'manual_settlement')
        ->assertJsonPath('data.allowed_actions.0', 'manual_settlement');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.payment.0', 'Phương thức thanh toán này không hoàn tiền vào Ví Mizuki');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/manual-settlement", [])
        ->assertUnprocessable();
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/manual-settlement", [
        'settlement_reference' => 'VNPAY-REFUND-QA-001',
    ])
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded')
        ->assertJsonPath('data.destination', 'vnpay')
        ->assertJsonPath('data.settlement.destination', 'vnpay_original')
        ->assertJsonPath('data.settlement_reference', 'VNPAY-REFUND-QA-001')
        ->assertJsonPath('data.wallet_transaction', null);

    expect($refund->refresh()->settlement_method)->toBe('vnpay')
        ->and($refund->settlement_reference)->toBe('VNPAY-REFUND-QA-001')
        ->and($refund->wallet_transaction_id)->toBeNull();
    $this->assertDatabaseMissing('wallet_transactions', ['order_id' => $order->id]);
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
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $order->update(['payment_method' => PaymentMethod::Wallet]);
    app(PaymentService::class)->createForOrder($order, PaymentStatus::Paid);
    $refund = createAdminManagedRefund(
        $order,
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
    $this->getJson('/api/v1/admin/orders?sort_by=raw_sql')->assertUnprocessable();
    $this->getJson('/api/v1/admin/orders?sort_direction=sideways')->assertUnprocessable();
    $this->getJson('/api/v1/admin/orders?date_from=31-12-2026')->assertUnprocessable();
});

test('admin order list composes date search branch status and whitelisted sorting', function (): void {
    $branch = createOrderAdminBranch('V2');
    $otherBranch = createOrderAdminBranch('V2O');
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Customer Orders V2',
        'phone' => '0909123456',
    ]);
    $older = createAdminManagedOrder($branch, $customer, OrderStatus::Pending, 'MZ-V2-OLD');
    $newer = createAdminManagedOrder($branch, $customer, OrderStatus::Pending, 'MZ-V2-NEW');
    $excluded = createAdminManagedOrder($otherBranch, $customer, OrderStatus::Pending, 'MZ-V2-OTHER');
    foreach ([
        [$older, 100_000, '2026-08-10 09:00:00'],
        [$newer, 500_000, '2026-08-12 09:00:00'],
        [$excluded, 900_000, '2026-08-12 09:00:00'],
    ] as [$order, $amount, $createdAt]) {
        $order->timestamps = false;
        $order->forceFill(['total_amount' => $amount, 'created_at' => $createdAt])->save();
    }
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $url = "/api/v1/admin/orders?branch_id={$branch->id}&status=pending&keyword=0909123456&date_from=2026-08-01&date_to=2026-08-31&sort_by=total_amount&per_page=1";
    $this->getJson($url.'&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonPath('data.0.id', $older->id);

    $this->getJson($url.'&sort_direction=desc')
        ->assertOk()
        ->assertJsonPath('data.0.id', $newer->id);
});

test('admin order search covers registered customer and checkout snapshots', function (): void {
    $branch = createOrderAdminBranch('OS');
    $customer = User::factory()->create([
        'role' => UserRole::Customer,
        'name' => 'Nguyễn Hải Yến',
        'email' => 'haiyen.orders@example.test',
        'phone' => '0912345678',
    ]);
    $registered = createAdminManagedOrder($branch, $customer, OrderStatus::Pending, 'MZ-SEARCH-AUTH');
    $registered->update([
        'recipient_name' => 'Trần Người Nhận',
        'recipient_phone' => '0988777666',
    ]);
    $guest = createAdminManagedOrder($branch, $customer, OrderStatus::Pending, 'MZ-SEARCH-GUEST');
    $guest->update([
        'user_id' => null,
        'channel' => 'counter',
        'customer_name' => 'Khách POS Bảo Châu',
        'customer_phone' => '0905111222',
    ]);
    createAdminManagedOrder($branch, User::factory()->create(['role' => UserRole::Customer]));
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    foreach (['0912345678', '123456', 'Nguyễn Hải Yến', 'Hải Yến', 'haiyen.orders', 'Trần Người Nhận', '877766'] as $keyword) {
        $this->getJson('/api/v1/admin/orders?keyword='.urlencode($keyword))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $registered->id);
    }

    foreach (['Khách POS Bảo Châu', 'Bảo Châu', '0905111222', '511122'] as $keyword) {
        $this->getJson('/api/v1/admin/orders?keyword='.urlencode($keyword))
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $guest->id);
    }
});

test('completed is the single admin contract for persisted delivered orders', function (): void {
    $branch = createOrderAdminBranch('OC');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $completed = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered, 'MZ-COMPLETED');
    createAdminManagedOrder($branch, $customer, OrderStatus::Pending, 'MZ-NOT-COMPLETED');
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/orders?status=completed')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $completed->id)
        ->assertJsonPath('data.0.status', 'completed')
        ->assertJsonPath('data.0.status_label', 'Hoàn thành');
});

test('admin order counts are lightweight and branch scoped', function (): void {
    $ownBranch = createOrderAdminBranch('OCOUNT');
    $otherBranch = createOrderAdminBranch('OCOUNTO');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    createAdminManagedOrder($ownBranch, $customer, OrderStatus::Pending);
    createAdminManagedOrder($ownBranch, $customer, OrderStatus::Pending);
    createAdminManagedOrder($ownBranch, $customer, OrderStatus::Processing);
    createAdminManagedOrder($ownBranch, $customer, OrderStatus::Shipping);
    createAdminManagedOrder($ownBranch, $customer, OrderStatus::RefundRequested);
    createAdminManagedOrder($otherBranch, $customer, OrderStatus::Pending);

    $manager = User::factory()->create([
        'role' => UserRole::BranchManager,
        'branch_id' => $ownBranch->id,
    ]);
    $this->actingAs($manager)->getJson('/api/v1/admin/orders/counts')
        ->assertOk()
        ->assertJsonPath('data.pending', 2)
        ->assertJsonPath('data.processing', 1)
        ->assertJsonPath('data.shipping', 1)
        ->assertJsonPath('data.refund', 1);

    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->getJson('/api/v1/admin/orders/counts')
        ->assertOk()
        ->assertJsonPath('data.pending', 3);
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
    $brand = Brand::query()->create([
        'name' => 'QA Order Brand',
        'slug' => 'qa-order-brand',
        'follower_count' => 0,
        'is_active' => true,
    ]);
    $category = Category::query()->create([
        'name' => 'QA Order Category',
        'slug' => 'qa-order-category',
        'is_active' => true,
    ]);
    $product = Product::query()->create([
        'category_id' => $category->id,
        'brand_id' => $brand->id,
        'name' => 'QA Order Product',
        'slug' => 'qa-order-product',
        'is_active' => true,
    ]);
    $variant = ProductVariant::query()->create([
        'product_id' => $product->id,
        'name' => 'QA Variant',
        'sku' => 'QA-ORDER-VARIANT',
        'price' => 300_000,
        'weight' => 50,
        'sort_order' => 0,
        'is_active' => true,
    ]);
    ProductImage::query()->create([
        'product_id' => $product->id,
        'product_variant_id' => $variant->id,
        'image_url' => '/storage/qa/admin-order-item.webp',
        'sort_order' => 0,
        'is_primary' => true,
    ]);
    $order->items()->create([
        'product_variant_id' => $variant->id,
        'product_id' => $product->id,
        'product_slug' => $product->slug,
        'brand_id' => $brand->id,
        'brand_name' => $brand->name,
        'brand_slug' => $brand->slug,
        'product_name' => $product->name,
        'variant_name' => $variant->name,
        'sku' => $variant->sku,
        'original_unit_price' => 300_000,
        'unit_price' => 300_000,
        'quantity' => 1,
        'line_total' => 300_000,
    ]);
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
        ->assertJsonPath('data.payment_status', 'paid')
        ->assertJsonPath('data.payment_status_label', 'Đã thu tiền')
        ->assertJsonPath('data.payment.id', $payment->id)
        ->assertJsonPath('data.payment.status', 'paid')
        ->assertJsonPath('data.payment.status_label', 'Đã thu tiền')
        ->assertJsonPath('data.delivery_address.address_id', null)
        ->assertJsonPath('data.delivery_address.recipient_name', 'Mizuki Customer')
        ->assertJsonPath('data.delivery_address.full_address', '123 Test Street, Can Tho')
        ->assertJsonPath('data.shipment.tracking_code', 'GHN-ADMIN-001')
        ->assertJsonPath('data.shipment.status', 'ready_to_pick')
        ->assertJsonPath('data.items.0.image_url', Storage::disk('public')->url('qa/admin-order-item.webp'))
        ->assertJsonPath('data.items.0.brand_id', $brand->id)
        ->assertJsonPath('data.items.0.brand_name', $brand->name)
        ->assertJsonPath('data.items.0.brand_slug', $brand->slug)
        ->assertJsonPath('data.allowed_actions.0', 'shipment_label')
        ->assertJsonPath('data.allowed_actions.1', 'cancel_shipment')
        ->assertJsonPath('data.note', 'Giao trong giờ hành chính');
});

test('cancelled GHN shipment cannot generate a new print token', function (): void {
    $branch = createOrderAdminBranch('LC');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Processing);
    $order->update(['fulfillment_method' => 'shipping']);
    app(PaymentService::class)->createForOrder($order, PaymentStatus::Pending);
    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-CANCELLED-LABEL',
        'status' => 'cancelled',
        'shipping_fee' => 30_000,
        'cancelled_at' => now(),
    ]);
    $ghn = Mockery::mock(GhnClient::class);
    $ghn->shouldNotReceive('generatePrintToken');
    app()->instance(GhnClient::class, $ghn);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/label")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.status.0', 'Không thể in nhãn cho vận đơn ở trạng thái hiện tại');

    $this->getJson("/api/v1/admin/orders/{$order->id}")
        ->assertOk()
        ->assertJsonMissing(['shipment_label']);
});

test('local manual shipment flow uses webhook synchronization and records delivered COD collection', function (): void {
    config()->set('shipping.manual_transitions_enabled', true);
    $branch = createOrderAdminBranch('MS');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Processing);
    $order->update(['fulfillment_method' => 'shipping']);
    $payment = app(PaymentService::class)->createForOrder($order, PaymentStatus::Pending);
    $shipment = Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-MANUAL-DEMO',
        'status' => 'ready_to_pick',
        'shipping_fee' => 30_000,
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/picked")
        ->assertOk()
        ->assertJsonPath('data.shipment.status', 'in_transit')
        ->assertJsonPath('data.status', OrderStatus::Shipping->value);
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/delivering")
        ->assertOk()
        ->assertJsonPath('data.shipment.status', 'out_for_delivery');
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/delivered")
        ->assertOk()
        ->assertJsonPath('data.shipment.status', 'delivered')
        ->assertJsonPath('data.status', 'completed')
        ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value)
        ->assertJsonPath('data.allowed_actions', []);

    expect($shipment->refresh()->delivered_at)->not->toBeNull()
        ->and($payment->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($payment->paid_at)->not->toBeNull()
        ->and($payment->provider_response['collection_source'])->toBe('shipment_delivered');

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/delivering")
        ->assertUnprocessable();

    $this->postJson("/api/v1/admin/orders/{$order->id}/payment/cod/confirm")
        ->assertOk()
        ->assertJsonPath('data.payment_status', PaymentStatus::Paid->value);
});

test('GHN simulator applies failure and return events while rejecting terminal reversal', function (): void {
    config()->set('shipping.manual_transitions_enabled', true);
    $branch = createOrderAdminBranch('SR');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Shipping);
    $order->update(['fulfillment_method' => 'shipping']);
    Shipment::query()->create([
        'order_id' => $order->id,
        'provider' => 'ghn',
        'ghn_order_code' => 'GHN-SIM-RETURN',
        'status' => 'out_for_delivery',
        'shipping_fee' => 30_000,
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/delivery-fail")
        ->assertOk()
        ->assertJsonPath('data.shipment.raw_status', 'delivery_fail')
        ->assertJsonPath('data.shipment.logistics_stage', 'delivery_failed');
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/waiting-to-return")
        ->assertOk()
        ->assertJsonPath('data.shipment.logistics_stage', 'waiting_return');
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/returned")
        ->assertOk()
        ->assertJsonPath('data.shipment.logistics_stage', 'returned');
    $this->postJson("/api/v1/admin/orders/{$order->id}/shipment/simulate/delivering")
        ->assertUnprocessable();
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
        ->assertJsonPath('data.status', 'completed')
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
        ->assertJsonPath('data.next_action', 'wallet_payout')
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

test('admin closes an unpaid refund without creating a wallet payout', function (): void {
    $branch = createOrderAdminBranch('NP');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = createAdminManagedOrder($branch, $customer, OrderStatus::Delivered);
    $refund = createAdminManagedRefund($order, $customer);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve")
        ->assertOk()
        ->assertJsonPath('data.status', 'refunded')
        ->assertJsonPath('data.allowed_actions', [])
        ->assertJsonPath('data.next_action', null)
        ->assertJsonPath('data.wallet_transaction', null);

    expect($refund->refresh()->wallet_transaction_id)->toBeNull()
        ->and($refund->refunded_at)->not->toBeNull();
    $this->assertDatabaseCount('wallet_transactions', 0);
    $this->assertDatabaseCount('wallets', 0);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertUnprocessable()
        ->assertJsonPath(
            'data.errors.payment.0',
            'Đơn hàng chưa thanh toán nên không thể chi trả hoàn tiền vào ví',
        );
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
