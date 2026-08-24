<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Enums\UserRole;
use App\Enums\WalletTransactionDirection;
use App\Enums\WalletTransactionType;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Payment;
use App\Models\Refund;
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use App\Services\WalletService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

uses(RefreshDatabase::class);

/**
 * @return array{user: User, order: Order, payment: Payment, wallet: Wallet}
 */
function createWalletCoreContext(
    PaymentMethod $method = PaymentMethod::Wallet,
    PaymentStatus $paymentStatus = PaymentStatus::Pending,
    OrderStatus $orderStatus = OrderStatus::Pending,
    int $amount = 200_000,
    int $balance = 500_000,
): array {
    $token = Str::upper(Str::random(8));
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $branch = Branch::query()->create([
        'code' => 'WL'.$token,
        'name' => 'Mizuki Wallet '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $order = Order::query()->create([
        'order_number' => 'MZ-WL-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => $method,
        'status' => $orderStatus,
        'subtotal' => $amount,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => $amount,
        'placed_at' => now(),
    ]);
    $payment = app(PaymentService::class)->createForOrder($order, $paymentStatus);
    $wallet = Wallet::query()->create([
        'user_id' => $user->id,
        'balance' => $balance,
    ]);

    return compact('user', 'order', 'payment', 'wallet');
}

test('customer sees balance and a missing wallet is lazily created only once', function (): void {
    $user = User::factory()->create(['role' => UserRole::Customer]);

    $this->actingAs($user)
        ->getJson('/api/v1/customer/wallet')
        ->assertOk()
        ->assertJsonPath('success', true)
        ->assertJsonPath('data.balance', 0)
        ->assertJsonPath('data.currency', 'VND')
        ->assertJsonPath('data.updated_at', fn (mixed $value): bool => is_string($value));

    $this->getJson('/api/v1/customer/wallet')
        ->assertOk()
        ->assertJsonPath('data.balance', 0);

    $this->assertDatabaseCount('wallets', 1);
    $this->assertDatabaseHas('wallets', ['user_id' => $user->id, 'balance' => 0]);
});

test('guest and internal staff cannot access any customer wallet endpoint', function (): void {
    $routes = [
        ['GET', '/api/v1/customer/wallet'],
        ['GET', '/api/v1/customer/wallet/transactions'],
    ];

    foreach ($routes as [$method, $route]) {
        $this->json($method, $route)->assertUnauthorized();
    }

    $this->actingAs(User::factory()->create(['role' => UserRole::Cashier]));

    foreach ($routes as [$method, $route]) {
        $this->json($method, $route)->assertForbidden();
    }
});

test('wallet transaction history is paginated newest first and scoped to its customer', function (): void {
    $context = createWalletCoreContext();
    $other = createWalletCoreContext();

    $transactions = [];

    foreach ([1, 2, 3] as $sequence) {
        $transactions[$sequence] = WalletTransaction::query()->create([
            'transaction_number' => "WT-HISTORY-{$sequence}",
            'wallet_id' => $context['wallet']->id,
            'order_id' => $sequence === 1 ? null : $context['order']->id,
            'type' => match ($sequence) {
                2 => WalletTransactionType::OrderPayment,
                3 => WalletTransactionType::Refund,
                default => WalletTransactionType::WalletTopUp,
            },
            'direction' => $sequence === 2
                ? WalletTransactionDirection::Debit
                : WalletTransactionDirection::Credit,
            'amount' => $sequence * 10_000,
            'balance_after' => $sequence * 10_000,
            'reference' => "HISTORY-{$sequence}",
        ]);
    }
    $refund = Refund::query()->create([
        'refund_number' => 'RF-WALLET-HISTORY',
        'order_id' => $context['order']->id,
        'user_id' => $context['user']->id,
        'wallet_transaction_id' => $transactions[3]->id,
        'status' => 'refunded',
        'requested_amount' => 30_000,
        'approved_amount' => 30_000,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm bị hư hỏng',
        'evidence_paths' => ['refund-evidence/proof.jpg'],
        'reviewed_at' => now(),
        'refunded_at' => now(),
    ]);
    WalletTransaction::query()->create([
        'transaction_number' => 'WT-OTHER',
        'wallet_id' => $other['wallet']->id,
        'type' => WalletTransactionType::Refund,
        'direction' => WalletTransactionDirection::Credit,
        'amount' => 99_000,
        'balance_after' => 99_000,
    ]);

    $this->actingAs($context['user'])
        ->getJson('/api/v1/customer/wallet/transactions?per_page=2')
        ->assertOk()
        ->assertJsonCount(2, 'data')
        ->assertJsonPath('data.0.transaction_number', 'WT-HISTORY-3')
        ->assertJsonPath('data.0.id', $transactions[3]->id)
        ->assertJsonPath('data.0.type', 'refund')
        ->assertJsonPath('data.0.direction', 'credit')
        ->assertJsonPath('data.0.currency', 'VND')
        ->assertJsonPath('data.0.order.id', $context['order']->id)
        ->assertJsonPath('data.0.order.order_number', $context['order']->order_number)
        ->assertJsonPath('data.0.refund.id', $refund->id)
        ->assertJsonPath('data.0.refund.refund_number', 'RF-WALLET-HISTORY')
        ->assertJsonPath('data.0.refund.status', 'refunded')
        ->assertJsonPath('data.0.refund.status_label', 'Đã hoàn tiền')
        ->assertJsonPath('meta.wallet.id', $context['wallet']->id)
        ->assertJsonPath('meta.wallet.balance', 500_000)
        ->assertJsonPath('meta.wallet.currency', 'VND')
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonMissing(['transaction_number' => 'WT-OTHER']);

    $this->getJson('/api/v1/customer/wallet/transactions?type=refund&direction=credit')
        ->assertOk()
        ->assertJsonCount(1, 'data')
        ->assertJsonPath('data.0.id', $transactions[3]->id)
        ->assertJsonPath('meta.pagination.total', 1);
});

test('wallet transaction filters reject unsupported contract values', function (): void {
    $context = createWalletCoreContext();
    $this->actingAs($context['user']);

    $this->getJson('/api/v1/customer/wallet/transactions?type=unknown')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.type.0', 'Loại giao dịch ví không hợp lệ');
    $this->getJson('/api/v1/customer/wallet/transactions?direction=unknown')
        ->assertUnprocessable()
        ->assertJsonPath('data.errors.direction.0', 'Chiều giao dịch ví không hợp lệ');
    $this->getJson('/api/v1/customer/wallet/transactions?page=0')->assertUnprocessable();
    $this->getJson('/api/v1/customer/wallet/transactions?per_page=101')->assertUnprocessable();
});

test('legacy post-checkout wallet payment endpoint is removed', function (): void {
    $context = createWalletCoreContext();

    $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/wallet")
        ->assertNotFound();
});

test('wallet payment cannot debit twice and always links a paid payment to its ledger', function (): void {
    $context = createWalletCoreContext(balance: 500_000);
    $service = app(WalletService::class);

    $transaction = $service->completeCheckoutPayment(
        $context['user'],
        $context['order'],
        $context['payment'],
        $context['wallet'],
    );

    expect(fn () => $service->completeCheckoutPayment(
        $context['user'],
        $context['order'],
        $context['payment'],
        $context['wallet'],
    ))->toThrow(ValidationException::class);

    expect($context['wallet']->refresh()->balance)->toBe(300_000)
        ->and($context['payment']->refresh()->status)->toBe(PaymentStatus::Paid)
        ->and($context['payment']->paid_at)->not->toBeNull()
        ->and($context['payment']->failed_at)->toBeNull()
        ->and($context['payment']->refunded_at)->toBeNull()
        ->and($context['payment']->wallet_transaction_id)->toBe($transaction->id)
        ->and(WalletTransaction::query()
            ->where('order_id', $context['order']->id)
            ->count())->toBe(1);
});
