<?php

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\Cart;
use App\Models\InventoryTransaction;
use App\Models\Order;
use App\Models\Payment;
use App\Models\PromotionUsage;
use App\Models\Refund;
use App\Models\Review;
use App\Models\SocialAccount;
use App\Models\User;
use App\Models\UserAddress;
use App\Models\Wallet;
use App\Models\WalletTransaction;

test('it casts its role to the user role enum', function (): void {
    $user = new User([
        'name' => 'Mizuki Cashier',
        'email' => 'cashier@example.com',
        'password' => 'password',
        'role' => UserRole::Cashier->value,
    ]);

    expect($user->role)->toBe(UserRole::Cashier);
});

test('it permits internal role and branch assignment while hiding sensitive attributes', function (): void {
    $user = new User();

    expect($user->isFillable('role'))->toBeTrue()
        ->and($user->isFillable('branch_id'))->toBeTrue()
        ->and($user->getHidden())->toContain('password', 'remember_token');
});

test('it defines customer, staff, and operational relationships', function (): void {
    $user = new User();

    expect($user->branch()->getRelated())->toBeInstanceOf(Branch::class)
        ->and($user->addresses()->getRelated())->toBeInstanceOf(UserAddress::class)
        ->and($user->wallet()->getRelated())->toBeInstanceOf(Wallet::class)
        ->and($user->socialAccounts()->getRelated())->toBeInstanceOf(SocialAccount::class)
        ->and($user->cart()->getRelated())->toBeInstanceOf(Cart::class)
        ->and($user->orders()->getRelated())->toBeInstanceOf(Order::class)
        ->and($user->createdOrders()->getRelated())->toBeInstanceOf(Order::class)
        ->and($user->walletTransactions()->getRelated())->toBeInstanceOf(WalletTransaction::class)
        ->and($user->processedPayments()->getRelated())->toBeInstanceOf(Payment::class)
        ->and($user->promotionUsages()->getRelated())->toBeInstanceOf(PromotionUsage::class)
        ->and($user->appointments()->getRelated())->toBeInstanceOf(Appointment::class)
        ->and($user->technicianAppointments()->getRelated())->toBeInstanceOf(Appointment::class)
        ->and($user->refunds()->getRelated())->toBeInstanceOf(Refund::class)
        ->and($user->reviewedRefunds()->getRelated())->toBeInstanceOf(Refund::class)
        ->and($user->inventoryTransactions()->getRelated())->toBeInstanceOf(InventoryTransaction::class)
        ->and($user->reviews()->getRelated())->toBeInstanceOf(Review::class)
        ->and($user->moderatedReviews()->getRelated())->toBeInstanceOf(Review::class);
});
