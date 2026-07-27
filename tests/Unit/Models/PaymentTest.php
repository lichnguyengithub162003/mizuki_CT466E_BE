<?php

use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Appointment;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Models\WalletTransaction;

test('it casts payment method, VND amount, provider data, and timestamps', function (): void {
    $payment = new Payment([
        'method' => PaymentMethod::VNPay->value,
        'status' => PaymentStatus::Pending->value,
        'amount' => '330000',
        'provider_response' => ['transaction_no' => '123456'],
        'paid_at' => '2026-06-22 10:00:00',
    ]);

    expect($payment->method)->toBe(PaymentMethod::VNPay)
        ->and($payment->status)->toBe(PaymentStatus::Pending)
        ->and($payment->amount)->toBeInt()->toBe(330000)
        ->and($payment->provider_response)->toBe(['transaction_no' => '123456'])
        ->and($payment->paid_at)->toBeInstanceOf(DateTimeInterface::class);
});

test('it defines payment relationships', function (): void {
    $payment = new Payment();

    expect($payment->order()->getRelated())->toBeInstanceOf(Order::class)
        ->and($payment->user()->getRelated())->toBeInstanceOf(User::class)
        ->and($payment->appointment()->getRelated())->toBeInstanceOf(Appointment::class)
        ->and($payment->walletTransaction()->getRelated())->toBeInstanceOf(WalletTransaction::class)
        ->and($payment->processedBy()->getRelated())->toBeInstanceOf(User::class);
});
