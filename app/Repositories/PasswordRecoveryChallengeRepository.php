<?php

namespace App\Repositories;

use App\Models\PasswordRecoveryChallenge;
use Closure;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<PasswordRecoveryChallenge> */
class PasswordRecoveryChallengeRepository extends BaseRepository
{
    public function __construct(PasswordRecoveryChallenge $model)
    {
        parent::__construct($model);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function latestForUserLocked(int $userId, string $email): ?PasswordRecoveryChallenge
    {
        return $this->query()
            ->where('user_id', $userId)
            ->where('email', $email)
            ->latest('id')
            ->lockForUpdate()
            ->first();
    }

    public function findLocked(int $id): ?PasswordRecoveryChallenge
    {
        return $this->query()->whereKey($id)->lockForUpdate()->first();
    }

    /** @param array<string, mixed> $attributes */
    public function createChallenge(array $attributes): PasswordRecoveryChallenge
    {
        /** @var PasswordRecoveryChallenge $challenge */
        $challenge = $this->create($attributes);

        return $challenge;
    }

    /** @param array<string, mixed> $attributes */
    public function updateChallenge(PasswordRecoveryChallenge $challenge, array $attributes): PasswordRecoveryChallenge
    {
        $challenge->fill($attributes)->save();

        return $challenge->refresh();
    }

    public function invalidateAllForUser(int $userId): void
    {
        $now = now();

        $this->query()
            ->where('user_id', $userId)
            ->update([
                'otp_consumed_at' => $now,
                'token_consumed_at' => $now,
                'updated_at' => $now,
            ]);
    }
}
