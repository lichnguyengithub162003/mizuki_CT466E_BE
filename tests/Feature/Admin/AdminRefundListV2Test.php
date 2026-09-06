<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\RefundReturnStatus;
use App\Enums\UserRole;
use App\Http\Resources\Admin\RefundResource;
use App\Models\Branch;
use App\Models\BranchInventory;
use App\Models\Brand;
use App\Models\Category;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Refund;
use App\Models\User;
use App\Repositories\RefundRepository;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

function refundV2Branch(string $prefix = 'RV2'): Branch
{
    return Branch::query()->create([
        'code' => $prefix.Str::upper(Str::random(8)),
        'name' => "Branch {$prefix}",
        'phone' => '02923888888',
        'address' => 'Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
}

function refundV2Order(
    Branch $branch,
    User $customer,
    int $amount = 300_000,
    PaymentMethod $paymentMethod = PaymentMethod::Wallet,
    OrderStatus $status = OrderStatus::Delivered,
): Order {
    return Order::query()->create([
        'order_number' => 'MZ-RV2-'.Str::upper(Str::random(10)),
        'user_id' => $customer->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => $paymentMethod,
        'status' => $status,
        'subtotal' => $amount,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => $amount,
        'placed_at' => now(),
    ]);
}

function refundV2Refund(Order $order, User $customer, string $status = 'requested', int $amount = 300_000): Refund
{
    return Refund::query()->create([
        'refund_number' => 'RF-RV2-'.Str::upper(Str::random(9)),
        'order_id' => $order->id,
        'user_id' => $customer->id,
        'status' => $status,
        'requested_amount' => $amount,
        'approved_amount' => $status === 'approved' ? $amount : null,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm lỗi',
        'evidence_paths' => [],
    ]);
}

function refundV2PaidPayment(
    Order $order,
    User $customer,
    PaymentMethod $method = PaymentMethod::Wallet,
): Payment {
    return Payment::query()->create([
        'payment_number' => 'PAY-RV2-'.Str::upper(Str::random(8)),
        'order_id' => $order->id,
        'user_id' => $customer->id,
        'method' => $method,
        'status' => PaymentStatus::Paid,
        'amount' => $order->total_amount,
        'paid_at' => now(),
    ]);
}

/** @return array<string, mixed> */
function refundV2ResourcePayload(Refund $refund): array
{
    $refund->load([
        'order.branch',
        'order.payment',
        'order.items.productVariant.images',
        'order.items.productVariant.product.images',
        'user',
        'reviewedBy',
        'walletTransaction',
    ]);

    return (new RefundResource($refund))->resolve(request());
}

test('refund resource maps a legacy null return status without a required return to not required', function (?bool $returnRequired): void {
    $branch = refundV2Branch('LEGACY-NR');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = refundV2Refund(refundV2Order($branch, $customer), $customer);
    $refund->forceFill([
        'return_required' => $returnRequired,
        'return_status' => null,
        'return_inspection_status' => null,
        'origin' => null,
        'settlement_status' => null,
    ]);

    $payload = refundV2ResourcePayload($refund);

    expect($payload['return']['status'])->toBe(RefundReturnStatus::NotRequired->value)
        ->and($payload['return']['status_label'])->toBe(RefundReturnStatus::NotRequired->label())
        ->and($payload['return']['inspection_status'])->toBeNull()
        ->and($payload['return']['allowed_actions'])->toBe([])
        ->and($payload['origin']['value'])->toBe('customer_return')
        ->and($payload['settlement']['status'])->toBe('pending')
        ->and($refund->fresh()->return_status)->toBe(RefundReturnStatus::NotRequired)
        ->and($refund->fresh()->return_required)->toBeFalse();
})->with([false, null]);

test('refund resource maps a legacy null return status with a required return to awaiting return', function (): void {
    $branch = refundV2Branch('LEGACY-AR');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = refundV2Refund(refundV2Order($branch, $customer), $customer);
    $refund->forceFill([
        'return_required' => true,
        'return_status' => null,
        'return_inspection_status' => null,
        'origin' => null,
        'settlement_status' => null,
    ]);

    $payload = refundV2ResourcePayload($refund);

    expect($payload['return']['status'])->toBe(RefundReturnStatus::AwaitingReturn->value)
        ->and($payload['return']['status_label'])->toBe(RefundReturnStatus::AwaitingReturn->label())
        ->and($payload['return']['inspection_status'])->toBe('pending')
        ->and($payload['return']['allowed_actions'])->toBe(['receive_return'])
        ->and($refund->fresh()->return_status)->toBe(RefundReturnStatus::NotRequired)
        ->and($refund->fresh()->return_required)->toBeFalse();
});

test('refund resource preserves a non null return lifecycle status', function (): void {
    $branch = refundV2Branch('LIFECYCLE');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = refundV2Refund(refundV2Order($branch, $customer), $customer);
    $refund->forceFill([
        'return_required' => true,
        'return_status' => RefundReturnStatus::Restocked,
        'return_inspection_status' => 'accepted_restockable',
    ]);

    $payload = refundV2ResourcePayload($refund);

    expect($payload['return']['status'])->toBe(RefundReturnStatus::Restocked->value)
        ->and($payload['return']['status_label'])->toBe(RefundReturnStatus::Restocked->label())
        ->and($payload['return']['inspection_status'])->toBe('accepted_restockable')
        ->and($payload['return']['allowed_actions'])->toBe([]);
});

test('refund list searches customer phone and preserves branch scope', function (): void {
    $own = refundV2Branch('PHONE');
    $other = refundV2Branch('OTHER');
    $customer = User::factory()->create(['role' => UserRole::Customer, 'phone' => '0909123456']);
    $ownRefund = refundV2Refund(refundV2Order($own, $customer), $customer);
    $ownRefund->update(['return_required' => true, 'return_status' => RefundReturnStatus::AwaitingReturn]);
    $otherRefund = refundV2Refund(refundV2Order($other, $customer), $customer);
    $otherRefund->update(['return_required' => true, 'return_status' => RefundReturnStatus::AwaitingReturn]);
    $manager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $own->id]);

    $this->actingAs($manager)->getJson('/api/v1/admin/refunds?keyword=09123456')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $ownRefund->id);
    $this->getJson('/api/v1/admin/refunds?return_status=awaiting_return')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $ownRefund->id);
});

