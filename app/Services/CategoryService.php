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
    ) {}

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
        $thumbnailByCategory = $this->categories
            ->getActiveThumbnailCandidates()
            ->mapWithKeys(fn ($image): array => [
                (int) $image->category_id => (string) $image->image_url,
            ]);

        return $this->buildTree($grouped, $thumbnailByCategory, null);
    }

    /**
     * @param  Collection<string, EloquentCollection<int, Category>>  $grouped
     * @param  Collection<int, string>  $thumbnailByCategory
     * @return EloquentCollection<int, Category>
     */
    private function buildTree(
        Collection $grouped,
        Collection $thumbnailByCategory,
        ?int $parentId,
    ): EloquentCollection {
        $key = $parentId === null ? 'root' : (string) $parentId;
        $categories = $grouped->get($key, new EloquentCollection);

        return $categories
            ->map(function (Category $category) use ($grouped, $thumbnailByCategory): Category {
                $children = $this->buildTree($grouped, $thumbnailByCategory, $category->id);
                $thumbnail = $thumbnailByCategory->get($category->id)
                    ?? $children->pluck('thumbnail_url')->first(
                        fn (mixed $value): bool => filled($value),
                    );

                $category->setRelation('children', $children);
                $category->setAttribute('thumbnail_url', $thumbnail);

                return $category;
            })
            ->values();
    }
}
