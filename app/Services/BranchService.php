<?php

namespace App\Services;

use App\Models\Branch;
use App\Repositories\BranchRepository;
use Illuminate\Database\Eloquent\Collection;

class BranchService extends BaseService
{
    public function __construct(
        private readonly BranchRepository $branchRepository,
    ) {}

    /**
     * @return Collection<int, Branch>
     */
    public function getActiveBranches(): Collection
    {
        return $this->branchRepository->getActiveForSelector();
    }
}