test('refund list applies whitelisted sorting and deterministic id ordering', function (string $field, string $direction): void {
    $branch = refundV2Branch('SORT');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $first = refundV2Refund(refundV2Order($branch, $customer), $customer, 'requested', 100_000);
    $second = refundV2Refund(refundV2Order($branch, $customer), $customer, 'approved', 500_000);
    $first->timestamps = false;
    $first->forceFill(['created_at' => '2026-08-01 09:00:00', 'updated_at' => '2026-08-02 09:00:00'])->save();
    $second->timestamps = false;
    $second->forceFill(['created_at' => '2026-08-10 09:00:00', 'updated_at' => '2026-08-11 09:00:00'])->save();
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $expected = $field === 'status'
        ? ($direction === 'asc' ? $second->id : $first->id)
        : ($direction === 'asc' ? $first->id : $second->id);

    $this->actingAs($admin)->getJson("/api/v1/admin/refunds?sort_by={$field}&sort_direction={$direction}&per_page=1")
        ->assertOk()->assertJsonPath('data.0.id', $expected);
})->with([
    ['created_at', 'asc'], ['created_at', 'desc'],
    ['requested_amount', 'asc'], ['requested_amount', 'desc'],
    ['status', 'asc'], ['status', 'desc'],
    ['updated_at', 'asc'], ['updated_at', 'desc'],
]);

test('refund list sorts return status in both directions while preserving branch scope and legacy null safety', function (): void {
    $own = refundV2Branch('RETURN-SORT');
    $other = refundV2Branch('RETURN-SORT-OTHER');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $firstAwaiting = refundV2Refund(refundV2Order($own, $customer), $customer);
    $secondAwaiting = refundV2Refund(refundV2Order($own, $customer), $customer);
    $restocked = refundV2Refund(refundV2Order($own, $customer), $customer);
    $crossBranch = refundV2Refund(refundV2Order($other, $customer), $customer);

    $firstAwaiting->update(['return_required' => true, 'return_status' => RefundReturnStatus::AwaitingReturn]);
    $secondAwaiting->update(['return_required' => true, 'return_status' => RefundReturnStatus::AwaitingReturn]);
    $restocked->update(['return_required' => true, 'return_status' => RefundReturnStatus::Restocked]);
    $crossBranch->update(['return_required' => true, 'return_status' => RefundReturnStatus::Received]);

    $manager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $own->id]);
    $this->actingAs($manager)
        ->getJson('/api/v1/admin/refunds?sort_by=return_status&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('data.0.id', $firstAwaiting->id)
        ->assertJsonPath('data.1.id', $secondAwaiting->id)
        ->assertJsonPath('data.2.id', $restocked->id);

    $this->getJson('/api/v1/admin/refunds?sort_by=return_status&sort_direction=desc')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonPath('data.0.id', $restocked->id)
        ->assertJsonPath('data.1.id', $secondAwaiting->id)
        ->assertJsonPath('data.2.id', $firstAwaiting->id);

    Schema::table('refunds', fn (Blueprint $table) => $table->string('return_status')->nullable()->change());
    $legacy = refundV2Refund(refundV2Order($own, $customer), $customer);
    $legacy->forceFill(['return_required' => false, 'return_status' => null])->save();

    $this->getJson('/api/v1/admin/refunds?sort_by=return_status&sort_direction=asc')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 4);
});

