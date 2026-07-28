<?php

namespace App\Repositories\Import;

use App\Models\Branch;
use App\Models\BranchService;
use App\Models\Service;
use Illuminate\Database\Eloquent\Collection;

class ClinicServiceImportRepository
{
    public function __construct(
        private readonly Branch $branch,
        private readonly Service $service,
        private readonly BranchService $branchService,
    ) {}

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
}
