<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Enums\UserRole;
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

    public function lockCustomerAccount(int $userId): ?User
    {
        return $this->user->newQuery()
            ->whereKey($userId)
            ->where('role', UserRole::Customer->value)
            ->lockForUpdate()
            ->first();
    }

    public function lockTechnicianForBranch(int $technicianId, int $branchId): ?User
    {
        return $this->user->newQuery()
            ->whereKey($technicianId)
            ->where('role', UserRole::Technician->value)
            ->where('branch_id', $branchId)
            ->lockForUpdate()
            ->first();
    }

    public function technicianHasOverlap(
        int $technicianId,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
        int $exceptAppointmentId,
    ): bool {
        return $this->blockingQuery($startsAt, $endsAt)
            ->where('technician_id', $technicianId)
            ->where('appointments.id', '!=', $exceptAppointmentId)
            ->exists();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginateForAdmin(
        UserRole $role,
        ?int $branchId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->applyAdminScope($this->query(), $role, $branchId)
            ->when(
                $role === UserRole::SuperAdmin && isset($filters['branch_id']),
                fn (Builder $query): Builder => $query->where('branch_id', $filters['branch_id']),
            )
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['technician_id']),
                fn (Builder $query): Builder => $query->where('technician_id', $filters['technician_id']),
            )
            ->when(
                isset($filters['appointment_date']),
                fn (Builder $query): Builder => $query->whereDate('starts_at', $filters['appointment_date']),
            )
            ->when(filled($filters['keyword'] ?? null), function (Builder $query) use ($filters): void {
                $keyword = trim((string) $filters['keyword']);
                $query->where(function (Builder $nested) use ($keyword): void {
                    $nested->where('appointment_number', 'like', "%{$keyword}%")
                        ->orWhere('customer_name', 'like', "%{$keyword}%")
                        ->orWhere('customer_phone', 'like', "%{$keyword}%")
                        ->orWhereHas('user', function (Builder $userQuery) use ($keyword): void {
                            $userQuery->where('name', 'like', "%{$keyword}%")
                                ->orWhere('email', 'like', "%{$keyword}%")
                                ->orWhere('phone', 'like', "%{$keyword}%");
                        });
                });
            })
            ->with($this->detailRelations())
            ->orderByDesc('created_at')
            ->orderByDesc('id')
            ->paginate($perPage);
    }

    public function findForAdmin(int $appointmentId, UserRole $role, ?int $branchId): ?Appointment
    {
        return $this->applyAdminScope($this->query(), $role, $branchId)
            ->whereKey($appointmentId)
            ->with($this->detailRelations())
            ->first();
    }

    public function lockForAdmin(int $appointmentId, UserRole $role, ?int $branchId): ?Appointment
    {
        return $this->applyAdminScope($this->query(), $role, $branchId)
            ->whereKey($appointmentId)
            ->lockForUpdate()
            ->first();
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginateForTechnician(
        int $technicianId,
        ?int $branchId,
        array $filters,
        int $perPage,
    ): LengthAwarePaginator {
        return $this->applyTechnicianScope($this->query(), $technicianId, $branchId)
            ->when(
                isset($filters['status']),
                fn (Builder $query): Builder => $query->where('status', $filters['status']),
            )
            ->when(
                isset($filters['appointment_date']),
                fn (Builder $query): Builder => $query->whereDate('starts_at', $filters['appointment_date']),
            )
            ->with($this->detailRelations())
            ->orderBy('starts_at')
            ->orderBy('id')
            ->paginate($perPage);
    }

    public function findForTechnician(
        int $appointmentId,
        int $technicianId,
        ?int $branchId,
    ): ?Appointment {
        return $this->applyTechnicianScope($this->query(), $technicianId, $branchId)
            ->whereKey($appointmentId)
            ->with($this->detailRelations())
            ->first();
    }

    public function lockForTechnician(
        int $appointmentId,
        int $technicianId,
        ?int $branchId,
    ): ?Appointment {
        return $this->applyTechnicianScope($this->query(), $technicianId, $branchId)
            ->whereKey($appointmentId)
            ->lockForUpdate()
            ->first();
    }

    public function assignTechnician(Appointment $appointment, User $technician): Appointment
    {
        $appointment->fill(['technician_id' => $technician->id])->save();

        return $this->loadDetails($appointment->refresh());
    }

    public function updateStatus(
        Appointment $appointment,
        AppointmentStatus $status,
        ?string $staffNote = null,
    ): Appointment {
        $attributes = ['status' => $status];

        if ($staffNote !== null) {
            $attributes['staff_note'] = $staffNote;
        }

        if ($status === AppointmentStatus::Completed) {
            $attributes['completed_at'] = now();
        }

        if ($status === AppointmentStatus::Cancelled) {
            $attributes['cancelled_at'] = now();
        }

        $appointment->fill($attributes)->save();

        return $this->loadDetails($appointment->refresh());
    }

    /** @return array<int, string> */
    private function detailRelations(): array
    {
        return [
            'user:id,name,email,phone',
            'branch:id,name,code,branch_type',
            'service:id,name,slug',
            'technician:id,name,branch_id',
        ];
    }

    /** @param Builder<Appointment> $query
     * @return Builder<Appointment>
     */
    private function applyAdminScope(Builder $query, UserRole $role, ?int $branchId): Builder
    {
        if ($role === UserRole::SuperAdmin) {
            return $query;
        }

        if ($role === UserRole::BranchManager && $branchId !== null) {
            return $query->where('branch_id', $branchId);
        }

        return $query->whereRaw('1 = 0');
    }

    /** @param Builder<Appointment> $query
     * @return Builder<Appointment>
     */
    private function applyTechnicianScope(
        Builder $query,
        int $technicianId,
        ?int $branchId,
    ): Builder {
        if ($branchId === null) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('technician_id', $technicianId)
            ->where('branch_id', $branchId);
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
        return $appointment->load($this->detailRelations());
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
