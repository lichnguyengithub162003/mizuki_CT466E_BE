<?php

namespace App\Repositories;

use App\Models\Wallet;
use Closure;
use Illuminate\Database\QueryException;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Wallet> */
class WalletRepository extends BaseRepository
{
    public function __construct(Wallet $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function findOrCreateForUser(int $userId): Wallet
    {
        $wallet = $this->query()->where('user_id', $userId)->first();

        if ($wallet !== null) {
            return $wallet;
        }

        try {
            /** @var Wallet $wallet */
            $wallet = $this->query()->create([
                'user_id' => $userId,
                'balance' => 0,
            ]);

            return $wallet;
        } catch (QueryException $exception) {
            $wallet = $this->query()->where('user_id', $userId)->first();

            if ($wallet === null) {
                throw $exception;
            }

            return $wallet;
        }
    }

    public function findOrCreateLockedForUser(int $userId): Wallet
    {
        $wallet = $this->query()
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();

        if ($wallet !== null) {
            return $wallet;
        }

        return $this->findOrCreateForUser($userId);
    }

    public function debit(Wallet $wallet, int $amount): Wallet
    {
        $wallet->balance -= $amount;
        $wallet->save();

        return $wallet->refresh();
    }
}
