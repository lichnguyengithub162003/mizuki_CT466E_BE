<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Promotion;
use App\Models\User;

class PromotionPolicy
{
    public function before(User $user): ?bool
    {
        return $user->role === UserRole::SuperAdmin ? true : null;
    }

    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::BranchManager;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::BranchManager;
    }

    public function createPersonal(User $user): bool
    {
        return false;
    }

    public function assignBranch(User $user, int $branchId): bool
    {
        return $user->role === UserRole::BranchManager
            && $user->branch_id !== null
            && $user->branch_id === $branchId;
    }

    public function view(User $user, Promotion $promotion): bool
    {
        return $this->canManageBranchCampaign($user, $promotion);
    }

    public function update(User $user, Promotion $promotion): bool
    {
        return $this->canManageBranchCampaign($user, $promotion);
    }

    public function delete(User $user, Promotion $promotion): bool
    {
        return $this->canManageBranchCampaign($user, $promotion);
    }

    private function canManageBranchCampaign(User $user, Promotion $promotion): bool
    {
        $personalUserIds = $promotion->scope['user_ids'] ?? [];

        return $user->role === UserRole::BranchManager
            && $user->branch_id !== null
            && $personalUserIds === []
            && $promotion->branches->contains('id', $user->branch_id);
    }
}
