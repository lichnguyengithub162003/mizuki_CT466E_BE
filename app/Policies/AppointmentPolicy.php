<?php

namespace App\Policies;

use App\Enums\UserRole;
use App\Models\Appointment;
use App\Models\User;

class AppointmentPolicy
{
    public function viewAny(User $user): bool
    {
        return in_array($user->role, [
            UserRole::Customer,
            UserRole::Technician,
            UserRole::BranchManager,
            UserRole::SuperAdmin,
        ], true);
    }

    public function create(User $user): bool
    {
        return $user->role === UserRole::Customer;
    }

    public function view(User $user, Appointment $appointment): bool
    {
        return match ($user->role) {
            UserRole::Customer => $appointment->user_id === $user->id,
            UserRole::SuperAdmin => true,
            UserRole::BranchManager => $user->branch_id !== null
                && $appointment->branch_id === $user->branch_id,
            UserRole::Technician => $user->branch_id !== null
                && $appointment->branch_id === $user->branch_id
                && $appointment->technician_id === $user->id,
            default => false,
        };
    }

    public function cancel(User $user, Appointment $appointment): bool
    {
        if ($user->role === UserRole::Customer) {
            return $this->view($user, $appointment);
        }

        return $this->manage($user, $appointment);
    }

    public function createWalkIn(User $user, int $branchId): bool
    {
        return $user->role === UserRole::SuperAdmin
            || ($user->role === UserRole::BranchManager
                && $user->branch_id !== null
                && $user->branch_id === $branchId);
    }

    public function manage(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::SuperAdmin
            || ($user->role === UserRole::BranchManager
                && $user->branch_id !== null
                && $appointment->branch_id === $user->branch_id);
    }

    public function updateAssigned(User $user, Appointment $appointment): bool
    {
        return $user->role === UserRole::Technician
            && $user->branch_id !== null
            && $appointment->branch_id === $user->branch_id
            && $appointment->technician_id === $user->id;
    }
}
