<?php

namespace App\Repositories;

use App\Enums\UserRole;
use App\Models\SkinProfile;
use App\Models\User;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<SkinProfile> */
class SkinProfileRepository extends BaseRepository
{
    public function __construct(
        SkinProfile $skinProfile,
        private readonly User $user,
    ) {
        parent::__construct($skinProfile);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function lockCustomer(int $userId): ?User
    {
        return $this->user->newQuery()
            ->whereKey($userId)
            ->where('role', UserRole::Customer->value)
            ->lockForUpdate()
            ->first();
    }

    public function findForUser(int $userId): ?SkinProfile
    {
        return $this->query()->where('user_id', $userId)->first();
    }

    /** @param array<string, mixed> $attributes */
    public function updateOrCreateForUser(int $userId, array $attributes): SkinProfile
    {
        /** @var SkinProfile $profile */
        $profile = $this->query()->updateOrCreate(
            ['user_id' => $userId],
            $attributes,
        );

        return $profile->refresh();
    }

    public function findCustomer(int $customerId): ?User
    {
        return $this->customerQuery($customerId)->first();
    }

    public function findCustomerForManager(int $customerId, int $branchId): ?User
    {
        return $this->customerQuery($customerId)
            ->whereHas(
                'appointments',
                fn (Builder $query): Builder => $query->where('branch_id', $branchId),
            )
            ->first();
    }

    public function findCustomerForTechnician(int $customerId, int $technicianId): ?User
    {
        return $this->customerQuery($customerId)
            ->whereHas(
                'appointments',
                fn (Builder $query): Builder => $query->where('technician_id', $technicianId),
            )
            ->first();
    }

    /** @return Builder<User> */
    private function customerQuery(int $customerId): Builder
    {
        return $this->user->newQuery()
            ->whereKey($customerId)
            ->where('role', UserRole::Customer->value)
            ->with('skinProfile');
    }
}