test('refund list validates and composes settlement and inclusive date filters', function (): void {
    $branch = refundV2Branch('FILTER');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $pending = refundV2Refund(refundV2Order($branch, $customer), $customer);
    $wallet = refundV2Refund(refundV2Order($branch, $customer), $customer, 'refunded');
    $wallet->update(['settlement_method' => 'wallet', 'refunded_at' => now()]);
    foreach ([[$pending, '2026-08-10 00:00:00'], [$wallet, '2026-08-11 23:59:59']] as [$refund, $date]) {
        $refund->timestamps = false;
        $refund->forceFill(['created_at' => $date, 'updated_at' => $date])->save();
    }
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)->getJson('/api/v1/admin/refunds?destination=pending&date_from=2026-08-10&date_to=2026-08-10')
        ->assertOk()->assertJsonPath('meta.pagination.total', 1)->assertJsonPath('data.0.id', $pending->id);
    $this->getJson('/api/v1/admin/refunds?settlement_method=wallet&date_from=2026-08-11&date_to=2026-08-11')
        ->assertOk()->assertJsonPath('data.0.id', $wallet->id);
    $this->getJson('/api/v1/admin/refunds?sort_by=raw_sql')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?sort_direction=sideways')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?settlement_method=cash')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?return_status=unknown')->assertUnprocessable();
    $this->getJson('/api/v1/admin/refunds?date_from=2026-08-12&date_to=2026-08-11')->assertUnprocessable();
});

test('refund list filters primary settlement sources while preserving legacy aliases and branch scope', function (): void {
    $own = refundV2Branch('SET-FILTER');
    $other = refundV2Branch('SET-OTHER');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refunds = [];

    foreach (['wallet', 'vnpay', 'momo', 'zalopay', 'manual_external', 'no_payout'] as $method) {
        $refund = refundV2Refund(refundV2Order($own, $customer), $customer, 'refunded');
        $refund->forceFill(['settlement_method' => $method, 'settlement_status' => 'succeeded'])->save();
        $refunds[$method] = $refund;
    }

    $pending = refundV2Refund(refundV2Order($own, $customer), $customer, 'approved');
    $pending->forceFill(['settlement_method' => null, 'settlement_status' => 'pending'])->save();
    $crossBranch = refundV2Refund(refundV2Order($other, $customer), $customer, 'refunded');
    $crossBranch->forceFill(['settlement_method' => 'vnpay', 'settlement_status' => 'succeeded'])->save();
    $manager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $own->id]);

    $this->actingAs($manager);

    foreach (['wallet', 'vnpay', 'momo', 'zalopay'] as $method) {
        $this->getJson("/api/v1/admin/refunds?settlement_method={$method}")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $refunds[$method]->id);
    }

    foreach (['manual_external', 'no_payout'] as $legacyMethod) {
        $this->getJson("/api/v1/admin/refunds?settlement_method={$legacyMethod}")
            ->assertOk()
            ->assertJsonPath('meta.pagination.total', 1)
            ->assertJsonPath('data.0.id', $refunds[$legacyMethod]->id);
    }

    $this->getJson('/api/v1/admin/refunds?settlement_method=pending')
        ->assertOk()
        ->assertJsonPath('meta.pagination.total', 1)
        ->assertJsonPath('data.0.id', $pending->id);
    $this->getJson('/api/v1/admin/refunds?settlement_method=bank_transfer')->assertUnprocessable();
});

