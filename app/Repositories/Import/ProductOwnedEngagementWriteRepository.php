<?php

namespace App\Repositories\Import;

use App\Models\Product;
use App\Models\ProductVariant;
use Closure;
use Illuminate\Support\Facades\DB;

class ProductOwnedEngagementWriteRepository
{
    public function transaction(Closure $callback): mixed
    {
        return DB::transaction(fn (): mixed => $callback($this), attempts: 1);
    }

    /** @param array<string, mixed> $group */
    public function lockGroup(array $group): void
    {
        $productIds = $this->integerList($group['candidate_product_ids'] ?? []);
        $variantIds = $this->integerList($group['candidate_variant_ids'] ?? []);
        $products = Product::query()->withTrashed()->whereIn('id', $productIds)->lockForUpdate()->get(['id']);
        $variants = ProductVariant::query()->withTrashed()->whereIn('id', $variantIds)->lockForUpdate()->get(['id']);

        if ($products->count() !== count($productIds) || $variants->count() !== count($variantIds)) {
            throw new \RuntimeException('Candidate Product or ProductVariant state no longer matches the report.');
        }

        DB::table('reviews')->whereIn('product_id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
        $questionIds = DB::table('product_questions')
            ->whereIn('product_id', $productIds)
            ->orderBy('id')
            ->lockForUpdate()
            ->pluck('id')
            ->map(static fn (mixed $id): int => (int) $id)
            ->all();
        if ($questionIds !== []) {
            DB::table('product_question_answers')
                ->whereIn('product_question_id', $questionIds)
                ->orderBy('id')
                ->lockForUpdate()
                ->get(['id']);
        }
        DB::table('product_favorites')->whereIn('product_id', $productIds)->orderBy('id')->lockForUpdate()->get(['id']);
    }

    /** @param array<string, mixed> $plan */
    public function isAlreadyReconciled(array $plan): bool
    {
        $canonicalId = (int) $plan['canonical_product_id_for_estimate'];
        $productIds = $this->integerList($plan['candidate_product_ids'] ?? []);
        $nonCanonicalIds = array_values(array_diff($productIds, [$canonicalId]));

        if ($nonCanonicalIds !== []) {
            foreach (['reviews', 'product_questions', 'product_favorites'] as $table) {
                $query = DB::table($table)->whereIn('product_id', $nonCanonicalIds);
                if ($table === 'reviews') {
                    $query->whereNull('deleted_at');
                }
                if ($query->exists()) {
                    return false;
                }
            }
        }

        $attributableReviewIds = [];
        foreach ($plan['reviews']['variant_attribution_by_record'] ?? [] as $reviewId => $attribution) {
            if (($attribution['status'] ?? null) === 'attributable_to_one_variant') {
                $attributableReviewIds[] = (int) $reviewId;
            }
        }

        return $attributableReviewIds === [] || ! DB::table('reviews')
            ->whereIn('id', $attributableReviewIds)
            ->whereNull('deleted_at')
            ->whereNull('product_variant_id')
            ->exists();
    }

    public function attributeReview(int $reviewId, int $variantId): int
    {
        return DB::table('reviews')
            ->where('id', $reviewId)
            ->whereNull('deleted_at')
            ->whereNull('product_variant_id')
            ->update(['product_variant_id' => $variantId]);
    }

    public function softDeleteReview(int $reviewId): int
    {
        return DB::table('reviews')
            ->where('id', $reviewId)
            ->whereNull('deleted_at')
            ->update(['deleted_at' => now()]);
    }

    public function transferReview(int $reviewId, int $canonicalProductId): int
    {
        return DB::table('reviews')
            ->where('id', $reviewId)
            ->whereNull('deleted_at')
            ->where('product_id', '<>', $canonicalProductId)
            ->update(['product_id' => $canonicalProductId]);
    }

    public function deleteQuestion(int $questionId): int
    {
        return DB::table('product_questions')->where('id', $questionId)->delete();
    }

    public function transferQuestion(int $questionId, int $canonicalProductId): int
    {
        return DB::table('product_questions')
            ->where('id', $questionId)
            ->where('product_id', '<>', $canonicalProductId)
            ->update(['product_id' => $canonicalProductId]);
    }

    public function deleteFavorite(int $favoriteId): int
    {
        return DB::table('product_favorites')->where('id', $favoriteId)->delete();
    }

    public function transferFavorite(int $favoriteId, int $canonicalProductId): int
    {
        return DB::table('product_favorites')
            ->where('id', $favoriteId)
            ->where('product_id', '<>', $canonicalProductId)
            ->update(['product_id' => $canonicalProductId]);
    }

    /** @param array<mixed> $values @return list<int> */
    private function integerList(array $values): array
    {
        $values = array_values(array_unique(array_map('intval', $values)));
        sort($values, SORT_NUMERIC);

        return $values;
    }
}
