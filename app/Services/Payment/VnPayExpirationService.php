<?php

namespace App\Services\Payment;

use App\Enums\OrderStatus;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use App\Services\BaseService;
use Carbon\CarbonImmutable;
use Throwable;

class VnPayExpirationService extends BaseService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
    ) {}

    /**
     * @return array{processed: int, skipped: int, failed: int}
     */
    public function processExpired(int $batchSize): array
    {
        $cutoff = CarbonImmutable::now()->subMinutes(
            (int) config('vnpay.expire_minutes', 15),
        );
        $paymentIds = $this->payments->expiredPendingVnPayIds($cutoff, $batchSize);
        $result = ['processed' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($paymentIds as $paymentId) {
            try {
                $processed = $this->payments->transaction(
                    fn (): bool => $this->expireOne($paymentId, $cutoff),
                );
                $result[$processed ? 'processed' : 'skipped']++;
            } catch (Throwable) {
                // One inconsistent order must not prevent other expired orders from processing.
                $result['failed']++;
            }
        }

        return $result;
    }

    private function expireOne(int $paymentId, CarbonImmutable $cutoff): bool
    {
        // Payment is locked first to serialize this flow with VNPay IPN.
        $payment = $this->payments->lockExpiredPendingVnPay($paymentId, $cutoff);

        if ($payment === null || $payment->order_id === null) {
            return false;
        }

        $order = $this->payments->lockOrder($payment->order_id);

        if ($order === null || $order->status !== OrderStatus::Pending) {
            return false;
        }

        $this->orders->releaseReservedInventory($order);
        $this->payments->markVnPayExpired($payment);
        $this->orders->markCancelled(
            $order,
            'payment_expired',
            'Thanh toán VNPay đã hết hạn',
            'system',
            null,
        );

        return true;
    }
}
