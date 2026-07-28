<?php

namespace App\Services;

use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\Service;
use App\Repositories\ClinicRepository;
use Carbon\CarbonImmutable;
use Illuminate\Database\Eloquent\Collection;

class ClinicService extends BaseService
{
    private const SLOT_INTERVAL_MINUTES = 30;

    private const EARLIEST_OPENING = '09:00:00';

    private const LATEST_CLOSING = '20:00:00';

    public function __construct(
        private readonly ClinicRepository $clinics,
    ) {}

    /**
     * @return Collection<int, Branch>
     */
    public function getActiveClinics(): Collection
    {
        return $this->clinics->getActiveClinics();
    }

    /**
     * @return array{branch: Branch, services: Collection<int, Service>}
     */
    public function getClinicServices(int $branchId): array
    {
        $branch = $this->clinics->findActiveClinicOrFail($branchId);

        return [
            'branch' => $branch,
            'services' => $this->clinics->getActiveServices($branch),
        ];
    }

    /**
     * @return array{
     *     branch: Branch,
     *     service: Service,
     *     date: string,
     *     timezone: string,
     *     slots: array<int, array{start_at: string, end_at: string, available: bool, remaining_capacity: int}>
     * }
     */
    public function getAvailableSlots(int $branchId, int $serviceId, string $date): array
    {
        $timezone = (string) config('app.timezone');
        $bookingDate = CarbonImmutable::createFromFormat('!Y-m-d', $date, $timezone);
        $branch = $this->clinics->findActiveClinicOrFail($branchId);
        $service = $this->clinics->findActiveServiceOrFail($branch, $serviceId);
        $businessHour = $this->clinics->findBusinessHour($branch, $bookingDate->dayOfWeek);
        $window = $this->resolveBookingWindow($bookingDate, $businessHour);

        return [
            'branch' => $branch,
            'service' => $service,
            'date' => $bookingDate->toDateString(),
            'timezone' => $timezone,
            'slots' => $window === null
                ? []
                : $this->generateSlots($branch, $service, $window['opens_at'], $window['closes_at']),
        ];
    }

    /**
     * @return array{opens_at: CarbonImmutable, closes_at: CarbonImmutable}|null
     */
    private function resolveBookingWindow(
        CarbonImmutable $date,
        ?BranchBusinessHour $businessHour,
    ): ?array {
        if ($businessHour?->is_closed) {
            return null;
        }

        $minimumOpening = $date->setTimeFromTimeString(self::EARLIEST_OPENING);
        $maximumClosing = $date->setTimeFromTimeString(self::LATEST_CLOSING);
        $hasValidHours = $businessHour !== null
            && $businessHour->opens_at !== null
            && $businessHour->closes_at !== null
            && $businessHour->opens_at < $businessHour->closes_at;

        if (! $hasValidHours) {
            return ['opens_at' => $minimumOpening, 'closes_at' => $maximumClosing];
        }

        $branchOpening = $date->setTimeFromTimeString((string) $businessHour->opens_at);
        $branchClosing = $date->setTimeFromTimeString((string) $businessHour->closes_at);
        $opensAt = $branchOpening->greaterThan($minimumOpening) ? $branchOpening : $minimumOpening;
        $closesAt = $branchClosing->lessThan($maximumClosing) ? $branchClosing : $maximumClosing;

        return $opensAt->lessThan($closesAt)
            ? ['opens_at' => $opensAt, 'closes_at' => $closesAt]
            : null;
    }

    /**
     * @return array<int, array{start_at: string, end_at: string, available: bool, remaining_capacity: int}>
     */
    private function generateSlots(
        Branch $branch,
        Service $service,
        CarbonImmutable $opensAt,
        CarbonImmutable $closesAt,
    ): array {
        $slots = [];
        $capacity = max(1, (int) $service->getAttribute('booking_capacity'));
        $duration = max(1, (int) $service->duration_minutes);
        $now = CarbonImmutable::now($opensAt->getTimezone());

        for ($startsAt = $opensAt; $startsAt->addMinutes($duration)->lessThanOrEqualTo($closesAt); $startsAt = $startsAt->addMinutes(self::SLOT_INTERVAL_MINUTES)) {
            $endsAt = $startsAt->addMinutes($duration);
            $blocking = $this->clinics->countBlockingAppointments($branch, $service, $startsAt, $endsAt);
            $remaining = max(0, $capacity - $blocking);
            $isFuture = $startsAt->greaterThan($now);

            $slots[] = [
                'start_at' => $startsAt->toIso8601String(),
                'end_at' => $endsAt->toIso8601String(),
                'available' => $isFuture && $remaining > 0,
                'remaining_capacity' => $isFuture ? $remaining : 0,
            ];
        }

        return $slots;
    }
}
