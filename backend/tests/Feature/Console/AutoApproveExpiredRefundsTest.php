<?php

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\UserRole;
use App\Models\Branch;
use App\Models\Order;
use App\Models\Refund;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;

uses(RefreshDatabase::class);

afterEach(function (): void {
    Carbon::setTestNow();
});

/** @return array{order: Order, refund: Refund, user: User} */
function createAutoApprovalRefund(Carbon $createdAt, string $status = 'requested'): array
{
    $token = Str::upper(Str::random(8));
    $branch = Branch::query()->create([
        'code' => 'AR'.$token,
        'name' => 'Mizuki Auto Refund '.$token,
        'phone' => '02923888888',
        'address' => 'Ninh Kiều, Cần Thơ',
        'province_code' => 'CT',
        'ghn_district_id' => 1442,
        'ghn_ward_code' => '21012',
        'is_active' => true,
    ]);
    $user = User::factory()->create(['role' => UserRole::Customer]);
    $order = Order::query()->create([
        'order_number' => 'MZ-AR-'.Str::upper(Str::random(10)),
        'user_id' => $user->id,
        'branch_id' => $branch->id,
        'channel' => 'online',
        'fulfillment_method' => 'pickup',
        'payment_method' => PaymentMethod::Cash,
        'status' => OrderStatus::Delivered,
        'subtotal' => 300_000,
        'discount_amount' => 0,
        'shipping_fee' => 0,
        'total_amount' => 300_000,
        'placed_at' => $createdAt->copy()->subDay(),
    ]);
    $refund = Refund::query()->create([
        'refund_number' => 'RF-AR-'.Str::upper(Str::random(10)),
        'order_id' => $order->id,
        'user_id' => $user->id,
        'status' => $status,
        'requested_amount' => 300_000,
        'reason_type' => 'product_damaged',
        'reason' => 'Sản phẩm bị hư hỏng',
        'evidence_paths' => ['refund-evidence/proof.jpg'],
    ]);
    $refund->forceFill([
        'created_at' => $createdAt,
        'updated_at' => $createdAt,
    ])->saveQuietly();

    return compact('order', 'refund', 'user');
}

test('refund before configured timeout is not auto approved', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 48);
    $context = createAutoApprovalRefund($now->copy()->subHours(47));

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 0')
        ->expectsOutput('Bỏ qua: 0')
        ->expectsOutput('Thất bại: 0')
        ->assertSuccessful();

    expect($context['refund']->refresh()->status)->toBe('requested')
        ->and($context['refund']->approved_amount)->toBeNull()
        ->and($context['refund']->reviewed_at)->toBeNull();
});

test('refund at or beyond timeout is fully auto approved', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 48);
    $atBoundary = createAutoApprovalRefund($now->copy()->subHours(48));
    $expired = createAutoApprovalRefund($now->copy()->subHours(72));

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 2')
        ->expectsOutput('Bỏ qua: 0')
        ->expectsOutput('Thất bại: 0')
        ->assertSuccessful();

    foreach ([$atBoundary['refund'], $expired['refund']] as $refund) {
        $refund->refresh();

        expect($refund->status)->toBe('approved')
            ->and($refund->approved_amount)->toBe($refund->requested_amount)
            ->and($refund->reviewed_at?->equalTo($now))->toBeTrue()
            ->and($refund->reviewed_by_user_id)->toBeNull()
            ->and($refund->review_note)->toBe('Tự động duyệt do quá hạn phản hồi');
    }
});

test('approved and rejected refunds are never processed again', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 48);
    $approved = createAutoApprovalRefund($now->copy()->subHours(72), 'approved');
    $rejected = createAutoApprovalRefund($now->copy()->subHours(72), 'rejected');

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 0')
        ->assertSuccessful();

    expect($approved['refund']->refresh()->status)->toBe('approved')
        ->and($approved['refund']->review_note)->toBeNull()
        ->and($rejected['refund']->refresh()->status)->toBe('rejected')
        ->and($rejected['refund']->review_note)->toBeNull();
});

test('command is idempotent when run twice', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 48);
    $context = createAutoApprovalRefund($now->copy()->subHours(72));

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 1')
        ->assertSuccessful();

    $firstReviewedAt = $context['refund']->refresh()->reviewed_at?->toISOString();

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 0')
        ->assertSuccessful();

    expect($context['refund']->refresh()->reviewed_at?->toISOString())->toBe($firstReviewedAt);
});

test('auto approval preserves order wallet and wallet transactions', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 48);
    $context = createAutoApprovalRefund($now->copy()->subHours(72));
    $wallet = $context['user']->wallet()->create(['balance' => 500_000]);

    $this->artisan('refunds:auto-approve')->assertSuccessful();

    expect($context['order']->refresh()->status)->toBe(OrderStatus::Delivered)
        ->and($wallet->refresh()->balance)->toBe(500_000)
        ->and($context['refund']->refresh()->wallet_transaction_id)->toBeNull();
    $this->assertDatabaseCount('wallet_transactions', 0);
});

test('configured timeout is used instead of the default value', function (): void {
    $now = Carbon::parse('2026-07-24 12:00:00');
    Carbon::setTestNow($now);
    config()->set('refund.auto_approve_hours', 24);
    $expired = createAutoApprovalRefund($now->copy()->subHours(25));
    $fresh = createAutoApprovalRefund($now->copy()->subHours(23));

    $this->artisan('refunds:auto-approve')
        ->expectsOutput('Đã tự động duyệt: 1')
        ->assertSuccessful();

    expect($expired['refund']->refresh()->status)->toBe('approved')
        ->and($fresh['refund']->refresh()->status)->toBe('requested');
});
