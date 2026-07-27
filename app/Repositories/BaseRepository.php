<?php

namespace App\Repositories;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;

/**
 * @template TModel of Model
 */
abstract class BaseRepository
{
    /**
     * @param TModel $model
     */
    public function __construct(
        protected Model $model,
    ) {
    }

    /**
     * @return Builder<TModel>
     */
    protected function query(): Builder
    {
        return $this->model->newQuery();
    }

    /**
     * @return TModel
     */
    public function findOrFail(int|string $id): Model
    {
        return $this->query()->findOrFail($id);
    }

    /**
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function create(array $attributes): Model
    {
        return $this->query()->create($attributes);
    }

    /**
     * @param TModel $model
     * @param array<string, mixed> $attributes
     * @return TModel
     */
    public function update(Model $model, array $attributes): Model
    {
        $model->update($attributes);

        return $model->refresh();
    }

    /**
     * @param TModel $model
     */
    public function delete(Model $model): bool
    {
        return (bool) $model->delete();
    }

    /**
     * @return LengthAwarePaginator<int, TModel>
     */
    public function paginate(int $perPage = 15): LengthAwarePaginator
    {
        return $this->query()->paginate($perPage);
    }
}
