<?php

namespace App\Services;

use App\Models\Category;
use App\Repositories\CategoryRepository;
use Illuminate\Database\Eloquent\Collection as EloquentCollection;
use Illuminate\Support\Collection;

class CategoryService extends BaseService
{
    public function __construct(
        private readonly CategoryRepository $categories,
    ) {
    }

    /**
     * @return EloquentCollection<int, Category>
     */
    public function getActiveHierarchy(): EloquentCollection
    {
        $grouped = $this->categories
            ->getActiveOrdered()
            ->groupBy(fn (Category $category): string => $category->parent_id === null
                ? 'root'
                : (string) $category->parent_id);

        return $this->buildTree($grouped, null);
    }

    /**
     * @param Collection<string, EloquentCollection<int, Category>> $grouped
     * @return EloquentCollection<int, Category>
     */
    private function buildTree(Collection $grouped, ?int $parentId): EloquentCollection
    {
        $key = $parentId === null ? 'root' : (string) $parentId;
        $categories = $grouped->get($key, new EloquentCollection());

        return $categories
            ->map(function (Category $category) use ($grouped): Category {
                $category->setRelation('children', $this->buildTree($grouped, $category->id));

                return $category;
            })
            ->values();
    }
}
