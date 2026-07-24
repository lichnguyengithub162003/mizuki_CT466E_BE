<?php

namespace App\Services;

use App\Enums\PaymentStatus;
use App\Models\Order;
use App\Models\Payment;
use App\Models\User;
use App\Repositories\OrderRepository;
use App\Repositories\PaymentRepository;
use Illuminate\Database\Eloquent\ModelNotFoundException;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;

class PaymentService extends BaseService
{
    public function __construct(
        private readonly PaymentRepository $payments,
        private readonly OrderRepository $orders,
    ) {
    }

    public function createForOrder(
        Order $order,
        PaymentStatus $status = PaymentStatus::Pending,
        ?int $processedByUserId = null,
    ): Payment {
        return $this->payments->transaction(function () use (
            $order,
            $status,
            $processedByUserId,
        ): Payment {
            $lockedOrder = $this->payments->lockOrder($order->id);

            if ($lockedOrder === null) {
                throw (new ModelNotFoundException())->setModel(Order::class, [$order->id]);
            }

            $existing = $this->payments->findForOrder($lockedOrder->id);

            if ($existing !== null) {
                return $existing;
            }

            return $this->payments->createPayment([
                'payment_number' => $this->generatePaymentNumber(),
                'order_id' => $lockedOrder->id,
                'user_id' => $lockedOrder->user_id,
                'processed_by_user_id' => $processedByUserId,
                'method' => $lockedOrder->payment_method,
                'status' => $status,
                'amount' => $lockedOrder->total_amount,
                'paid_at' => $status === PaymentStatus::Paid ? now() : null,
            ]);
        });
    }

    public function forCustomer(User $user, int $orderId): ?Payment
    {
        $order = $this->orders->findForUser($orderId, $user->id);

        if ($order === null) {
            return null;
        }

        Gate::forUser($user)->authorize('view', $order);

        return $this->payments->findForOrder($order->id);
    }

    private function generatePaymentNumber(): string
    {
        return 'PAY-'.now()->format('YmdHis').'-'.Str::upper(Str::random(8));
    }
}
