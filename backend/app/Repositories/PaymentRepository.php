<?php

namespace App\Repositories;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Closure;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Payment> */
class PaymentRepository extends BaseRepository
{
    public function __construct(
        Payment $model,
        private readonly Order $orders,
    ) {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function lockOrder(int $orderId): ?Order
    {
        return $this->orders->newQuery()
            ->whereKey($orderId)
            ->lockForUpdate()
            ->first();
    }

    public function findForOrder(int $orderId): ?Payment
    {
        return $this->query()
            ->where('order_id', $orderId)
            ->first();
    }

    public function findByPaymentNumber(string $paymentNumber): ?Payment
    {
        return $this->query()
            ->with('order')
            ->where('payment_number', $paymentNumber)
            ->first();
    }

    public function lockByPaymentNumber(string $paymentNumber): ?Payment
    {
        $payment = $this->query()
            ->where('payment_number', $paymentNumber)
            ->lockForUpdate()
            ->first();

        $payment?->load('order');

        return $payment;
    }

    public function transactionReferenceInUse(string $reference, int $exceptPaymentId): bool
    {
        return $this->query()
            ->where('transaction_reference', $reference)
            ->whereKeyNot($exceptPaymentId)
            ->exists();
    }

    /** @param array<string, string> $providerResponse */
    public function markVnPayPaid(
        Payment $payment,
        string $transactionReference,
        array $providerResponse,
    ): Payment {
        return $this->update($payment, [
            'status' => PaymentStatus::Paid,
            'provider' => 'vnpay',
            'transaction_reference' => $transactionReference,
            'provider_response' => $providerResponse,
            'paid_at' => now(),
            'failed_at' => null,
        ]);
    }

    /** @param array<string, string> $providerResponse */
    public function markVnPayFailed(Payment $payment, array $providerResponse): Payment
    {
        return $this->update($payment, [
            'status' => PaymentStatus::Failed,
            'provider' => 'vnpay',
            'provider_response' => $providerResponse,
            'failed_at' => now(),
        ]);
    }

    /** @param array<string, mixed> $attributes */
    public function createPayment(array $attributes): Payment
    {
        /** @var Payment $payment */
        $payment = $this->query()->create($attributes);

        return $payment;
    }
}
