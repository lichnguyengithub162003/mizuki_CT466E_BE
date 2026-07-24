<?php

namespace App\Repositories;

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

    /** @param array<string, mixed> $attributes */
    public function createPayment(array $attributes): Payment
    {
        /** @var Payment $payment */
        $payment = $this->query()->create($attributes);

        return $payment;
    }
}
