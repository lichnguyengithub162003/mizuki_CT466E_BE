<?php

namespace App\Services\Admin;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BranchService;
use App\Models\User;
use App\Repositories\AppointmentRepository;
use App\Services\BaseService;
use App\Services\ClinicService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentService extends BaseService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ClinicService $clinics,
    ) {}

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        Gate::forUser($user)->authorize('viewAny', Appointment::class);

        return $this->appointments->paginateForAdmin(
            $user->role,
            $user->branch_id,
            $filters,
            (int) ($filters['per_page'] ?? 20),
        );
    }

    public function detail(User $user, int $appointmentId): ?Appointment
    {
        $appointment = $this->appointments->findForAdmin(
            $appointmentId,
            $user->role,
            $user->branch_id,
        );

        if ($appointment !== null) {
            Gate::forUser($user)->authorize('view', $appointment);
        }

        return $appointment;
    }

    /** @param array<string, mixed> $data */
    public function createWalkIn(User $user, array $data): Appointment
    {
        Gate::forUser($user)->authorize('createWalkIn', [Appointment::class, (int) $data['branch_id']]);

        return $this->appointments->transaction(function () use ($data): Appointment {
            $customer = isset($data['customer_id'])
                ? $this->appointments->lockCustomerAccount((int) $data['customer_id'])
                : null;

            if (isset($data['customer_id']) && $customer === null) {
                throw ValidationException::withMessages([
                    'customer_id' => ['Tài khoản được chọn không phải tài khoản khách hàng!'],
                ]);
            }

            $branchService = $this->appointments->lockBookableBranchService(
                (int) $data['branch_id'],
                (int) $data['service_id'],
            );

            if ($branchService === null) {
                throw ValidationException::withMessages([
                    'service_id' => ['Cơ sở chăm sóc da hoặc dịch vụ không khả dụng!'],
                ]);
            }

            [$startsAt, $endsAt] = $this->resolveRequestedSlot($branchService, $data);
            $blockingCount = $this->appointments->countBlockingAppointments(
                $branchService->branch_id,
                $branchService->service_id,
                $startsAt,
                $endsAt,
            );

            if ($blockingCount >= max(1, (int) $branchService->capacity)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Khung giờ đã hết chỗ, vui lòng chọn khung giờ khác!'],
                ]);
            }

            if ($customer !== null
                && $this->appointments->customerHasOverlap($customer->id, $startsAt, $endsAt)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Khách hàng đã có lịch hẹn trùng với khung giờ này!'],
                ]);
            }

            $service = $branchService->service;

            return $this->appointments->createAppointment([
                'appointment_number' => $this->appointmentNumber(),
                'user_id' => $customer?->id,
                'customer_name' => $customer?->name ?? $data['customer_name'] ?? null,
                'customer_phone' => $customer?->phone ?? $data['customer_phone'] ?? null,
                'branch_id' => $branchService->branch_id,
                'service_id' => $branchService->service_id,
                'status' => AppointmentStatus::Confirmed,
                'service_name' => $service->name,
                'service_price' => $service->price,
                'duration_minutes' => $service->duration_minutes,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'customer_note' => $data['customer_note'] ?? null,
            ]);
        });
    }

    public function assignTechnician(User $user, int $appointmentId, int $technicianId): ?Appointment
    {
        return $this->appointments->transaction(function () use ($user, $appointmentId, $technicianId): ?Appointment {
            $appointment = $this->appointments->lockForAdmin(
                $appointmentId,
                $user->role,
                $user->branch_id,
            );

            if ($appointment === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manage', $appointment);

            if (! in_array($appointment->status, [
                AppointmentStatus::Pending,
                AppointmentStatus::Confirmed,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể phân công kỹ thuật viên cho lịch hẹn đang chờ hoặc đã xác nhận!'],
                ]);
            }

            $technician = $this->appointments->lockTechnicianForBranch(
                $technicianId,
                $appointment->branch_id,
            );

            if ($technician === null) {
                throw ValidationException::withMessages([
                    'technician_id' => ['Kỹ thuật viên không thuộc chi nhánh của lịch hẹn!'],
                ]);
            }

            if ($appointment->technician_id === $technician->id) {
                return $this->appointments->loadDetails($appointment);
            }

            if ($this->appointments->technicianHasOverlap(
                $technician->id,
                $appointment->starts_at,
                $appointment->ends_at,
                $appointment->id,
            )) {
                throw ValidationException::withMessages([
                    'technician_id' => ['Kỹ thuật viên đã có lịch hẹn trùng với khung giờ này!'],
                ]);
            }

            return $this->appointments->assignTechnician($appointment, $technician);
        });
    }

    public function confirm(User $user, int $appointmentId): ?Appointment
    {
        return $this->transition(
            $user,
            $appointmentId,
            AppointmentStatus::Pending,
            AppointmentStatus::Confirmed,
        );
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
            true,
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

    /** @param array<string, mixed> $data */
    public function cancel(User $user, int $appointmentId, array $data): ?Appointment
    {
        return $this->appointments->transaction(function () use ($user, $appointmentId, $data): ?Appointment {
            $appointment = $this->appointments->lockForAdmin(
                $appointmentId,
                $user->role,
                $user->branch_id,
            );

            if ($appointment === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manage', $appointment);

            if (! in_array($appointment->status, [
                AppointmentStatus::Pending,
                AppointmentStatus::Confirmed,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Chỉ có thể hủy lịch hẹn đang chờ hoặc đã xác nhận!'],
                ]);
            }

            return $this->appointments->updateStatus(
                $appointment,
                AppointmentStatus::Cancelled,
                $data['staff_note'] ?? null,
            );
        });
    }

    private function transition(
        User $user,
        int $appointmentId,
        AppointmentStatus $expected,
        AppointmentStatus $target,
        ?string $staffNote = null,
        bool $requiresTechnician = false,
    ): ?Appointment {
        return $this->appointments->transaction(function () use (
            $user,
            $appointmentId,
            $expected,
            $target,
            $staffNote,
            $requiresTechnician,
        ): ?Appointment {
            $appointment = $this->appointments->lockForAdmin(
                $appointmentId,
                $user->role,
                $user->branch_id,
            );

            if ($appointment === null) {
                return null;
            }

            Gate::forUser($user)->authorize('manage', $appointment);

            if ($appointment->status !== $expected) {
                throw ValidationException::withMessages([
                    'status' => ['Chuyển trạng thái lịch hẹn không hợp lệ!'],
                ]);
            }

            if ($requiresTechnician) {
                $technician = $appointment->technician_id === null
                    ? null
                    : $this->appointments->lockTechnicianForBranch(
                        $appointment->technician_id,
                        $appointment->branch_id,
                    );

                if ($technician === null) {
                    throw ValidationException::withMessages([
                        'technician_id' => ['Vui lòng phân công kỹ thuật viên hợp lệ trước khi bắt đầu!'],
                    ]);
                }
            }

            return $this->appointments->updateStatus($appointment, $target, $staffNote);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveRequestedSlot(BranchService $branchService, array $data): array
    {
        $startsAt = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $data['appointment_date'].' '.$data['start_time'],
            (string) config('app.timezone'),
        );
        $endsAt = $startsAt->addMinutes((int) $branchService->service->duration_minutes);
        $availability = $this->clinics->getAvailableSlots(
            $branchService->branch_id,
            $branchService->service_id,
            (string) $data['appointment_date'],
        );
        $slot = collect($availability['slots'])->first(
            fn (array $candidate): bool => CarbonImmutable::parse($candidate['start_at'])->equalTo($startsAt),
        );

        if ($slot === null) {
            throw ValidationException::withMessages([
                'start_time' => ['Khung giờ không hợp lệ hoặc nằm ngoài giờ hoạt động!'],
            ]);
        }

        if (! $slot['available']) {
            throw ValidationException::withMessages([
                'start_time' => ['Khung giờ không còn khả dụng!'],
            ]);
        }

        return [$startsAt, $endsAt];
    }

    private function appointmentNumber(): string
    {
        return 'APT-WI-'.now()->format('ymd').'-'.Str::upper(Str::random(8));
    }
}
