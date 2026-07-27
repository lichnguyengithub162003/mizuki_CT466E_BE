<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Refund;
use App\Models\User;

class RefundPolicy
{
    public function before(User $user): ?bool
    {
        return $user->role === UserRole::SuperAdmin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::BranchManager && $user->branch_id !== null;
    }

    public function view(User $user, Refund $refund): bool
    {
        return $this->belongsToManagedBranch($user, $refund);
    }

    public function review(User $user, Refund $refund): bool
    {
        return $this->belongsToManagedBranch($user, $refund);
    }

    public function payout(User $user, Refund $refund): bool
    {
        return $this->belongsToManagedBranch($user, $refund);
    }

    private function belongsToManagedBranch(User $user, Refund $refund): bool
    {
        return $user->role === UserRole::BranchManager
            && $user->branch_id !== null
            && $refund->order->branch_id === $user->branch_id;
    }
}
