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
use App\Models\User;
use App\Models\Wallet;
use App\Models\WalletTransaction;
use App\Services\PaymentService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

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
        ->assertJsonPath('data.currency', 'VND');

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

    foreach ([1, 2, 3] as $sequence) {
        WalletTransaction::query()->create([
            'transaction_number' => "WT-HISTORY-{$sequence}",
            'wallet_id' => $context['wallet']->id,
            'type' => WalletTransactionType::WalletTopUp,
            'direction' => WalletTransactionDirection::Credit,
            'amount' => $sequence * 10_000,
            'balance_after' => $sequence * 10_000,
            'reference' => "HISTORY-{$sequence}",
        ]);
    }
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
        ->assertJsonPath('data.0.type', 'wallet_top_up')
        ->assertJsonPath('data.0.direction', 'credit')
        ->assertJsonPath('meta.pagination.current_page', 1)
        ->assertJsonPath('meta.pagination.per_page', 2)
        ->assertJsonPath('meta.pagination.total', 3)
        ->assertJsonMissing(['transaction_number' => 'WT-OTHER']);
});

test('legacy post-checkout wallet payment endpoint is removed', function (): void {
    $context = createWalletCoreContext();

    $this->actingAs($context['user'])
        ->postJson("/api/v1/customer/orders/{$context['order']->id}/payment/wallet")
        ->assertNotFound();
});
