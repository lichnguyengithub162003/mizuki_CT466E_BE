<?php

namespace App\Repositories;

use App\Enums\AppointmentStatus;
use App\Enums\BranchType;
use App\Models\Appointment;
use App\Models\Branch;
use App\Models\BranchBusinessHour;
use App\Models\Service;
use Carbon\CarbonInterface;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;

/**
 * @extends BaseRepository<Branch>
 */
class ClinicRepository extends BaseRepository
{
    public function __construct(
        Branch $branch,
        private readonly Service $service,
        private readonly BranchBusinessHour $businessHour,
        private readonly Appointment $appointment,
    ) {
        parent::__construct($branch);
    }

    /**
     * @return Collection<int, Branch>
     */
    public function getActiveClinics(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->whereIn('branch_type', [BranchType::Clinic->value, BranchType::Hybrid->value])
            ->with(['businessHours' => fn ($query) => $query->orderBy('weekday')])
            ->orderBy('name')
            ->get();
    }

    public function findActiveClinicOrFail(int $branchId): Branch
    {
        /** @var Branch $branch */
        $branch = $this->query()
            ->whereKey($branchId)
            ->where('is_active', true)
            ->whereIn('branch_type', [BranchType::Clinic->value, BranchType::Hybrid->value])
            ->with(['businessHours' => fn ($query) => $query->orderBy('weekday')])
            ->firstOrFail();

        return $branch;
    }

    /**
     * @return Collection<int, Service>
     */
    public function getActiveServices(Branch $branch): Collection
    {
        return $this->activeServicesQuery($branch)
            ->orderBy('services.sort_order')
            ->orderBy('services.name')
            ->get();
    }

    public function findActiveServiceOrFail(Branch $branch, int $serviceId): Service
    {
        /** @var Service $service */
        $service = $this->activeServicesQuery($branch)
            ->whereKey($serviceId)
            ->firstOrFail();

        return $service;
    }

    public function findBusinessHour(Branch $branch, int $weekday): ?BranchBusinessHour
    {
        /** @var BranchBusinessHour|null $hour */
        $hour = $this->businessHour->newQuery()
            ->where('branch_id', $branch->id)
            ->where('weekday', $weekday)
            ->first();

        return $hour;
    }

    public function countBlockingAppointments(
        Branch $branch,
        Service $service,
        CarbonInterface $startsAt,
        CarbonInterface $endsAt,
    ): int {
        return $this->appointment->newQuery()
            ->where('branch_id', $branch->id)
            ->where('service_id', $service->id)
            ->whereIn('status', [
                AppointmentStatus::Pending->value,
                AppointmentStatus::Confirmed->value,
                AppointmentStatus::InProgress->value,
            ])
            ->where('starts_at', '<', $endsAt)
            ->where('ends_at', '>', $startsAt)
            ->count();
    }

    private function activeServicesQuery(Branch $branch): Builder
    {
        return $this->service->newQuery()
            ->join('branch_services', 'branch_services.service_id', '=', 'services.id')
            ->where('branch_services.branch_id', $branch->id)
            ->where('branch_services.is_available', true)
            ->where('services.is_active', true)
            ->select('services.*')
            ->addSelect([
                'branch_services.capacity as booking_capacity',
                'branch_services.is_available as booking_is_available',
            ]);
    }
}
