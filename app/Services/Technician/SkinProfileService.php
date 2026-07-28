<?php

namespace App\Services\Technician;

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

    public function show(User $technician, int $customerId): ?User
    {
        $customer = $this->profiles->findCustomerForTechnician(
            $customerId,
            $technician->id,
        );

        if ($customer !== null) {
            Gate::forUser($technician)->authorize(
                'viewAsTechnician',
                [SkinProfile::class, $customer],
            );
        }

        return $customer;
    }
}
