<?php

namespace App\Repositories;

use App\Models\Branch;
use Illuminate\Database\Eloquent\Collection;

class BranchRepository extends BaseRepository
{
    public function __construct(Branch $model)
    {
        parent::__construct($model);
    }

    /**
     * @return Collection<int, Branch>
     */
    public function getActiveForSelector(): Collection
    {
        return $this->query()
            ->where('is_active', true)
            ->with([
                'businessHours' => fn ($query) => $query
                    ->orderBy('weekday'),
            ])
            ->orderBy('name')
            ->orderBy('id')
            ->get();
    }
}
