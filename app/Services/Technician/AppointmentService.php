<?php

namespace App\Services\Technician;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\User;
use App\Repositories\AppointmentRepository;
use App\Services\BaseService;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Validation\ValidationException;

class AppointmentService extends BaseService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Appointment::class);

        return $this->appointments->paginateForTechnician(
            $user->id,
            $user->branch_id,
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );
    }

    public function detail(User $user, int $appointmentId): ?Appointment
    {
        $appointment = $this->appointments->findForTechnician(
            $appointmentId,
            $user->id,
            $user->branch_id,
        );

        if ($appointment !== null) {
            Gate::forUser($user)->authorize('view', $appointment);
        }

        return $appointment;
    }

    /** @param array<string, mixed> $data */
    public function start(User $user, int $appointmentId, array $data): ?Appointment
    {
        return $this->transition(
            $user,
            $appointmentId,
            AppointmentStatus::Confirmed,
            AppointmentStatus::InProgress,
            $data['staff_note'] ?? null,
        );
    }

    /** @param array<string, mixed> $data */
    public function complete(User $user, int $appointmentId, array $data): ?Appointment
    {
        return $this->transition(
            $user,
            $appointmentId,
            AppointmentStatus::InProgress,
            AppointmentStatus::Completed,
            $data['staff_note'] ?? null,
        );
    }

    private function transition(
        User $user,
        int $appointmentId,
        AppointmentStatus $expected,
        AppointmentStatus $target,
        ?string $staffNote,
    ): ?Appointment {
        return $this->appointments->transaction(function () use (
            $user,
            $appointmentId,
            $expected,
            $target,
            $staffNote,
        ): ?Appointment {
            $appointment = $this->appointments->lockForTechnician(
                $appointmentId,
                $user->id,
                $user->branch_id,
            );

            if ($appointment === null) {
                return null;
            }

            Gate::forUser($user)->authorize('updateAssigned', $appointment);

            if ($appointment->status !== $expected) {
                throw ValidationException::withMessages([
                    'status' => ['Chuyển trạng thái lịch hẹn không hợp lệ!'],
                ]);
            }

            return $this->appointments->updateStatus($appointment, $target, $staffNote);
        });
    }
}
