<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::Customer
            && $appointment->user_id === $user->id;
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        return $this->view($user, $appointment);
    }
}
