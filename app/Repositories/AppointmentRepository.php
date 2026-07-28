<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Models\Appointment;
use App\Models\BranchService;
use App\Models\User;
use Carbon\CarbonInterface;
use Closure;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

/** @extends BaseRepository<Appointment> */
class AppointmentRepository extends BaseRepository
{
    public function __construct(
        Appointment $appointment,
        private readonly BranchService $branchService,
        private readonly User $user,
    ) {
        parent::__construct($appointment);
    }

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function lockCustomer(int $userId): ?User
    {
        return $this->user->newQuery()
            ->whereKey($userId)
            ->lockForUpdate()
            ->first();
    }

    public function lockBookableBranchService(int $branchId, int $serviceId): ?BranchService
    {
        return $this->branchService->newQuery()
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->where('is_available', true)
            ->whereHas('branch', function (Builder $query): void {
                $query->where('is_active', true)
                    ->whereIn('branch_type', [
                        BranchType::Clinic->value,
                        BranchType::Hybrid->value,
                    ]);
            })
            ->whereHas('service', fn (Builder $query): Builder => $query->where('is_active', true))
            ->with(['branch:id,name,code,branch_type,is_active', 'service'])
            ->lockForUpdate()
            ->first();
    }

    public function countBlockingAppointments(
        int $branchId,
        int $serviceId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): int {
        return $this->blockingQuery($startsAt, $endsAt)
            ->where('branch_id', $branchId)
            ->where('service_id', $serviceId)
            ->count();
    }

    public function customerHasOverlap(
        int $userId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): bool {
        return $this->blockingQuery($startsAt, $endsAt)
            ->where('user_id', $userId)
            ->exists();
    }

    /** @param array<string, mixed> $attributes */
    public function createAppointment(array $attributes): Appointment
    {
        /** @var Appointment $appointment */
        $appointment = $this->query()->create($attributes);

        return $this->loadDetails($appointment);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginateForUser(int $userId, array $filters, int $perPage): LengthAwarePaginator
    {
        return $this->query()
            ->where('user_id', $userId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->with([
                'branch:id,name,code,branch_type',
                'service:id,name,slug',
                'technician:id,name',
            ])
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForUser(int $appointmentId, int $userId): ?Appointment
    {
        $appointment = $this->query()
            ->whereKey($appointmentId)
            ->where('user_id', $userId)
            ->first();

        return $appointment === null ? null : $this->loadDetails($appointment);
    }

    public function lockForUser(int $appointmentId, int $userId): ?Appointment
    {
        return $this->query()
            ->whereKey($appointmentId)
            ->where('user_id', $userId)
            ->lockForUpdate()
            ->first();
    }

    public function markCancelled(Appointment $appointment): Appointment
    {
        $appointment->fill([
            'status' => AppointmentStatus::Cancelled,
            'cancelled_at' => now(),
        ])->save();

        return $this->loadDetails($appointment->refresh());
    }

    public function loadDetails(Appointment $appointment): Appointment
    {
        return $appointment->load([
            'branch:id,name,code,branch_type',
            'service:id,name,slug',
            'technician:id,name',
        ]);
    }

    /** @return Builder<Appointment> */
    private function blockingQuery(
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): Builder {
        return $this->query()
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::InProgress->value,
            ])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt);
    }
}
