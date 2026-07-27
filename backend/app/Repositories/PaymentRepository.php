<?php

namespace App\Repositories;

use App\Enums\OrderStatus;
use App\Enums\PaymentMethod;
use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Collection;
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

    /**
     * @return Collection<int, int>
     */
    public function expiredPendingVnPayIds(CarbonInterface $cutoff, int $limit): Collection
    {
        return $this->query()
            ->where('method', PaymentMethod::VNPay)
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->whereHas(
                'order',
                fn (Builder $query): Builder => $query->where('status', OrderStatus::Pending),
            )
            ->orderBy('id')
            ->limit($limit)
            ->pluck('id');
    }

    public function lockExpiredPendingVnPay(
        int $paymentId,
        CarbonInterface $cutoff,
    ): ?Payment {
        return $this->query()
            ->whereKey($paymentId)
            ->where('method', PaymentMethod::VNPay)
            ->where('status', PaymentStatus::Pending)
            ->where('created_at', '<=', $cutoff)
            ->lockForUpdate()
            ->first();
    }

    public function markVnPayExpired(Payment $payment): Payment
    {
        return $this->update($payment, [
            'status' => PaymentStatus::Failed,
            'provider' => 'vnpay',
            'provider_response' => [
                'source' => 'system',
                'reason' => 'payment_expired',
            ],
            'failed_at' => now(),
        ]);
    }

    public function markWalletPaid(Payment $payment, int $walletTransactionId): Payment
    {
        return $this->update($payment, [
            'status' => PaymentStatus::Paid,
            'wallet_transaction_id' => $walletTransactionId,
            'paid_at' => now(),
            'failed_at' => null,
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
