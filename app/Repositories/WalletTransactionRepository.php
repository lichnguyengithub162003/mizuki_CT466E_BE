<?php

namespace App\Repositories;

use App\Models\WalletTransaction;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;

/** @extends BaseRepository<WalletTransaction> */
class WalletTransactionRepository extends BaseRepository
{
    public function __construct(WalletTransaction $model)
    {
        parent::__construct($model);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, WalletTransaction>
     */
    public function paginateForWallet(
        int $walletId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->query()
            ->where('wallet_id', $walletId)
            ->when(
                isset($filters['type']),
                fn (Builder $query): Builder => $query->where('type', $filters['type']),
            )
            ->when(
                isset($filters['direction']),
                fn (Builder $query): Builder => $query->where('direction', $filters['direction']),
            )
            ->with([
                'order:id,order_number',
                'refund:id,refund_number,wallet_transaction_id,status,requested_amount,approved_amount,refunded_at',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    /** @param array<string, mixed> $attributes */
    public function createTransaction(array $attributes): WalletTransaction
    {
        /** @var WalletTransaction $transaction */
        $transaction = $this->query()->create($attributes);

        return $transaction;
    }
}
