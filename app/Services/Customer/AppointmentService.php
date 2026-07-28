<?php

namespace App\Services\Customer;

use App\Enums\AppointmentStatus;
use App\Models\Appointment;
use App\Models\BranchService;
use App\Models\User;
use App\Repositories\AppointmentRepository;
use App\Services\BaseService;
use App\Services\ClinicService;
use Carbon\CarbonImmutable;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;

class AppointmentService extends BaseService
{
    public function __construct(
        private readonly AppointmentRepository $appointments,
        private readonly ClinicService $clinics,
    ) {}

    /** @param array<string, mixed> $data */
    public function create(User $user, array $data): Appointment
    {
        return $this->appointments->transaction(function () use ($user, $data): Appointment {
            $this->appointments->lockCustomer($user->id);

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
            $capacity = max(1, (int) $branchService->capacity);
            $blockingCount = $this->appointments->countBlockingAppointments(
                $branchService->branch_id,
                $branchService->service_id,
                $startsAt,
                $endsAt,
            );

            if ($blockingCount >= $capacity) {
                throw ValidationException::withMessages([
                    'start_time' => ['Khung giờ đã hết chỗ, vui lòng chọn khung giờ khác!'],
                ]);
            }

            if ($this->appointments->customerHasOverlap($user->id, $startsAt, $endsAt)) {
                throw ValidationException::withMessages([
                    'start_time' => ['Bạn đã có lịch hẹn trùng với khung giờ này!'],
                ]);
            }

            $service = $branchService->service;

            return $this->appointments->createAppointment([
                'appointment_number' => $this->appointmentNumber(),
                'user_id' => $user->id,
                'branch_id' => $branchService->branch_id,
                'service_id' => $branchService->service_id,
                'status' => AppointmentStatus::Pending,
                'service_name' => $service->name,
                'service_price' => $service->price,
                'duration_minutes' => $service->duration_minutes,
                'starts_at' => $startsAt,
                'ends_at' => $endsAt,
                'customer_note' => $data['customer_note'] ?? null,
            ]);
        });
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return LengthAwarePaginator<int, Appointment>
     */
    public function paginate(User $user, array $filters): LengthAwarePaginator
    {
        return $this->appointments->paginateForUser(
            $user->id,
            $filters,
            (int) ($filters['per_page'] ?? 15),
        );
    }

    public function detail(User $user, int $appointmentId): ?Appointment
    {
        return $this->appointments->findForUser($appointmentId, $user->id);
    }

    public function cancel(User $user, int $appointmentId): ?Appointment
    {
        return $this->appointments->transaction(function () use ($user, $appointmentId): ?Appointment {
            $appointment = $this->appointments->lockForUser($appointmentId, $user->id);

            if ($appointment === null) {
                return null;
            }

            if (! in_array($appointment->status, [
                AppointmentStatus::Pending,
                AppointmentStatus::Confirmed,
            ], true)) {
                throw ValidationException::withMessages([
                    'status' => ['Lịch hẹn ở trạng thái hiện tại không thể hủy!'],
                ]);
            }

            return $this->appointments->markCancelled($appointment);
        });
    }

    /**
     * @param  array<string, mixed>  $data
     * @return array{CarbonImmutable, CarbonImmutable}
     */
    private function resolveRequestedSlot(BranchService $branchService, array $data): array
    {
        $timezone = (string) config('app.timezone');
        $startsAt = CarbonImmutable::createFromFormat(
            '!Y-m-d H:i',
            $data['appointment_date'].' '.$data['start_time'],
            $timezone,
        );
        $endsAt = $startsAt->addMinutes((int) $branchService->service->duration_minutes);
        $availability = $this->clinics->getAvailableSlots(
            $branchService->branch_id,
            $branchService->service_id,
            (string) $data['appointment_date'],
        );

        $requestedSlot = collect($availability['slots'])->first(
            fn (array $slot): bool => CarbonImmutable::parse($slot['start_at'])
                ->equalTo($startsAt),
        );

        if ($requestedSlot === null) {
            throw ValidationException::withMessages([
                'start_time' => ['Khung giờ không hợp lệ hoặc nằm ngoài giờ hoạt động!'],
            ]);
        }

        if (! $requestedSlot['available']) {
            throw ValidationException::withMessages([
                'start_time' => ['Khung giờ không còn khả dụng!'],
            ]);
        }

        return [$startsAt, $endsAt];
    }

    private function appointmentNumber(): string
    {
        return 'APT-'.now()->format('ymd').'-'.Str::upper(Str::random(10));
    }
}
