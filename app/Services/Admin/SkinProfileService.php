<?php

namespace App\Services\Admin;

use App\Enums\UserRole;
use App\Models\SkinProfile;
use App\Models\User;
use App\Repositories\SkinProfileRepository;
use App\Services\BaseService;
use Illuminate\Support\Facades\Gate;

class SkinProfileService extends BaseService
{
    public function __construct(
        private readonly SkinProfileRepository $profiles,
    ) {}

    public function show(User $actor, int $customerId): ?User
    {
        $customer = match ($actor->role) {
            UserRole::SuperAdmin => $this->profiles->findCustomer($customerId),
            UserRole::BranchManager => $actor->branch_id === null
                ? null
                : $this->profiles->findCustomerForManager($customerId, $actor->branch_id),
            default => null,
        };

        if ($customer !== null) {
            Gate::forUser($actor)->authorize('viewAsAdmin', [SkinProfile::class, $customer]);
        }

        return $customer;
    }
}
