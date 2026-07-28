<?php

namespace App\Services\Customer;

use App\Models\SkinProfile;
use App\Models\User;
use App\Repositories\SkinProfileRepository;
use App\Services\BaseService;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class SkinProfileService extends BaseService
{
    public function __construct(
        private readonly SkinProfileRepository $profiles,
    ) {}

    public function show(User $user): SkinProfile
    {
        Gate::forUser($user)->authorize('viewOwn', [SkinProfile::class, $user]);

        return $this->profiles->findForUser($user->id)
            ?? $this->emptyProfile($user);
    }

    /** @param array<string, mixed> $data */
    public function update(User $user, array $data): SkinProfile
    {
        Gate::forUser($user)->authorize('updateOwn', [SkinProfile::class, $user]);

        return $this->profiles->transaction(function () use ($user, $data): SkinProfile {
            $customer = $this->profiles->lockCustomer($user->id);

            if ($customer === null) {
                throw ValidationException::withMessages([
                    'customer' => ['Tài khoản khách hàng không hợp lệ!'],
                ]);
            }

            if (is_array($data['concerns'] ?? null)) {
                $data['concerns'] = array_values(array_unique(array_map(
                    fn (string $concern): string => trim($concern),
                    $data['concerns'],
                )));
            }

            return $this->profiles->updateOrCreateForUser($customer->id, $data);
        });
    }

    private function emptyProfile(User $user): SkinProfile
    {
        $profile = new SkinProfile([
            'user_id' => $user->id,
            'skin_type' => null,
            'concerns' => null,
            'sensitivity_level' => null,
            'allergies' => null,
            'current_products' => null,
            'notes' => null,
        ]);
        $profile->setRelation('user', $user);

        return $profile;
    }
}
