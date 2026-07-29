<?php

namespace App\Repositories\Import;

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use Closure;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;

class ClinicServiceImportRepository
{
    public function __construct(
        private readonly Branch $branch,
        private readonly Service $service,
        private readonly BranchService $branchService,
    ) {}

    public function transaction(Closure $callback): mixed
    {
        return DB::transaction($callback, 3);
    }

    public function findBranch(int $branchId): ?Branch
    {
        /** @var Branch|null $branch */
        $branch = $this->branch->newQuery()->find($branchId);

        return $branch;
    }

    /**
     * @param  array<int, string>  $slugs
     * @return Collection<int, Service>
     */
    public function findServicesBySlugs(array $slugs): Collection
    {
        if ($slugs === []) {
            return new Collection;
        }

        return $this->service->newQuery()
            ->withTrashed()
            ->whereIn('slug', $slugs)
            ->get();
    }

    /**
     * @param  array<int, string>  $slugs
     * @return Collection<int, Service>
     */
    public function findServicesBySlugsForUpdate(array $slugs): Collection
    {
        if ($slugs === []) {
            return new Collection;
        }

        return $this->service->newQuery()
            ->withTrashed()
            ->whereIn('slug', $slugs)
            ->lockForUpdate()
            ->get();
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array{service: Service, operation: string}
     */
    public function persistService(?Service $service, array $attributes): array
    {
        if ($service === null) {
            return [
                'service' => $this->service->newQuery()->create($attributes),
                'operation' => 'created',
            ];
        }

        $wasTrashed = $service->trashed();
        $service->fill($attributes);

        if (! $wasTrashed && ! $service->isDirty()) {
            return ['service' => $service, 'operation' => 'unchanged'];
        }

        if ($wasTrashed) {
            $service->restore();
        } else {
            $service->save();
        }

        return ['service' => $service->refresh(), 'operation' => 'updated'];
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return array<int, int>
     */
    public function attachedServiceIds(int $branchId, array $serviceIds): array
    {
        if ($serviceIds === []) {
            return [];
        }

        return $this->branchService->newQuery()
            ->where('branch_id', $branchId)
            ->whereIn('service_id', $serviceIds)
            ->pluck('service_id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
    }

    /**
     * @param  array<int, int>  $serviceIds
     * @return Collection<int, BranchService>
     */
    public function findBranchServicesForUpdate(int $branchId, array $serviceIds): Collection
    {
        if ($serviceIds === []) {
            return new Collection;
        }

        return $this->branchService->newQuery()
            ->where('branch_id', $branchId)
            ->whereIn('service_id', $serviceIds)
            ->lockForUpdate()
            ->get();
    }

    public function createBranchService(int $branchId, int $serviceId): BranchService
    {
        return $this->branchService->newQuery()->create([
            'branch_id' => $branchId,
            'service_id' => $serviceId,
            'is_available' => true,
            'capacity' => 1,
        ]);
    }
}