test('refund work queue counts only records requiring an admin action and is branch scoped', function (): void {
    $own = refundV2Branch('COUNT');
    $other = refundV2Branch('COUNTO');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    refundV2Refund(refundV2Order($own, $customer), $customer, 'requested');
    $payableOrder = refundV2Order($own, $customer);
    refundV2PaidPayment($payableOrder, $customer);
    refundV2Refund($payableOrder, $customer, 'approved');
    refundV2Refund(refundV2Order($own, $customer), $customer, 'approved');
    refundV2Refund(refundV2Order($own, $customer), $customer, 'rejected');
    $settled = refundV2Refund(refundV2Order($own, $customer), $customer, 'refunded');
    $settled->update(['settlement_method' => 'no_payout', 'refunded_at' => now()]);
    refundV2Refund(refundV2Order($other, $customer), $customer, 'requested');

    $manager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $own->id]);
    $this->actingAs($manager)->getJson('/api/v1/admin/refunds/counts')
        ->assertOk()->assertJsonPath('data.requested', 1)
        ->assertJsonPath('data.approved_pending_payout', 1)
        ->assertJsonPath('data.pending_action', 2);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->getJson('/api/v1/admin/refunds/counts')
        ->assertOk()->assertJsonPath('data.requested', 2)
        ->assertJsonPath('data.pending_action', 3);
});

test('refund return lifecycle is financially independent and restocks full order exactly once', function (): void {
    $branch = refundV2Branch('RETURN');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);
    $order = refundV2Order($branch, $customer, 200_000);
    refundV2PaidPayment($order, $customer);
    $category = Category::query()->create(['name' => 'Return', 'slug' => 'return-'.Str::random(6)]);
    $brand = Brand::query()->create(['name' => 'Return', 'slug' => 'return-brand-'.Str::random(6)]);
    $product = Product::query()->create(['category_id' => $category->id, 'brand_id' => $brand->id, 'name' => 'Return item', 'slug' => 'return-item-'.Str::random(6)]);
    $variant = ProductVariant::query()->create(['product_id' => $product->id, 'name' => 'Default', 'sku' => 'RV2-'.Str::random(8), 'price' => 100_000, 'weight' => 100]);
    $order->items()->create(['product_variant_id' => $variant->id, 'product_name' => $product->name, 'variant_name' => $variant->name, 'sku' => $variant->sku, 'unit_price' => 100_000, 'quantity' => 2, 'line_total' => 200_000]);
    $inventory = BranchInventory::query()->create(['branch_id' => $branch->id, 'product_variant_id' => $variant->id, 'quantity' => 5, 'reserved_quantity' => 0]);
    $refund = refundV2Refund($order, $customer, 'requested', 200_000);
    $this->actingAs($admin);

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/approve", ['return_required' => true])
        ->assertOk()
        ->assertJsonPath('data.status', 'approved')
        ->assertJsonPath('data.next_action', null)
        ->assertJsonPath('data.return.status', RefundReturnStatus::AwaitingReturn->value)
        ->assertJsonPath('data.return.inspection_status', 'pending')
        ->assertJsonPath('data.settlement.status', 'pending');
    expect($inventory->refresh()->quantity)->toBe(5)
        ->and($refund->refresh()->status)->toBe('approved');

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.return_inspection_status.0', 'Phải hoàn tất kiểm tra hàng hoàn trước khi hoàn tiền');

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/return/restock")
        ->assertUnprocessable();
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/return/receive")
        ->assertOk()->assertJsonPath('data.return.status', RefundReturnStatus::Received->value)
        ->assertJsonPath('data.return.allowed_actions.0', 'restock');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/return/restock")
        ->assertOk()
        ->assertJsonPath('data.return.status', RefundReturnStatus::Restocked->value)
        ->assertJsonPath('data.return.inspection_status', 'accepted_restockable')
        ->assertJsonPath('data.next_action', 'wallet_payout');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/return/restock")->assertOk();

    expect($inventory->refresh()->quantity)->toBe(7)
        ->and(InventoryTransaction::query()->where('reference_type', Refund::class)->where('reference_id', $refund->id)->count())->toBe(1)
        ->and($refund->refresh()->return_received_at)->not->toBeNull()
        ->and($refund->restocked_at)->not->toBeNull();
});

test('return operations reject wrong branches and non returnable refunds', function (): void {
    $own = refundV2Branch('R-OWN');
    $other = refundV2Branch('R-OTHER');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $refund = refundV2Refund(refundV2Order($other, $customer), $customer, 'approved');
    $refund->update(['return_required' => true, 'return_status' => RefundReturnStatus::Received]);
    $manager = User::factory()->create(['role' => UserRole::BranchManager, 'branch_id' => $own->id]);

    $this->actingAs($manager)->postJson("/api/v1/admin/refunds/{$refund->id}/return/restock")->assertNotFound();

    $plain = refundV2Refund(refundV2Order($own, $customer), $customer, 'approved');
    $this->postJson("/api/v1/admin/refunds/{$plain->id}/return/receive")->assertUnprocessable();
});

