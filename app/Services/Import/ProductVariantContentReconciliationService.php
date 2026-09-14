<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductVariantContentRepository;
use App\Support\Import\ProductVariantContentReconciler;
use App\Support\Import\ProductVariantContentReconciliationResult;
use JsonException;
use RuntimeException;

final class ProductVariantContentReconciliationService
{
    public function __construct(
        private readonly ProductVariantContentRepository $repository,
        private readonly ProductVariantContentReconciler $reconciler,
    ) {}

    public function audit(string $manifestPath): ProductVariantContentReconciliationResult
    {
        $groups = $this->manifestGroups($manifestPath);
        $results = [];

        foreach ($groups as $group) {
            $results[] = $this->reconciler->reconcile($group, $this->repository->groupData($group));
        }

        usort($results, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);
        $decisions = array_count_values(array_column($results, 'recommended_merge_decision'));

        return new ProductVariantContentReconciliationResult([
            'total_candidate_groups_analyzed' => count($results),
            'already_normalized' => (int) ($decisions['already_normalized'] ?? 0),
            'merge_ready' => (int) ($decisions['merge_ready'] ?? 0),
            'merge_after_variant_content_move' => (int) ($decisions['merge_after_variant_content_move'] ?? 0),
            'manual_review' => (int) ($decisions['manual_review'] ?? 0),
            'do_not_merge' => (int) ($decisions['do_not_merge'] ?? 0),
            'groups_with_ingredient_conflicts' => $this->groupsWithFieldConflict($results, 'ingredients'),
            'groups_with_usage_conflicts' => $this->groupsWithFieldConflict($results, 'usage_instructions'),
            'groups_with_category_conflicts' => $this->groupsWithFieldConflict($results, 'category_id'),
            'groups_with_review_question_favorite_ownership_conflicts' => count(array_filter(
                $results,
                static fn (array $result): bool => (bool) $result['operational_content']['has_ownership_conflicts'],
            )),
        ], $results);
    }

    /** @return list<array<string, mixed>> */
    private function manifestGroups(string $manifestPath): array
    {
        if (! is_file($manifestPath) || ! is_readable($manifestPath)) {
            throw new RuntimeException('The T1.1 normalization manifest is missing or unreadable.');
        }

        try {
            $manifest = json_decode((string) file_get_contents($manifestPath), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException('The T1.1 normalization manifest contains invalid JSON.', previous: $exception);
        }

        if (! is_array($manifest) || ! is_array($manifest['groups'] ?? null)) {
            throw new RuntimeException('The T1.1 normalization manifest does not contain a groups list.');
        }

        $groups = [];
        foreach ($manifest['groups'] as $group) {
            if (! is_array($group)
                || ! is_string($group['group_identifier'] ?? null)
                || ! is_array($group['candidate_product_ids'] ?? null)
                || count($group['candidate_product_ids']) < 2) {
                throw new RuntimeException('The T1.1 normalization manifest contains an invalid candidate group.');
            }
            $groups[] = $group;
        }

        usort($groups, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);

        return $groups;
    }

    /** @param list<array<string, mixed>> $results */
    private function groupsWithFieldConflict(array $results, string $field): int
    {
        return count(array_filter(
            $results,
            static fn (array $result): bool => ($result['per_field_classification'][$field]['classification'] ?? null)
                === 'semantic_conflict',
        ));
    }
}
