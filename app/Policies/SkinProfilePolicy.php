<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\User;

class SkinProfilePolicy
{
    public function viewOwn(User $actor, User $customer): bool
    {
        return $actor->role === UserRole::Customer
            && $customer->role === UserRole::Customer
            && $actor->id === $customer->id;
    }

    public function updateOwn(User $actor, User $customer): bool
    {
        return $this->viewOwn($actor, $customer);
    }

    public function viewAsAdmin(User $actor, User $customer): bool
    {
        return in_array($actor->role, [
            UserRole::BranchManager,
            UserRole::SuperAdmin,
        ], true) && $customer->role === UserRole::Customer;
    }

    public function viewAsTechnician(User $actor, User $customer): bool
    {
        return $actor->role === UserRole::Technician
            && $customer->role === UserRole::Customer;
    }
}