test('received returns can be finalized as not restockable without changing inventory', function (): void {
    $branch = refundV2Branch('NO-STOCK');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = refundV2Order($branch, $customer);
    refundV2PaidPayment($order, $customer);
    $refund = refundV2Refund($order, $customer, 'approved');
    $refund->update([
        'return_required' => true,
        'return_status' => RefundReturnStatus::Received,
        'return_received_at' => now(),
    ]);
    $admin = User::factory()->create(['role' => UserRole::SuperAdmin]);

    $this->actingAs($admin)
        ->postJson("/api/v1/admin/refunds/{$refund->id}/return/not-restockable")
        ->assertOk()
        ->assertJsonPath('data.return.status', RefundReturnStatus::NotRestockable->value)
        ->assertJsonPath('data.return.inspection_status', 'accepted_not_restockable')
        ->assertJsonPath('data.next_action', 'wallet_payout')
        ->assertJsonPath('data.return.allowed_actions', []);

    expect(InventoryTransaction::query()->count())->toBe(0)
        ->and($refund->refresh()->restocked_at)->toBeNull();
});

test('rejected return inspection is distinct from not restockable and blocks settlement', function (): void {
    $branch = refundV2Branch('REJECT-INSP');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = refundV2Order($branch, $customer);
    refundV2PaidPayment($order, $customer);
    $refund = refundV2Refund($order, $customer, 'approved');
    $refund->update([
        'return_required' => true,
        'return_status' => RefundReturnStatus::Received,
        'return_inspection_status' => 'pending',
        'return_received_at' => now(),
    ]);
    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]));

    $this->postJson("/api/v1/admin/refunds/{$refund->id}/return/reject-inspection")
        ->assertOk()
        ->assertJsonPath('data.status', 'rejected')
        ->assertJsonPath('data.return.status', RefundReturnStatus::Received->value)
        ->assertJsonPath('data.return.inspection_status', 'rejected')
        ->assertJsonPath('data.return.allowed_actions', [])
        ->assertJsonPath('data.settlement.status', null);

    expect($refund->refresh()->return_status)->toBe(RefundReturnStatus::Received)
        ->and($refund->return_inspection_status)->toBe('rejected');
    $this->postJson("/api/v1/admin/refunds/{$refund->id}/wallet-payout")->assertUnprocessable();
});

test('refund origin exposes cancellation rows already created through the refund repository', function (): void {
    $branch = refundV2Branch('CANCEL');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = refundV2Order($branch, $customer, status: OrderStatus::Cancelled);
    $refund = app(RefundRepository::class)->createRefund([
        'refund_number' => 'RF-CANCEL-'.Str::upper(Str::random(8)),
        'order_id' => $order->id,
        'user_id' => $customer->id,
        'status' => 'approved',
        'requested_amount' => $order->total_amount,
        'approved_amount' => $order->total_amount,
        'reason_type' => 'order_cancellation',
        'reason' => 'Hoàn tiền đơn đã hủy',
        'evidence_paths' => [],
    ]);

    $this->actingAs(User::factory()->create(['role' => UserRole::SuperAdmin]))
        ->getJson('/api/v1/admin/refunds')
        ->assertOk()
        ->assertJsonPath('data.0.id', $refund->id)
        ->assertJsonPath('data.0.origin.value', 'order_cancellation');

    expect($refund->refresh()->origin)->toBe('order_cancellation');
});

test('settlement contract derives destination from the authoritative payment method', function (
    PaymentMethod $paymentMethod,
    string $method,
    string $destination,
): void {
    $branch = refundV2Branch('SETTLE');
    $customer = User::factory()->create(['role' => UserRole::Customer]);
    $order = refundV2Order($branch, $customer, paymentMethod: $paymentMethod);
    refundV2PaidPayment($order, $customer, $paymentMethod);
    $refund = refundV2Refund($order, $customer, 'approved');

    $payload = refundV2ResourcePayload($refund);

    expect($payload['settlement']['method'])->toBe($method)
        ->and($payload['settlement']['destination'])->toBe($destination)
        ->and($payload['settlement']['status'])->toBe('pending');
})->with([
    'cash to Mizuki wallet' => [PaymentMethod::Cash, 'wallet', 'wallet'],
    'wallet to Mizuki wallet' => [PaymentMethod::Wallet, 'wallet', 'wallet'],
    'VNPAY to original transaction' => [PaymentMethod::VNPay, 'vnpay', 'vnpay_original'],
]);
