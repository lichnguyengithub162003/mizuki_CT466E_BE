<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductVariant;
use App\Models\Review;
use Illuminate\Support\Collection;

class ProductReviewImportRepository
{
    /**
     * @param  list<array<string, mixed>>  $reviews
     * @param  array{skipped: int, duplicate_collapsed: int, failed: int}  $mappingStats
     * @param  array{created: int, updated: int, unchanged: int, deleted: int, skipped: int, duplicate_collapsed: int, failed: int}  $counters
     */
    public function synchronize(
        Product $product,
        array $reviews,
        array $mappingStats,
        array &$counters,
    ): void {
        $counters['skipped'] += $mappingStats['skipped'];
        $counters['duplicate_collapsed'] += $mappingStats['duplicate_collapsed'];
        $counters['failed'] += $mappingStats['failed'];
        $existing = Review::query()
            ->withTrashed()
            ->where('product_id', $product->id)
            ->where('source', 'hasaki')
            ->lockForUpdate()
            ->get()
            ->keyBy('source_key');
        $variants = $this->variantsByExactName($product);
        $incomingKeys = [];

        foreach ($reviews as $attributes) {
            $sourceKey = (string) $attributes['source_key'];
            $incomingKeys[] = $sourceKey;
            $attributes['product_id'] = $product->id;
            $attributes['product_variant_id'] = $this->matchingVariantId(
                $variants,
                $attributes['variant_purchased'],
            );
            $createdAt = $attributes['created_at'];
            unset($attributes['created_at']);
            /** @var Review|null $review */
            $review = $existing->get($sourceKey);

            if ($review === null) {
                $review = new Review;
                $review->fill($attributes);

                if ($createdAt !== null) {
                    $review->setAttribute('created_at', $createdAt);
                }

                $review->save();
                $counters['created']++;

                continue;
            }

            $wasTrashed = $review->trashed();

            if ($wasTrashed) {
                $review->restore();
            }

            $review->fill($attributes);

            if ($createdAt !== null) {
                $review->setAttribute('created_at', $createdAt);
            }

            if ($wasTrashed || $review->isDirty()) {
                $review->save();
                $counters['updated']++;
            } else {
                $counters['unchanged']++;
            }
        }

        $stale = $existing->reject(
            static fn (Review $review, string $key): bool => in_array($key, $incomingKeys, true),
        );

        foreach ($stale as $review) {
            if (! $review->trashed()) {
                $review->delete();
                $counters['deleted']++;
            }
        }
    }

    /**
     * @return Collection<string, Collection<int, ProductVariant>>
     */
    private function variantsByExactName(Product $product): Collection
    {
        return $product->variants()
            ->get(['id', 'name'])
            ->groupBy(fn ($variant): string => $this->normalizedName($variant->name));
    }

    /**
     * @param  Collection<string, Collection<int, ProductVariant>>  $variants
     */
    private function matchingVariantId(Collection $variants, mixed $variantPurchased): ?int
    {
        if (! is_string($variantPurchased) || trim($variantPurchased) === '') {
            return null;
        }

        $matches = $variants->get($this->normalizedName($variantPurchased));

        return $matches?->count() === 1 ? (int) $matches->first()->id : null;
    }

    private function normalizedName(string $name): string
    {
        $name = mb_strtolower(trim($name));

        return preg_replace('/\s+/u', ' ', $name) ?? $name;
    }
}
