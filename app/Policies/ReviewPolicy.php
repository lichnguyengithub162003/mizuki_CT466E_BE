<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Review;
use App\Models\User;

class ReviewPolicy
{
    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function update(User $user, Review $review): bool
    {
        return $user->role === UserRole::Customer
            && $review->user_id === $user->id
            && $review->source === null;
    }
}
