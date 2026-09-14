<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductVariantNormalizationRepository;
use App\Support\Import\ProductVariantGroupingPolicy;
use App\Support\Import\ProductVariantNormalizationResult;

final class ProductVariantNormalizationService
{
    public function __construct(
        private readonly ProductVariantNormalizationRepository $repository,
        private readonly ProductVariantGroupingPolicy $policy,
    ) {}

    public function plan(string $source = 'hasaki'): ProductVariantNormalizationResult
    {
        $profiles = [];
        $sourceIndex = [];

        foreach ($this->repository->importedProducts($source) as $product) {
            $profile = $this->policy->profile($product);
            $profiles[$profile['product_id']] = $profile;

            if ($profile['external_id'] !== null) {
                $sourceIndex[$profile['source']][$profile['external_id']] = $profile['product_id'];
            }
        }

        $components = $this->candidateComponents($profiles, $sourceIndex);
        $candidateProductIds = $components === []
            ? []
            : array_values(array_unique(array_merge(...array_values($components))));
        sort($candidateProductIds, SORT_NUMERIC);
        $variantProductIds = [];

        foreach ($candidateProductIds as $productId) {
            foreach ($profiles[$productId]['variant_ids'] as $variantId) {
                $variantProductIds[$variantId] = $productId;
            }
        }

        $operational = $this->repository->operationalCounts($candidateProductIds, $variantProductIds);
        $groups = [];

        foreach ($components as $productIds) {
            $groupProfiles = array_map(static fn (int $id): array => $profiles[$id], $productIds);
            $group = $this->policy->evaluate($groupProfiles, $operational);
            $identity = implode('|', array_map(
                static fn (array $identity): string => ($identity['source'] ?? '').':'.($identity['external_id'] ?? ''),
                $group['source_external_ids'],
            ));
            $group['group_identifier'] = 'pvg-'.substr(hash('sha256', $identity), 0, 16);
            unset($group['_external_ids']);
            $groups[] = $group;
        }

        usort($groups, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);
        $classifications = array_count_values(array_column($groups, 'classification'));
        $groupsWithHighRiskOperations = count(array_filter(
            $groups,
            static fn (array $group): bool => array_sum(array_intersect_key(
                $group['operational_conflicts']['totals'],
                array_flip(['order_items', 'reviews', 'favorites']),
            )) > 0,
        ));

        return new ProductVariantNormalizationResult([
            'products_scanned' => count($profiles),
            'candidate_groups' => count($groups),
            'auto_safe' => (int) ($classifications['auto_safe'] ?? 0),
            'manual_review' => (int) ($classifications['manual_review'] ?? 0),
            'rejected' => (int) ($classifications['rejected'] ?? 0),
            'products_involved' => count($candidateProductIds),
            'groups_with_order_review_favorite_conflicts' => $groupsWithHighRiskOperations,
        ], $groups);
    }

    /**
     * @param  array<int, array<string, mixed>>  $profiles
     * @param  array<string, array<string, int>>  $sourceIndex
     * @return array<int, list<int>>
     */
    private function candidateComponents(array $profiles, array $sourceIndex): array
    {
        $parents = array_fill_keys(array_keys($profiles), null);
        $buckets = [];

        foreach ($profiles as $productId => $profile) {
            $bucket = implode('|', [
                $profile['source'] ?? '',
                $profile['brand_id'],
                $profile['normalized_core_name'],
            ]);
            $buckets[$bucket][] = $productId;
        }

        foreach ($buckets as $productIds) {
            if (count($productIds) < 2) {
                continue;
            }

            $first = $productIds[0];

            foreach (array_slice($productIds, 1) as $productId) {
                $this->union($parents, $first, $productId);
            }
        }

        foreach ($profiles as $productId => $profile) {
            foreach ($profile['source_relationship_ids'] as $externalId) {
                $relatedId = $sourceIndex[$profile['source']][$externalId] ?? null;

                if ($relatedId !== null && $relatedId !== $productId) {
                    $this->union($parents, $productId, $relatedId);
                }
            }
        }

        $components = [];

        foreach (array_keys($profiles) as $productId) {
            $root = $this->find($parents, $productId);
            $components[$root][] = $productId;
        }

        $components = array_filter($components, static fn (array $ids): bool => count($ids) >= 2);

        foreach ($components as &$productIds) {
            sort($productIds, SORT_NUMERIC);
        }
        unset($productIds);

        ksort($components, SORT_NUMERIC);

        return $components;
    }

    /** @param  array<int, int|null>  $parents */
    private function find(array &$parents, int $id): int
    {
        if ($parents[$id] === null) {
            return $id;
        }

        return $parents[$id] = $this->find($parents, $parents[$id]);
    }

    /** @param  array<int, int|null>  $parents */
    private function union(array &$parents, int $left, int $right): void
    {
        $leftRoot = $this->find($parents, $left);
        $rightRoot = $this->find($parents, $right);

        if ($leftRoot === $rightRoot) {
            return;
        }

        $parents[max($leftRoot, $rightRoot)] = min($leftRoot, $rightRoot);
    }
}
