<?php

namespace App\Repositories;

use App\Models\Refund;
use Closure;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Refund> */
class RefundRepository extends BaseRepository
{
    public function __construct(Refund $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function existsForOrder(int $orderId): bool
    {
        return $this->query()->where('order_id', $orderId)->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createRefund(array $attributes): Refund
    {
        /** @var Refund $refund */
        $refund = $this->query()->create($attributes);

        return $refund->load('order:id,order_number,status,total_amount');
    }
}
