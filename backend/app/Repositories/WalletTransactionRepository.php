<?php

namespace App\Repositories;

use App\Models\WalletTransaction;
use Illuminate\Pagination\LengthAwarePaginator;

/** @extends BaseRepository<WalletTransaction> */
class WalletTransactionRepository extends BaseRepository
{
    public function __construct(WalletTransaction $model)
    {
        parent::__construct($model);
    }

    /**
     * @return LengthAwarePaginator<int, WalletTransaction>
     */
    public function paginateForWallet(int $walletId, int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->where('wallet_id', $walletId)
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
