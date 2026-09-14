<?php

namespace App\Services\Import;

use App\Repositories\Import\ProductOwnedEngagementRepository;
use App\Support\Import\ProductOwnedEngagementReconciler;
use App\Support\Import\ProductOwnedEngagementReconciliationResult;
use JsonException;
use RuntimeException;

final class ProductOwnedEngagementReconciliationService
{
    public function __construct(
        private readonly ProductOwnedEngagementRepository $repository,
        private readonly ProductOwnedEngagementReconciler $reconciler,
    ) {}

    public function audit(string $manifestPath, string $contentReportPath): ProductOwnedEngagementReconciliationResult
    {
        $groups = $this->groups($manifestPath, 'T1.1 normalization manifest');
        $contentGroups = [];
        foreach ($this->groups($contentReportPath, 'T1.5 content report') as $group) {
            $contentGroups[$group['group_identifier']] = $group;
        }

        $results = [];
        foreach ($groups as $group) {
            $identifier = $group['group_identifier'];
            $results[] = $this->reconciler->reconcile(
                $group,
                $this->repository->groupData($group),
                $contentGroups[$identifier] ?? null,
            );
        }
        usort($results, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);
        $decisions = array_count_values(array_column($results, 'resulting_engagement_decision'));
        $metrics = ['transferred' => 0, 'deduplicated' => 0, 'variant_attributable' => 0];
        $withEngagement = 0;
        $t15ManualPotential = 0;
        $t15VariantMoveResolvable = 0;

        foreach ($results as &$result) {
            $withEngagement += $result['_metrics']['has_engagement'];
            foreach ($metrics as $key => $_) {
                $metrics[$key] += $result['_metrics'][$key];
            }
            $engagementResolvable = $result['resulting_engagement_decision'] !== 'manual_review';
            $contentReasons = array_values(array_diff(
                $result['t1_5_reasons'],
                ['review_question_or_favorite_ownership_conflict', 't1_1_manual_review_required'],
            ));
            $engagementWasContentBlocker = in_array(
                'review_question_or_favorite_ownership_conflict',
                $result['t1_5_reasons'],
                true,
            );
            if ($engagementResolvable
                && $result['t1_5_merge_decision'] === 'manual_review'
                && $engagementWasContentBlocker
                && $contentReasons === []) {
                $t15ManualPotential++;
            }
            if ($engagementResolvable && $result['t1_5_merge_decision'] === 'merge_after_variant_content_move') {
                $t15VariantMoveResolvable++;
            }
            unset($result['_metrics']);
        }
        unset($result);

        return new ProductOwnedEngagementReconciliationResult([
            'total_candidate_groups_analyzed' => count($results),
            'total_groups_with_reviews_questions_or_favorites' => $withEngagement,
            'groups_that_can_be_resolved_automatically' => count($results) - (int) ($decisions['manual_review'] ?? 0),
            'groups_requiring_dedup_only' => (int) ($decisions['engagement_merge_ready_with_dedup'] ?? 0),
            'groups_requiring_variant_attribution' => (int) ($decisions['needs_variant_attribution'] ?? 0),
            'genuine_manual_conflicts' => (int) ($decisions['manual_review'] ?? 0),
            'records_that_would_be_transferred' => $metrics['transferred'],
            'records_that_would_be_deduplicated' => $metrics['deduplicated'],
            'records_that_could_receive_variant_attribution' => $metrics['variant_attributable'],
            't1_5_manual_review_groups_engagement_resolvable' => $t15ManualPotential,
            't1_5_merge_after_variant_content_move_groups_engagement_resolvable' => $t15VariantMoveResolvable,
            'estimated_additional_product_groups_mergeable_after_engagement_policy' => $t15ManualPotential,
        ], $results);
    }

    /** @return list<array<string, mixed>> */
    private function groups(string $path, string $label): array
    {
        if (! is_file($path) || ! is_readable($path)) {
            throw new RuntimeException("The {$label} is missing or unreadable.");
        }
        try {
            $document = json_decode((string) file_get_contents($path), true, 512, JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException("The {$label} contains invalid JSON.", previous: $exception);
        }
        if (! is_array($document) || ! is_array($document['groups'] ?? null)) {
            throw new RuntimeException("The {$label} does not contain a groups list.");
        }

        $groups = [];
        foreach ($document['groups'] as $group) {
            if (! is_array($group) || ! is_string($group['group_identifier'] ?? null)) {
                throw new RuntimeException("The {$label} contains an invalid group.");
            }
            $groups[] = $group;
        }
        usort($groups, static fn (array $left, array $right): int => $left['group_identifier'] <=> $right['group_identifier']);

        return $groups;
    }
}
