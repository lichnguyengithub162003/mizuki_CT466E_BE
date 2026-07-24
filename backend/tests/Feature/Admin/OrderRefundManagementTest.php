<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Events\OrderStatusUpdated;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use App\Services\PaymentService;
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
        ['GET', '/api/v1/admin/refunds'],
        ['GET', '/api/v1/admin/refunds/1'],
        ['POST', '/api/v1/admin/refunds/1/approve'],
        ['POST', '/api/v1/admin/refunds/1/reject'],
    ];

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertUnauthorized();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Customer]));

    foreach ($paths as [$method, $path]) {
        $this->json($method, $path)->assertForbidden();
    }
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
    $requested = createAdminManagedRefund($requestedOrder, $customer);
    createAdminManagedRefund($approvedOrder, $customer, 'approved');
    $otherBranch = createOrderAdminBranch('RFB');
    $otherOrder = createAdminManagedOrder($otherBranch, $customer, OrderStatus::Delivered);
    createAdminManagedRefund($otherOrder, $customer);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->getJson('/api/v1/admin/refunds')
        ->assertOk()
        ->assertJsonCount(3, 'data');

    $this->getJson('/api/v1/admin/refunds?status=requested&keyword=refund.search')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('meta.pagination.total', 2)
        ->assertJsonFragment(['id' => $requested->id]);
});

test('admin confirms only pending orders and dispatches status event after commit', function (): void {
    Event::fake([OrderStatusUpdated::class]);
    $branch = createOrderAdminBranch();
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $pending = createAdminManagedOrder($branch, $customer);
    $confirmed = createAdminManagedOrder($branch, $customer, OrderStatus::Confirmed);
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
