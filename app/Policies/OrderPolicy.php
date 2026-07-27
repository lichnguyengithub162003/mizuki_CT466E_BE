<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Order;
use App\Models\User;

class OrderPolicy
{
    public function before(User $user): ?bool
    {
        return $user->role === UserRole::SuperAdmin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return in_array($user->role, [UserRole::Customer, UserRole::BranchManager], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function view(User $user, Order $order): bool
    {
        if ($user->role === UserRole::Customer) {
            return $order->user_id === $user->id;
        }

        return $user->role === UserRole::BranchManager
            && $user->branch_id !== null
            && $order->branch_id === $user->branch_id;
    }

    public function confirm(User $user, Order $order): bool
    {
        return $user->role === UserRole::BranchManager
            && $user->branch_id !== null
            && $order->branch_id === $user->branch_id;
    }
}
